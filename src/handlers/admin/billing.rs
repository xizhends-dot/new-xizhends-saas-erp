//! SaaS 超管后台“套餐 / 计费”页面。
//!
//! 当前计费口径：每个平台账号按月计费，租户必须先在超管后台预存余额；
//! 租户状态为 active 且余额覆盖当前月预估费用时，租户侧系统才允许进入。

use axum::{
    extract::{Path, Query, State},
    response::{Html, IntoResponse, Json, Redirect, Response},
    routing::{get, post},
    Form, Router,
};
use serde::{Deserialize, Serialize};
use tera::Context;

use crate::error::AppError;
use crate::repository::billing_repo::{self, BillingLedgerEntry, TenantBillingRow};
use crate::state::AppState;

const BILLING_TEMPLATE: &str = "admin/billing.html";
const ADMIN_ACCENT: &str = "#07C160";
const DEFAULT_LEDGER_LIMIT: i64 = 30;

#[derive(Debug, Clone, Default, Deserialize, Serialize)]
pub struct BillingQuery {
    #[serde(default)]
    pub plan: Option<String>,
    #[serde(default)]
    pub status: Option<String>,
    #[serde(default)]
    pub q: Option<String>,
}

#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize)]
pub struct BillingFilters {
    pub plan: String,
    pub status: String,
    pub q: String,
}

#[derive(Debug, Deserialize)]
pub struct BillingSettingsForm {
    pub platform_account_unit_yuan: String,
}

