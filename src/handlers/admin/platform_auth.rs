//! 超管后台「平台授权」页面处理器。
//!
//! GET 读取租户列表与选中租户的 `platforms` / `tenant_platform` 授权矩阵；
//! POST 将表单中的 `tenant_id` / `platform_code` / `enabled` / `locked` 交给
//! `platform_auth_service::set_tenant_platform` upsert 后重定向回当前租户页面。

use axum::{
    extract::{Query, State},
    response::{Html, IntoResponse, Json, Redirect, Response},
    routing::get,
    Form, Router,
};
use serde::{Deserialize, Serialize};
use sqlx::{FromRow, MySqlPool};
use tera::Context;

use crate::error::AppError;
use crate::repository::tenant_repo::{self, TenantSummary};
use crate::services::platform_auth_service;
use crate::state::AppState;

const PLATFORM_AUTH_TEMPLATE: &str = "admin/platform_auth.html";
const ADMIN_ACCENT: &str = "#07C160";

#[derive(Debug, Deserialize)]
pub struct PlatformAuthQuery {
    #[serde(default)]
    pub tenant_id: Option<i64>,
}

#[derive(Debug, Deserialize)]
pub struct PlatformAuthForm {
    pub tenant_id: i64,
    pub platform_code: String,
    #[serde(default)]
    pub enabled: bool,
    #[serde(default)]
    pub locked: bool,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct TenantOption {
    pub id: i64,
    pub company_name: String,
    pub subdomain: String,
    pub status: String,
    pub selected: bool,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct PlatformAuthRow {
    pub code: String,
    pub name: String,
    pub sort_order: i32,
    pub enabled: bool,
    pub locked: bool,
    pub state_label: String,
    pub state_class: String,
    pub is_disabled_state: bool,
    pub is_normal_state: bool,
    pub is_locked_state: bool,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct PlatformAuthView {
    pub active_nav: String,
    pub body_class: String,
    pub admin_accent: String,
    pub tenants: Vec<TenantOption>,
    pub has_tenants: bool,
    pub selected_tenant_id: Option<i64>,
    pub selected_tenant_name: Option<String>,
    pub selected_tenant_status: Option<String>,
    pub platforms: Vec<PlatformAuthRow>,
}

#[derive(Debug, FromRow)]
struct PlatformAuthDbRow {
    code: String,
    name: String,
    sort_order: i32,
    enabled: Option<i8>,
    locked: Option<i8>,
}

/// 组装超管后台平台授权路由。
pub fn routes() -> Router<AppState> {
    Router::new().route(
        "/admin/platform-auth",
        get(show_platform_auth).post(update_platform_auth),
    )
}

/// 平台授权页：租户列表 + 选中租户的平台三态授权矩阵。
pub async fn show_platform_auth(
    State(state): State<AppState>,
    Query(query): Query<PlatformAuthQuery>,
) -> Result<Response, AppError> {
    let tenants = tenant_repo::list_tenants(state.master_pool()).await?;
    let selected_tenant_id = resolve_selected_tenant(&tenants, query.tenant_id)?;
    let platforms = match selected_tenant_id {
        Some(tenant_id) => load_platform_auth_rows(state.master_pool(), tenant_id).await?,
        None => Vec::new(),
    };

    let view = build_platform_auth_view(tenants, selected_tenant_id, platforms);
    Ok(render_platform_auth(state.tera(), &view))
}

/// 写入某租户某平台的开通/锁定状态，完成后回到同租户筛选页。
pub async fn update_platform_auth(
    State(state): State<AppState>,
    Form(form): Form<PlatformAuthForm>,
) -> Result<Redirect, AppError> {
    let tenants = tenant_repo::list_tenants(state.master_pool()).await?;
    if !tenants.iter().any(|tenant| tenant.id == form.tenant_id) {
        return Err(AppError::NotFound);
    }
    let locked = form.enabled && form.locked;

    platform_auth_service::set_tenant_platform(
        state.master_pool(),
        form.tenant_id,
        form.platform_code.trim(),
        form.enabled,
        locked,
    )
    .await?;

    Ok(Redirect::to(&format!(
        "/admin/platform-auth?tenant_id={}",
        form.tenant_id
    )))
}

fn resolve_selected_tenant(
    tenants: &[TenantSummary],
    requested_id: Option<i64>,
) -> Result<Option<i64>, AppError> {
    if tenants.is_empty() {
        return Ok(None);
    }

    match requested_id {
        Some(id) if tenants.iter().any(|tenant| tenant.id == id) => Ok(Some(id)),
        Some(_) => Err(AppError::NotFound),
        None => Ok(tenants.first().map(|tenant| tenant.id)),
    }
}

async fn load_platform_auth_rows(
    master: &MySqlPool,
    tenant_id: i64,
) -> Result<Vec<PlatformAuthRow>, AppError> {
    let rows: Vec<PlatformAuthDbRow> = sqlx::query_as::<_, PlatformAuthDbRow>(
        "SELECT p.`code` AS `code`, p.`name` AS `name`, p.`sort_order` AS `sort_order`, \
                tp.`enabled` AS `enabled`, tp.`locked` AS `locked` \
         FROM `platforms` p \
         LEFT JOIN `tenant_platform` tp \
           ON tp.`platform_code` = p.`code` AND tp.`tenant_id` = ? \
         ORDER BY p.`sort_order` ASC, p.`code` ASC",
    )
    .bind(tenant_id)
    .fetch_all(master)
    .await?;

    Ok(rows
        .into_iter()
        .map(|row| {
            let enabled = row.enabled.unwrap_or(0) != 0;
            let locked = row.locked.unwrap_or(0) != 0;
            build_platform_row(row.code, row.name, row.sort_order, enabled, locked)
        })
        .collect())
}

fn build_platform_row(
    code: String,
    name: String,
    sort_order: i32,
    enabled: bool,
    locked: bool,
) -> PlatformAuthRow {
    let locked = enabled && locked;
    let is_disabled_state = !enabled;
    let is_locked_state = enabled && locked;
    let is_normal_state = enabled && !locked;
    let (state_label, state_class) = if is_disabled_state {
        ("未开通", "disabled")
    } else if is_locked_state {
        ("已锁定", "locked")
    } else {
        ("已开通", "normal")
    };

    PlatformAuthRow {
        code,
        name,
        sort_order,
        enabled,
        locked,
        state_label: state_label.to_string(),
        state_class: state_class.to_string(),
        is_disabled_state,
        is_normal_state,
        is_locked_state,
    }
}

fn build_platform_auth_view(
    tenants: Vec<TenantSummary>,
    selected_tenant_id: Option<i64>,
    platforms: Vec<PlatformAuthRow>,
) -> PlatformAuthView {
    let selected = selected_tenant_id.and_then(|id| tenants.iter().find(|tenant| tenant.id == id));

    let selected_tenant_name = selected.map(|tenant| tenant.company_name.clone());
    let selected_tenant_status = selected.map(|tenant| tenant.status.clone());
    let tenant_options = tenants
        .into_iter()
        .map(|tenant| TenantOption {
            selected: Some(tenant.id) == selected_tenant_id,
            id: tenant.id,
            company_name: tenant.company_name,
            subdomain: tenant.subdomain,
            status: tenant.status,
        })
        .collect::<Vec<_>>();

    PlatformAuthView {
        active_nav: "auth".to_string(),
        body_class: "admin-identity".to_string(),
        admin_accent: ADMIN_ACCENT.to_string(),
        has_tenants: !tenant_options.is_empty(),
        tenants: tenant_options,
        selected_tenant_id,
        selected_tenant_name,
        selected_tenant_status,
        platforms,
    }
}

fn render_platform_auth(tera: &tera::Tera, view: &PlatformAuthView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(PLATFORM_AUTH_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = PLATFORM_AUTH_TEMPLATE, "平台授权模板渲染失败，回退 JSON");
                Json(view).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "平台授权上下文序列化失败，回退 JSON");
            Json(view).into_response()
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use sqlx::types::chrono::NaiveDateTime;

    fn tenant(id: i64) -> TenantSummary {
        TenantSummary {
            id,
            company_name: format!("公司{id}"),
            company_short_name: None,
            contact_name: None,
            contact_phone: None,
            contact_email: None,
            contact_wechat: None,
            address: None,
            remark: None,
            subdomain: format!("tenant{id}"),
            db_label: "db:3306/name".to_string(),
            plan: "basic".to_string(),
            authorized_platforms: Vec::new(),
            staff_count: 0,
            status: "active".to_string(),
            created_at: NaiveDateTime::default(),
        }
    }

    #[test]
    fn resolve_selected_tenant_defaults_to_first() {
        assert_eq!(
            resolve_selected_tenant(&[tenant(3), tenant(5)], None).unwrap(),
            Some(3)
        );
    }

    #[test]
    fn resolve_selected_tenant_rejects_unknown_requested_id() {
        assert!(matches!(
            resolve_selected_tenant(&[tenant(3)], Some(9)),
            Err(AppError::NotFound)
        ));
    }

    #[test]
    fn build_platform_row_maps_enabled_locked_to_three_states() {
        let disabled = build_platform_row("y".into(), "Yahoo购物".into(), 10, false, false);
        assert_eq!(disabled.state_label, "未开通");
        assert!(disabled.is_disabled_state);

        let normal = build_platform_row("r".into(), "乐天".into(), 20, true, false);
        assert_eq!(normal.state_label, "已开通");
        assert!(normal.is_normal_state);

        let locked = build_platform_row("w".into(), "Wowma".into(), 30, true, true);
        assert_eq!(locked.state_label, "已锁定");
        assert!(locked.is_locked_state);
    }
}
