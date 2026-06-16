//! `TenantPoolManager`：多租户连接池生命周期管理（Task 3.1 / Requirements 1.3–1.7）。
//!
//! 设计依据 `design.md` 4.1–4.5：
//! - **主库（master）**：单一固定连接池，承载全局数据（租户档案、超管、平台授权等）。
//! - **租户库（tenant）**：每租户一个独立 MySQL 库，连接信息存于主库 `tenants.db_dsn_enc`
//!   （加密存储）。连接池**按需懒创建、缓存（`DashMap`）、空闲回收、立即失效**。
//!
//! 关键不变量：
//! - 每个租户连接池的最大连接数恒不超过配置值 `max_conns_per_tenant`（Requirements 1.7）。
//! - 命中缓存即刷新 `last_used`（Requirements 1.4）；空闲超过 `idle_ttl` 即关闭并移除
//!   （Requirements 1.5）；租户禁用/删除/DSN 变更时立即失效（Requirements 1.6）。
//! - 敏感信息（DSN 明文、密码）只在内存态短暂存在，绝不写入日志。

use std::sync::atomic::{AtomicI64, Ordering};
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use dashmap::DashMap;
use sqlx::mysql::{MySqlConnectOptions, MySqlPoolOptions};
use sqlx::MySqlPool;

use crate::error::AppError;

/// 租户标识（主库 `tenants` 主键）。
pub type TenantId = i64;

/// 租户连接信息（来源于主库 `tenants.db_dsn_enc`，解密并解析后的内存态）。
///
/// `password` 为解密后的明文口令，**绝不可落日志**；故本类型实现了自定义
/// [`std::fmt::Debug`]，对口令做脱敏。
#[derive(Clone, PartialEq, Eq)]
pub struct TenantDsn {
    pub host: String,
    pub port: u16,
    pub database: String,
    pub username: String,
    /// 解密后内存态，不落日志（Debug 已脱敏）。
    pub password: String,
}

impl std::fmt::Debug for TenantDsn {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("TenantDsn")
            .field("host", &self.host)
            .field("port", &self.port)
            .field("database", &self.database)
            .field("username", &self.username)
            .field("password", &"***")
            .finish()
    }
}

/// DSN 字符串解析错误（仅用于服务端日志/诊断，不外泄客户端）。
#[derive(Debug, thiserror::Error, PartialEq, Eq)]
pub enum DsnParseError {
    #[error("DSN 缺少 mysql:// 前缀")]
    MissingScheme,
    #[error("DSN 缺少主机")]
    MissingHost,
    #[error("DSN 端口非法: {0}")]
    InvalidPort(String),
    #[error("DSN 缺少数据库名")]
    MissingDatabase,
}

impl TenantDsn {
    /// 从主库存储的加密 DSN 还原为内存态 [`TenantDsn`]（先解密再解析）。
    ///
    /// 失败（解密/解析异常）按 [`AppError::PoolBuildFailed`] 处理，对应设计错误表中
    /// 「DSN 解密失败 → PoolBuildFailed」。底层原因仅记日志，不外泄。
    pub fn from_encrypted(enc: &str) -> Result<TenantDsn, AppError> {
        let plain = decrypt_dsn(enc);
        TenantDsn::parse(&plain).map_err(|e| {
            // 注意：不记录明文 DSN（可能含口令），仅记录解析错误类别。
            tracing::error!(error = %e, "解析租户 DSN 失败");
            AppError::PoolBuildFailed
        })
    }

