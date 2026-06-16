//! 员工 / 租户用户数据访问。
//!
//! 仅访问当前租户库 `users` 与 `stores` 表。主库中的租户员工数缓存由调用方在写操作后同步。

use std::collections::{HashMap, HashSet};

use serde::{Deserialize, Serialize};
use sqlx::types::chrono::NaiveDateTime;
use sqlx::{FromRow, MySqlPool};

use crate::error::AppError;
use crate::models::user::{FeatureModule, Role};
use crate::services::auth_service::hash_password;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub enum StoreScopeInput {
    All,
    Restricted(Vec<i64>),
}

#[derive(Debug, Clone)]
pub struct NewTenantUser {
    pub username: String,
    pub password: String,
    pub role: Role,
    pub permissions: HashMap<FeatureModule, bool>,
    pub store_scope: StoreScopeInput,
}

#[derive(Debug, Clone)]
pub struct TenantUserUpdate {
    pub role: Role,
    pub permissions: HashMap<FeatureModule, bool>,
    pub store_scope: StoreScopeInput,
}

#[derive(Debug, Clone, FromRow, Serialize)]
pub struct TenantUserSummary {
    pub id: i64,
    pub username: String,
    pub is_company_admin: bool,
    pub role: String,
    pub permissions: Option<sqlx::types::Json<serde_json::Value>>,
    pub dpqz: String,
    pub dpquancheng: String,
    pub is_active: bool,
    pub created_at: NaiveDateTime,
    pub updated_at: NaiveDateTime,
}

#[derive(Debug, Clone, FromRow, Serialize)]
pub struct StoreScopeOption {
    pub id: i64,
    pub dpqz: String,
    pub dpquancheng: String,
    pub platform: String,
}

pub async fn list_users(pool: &MySqlPool) -> Result<Vec<TenantUserSummary>, AppError> {
    let rows = sqlx::query_as::<_, TenantUserSummary>(
        "SELECT `id`, `username`, `is_company_admin`, `role`, `permissions`, `dpqz`, \
                `dpquancheng`, `is_active`, `created_at`, `updated_at` \
         FROM `users` ORDER BY `is_company_admin` DESC, `is_active` DESC, `id` ASC",
    )
    .fetch_all(pool)
    .await?;

    Ok(rows)
}

pub async fn list_store_scope_options(pool: &MySqlPool) -> Result<Vec<StoreScopeOption>, AppError> {
    let rows = sqlx::query_as::<_, StoreScopeOption>(
        "SELECT `id`, `dpqz`, `dpquancheng`, `platform` \
         FROM `stores` WHERE `is_hidden` = 0 ORDER BY `platform`, `id`",
    )
    .fetch_all(pool)
    .await?;

    Ok(rows)
}

pub async fn create_user(pool: &MySqlPool, new: &NewTenantUser) -> Result<i64, AppError> {
    validate_username(&new.username)?;
    validate_password(&new.password)?;
    validate_store_scope(&new.store_scope)?;

    let password_hash = hash_password(&new.password)?;
    let permissions = permissions_json(&new.permissions)?;
    let (dpqz, dpquancheng) = store_scope_columns(pool, &new.store_scope).await?;

    let result = sqlx::query(
        "INSERT INTO `users` \
         (`username`, `password_hash`, `legacy_password`, `is_company_admin`, `role`, \
          `permissions`, `dpqz`, `dpquancheng`, `is_active`) \
         VALUES (?, ?, NULL, 0, ?, ?, ?, ?, 1)",
    )
    .bind(new.username.trim())
    .bind(password_hash)
    .bind(role_db_value(new.role))
    .bind(permissions)
    .bind(dpqz)
    .bind(dpquancheng)
    .execute(pool)
    .await?;

    Ok(result.last_insert_id() as i64)
}

pub async fn update_user(
    pool: &MySqlPool,
    user_id: i64,
    update: &TenantUserUpdate,
) -> Result<bool, AppError> {
    validate_store_scope(&update.store_scope)?;

    let permissions = permissions_json(&update.permissions)?;
    let (dpqz, dpquancheng) = store_scope_columns(pool, &update.store_scope).await?;
    let result = sqlx::query(
        "UPDATE `users` \
         SET `role` = ?, `permissions` = ?, `dpqz` = ?, `dpquancheng` = ? \
         WHERE `id` = ? AND `is_company_admin` = 0",
    )
    .bind(role_db_value(update.role))
    .bind(permissions)
    .bind(dpqz)
    .bind(dpquancheng)
    .bind(user_id)
    .execute(pool)
    .await?;

    Ok(result.rows_affected() > 0)
}

pub async fn update_user_password(
    pool: &MySqlPool,
    user_id: i64,
    password: &str,
) -> Result<bool, AppError> {
    validate_password(password)?;

    let password_hash = hash_password(password)?;
    let result = sqlx::query(
        "UPDATE `users` SET `password_hash` = ?, `legacy_password` = NULL \
         WHERE `id` = ? AND `is_company_admin` = 0",
    )
    .bind(password_hash)
    .bind(user_id)
    .execute(pool)
    .await?;

    Ok(result.rows_affected() > 0)
}

