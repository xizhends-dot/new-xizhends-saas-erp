//! 预付费计费数据访问（主库）。
//!
//! 计费口径当前固定为：
//! - 全局单价：每个平台账号每月单价，默认 50 RMB；
//! - 计费单元：`max(tenants.staff_count, 1) * enabled_platform_count`；
//! - 可用判断：租户状态为 active，且预存余额 >= 当前月预估费用。

use serde::Serialize;
use sqlx::types::chrono::NaiveDateTime;
use sqlx::{FromRow, MySqlPool};

use crate::error::AppError;

pub const DEFAULT_CURRENCY: &str = "RMB";
pub const DEFAULT_PLATFORM_ACCOUNT_UNIT_CENTS: i64 = 5_000;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, FromRow)]
pub struct BillingSettings {
    pub currency: String,
    pub platform_account_unit_cents: i64,
    pub billing_cycle: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct TenantBillingRow {
    pub tenant_id: i64,
    pub company_name: String,
    pub subdomain: String,
    pub plan: String,
    pub tenant_status: String,
    pub staff_count: i32,
    pub billable_accounts: i64,
    pub enabled_platforms: i64,
    pub unit_price_cents: i64,
    pub estimated_monthly_cents: i64,
    pub balance_cents: i64,
    pub remaining_after_estimate_cents: i64,
    pub access_allowed: bool,
    pub billing_status: String,
    pub billing_status_label: String,
    pub billing_status_class: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, FromRow)]