    /// 解析形如 `mysql://user:password@host:port/database` 的 DSN 字符串。
    ///
    /// - 兼容 `mariadb://` 前缀。
    /// - 缺省端口回退 `3306`。
    /// - 自动剥离 `?...` 查询串与 `#...` 片段。
    /// - 用户名/口令/库名做百分号解码（支持含特殊字符的口令）。
    ///
    /// 不支持 IPv6 字面量主机（`[::1]`）等边缘形态。
    pub fn parse(dsn: &str) -> Result<TenantDsn, DsnParseError> {
        let dsn = dsn.trim();
        let rest = dsn
            .strip_prefix("mysql://")
            .or_else(|| dsn.strip_prefix("mariadb://"))
            .ok_or(DsnParseError::MissingScheme)?;

        // 去除查询串/片段。
        let rest = rest.split(['?', '#']).next().unwrap_or(rest);

        // 拆分 userinfo@hostpart（口令可能不含 '@'，主机段不含 '@'，故按最后一个 '@' 切）。
        let (userinfo, hostpart) = match rest.rsplit_once('@') {
            Some((u, h)) => (Some(u), h),
            None => (None, rest),
        };

        // host[:port] / database
        let (hostport, database) = hostpart
            .split_once('/')
            .ok_or(DsnParseError::MissingDatabase)?;
        if database.is_empty() {
            return Err(DsnParseError::MissingDatabase);
        }

        // host 与可选端口
        let (host, port) = match hostport.rsplit_once(':') {
            Some((h, p)) => {
                let port = p
                    .parse::<u16>()
                    .map_err(|_| DsnParseError::InvalidPort(p.to_string()))?;
                (h, port)
            }
            None => (hostport, 3306u16),
        };
        if host.is_empty() {
            return Err(DsnParseError::MissingHost);
        }

        // userinfo → username[:password]
        let (username, password) = match userinfo {
            Some(ui) => match ui.split_once(':') {
                Some((u, p)) => (percent_decode(u), percent_decode(p)),
                None => (percent_decode(ui), String::new()),
            },
            None => (String::new(), String::new()),
        };

        Ok(TenantDsn {
            host: percent_decode(host),
            port,
            database: percent_decode(database),
            username,
            password,
        })
    }
}

/// 缓存中的租户池条目：连接池 + 最近使用时间（unix 秒）。
struct CachedPool {
    pool: MySqlPool,
    /// 最近一次命中/创建时间（unix 秒），用于空闲回收判定。
    last_used: AtomicI64,
}

/// 多租户连接池管理器。
///
/// 持有主库池与按租户缓存的连接池；按需懒创建、复用、回收、失效。
/// 克隆/共享应通过 `Arc<TenantPoolManager>`（由 `AppState` 持有）。
pub struct TenantPoolManager {
    /// 主库池（全局数据 + 租户 DSN 解析来源）。
    master: MySqlPool,
    /// 租户池缓存：并发安全、读多写少。
    cache: DashMap<TenantId, CachedPool>,
    /// 每租户连接池最大连接数（Requirements 1.7）。
    max_conns_per_tenant: u32,
    /// 空闲回收阈值（Requirements 1.5）。
    idle_ttl: Duration,
}

impl TenantPoolManager {
    /// 构造管理器。`master` 为已建立的主库池。
    pub fn new(master: MySqlPool, max_conns_per_tenant: u32, idle_ttl: Duration) -> Self {
        Self {
            master,
            cache: DashMap::new(),
            max_conns_per_tenant,
            idle_ttl,
        }
    }

    /// 主库池访问（供 `tenant_repo` / 超管功能使用）。
    pub fn master(&self) -> &MySqlPool {
        &self.master
    }

    /// 当前已缓存的租户池数量（用于诊断/测试）。
    pub fn cached_tenant_count(&self) -> usize {
        self.cache.len()
    }

    /// 获取（或懒创建）某租户的连接池（设计 4.4）。
    ///
    /// 前置条件：`tenant_id` 在主库存在且状态为 `active`。
    /// 后置条件：返回可用池并刷新 `last_used`；租户不存在/已停用返回
    /// [`AppError::TenantUnavailable`]；建池失败返回 [`AppError::PoolBuildFailed`]。
    pub async fn pool_for(&self, tenant_id: TenantId) -> Result<MySqlPool, AppError> {
        // 1. 快路径：缓存命中 → 刷新 last_used 后直接复用（Requirements 1.4）。
        if let Some(entry) = self.cache.get(&tenant_id) {
            entry.last_used.store(now_unix(), Ordering::Relaxed);
            return Ok(entry.pool.clone()); // MySqlPool 内部 Arc 共享，clone 廉价。
        }

        // 2. 慢路径：主库取 DSN（含状态校验）（Requirements 1.3）。
        let dsn = crate::repository::tenant_repo::load_active_dsn(&self.master, tenant_id)
            .await?
            .ok_or(AppError::TenantUnavailable)?;

        // 3. 建池后双重检查写缓存，避免并发重复建池。
        let pool = self.build_pool(&dsn).await?;
        let cached = CachedPool {
            pool: pool.clone(),
            last_used: AtomicI64::new(now_unix()),
        };
        // entry API 保证并发下只保留一个；若已有则复用既有池，丢弃本次新建池。
        let entry = self.cache.entry(tenant_id).or_insert(cached);
        Ok(entry.pool.clone())
    }