#[derive(Debug, Deserialize)]
pub struct RechargeForm {
    pub amount_yuan: String,
    #[serde(default)]
    pub note: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct BillingSettingsView {
    pub currency: String,
    pub billing_cycle: String,
    pub platform_account_unit_yuan: String,
    pub platform_account_unit_label: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct BillingStats {
    pub total_balance_cents: i64,
    pub total_balance: String,
    pub monthly_estimate_cents: i64,
    pub monthly_estimate: String,
    pub available_tenants: usize,
    pub insufficient_tenants: usize,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct BillingTenantView {
    pub tenant_id: i64,
    pub company_name: String,
    pub subdomain: String,
    pub plan: String,
    pub plan_label: String,
    pub plan_class: String,
    pub tenant_status: String,
    pub staff_count: i32,
    pub billable_accounts: i64,
    pub enabled_platforms: i64,
    pub unit_price: String,
    pub estimated_monthly: String,
    pub balance: String,
    pub remaining_after_estimate: String,
    pub remaining_class: String,
    pub access_allowed: bool,
    pub billing_status: String,
    pub billing_status_label: String,
    pub billing_status_class: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct BillingLedgerView {
    pub id: i64,
    pub tenant_id: i64,
    pub company_name: String,
    pub entry_type: String,
    pub entry_type_label: String,
    pub entry_type_class: String,
    pub amount: String,
    pub amount_class: String,
    pub balance_after: String,
    pub note: String,
    pub created_at: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct BillingView {
    pub active_nav: String,
    pub body_class: String,
    pub admin_accent: String,
    pub settings: BillingSettingsView,
    pub stats: BillingStats,
    pub tenants: Vec<BillingTenantView>,
    pub total_tenants: usize,
    pub filters: BillingFilters,
    pub ledger: Vec<BillingLedgerView>,
}

pub fn routes() -> Router<AppState> {
    Router::new()
        .route("/admin/billing", get(show_billing))
        .route("/admin/billing/settings", post(update_billing_settings))
        .route("/admin/billing/:tenant_id/recharge", post(recharge_tenant))
}

pub async fn show_billing(
    State(state): State<AppState>,
    Query(query): Query<BillingQuery>,
) -> Result<Response, AppError> {
    let filters = normalize_filters(query);
    let settings = billing_repo::settings(state.master_pool()).await?;
    let rows = billing_repo::list_tenant_billing(state.master_pool()).await?;
    let ledger = billing_repo::recent_ledger(state.master_pool(), DEFAULT_LEDGER_LIMIT).await?;
    let view = build_billing_view(settings, rows, ledger, filters);
    Ok(render_billing(state.tera(), &view))
}

pub async fn update_billing_settings(
    State(state): State<AppState>,
    Form(form): Form<BillingSettingsForm>,
) -> Result<Redirect, AppError> {
    let unit_price_cents = billing_repo::parse_yuan_to_cents(&form.platform_account_unit_yuan)?;
    billing_repo::update_unit_price(state.master_pool(), unit_price_cents).await?;
    tracing::info!(unit_price_cents, "计费单价已更新");
    Ok(Redirect::to("/admin/billing"))
}

pub async fn recharge_tenant(
    State(state): State<AppState>,
    Path(tenant_id): Path<i64>,
    Form(form): Form<RechargeForm>,
) -> Result<Redirect, AppError> {
    if !tenant_exists(state.master_pool(), tenant_id).await? {
        return Err(AppError::NotFound);
    }

    let amount_cents = billing_repo::parse_yuan_to_cents(&form.amount_yuan)?;
    let balance_after = billing_repo::recharge(
        state.master_pool(),
        tenant_id,
        amount_cents,
        normalize_note(form.note),
    )
    .await?;

    tracing::info!(tenant_id, amount_cents, balance_after, "租户已充值");
    Ok(Redirect::to("/admin/billing"))
}

async fn tenant_exists(master: &sqlx::MySqlPool, tenant_id: i64) -> Result<bool, AppError> {
    let found: Option<i64> = sqlx::query_scalar("SELECT `id` FROM `tenants` WHERE `id` = ?")
        .bind(tenant_id)
        .fetch_optional(master)
        .await?;
    Ok(found.is_some())
}

fn normalize_note(note: Option<String>) -> Option<String> {
    note.map(|s| s.trim().chars().take(255).collect::<String>())
        .filter(|s| !s.is_empty())
}

fn normalize_filters(query: BillingQuery) -> BillingFilters {
    let plan = query.plan.unwrap_or_default().trim().to_ascii_lowercase();
    let plan = match plan.as_str() {
        "basic" | "pro" | "ent" => plan,
        _ => String::new(),
    };

    let status = query.status.unwrap_or_default().trim().to_ascii_lowercase();
    let status = match status.as_str() {
        "available" | "insufficient" | "tenant_suspended" => status,
        _ => String::new(),
    };

    BillingFilters {
        plan,
        status,
        q: query.q.unwrap_or_default().trim().to_string(),
    }
}

fn tenant_matches_filters(tenant: &TenantBillingRow, filters: &BillingFilters) -> bool {
    if !filters.plan.is_empty() && tenant.plan != filters.plan {
        return false;
    }
    if !filters.status.is_empty() && tenant.billing_status != filters.status {
        return false;
    }
    if filters.q.is_empty() {
        return true;
    }

    let q = filters.q.to_lowercase();
    tenant.company_name.to_lowercase().contains(&q)
        || tenant.subdomain.to_lowercase().contains(&q)
        || tenant.plan.to_lowercase().contains(&q)
        || tenant.billing_status_label.to_lowercase().contains(&q)
}

fn build_billing_view(
    settings: billing_repo::BillingSettings,
    rows: Vec<TenantBillingRow>,
    ledger: Vec<BillingLedgerEntry>,
    filters: BillingFilters,
) -> BillingView {
    let total_balance_cents = rows.iter().map(|row| row.balance_cents).sum();
    let monthly_estimate_cents = rows.iter().map(|row| row.estimated_monthly_cents).sum();
    let available_tenants = rows.iter().filter(|row| row.access_allowed).count();
    let insufficient_tenants = rows
        .iter()
        .filter(|row| row.billing_status == "insufficient")
        .count();
    let total_tenants = rows.len();
    let currency = settings.currency.clone();

    let tenants = rows
        .iter()
        .filter(|tenant| tenant_matches_filters(tenant, &filters))
        .map(|tenant| build_tenant_view(tenant, &currency))
        .collect();

    BillingView {
        active_nav: "billing".to_string(),
        body_class: "admin-identity".to_string(),
        admin_accent: ADMIN_ACCENT.to_string(),
        settings: BillingSettingsView {
            currency: settings.currency.clone(),
            billing_cycle: settings.billing_cycle,
            platform_account_unit_yuan: billing_repo::format_cents(
                settings.platform_account_unit_cents,
            ),
            platform_account_unit_label: format_money(
                settings.platform_account_unit_cents,
                &settings.currency,
            ),
        },
        stats: BillingStats {
            total_balance_cents,
            total_balance: format_money(total_balance_cents, &currency),
            monthly_estimate_cents,
            monthly_estimate: format_money(monthly_estimate_cents, &currency),
            available_tenants,
            insufficient_tenants,
        },
        tenants,
        total_tenants,
        filters,
        ledger: ledger
            .into_iter()
            .map(|entry| build_ledger_view(entry, &currency))
            .collect(),
    }
}

fn build_tenant_view(row: &TenantBillingRow, currency: &str) -> BillingTenantView {
    let remaining_after_estimate_cents = row.balance_cents - row.estimated_monthly_cents;
    BillingTenantView {
        tenant_id: row.tenant_id,
        company_name: row.company_name.clone(),
        subdomain: row.subdomain.clone(),
        plan: row.plan.clone(),
        plan_label: plan_label(&row.plan).to_string(),
        plan_class: plan_class(&row.plan).to_string(),
        tenant_status: row.tenant_status.clone(),
        staff_count: row.staff_count,
        billable_accounts: row.billable_accounts,
        enabled_platforms: row.enabled_platforms,
        unit_price: format_money(row.unit_price_cents, currency),
        estimated_monthly: format_money(row.estimated_monthly_cents, currency),
        balance: format_money(row.balance_cents, currency),
        remaining_after_estimate: format_money(remaining_after_estimate_cents, currency),
        remaining_class: if remaining_after_estimate_cents >= 0 {
            "money-ok"
        } else {
            "money-bad"
        }
        .to_string(),
        access_allowed: row.access_allowed,
        billing_status: row.billing_status.clone(),
        billing_status_label: row.billing_status_label.clone(),
        billing_status_class: row.billing_status_class.clone(),
    }
}

fn build_ledger_view(entry: BillingLedgerEntry, currency: &str) -> BillingLedgerView {
    let (entry_type_label, entry_type_class) = match entry.entry_type.as_str() {
        "recharge" => ("充值", "on"),
        "adjustment" => ("调整", "lock"),
        "charge" => ("扣费", "off"),
        _ => ("其他", "off"),
    };
    let amount_prefix = if entry.amount_cents > 0 { "+" } else { "" };

    BillingLedgerView {
        id: entry.id,
        tenant_id: entry.tenant_id,
        company_name: entry.company_name,
        entry_type: entry.entry_type,
        entry_type_label: entry_type_label.to_string(),
        entry_type_class: entry_type_class.to_string(),
        amount: format!(
            "{amount_prefix}{} {}",
            billing_repo::format_cents(entry.amount_cents),
            currency
        ),
        amount_class: if entry.amount_cents >= 0 {
            "money-ok"
        } else {
            "money-bad"
        }
        .to_string(),
        balance_after: format_money(entry.balance_after_cents, currency),
        note: entry.note.unwrap_or_else(|| "-".to_string()),
        created_at: entry.created_at.to_string(),
    }
}

fn plan_label(plan: &str) -> &str {
    match plan {
        "basic" => "基础版",
        "pro" => "专业版",
        "ent" => "企业版",
        _ => "未知套餐",
    }
}

fn plan_class(plan: &str) -> &str {
    match plan {
        "basic" => "basic",
        "pro" => "pro",
        "ent" => "ent",
        _ => "basic",
    }
}

fn format_money(cents: i64, currency: &str) -> String {
    format!("{} {}", billing_repo::format_cents(cents), currency)
}

fn render_billing(tera: &tera::Tera, view: &BillingView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(BILLING_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = BILLING_TEMPLATE, "套餐计费模板渲染失败，回退 JSON");
                Json(view).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "套餐计费上下文序列化失败，回退 JSON");
            Json(view).into_response()
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use sqlx::types::chrono::NaiveDateTime;

    fn tenant(
        tenant_id: i64,
        plan: &str,
        status: &str,
        billing_status: &str,
        staff_count: i32,
        platforms: i64,
        balance_cents: i64,
    ) -> TenantBillingRow {
        let unit_price_cents = 5_000;
        let billable_accounts = i64::from(staff_count).max(1);
        let estimated_monthly_cents = billable_accounts * platforms * unit_price_cents;
        let access_allowed = status == "active" && balance_cents >= estimated_monthly_cents;
        let (billing_status_label, billing_status_class) = match billing_status {
            "available" => ("可使用", "on"),
            "insufficient" => ("余额不足", "lock"),
            "tenant_suspended" => ("租户停用", "off"),
            _ => ("未知", "off"),
        };

        TenantBillingRow {
            tenant_id,
            company_name: format!("公司{tenant_id}"),
            subdomain: format!("tenant{tenant_id}"),
            plan: plan.to_string(),
            tenant_status: status.to_string(),
            staff_count,
            billable_accounts,
            enabled_platforms: platforms,
            unit_price_cents,
            estimated_monthly_cents,
            balance_cents,
            remaining_after_estimate_cents: balance_cents - estimated_monthly_cents,
            access_allowed,
            billing_status: billing_status.to_string(),
            billing_status_label: billing_status_label.to_string(),
            billing_status_class: billing_status_class.to_string(),
        }
    }

    fn settings() -> billing_repo::BillingSettings {
        billing_repo::BillingSettings {
            currency: "RMB".to_string(),
            platform_account_unit_cents: 5_000,
            billing_cycle: "monthly".to_string(),
        }
    }

    #[test]
    fn normalize_filters_accepts_billing_status_values() {
        let filters = normalize_filters(BillingQuery {
            plan: Some("pro".into()),
            status: Some("insufficient".into()),
            q: Some("  xi  ".into()),
        });

        assert_eq!(filters.plan, "pro");
        assert_eq!(filters.status, "insufficient");
        assert_eq!(filters.q, "xi");

        let filters = normalize_filters(BillingQuery {
            plan: Some("gold".into()),
            status: Some("active".into()),
            q: None,
        });
        assert_eq!(filters.plan, "");
        assert_eq!(filters.status, "");
    }

    #[test]
    fn build_billing_view_summarizes_real_balance_and_estimate() {
        let view = build_billing_view(
            settings(),
            vec![
                tenant(1, "basic", "active", "available", 2, 2, 20_000),
                tenant(2, "pro", "active", "insufficient", 3, 2, 1_000),
            ],
            Vec::new(),
            BillingFilters::default(),
        );

        assert_eq!(view.stats.total_balance_cents, 21_000);
        assert_eq!(view.stats.monthly_estimate_cents, 50_000);
        assert_eq!(view.stats.available_tenants, 1);
        assert_eq!(view.stats.insufficient_tenants, 1);
        assert_eq!(view.settings.platform_account_unit_label, "50.00 RMB");
        assert_eq!(view.tenants[1].remaining_class, "money-bad");
    }

    #[test]
    fn filters_tenants_by_billing_status_and_search_text() {
        let view = build_billing_view(
            settings(),
            vec![
                tenant(1, "basic", "active", "available", 1, 1, 5_000),
                tenant(2, "pro", "active", "insufficient", 1, 2, 1_000),
            ],
            Vec::new(),
            BillingFilters {
                status: "insufficient".into(),
                q: "tenant2".into(),
                ..BillingFilters::default()
            },
        );

        assert_eq!(view.tenants.len(), 1);
        assert_eq!(view.tenants[0].tenant_id, 2);
    }

    #[test]
    fn ledger_view_formats_signed_amount() {
        let view = build_ledger_view(
            BillingLedgerEntry {
                id: 9,
                tenant_id: 1,
                company_name: "西阵".to_string(),
                entry_type: "recharge".to_string(),
                amount_cents: 5_000,
                balance_after_cents: 10_000,
                note: Some("预存".to_string()),
                created_at: NaiveDateTime::default(),
            },
            "RMB",
        );

        assert_eq!(view.amount, "+50.00 RMB");
        assert_eq!(view.balance_after, "100.00 RMB");
        assert_eq!(view.entry_type_label, "充值");
    }

    #[test]
    fn normalize_note_trims_and_caps_length() {
        assert_eq!(
            normalize_note(Some("  充值  ".into())).as_deref(),
            Some("充值")
        );
        assert_eq!(normalize_note(Some("   ".into())), None);
        assert_eq!(normalize_note(Some("a".repeat(300))).unwrap().len(), 255);
    }
}
