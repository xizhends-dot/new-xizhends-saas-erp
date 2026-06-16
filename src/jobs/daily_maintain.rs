//! 定时任务：日常维护聚合 + 连接池空闲回收（Task 14.8 / Requirements 11.1）。
//!
//! 语义对应 old 系统 `cron/daily_maintain.php`：夜间执行的聚合维护脚本，统一做若干
//! 「低频清理 / 缓存重置」动作。old 的具体动作（重置 `ph_obtb_cache`、删除 30 天前的
//! `ph_log_obtb` / `ph_log_shipinfo` / `ph_log_1688api` / `ph_log_express_showapi` 等
//! 瞬时调用日志）依赖的那些日志/缓存表在规范化后的租户库中已不存在；本实现把日常维护
//! 收敛为两件仍然成立的事：
//! 1. **审计日志保留**：删除 `order_logs` 中早于保留期的旧审计记录（瞬时维护日志的等价物）。
//! 2. **连接池空闲回收**：调用
//!    [`TenantPoolManager::evict_idle`](crate::db::pool::TenantPoolManager::evict_idle)
//!    关闭并移除空闲超阈值的租户连接池（Requirements 1.5）。这是新系统特有、old 无对应的
//!    资源维护——多租户按需建池后须周期性回收空闲池，正好挂在每日维护窗口里触发。
//!
//! 本任务是把其余维护动作「聚合」在一起的入口：随着系统演进，新的低频清理动作应加到这里，
//! 与连接池回收一起在维护窗口统一执行。
//!
//! ## 纯函数接缝
//! 「保留期截止时间」是纯计算 [`log_retention_cutoff`]，无 IO、可单测；DB 删除与连接池回收
//! 为副作用，分别由租户库与连接池管理器承担。

use std::sync::Arc;
use std::time::Duration;

use chrono::{Duration as ChronoDuration, NaiveDate, Utc};
use sqlx::MySqlPool;

use crate::db::pool::TenantPoolManager;
use crate::error::AppError;
use crate::jobs::scheduler::{Job, JobContext};

/// 审计日志默认保留天数。
///
/// old 对**瞬时 API 调用日志**用 30 天；`order_logs` 属订单审计日志，价值更高，故保留更久
/// （默认 90 天）。可经 [`DailyMaintainJob::with_retention_days`] 调整。
pub const DEFAULT_LOG_RETENTION_DAYS: i64 = 90;

// ───────────────────────────────────────────────────────────────────────────
// 纯函数辅助（无 IO，便于单元测试）
// ───────────────────────────────────────────────────────────────────────────

/// 计算审计日志保留截止时间戳（纯函数）：`(today - retention_days)` 当日 `00:00:00`。
///
/// 复刻 old `date('Y-m-d 00:00:00', strtotime('-N days'))`：删除 `created_at < cutoff` 的记录，
/// 即只保留最近 `retention_days` 天（含当日）。返回 `YYYY-MM-DD 00:00:00` 文本（MySQL DATETIME 可比较）。
pub fn log_retention_cutoff(today: NaiveDate, retention_days: i64) -> String {
    let cutoff_day = today - ChronoDuration::days(retention_days);
    format!("{} 00:00:00", cutoff_day.format("%Y-%m-%d"))
}

// ───────────────────────────────────────────────────────────────────────────
// 维护动作
// ───────────────────────────────────────────────────────────────────────────

/// 删除单个租户库中早于保留期的审计日志，返回删除行数。
async fn prune_order_logs(pool: &MySqlPool, cutoff: &str) -> Result<u64, AppError> {
    let result = sqlx::query("DELETE FROM `order_logs` WHERE `created_at` < ?")
        .bind(cutoff)
        .execute(pool)
        .await?;
    Ok(result.rows_affected())
}

/// 触发连接池空闲回收（Requirements 1.5）：关闭并移除空闲超阈值的租户连接池。
///
/// 这是 daily_maintain 把「资源维护」聚合进每日窗口的关键一环——独立成函数便于复用与
/// 显式表达意图。回收为幂等操作：仅淘汰确已空闲的池，重复调用安全。
pub async fn reclaim_idle_connection_pools(pools: &TenantPoolManager) {
    pools.evict_idle().await;
    tracing::info!(
        cached = pools.cached_tenant_count(),
        "daily_maintain：连接池空闲回收完成"
    );
}

// ───────────────────────────────────────────────────────────────────────────
// 定时任务
// ───────────────────────────────────────────────────────────────────────────

