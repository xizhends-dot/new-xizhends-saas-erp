//! 超管后台「数据库管理」页面。
//!
//! 当前主库没有独立的数据库巡检表，本页基于主库 `tenants` 档案与运行时连接池状态
//! 展示可运维摘要。租户库连接信息只显示 `tenant_repo::db_label_from_enc` 生成的脱敏标签，
//! 不回显 DSN、用户名或口令。

use axum::{
    extract::{Path, Query, State},
    response::{Html, IntoResponse, Json, Redirect, Response},
    routing::get,
    Router,
};
use serde::{Deserialize, Serialize};
use tera::Context;

use crate::error::AppError;
use crate::repository::tenant_repo::{self, TenantSummary};
use crate::state::AppState;

const DATABASES_TEMPLATE: &str = "admin/databases.html";

#[derive(Debug, Clone, Default, Deserialize, Serialize)]
pub struct DatabaseQuery {
    #[serde(default)]
    pub status: Option<String>,
    #[serde(default)]
    pub plan: Option<String>,
    #[serde(default)]
    pub q: Option<String>,
}

#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize)]
pub struct DatabaseFilters {
    pub status: String,
    pub plan: String,
    pub q: String,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct DatabaseStats {
    pub master_status_label: String,
    pub master_status_class: String,
    pub master_pool_size: u32,
    pub master_pool_idle: usize,
    pub tenant_db_count: usize,
    pub active_tenant_db_count: usize,
    pub cached_pool_count: usize,
    pub invalid_config_count: usize,
    pub max_conns_per_tenant: u32,
    pub idle_ttl_secs: u64,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct DatabaseRow {
    pub tenant_id: i64,
    pub company_name: String,
    pub subdomain: String,
    pub db_label: String,
    pub plan: String,
    pub status: String,
    pub staff_count: i32,
    pub platform_count: usize,
    pub connection_label: String,
    pub connection_class: String,
    pub pool_policy: String,
    pub action_enabled: bool,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct DatabasesView {
    pub stats: DatabaseStats,
    pub rows: Vec<DatabaseRow>,
    pub filters: DatabaseFilters,
    pub total_rows: usize,
    pub body_class: String,
}

pub fn routes() -> Router<AppState> {
    Router::new()
        .route("/admin/databases", get(databases))
        .route(
            "/admin/databases/:id/invalidate",
            axum::routing::post(invalidate_pool),
        )
}

pub async fn databases(
    State(state): State<AppState>,
    Query(query): Query<DatabaseQuery>,
) -> Result<Response, AppError> {
    let filters = normalize_filters(query);
    let tenants = tenant_repo::list_tenants(state.master_pool()).await?;
    let master_ok = sqlx::query("SELECT 1")
        .execute(state.master_pool())
        .await
        .map(|_| true)
        .unwrap_or_else(|e| {
            tracing::warn!(error = %e, "数据库管理页主库探测失败");
            false
        });

    let view = build_databases_view(
        tenants,
        filters,
        master_ok,
        state.master_pool().size(),
        state.master_pool().num_idle(),
        state.pools().cached_tenant_count(),
        state.config().max_conns_per_tenant,
        state.config().tenant_pool_idle_ttl.as_secs(),
    );
    Ok(render_databases(state.tera(), &view))
}

pub async fn invalidate_pool(
    State(state): State<AppState>,
    Path(id): Path<i64>,
) -> Result<Redirect, AppError> {
    let tenants = tenant_repo::list_tenants(state.master_pool()).await?;
    if !tenants.iter().any(|tenant| tenant.id == id) {
        return Err(AppError::NotFound);
    }

    state.pools().invalidate(id).await;
    tracing::info!(tenant_id = id, "超管手动重置租户连接池缓存");
    Ok(Redirect::to("/admin/databases"))
}

pub fn normalize_filters(query: DatabaseQuery) -> DatabaseFilters {
    let status = query.status.unwrap_or_default().trim().to_ascii_lowercase();
    let status = match status.as_str() {
        "active" | "suspended" | "invalid" => status,
        _ => String::new(),
    };

    let plan = query.plan.unwrap_or_default().trim().to_ascii_lowercase();
    let plan = match plan.as_str() {
        "basic" | "pro" | "ent" => plan,
        _ => String::new(),
    };

    DatabaseFilters {
        status,
        plan,
        q: query.q.unwrap_or_default().trim().to_string(),
    }
}

pub fn build_databases_view(
    tenants: Vec<TenantSummary>,
    filters: DatabaseFilters,
    master_ok: bool,
    master_pool_size: u32,
    master_pool_idle: usize,
    cached_pool_count: usize,
    max_conns_per_tenant: u32,
    idle_ttl_secs: u64,
) -> DatabasesView {
    let total_rows = tenants.len();
    let invalid_config_count = tenants
        .iter()
        .filter(|tenant| tenant.db_label == "—")
        .count();
    let active_tenant_db_count = tenants
        .iter()
        .filter(|tenant| tenant.status == "active" && tenant.db_label != "—")
        .count();

    let rows = tenants
        .into_iter()
        .map(build_database_row)
        .filter(|row| database_row_matches(row, &filters))
        .collect();

    DatabasesView {
        stats: DatabaseStats {
            master_status_label: if master_ok { "正常" } else { "异常" }.to_string(),
            master_status_class: if master_ok { "ok" } else { "stop" }.to_string(),
            master_pool_size,
            master_pool_idle,
            tenant_db_count: total_rows,
            active_tenant_db_count,
            cached_pool_count,
            invalid_config_count,
            max_conns_per_tenant,
            idle_ttl_secs,
        },
        rows,
        filters,
        total_rows,
        body_class: "admin-identity".to_string(),
    }
}

pub fn build_database_row(tenant: TenantSummary) -> DatabaseRow {
    let db_invalid = tenant.db_label == "—";
    let suspended = tenant.status == "suspended";
    let (connection_label, connection_class, pool_policy, action_enabled) = if db_invalid {
        ("配置异常", "stop", "无法建池", false)
    } else if suspended {
        ("租户停用", "stop", "已失效", true)
    } else {
        ("配置可用", "ok", "按需创建", true)
    };

    DatabaseRow {
        tenant_id: tenant.id,
        company_name: tenant.company_name,
        subdomain: tenant.subdomain,
        db_label: tenant.db_label,
        plan: tenant.plan,
        status: tenant.status,
        staff_count: tenant.staff_count,
        platform_count: tenant.authorized_platforms.len(),
        connection_label: connection_label.to_string(),
        connection_class: connection_class.to_string(),
        pool_policy: pool_policy.to_string(),
        action_enabled,
    }
}

fn database_row_matches(row: &DatabaseRow, filters: &DatabaseFilters) -> bool {
    if !filters.plan.is_empty() && row.plan != filters.plan {
        return false;
    }
    if !filters.status.is_empty() {
        let row_status = if row.db_label == "—" {
            "invalid"
        } else {
            row.status.as_str()
        };
        if row_status != filters.status {
            return false;
        }
    }
    if filters.q.is_empty() {
        return true;
    }

    let q = filters.q.to_lowercase();
    row.company_name.to_lowercase().contains(&q)
        || row.subdomain.to_lowercase().contains(&q)
        || row.db_label.to_lowercase().contains(&q)
        || row.plan.to_lowercase().contains(&q)
}

fn render_databases(tera: &tera::Tera, view: &DatabasesView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(DATABASES_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = DATABASES_TEMPLATE, "数据库管理模板渲染失败，回退 JSON");
                Json(view).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "数据库管理上下文序列化失败，回退 JSON");
            Json(view).into_response()
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use sqlx::types::chrono::NaiveDateTime;

    fn tenant(id: i64, db_label: &str, status: &str, plan: &str) -> TenantSummary {
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
            db_label: db_label.to_string(),
            plan: plan.to_string(),
            authorized_platforms: vec!["y".to_string(), "r".to_string()],
            staff_count: id as i32,
            status: status.to_string(),
            created_at: NaiveDateTime::default(),
        }
    }

    #[test]
    fn build_database_row_does_not_expose_dsn_credentials() {
        let row = build_database_row(tenant(1, "127.0.0.1:3306/xizhends_tenant", "active", "pro"));
        let json = serde_json::to_string(&row).unwrap();
        assert!(json.contains("127.0.0.1:3306/xizhends_tenant"));
        assert!(!json.contains("mysql://"));
        assert!(!json.contains("password"));
    }

    #[test]
    fn invalid_database_label_marks_row_as_config_error() {
        let row = build_database_row(tenant(2, "—", "active", "basic"));
        assert_eq!(row.connection_label, "配置异常");
        assert_eq!(row.connection_class, "stop");
        assert!(!row.action_enabled);
    }

    #[test]
    fn build_view_counts_active_and_invalid_databases() {
        let view = build_databases_view(
            vec![
                tenant(1, "db:3306/t1", "active", "basic"),
                tenant(2, "—", "active", "pro"),
                tenant(3, "db:3306/t3", "suspended", "ent"),
            ],
            DatabaseFilters::default(),
            true,
            2,
            1,
            1,
            10,
            600,
        );

        assert_eq!(view.stats.tenant_db_count, 3);
        assert_eq!(view.stats.active_tenant_db_count, 1);
        assert_eq!(view.stats.invalid_config_count, 1);
        assert_eq!(view.rows.len(), 3);
    }

    #[test]
    fn filters_include_invalid_status_bucket() {
        let filters = normalize_filters(DatabaseQuery {
            status: Some("invalid".into()),
            plan: None,
            q: None,
        });
        let view = build_databases_view(
            vec![
                tenant(1, "db:3306/t1", "active", "basic"),
                tenant(2, "—", "active", "basic"),
            ],
            filters,
            true,
            0,
            0,
            0,
            10,
            600,
        );

        assert_eq!(view.rows.len(), 1);
        assert_eq!(view.rows[0].tenant_id, 2);
    }
}