    /// 由解析后的 DSN 建池（cache 未命中时调用）。
    ///
    /// 强制每租户连接数上限 `max_conns_per_tenant`（Requirements 1.7）。
    /// 失败不缓存坏池，返回 [`AppError::PoolBuildFailed`]，底层原因仅记日志。
    async fn build_pool(&self, dsn: &TenantDsn) -> Result<MySqlPool, AppError> {
        let opts = MySqlConnectOptions::new()
            .host(&dsn.host)
            .port(dsn.port)
            .username(&dsn.username)
            .password(&dsn.password)
            .database(&dsn.database);

        MySqlPoolOptions::new()
            .max_connections(self.max_conns_per_tenant)
            .connect_with(opts)
            .await
            .map_err(|e| {
                // 不记录口令；host/database 非敏感，便于定位。
                tracing::error!(
                    error = %e,
                    host = %dsn.host,
                    database = %dsn.database,
                    "租户连接池建立失败"
                );
                AppError::PoolBuildFailed
            })
    }

    /// 回收空闲超过 `idle_ttl` 的池（由后台 daily_maintain / 定时器调用）（Requirements 1.5）。
    ///
    /// 先收集待回收的租户键（结束对缓存的遍历），再逐一移除并关闭其池，避免遍历期间的并发改动风险。
    pub async fn evict_idle(&self) {
        let now = now_unix();
        let ttl_secs = self.idle_ttl.as_secs() as i64;

        let stale: Vec<TenantId> = self
            .cache
            .iter()
            .filter(|e| now.saturating_sub(e.value().last_used.load(Ordering::Relaxed)) > ttl_secs)
            .map(|e| *e.key())
            .collect();

        for tenant_id in stale {
            if let Some((_, cached)) = self.cache.remove(&tenant_id) {
                cached.pool.close().await;
                tracing::debug!(tenant_id, "回收空闲租户连接池");
            }
        }
    }

    /// 立即失效某租户池（租户禁用/删除/DSN 变更时）（Requirements 1.6）。
    ///
    /// 从缓存移除并优雅关闭其连接池；对未缓存的租户为无操作。
    pub async fn invalidate(&self, tenant_id: TenantId) {
        if let Some((_, cached)) = self.cache.remove(&tenant_id) {
            cached.pool.close().await;
            tracing::info!(tenant_id, "失效租户连接池");
        }
    }
}

/// 当前 unix 时间（秒）。系统时钟早于纪元时回退为 0。
fn now_unix() -> i64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0)
}

/// 解密主库 `tenants.db_dsn_enc` 得到明文 DSN。
///
/// 占位实现（恒等）：当前直接将存储值视为明文 DSN。真正的对称解密将在加密模块
/// 落地后接入此接缝（仅需替换本函数），调用方无需改动。
fn decrypt_dsn(enc: &str) -> String {
    enc.to_string()
}

