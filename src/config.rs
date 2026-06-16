//! `AppConfig`：监听地址、主库 DSN、会话密钥、连接池参数、外部 API 凭证路径。
//!
//! 从环境变量（或注入的键值源）加载并校验运行所需配置（Task 1.2）。
//!
//! 设计依据 `design.md` 2.3/2.4 与 Requirements 1.7（连接池每租户最大连接数限制）。
//!
//! 支持的环境变量：
//! - `LISTEN_ADDR`：HTTP 监听地址，形如 `0.0.0.0:8080`（默认 `127.0.0.1:8080`）。
//! - `MASTER_DB_DSN`：主库连接串（必填）。
//! - `SESSION_SECRET`：会话签名/加密密钥，至少 32 字节（必填）。
//! - `MAX_CONNS_PER_TENANT`：每租户连接池最大连接数（默认 `10`，必须 ≥ 1）。
//! - `TENANT_POOL_IDLE_TTL_SECS`：租户连接池空闲回收阈值，单位秒（默认 `600`，必须 ≥ 1）。
//! - `EXTERNAL_API_CREDENTIALS_PATH`：外部 API 凭证文件路径（必填）。

use std::net::SocketAddr;
use std::path::PathBuf;
use std::time::Duration;

/// 会话密钥最小长度（字节）。低于该长度的密钥会被拒绝，避免弱密钥。
const MIN_SESSION_SECRET_LEN: usize = 32;

/// 默认监听地址。
const DEFAULT_LISTEN_ADDR: &str = "127.0.0.1:8080";

/// 默认每租户最大连接数。
const DEFAULT_MAX_CONNS_PER_TENANT: u32 = 10;

/// 默认租户连接池空闲回收阈值（秒）。
const DEFAULT_IDLE_TTL_SECS: u64 = 600;

/// 应用运行期配置。
///
/// 由 [`AppConfig::from_env`] 从环境变量加载并校验后产生；克隆成本低，可随 `AppState` 共享。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct AppConfig {
    /// HTTP 服务监听地址。
    pub listen_addr: SocketAddr,
    /// 主库（Master_DB）连接串。
    pub master_db_dsn: String,
    /// 会话签名/加密密钥。
    pub session_secret: String,
    /// 每个租户连接池允许的最大连接数（Requirements 1.7）。
    pub max_conns_per_tenant: u32,
    /// 租户连接池空闲回收阈值。
    pub tenant_pool_idle_ttl: Duration,
    /// 外部 API 凭证文件路径。
    pub external_api_credentials_path: PathBuf,
}

/// 配置加载/校验过程中可能出现的错误。
#[derive(Debug, thiserror::Error, PartialEq, Eq)]
pub enum ConfigError {
    /// 必填项缺失。
    #[error("缺少必填配置项: {0}")]
    Missing(&'static str),

    /// 某项的值非法（含字段名与原因）。
    #[error("配置项 {key} 取值非法: {reason}")]
    Invalid {
        /// 出错的配置键名。
        key: &'static str,
        /// 失败原因。
        reason: String,
    },
}

impl ConfigError {
    fn invalid(key: &'static str, reason: impl Into<String>) -> Self {
        ConfigError::Invalid {
            key,
            reason: reason.into(),
        }
    }
}

impl AppConfig {
    /// 从进程环境变量加载并校验配置。
    pub fn from_env() -> Result<Self, ConfigError> {
        Self::from_source(|key| std::env::var(key).ok())
    }

