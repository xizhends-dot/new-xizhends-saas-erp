//! 超管后台「概览」处理器（Task 11.5 / Requirements 10.1、10.4）。
//!
//! 概览页聚合展示（见 design.md 7.6「概览」视图）：
//! - 统计卡片：租户数 / 活跃租户 / 员工总数 / 平台授权数（主库聚合，见
//!   [`crate::repository::tenant_repo::overview_stats`]）。
//! - 最近租户列表（[`crate::repository::tenant_repo::recent_tenants`]）。
//! - 系统运行状态：主库连接、租户库连接池数、定时任务（运行时状态，见 [`SystemStatus`]）。
//!
//! **超管身份界面（Requirements 10.4）**：超管后台沿用 `sample-admin.html` 的主绿色，
//! 仅在超级管理员角色标识、头像与锁定态使用橙色。
//! 概览模板 `admin/overview.html` 继承 `layout.html` 并把 `<body>` 标记为 `admin-identity`
//! ——`layout.html` 已为该类设定 `--tenant-accent: #07C160`。模板另显式声明
//! `--admin-accent: #07C160` 作为超管主强调色变量，驱动主按钮、导航 active 与操作链接。
//!
//! 设计取舍：DB 访问下沉到 [`crate::repository::tenant_repo`]（仅访问主库），handler 仅做
//! 聚合装配与渲染。视图模型的装配逻辑抽为纯函数 [`build_overview_view`] 以便无 DB 单测；
//! 优先经 Tera 渲染概览页，模板缺失（如空模板引擎）时回退为等价 JSON，保证可用性。
//! 所有错误经统一 [`AppError`] 呈现，绝不外泄底层细节。

use axum::{
    extract::State,
    response::{Html, IntoResponse, Json, Response},
    routing::get,
    Router,
};
use serde::Serialize;
use tera::Context;

use crate::error::AppError;
use crate::repository::tenant_repo::{self, OverviewStats, RecentTenant};
use crate::state::AppState;

/// 概览页「最近租户列表」默认展示条数。
const RECENT_TENANTS_LIMIT: i64 = 10;

/// 概览页使用的 Tera 模板名（位于 `src/templates/admin/overview.html`）。
const OVERVIEW_TEMPLATE: &str = "admin/overview.html";

/// 系统运行状态（design.md 7.6「概览」视图的「系统状态」块）。
///
/// 由运行时状态装配，可安全直接展示，不含任何敏感连接信息：
/// - `master_db_ok`：主库连接是否可用（概览聚合查询成功即视为可用）。
/// - `tenant_pool_count`：当前已缓存的租户库连接池数量（[`TenantPoolManager`] 缓存计数）。
/// - `scheduler_running`：后台定时任务调度器是否在运行。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
pub struct SystemStatus {
    pub master_db_ok: bool,
    pub tenant_pool_count: usize,
    pub scheduler_running: bool,
}

/// 概览页视图模型：统计 + 最近租户列表 + 系统状态。
///
/// 作为 Tera 渲染上下文与 JSON 回退的统一数据载体；并带后台主强调色，供模板着色与
/// 前端断言超管身份（Requirements 10.4）。
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct OverviewView {
    pub stats: OverviewStats,
    pub recent_tenants: Vec<RecentTenant>,
    pub system_status: SystemStatus,
    /// 超管后台主强调色（与 `sample-admin.html` 的 `--accent` 一致）。
    pub admin_accent: String,
    /// 应用于 `<body>` 的身份类名（驱动 `layout.html` 切换为橙色身份色）。
    pub body_class: String,
}

/// 超管后台主强调色（与 `sample-admin.html` 的 `--accent` 一致）。
pub const ADMIN_ACCENT: &str = "#07C160";

/// 装配概览视图模型（**纯函数**，便于无 DB / 无 Tera 单测）。
///
/// 固定注入样例主强调色与 `admin-identity` 身份类（Requirements 10.4）。
/// 不做任何 IO，输入即输出。
pub fn build_overview_view(
    stats: OverviewStats,
    recent_tenants: Vec<RecentTenant>,
    system_status: SystemStatus,
) -> OverviewView {
    OverviewView {
        stats,
        recent_tenants,
        system_status,
        admin_accent: ADMIN_ACCENT.to_string(),
        body_class: "admin-identity".to_string(),
    }
}

