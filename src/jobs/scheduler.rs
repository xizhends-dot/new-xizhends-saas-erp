//! `Job_Scheduler`：多租户 + 分布式锁调度器（Task 14.1 / Requirements 11.1、11.7）。
//!
//! 设计依据 `design.md` 6.3 末「多租户调度」与错误处理表「后台任务隔离」：
//! - **多租户循环**：每个 job 对所有「启用」租户循环执行——先取该租户库连接池，
//!   再运行单租户逻辑，而非 old 系统的单库执行。
//! - **分布式锁**（Requirements 11.1）：用锁避免多实例重复跑同一任务。锁被抽象为
//!   [`DistributedLock`] trait，便于测试注入；生产可用 [`MysqlAdvisoryLock`]
//!   （MySQL `GET_LOCK`），单机/开发可用 [`NoopLock`]，同进程去重可用 [`InMemoryLock`]。
//! - **单租户失败隔离**（Requirements 11.7）：某租户的 job 失败（取池失败或运行报错）
//!   只记日志并计入失败计数，继续处理其余租户，绝不中断整轮。
//!
//! ## 可测试性接缝
//! 真正触达网络/DB 的两处——「列举启用租户」与「按租户取连接池」——统一收敛到
//! [`TenantProvider`] trait 之后；调度核心 [`dispatch_job`] 只依赖 trait 对象，
//! 因此单元测试可注入假的租户列表 + 假任务 + 假锁，在**无真实 MySQL** 的前提下断言
//! 「失败隔离」与「锁门控」。生产实现 [`MasterTenantProvider`] 从主库读启用租户、
//! 经 [`TenantPoolManager`](crate::db::pool::TenantPoolManager) 取池。

use std::sync::Arc;
use std::time::Duration;

use async_trait::async_trait;
use sqlx::MySqlPool;

use crate::db::pool::{TenantId, TenantPoolManager};
use crate::error::AppError;
use crate::state::AppState;

// ───────────────────────────────────────────────────────────────────────────
// Job 抽象
// ───────────────────────────────────────────────────────────────────────────

/// 单个定时任务的运行上下文：目标租户 + 其库连接池。
///
/// 由调度器在确认锁与租户可用后构造并传入 [`Job::run_for_tenant`]，使任务实现
/// 只需关注「针对该租户库做什么」，无需关心租户枚举与池生命周期。
pub struct JobContext {
    /// 目标租户主键。
    pub tenant_id: TenantId,
    /// 该租户库连接池（来自连接池管理器，已就绪）。
    pub pool: MySqlPool,
}

/// 定时任务契约（对应 `design.md` 6.3 的 9 个 job）。
///
/// 每个实现声明自己的名字（用于分布式锁键与日志）与运行周期，并实现「对单个租户库」
/// 的处理逻辑。调度器负责对所有启用租户循环调用 [`Job::run_for_tenant`]。
#[async_trait]
pub trait Job: Send + Sync {
    /// 任务名：既作日志标识，也作分布式锁键（同名任务跨实例互斥）。
    fn name(&self) -> &'static str;

    /// 运行周期（两次触发的最小间隔）。
    fn interval(&self) -> Duration;

    /// 针对单个租户库执行任务。返回 `Err` 仅影响该租户（被隔离），不影响其余租户。
    async fn run_for_tenant(&self, ctx: &JobContext) -> Result<(), AppError>;
}

// ───────────────────────────────────────────────────────────────────────────
// 租户来源抽象（可测试接缝）
// ───────────────────────────────────────────────────────────────────────────

/// 租户枚举 + 取池抽象。把唯一会触达网络/DB 的两处操作收敛于此，便于测试替身注入。
#[async_trait]
pub trait TenantProvider: Send + Sync {
    /// 列出所有「启用」（`status='active'`）租户的主键。
    async fn active_tenants(&self) -> Result<Vec<TenantId>, AppError>;

    /// 取得某租户库连接池（懒建/缓存复用由实现负责）。
    async fn pool_for(&self, tenant_id: TenantId) -> Result<MySqlPool, AppError>;
}