    /// 从任意键值源加载并校验配置。
    ///
    /// 抽出此函数便于单元测试注入受控的键值对，而不污染进程环境。
    /// `get` 对缺失键返回 `None`；空字符串视为「已设置但为空」，按非法处理。
    pub fn from_source<F>(get: F) -> Result<Self, ConfigError>
    where
        F: Fn(&str) -> Option<String>,
    {
        // 监听地址：可选，缺省回退默认值。
        let listen_addr_raw =
            non_empty(get("LISTEN_ADDR")).unwrap_or_else(|| DEFAULT_LISTEN_ADDR.to_string());
        let listen_addr = listen_addr_raw.parse::<SocketAddr>().map_err(|e| {
            ConfigError::invalid("LISTEN_ADDR", format!("无法解析为 socket 地址: {e}"))
        })?;

        // 主库 DSN：必填且非空。
        let master_db_dsn =
            non_empty(get("MASTER_DB_DSN")).ok_or(ConfigError::Missing("MASTER_DB_DSN"))?;

        // 会话密钥：必填且需满足最小长度。
        let session_secret =
            non_empty(get("SESSION_SECRET")).ok_or(ConfigError::Missing("SESSION_SECRET"))?;
        if session_secret.len() < MIN_SESSION_SECRET_LEN {
            return Err(ConfigError::invalid(
                "SESSION_SECRET",
                format!(
                    "长度至少为 {MIN_SESSION_SECRET_LEN} 字节，当前为 {}",
                    session_secret.len()
                ),
            ));
        }

        // 每租户最大连接数：可选，缺省回退默认值；必须 ≥ 1。
        let max_conns_per_tenant = match non_empty(get("MAX_CONNS_PER_TENANT")) {
            Some(raw) => raw.parse::<u32>().map_err(|e| {
                ConfigError::invalid("MAX_CONNS_PER_TENANT", format!("无法解析为正整数: {e}"))
            })?,
            None => DEFAULT_MAX_CONNS_PER_TENANT,
        };
        if max_conns_per_tenant < 1 {
            return Err(ConfigError::invalid("MAX_CONNS_PER_TENANT", "必须至少为 1"));
        }

        // 空闲回收阈值（秒）：可选，缺省回退默认值；必须 ≥ 1。
        let idle_ttl_secs = match non_empty(get("TENANT_POOL_IDLE_TTL_SECS")) {
            Some(raw) => raw.parse::<u64>().map_err(|e| {
                ConfigError::invalid(
                    "TENANT_POOL_IDLE_TTL_SECS",
                    format!("无法解析为非负整数: {e}"),
                )
            })?,
            None => DEFAULT_IDLE_TTL_SECS,
        };
        if idle_ttl_secs < 1 {
            return Err(ConfigError::invalid(
                "TENANT_POOL_IDLE_TTL_SECS",
                "必须至少为 1 秒",
            ));
        }
        let tenant_pool_idle_ttl = Duration::from_secs(idle_ttl_secs);

        // 外部 API 凭证路径：必填且非空。
        let external_api_credentials_path = non_empty(get("EXTERNAL_API_CREDENTIALS_PATH"))
            .map(PathBuf::from)
            .ok_or(ConfigError::Missing("EXTERNAL_API_CREDENTIALS_PATH"))?;

        Ok(AppConfig {
            listen_addr,
            master_db_dsn,
            session_secret,
            max_conns_per_tenant,
            tenant_pool_idle_ttl,
            external_api_credentials_path,
        })
    }
}

/// 把 `Some("")`（已设置但为空）归一为 `None`，并去除首尾空白。
fn non_empty(value: Option<String>) -> Option<String> {
    value
        .map(|v| v.trim().to_string())
        .filter(|v| !v.is_empty())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::collections::HashMap;

    /// 构造一份齐全且合法的键值源。
    fn full_env() -> HashMap<&'static str, String> {
        let mut m = HashMap::new();
        m.insert("LISTEN_ADDR", "0.0.0.0:9090".to_string());
        m.insert(
            "MASTER_DB_DSN",
            "mysql://user:pass@localhost/master".to_string(),
        );
        m.insert("SESSION_SECRET", "x".repeat(MIN_SESSION_SECRET_LEN));
        m.insert("MAX_CONNS_PER_TENANT", "20".to_string());
        m.insert("TENANT_POOL_IDLE_TTL_SECS", "120".to_string());
        m.insert(
            "EXTERNAL_API_CREDENTIALS_PATH",
            "/etc/xizhends/creds.json".to_string(),
        );
        m
    }

    fn source(map: HashMap<&'static str, String>) -> impl Fn(&str) -> Option<String> {
        move |k| map.get(k).cloned()
    }

    #[test]
    fn loads_full_config() {
        let cfg = AppConfig::from_source(source(full_env())).expect("应加载成功");
        assert_eq!(
            cfg.listen_addr,
            "0.0.0.0:9090".parse::<SocketAddr>().unwrap()
        );
        assert_eq!(cfg.master_db_dsn, "mysql://user:pass@localhost/master");
        assert_eq!(cfg.session_secret.len(), MIN_SESSION_SECRET_LEN);
        assert_eq!(cfg.max_conns_per_tenant, 20);
        assert_eq!(cfg.tenant_pool_idle_ttl, Duration::from_secs(120));
        assert_eq!(
            cfg.external_api_credentials_path,
            PathBuf::from("/etc/xizhends/creds.json")
        );
    }

