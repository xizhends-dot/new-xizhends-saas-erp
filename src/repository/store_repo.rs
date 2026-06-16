//! 店铺数据访问。
//!
//! 租户库 `stores` 表承载平台店铺与平台侧凭证。本模块只操作当前请求注入的租户库
//! `MySqlPool`，不访问主库，不触碰外部平台。

use serde::Serialize;
use sqlx::types::chrono::NaiveDateTime;
use sqlx::{FromRow, MySqlPool};

use crate::error::AppError;
use crate::models::store::Store;

/// 店铺列表页行。
#[derive(Debug, Clone, Serialize, FromRow)]
pub struct StoreSummary {
    pub id: i64,
    pub platform: String,
    pub dpqz: String,
    pub dpquancheng: String,
    pub is_hidden: bool,
    pub rms_service_secret: Option<String>,
    pub rms_license_key: Option<String>,
    pub rms_credentials_updated_at: Option<NaiveDateTime>,
    pub last_sync_at: Option<NaiveDateTime>,
    pub last_sync_status: Option<String>,
    pub last_sync_message: Option<String>,
    pub created_at: NaiveDateTime,
    pub updated_at: NaiveDateTime,
}

impl StoreSummary {
    pub fn has_rms_credentials(&self) -> bool {
        credential_pair_is_complete(
            self.rms_service_secret.as_deref(),
            self.rms_license_key.as_deref(),
        )
    }

    pub fn platform_label(&self) -> &'static str {
        platform_label(&self.platform)
    }
}

/// 保存乐天凭证的输入。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RakutenCredentialInput {
    pub service_secret: String,
    pub license_key: String,
}

/// 本地同步检查报告。
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct RakutenSyncCheckReport {
    pub store_id: i64,
    pub store_name: String,
    pub platform: String,
    pub ok: bool,
    pub status: String,
    pub message: String,
    pub checked_steps: Vec<String>,
}

/// 新建店铺输入。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct NewStore {
    pub platform: String,
    pub dpqz: String,
    pub dpquancheng: String,
    pub is_hidden: bool,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StoreUpdate {
    pub dpqz: String,
    pub dpquancheng: String,
    pub is_hidden: bool,
}

const STORE_BASE_COLUMNS: &str = "`id`, `platform`, `dpqz`, `dpquancheng`, `is_hidden`";

const STORE_SUMMARY_COLUMNS: &str = "`id`, `platform`, `dpqz`, `dpquancheng`, `is_hidden`, \
     `rms_service_secret`, `rms_license_key`, `rms_credentials_updated_at`, \
     `last_sync_at`, `last_sync_status`, `last_sync_message`, `created_at`, `updated_at`";

/// 读取店铺基础模型，供权限和旧逻辑使用。
pub async fn list_stores(pool: &MySqlPool) -> Result<Vec<Store>, AppError> {
    let stores = sqlx::query_as::<_, Store>(&format!(
        "SELECT {STORE_BASE_COLUMNS} FROM `stores` ORDER BY `platform`, `id`"
    ))
    .fetch_all(pool)
    .await?;

    Ok(stores)
}

/// 店铺管理列表。
pub async fn list_store_summaries(pool: &MySqlPool) -> Result<Vec<StoreSummary>, AppError> {
    let stores = sqlx::query_as::<_, StoreSummary>(&format!(
        "SELECT {STORE_SUMMARY_COLUMNS} FROM `stores` ORDER BY `platform`, `id`"
    ))
    .fetch_all(pool)
    .await?;

    Ok(stores)
}

/// 按 id 读取店铺管理行。
pub async fn get_store_summary(
    pool: &MySqlPool,
    store_id: i64,
) -> Result<Option<StoreSummary>, AppError> {
    let store = sqlx::query_as::<_, StoreSummary>(&format!(
        "SELECT {STORE_SUMMARY_COLUMNS} FROM `stores` WHERE `id` = ? LIMIT 1"
    ))
    .bind(store_id)
    .fetch_optional(pool)
    .await?;

    Ok(store)
}