/// 生产实现：从主库读启用租户、经连接池管理器取池。
///
/// 仅访问主库 `tenants` 表枚举启用租户；取池委托
/// [`TenantPoolManager::pool_for`](crate::db::pool::TenantPoolManager::pool_for)。
pub struct MasterTenantProvider {
    master: MySqlPool,
    pools: Arc<TenantPoolManager>,
}

impl MasterTenantProvider {
    /// 由已构建的主库池与连接池管理器构造。
    pub fn new(master: MySqlPool, pools: Arc<TenantPoolManager>) -> Self {
        Self { master, pools }
    }

    /// 便捷构造：从 [`AppState`] 取主库池与连接池管理器（均为廉价 `Arc`/句柄克隆）。
    pub fn from_state(state: &AppState) -> Self {
        Self::new(state.master_pool().clone(), state.pools().clone())
    }
}

#[async_trait]
impl TenantProvider for MasterTenantProvider {
    async fn active_tenants(&self) -> Result<Vec<TenantId>, AppError> {
        let rows: Vec<(TenantId,)> = sqlx::query_as(
            "SELECT `id` FROM `tenants` WHERE `status` = 'active' ORDER BY `id` ASC",
        )
        .fetch_all(&self.master)
        .await?;
        Ok(rows.into_iter().map(|(id,)| id).collect())
    }

    async fn pool_for(&self, tenant_id: TenantId) -> Result<MySqlPool, AppError> {
        self.pools.pool_for(tenant_id).await
    }
}

// ───────────────────────────────────────────────────────────────────────────
// 分布式锁抽象（Requirements 11.1）
// ───────────────────────────────────────────────────────────────────────────

/// 分布式锁契约：避免多实例重复执行同一任务。
///
/// 语义：`try_acquire` **非阻塞**，拿到锁返回 `true`，否则返回 `false`（本轮跳过）；
/// 仅当 `try_acquire` 返回 `true` 时才应调用 `release`。
#[async_trait]
pub trait DistributedLock: Send + Sync {
    /// 尝试获取命名锁；成功返回 `true`，已被他人持有返回 `false`。
    async fn try_acquire(&self, job_name: &str) -> bool;

    /// 释放命名锁（仅在 `try_acquire` 成功后调用）。
    async fn release(&self, job_name: &str);
}

/// 无操作锁：恒获取成功。**适用于单实例/开发环境**（不防多实例重复）。
#[derive(Debug, Default, Clone)]
pub struct NoopLock;

#[async_trait]
impl DistributedLock for NoopLock {
    async fn try_acquire(&self, _job_name: &str) -> bool {
        true
    }
    async fn release(&self, _job_name: &str) {}
}

/// 进程内锁：用 `Mutex<HashSet>` 保证**同一进程内**同名任务不并发重入。
///
/// 适合单实例多 worker 的场景或测试；**不**跨进程/跨实例互斥
/// （跨实例请用 [`MysqlAdvisoryLock`]）。
#[derive(Debug, Default)]
pub struct InMemoryLock {
    held: std::sync::Mutex<std::collections::HashSet<String>>,
}

#[async_trait]
impl DistributedLock for InMemoryLock {
    async fn try_acquire(&self, job_name: &str) -> bool {
        // 临界区无 await，守卫不跨越 await 点。
        self.held.lock().unwrap().insert(job_name.to_string())
    }
    async fn release(&self, job_name: &str) {
        self.held.lock().unwrap().remove(job_name);
    }
}

