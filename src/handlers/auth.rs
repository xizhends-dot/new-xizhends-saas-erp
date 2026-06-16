//! 认证处理器：登录入口。

use std::sync::OnceLock;

use axum::{
    extract::{Form, State},
    http::{header, HeaderMap, HeaderValue, StatusCode},
    response::{Html, IntoResponse, Redirect, Response},
    routing::get,
    Router,
};
use serde::{Deserialize, Serialize};
use tera::{Context, Tera};

use crate::error::AppError;
use crate::repository::{billing_repo, session_repo, tenant_repo};
use crate::services::auth_service::{self, LoginRateLimiter};
use crate::state::AppState;
use crate::{header_host, is_saas_admin_host};

const ADMIN_REDIRECT: &str = "/admin/overview";
const TENANT_REDIRECT: &str = "/dashboard";
const GENERIC_LOGIN_ERROR: &str = "账号或密码不正确";
const LOGIN_TEMPLATE: &str = "login.html";
const LOGOUT_TEMPLATE: &str = "logout.html";

static LOGIN_LIMITER: OnceLock<LoginRateLimiter> = OnceLock::new();

#[derive(Debug, Deserialize)]
pub struct LoginForm {
    username: String,
    password: String,
}

pub fn routes() -> Router<AppState> {
    Router::new()
        .route("/login", get(login_page).post(login_submit))
        .route("/logout", get(logout_page).post(logout_submit))
}

async fn login_page(State(state): State<AppState>, headers: HeaderMap) -> Response {
    render_login_page(
        state.tera(),
        &build_login_view("", None, is_admin_request(&headers)),
    )
}

async fn login_submit(
    State(state): State<AppState>,
    headers: HeaderMap,
    Form(form): Form<LoginForm>,
) -> Response {
    let host = header_host(&headers);
    let is_admin = is_saas_admin_host(host.as_deref());
    let username = form.username.trim();
    let ip = client_ip(&headers);
    let user_agent = user_agent(&headers);
    let limiter = LOGIN_LIMITER.get_or_init(LoginRateLimiter::default);

    let result = if is_admin {
        auth_service::login_super_admin(
            state.master_pool(),
            limiter,
            username,
            &form.password,
            ip.as_deref(),
            user_agent.as_deref(),
        )
        .await
        .map(|outcome| (outcome.set_cookie, ADMIN_REDIRECT))
    } else {
        login_tenant(
            &state,
            limiter,
            host.as_deref(),
            username,
            &form.password,
            &ip,
            &user_agent,
        )
        .await
    };

    match result {
        Ok((set_cookie, location)) => redirect_with_cookie(location, &set_cookie),
        Err(err) => login_error_response(state.tera(), err, username, is_admin),
    }
}

async fn logout_page(State(state): State<AppState>, headers: HeaderMap) -> Response {
    render_logout_page(
        state.tera(),
        &build_logout_view(LogoutMode::Confirm, is_admin_request(&headers)),
    )
}

async fn logout_submit(State(state): State<AppState>, headers: HeaderMap) -> Response {
    if let Some(token) = session_token(&headers) {
        if let Err(err) = session_repo::revoke(state.master_pool(), &token).await {
            tracing::warn!(error = %err, "logout session revoke failed");
        }
    }

    let mut response = render_logout_page(
        state.tera(),
        &build_logout_view(LogoutMode::Completed, is_admin_request(&headers)),
    );
    if let Ok(value) = HeaderValue::from_str(&auth_service::build_expired_cookie()) {
        response.headers_mut().append(header::SET_COOKIE, value);
    } else {
        tracing::error!("logout produced invalid Set-Cookie header");
    }
    response
}

async fn login_tenant(
    state: &AppState,
    limiter: &LoginRateLimiter,
    host: Option<&str>,
    username: &str,
    password: &str,
    ip: &Option<String>,
    user_agent: &Option<String>,
) -> Result<(String, &'static str), AppError> {
    let host = host.ok_or(AppError::TenantUnavailable)?;
    let (tenant_id, _) = tenant_repo::resolve_tenant_by_host(state.master_pool(), host)
        .await?
        .ok_or(AppError::TenantUnavailable)?;
    let billing = billing_repo::access_for_tenant(state.master_pool(), tenant_id).await?;
    if !billing.allowed {
        return Err(AppError::BillingUnavailable);
    }
    let tenant_pool = state.pools().pool_for(tenant_id).await?;
    let outcome = auth_service::login_tenant_user(
        state.master_pool(),
        &tenant_pool,
        limiter,
        tenant_id,
        username,
        password,
        ip.as_deref(),
        user_agent.as_deref(),
    )
    .await?;

    Ok((outcome.set_cookie, TENANT_REDIRECT))
}