pub async fn set_user_active(
    pool: &MySqlPool,
    user_id: i64,
    active: bool,
) -> Result<bool, AppError> {
    let result =
        sqlx::query("UPDATE `users` SET `is_active` = ? WHERE `id` = ? AND `is_company_admin` = 0")
            .bind(active)
            .bind(user_id)
            .execute(pool)
            .await?;

    Ok(result.rows_affected() > 0)
}

pub async fn count_active_users(pool: &MySqlPool) -> Result<i64, AppError> {
    let (count,): (i64,) =
        sqlx::query_as("SELECT CAST(COUNT(*) AS SIGNED) FROM `users` WHERE `is_active` = 1")
            .fetch_one(pool)
            .await?;
    Ok(count)
}

pub fn role_db_value(role: Role) -> &'static str {
    match role {
        Role::Buyer => "采购",
        Role::ServiceStaff => "客服",
        Role::ItemChecker => "品检",
    }
}

pub fn role_form_value(role: Role) -> &'static str {
    match role {
        Role::Buyer => "buyer",
        Role::ServiceStaff => "service",
        Role::ItemChecker => "item_checker",
    }
}

pub fn parse_role_value(value: &str) -> Option<Role> {
    match value.trim() {
        "buyer" | "采购" => Some(Role::Buyer),
        "service" | "service_staff" | "客服" => Some(Role::ServiceStaff),
        "item_checker" | "checker" | "品检" => Some(Role::ItemChecker),
        _ => None,
    }
}

pub fn feature_module_key(module: FeatureModule) -> &'static str {
    match module {
        FeatureModule::SystemSettings => "system_settings",
        FeatureModule::OrderLog => "order_log",
        FeatureModule::Log1688 => "1688_log",
        FeatureModule::JpshipinfoLog => "jpshipinfo_log",
        FeatureModule::ShowapiLog => "showapi_log",
        FeatureModule::PerformanceAnalysis => "performance_analysis",
        FeatureModule::PerformanceView => "performance_view",
        FeatureModule::ProductStatistics => "product_statistics",
        FeatureModule::CaigouStats => "caigou_stats",
        FeatureModule::ProfitAnalysis => "profit_analysis",
        FeatureModule::CaigouStatusStats => "caigou_status_stats",
        FeatureModule::ShippingAnomaly => "shipping_anomaly",
        FeatureModule::WowmaBatchSync => "wowma_batch_sync",
        FeatureModule::KefuMail => "kefu_mail",
    }
}

pub fn feature_module_label(module: FeatureModule) -> &'static str {
    match module {
        FeatureModule::SystemSettings => "系统设置",
        FeatureModule::OrderLog => "订单增删日志",
        FeatureModule::Log1688 => "1688 物流查询日志",
        FeatureModule::JpshipinfoLog => "国际物流查询日志",
        FeatureModule::ShowapiLog => "ShowAPI 物流查询日志",
        FeatureModule::PerformanceAnalysis => "性能分析",
        FeatureModule::PerformanceView => "西阵业绩统计",
        FeatureModule::ProductStatistics => "出单商品统计",
        FeatureModule::CaigouStats => "采购员业绩统计",
        FeatureModule::ProfitAnalysis => "利润核算分析",
        FeatureModule::CaigouStatusStats => "采购状态统计",
        FeatureModule::ShippingAnomaly => "国际运费异常检测",
        FeatureModule::WowmaBatchSync => "Wowma 批量同步订单",
        FeatureModule::KefuMail => "客服邮件中心",
    }
}

pub fn selected_modules_csv(permissions: &HashMap<FeatureModule, bool>) -> String {
    FeatureModule::ALL
        .iter()
        .copied()
        .filter(|module| permissions.get(module).copied().unwrap_or(false))
        .map(feature_module_key)
        .collect::<Vec<_>>()
        .join(",")
}

pub fn parse_enabled_permissions(csv: &str) -> HashMap<FeatureModule, bool> {
    let enabled: HashSet<&str> = csv
        .split(',')
        .map(str::trim)
        .filter(|s| !s.is_empty())
        .collect();

    FeatureModule::ALL
        .into_iter()
        .map(|module| (module, enabled.contains(feature_module_key(module))))
        .collect()
}

pub fn parse_store_scope(
    all_stores: bool,
    store_ids_csv: &str,
) -> Result<StoreScopeInput, AppError> {
    if all_stores {
        return Ok(StoreScopeInput::All);
    }

    let mut ids = Vec::new();
    for token in store_ids_csv.split(',') {
        let token = token.trim();
        if token.is_empty() {
            continue;
        }
        let id = token
            .parse::<i64>()
            .map_err(|_| AppError::Validation("店铺范围包含非法店铺 ID".to_string()))?;
        if id <= 0 {
            return Err(AppError::Validation("店铺范围包含非法店铺 ID".to_string()));
        }
        if !ids.contains(&id) {
            ids.push(id);
        }
    }

    if ids.is_empty() {
        return Err(AppError::Validation(
            "请选择至少一个店铺，或勾选全部店铺".to_string(),
        ));
    }

    Ok(StoreScopeInput::Restricted(ids))
}