/// 基于 MySQL `GET_LOCK` 的跨实例分布式锁（Requirements 11.1）。
///
/// `GET_LOCK(name, timeout)` 是**连接作用域**的命名锁：同一连接重复获取可叠加，
/// 释放须在**同一连接**上执行 `RELEASE_LOCK(name)`，连接断开则自动释放。因此本实现
/// 在获取成功时**保留该连接**（存入 `held`），释放时取回同一连接执行 `RELEASE_LOCK`。
///
/// 关键约束与权衡：
/// - `timeout_secs = 0` 表示**非阻塞**尝试（拿不到立即返回 0），契合 `try_acquire` 语义。
/// - 保留连接期间会占用连接池一个名额；任务名数量有限，影响可控。
/// - 进程崩溃时连接关闭 → MySQL 自动释放锁，天然避免死锁残留。
/// - 同进程已持有时直接返回 `false`，避免本进程重复进入（与跨实例语义一致）。
pub struct MysqlAdvisoryLock {
    master: MySqlPool,
    /// `GET_LOCK` 等待超时（秒）；`0` = 非阻塞尝试。
    timeout_secs: i64,
    /// 已持有的命名锁 → 其占用的连接（释放须用同一连接）。
    held: std::sync::Mutex<
        std::collections::HashMap<String, sqlx::pool::PoolConnection<sqlx::MySql>>,
    >,
}

impl MysqlAdvisoryLock {
    /// 用主库池构造（默认非阻塞：`timeout_secs = 0`）。
    pub fn new(master: MySqlPool) -> Self {
        Self {
            master,
            timeout_secs: 0,
            held: std::sync::Mutex::new(std::collections::HashMap::new()),
        }
    }
}

#[async_trait]
impl DistributedLock for MysqlAdvisoryLock {
    async fn try_acquire(&self, job_name: &str) -> bool {
        // 本进程已持有 → 视为未获取，避免重复进入。临界区无 await。
        if self.held.lock().unwrap().contains_key(job_name) {
            return false;
        }

        let mut conn = match self.master.acquire().await {
            Ok(c) => c,
            Err(e) => {
                tracing::error!(job = job_name, error = %e, "获取分布式锁连接失败");
                return false;
            }
        };

        // GET_LOCK 返回 1=成功 / 0=超时未获取 / NULL=出错。
        let acquired: Option<i64> = match sqlx::query_scalar("SELECT GET_LOCK(?, ?)")
            .bind(job_name)
            .bind(self.timeout_secs)
            .fetch_one(conn.as_mut())
            .await
        {
            Ok(v) => v,
            Err(e) => {
                tracing::error!(job = job_name, error = %e, "GET_LOCK 执行失败");
                return false;
            }
        };

        if acquired == Some(1) {
            // 保留连接以便后续在同一连接上 RELEASE_LOCK。临界区无 await。
            self.held.lock().unwrap().insert(job_name.to_string(), conn);
            true
        } else {
            // conn 在此 drop 并归还连接池。
            false
        }
    }

    async fn release(&self, job_name: &str) {
        // 取回持锁连接（临界区无 await），在同一连接上释放。
        let conn = self.held.lock().unwrap().remove(job_name);
        if let Some(mut conn) = conn {
            if let Err(e) = sqlx::query("SELECT RELEASE_LOCK(?)")
                .bind(job_name)
                .execute(conn.as_mut())
                .await
            {
                tracing::warn!(job = job_name, error = %e, "RELEASE_LOCK 执行失败");
            }
            // conn 在此 drop 归还连接池（即便 RELEASE_LOCK 失败，连接归还后锁最终随会话释放）。
        }
    }
}

// ───────────────────────────────────────────────────────────────────────────
// 调度核心
// ───────────────────────────────────────────────────────────────────────────

/// 单次任务调度的结果汇总（便于日志/监控/测试断言）。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct JobOutcome {
    /// 任务名。
    pub job_name: String,
    /// 是否取得分布式锁（`false` 表示本轮被锁门控跳过）。
    pub lock_acquired: bool,
    /// 成功处理的租户。
    pub succeeded: Vec<TenantId>,
    /// 失败（取池失败或运行报错）的租户——已被隔离，不影响其余。
    pub failed: Vec<TenantId>,
}

impl JobOutcome {
    /// 因未取得锁而跳过的结果。
    fn skipped(job_name: &str) -> Self {
        Self {
            job_name: job_name.to_string(),
            lock_acquired: false,
            succeeded: Vec::new(),
            failed: Vec::new(),
        }
    }
}