fn login_error_response(tera: &Tera, err: AppError, username: &str, is_admin: bool) -> Response {
    let (status, message) = login_error_status_and_message(err);

    (
        status,
        render_login_page(tera, &build_login_view(username, Some(&message), is_admin)),
    )
        .into_response()
}

fn login_error_status_and_message(err: AppError) -> (StatusCode, String) {
    match err {
        AppError::Validation(message) => (StatusCode::UNPROCESSABLE_ENTITY, message),
        AppError::Unauthorized => (StatusCode::UNAUTHORIZED, GENERIC_LOGIN_ERROR.to_string()),
        AppError::TenantUnavailable => (
            StatusCode::SERVICE_UNAVAILABLE,
            "租户不可用或已停用".to_string(),
        ),
        AppError::BillingUnavailable => (
            StatusCode::PAYMENT_REQUIRED,
            AppError::BillingUnavailable.client_message(),
        ),
        other => {
            tracing::warn!(error = %other, "login failed");
            (other.status_code(), "登录失败，请稍后再试".to_string())
        }
    }
}

fn redirect_with_cookie(location: &str, set_cookie: &str) -> Response {
    let mut response = Redirect::to(location).into_response();
    if let Ok(value) = HeaderValue::from_str(set_cookie) {
        response.headers_mut().append(header::SET_COOKIE, value);
    } else {
        tracing::error!("login produced invalid Set-Cookie header");
    }
    response
}

fn is_admin_request(headers: &HeaderMap) -> bool {
    is_saas_admin_host(header_host(headers).as_deref())
}

fn client_ip(headers: &HeaderMap) -> Option<String> {
    headers
        .get("cf-connecting-ip")
        .or_else(|| headers.get("x-real-ip"))
        .or_else(|| headers.get("x-forwarded-for"))
        .and_then(|value| value.to_str().ok())
        .and_then(|raw| raw.split(',').next())
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .map(ToOwned::to_owned)
}

fn user_agent(headers: &HeaderMap) -> Option<String> {
    headers
        .get(header::USER_AGENT)
        .and_then(|value| value.to_str().ok())
        .map(str::trim)
        .filter(|value| !value.is_empty())
        .map(|value| value.chars().take(512).collect())
}

fn session_token(headers: &HeaderMap) -> Option<String> {
    headers
        .get(header::COOKIE)
        .and_then(|value| value.to_str().ok())
        .and_then(|cookies| parse_cookie_value(cookies, auth_service::SESSION_COOKIE_NAME))
}

fn parse_cookie_value(header: &str, name: &str) -> Option<String> {
    header.split(';').find_map(|part| {
        let (key, value) = part.trim().split_once('=')?;
        if key.trim() == name {
            let value = value.trim();
            (!value.is_empty()).then(|| value.to_string())
        } else {
            None
        }
    })
}