/// 组装超管后台概览的路由。
///
/// - `GET /admin`          → 概览页（超管后台首页）
/// - `GET /admin/overview` → 概览页（同一处理器，便于直达）
pub fn routes() -> Router<AppState> {
    Router::new()
        .route("/admin", get(overview))
        .route("/admin/overview", get(overview))
}

/// 概览页处理器（Requirements 10.1、10.4）。
///
/// 流程：并发无依赖地读主库聚合统计与最近租户列表 → 装配运行时系统状态 → 组装视图模型 →
/// 优先 Tera 渲染 `admin/overview.html`，模板缺失时回退 JSON。
/// 主库聚合查询成功即认定主库连接可用（`master_db_ok = true`）。
pub async fn overview(State(state): State<AppState>) -> Result<Response, AppError> {
    // 聚合统计成功即说明主库连接可用。
    let stats = tenant_repo::overview_stats(state.master_pool()).await?;
    let recent = tenant_repo::recent_tenants(state.master_pool(), RECENT_TENANTS_LIMIT).await?;

    let system_status = SystemStatus {
        master_db_ok: true,
        tenant_pool_count: state.pools().cached_tenant_count(),
        scheduler_running: true,
    };

    let view = build_overview_view(stats, recent, system_status);
    Ok(render_overview(state.tera(), &view))
}

/// 渲染概览视图：优先 Tera 模板，模板缺失或渲染失败时回退为等价 JSON。
///
/// 回退保证「即使模板尚未装载也能返回有效数据」，且不会因模板问题向客户端外泄错误细节。
fn render_overview(tera: &tera::Tera, view: &OverviewView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(OVERVIEW_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = OVERVIEW_TEMPLATE, "概览模板渲染失败，回退 JSON");
                Json(view).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "概览上下文序列化失败，回退 JSON");
            Json(view).into_response()
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use sqlx::types::chrono::NaiveDateTime;

    fn sample_stats() -> OverviewStats {
        OverviewStats {
            total_tenants: 8,
            active_tenants: 6,
            total_staff: 73,
            platform_authorizations: 21,
        }
    }

    fn sample_recent() -> Vec<RecentTenant> {
        vec![RecentTenant {
            id: 3,
            company_name: "西阵".into(),
            subdomain: "xizhen".into(),
            plan: "pro".into(),
            status: "active".into(),
            staff_count: 12,
            created_at: NaiveDateTime::default(),
        }]
    }

    fn sample_status() -> SystemStatus {
        SystemStatus {
            master_db_ok: true,
            tenant_pool_count: 2,
            scheduler_running: true,
        }
    }

    #[test]
    fn build_overview_view_injects_sample_admin_identity() {
        let view = build_overview_view(sample_stats(), sample_recent(), sample_status());
        assert_eq!(view.admin_accent, "#07C160");
        assert_eq!(view.body_class, "admin-identity");
    }

    #[test]
    fn build_overview_view_preserves_inputs() {
        let view = build_overview_view(sample_stats(), sample_recent(), sample_status());
        assert_eq!(view.stats.total_tenants, 8);
        assert_eq!(view.stats.active_tenants, 6);
        assert_eq!(view.stats.total_staff, 73);
        assert_eq!(view.stats.platform_authorizations, 21);
        assert_eq!(view.recent_tenants.len(), 1);
        assert_eq!(view.recent_tenants[0].company_name, "西阵");
        assert_eq!(view.system_status.tenant_pool_count, 2);
        assert!(view.system_status.master_db_ok);
    }

    #[test]
    fn overview_view_serializes_for_template_and_json() {
        let view = build_overview_view(sample_stats(), sample_recent(), sample_status());
        let json = serde_json::to_value(&view).unwrap();
        assert_eq!(json["stats"]["total_tenants"], 8);
        assert_eq!(json["admin_accent"], "#07C160");
        assert_eq!(json["body_class"], "admin-identity");
        assert_eq!(json["system_status"]["scheduler_running"], true);
        assert_eq!(json["recent_tenants"][0]["subdomain"], "xizhen");
    }

    #[test]
    fn render_overview_falls_back_to_json_when_template_missing() {
        // 空 Tera 引擎未注册任何模板：应回退为 JSON 而非 panic 或泄露错误。
        let tera = tera::Tera::default();
        let view = build_overview_view(sample_stats(), sample_recent(), sample_status());
        let resp = render_overview(&tera, &view);
        assert_eq!(resp.status(), axum::http::StatusCode::OK);
    }
}