/// 调度单个任务：先抢锁，拿到后对所有启用租户循环执行，最后释放锁。
///
/// - 未取得锁（Requirements 11.1）→ 直接返回 [`JobOutcome::skipped`]，**不**释放锁。
/// - 取得锁后枚举启用租户失败 → 记日志、释放锁、返回空结果（`lock_acquired = true`）。
/// - 单租户取池或运行失败（Requirements 11.7）→ 记日志、计入 `failed`、继续下一租户。
pub async fn dispatch_job(
    job: &dyn Job,
    provider: &dyn TenantProvider,
    lock: &dyn DistributedLock,
) -> JobOutcome {
    let name = job.name();

    if !lock.try_acquire(name).await {
        tracing::debug!(job = name, "未取得分布式锁（疑另一实例正在运行），跳过本轮");
        return JobOutcome::skipped(name);
    }

    let outcome = run_for_all_tenants(job, provider).await;
    lock.release(name).await;
    outcome
}

/// 已持锁的前提下，对所有启用租户循环执行任务，做单租户失败隔离（Requirements 11.7）。
async fn run_for_all_tenants(job: &dyn Job, provider: &dyn TenantProvider) -> JobOutcome {
    let name = job.name();
    let mut outcome = JobOutcome {
        job_name: name.to_string(),
        lock_acquired: true,
        succeeded: Vec::new(),
        failed: Vec::new(),
    };

    let tenants = match provider.active_tenants().await {
        Ok(t) => t,
        Err(e) => {
            // 枚举失败属本轮整体问题，记日志后返回空结果（仍标记已持锁，便于调用方释放）。
            tracing::error!(job = name, error = %e, "枚举启用租户失败，跳过本轮");
            return outcome;
        }
    };

    for tenant_id in tenants {
        // 取池失败 → 隔离该租户，继续其余（design.md 错误表「后台任务跳过该租户继续下一个」）。
        let pool = match provider.pool_for(tenant_id).await {
            Ok(p) => p,
            Err(e) => {
                tracing::warn!(job = name, tenant_id, error = %e, "取租户连接池失败，跳过该租户");
                outcome.failed.push(tenant_id);
                continue;
            }
        };

        let ctx = JobContext { tenant_id, pool };
        match job.run_for_tenant(&ctx).await {
            Ok(()) => outcome.succeeded.push(tenant_id),
            Err(e) => {
                tracing::warn!(job = name, tenant_id, error = %e, "任务在该租户执行失败，已隔离继续");
                outcome.failed.push(tenant_id);
            }
        }
    }

    outcome
}

/// 多租户定时任务调度器。
///
/// 持有一组任务、一个租户来源与一个分布式锁。调用 [`JobScheduler::spawn`] 为每个任务
/// 启动独立的 tokio 定时循环（各自按 [`Job::interval`] 触发），每次触发执行 [`dispatch_job`]。
pub struct JobScheduler {
    jobs: Vec<Arc<dyn Job>>,
    provider: Arc<dyn TenantProvider>,
    lock: Arc<dyn DistributedLock>,
}

impl JobScheduler {
    /// 构造调度器（租户来源 + 分布式锁）。
    pub fn new(provider: Arc<dyn TenantProvider>, lock: Arc<dyn DistributedLock>) -> Self {
        Self {
            jobs: Vec::new(),
            provider,
            lock,
        }
    }

    /// 注册一个任务（链式）。
    pub fn register(mut self, job: Arc<dyn Job>) -> Self {
        self.jobs.push(job);
        self
    }

    /// 已注册任务数量。
    pub fn job_count(&self) -> usize {
        self.jobs.len()
    }

    /// 立即对所有已注册任务各执行一轮（不依赖定时器）。
    ///
    /// 供启动自检 / 手动触发 / 测试使用；按注册顺序串行执行，返回各任务结果。
    pub async fn run_all_once(&self) -> Vec<JobOutcome> {
        let mut outcomes = Vec::with_capacity(self.jobs.len());
        for job in &self.jobs {
            outcomes
                .push(dispatch_job(job.as_ref(), self.provider.as_ref(), self.lock.as_ref()).await);
        }
        outcomes
    }