/// 最小百分号解码（用于 DSN 中的用户名/口令/库名）。无效转义序列原样保留。
fn percent_decode(s: &str) -> String {
    let bytes = s.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'%' && i + 2 < bytes.len() {
            if let (Some(h), Some(l)) = (hex_val(bytes[i + 1]), hex_val(bytes[i + 2])) {
                out.push(h * 16 + l);
                i += 3;
                continue;
            }
        }
        out.push(bytes[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

/// 单个十六进制字符 → 数值。
fn hex_val(b: u8) -> Option<u8> {
    match b {
        b'0'..=b'9' => Some(b - b'0'),
        b'a'..=b'f' => Some(b - b'a' + 10),
        b'A'..=b'F' => Some(b - b'A' + 10),
        _ => None,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use proptest::prelude::*;

    #[test]
    fn parses_full_dsn() {
        let dsn = TenantDsn::parse("mysql://alice:s3cr3t@db.example.com:3307/tenant_42").unwrap();
        assert_eq!(dsn.host, "db.example.com");
        assert_eq!(dsn.port, 3307);
        assert_eq!(dsn.username, "alice");
        assert_eq!(dsn.password, "s3cr3t");
        assert_eq!(dsn.database, "tenant_42");
    }

    #[test]
    fn defaults_port_when_absent() {
        let dsn = TenantDsn::parse("mysql://u:p@localhost/mydb").unwrap();
        assert_eq!(dsn.host, "localhost");
        assert_eq!(dsn.port, 3306);
        assert_eq!(dsn.database, "mydb");
    }

    #[test]
    fn accepts_mariadb_scheme() {
        let dsn = TenantDsn::parse("mariadb://u:p@h:3306/d").unwrap();
        assert_eq!(dsn.host, "h");
    }

    #[test]
    fn strips_query_string() {
        let dsn = TenantDsn::parse("mysql://u:p@h:3306/d?ssl-mode=REQUIRED").unwrap();
        assert_eq!(dsn.database, "d");
    }

    #[test]
    fn percent_decodes_password_with_special_chars() {
        // 口令 "p@ss:w/rd" 经百分号编码后应被正确还原。
        let dsn = TenantDsn::parse("mysql://user:p%40ss%3Aw%2Frd@h:3306/d").unwrap();
        assert_eq!(dsn.password, "p@ss:w/rd");
        assert_eq!(dsn.username, "user");
    }

    #[test]
    fn missing_scheme_is_error() {
        assert_eq!(
            TenantDsn::parse("user:p@h:3306/d"),
            Err(DsnParseError::MissingScheme)
        );
    }

    #[test]
    fn missing_database_is_error() {
        assert_eq!(
            TenantDsn::parse("mysql://u:p@h:3306"),
            Err(DsnParseError::MissingDatabase)
        );
        assert_eq!(
            TenantDsn::parse("mysql://u:p@h:3306/"),
            Err(DsnParseError::MissingDatabase)
        );
    }

    #[test]
    fn invalid_port_is_error() {
        assert_eq!(
            TenantDsn::parse("mysql://u:p@h:notaport/d"),
            Err(DsnParseError::InvalidPort("notaport".into()))
        );
    }

    #[test]
    fn debug_redacts_password() {
        let dsn = TenantDsn {
            host: "h".into(),
            port: 3306,
            database: "d".into(),
            username: "u".into(),
            password: "supersecret".into(),
        };
        let rendered = format!("{dsn:?}");
        assert!(!rendered.contains("supersecret"));
        assert!(rendered.contains("***"));
    }

    #[test]
    fn from_encrypted_maps_parse_failure_to_pool_build_failed() {
        // 当前 decrypt 为恒等，传入非法 DSN 应得到 PoolBuildFailed。
        let err = TenantDsn::from_encrypted("not-a-valid-dsn").unwrap_err();
        assert!(matches!(err, AppError::PoolBuildFailed));
    }

    #[tokio::test]
    async fn evict_and_invalidate_are_safe_on_empty_cache() {
        // 不依赖真实 MySQL：仅验证空缓存下生命周期方法不 panic。
        let mgr = empty_manager();
        assert_eq!(mgr.cached_tenant_count(), 0);
        mgr.evict_idle().await;
        mgr.invalidate(123).await;
        assert_eq!(mgr.cached_tenant_count(), 0);
    }

    /// 构造一个不持有真实连接的管理器（lazy 池在首次使用前不连接）。
    fn empty_manager() -> TenantPoolManager {
        manager_with_ttl(Duration::from_secs(600))
    }

    /// 按指定空闲阈值构造管理器（lazy 池，不触发真实连接）。
    fn manager_with_ttl(idle_ttl: Duration) -> TenantPoolManager {
        let master = lazy_pool();
        TenantPoolManager::new(master, 8, idle_ttl)
    }

    /// 构造一个 lazy MySqlPool 占位池：在首次使用前不会建立真实 TCP 连接，
    /// 因此可在无 MySQL 服务的环境下用于填充缓存、验证生命周期逻辑。
    fn lazy_pool() -> MySqlPool {
        MySqlPoolOptions::new()
            .max_connections(1)
            .connect_lazy("mysql://placeholder:placeholder@127.0.0.1:3306/placeholder")
            .expect("lazy 池构造不应失败")
    }

    /// 向缓存直接写入一个带指定 `last_used` 的租户池条目（测试夹具）。
    /// 在同模块内可访问私有字段 `cache` 与私有类型 `CachedPool`。
    fn seed_cached(mgr: &TenantPoolManager, tenant_id: TenantId, last_used: i64) {
        mgr.cache.insert(
            tenant_id,
            CachedPool {
                pool: lazy_pool(),
                last_used: AtomicI64::new(last_used),
            },
        );
    }

    /// 读取缓存条目当前的 `last_used`（不存在则 None）。
    fn cached_last_used(mgr: &TenantPoolManager, tenant_id: TenantId) -> Option<i64> {
        mgr.cache
            .get(&tenant_id)
            .map(|e| e.last_used.load(Ordering::Relaxed))
    }

    /// Requirements 1.4：命中已缓存的租户池时，复用既有池并刷新 `last_used`。
    #[tokio::test]
    async fn pool_for_cache_hit_refreshes_last_used() {
        let mgr = empty_manager();
        let tenant_id: TenantId = 7;
        // 预置一个 last_used 远在过去的缓存条目。
        let stale_ts = now_unix() - 10_000;
        seed_cached(&mgr, tenant_id, stale_ts);

        // 命中快路径：不访问主库，直接复用并刷新时间戳。
        let before = now_unix();
        let pool = mgr.pool_for(tenant_id).await.expect("缓存命中应直接返回池");
        // 池可用（lazy，未触发真实连接），且缓存仍只有一条目（复用而非新建）。
        let _ = pool;
        assert_eq!(mgr.cached_tenant_count(), 1);

        let refreshed = cached_last_used(&mgr, tenant_id).expect("条目应仍存在");
        assert!(
            refreshed >= before,
            "last_used 应被刷新为当前时间（refreshed={refreshed}, before={before}）"
        );
        assert!(refreshed > stale_ts, "last_used 应大于预置的过期时间戳");
    }

    /// Requirements 1.5：空闲时长超过 `idle_ttl` 的池应被关闭并移除；未超阈值的保留。
    #[tokio::test]
    async fn evict_idle_removes_only_stale_pools() {
        // 极小空闲阈值，便于判定。
        let mgr = manager_with_ttl(Duration::from_secs(1));
        let now = now_unix();
        // 过期：last_used 距今远超 1 秒。
        seed_cached(&mgr, 1, now - 3600);
        // 新鲜：刚刚使用。
        seed_cached(&mgr, 2, now);
        assert_eq!(mgr.cached_tenant_count(), 2);

        mgr.evict_idle().await;

        assert_eq!(mgr.cached_tenant_count(), 1, "仅应回收过期条目");
        assert!(cached_last_used(&mgr, 1).is_none(), "过期租户应被移除");
        assert!(cached_last_used(&mgr, 2).is_some(), "新鲜租户应保留");
    }

    /// Requirements 1.6：失效指定租户时，从缓存移除该租户池而不影响其它租户。
    #[tokio::test]
    async fn invalidate_removes_specific_tenant_only() {
        let mgr = empty_manager();
        let now = now_unix();
        seed_cached(&mgr, 100, now);
        seed_cached(&mgr, 200, now);
        assert_eq!(mgr.cached_tenant_count(), 2);

        mgr.invalidate(100).await;

        assert_eq!(mgr.cached_tenant_count(), 1, "仅应移除被失效的租户");
        assert!(cached_last_used(&mgr, 100).is_none(), "被失效租户应移除");
        assert!(cached_last_used(&mgr, 200).is_some(), "其它租户应保留");
    }

    proptest! {
        /// DSN 解析对「安全字符」组件可往返还原（host/port/user/pass/db）。
        #[test]
        fn parse_roundtrips_safe_components(
            host in "[a-z][a-z0-9.-]{0,20}",
            port in 1u16..=65535,
            user in "[a-zA-Z0-9_]{1,16}",
            pass in "[a-zA-Z0-9_]{0,16}",
            db in "[a-zA-Z0-9_]{1,16}",
        ) {
            let dsn_str = format!("mysql://{user}:{pass}@{host}:{port}/{db}");
            let parsed = TenantDsn::parse(&dsn_str).expect("应解析成功");
            prop_assert_eq!(parsed.host, host);
            prop_assert_eq!(parsed.port, port);
            prop_assert_eq!(parsed.username, user);
            prop_assert_eq!(parsed.password, pass);
            prop_assert_eq!(parsed.database, db);
        }
    }
}