fn validate_username(username: &str) -> Result<(), AppError> {
    let username = username.trim();
    if username.is_empty() {
        return Err(AppError::Validation("登录账号不能为空".to_string()));
    }
    if username.len() > 128 {
        return Err(AppError::Validation(
            "登录账号不能超过 128 个字符".to_string(),
        ));
    }
    Ok(())
}

fn validate_password(password: &str) -> Result<(), AppError> {
    if password.len() < 8 {
        return Err(AppError::Validation("初始密码至少 8 个字符".to_string()));
    }
    Ok(())
}

fn validate_store_scope(scope: &StoreScopeInput) -> Result<(), AppError> {
    match scope {
        StoreScopeInput::All => Ok(()),
        StoreScopeInput::Restricted(ids) if !ids.is_empty() => Ok(()),
        StoreScopeInput::Restricted(_) => Err(AppError::Validation(
            "请选择至少一个店铺，或勾选全部店铺".to_string(),
        )),
    }
}

fn permissions_json(permissions: &HashMap<FeatureModule, bool>) -> Result<String, AppError> {
    let mut obj = serde_json::Map::new();
    for module in FeatureModule::ALL {
        obj.insert(
            feature_module_key(module).to_string(),
            serde_json::Value::Bool(permissions.get(&module).copied().unwrap_or(false)),
        );
    }
    serde_json::to_string(&serde_json::Value::Object(obj))
        .map_err(|_| AppError::Validation("权限数据无法保存".to_string()))
}

async fn store_scope_columns(
    pool: &MySqlPool,
    scope: &StoreScopeInput,
) -> Result<(String, String), AppError> {
    match scope {
        StoreScopeInput::All => Ok((String::new(), String::new())),
        StoreScopeInput::Restricted(ids) => {
            validate_store_scope(scope)?;
            let names = store_names_for_ids(pool, ids).await?;
            if names.len() != ids.len() {
                return Err(AppError::Validation(
                    "店铺范围中包含不存在或已隐藏的店铺".to_string(),
                ));
            }
            Ok((
                ids.iter()
                    .map(ToString::to_string)
                    .collect::<Vec<_>>()
                    .join(","),
                names.join(","),
            ))
        }
    }
}

async fn store_names_for_ids(pool: &MySqlPool, ids: &[i64]) -> Result<Vec<String>, AppError> {
    let stores = list_store_scope_options(pool).await?;
    let by_id: HashMap<i64, String> = stores
        .into_iter()
        .map(|store| {
            let name = if store.dpquancheng.trim().is_empty() {
                store.dpqz
            } else {
                store.dpquancheng
            };
            (store.id, name)
        })
        .collect();

    let mut names = Vec::with_capacity(ids.len());
    for id in ids {
        if let Some(name) = by_id.get(id) {
            names.push(name.clone());
        }
    }
    Ok(names)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parse_role_value_accepts_form_and_db_values() {
        assert_eq!(parse_role_value("buyer"), Some(Role::Buyer));
        assert_eq!(parse_role_value("采购"), Some(Role::Buyer));
        assert_eq!(parse_role_value("service"), Some(Role::ServiceStaff));
        assert_eq!(parse_role_value("客服"), Some(Role::ServiceStaff));
        assert_eq!(parse_role_value("item_checker"), Some(Role::ItemChecker));
        assert_eq!(parse_role_value("unknown"), None);
    }

    #[test]
    fn permissions_csv_round_trips_enabled_modules() {
        let mut perms = HashMap::new();
        perms.insert(FeatureModule::KefuMail, true);
        perms.insert(FeatureModule::SystemSettings, true);

        let csv = selected_modules_csv(&perms);
        assert!(csv.contains("kefu_mail"));
        assert!(csv.contains("system_settings"));

        let parsed = parse_enabled_permissions(&csv);
        assert_eq!(parsed[&FeatureModule::KefuMail], true);
        assert_eq!(parsed[&FeatureModule::SystemSettings], true);
        assert_eq!(parsed[&FeatureModule::OrderLog], false);
    }

    #[test]
    fn parse_store_scope_supports_all_or_restricted_ids() {
        assert_eq!(parse_store_scope(true, "").unwrap(), StoreScopeInput::All);
        assert_eq!(
            parse_store_scope(false, "1,2,2, 3").unwrap(),
            StoreScopeInput::Restricted(vec![1, 2, 3])
        );
    }

    #[test]
    fn parse_store_scope_requires_selection_when_not_all() {
        assert!(matches!(
            parse_store_scope(false, ""),
            Err(AppError::Validation(_))
        ));
        assert!(matches!(
            parse_store_scope(false, "abc"),
            Err(AppError::Validation(_))
        ));
    }
}
