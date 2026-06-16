//! 超管后台「租户管理」处理器（Task 11.1 / Requirements 10.2、7.4）。
//!
//! 提供租户列表与新建 / 编辑 / 停用 / 启用四类写操作，全部落主库 `tenants`（见 design.md 3.8、7.6）：
//! - 列表：公司 / 子域名 / 数据库（脱敏）/ 套餐 / 平台授权标签 / 员工数 / 状态 / 创建时间。
//! - 新建 / 编辑 / 停用-启用：写主库 `tenants`（Requirements 7.4）。
//! - **停用**：置 `status='suspended'` 后**立即失效**该租户连接池
//!   （[`TenantPoolManager::invalidate`]，Requirements 7.4 / 设计 4.2），使其连接即时不可用。
//!
//! 设计取舍：DB 访问下沉到 [`crate::repository::tenant_repo`]（仅访问主库），handler 仅做
//! 入参校验、调用仓储、装配响应。当前模板尚未落地，故写操作返回结构化 JSON 结果，
//! 列表返回 JSON 数据；待 Task 11.5「超管身份界面」补全模板后可切换为 Tera 渲染。
//! 所有错误经统一 [`AppError`] 呈现，绝不外泄底层细节或 DSN 口令。

use axum::{
    extract::{Path, Query, State},
    response::{Html, IntoResponse, Json, Redirect, Response},
    routing::{get, post},
    Form, Router,
};
use serde::{Deserialize, Serialize};
use tera::Context;

use crate::error::AppError;
use crate::repository::billing_repo;
use crate::repository::tenant_repo::{self, NewTenant, TenantUpdate};
use crate::state::AppState;

const TENANTS_TEMPLATE: &str = "admin/tenants.html";
const TENANT_NEW_TEMPLATE: &str = "admin/tenant_new.html";
const TENANT_EDIT_TEMPLATE: &str = "admin/tenant_edit.html";

/// 新建租户表单（来自超管「新建租户」独立页面）。
#[derive(Debug, Deserialize)]
pub struct CreateTenantForm {
    pub company_name: String,
    #[serde(default)]
    pub company_short_name: Option<String>,
    pub subdomain: String,
    /// 加密后的独立库 DSN（密文）。
    pub db_dsn_enc: String,
    pub plan: String,
    pub contact_name: String,
    pub contact_phone: String,
    #[serde(default)]
    pub contact_email: Option<String>,
    #[serde(default)]
    pub contact_wechat: Option<String>,
    #[serde(default)]
    pub address: Option<String>,
    #[serde(default)]
    pub remark: Option<String>,
    #[serde(default)]
    pub initial_balance_yuan: Option<String>,
}

/// 编辑租户表单。`db_dsn_enc` 省略或为空字符串时保留原 DSN 不变。
#[derive(Debug, Deserialize)]
pub struct UpdateTenantForm {
    pub company_name: String,
    #[serde(default)]
    pub company_short_name: Option<String>,
    pub subdomain: String,
    #[serde(default)]
    pub db_dsn_enc: Option<String>,
    pub plan: String,
    #[serde(default)]
    pub contact_name: Option<String>,
    #[serde(default)]
    pub contact_phone: Option<String>,
    #[serde(default)]
    pub contact_email: Option<String>,
    #[serde(default)]
    pub contact_wechat: Option<String>,
    #[serde(default)]
    pub address: Option<String>,
    #[serde(default)]
    pub remark: Option<String>,
}

/// 租户管理列表筛选参数。
#[derive(Debug, Clone, Default, Deserialize, Serialize)]
pub struct TenantListQuery {
    #[serde(default)]
    pub status: Option<String>,
    #[serde(default)]
    pub plan: Option<String>,
    #[serde(default)]
    pub q: Option<String>,
}

#[derive(Debug, Clone, Default, Serialize)]
pub struct TenantListFilters {
    pub status: String,
    pub plan: String,
    pub q: String,
}

/// 写操作的结构化结果（新建 / 编辑 / 停用 / 启用通用）。
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct TenantActionResponse {
    pub id: i64,
    /// 操作后的租户状态（`active` / `suspended`），编辑场景沿用既有状态。
    pub status: String,
    /// 面向超管的简短提示文案。
    pub message: String,
}

/// 校验必填文本字段非空（去除首尾空白后判定）。空 → [`AppError::Validation`]（可安全回显）。
fn require_nonempty(field: &str, value: &str) -> Result<(), AppError> {
    if value.trim().is_empty() {
        Err(AppError::Validation(format!("{field}不能为空")))
    } else {
        Ok(())
    }
}

/// 把表单中的可选 DSN 归一：`None` 或纯空白 → `None`（保留原值）；否则 `Some(原值)`。
fn normalize_optional_dsn(dsn: Option<String>) -> Option<String> {
    dsn.filter(|s| !s.trim().is_empty())
}

