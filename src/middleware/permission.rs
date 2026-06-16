//! 权限校验中间件（`PermissionMiddleware`）：页面级守卫（Task 6.8 / Requirements 4.6）。
//!
//! 职责（design.md 5.6「中间件守卫」）：对应 old 的 `require_permission(...)`，在路由进入
//! handler 前校验当前主体是否拥有该页面所需的功能模块权限（[`FeatureModule`]）。校验失败时
//! 按请求来源分两支呈现（复刻 old 的「Ajax / 页面」双分支）：
//! - **普通请求**：返回 `302 Found` 跳转响应（`Location` 指向 [`FORBIDDEN_REDIRECT`]）。
//! - **HTMX 请求**（带 `HX-Request` 头）：返回 `403` + 错误片段（局部回填，不整页跳转），
//!   片段由 [`AppError::Forbidden`] 经 [`AppError::into_response_with`]`(true)` 渲染。
//!
//! ## 为何是「守卫工厂」
//! axum 的中间件本身不知道某条路由需要哪个功能模块，因此本模块导出**守卫工厂**
//! [`require_feature`]：传入路由所需的 [`FeatureModule`]，返回一个可被
//! `axum::middleware::from_fn` 挂载的中间件闭包。例如：
//!
//! ```ignore
//! use axum::{routing::get, Router, middleware::from_fn};
//! use crate::middleware::permission::require_feature;
//! use crate::models::user::FeatureModule;
//!
//! let app = Router::new()
//!     .route("/mail", get(mail_center))
//!     .layer(from_fn(require_feature(FeatureModule::KefuMail)));
//! ```
//!
//! 该闭包**实现 `Clone`**（捕获 `Copy` 的 `FeatureModule`），满足 `from_fn` 对中间件可克隆的要求。
//!
//! ## 主体来源
//! 主体由上游 [`session`](super::session) 中间件注入到请求扩展（`Extension<Principal>`）。
//! 本守卫从 `req.extensions()` 读取 [`Principal`]：
//! - 主体存在且 `can_access(module)` 为真 → 放行（`next.run`）。
//! - 主体存在但无该模块权限 → 按上文双分支拒绝（普通 302 / HTMX 403 片段）。
//! - 主体缺失（理论上不应发生，因 SessionMiddleware 先行注入）→ 防御性地按拒绝处理，
//!   避免在鉴权信息缺失时误放行。

use std::future::Future;
use std::pin::Pin;

use axum::extract::Request;
use axum::http::{header, HeaderName, HeaderValue, StatusCode};
use axum::middleware::Next;
use axum::response::{IntoResponse, Response};

use crate::error::AppError;
use crate::models::user::{FeatureModule, Principal};

/// 权限校验失败（普通请求）时跳转的目标路径。
///
/// 选择租户主页 `/` 作为落地页：被拒页面无权访问，跳回首页是 old 系统的常见行为，
/// 且不暴露被拒资源细节。若后续需要专门的「无权限」提示页，改此常量即可。
const FORBIDDEN_REDIRECT: &str = "/";

/// HTMX 请求标识头名（小写，`HeaderName::from_static` 要求小写）。
const HX_REQUEST: HeaderName = HeaderName::from_static("hx-request");

/// 守卫判定结果（内部用，便于纯逻辑单元测试）。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Outcome {
    /// 放行：主体存在且拥有所需模块权限。
    Allow,
    /// 拒绝：主体缺失或不具备所需模块权限。
    Deny,
}

/// 守卫工厂：传入路由所需的功能模块，返回可挂载到 `axum::middleware::from_fn` 的中间件闭包。
///
/// 返回的闭包捕获 `Copy` 的 [`FeatureModule`]，因此天然 `Clone`，满足 `from_fn` 的要求。
/// 闭包在每次请求时调用内部 [`guard`]，根据主体权限决定放行或拒绝。
pub fn require_feature(
    module: FeatureModule,
) -> impl Clone + Fn(Request, Next) -> Pin<Box<dyn Future<Output = Response> + Send>> {
    move |req, next| Box::pin(guard(module, req, next))
}

/// 守卫主体：读取注入的 [`Principal`]，按所需模块判定放行 / 拒绝。
async fn guard(module: FeatureModule, req: Request, next: Next) -> Response {
    // 以 HX-Request 头区分 HTMX 与普通请求，决定拒绝响应形态。
    let is_htmx = req.headers().get(HX_REQUEST).is_some();

    // 主体由 SessionMiddleware 注入；缺失视为无鉴权信息。
    let principal = req.extensions().get::<Principal>();

    match evaluate(principal, module) {
        Outcome::Allow => next.run(req).await,
        Outcome::Deny => permission_denied_response(is_htmx),
    }
}

/// 纯逻辑判定：给定（可选）主体与所需模块，决定放行还是拒绝。
///
/// 后置条件（design.md 5.6 / Requirements 4.6）：当且仅当主体存在且
/// [`Principal::can_access`]`(module)` 为真时放行；其余（含主体缺失）一律拒绝。
fn evaluate(principal: Option<&Principal>, module: FeatureModule) -> Outcome {
    match principal {
        Some(p) if p.can_access(module) => Outcome::Allow,
        _ => Outcome::Deny,
    }
}