#[derive(Debug, Clone, Serialize)]
struct LoginView {
    tenant_name: String,
    page_title: String,
    subtitle: String,
    username: String,
    error: Option<String>,
    body_class: String,
    scope: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum LogoutMode {
    Confirm,
    Completed,
}

#[derive(Debug, Clone, Serialize)]
struct LogoutView {
    tenant_name: String,
    page_title: String,
    subtitle: String,
    body_class: String,
    scope: String,
    mode: String,
    is_completed: bool,
    primary_action: String,
    primary_href: String,
    cancel_href: String,
}

fn build_login_view(username: &str, error: Option<&str>, is_admin: bool) -> LoginView {
    if is_admin {
        LoginView {
            tenant_name: "西阵 SaaS 管理".to_string(),
            page_title: "西阵 SaaS 管理".to_string(),
            subtitle: "超级管理员登录".to_string(),
            username: username.to_string(),
            error: error.map(ToOwned::to_owned),
            body_class: "admin-identity".to_string(),
            scope: "admin".to_string(),
        }
    } else {
        LoginView {
            tenant_name: "西阵订单系统".to_string(),
            page_title: "西阵订单系统".to_string(),
            subtitle: "租户账号登录".to_string(),
            username: username.to_string(),
            error: error.map(ToOwned::to_owned),
            body_class: String::new(),
            scope: "tenant".to_string(),
        }
    }
}

fn build_logout_view(mode: LogoutMode, is_admin: bool) -> LogoutView {
    let (tenant_name, body_class, scope, cancel_href) = if is_admin {
        (
            "\u{897f}\u{9635} SaaS \u{7ba1}\u{7406}",
            "admin-identity",
            "admin",
            ADMIN_REDIRECT,
        )
    } else {
        (
            "\u{897f}\u{9635}\u{8ba2}\u{5355}\u{7cfb}\u{7edf}",
            "",
            "tenant",
            TENANT_REDIRECT,
        )
    };

    let is_completed = mode == LogoutMode::Completed;
    LogoutView {
        tenant_name: tenant_name.to_string(),
        page_title: if is_completed {
            "\u{5df2}\u{9000}\u{51fa}\u{767b}\u{5f55}".to_string()
        } else {
            "\u{786e}\u{8ba4}\u{9000}\u{51fa}\u{767b}\u{5f55}".to_string()
        },
        subtitle: if is_completed {
            "\u{5f53}\u{524d}\u{4f1a}\u{8bdd}\u{5df2}\u{6e05}\u{9664}\u{ff0c}\u{8bf7}\u{6ce8}\u{610f}\u{5173}\u{95ed}\u{5171}\u{7528}\u{8bbe}\u{5907}\u{7684}\u{6d4f}\u{89c8}\u{5668}\u{9875}\u{9762}\u{3002}".to_string()
        } else {
            "\u{9000}\u{51fa}\u{540e}\u{9700}\u{8981}\u{91cd}\u{65b0}\u{767b}\u{5f55}\u{624d}\u{80fd}\u{7ee7}\u{7eed}\u{64cd}\u{4f5c}\u{3002}".to_string()
        },
        body_class: body_class.to_string(),
        scope: scope.to_string(),
        mode: if is_completed { "completed" } else { "confirm" }.to_string(),
        is_completed,
        primary_action: if is_completed {
            "\u{8fd4}\u{56de}\u{767b}\u{5f55}".to_string()
        } else {
            "\u{786e}\u{8ba4}\u{9000}\u{51fa}".to_string()
        },
        primary_href: "/login".to_string(),
        cancel_href: cancel_href.to_string(),
    }
}

fn render_login_page(tera: &Tera, view: &LoginView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(LOGIN_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = LOGIN_TEMPLATE, "login template render failed");
                Html(render_login_fallback(view)).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "login view serialization failed");
            Html(render_login_fallback(view)).into_response()
        }
    }
}

fn render_logout_page(tera: &Tera, view: &LogoutView) -> Response {
    match Context::from_serialize(view) {
        Ok(ctx) => match tera.render(LOGOUT_TEMPLATE, &ctx) {
            Ok(html) => Html(html).into_response(),
            Err(e) => {
                tracing::warn!(error = %e, template = LOGOUT_TEMPLATE, "logout template render failed");
                Html(render_logout_fallback(view)).into_response()
            }
        },
        Err(e) => {
            tracing::warn!(error = %e, "logout view serialization failed");
            Html(render_logout_fallback(view)).into_response()
        }
    }
}

