//! 租户识别中间件（Task 3.3 / Requirements 1.1、1.2）。
//!
//! 职责（见 design.md 2.2 请求生命周期、4.5 与中间件衔接）：
//! 1. 由请求 `Host`（子域名 / 自定义域名）解析 `tenant_id`（主库 `tenants` 查询，仅启用租户）；
//! 2. 调用 [`TenantPoolManager::pool_for`](crate::db::pool::TenantPoolManager::pool_for)
//!    取得该租户库连接池；
//! 3. 构造 [`TenantContext`]（`tenant_id` / `pool` / `company_name`）注入请求扩展，
//!    供下游 `SessionMiddleware` / handler / repository 使用 `ctx.pool` 操作租户库；
//! 4. 无法匹配启用租户（或缺失可用 Host）时返回 [`AppError::TenantUnavailable`]。
//!
//! 中间件位于栈最前：租户识别 → 会话认证 → 权限校验。

use axum::{
    extract::{Request, State},
    http::header,
    middleware::Next,
    response::Response,
};
use sqlx::MySqlPool;

use crate::db::pool::TenantId;
use crate::error::AppError;
use crate::repository::{billing_repo, tenant_repo};
use crate::state::AppState;

/// 租户上下文：注入请求扩展，贯穿单次请求的下游处理（design.md 4.5）。
///
/// 持有该租户库连接池（`MySqlPool` 内部 `Arc` 共享，克隆廉价）；下游一律经 `pool`
/// 访问租户库，主库仅由 `tenant_repo` / 超管功能访问。
#[derive(Clone)]
pub struct TenantContext {
    /// 租户标识（主库 `tenants` 主键）。
    pub tenant_id: TenantId,
    /// 该租户库连接池。
    pub pool: MySqlPool,
    /// 公司名（用于页面展示 / 日志上下文）。
    pub company_name: String,
}

/// 租户识别中间件入口（`axum::middleware::from_fn_with_state` 适配）。
///
/// 成功时把 [`TenantContext`] 注入请求扩展并放行；失败时以 [`AppError`] 的
/// `IntoResponse` 统一呈现（`TenantUnavailable` → 503；建池失败 → 503）。
pub async fn tenant_middleware(
    State(state): State<AppState>,
    mut req: Request,
    next: Next,
) -> Result<Response, AppError> {
    // 1. 解析请求 Host（缺失 Host 视为不可识别租户）。
    let host = extract_host(&req).ok_or(AppError::TenantUnavailable)?;

    // 2. 主库按 Host 解析启用租户（子域名 / 自定义域名），未命中即不可用。
    let (tenant_id, company_name) = tenant_repo::resolve_tenant_by_host(state.master_pool(), &host)
        .await?
        .ok_or(AppError::TenantUnavailable)?;

    let billing = billing_repo::access_for_tenant(state.master_pool(), tenant_id).await?;
    if !billing.allowed {
        return Err(AppError::BillingUnavailable);
    }

    // 3. 取（或懒建）该租户库连接池。
    let pool = state.pools().pool_for(tenant_id).await?;

    // 4. 注入租户上下文，放行下游。
    let ctx = TenantContext {
        tenant_id,
        pool,
        company_name,
    };
    req.extensions_mut().insert(ctx);

    Ok(next.run(req).await)
}

/// 从请求中提取用于租户解析的 Host。
///
/// 优先级：`X-Forwarded-Host`（反向代理场景，取首个值）→ `Host` 头 → URI 自带主机。
/// 任一来源得到非空字符串即返回；全部缺失返回 `None`。
fn extract_host(req: &Request) -> Option<String> {
    // 反向代理转发的原始 Host（可能为逗号分隔列表，取第一个）。
    if let Some(value) = req.headers().get("x-forwarded-host") {
        if let Ok(s) = value.to_str() {
            let first = s.split(',').next().unwrap_or(s).trim();
            if !first.is_empty() {
                return Some(first.to_string());
            }
        }
    }

    // 标准 Host 头。
    if let Some(value) = req.headers().get(header::HOST) {
        if let Ok(s) = value.to_str() {
            let s = s.trim();
            if !s.is_empty() {
                return Some(s.to_string());
            }
        }
    }

    // HTTP/2 / 绝对形式 URI 可能直接携带主机。
    req.uri().host().map(|h| h.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::Body;
    use axum::http::Request as HttpRequest;

    fn req_with_headers(pairs: &[(&str, &str)]) -> Request {
        let mut builder = HttpRequest::builder().uri("/");
        for (k, v) in pairs {
            builder = builder.header(*k, *v);
        }
        builder.body(Body::empty()).expect("构造请求不应失败")
    }

    #[test]
    fn extracts_host_header() {
        let req = req_with_headers(&[("host", "companya.example.com")]);
        assert_eq!(extract_host(&req).as_deref(), Some("companya.example.com"));
    }

    #[test]
    fn forwarded_host_takes_precedence() {
        let req = req_with_headers(&[
            ("host", "internal-lb:8080"),
            ("x-forwarded-host", "companya.example.com"),
        ]);
        assert_eq!(extract_host(&req).as_deref(), Some("companya.example.com"));
    }

    #[test]
    fn forwarded_host_uses_first_of_list() {
        let req = req_with_headers(&[("x-forwarded-host", "a.example.com, b.example.com")]);
        assert_eq!(extract_host(&req).as_deref(), Some("a.example.com"));
    }

    #[test]
    fn missing_host_yields_none() {
        let req = req_with_headers(&[]);
        assert_eq!(extract_host(&req), None);
    }

    #[tokio::test]
    async fn tenant_context_is_cloneable() {
        // 构造一个 lazy 池占位，验证上下文可廉价克隆（不触发真实连接）。
        // 使用 tokio 运行时上下文：connect_lazy 需要 Tokio 运行时在场。
        let pool = sqlx::mysql::MySqlPoolOptions::new()
            .max_connections(1)
            .connect_lazy("mysql://u:p@127.0.0.1:3306/d")
            .expect("lazy 池构造不应失败");
        let ctx = TenantContext {
            tenant_id: 42,
            pool,
            company_name: "公司A".to_string(),
        };
        let cloned = ctx.clone();
        assert_eq!(cloned.tenant_id, 42);
        assert_eq!(cloned.company_name, "公司A");
    }
}