/// 构造权限校验失败响应（双分支，见模块级文档）。
///
/// - `is_htmx == true`：`403` + 错误片段（复用 [`AppError::Forbidden`] 的 HTMX 呈现，
///   局部回填，不整页跳转）。
/// - `is_htmx == false`：`302 Found` 跳转，`Location` 指向 [`FORBIDDEN_REDIRECT`]。
fn permission_denied_response(is_htmx: bool) -> Response {
    if is_htmx {
        // 403 + 错误片段（HTMX 局部回填）。
        AppError::Forbidden.into_response_with(true)
    } else {
        // 302 跳转（普通请求整页重定向）。
        let mut resp = StatusCode::FOUND.into_response();
        resp.headers_mut().insert(
            header::LOCATION,
            HeaderValue::from_static(FORBIDDEN_REDIRECT),
        );
        resp
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::models::user::{Role, StoreScope, TenantId};
    use std::collections::HashMap;

    fn employee(overrides: HashMap<FeatureModule, bool>) -> Principal {
        Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 42,
            role: Role::ItemChecker, // 品检默认几乎全关，便于构造「无权限」场景
            overrides,
            store_scope: StoreScope::All,
        }
    }

    // ---- evaluate：放行 / 拒绝判定（纯逻辑）----------------------------------

    #[test]
    fn evaluate_allows_when_principal_can_access() {
        // 超管对任意模块恒可访问 → 放行。
        assert_eq!(
            evaluate(Some(&Principal::SuperAdmin), FeatureModule::SystemSettings),
            Outcome::Allow
        );
        // 公司管理员本租户全开 → 放行。
        let admin = Principal::CompanyAdmin {
            tenant_id: TenantId(7),
        };
        assert_eq!(
            evaluate(Some(&admin), FeatureModule::KefuMail),
            Outcome::Allow
        );
    }

    #[test]
    fn evaluate_denies_when_principal_lacks_permission() {
        // 品检默认关闭 system_settings → 拒绝。
        let emp = employee(HashMap::new());
        assert_eq!(
            evaluate(Some(&emp), FeatureModule::SystemSettings),
            Outcome::Deny
        );
    }

    #[test]
    fn evaluate_respects_explicit_override() {
        // 显式授予 system_settings 后应放行（override 优先于角色默认）。
        let mut overrides = HashMap::new();
        overrides.insert(FeatureModule::SystemSettings, true);
        let emp = employee(overrides);
        assert_eq!(
            evaluate(Some(&emp), FeatureModule::SystemSettings),
            Outcome::Allow
        );
    }

    #[test]
    fn evaluate_denies_when_principal_missing() {
        // 主体缺失（鉴权信息不存在）→ 防御性拒绝，绝不误放行。
        assert_eq!(evaluate(None, FeatureModule::KefuMail), Outcome::Deny);
    }

    // ---- permission_denied_response：双分支呈现 ------------------------------

    #[test]
    fn denied_normal_request_returns_302_redirect() {
        let resp = permission_denied_response(false);
        assert_eq!(resp.status(), StatusCode::FOUND, "普通请求应返回 302");
        let loc = resp
            .headers()
            .get(header::LOCATION)
            .expect("普通请求拒绝响应应带 Location 头");
        assert_eq!(loc, FORBIDDEN_REDIRECT, "应跳转到约定的目标路径");
    }

    #[test]
    fn denied_htmx_request_returns_403_fragment() {
        let resp = permission_denied_response(true);
        assert_eq!(resp.status(), StatusCode::FORBIDDEN, "HTMX 请求应返回 403");
        // HTMX 片段为局部回填，不应携带整页跳转的 Location 头。
        assert!(
            resp.headers().get(header::LOCATION).is_none(),
            "HTMX 403 片段不应带 Location 跳转头"
        );
    }

    #[test]
    fn denied_branches_differ_by_status() {
        // 同为「校验失败」，普通请求与 HTMX 请求状态码不同（302 vs 403）。
        let normal = permission_denied_response(false);
        let htmx = permission_denied_response(true);
        assert_ne!(normal.status(), htmx.status());
    }

    /// 验证 HTMX 拒绝响应体确实是错误片段（而非整页），与 error.rs 的片段渲染一致。
    #[tokio::test]
    async fn denied_htmx_body_is_error_fragment_not_full_page() {
        use axum::body::to_bytes;

        let resp = permission_denied_response(true);
        let body = to_bytes(resp.into_body(), 64 * 1024).await.unwrap();
        let text = String::from_utf8_lossy(&body);
        assert!(text.contains("app-error"), "应为错误片段: {text}");
        assert!(
            !text.contains("<!DOCTYPE html>"),
            "片段不应是整页文档: {text}"
        );
    }

    /// 守卫工厂返回的闭包须可克隆（`from_fn` 要求中间件可克隆）。
    #[test]
    fn require_feature_returns_cloneable_guard() {
        let guard = require_feature(FeatureModule::KefuMail);
        let _cloned = guard.clone();
    }
}