    #[test]
    fn applies_defaults_for_optional_keys() {
        let mut m = full_env();
        m.remove("LISTEN_ADDR");
        m.remove("MAX_CONNS_PER_TENANT");
        m.remove("TENANT_POOL_IDLE_TTL_SECS");
        let cfg = AppConfig::from_source(source(m)).expect("应使用默认值加载成功");
        assert_eq!(
            cfg.listen_addr,
            DEFAULT_LISTEN_ADDR.parse::<SocketAddr>().unwrap()
        );
        assert_eq!(cfg.max_conns_per_tenant, DEFAULT_MAX_CONNS_PER_TENANT);
        assert_eq!(
            cfg.tenant_pool_idle_ttl,
            Duration::from_secs(DEFAULT_IDLE_TTL_SECS)
        );
    }

    #[test]
    fn missing_master_dsn_is_error() {
        let mut m = full_env();
        m.remove("MASTER_DB_DSN");
        assert_eq!(
            AppConfig::from_source(source(m)),
            Err(ConfigError::Missing("MASTER_DB_DSN"))
        );
    }

    #[test]
    fn missing_session_secret_is_error() {
        let mut m = full_env();
        m.remove("SESSION_SECRET");
        assert_eq!(
            AppConfig::from_source(source(m)),
            Err(ConfigError::Missing("SESSION_SECRET"))
        );
    }

    #[test]
    fn missing_credentials_path_is_error() {
        let mut m = full_env();
        m.remove("EXTERNAL_API_CREDENTIALS_PATH");
        assert_eq!(
            AppConfig::from_source(source(m)),
            Err(ConfigError::Missing("EXTERNAL_API_CREDENTIALS_PATH"))
        );
    }

    #[test]
    fn empty_value_treated_as_missing() {
        let mut m = full_env();
        m.insert("MASTER_DB_DSN", "   ".to_string());
        assert_eq!(
            AppConfig::from_source(source(m)),
            Err(ConfigError::Missing("MASTER_DB_DSN"))
        );
    }

    #[test]
    fn short_session_secret_is_invalid() {
        let mut m = full_env();
        m.insert("SESSION_SECRET", "tooshort".to_string());
        let err = AppConfig::from_source(source(m)).unwrap_err();
        assert!(matches!(
            err,
            ConfigError::Invalid {
                key: "SESSION_SECRET",
                ..
            }
        ));
    }

    #[test]
    fn invalid_listen_addr_is_invalid() {
        let mut m = full_env();
        m.insert("LISTEN_ADDR", "not-an-addr".to_string());
        let err = AppConfig::from_source(source(m)).unwrap_err();
        assert!(matches!(
            err,
            ConfigError::Invalid {
                key: "LISTEN_ADDR",
                ..
            }
        ));
    }

    #[test]
    fn non_numeric_max_conns_is_invalid() {
        let mut m = full_env();
        m.insert("MAX_CONNS_PER_TENANT", "abc".to_string());
        let err = AppConfig::from_source(source(m)).unwrap_err();
        assert!(matches!(
            err,
            ConfigError::Invalid {
                key: "MAX_CONNS_PER_TENANT",
                ..
            }
        ));
    }

    #[test]
    fn zero_max_conns_is_invalid() {
        let mut m = full_env();
        m.insert("MAX_CONNS_PER_TENANT", "0".to_string());
        let err = AppConfig::from_source(source(m)).unwrap_err();
        assert!(matches!(
            err,
            ConfigError::Invalid {
                key: "MAX_CONNS_PER_TENANT",
                ..
            }
        ));
    }

    #[test]
    fn zero_idle_ttl_is_invalid() {
        let mut m = full_env();
        m.insert("TENANT_POOL_IDLE_TTL_SECS", "0".to_string());
        let err = AppConfig::from_source(source(m)).unwrap_err();
        assert!(matches!(
            err,
            ConfigError::Invalid {
                key: "TENANT_POOL_IDLE_TTL_SECS",
                ..
            }
        ));
    }
}
