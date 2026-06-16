//! `AppState`：主库 `MySqlPool` + `TenantPoolManager` + `Tera` + `AppConfig`（Task 1.4）。
//!
//! 设计依据 `design.md` 2.3（`state.rs` 持有主库池 + 连接池管理器 + Tera + 配置）与
//! Requirements 1.3（首次访问租户时由主库读取 DSN 建池）。
//!
//! 共享策略：`AppState` 内部把所有字段收进一个 [`AppStateInner`] 并以 `Arc` 包裹，
//! 外层 `#[derive(Clone)]` 仅克隆该 `Arc`——因此可作为 `axum` 的共享状态在所有
//! handler / 中间件间廉价克隆传递（`axum` 要求 state 实现 `Clone`）。
//!
//! 主库连接池被同时持有于两处：
//! - `AppStateInner.master`：直接暴露给只访问主库的代码（`tenant_repo` / 超管功能）；
//! - `TenantPoolManager`：内部亦持有主库池，用于按需解析租户 DSN 并建池。
//!
//! 二者是同一个池的廉价克隆（`MySqlPool` 内部为 `Arc` 共享），不会产生重复连接。

use std::sync::Arc;

use sqlx::mysql::MySqlPoolOptions;
use sqlx::MySqlPool;
use tera::Tera;

use crate::config::AppConfig;
use crate::db::pool::TenantPoolManager;
use crate::error::AppError;

/// 默认 Tera 模板搜索通配符（相对工作目录）。
///
/// 对应 `design.md` 2.3 中的 `src/templates/` 模板目录。允许目录暂时为空：
/// 加载不到任何模板时回退为空的 [`Tera`] 实例，不阻断启动（模板会在后续任务陆续加入）。
const DEFAULT_TEMPLATE_GLOB: &str = "src/templates/**/*.html";

/// 应用共享状态。
///
/// 通过内部 `Arc<AppStateInner>` 实现 `Clone` 的廉价共享，可直接用作 `axum` 的
/// `with_state` 参数并被各 handler 通过 `State<AppState>` 提取。
#[derive(Clone)]
pub struct AppState {
    inner: Arc<AppStateInner>,
}

/// `AppState` 的实际持有者；仅经由 `Arc` 共享，不单独对外。
struct AppStateInner {
    /// 主库连接池（全局数据：租户档案 / 超管 / 平台授权 / 公告）。
    master: MySqlPool,
    /// 多租户连接池管理器（按需懒创建 / 缓存 / 回收租户库连接池）。
    pools: Arc<TenantPoolManager>,
    /// Tera 模板引擎。
    tera: Tera,
    /// 运行期配置。
    config: AppConfig,
}

impl AppState {
    /// 从配置装配应用状态：建主库池 → 构造连接池管理器 → 加载 Tera 模板。
    ///
    /// 前置条件：`config` 已通过校验（见 [`AppConfig::from_env`]）。
    /// 后置条件：返回可共享的 [`AppState`]；主库不可达时返回 [`AppError::PoolBuildFailed`]。
    ///
    /// 模板从 [`DEFAULT_TEMPLATE_GLOB`] 加载，目录为空时容忍并回退为空引擎。
    pub async fn new(config: AppConfig) -> Result<Self, AppError> {
        let master = build_master_pool(&config).await?;
        let tera = load_templates(DEFAULT_TEMPLATE_GLOB);
        Ok(Self::from_parts(master, tera, config))
    }

    /// 由已构建好的部件装配状态（主库池 + Tera + 配置）。
    ///
    /// 便于测试注入受控的主库池（如 `connect_lazy` 池）与模板引擎，无需真实 MySQL。
    /// 连接池管理器在此构造，并复用传入的主库池（廉价 `Arc` 克隆）。
    pub fn from_parts(master: MySqlPool, tera: Tera, config: AppConfig) -> Self {
        let pools = Arc::new(TenantPoolManager::new(
            master.clone(),
            config.max_conns_per_tenant,
            config.tenant_pool_idle_ttl,
        ));
        Self {
            inner: Arc::new(AppStateInner {
                master,
                pools,
                tera,
                config,
            }),
        }
    }