fn render_login_fallback(view: &LoginView) -> String {
    let error = view
        .error
        .as_deref()
        .map(|msg| format!(r#"<p role="alert">{}</p>"#, html_escape(msg)))
        .unwrap_or_default();

    format!(
        r#"<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>{}</title></head><body><main><h1>{}</h1><p>{}</p>{}<form method="post" action="/login"><label>账号<input name="username" value="{}" required></label><label>密码<input name="password" type="password" required></label><button type="submit">登录</button></form></main></body></html>"#,
        html_escape(&view.page_title),
        html_escape(&view.page_title),
        html_escape(&view.subtitle),
        error,
        html_escape(&view.username),
    )
}

fn render_logout_fallback(view: &LogoutView) -> String {
    if view.is_completed {
        format!(
            r#"<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>{}</title></head><body><main><h1>{}</h1><p>{}</p><a href="{}">{}</a></main></body></html>"#,
            html_escape(&view.page_title),
            html_escape(&view.page_title),
            html_escape(&view.subtitle),
            html_escape(&view.primary_href),
            html_escape(&view.primary_action),
        )
    } else {
        format!(
            r#"<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><title>{}</title></head><body><main><h1>{}</h1><p>{}</p><form method="post" action="/logout"><button type="submit">{}</button></form><a href="{}">取消</a></main></body></html>"#,
            html_escape(&view.page_title),
            html_escape(&view.page_title),
            html_escape(&view.subtitle),
            html_escape(&view.primary_action),
            html_escape(&view.cancel_href),
        )
    }
}

fn html_escape(s: &str) -> String {
    s.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&#39;")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn login_page_escapes_username_and_error() {
        let view = build_login_view(r#"<script>"&"#, Some("账号 <b>错误</b>"), true);
        let html = render_login_fallback(&view);

        assert!(html.contains("&lt;script&gt;&quot;&amp;"));
        assert!(html.contains("账号 &lt;b&gt;错误&lt;/b&gt;"));
        assert!(!html.contains(r#"<script>"&"#));
    }

    #[test]
    fn admin_login_view_uses_admin_identity_class() {
        let view = build_login_view("", None, true);

        assert_eq!(view.body_class, "admin-identity");
        assert_eq!(view.scope, "admin");
    }

    #[test]
    fn tenant_login_view_uses_default_tenant_identity() {
        let view = build_login_view("", None, false);

        assert_eq!(view.body_class, "");
        assert_eq!(view.scope, "tenant");
    }

    #[test]
    fn billing_unavailable_login_error_uses_explicit_message() {
        let (status, message) = login_error_status_and_message(AppError::BillingUnavailable);

        assert_eq!(status, StatusCode::PAYMENT_REQUIRED);
        assert_eq!(message, "预存余额不足，请联系管理员充值");
    }

    #[test]
    fn logout_view_switches_between_confirm_and_completed() {
        let confirm = build_logout_view(LogoutMode::Confirm, false);
        let completed = build_logout_view(LogoutMode::Completed, true);

        assert_eq!(confirm.scope, "tenant");
        assert_eq!(confirm.mode, "confirm");
        assert!(!confirm.is_completed);
        assert_eq!(confirm.cancel_href, TENANT_REDIRECT);
        assert_eq!(completed.scope, "admin");
        assert_eq!(completed.mode, "completed");
        assert!(completed.is_completed);
        assert_eq!(completed.cancel_href, ADMIN_REDIRECT);
    }

    #[test]
    fn logout_fallback_escapes_view_values() {
        let mut view = build_logout_view(LogoutMode::Completed, false);
        view.page_title = r#"<script>"&"#.to_string();
        view.subtitle = "退出 <b>完成</b>".to_string();

        let html = render_logout_fallback(&view);

        assert!(html.contains("&lt;script&gt;&quot;&amp;"));
        assert!(html.contains("退出 &lt;b&gt;完成&lt;/b&gt;"));
        assert!(!html.contains(r#"<script>"&"#));
    }

    #[test]
    fn session_token_reads_named_cookie_only() {
        let mut headers = HeaderMap::new();
        headers.insert(
            header::COOKIE,
            HeaderValue::from_static("xsession=nope; xizhends_session=tok123; other=1"),
        );

        assert_eq!(session_token(&headers).as_deref(), Some("tok123"));
    }

    #[test]
    fn session_token_ignores_empty_cookie() {
        let mut headers = HeaderMap::new();
        headers.insert(
            header::COOKIE,
            HeaderValue::from_static("xizhends_session=; other=1"),
        );

        assert_eq!(session_token(&headers), None);
    }

    #[test]
    fn client_ip_prefers_cloudflare_header() {
        let mut headers = HeaderMap::new();
        headers.insert("x-forwarded-for", HeaderValue::from_static("10.0.0.1"));
        headers.insert("cf-connecting-ip", HeaderValue::from_static("203.0.113.9"));

        assert_eq!(client_ip(&headers).as_deref(), Some("203.0.113.9"));
    }

    #[test]
    fn client_ip_uses_first_forwarded_address() {
        let mut headers = HeaderMap::new();
        headers.insert(
            "x-forwarded-for",
            HeaderValue::from_static("203.0.113.1, 10.0.0.1"),
        );

        assert_eq!(client_ip(&headers).as_deref(), Some("203.0.113.1"));
    }

    #[test]
    fn admin_request_uses_forwarded_host() {
        let mut headers = HeaderMap::new();
        headers.insert("host", HeaderValue::from_static("127.0.0.1"));
        headers.insert(
            "x-forwarded-host",
            HeaderValue::from_static("saas.xizhends.com"),
        );

        assert!(is_admin_request(&headers));
    }
}