/// 新建店铺。当前切片只开放乐天店铺创建，但仓储保持通用字段。
pub async fn create_store(pool: &MySqlPool, new: &NewStore) -> Result<i64, AppError> {
    validate_platform(&new.platform)?;
    validate_store_name(&new.dpqz, "店铺缩写")?;
    validate_store_name(&new.dpquancheng, "店铺全称")?;

    let result = sqlx::query(
        "INSERT INTO `stores` (`platform`, `dpqz`, `dpquancheng`, `is_hidden`) \
         VALUES (?, ?, ?, ?)",
    )
    .bind(new.platform.trim())
    .bind(new.dpqz.trim())
    .bind(new.dpquancheng.trim())
    .bind(new.is_hidden)
    .execute(pool)
    .await?;

    Ok(result.last_insert_id() as i64)
}

pub async fn update_store(
    pool: &MySqlPool,
    store_id: i64,
    update: &StoreUpdate,
) -> Result<bool, AppError> {
    validate_store_name(&update.dpqz, "店铺缩写")?;
    validate_store_name(&update.dpquancheng, "店铺全称")?;

    let result = sqlx::query(
        "UPDATE `stores` SET `dpqz` = ?, `dpquancheng` = ?, `is_hidden` = ?, `updated_at` = NOW() \
         WHERE `id` = ?",
    )
    .bind(update.dpqz.trim())
    .bind(update.dpquancheng.trim())
    .bind(update.is_hidden)
    .bind(store_id)
    .execute(pool)
    .await?;

    Ok(result.rows_affected() > 0)
}

/// 保存乐天 RMS `serviceSecret` / `licenseKey`。
pub async fn save_rakuten_credentials(
    pool: &MySqlPool,
    store_id: i64,
    input: &RakutenCredentialInput,
) -> Result<(), AppError> {
    validate_rakuten_credentials(input)?;
    ensure_rakuten_store(pool, store_id).await?;

    let result = sqlx::query(
        "UPDATE `stores` \
         SET `rms_service_secret` = ?, `rms_license_key` = ?, \
             `rms_credentials_updated_at` = NOW(), `updated_at` = NOW() \
         WHERE `id` = ? AND `platform` = 'r'",
    )
    .bind(input.service_secret.trim())
    .bind(input.license_key.trim())
    .bind(store_id)
    .execute(pool)
    .await?;

    if result.rows_affected() == 0 {
        return Err(AppError::NotFound);
    }

    Ok(())
}

/// 记录一次同步检查结果。这里仅写本地检查状态，不访问外网。
pub async fn record_sync_report(
    pool: &MySqlPool,
    report: &RakutenSyncCheckReport,
) -> Result<(), AppError> {
    sqlx::query(
        "UPDATE `stores` SET `last_sync_at` = NOW(), `last_sync_status` = ?, \
             `last_sync_message` = ?, `updated_at` = NOW() \
         WHERE `id` = ?",
    )
    .bind(&report.status)
    .bind(&report.message)
    .bind(report.store_id)
    .execute(pool)
    .await?;

    Ok(())
}

pub async fn record_sync_status(
    pool: &MySqlPool,
    store_id: i64,
    status: &str,
    message: &str,
) -> Result<(), AppError> {
    sqlx::query(
        "UPDATE `stores` SET `last_sync_at` = NOW(), `last_sync_status` = ?, \
             `last_sync_message` = ?, `updated_at` = NOW() \
         WHERE `id` = ?",
    )
    .bind(status)
    .bind(message)
    .bind(store_id)
    .execute(pool)
    .await?;

    Ok(())
}