pub struct BillingLedgerEntry {
    pub id: i64,
    pub tenant_id: i64,
    pub company_name: String,
    pub entry_type: String,
    pub amount_cents: i64,
    pub balance_after_cents: i64,
    pub note: Option<String>,
    pub created_at: NaiveDateTime,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct BillingAccess {
    pub allowed: bool,
    pub balance_cents: i64,
    pub required_cents: i64,
}

#[derive(Debug, FromRow)]
struct RawTenantBillingRow {
    tenant_id: i64,
    company_name: String,
    subdomain: String,
    plan: String,
    tenant_status: String,
    staff_count: i32,
    enabled_platforms: i64,
    balance_cents: i64,
}

pub async fn ensure_all_accounts(master: &MySqlPool) -> Result<(), AppError> {
    sqlx::query(
        "INSERT IGNORE INTO `tenant_billing_accounts` (`tenant_id`, `balance_cents`) \
         SELECT `id`, 0 FROM `tenants`",
    )
    .execute(master)
    .await?;

    Ok(())
}

pub async fn ensure_account(master: &MySqlPool, tenant_id: i64) -> Result<(), AppError> {
    sqlx::query(
        "INSERT IGNORE INTO `tenant_billing_accounts` (`tenant_id`, `balance_cents`) \
         VALUES (?, 0)",
    )
    .bind(tenant_id)
    .execute(master)
    .await?;

    Ok(())
}

pub async fn settings(master: &MySqlPool) -> Result<BillingSettings, AppError> {
    ensure_settings(master).await?;
    let settings = sqlx::query_as::<_, BillingSettings>(
        "SELECT `currency`, `platform_account_unit_cents`, `billing_cycle` \
         FROM `billing_settings` WHERE `id` = 1",
    )
    .fetch_one(master)
    .await?;

    Ok(settings)
}

async fn ensure_settings(master: &MySqlPool) -> Result<(), AppError> {
    sqlx::query(
        "INSERT IGNORE INTO `billing_settings` \
         (`id`, `currency`, `platform_account_unit_cents`, `billing_cycle`) \
         VALUES (1, ?, ?, 'monthly')",
    )
    .bind(DEFAULT_CURRENCY)
    .bind(DEFAULT_PLATFORM_ACCOUNT_UNIT_CENTS)
    .execute(master)
    .await?;

    Ok(())
}

pub async fn update_unit_price(master: &MySqlPool, unit_price_cents: i64) -> Result<(), AppError> {
    if unit_price_cents < 0 {
        return Err(AppError::Validation("单价不能为负数".to_string()));
    }

    ensure_settings(master).await?;
    sqlx::query("UPDATE `billing_settings` SET `platform_account_unit_cents` = ? WHERE `id` = 1")
        .bind(unit_price_cents)
        .execute(master)
        .await?;

    Ok(())
}

pub async fn list_tenant_billing(master: &MySqlPool) -> Result<Vec<TenantBillingRow>, AppError> {
    ensure_all_accounts(master).await?;
    let settings = settings(master).await?;
    let rows: Vec<RawTenantBillingRow> = sqlx::query_as::<_, RawTenantBillingRow>(
        "SELECT \
            t.`id` AS `tenant_id`, \
            t.`company_name`, \
            t.`subdomain`, \
            t.`plan`, \
            t.`status` AS `tenant_status`, \
            t.`staff_count`, \
            COUNT(tp.`platform_code`) AS `enabled_platforms`, \
            COALESCE(a.`balance_cents`, 0) AS `balance_cents` \
         FROM `tenants` t \
         LEFT JOIN `tenant_billing_accounts` a ON a.`tenant_id` = t.`id` \
         LEFT JOIN `tenant_platform` tp ON tp.`tenant_id` = t.`id` AND tp.`enabled` = 1 \
         GROUP BY t.`id`, t.`company_name`, t.`subdomain`, t.`plan`, t.`status`, \
                  t.`staff_count`, a.`balance_cents` \
         ORDER BY t.`created_at` DESC, t.`id` DESC",
    )
    .fetch_all(master)
    .await?;

    Ok(rows
        .into_iter()
        .map(|row| build_tenant_billing_row(row, settings.platform_account_unit_cents))
        .collect())
}

pub async fn access_for_tenant(
    master: &MySqlPool,
    tenant_id: i64,
) -> Result<BillingAccess, AppError> {
    ensure_account(master, tenant_id).await?;
    let settings = settings(master).await?;
    let row: Option<RawTenantBillingRow> = sqlx::query_as::<_, RawTenantBillingRow>(
        "SELECT \
            t.`id` AS `tenant_id`, \
            t.`company_name`, \
            t.`subdomain`, \
            t.`plan`, \
            t.`status` AS `tenant_status`, \
            t.`staff_count`, \
            COUNT(tp.`platform_code`) AS `enabled_platforms`, \
            COALESCE(a.`balance_cents`, 0) AS `balance_cents` \
         FROM `tenants` t \
         LEFT JOIN `tenant_billing_accounts` a ON a.`tenant_id` = t.`id` \
         LEFT JOIN `tenant_platform` tp ON tp.`tenant_id` = t.`id` AND tp.`enabled` = 1 \
         WHERE t.`id` = ? \
         GROUP BY t.`id`, t.`company_name`, t.`subdomain`, t.`plan`, t.`status`, \
                  t.`staff_count`, a.`balance_cents`",
    )
    .bind(tenant_id)
    .fetch_optional(master)
    .await?;

    let Some(row) = row else {
        return Ok(BillingAccess {
            allowed: false,
            balance_cents: 0,
            required_cents: 0,
        });
    };

    let row = build_tenant_billing_row(row, settings.platform_account_unit_cents);
    Ok(BillingAccess {
        allowed: row.access_allowed,
        balance_cents: row.balance_cents,
        required_cents: row.estimated_monthly_cents,
    })
}

pub async fn recharge(
    master: &MySqlPool,
    tenant_id: i64,
    amount_cents: i64,
    note: Option<String>,
) -> Result<i64, AppError> {
    if amount_cents <= 0 {
        return Err(AppError::Validation("充值金额必须大于 0".to_string()));
    }

    ensure_account(master, tenant_id).await?;

    let mut tx = master.begin().await?;
    let (balance_before,): (i64,) = sqlx::query_as(
        "SELECT `balance_cents` FROM `tenant_billing_accounts` \
         WHERE `tenant_id` = ? FOR UPDATE",
    )
    .bind(tenant_id)
    .fetch_one(&mut *tx)
    .await?;

    let balance_after = balance_before
        .checked_add(amount_cents)
        .ok_or_else(|| AppError::Validation("充值后余额过大".to_string()))?;

    sqlx::query("UPDATE `tenant_billing_accounts` SET `balance_cents` = ? WHERE `tenant_id` = ?")
        .bind(balance_after)
        .bind(tenant_id)
        .execute(&mut *tx)
        .await?;

    sqlx::query(
        "INSERT INTO `tenant_billing_ledger` \
         (`tenant_id`, `entry_type`, `amount_cents`, `balance_after_cents`, `note`) \
         VALUES (?, 'recharge', ?, ?, ?)",
    )
    .bind(tenant_id)
    .bind(amount_cents)
    .bind(balance_after)
    .bind(note.filter(|s| !s.trim().is_empty()))
    .execute(&mut *tx)
    .await?;

    tx.commit().await?;
    Ok(balance_after)
}

pub async fn recent_ledger(
    master: &MySqlPool,
    limit: i64,
) -> Result<Vec<BillingLedgerEntry>, AppError> {
    let limit = limit.clamp(0, 200);
    let rows = sqlx::query_as::<_, BillingLedgerEntry>(
        "SELECT l.`id`, l.`tenant_id`, t.`company_name`, l.`entry_type`, \
                l.`amount_cents`, l.`balance_after_cents`, l.`note`, l.`created_at` \
         FROM `tenant_billing_ledger` l \
         JOIN `tenants` t ON t.`id` = l.`tenant_id` \
         ORDER BY l.`created_at` DESC, l.`id` DESC LIMIT ?",
    )
    .bind(limit)
    .fetch_all(master)
    .await?;

    Ok(rows)
}

fn build_tenant_billing_row(row: RawTenantBillingRow, unit_price_cents: i64) -> TenantBillingRow {
    let billable_accounts = i64::from(row.staff_count).max(1);
    let estimated_monthly_cents = billable_accounts
        .saturating_mul(row.enabled_platforms)
        .saturating_mul(unit_price_cents);
    let remaining_after_estimate_cents = row.balance_cents.saturating_sub(estimated_monthly_cents);
    let access_allowed =
        row.tenant_status == "active" && row.balance_cents >= estimated_monthly_cents;
    let (billing_status, billing_status_label, billing_status_class) =
        if row.tenant_status != "active" {
            ("tenant_suspended", "租户停用", "off")
        } else if access_allowed {
            ("available", "可使用", "on")
        } else {
            ("insufficient", "余额不足", "lock")
        };

    TenantBillingRow {
        tenant_id: row.tenant_id,
        company_name: row.company_name,
        subdomain: row.subdomain,
        plan: row.plan,
        tenant_status: row.tenant_status,
        staff_count: row.staff_count,
        billable_accounts,
        enabled_platforms: row.enabled_platforms,
        unit_price_cents,
        estimated_monthly_cents,
        balance_cents: row.balance_cents,
        remaining_after_estimate_cents,
        access_allowed,
        billing_status: billing_status.to_string(),
        billing_status_label: billing_status_label.to_string(),
        billing_status_class: billing_status_class.to_string(),
    }
}

pub fn parse_yuan_to_cents(raw: &str) -> Result<i64, AppError> {
    let s = raw.trim().replace(',', "");
    if s.is_empty() {
        return Err(AppError::Validation("金额不能为空".to_string()));
    }
    if s.starts_with('-') {
        return Err(AppError::Validation("金额不能为负数".to_string()));
    }

    let mut parts = s.split('.');
    let yuan = parts.next().unwrap_or("");
    let cents = parts.next().unwrap_or("");
    if parts.next().is_some() {
        return Err(AppError::Validation("金额格式不正确".to_string()));
    }
    if yuan.is_empty() && cents.is_empty() {
        return Err(AppError::Validation("金额格式不正确".to_string()));
    }
    if !yuan.chars().all(|c| c.is_ascii_digit()) {
        return Err(AppError::Validation("金额格式不正确".to_string()));
    }
    if !cents.chars().all(|c| c.is_ascii_digit()) || cents.len() > 2 {
        return Err(AppError::Validation("金额最多保留 2 位小数".to_string()));
    }

    let yuan_value = if yuan.is_empty() {
        0
    } else {
        yuan.parse::<i64>()
            .map_err(|_| AppError::Validation("金额过大".to_string()))?
    };
    let mut cents_text = cents.to_string();
    while cents_text.len() < 2 {
        cents_text.push('0');
    }
    let cents_value = if cents_text.is_empty() {
        0
    } else {
        cents_text
            .parse::<i64>()
            .map_err(|_| AppError::Validation("金额格式不正确".to_string()))?
    };

    yuan_value
        .checked_mul(100)
        .and_then(|value| value.checked_add(cents_value))
        .ok_or_else(|| AppError::Validation("金额过大".to_string()))
}

pub fn format_cents(cents: i64) -> String {
    let sign = if cents < 0 { "-" } else { "" };
    let abs = cents.abs();
    format!("{sign}{}.{:02}", abs / 100, abs % 100)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn raw(
        staff_count: i32,
        platforms: i64,
        balance_cents: i64,
        status: &str,
    ) -> RawTenantBillingRow {
        RawTenantBillingRow {
            tenant_id: 1,
            company_name: "西阵".to_string(),
            subdomain: "xizhen".to_string(),
            plan: "pro".to_string(),
            tenant_status: status.to_string(),
            staff_count,
            enabled_platforms: platforms,
            balance_cents,
        }
    }

    #[test]
    fn parse_yuan_to_cents_accepts_common_money_inputs() {
        assert_eq!(parse_yuan_to_cents("50").unwrap(), 5_000);
        assert_eq!(parse_yuan_to_cents("50.5").unwrap(), 5_050);
        assert_eq!(parse_yuan_to_cents("50.05").unwrap(), 5_005);
        assert_eq!(parse_yuan_to_cents("1,234.56").unwrap(), 123_456);
    }

    #[test]
    fn parse_yuan_to_cents_rejects_invalid_inputs() {
        assert!(parse_yuan_to_cents("").is_err());
        assert!(parse_yuan_to_cents("-1").is_err());
        assert!(parse_yuan_to_cents("1.234").is_err());
        assert!(parse_yuan_to_cents("abc").is_err());
    }

    #[test]
    fn billing_row_requires_balance_to_cover_month_estimate() {
        let row = build_tenant_billing_row(raw(3, 2, 29_999, "active"), 5_000);
        assert_eq!(row.billable_accounts, 3);
        assert_eq!(row.estimated_monthly_cents, 30_000);
        assert!(!row.access_allowed);
        assert_eq!(row.billing_status, "insufficient");

        let row = build_tenant_billing_row(raw(3, 2, 30_000, "active"), 5_000);
        assert!(row.access_allowed);
        assert_eq!(row.billing_status, "available");
    }

    #[test]
    fn zero_staff_still_counts_as_one_billable_account() {
        let row = build_tenant_billing_row(raw(0, 2, 10_000, "active"), 5_000);
        assert_eq!(row.billable_accounts, 1);
        assert_eq!(row.estimated_monthly_cents, 10_000);
        assert!(row.access_allowed);
    }

    #[test]
    fn suspended_tenant_is_not_allowed_even_with_balance() {
        let row = build_tenant_billing_row(raw(2, 2, 999_999, "suspended"), 5_000);
        assert!(!row.access_allowed);
        assert_eq!(row.billing_status, "tenant_suspended");
    }

    #[test]
    fn format_cents_outputs_two_decimal_places() {
        assert_eq!(format_cents(5_000), "50.00");
        assert_eq!(format_cents(5_005), "50.05");
        assert_eq!(format_cents(-125), "-1.25");
    }
}