    /// 为每个任务启动独立的定时循环（tokio 定时器），返回各循环的 join 句柄。
    ///
    /// 每个任务用 [`tokio::time::interval`] 按其 [`Job::interval`] 触发；首个 tick 立即就绪
    /// （即启动后先跑一轮），其后按周期触发。错过的 tick 采用 `Skip` 策略，避免任务堆积补偿。
    /// 各轮通过 [`dispatch_job`] 执行，锁门控与失败隔离已内建。
    pub fn spawn(self) -> Vec<tokio::task::JoinHandle<()>> {
        let mut handles = Vec::with_capacity(self.jobs.len());
        for job in self.jobs {
            let provider = self.provider.clone();
            let lock = self.lock.clone();
            let handle = tokio::spawn(async move {
                let mut ticker = tokio::time::interval(job.interval());
                ticker.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Skip);
                loop {
                    ticker.tick().await;
                    let _ = dispatch_job(job.as_ref(), provider.as_ref(), lock.as_ref()).await;
                }
            });
            handles.push(handle);
        }
        handles
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use sqlx::mysql::MySqlPoolOptions;
    use std::collections::HashSet;
    use std::sync::atomic::{AtomicUsize, Ordering};
    use std::sync::Mutex;

    /// 构造一个 lazy MySqlPool 占位池：首次使用前不会建立真实连接，
    /// 因此可在无 MySQL 服务的环境下作为 `JobContext.pool` 注入。
    fn lazy_pool() -> MySqlPool {
        MySqlPoolOptions::new()
            .max_connections(1)
            .connect_lazy("mysql://placeholder:placeholder@127.0.0.1:3306/placeholder")
            .expect("lazy 池构造不应失败")
    }

    /// 记录式假任务：记录被调用的租户顺序，对 `fail_for` 中的租户返回错误。
    struct RecordingJob {
        name: &'static str,
        fail_for: HashSet<TenantId>,
        ran: Arc<Mutex<Vec<TenantId>>>,
    }