/// `daily_maintain` 定时任务：聚合日常维护并触发连接池空闲回收。
///
/// 持有连接池管理器以便在维护窗口触发空闲回收。[`Job::run_for_tenant`] 对每个租户库
/// 执行审计日志保留清理；连接池回收为进程级（非按租户），由 [`Self::reclaim`] 暴露，
/// 并在每轮维护时触发一次（幂等，多次调用仅淘汰确已空闲的池）。
pub struct DailyMaintainJob {
    pools: Arc<TenantPoolManager>,
    retention_days: i64,
}

impl DailyMaintainJob {
    /// 用连接池管理器构造（采用默认日志保留天数）。
    pub fn new(pools: Arc<TenantPoolManager>) -> Self {
        Self {
            pools,
            retention_days: DEFAULT_LOG_RETENTION_DAYS,
        }
    }

    /// 覆盖审计日志保留天数（链式）。
    pub fn with_retention_days(mut self, retention_days: i64) -> Self {
        self.retention_days = retention_days;
        self
    }

    /// 触发连接池空闲回收（委托 [`reclaim_idle_connection_pools`]）。
    pub async fn reclaim(&self) {
        reclaim_idle_connection_pools(&self.pools).await;
    }
}

#[async_trait::async_trait]
impl Job for DailyMaintainJob {
    fn name(&self) -> &'static str {
        "daily_maintain"
    }

    fn interval(&self) -> Duration {
        // 每日一次（old crontab 夜间执行）。
        Duration::from_secs(24 * 60 * 60)
    }

    async fn run_for_tenant(&self, ctx: &JobContext) -> Result<(), AppError> {
        // 1) 租户级：清理早于保留期的审计日志。
        let cutoff = log_retention_cutoff(Utc::now().date_naive(), self.retention_days);
        let pruned = prune_order_logs(&ctx.pool, &cutoff).await?;
        tracing::info!(tenant_id = ctx.tenant_id, pruned, cutoff = %cutoff, "daily_maintain：审计日志清理完成");

        // 2) 进程级：在维护窗口触发连接池空闲回收（幂等）。把资源维护聚合进每日维护。
        self.reclaim().await;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn d(y: i32, m: u32, day: u32) -> NaiveDate {
        NaiveDate::from_ymd_opt(y, m, day).unwrap()
    }

    #[test]
    fn retention_cutoff_subtracts_days_at_midnight() {
        // 2024-06-15 保留 90 天 → 截止 2024-03-17 00:00:00。
        assert_eq!(
            log_retention_cutoff(d(2024, 6, 15), 90),
            "2024-03-17 00:00:00"
        );
    }

    #[test]
    fn retention_cutoff_borrows_across_year() {
        // 2024-01-10 保留 30 天 → 2023-12-11 00:00:00。
        assert_eq!(
            log_retention_cutoff(d(2024, 1, 10), 30),
            "2023-12-11 00:00:00"
        );
    }

    #[test]
    fn retention_cutoff_zero_days_is_today_midnight() {
        assert_eq!(
            log_retention_cutoff(d(2024, 6, 15), 0),
            "2024-06-15 00:00:00"
        );
    }

    #[test]
    fn default_retention_is_ninety_days() {
        assert_eq!(DEFAULT_LOG_RETENTION_DAYS, 90);
    }

    /// 连接池回收在空缓存下安全（不 panic），并保持缓存为空。
    #[tokio::test]
    async fn reclaim_is_safe_on_empty_pool_manager() {
        let master = sqlx::mysql::MySqlPoolOptions::new()
            .max_connections(1)
            .connect_lazy("mysql://placeholder:placeholder@127.0.0.1:3306/placeholder")
            .expect("lazy 池构造不应失败");
        let pools = Arc::new(TenantPoolManager::new(master, 8, Duration::from_secs(600)));

        let job = DailyMaintainJob::new(pools.clone());
        job.reclaim().await;
        assert_eq!(pools.cached_tenant_count(), 0);
    }

    #[tokio::test]
    async fn job_metadata_is_stable() {
        let master = sqlx::mysql::MySqlPoolOptions::new()
            .max_connections(1)
            .connect_lazy("mysql://placeholder:placeholder@127.0.0.1:3306/placeholder")
            .expect("lazy 池构造不应失败");
        let pools = Arc::new(TenantPoolManager::new(master, 8, Duration::from_secs(600)));
        let job = DailyMaintainJob::new(pools);
        assert_eq!(job.name(), "daily_maintain");
        assert_eq!(job.interval(), Duration::from_secs(86_400));
    }
}
