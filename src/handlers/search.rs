//! 全局搜索处理器（Task 15.3 / Requirements 4.1）。
//!
//! 薄 handler：从请求扩展取租户上下文（[`TenantContext`]）与主体（[`Principal`]），
//! 读取查询参数 `q`，调用 [`search_service::global_search`] 并以 JSON 返回命中。
//!
//! ## 租户隔离 + 店铺范围
//! - **租户隔离**：仅使用 `ctx.pool`（租户自有库连接池）执行搜索——跨租户数据物理不可达
//!   （见 [`search_service`] 模块文档 / Requirements 4.1）。
//! - **店铺范围**：由主体的 [`Principal::allowed_store_ids`] 推导 [`StoreScope`]：
//!   `None`（不受限：超管 / 公司管理员 / 全范围员工）⟹ [`StoreScope::All`]；
//!   `Some(ids)`（受限员工）⟹ [`StoreScope::Restricted(ids)`]，仅返回授权店铺的命中。

use axum::{
    extract::{Extension, Query, State},
    response::{Html, IntoResponse, Json, Response},
    routing::get,
    Router,
};
use serde::{Deserialize, Serialize};
use tera::Context;

use crate::error::AppError;
use crate::middleware::tenant::TenantContext;
use crate::models::user::{Principal, StoreScope};
use crate::services::platform_auth_service::{self, PlatformMenuItem};
use crate::services::search_service::{self, SearchResults};
use crate::state::AppState;

const SEARCH_TEMPLATE: &str = "search.html";

/// 全局搜索查询参数（`GET /search?q=...`）。
#[derive(Debug, Deserialize)]
pub struct SearchQuery {
    /// 查询词；缺省时按空串处理（返回空结果）。
    #[serde(default)]
    pub q: String,
    /// `format=json` 时返回 JSON，默认返回页面。
    #[serde(default)]
    pub format: Option<String>,
}

#[derive(Debug, Clone, Serialize)]
pub struct SearchView {
    pub tenant_name: String,
    pub tenant_id: i64,
    pub active_nav: String,
    pub active_platform: Option<String>,
    pub platform_menu: Vec<PlatformMenuItem>,
    pub query: String,
    pub results: SearchResults,
}

/// 由主体推导店铺范围：复用 [`Principal::allowed_store_ids`] 的既有语义。
///
/// - `None` ⟹ 不受店铺范围限制 ⟹ [`StoreScope::All`]。
/// - `Some(ids)` ⟹ 受限 ⟹ [`StoreScope::Restricted(ids)`]（`ids` 为空时安全返回无结果）。
fn scope_for(principal: &Principal) -> StoreScope {
    match principal.allowed_store_ids() {
        None => StoreScope::All,
        Some(ids) => StoreScope::Restricted(ids),
    }
}

/// 全局搜索（`GET /search`）。返回命中订单 / 子商品的 JSON 结果。
///
/// 主体由上游会话中间件注入；缺失视为未认证（[`AppError::Unauthorized`]）。
pub async fn search(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    principal: Option<Extension<Principal>>,
    Query(params): Query<SearchQuery>,
) -> Result<Response, AppError> {
    let Extension(principal) = principal.ok_or(AppError::Unauthorized)?;

    let scope = scope_for(&principal);
    let results = search_service::global_search(&ctx.pool, &params.q, &scope).await?;
    if params.format.as_deref() == Some("json") {
        return Ok(Json(results).into_response());
    }

    let platform_menu = platform_auth_service::load_sidebar_menu(state.master_pool(), ctx.tenant_id)
        .await
        .unwrap_or_else(|e| {
            tracing::warn!(error = %e, tenant_id = ctx.tenant_id, "平台菜单加载失败，搜索侧栏将显示基础入口");
            Vec::new()
        });
    let view = SearchView {
        tenant_name: ctx.company_name,
        tenant_id: ctx.tenant_id,
        active_nav: "search".to_string(),
        active_platform: None,
        platform_menu,
        query: params.q,
        results,
    };

    Ok(render_search(state.tera(), &view))
}

fn render_search(tera: &tera::Tera, view: &SearchView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(SEARCH_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = SEARCH_TEMPLATE, "搜索模板渲染失败，回退 JSON");
                Json(view).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "搜索上下文序列化失败，回退 JSON");
            Json(view).into_response()
        }
    }
}

/// 组装全局搜索路由。
///
/// - `GET /search?q=...` → 跨订单 / 子商品 / 采购 / 物流的全局搜索（JSON）。
pub fn routes() -> Router<AppState> {
    Router::new().route("/search", get(search))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::models::user::{Role, TenantId};
    use std::collections::HashMap;

    fn employee(store_scope: StoreScope) -> Principal {
        Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 42,
            role: Role::Buyer,
            overrides: HashMap::new(),
            store_scope,
        }
    }

    #[test]
    fn scope_for_super_admin_is_all() {
        assert_eq!(scope_for(&Principal::SuperAdmin), StoreScope::All);
    }

    #[test]
    fn scope_for_company_admin_is_all() {
        let p = Principal::CompanyAdmin {
            tenant_id: TenantId(7),
        };
        assert_eq!(scope_for(&p), StoreScope::All);
    }

    #[test]
    fn scope_for_employee_all_is_all() {
        assert_eq!(scope_for(&employee(StoreScope::All)), StoreScope::All);
    }

    #[test]
    fn scope_for_employee_restricted_preserves_ids() {
        let p = employee(StoreScope::Restricted(vec![3, 5]));
        assert_eq!(scope_for(&p), StoreScope::Restricted(vec![3, 5]));
    }

    #[test]
    fn scope_for_employee_empty_restricted_is_empty_restricted() {
        let p = employee(StoreScope::Restricted(vec![]));
        assert_eq!(scope_for(&p), StoreScope::Restricted(vec![]));
    }
}