/// 本地同步预检。
///
/// 后续接入真实 provider 时，应按 `old/plugins/rakuten-rms-api` 的 `searchOrder` /
/// `getOrder` 流程，通过 `Authorization: ESA base64(serviceSecret:licenseKey)` 请求
/// RMS WEB SERVICE Order API。当前函数只检查本地凭证完整性并生成任务报告。
pub async fn rakuten_sync_check(
    pool: &MySqlPool,
    store_id: i64,
) -> Result<RakutenSyncCheckReport, AppError> {
    let store = get_store_summary(pool, store_id)
        .await?
        .ok_or(AppError::NotFound)?;
    if store.platform != "r" {
        return Err(AppError::Validation(
            "当前同步切片仅支持乐天店铺".to_string(),
        ));
    }

    let mut steps = vec![
        "确认店铺平台为乐天 r".to_string(),
        "检查 RMS serviceSecret".to_string(),
        "检查 RMS licenseKey".to_string(),
    ];
    let store_name = if store.dpquancheng.trim().is_empty() {
        store.dpqz.clone()
    } else {
        store.dpquancheng.clone()
    };

    let ok = store.has_rms_credentials();
    let (status, message) = if ok {
        steps.push("已准备按 searchOrder/getOrder/ESA header 接入 provider".to_string());
        (
            "ready".to_string(),
            "本地凭证检查通过；当前按钮只生成任务报告，未访问乐天 RMS 外网。".to_string(),
        )
    } else {
        (
            "missing_credentials".to_string(),
            "缺少 RMS serviceSecret 或 licenseKey，请先保存凭证。".to_string(),
        )
    };

    Ok(RakutenSyncCheckReport {
        store_id: store.id,
        store_name,
        platform: store.platform,
        ok,
        status,
        message,
        checked_steps: steps,
    })
}

fn validate_platform(platform: &str) -> Result<(), AppError> {
    match platform.trim() {
        "r" => Ok(()),
        _ => Err(AppError::Validation(
            "当前店铺切片仅支持新建乐天店铺".to_string(),
        )),
    }
}

fn validate_store_name(raw: &str, field: &str) -> Result<(), AppError> {
    let value = raw.trim();
    if value.is_empty() {
        return Err(AppError::Validation(format!("{field}不能为空")));
    }
    if value.chars().count() > 255 {
        return Err(AppError::Validation(format!("{field}不能超过 255 个字符")));
    }
    Ok(())
}

fn validate_rakuten_credentials(input: &RakutenCredentialInput) -> Result<(), AppError> {
    let secret = input.service_secret.trim();
    let key = input.license_key.trim();
    if secret.is_empty() || key.is_empty() {
        return Err(AppError::Validation(
            "serviceSecret 和 licenseKey 都不能为空".to_string(),
        ));
    }
    if secret.len() > 255 || key.len() > 255 {
        return Err(AppError::Validation(
            "serviceSecret 和 licenseKey 不能超过 255 字符".to_string(),
        ));
    }
    Ok(())
}

fn credential_pair_is_complete(secret: Option<&str>, key: Option<&str>) -> bool {
    matches!(secret.map(str::trim), Some(s) if !s.is_empty())
        && matches!(key.map(str::trim), Some(s) if !s.is_empty())
}

fn platform_label(platform: &str) -> &'static str {
    match platform {
        "r" => "乐天",
        "y" => "Yahoo购物",
        "w" => "Wowma",
        "m" => "Mercari",
        "q" => "Qoo10",
        "yp" => "雅虎拍卖",
        _ => "未知平台",
    }
}

async fn ensure_rakuten_store(pool: &MySqlPool, store_id: i64) -> Result<(), AppError> {
    let platform: Option<String> =
        sqlx::query_scalar("SELECT `platform` FROM `stores` WHERE `id` = ?")
            .bind(store_id)
            .fetch_optional(pool)
            .await?;

    match platform.as_deref() {
        Some("r") => Ok(()),
        Some(_) => Err(AppError::Validation(
            "只有乐天店铺可以保存 RMS 凭证".to_string(),
        )),
        None => Err(AppError::NotFound),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn credential_pair_requires_both_values() {
        assert!(credential_pair_is_complete(Some("a"), Some("b")));
        assert!(!credential_pair_is_complete(Some("a"), Some("")));
        assert!(!credential_pair_is_complete(Some(""), Some("b")));
        assert!(!credential_pair_is_complete(None, Some("b")));
    }

    #[test]
    fn platform_label_knows_rakuten() {
        assert_eq!(platform_label("r"), "乐天");
        assert_eq!(platform_label("unknown"), "未知平台");
    }

    #[test]
    fn validates_only_rakuten_creation() {
        assert!(validate_platform("r").is_ok());
        assert!(validate_platform("w").is_err());
    }
}