    #[async_trait]
    impl Job for RecordingJob {
        fn name(&self) -> &'static str {
            self.name
        }
        fn interval(&self) -> Duration {
            Duration::from_secs(60)
        }
        async fn run_for_tenant(&self, ctx: &JobContext) -> Result<(), AppError> {
            self.ran.lock().unwrap().push(ctx.tenant_id);
            if self.fail_for.contains(&ctx.tenant_id) {
                return Err(AppError::TenantUnavailable);
            }
            Ok(())
        }
    }

    /// 假租户来源：返回固定租户列表，取池恒返回 lazy 池。
    struct FakeProvider {
        tenants: Vec<TenantId>,
    }

    #[async_trait]
    impl TenantProvider for FakeProvider {
        async fn active_tenants(&self) -> Result<Vec<TenantId>, AppError> {
            Ok(self.tenants.clone())
        }
        async fn pool_for(&self, _tenant_id: TenantId) -> Result<MySqlPool, AppError> {
            Ok(lazy_pool())
        }
    }

    /// 可控假锁：按 `acquireable` 决定是否放行，并记录 acquire/release 次数。
    struct FakeLock {
        acquireable: bool,
        acquired: AtomicUsize,
        released: AtomicUsize,
    }

    impl FakeLock {
        fn new(acquireable: bool) -> Self {
            Self {
                acquireable,
                acquired: AtomicUsize::new(0),
                released: AtomicUsize::new(0),
            }
        }
    }

    #[async_trait]
    impl DistributedLock for FakeLock {
        async fn try_acquire(&self, _job_name: &str) -> bool {
            self.acquired.fetch_add(1, Ordering::SeqCst);
            self.acquireable
        }
        async fn release(&self, _job_name: &str) {
            self.released.fetch_add(1, Ordering::SeqCst);
        }
    }

    /// Requirements 11.7：某租户失败不应中断其余租户——所有租户都被尝试，
    /// 失败者计入 `failed`、其余计入 `succeeded`。
    #[tokio::test]
    async fn one_tenant_failure_does_not_abort_others() {
        let ran = Arc::new(Mutex::new(Vec::new()));
        let job = RecordingJob {
            name: "test_job",
            fail_for: HashSet::from([2]),
            ran: ran.clone(),
        };
        let provider = FakeProvider {
            tenants: vec![1, 2, 3],
        };
        let lock = NoopLock;

        let outcome = dispatch_job(&job, &provider, &lock).await;

        // 锁放行，三个租户全部被尝试（含失败的 2）。
        assert!(outcome.lock_acquired);
        assert_eq!(
            *ran.lock().unwrap(),
            vec![1, 2, 3],
            "失败租户不应中断后续租户"
        );
        assert_eq!(outcome.succeeded, vec![1, 3]);
        assert_eq!(outcome.failed, vec![2]);
    }

    /// Requirements 11.7：取池失败的租户被隔离，其余租户仍照常运行。
    #[tokio::test]
    async fn pool_acquire_failure_is_isolated_per_tenant() {
        struct FlakyProvider;
        #[async_trait]
        impl TenantProvider for FlakyProvider {
            async fn active_tenants(&self) -> Result<Vec<TenantId>, AppError> {
                Ok(vec![1, 2, 3])
            }
            async fn pool_for(&self, tenant_id: TenantId) -> Result<MySqlPool, AppError> {
                if tenant_id == 2 {
                    Err(AppError::PoolBuildFailed)
                } else {
                    Ok(lazy_pool())
                }
            }
        }

        let ran = Arc::new(Mutex::new(Vec::new()));
        let job = RecordingJob {
            name: "test_job",
            fail_for: HashSet::new(),
            ran: ran.clone(),
        };

        let outcome = dispatch_job(&job, &FlakyProvider, &NoopLock).await;

        // 租户 2 取池失败 → 未运行 job、计入 failed；1、3 正常运行。
        assert_eq!(*ran.lock().unwrap(), vec![1, 3]);
        assert_eq!(outcome.succeeded, vec![1, 3]);
        assert_eq!(outcome.failed, vec![2]);
    }

    /// Requirements 11.1：未取得锁时整轮跳过——任务不对任何租户运行，且不调用 release。
    #[tokio::test]
    async fn lock_not_acquired_skips_entire_run() {
        let ran = Arc::new(Mutex::new(Vec::new()));
        let job = RecordingJob {
            name: "test_job",
            fail_for: HashSet::new(),
            ran: ran.clone(),
        };
        let provider = FakeProvider {
            tenants: vec![1, 2, 3],
        };
        let lock = FakeLock::new(false);

        let outcome = dispatch_job(&job, &provider, &lock).await;

        assert!(!outcome.lock_acquired, "未取得锁应标记 lock_acquired=false");
        assert!(ran.lock().unwrap().is_empty(), "未取得锁时任务不应运行");
        assert!(outcome.succeeded.is_empty());
        assert!(outcome.failed.is_empty());
        assert_eq!(lock.acquired.load(Ordering::SeqCst), 1, "应尝试获取一次锁");
        assert_eq!(lock.released.load(Ordering::SeqCst), 0, "未取得锁不应释放");
    }

    /// Requirements 11.1：取得锁时正常运行，并在结束后释放锁。
    #[tokio::test]
    async fn lock_acquired_runs_and_releases() {
        let ran = Arc::new(Mutex::new(Vec::new()));
        let job = RecordingJob {
            name: "test_job",
            fail_for: HashSet::new(),
            ran: ran.clone(),
        };
        let provider = FakeProvider {
            tenants: vec![1, 2],
        };
        let lock = FakeLock::new(true);

        let outcome = dispatch_job(&job, &provider, &lock).await;

        assert!(outcome.lock_acquired);
        assert_eq!(outcome.succeeded, vec![1, 2]);
        assert_eq!(lock.acquired.load(Ordering::SeqCst), 1);
        assert_eq!(
            lock.released.load(Ordering::SeqCst),
            1,
            "取得锁后应释放一次"
        );
    }

    /// 枚举启用租户失败时，本轮返回空结果但仍标记已持锁（供调用方释放）。
    #[tokio::test]
    async fn listing_tenants_failure_yields_empty_run() {
        struct FailingProvider;
        #[async_trait]
        impl TenantProvider for FailingProvider {
            async fn active_tenants(&self) -> Result<Vec<TenantId>, AppError> {
                Err(AppError::Db(sqlx::Error::PoolClosed))
            }
            async fn pool_for(&self, _tenant_id: TenantId) -> Result<MySqlPool, AppError> {
                Ok(lazy_pool())
            }
        }

        let ran = Arc::new(Mutex::new(Vec::new()));
        let job = RecordingJob {
            name: "test_job",
            fail_for: HashSet::new(),
            ran: ran.clone(),
        };
        let lock = FakeLock::new(true);

        let outcome = dispatch_job(&job, &FailingProvider, &lock).await;

        assert!(outcome.lock_acquired);
        assert!(outcome.succeeded.is_empty());
        assert!(outcome.failed.is_empty());
        assert!(ran.lock().unwrap().is_empty());
        // 即便枚举失败，也应释放锁，避免锁泄漏。
        assert_eq!(lock.released.load(Ordering::SeqCst), 1);
    }

    /// `InMemoryLock`：同名任务在释放前不可重复获取（同进程去重）。
    #[tokio::test]
    async fn in_memory_lock_prevents_duplicate_until_released() {
        let lock = InMemoryLock::default();
        assert!(lock.try_acquire("job_a").await, "首次获取应成功");
        assert!(!lock.try_acquire("job_a").await, "未释放前重复获取应失败");
        // 不同任务名互不影响。
        assert!(lock.try_acquire("job_b").await);
        lock.release("job_a").await;
        assert!(lock.try_acquire("job_a").await, "释放后应可再次获取");
    }

    /// `NoopLock`：恒获取成功（单实例/开发用）。
    #[tokio::test]
    async fn noop_lock_always_acquires() {
        let lock = NoopLock;
        assert!(lock.try_acquire("x").await);
        assert!(lock.try_acquire("x").await);
        lock.release("x").await;
    }

    /// 调度器可注册任务并按注册顺序执行一轮。
    #[tokio::test]
    async fn scheduler_runs_all_registered_jobs_once() {
        let ran_a = Arc::new(Mutex::new(Vec::new()));
        let ran_b = Arc::new(Mutex::new(Vec::new()));
        let job_a = Arc::new(RecordingJob {
            name: "job_a",
            fail_for: HashSet::new(),
            ran: ran_a.clone(),
        });
        let job_b = Arc::new(RecordingJob {
            name: "job_b",
            fail_for: HashSet::new(),
            ran: ran_b.clone(),
        });

        let provider: Arc<dyn TenantProvider> = Arc::new(FakeProvider {
            tenants: vec![1, 2],
        });
        let lock: Arc<dyn DistributedLock> = Arc::new(NoopLock);
        let scheduler = JobScheduler::new(provider, lock)
            .register(job_a)
            .register(job_b);

        assert_eq!(scheduler.job_count(), 2);
        let outcomes = scheduler.run_all_once().await;

        assert_eq!(outcomes.len(), 2);
        assert_eq!(outcomes[0].job_name, "job_a");
        assert_eq!(outcomes[0].succeeded, vec![1, 2]);
        assert_eq!(outcomes[1].job_name, "job_b");
        assert_eq!(outcomes[1].succeeded, vec![1, 2]);
        assert_eq!(*ran_a.lock().unwrap(), vec![1, 2]);
        assert_eq!(*ran_b.lock().unwrap(), vec![1, 2]);
    }
}