/// 把可选文本字段归一：纯空白按未填写处理；非空则去除首尾空白后落库。
fn normalize_optional_text(value: Option<String>) -> Option<String> {
    value
        .map(|s| s.trim().to_string())
        .filter(|s| !s.is_empty())
}

fn parse_optional_initial_balance(raw: Option<String>) -> Result<Option<i64>, AppError> {
    let Some(raw) = raw else {
        return Ok(None);
    };
    let raw = raw.trim();
    if raw.is_empty() {
        return Ok(None);
    }

    let cents = billing_repo::parse_yuan_to_cents(raw)?;
    if cents == 0 {
        Ok(None)
    } else {
        Ok(Some(cents))
    }
}

fn normalize_filters(query: TenantListQuery) -> TenantListFilters {
    let status = query.status.unwrap_or_default().trim().to_ascii_lowercase();
    let status = match status.as_str() {
        "active" | "suspended" => status,
        _ => String::new(),
    };

    let plan = query.plan.unwrap_or_default().trim().to_ascii_lowercase();
    let plan = match plan.as_str() {
        "basic" | "pro" | "ent" => plan,
        _ => String::new(),
    };

    TenantListFilters {
        status,
        plan,
        q: query.q.unwrap_or_default().trim().to_string(),
    }
}

fn tenant_matches_filters(
    tenant: &tenant_repo::TenantSummary,
    filters: &TenantListFilters,
) -> bool {
    if !filters.status.is_empty() && tenant.status != filters.status {
        return false;
    }
    if !filters.plan.is_empty() && tenant.plan != filters.plan {
        return false;
    }
    if filters.q.is_empty() {
        return true;
    }

    let q = filters.q.to_lowercase();
    tenant.company_name.to_lowercase().contains(&q)
        || tenant
            .company_short_name
            .as_deref()
            .unwrap_or("")
            .to_lowercase()
            .contains(&q)
        || tenant.subdomain.to_lowercase().contains(&q)
        || tenant.db_label.to_lowercase().contains(&q)
        || tenant.plan.to_lowercase().contains(&q)
        || tenant
            .contact_name
            .as_deref()
            .unwrap_or("")
            .to_lowercase()
            .contains(&q)
        || tenant
            .contact_phone
            .as_deref()
            .unwrap_or("")
            .to_lowercase()
            .contains(&q)
        || tenant
            .contact_email
            .as_deref()
            .unwrap_or("")
            .to_lowercase()
            .contains(&q)
        || tenant
            .contact_wechat
            .as_deref()
            .unwrap_or("")
            .to_lowercase()
            .contains(&q)
        || tenant
            .authorized_platforms
            .iter()
            .any(|code| code.to_lowercase().contains(&q))
}

/// 组装超管后台租户管理的路由（前缀 `/admin/tenants`）。
///
/// - `GET  /admin/tenants`            → 列表
/// - `GET  /admin/tenants/new`        → 新建页面
/// - `POST /admin/tenants`            → 新建
/// - `GET  /admin/tenants/:id/edit`   → 编辑页面
/// - `POST /admin/tenants/:id`        → 编辑
/// - `POST /admin/tenants/:id/suspend`→ 停用（置 suspended + 失效连接池）
/// - `POST /admin/tenants/:id/enable` → 启用（置 active）
pub fn routes() -> Router<AppState> {
    Router::new()
        .route("/admin/tenants", get(list_tenants).post(create_tenant))
        .route("/admin/tenants/new", get(new_tenant_page))
        .route("/admin/tenants/:id/edit", get(edit_tenant_page))
        .route("/admin/tenants/:id", post(update_tenant))
        .route("/admin/tenants/:id/suspend", post(suspend_tenant))
        .route("/admin/tenants/:id/enable", post(enable_tenant))
}

/// 独立新建租户页面。
pub async fn new_tenant_page(State(state): State<AppState>) -> Result<Response, AppError> {
    let ctx = Context::new();
    match state.tera().render(TENANT_NEW_TEMPLATE, &ctx) {
        Ok(html) => Ok(Html(html).into_response()),
        Err(e) => {
            tracing::warn!(error = %e, template = TENANT_NEW_TEMPLATE, "新建租户模板渲染失败，回退 JSON");
            Ok(Json(serde_json::json!({
                "template": TENANT_NEW_TEMPLATE,
                "message": "新建租户页面暂不可用"
            }))
            .into_response())
        }
    }
}