    /// 主库连接池（供仅访问主库的 `tenant_repo` / 超管功能使用）。
    pub fn master_pool(&self) -> &MySqlPool {
        &self.inner.master
    }

    /// 多租户连接池管理器（共享句柄）。
    pub fn pools(&self) -> &Arc<TenantPoolManager> {
        &self.inner.pools
    }

    /// Tera 模板引擎。
    pub fn tera(&self) -> &Tera {
        &self.inner.tera
    }

    /// 运行期配置。
    pub fn config(&self) -> &AppConfig {
        &self.inner.config
    }
}

/// 由配置中的主库 DSN 建立主库连接池。
///
/// 强制以配置的每租户最大连接数同口径限制主库连接数，避免主库连接失控。
/// 建池失败（DSN 非法 / MySQL 不可达）返回 [`AppError::PoolBuildFailed`]，底层原因仅记日志。
async fn build_master_pool(config: &AppConfig) -> Result<MySqlPool, AppError> {
    MySqlPoolOptions::new()
        .max_connections(config.max_conns_per_tenant)
        .connect(&config.master_db_dsn)
        .await
        .map_err(|e| {
            // 不记录 DSN（可能含口令），仅记录错误类别。
            tracing::error!(error = %e, "主库连接池建立失败");
            AppError::PoolBuildFailed
        })
}

/// 加载 Tera 模板，容忍模板目录为空。
///
/// `Tera::new` 在通配符无法解析时才报错；目录为空（无匹配文件）通常返回空引擎。
/// 为稳健起见，任何加载错误都记日志并回退为 [`Tera::default`]，确保启动不被模板问题阻断。
fn load_templates(glob: &str) -> Tera {
    match Tera::new(glob) {
        Ok(tera) => tera,
        Err(e) => {
            tracing::warn!(error = %e, glob, "Tera 模板加载失败，回退为空模板引擎");
            Tera::default()
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::PathBuf;
    use std::time::Duration;

    /// 构造一份合法配置（不触发真实连接）。
    fn test_config() -> AppConfig {
        AppConfig {
            listen_addr: "127.0.0.1:8080".parse().unwrap(),
            master_db_dsn: "mysql://u:p@127.0.0.1:3306/master".to_string(),
            session_secret: "x".repeat(32),
            max_conns_per_tenant: 8,
            tenant_pool_idle_ttl: Duration::from_secs(600),
            external_api_credentials_path: PathBuf::from("/tmp/creds.json"),
        }
    }

    /// 构造一个 lazy 主库池（首次使用前不实际连接）。
    fn lazy_master() -> MySqlPool {
        MySqlPoolOptions::new()
            .max_connections(1)
            .connect_lazy("mysql://placeholder:placeholder@127.0.0.1:3306/placeholder")
            .expect("lazy 池构造不应失败")
    }

    #[tokio::test]
    async fn from_parts_exposes_all_components() {
        let cfg = test_config();
        let state = AppState::from_parts(lazy_master(), Tera::default(), cfg.clone());

        // 配置可读且与传入一致。
        assert_eq!(state.config(), &cfg);
        // 连接池管理器初始无缓存租户。
        assert_eq!(state.pools().cached_tenant_count(), 0);
        // 主库池与管理器内主库池为同一来源（句柄可获取）。
        let _ = state.master_pool();
        // Tera 句柄可获取。
        let _ = state.tera();
    }

    #[tokio::test]
    async fn clone_is_cheap_and_shares_inner() {
        let state = AppState::from_parts(lazy_master(), Tera::default(), test_config());
        let cloned = state.clone();
        // 两个克隆共享同一 inner（Arc 强引用计数 >= 2）。
        assert_eq!(Arc::strong_count(&state.inner), 2);
        // 共享的连接池管理器是同一实例。
        assert!(Arc::ptr_eq(state.pools(), cloned.pools()));
    }

    #[test]
    fn load_templates_tolerates_empty_directory() {
        // 指向一个不存在任何模板的通配符：应回退为空引擎而非 panic。
        let tera = load_templates("src/templates/__nonexistent__/**/*.html");
        // 空引擎下渲染未注册模板会出错，但实例本身有效（可获取模板名集合）。
        let names: Vec<_> = tera.get_template_names().collect();
        assert!(names.is_empty());
    }
}
