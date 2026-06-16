//! 租户端首页仪表盘。

use axum::{
    extract::{Extension, State},
    response::{Html, IntoResponse, Json, Response},
    routing::get,
    Router,
};
use serde::Serialize;
use tera::Context;

use crate::error::AppError;
use crate::middleware::tenant::TenantContext;
use crate::services::platform_auth_service::{self, PlatformMenuItem};
use crate::state::AppState;

const DASHBOARD_TEMPLATE: &str = "dashboard.html";

#[derive(Debug, Clone, Serialize, sqlx::FromRow)]
pub struct DashboardStats {
    pub total_orders: i64,
    pub cn_purchase_items: i64,
    pub jp_stock_items: i64,
    pub pending_items: i64,
    pub intl_shipments: i64,
}

#[derive(Debug, Clone, Serialize, sqlx::FromRow)]
pub struct RecentOrderRow {
    pub id: i64,
    pub platform: String,
    pub platform_order_id: String,
    pub order_status: String,
    pub customer_name: String,
    pub imported_at: sqlx::types::chrono::NaiveDateTime,
}

#[derive(Debug, Clone, Serialize, sqlx::FromRow)]
pub struct RecentLogRow {
    pub created_at: sqlx::types::chrono::NaiveDateTime,
    pub operator: String,
    pub action_type: String,
    pub field_name: String,
}

#[derive(Debug, Clone, Serialize)]
pub struct DashboardView {
    pub tenant_name: String,
    pub tenant_id: i64,
    pub active_nav: String,
    pub active_platform: Option<String>,
    pub platform_menu: Vec<PlatformMenuItem>,
    pub stats: DashboardStats,
    pub recent_orders: Vec<RecentOrderRow>,
    pub recent_logs: Vec<RecentLogRow>,
    pub purchase_count: i64,
    pub jpstock_count: i64,
}

pub fn routes() -> Router<AppState> {
    Router::new().route("/dashboard", get(dashboard))
}

pub async fn dashboard(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
) -> Result<Response, AppError> {
    let stats = load_stats(&ctx.pool).await?;
    let recent_orders = load_recent_orders(&ctx.pool).await?;
    let recent_logs = load_recent_logs(&ctx.pool).await?;
    let platform_menu = platform_auth_service::load_sidebar_menu(state.master_pool(), ctx.tenant_id)
        .await
        .unwrap_or_else(|e| {
            tracing::warn!(error = %e, tenant_id = ctx.tenant_id, "平台菜单加载失败，首页侧栏将显示基础入口");
            Vec::new()
        });

    let view = DashboardView {
        tenant_name: ctx.company_name,
        tenant_id: ctx.tenant_id,
        active_nav: "dashboard".to_string(),
        active_platform: None,
        purchase_count: stats.cn_purchase_items,
        jpstock_count: stats.jp_stock_items,
        platform_menu,
        stats,
        recent_orders,
        recent_logs,
    };

    Ok(render_dashboard(state.tera(), &view))
}

async fn load_stats(pool: &sqlx::MySqlPool) -> Result<DashboardStats, AppError> {
    let stats = sqlx::query_as::<_, DashboardStats>(
        "SELECT \
            (SELECT CAST(COUNT(*) AS SIGNED) FROM `orders`) AS `total_orders`, \
            (SELECT CAST(COUNT(*) AS SIGNED) FROM `order_items` WHERE `source_type` = 'cn_purchase') AS `cn_purchase_items`, \
            (SELECT CAST(COUNT(*) AS SIGNED) FROM `order_items` WHERE `source_type` = 'jp_stock') AS `jp_stock_items`, \
            (SELECT CAST(COUNT(*) AS SIGNED) FROM `order_items` WHERE `source_type` = 'pending') AS `pending_items`, \
            (SELECT CAST(COUNT(*) AS SIGNED) FROM `intl_shipments`) AS `intl_shipments`",
    )
    .fetch_one(pool)
    .await?;

    Ok(stats)
}

async fn load_recent_orders(pool: &sqlx::MySqlPool) -> Result<Vec<RecentOrderRow>, AppError> {
    let rows = sqlx::query_as::<_, RecentOrderRow>(
        "SELECT `id`, `platform`, `platform_order_id`, `order_status`, \
                `customer_name`, `imported_at` \
         FROM `orders` \
         ORDER BY `id` DESC \
         LIMIT 8",
    )
    .fetch_all(pool)
    .await?;

    Ok(rows)
}

async fn load_recent_logs(pool: &sqlx::MySqlPool) -> Result<Vec<RecentLogRow>, AppError> {
    let rows = sqlx::query_as::<_, RecentLogRow>(
        "SELECT `created_at`, `operator`, `action_type`, `field_name` \
         FROM `order_logs` \
         ORDER BY `id` DESC \
         LIMIT 8",
    )
    .fetch_all(pool)
    .await?;

    Ok(rows)
}

fn render_dashboard(tera: &tera::Tera, view: &DashboardView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(DASHBOARD_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = DASHBOARD_TEMPLATE, "首页模板渲染失败，回退 JSON");
                Json(view).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "首页上下文序列化失败，回退 JSON");
            Json(view).into_response()
        }
    }
}