/// 独立编辑租户资料页面，避免在租户列表横向滚动表格里展开大表单导致裁切错位。
pub async fn edit_tenant_page(
    State(state): State<AppState>,
    Path(id): Path<i64>,
) -> Result<Response, AppError> {
    let Some(tenant) = tenant_repo::get_tenant(state.master_pool(), id).await? else {
        return Err(AppError::NotFound);
    };

    let mut ctx = Context::new();
    ctx.insert("tenant", &tenant);
    match state.tera().render(TENANT_EDIT_TEMPLATE, &ctx) {
        Ok(html) => Ok(Html(html).into_response()),
        Err(e) => {
            tracing::warn!(error = %e, template = TENANT_EDIT_TEMPLATE, tenant_id = id, "编辑租户模板渲染失败，回退 JSON");
            Ok(Json(tenant).into_response())
        }
    }
}

/// 租户列表（Requirements 10.2）。返回脱敏的列表视图数据。
pub async fn list_tenants(
    State(state): State<AppState>,
    Query(query): Query<TenantListQuery>,
) -> Result<Response, AppError> {
    let filters = normalize_filters(query);
    let all_tenants = tenant_repo::list_tenants(state.master_pool()).await?;
    let total_tenants = all_tenants.len();
    let tenants = all_tenants
        .into_iter()
        .filter(|tenant| tenant_matches_filters(tenant, &filters))
        .collect::<Vec<_>>();
    let mut ctx = Context::new();
    ctx.insert("tenants", &tenants);
    ctx.insert("filters", &filters);
    ctx.insert("total_tenants", &total_tenants);
    match state.tera().render(TENANTS_TEMPLATE, &ctx) {
        Ok(html) => Ok(Html(html).into_response()),
        Err(e) => {
            tracing::warn!(error = %e, template = TENANTS_TEMPLATE, "租户管理模板渲染失败，回退 JSON");
            Ok(Json(tenants).into_response())
        }
    }
}

/// 新建租户：写主库 `tenants`（Requirements 7.4）。
pub async fn create_tenant(
    State(state): State<AppState>,
    Form(form): Form<CreateTenantForm>,
) -> Result<Redirect, AppError> {
    require_nonempty("公司名", &form.company_name)?;
    require_nonempty("子域名", &form.subdomain)?;
    require_nonempty("负责人", &form.contact_name)?;
    require_nonempty("联系电话", &form.contact_phone)?;
    require_nonempty("数据库连接", &form.db_dsn_enc)?;
    let initial_balance_cents = parse_optional_initial_balance(form.initial_balance_yuan)?;

    let id = tenant_repo::create_tenant(
        state.master_pool(),
        &NewTenant {
            company_name: form.company_name.trim().to_string(),
            company_short_name: normalize_optional_text(form.company_short_name),
            subdomain: form.subdomain.trim().to_string(),
            db_dsn_enc: form.db_dsn_enc,
            plan: form.plan,
            contact_name: normalize_optional_text(Some(form.contact_name)),
            contact_phone: normalize_optional_text(Some(form.contact_phone)),
            contact_email: normalize_optional_text(form.contact_email),
            contact_wechat: normalize_optional_text(form.contact_wechat),
            address: normalize_optional_text(form.address),
            remark: normalize_optional_text(form.remark),
        },
    )
    .await?;

    if let Some(amount_cents) = initial_balance_cents {
        billing_repo::recharge(
            state.master_pool(),
            id,
            amount_cents,
            Some("创建租户初始预存".to_string()),
        )
        .await?;
    } else {
        billing_repo::ensure_account(state.master_pool(), id).await?;
    }

    tracing::info!(tenant_id = id, "租户已创建");
    Ok(Redirect::to("/admin/tenants"))
}

/// 编辑租户基础信息：公司名 / 子域名 / 套餐 / （可选）DSN（Requirements 7.4）。
pub async fn update_tenant(
    State(state): State<AppState>,
    Path(id): Path<i64>,
    Form(form): Form<UpdateTenantForm>,
) -> Result<Redirect, AppError> {
    require_nonempty("公司名", &form.company_name)?;
    require_nonempty("子域名", &form.subdomain)?;

    let dsn_changed = form
        .db_dsn_enc
        .as_deref()
        .map(str::trim)
        .map(|dsn| !dsn.is_empty())
        .unwrap_or(false);

    let updated = tenant_repo::update_tenant(
        state.master_pool(),
        id,
        &TenantUpdate {
            company_name: form.company_name.trim().to_string(),
            company_short_name: normalize_optional_text(form.company_short_name),
            subdomain: form.subdomain.trim().to_string(),
            db_dsn_enc: normalize_optional_dsn(form.db_dsn_enc),
            plan: form.plan,
            contact_name: normalize_optional_text(form.contact_name),
            contact_phone: normalize_optional_text(form.contact_phone),
            contact_email: normalize_optional_text(form.contact_email),
            contact_wechat: normalize_optional_text(form.contact_wechat),
            address: normalize_optional_text(form.address),
            remark: normalize_optional_text(form.remark),
        },
    )
    .await?;

    if !updated {
        return Err(AppError::NotFound);
    }

    if dsn_changed {
        state.pools().invalidate(id).await;
    }

    tracing::info!(tenant_id = id, "租户已更新");
    Ok(Redirect::to("/admin/tenants"))
}

/// 停用租户：置 `status='suspended'` 并**立即失效连接池**（Requirements 7.4）。
///
/// 顺序：先写库置停用 → 命中后调用 [`crate::db::pool::TenantPoolManager::invalidate`]
/// 驱逐缓存的连接池，使该租户连接即时不可用（设计 4.2）。租户不存在 → 404。
pub async fn suspend_tenant(
    State(state): State<AppState>,
    Path(id): Path<i64>,
) -> Result<Redirect, AppError> {
    let found = tenant_repo::set_tenant_status(state.master_pool(), id, "suspended").await?;
    if !found {
        return Err(AppError::NotFound);
    }

    // 立即失效该租户连接池：已建立的连接被优雅关闭并移出缓存（Requirements 7.4）。
    state.pools().invalidate(id).await;

    tracing::info!(tenant_id = id, "租户已停用，连接池已失效");
    Ok(Redirect::to("/admin/tenants"))
}

/// 启用租户：置 `status='active'`（Requirements 7.4）。
///
/// 无需预建连接池——下次访问该租户时由连接池管理器按需懒建（设计 4.4）。租户不存在 → 404。
pub async fn enable_tenant(
    State(state): State<AppState>,
    Path(id): Path<i64>,
) -> Result<Redirect, AppError> {
    let found = tenant_repo::set_tenant_status(state.master_pool(), id, "active").await?;
    if !found {
        return Err(AppError::NotFound);
    }

    tracing::info!(tenant_id = id, "租户已启用");
    Ok(Redirect::to("/admin/tenants"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn require_nonempty_rejects_blank_and_whitespace() {
        assert!(require_nonempty("公司名", "").is_err());
        assert!(require_nonempty("公司名", "   ").is_err());
        assert!(require_nonempty("公司名", "\t\n").is_err());
        assert!(require_nonempty("公司名", "西阵").is_ok());
    }

    #[test]
    fn require_nonempty_error_names_the_field() {
        let err = require_nonempty("子域名", "").unwrap_err();
        assert!(matches!(&err, AppError::Validation(_)));
        assert!(err.client_message().contains("子域名"));
    }

    #[test]
    fn normalize_optional_dsn_treats_blank_as_keep_existing() {
        assert_eq!(normalize_optional_dsn(None), None);
        assert_eq!(normalize_optional_dsn(Some("".into())), None);
        assert_eq!(normalize_optional_dsn(Some("   ".into())), None);
        assert_eq!(
            normalize_optional_dsn(Some("mysql://u:p@h/d".into())),
            Some("mysql://u:p@h/d".to_string())
        );
    }

    #[test]
    fn normalize_optional_text_trims_and_drops_blank() {
        assert_eq!(normalize_optional_text(None), None);
        assert_eq!(normalize_optional_text(Some("   ".into())), None);
        assert_eq!(
            normalize_optional_text(Some("  张三  ".into())),
            Some("张三".to_string())
        );
    }

    #[test]
    fn parse_optional_initial_balance_accepts_blank_and_money() {
        assert_eq!(parse_optional_initial_balance(None).unwrap(), None);
        assert_eq!(
            parse_optional_initial_balance(Some("   ".into())).unwrap(),
            None
        );
        assert_eq!(
            parse_optional_initial_balance(Some("0".into())).unwrap(),
            None
        );
        assert_eq!(
            parse_optional_initial_balance(Some("500.25".into())).unwrap(),
            Some(50_025)
        );
    }

    #[test]
    fn normalize_filters_accepts_only_known_values() {
        let filters = normalize_filters(TenantListQuery {
            status: Some("active".into()),
            plan: Some("pro".into()),
            q: Some("  xi  ".into()),
        });
        assert_eq!(filters.status, "active");
        assert_eq!(filters.plan, "pro");
        assert_eq!(filters.q, "xi");

        let filters = normalize_filters(TenantListQuery {
            status: Some("deleted".into()),
            plan: Some("gold".into()),
            q: None,
        });
        assert_eq!(filters.status, "");
        assert_eq!(filters.plan, "");
    }

    #[test]
    fn action_response_serializes_to_expected_json() {
        let resp = TenantActionResponse {
            id: 42,
            status: "suspended".into(),
            message: "租户已停用，连接池已失效".into(),
        };
        let json = serde_json::to_value(&resp).unwrap();
        assert_eq!(json["id"], 42);
        assert_eq!(json["status"], "suspended");
    }
}
