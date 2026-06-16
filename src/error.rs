//! 统一 `AppError` 枚举 + `IntoResponse` 实现（Task 1.3 / Requirements 4.6）。
//!
//! 设计要点：
//! - 所有层（middleware / handler / service / repository / integration）统一向上返回
//!   `Result<_, AppError>`，由本模块集中决定 HTTP 状态码与响应体。
//! - **敏感内部细节（DSN、密码、底层 sqlx 错误、栈）只写入日志，绝不外泄给客户端。**
//!   `Display`（由 `thiserror` 生成）仅用于服务端日志；面向客户端的文案由
//!   [`AppError::client_message`] 提供，且为通用、脱敏的文本。
//! - 普通请求与 HTMX 请求采用不同呈现（见设计 5.6）：
//!   `IntoResponse` 由于拿不到请求头，默认按「整页错误」呈现；当调用方（middleware/handler）
//!   已知请求来自 HTMX 时，应改用 [`AppError::into_response_with`] 并传入 `is_htmx = true`，
//!   以返回错误片段而非整页。推荐在中间件中读取 `HX-Request` 头后据此选择。

use axum::{
    http::StatusCode,
    response::{Html, IntoResponse, Response},
};
use thiserror::Error;

/// 全系统统一错误类型。
///
/// 注意：`#[error(...)]` 文案仅供**服务端日志**使用，可能包含定位用细节；
/// 面向客户端的安全文案统一由 [`AppError::client_message`] 给出。
#[derive(Debug, Error)]
pub enum AppError {
    /// 租户不存在 / 已停用 / 已暂停。
    #[error("tenant unavailable")]
    TenantUnavailable,

    /// 租户预存余额不足，禁止租户侧访问。
    #[error("billing unavailable")]
    BillingUnavailable,

    /// 租户连接池建立失败（DSN 解密失败 / MySQL 不可达 / 超过连接上限等）。
    #[error("tenant pool build failed")]
    PoolBuildFailed,

    /// 未认证（缺少有效会话）。
    #[error("unauthorized")]
    Unauthorized,

    /// 已认证但无权限。
    #[error("forbidden")]
    Forbidden,

    /// 资源不存在。
    #[error("not found")]
    NotFound,

    /// 入参校验失败；`String` 为字段级提示，可安全回显给客户端。
    #[error("validation error: {0}")]
    Validation(String),

    /// 外部 API 调用失败（1688 / RMS / jpship / 佐川 / ShowAPI / IMAP-SMTP 等）。
    /// `detail` 仅用于日志，不外泄。
    #[error("external api error from {provider}: {detail}")]
    ExternalApi { provider: String, detail: String },

    /// 数据库错误；底层 `sqlx::Error` 仅记日志，不外泄。
    #[error("database error: {0}")]
    Db(#[from] sqlx::Error),

    /// 迁移批次失败（对账不符 / 外键缺失 / 解析异常），该批次整体回滚。
    #[error("migration batch failed: {batch_id}")]
    MigrationBatchFailed { batch_id: String },
}

impl AppError {
    /// 映射到 HTTP 状态码。
    pub fn status_code(&self) -> StatusCode {
        match self {
            AppError::TenantUnavailable => StatusCode::SERVICE_UNAVAILABLE,
            AppError::BillingUnavailable => StatusCode::PAYMENT_REQUIRED,
            AppError::PoolBuildFailed => StatusCode::SERVICE_UNAVAILABLE,
            AppError::Unauthorized => StatusCode::UNAUTHORIZED,
            AppError::Forbidden => StatusCode::FORBIDDEN,
            AppError::NotFound => StatusCode::NOT_FOUND,
            AppError::Validation(_) => StatusCode::UNPROCESSABLE_ENTITY,
            AppError::ExternalApi { .. } => StatusCode::BAD_GATEWAY,
            AppError::Db(_) => StatusCode::INTERNAL_SERVER_ERROR,
            AppError::MigrationBatchFailed { .. } => StatusCode::INTERNAL_SERVER_ERROR,
        }
    }

    /// 面向客户端的安全文案（已脱敏，绝不含 DSN / 密码 / 底层错误 / 栈）。
    ///
    /// 仅 `Validation` 会回显调用方提供的字段级提示，因为该提示本就来自入参校验、可安全展示。
    pub fn client_message(&self) -> String {
        match self {
            AppError::TenantUnavailable => "租户不可用或已停用".to_string(),
            AppError::BillingUnavailable => "预存余额不足，请联系管理员充值".to_string(),
            AppError::PoolBuildFailed => "服务暂时不可用，请稍后重试".to_string(),
            AppError::Unauthorized => "请先登录".to_string(),
            AppError::Forbidden => "没有访问权限".to_string(),
            AppError::NotFound => "资源不存在".to_string(),
            AppError::Validation(msg) => msg.clone(),
            AppError::ExternalApi { .. } => "外部服务查询失败，请稍后重试".to_string(),
            AppError::Db(_) => "服务器内部错误".to_string(),
            AppError::MigrationBatchFailed { .. } => "迁移批次处理失败".to_string(),
        }
    }

    /// 将敏感细节写入服务端日志。仅在此处记录底层错误，响应体永不包含这些信息。
    fn log(&self) {
        match self {
            AppError::Db(e) => {
                tracing::error!(error = %e, "AppError::Db");
            }
            AppError::ExternalApi { provider, detail } => {
                tracing::warn!(provider = %provider, detail = %detail, "AppError::ExternalApi");
            }
            AppError::MigrationBatchFailed { batch_id } => {
                tracing::error!(batch_id = %batch_id, "AppError::MigrationBatchFailed");
            }
            AppError::PoolBuildFailed => {
                tracing::error!("AppError::PoolBuildFailed");
            }
            AppError::TenantUnavailable => {
                tracing::warn!("AppError::TenantUnavailable");
            }
            AppError::BillingUnavailable => {
                tracing::warn!("AppError::BillingUnavailable");
            }
            // Unauthorized / Forbidden / NotFound / Validation 属常规业务流，按 debug 记录即可。
            other => {
                tracing::debug!(error = %other, "AppError");
            }
        }
    }

    /// 根据是否为 HTMX 请求选择呈现方式：
    /// - `is_htmx == false`：返回整页错误页面（普通请求）。
    /// - `is_htmx == true`：返回错误片段（局部回填），供 HTMX 就地插入。
    ///
    /// 调用方（通常是中间件）应读取请求的 `HX-Request` 头来决定该参数。
    pub fn into_response_with(self, is_htmx: bool) -> Response {
        self.log();
        let status = self.status_code();
        let msg = self.client_message();
        if is_htmx {
            (status, Html(render_fragment(&msg))).into_response()
        } else {
            (status, Html(render_page(status, &msg))).into_response()
        }
    }
}

/// 默认 `IntoResponse`：当无法获知请求来源时，按整页错误呈现。
///
/// 处理 HTMX 请求时应优先调用 [`AppError::into_response_with`]，以返回错误片段。
impl IntoResponse for AppError {
    fn into_response(self) -> Response {
        self.into_response_with(false)
    }
}

/// HTMX 错误包装器：当调用方明确知道当前请求来自 HTMX 时，
/// 可返回 `Result<_, HtmxError>`，其 `IntoResponse` 自动渲染错误片段。
#[derive(Debug)]
pub struct HtmxError(pub AppError);

impl From<AppError> for HtmxError {
    fn from(e: AppError) -> Self {
        HtmxError(e)
    }
}

impl IntoResponse for HtmxError {
    fn into_response(self) -> Response {
        self.0.into_response_with(true)
    }
}

/// 渲染整页错误（普通请求）。仅含脱敏文案。
fn render_page(status: StatusCode, message: &str) -> String {
    let code = status.as_u16();
    let escaped = html_escape(message);
    format!(
        "<!DOCTYPE html>\n<html lang=\"zh-CN\">\n<head>\n<meta charset=\"utf-8\">\n\
<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n\
<title>出错了 · {code}</title>\n</head>\n<body>\n\
<main style=\"max-width:480px;margin:80px auto;font-family:system-ui,sans-serif;text-align:center;\">\n\
<h1 style=\"font-size:48px;margin:0;color:#b91c1c;\">{code}</h1>\n\
<p style=\"font-size:16px;color:#374151;\">{escaped}</p>\n\
</main>\n</body>\n</html>\n"
    )
}

/// 渲染错误片段（HTMX 请求局部回填）。仅含脱敏文案。
fn render_fragment(message: &str) -> String {
    let escaped = html_escape(message);
    format!("<div class=\"app-error\" role=\"alert\" data-error=\"1\">{escaped}</div>")
}

/// 最小 HTML 转义，避免脱敏文案中的特殊字符破坏页面结构。
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
    fn status_codes_are_mapped_per_variant() {
        assert_eq!(
            AppError::TenantUnavailable.status_code(),
            StatusCode::SERVICE_UNAVAILABLE
        );
        assert_eq!(
            AppError::BillingUnavailable.status_code(),
            StatusCode::PAYMENT_REQUIRED
        );
        assert_eq!(
            AppError::PoolBuildFailed.status_code(),
            StatusCode::SERVICE_UNAVAILABLE
        );
        assert_eq!(
            AppError::Unauthorized.status_code(),
            StatusCode::UNAUTHORIZED
        );
        assert_eq!(AppError::Forbidden.status_code(), StatusCode::FORBIDDEN);
        assert_eq!(AppError::NotFound.status_code(), StatusCode::NOT_FOUND);
        assert_eq!(
            AppError::Validation("金额非法".into()).status_code(),
            StatusCode::UNPROCESSABLE_ENTITY
        );
        assert_eq!(
            AppError::ExternalApi {
                provider: "1688".into(),
                detail: "timeout".into()
            }
            .status_code(),
            StatusCode::BAD_GATEWAY
        );
        assert_eq!(
            AppError::MigrationBatchFailed {
                batch_id: "2021-batch-3".into()
            }
            .status_code(),
            StatusCode::INTERNAL_SERVER_ERROR
        );
    }

    #[test]
    fn validation_message_is_echoed_to_client() {
        let err = AppError::Validation("金额必须为正数".into());
        assert_eq!(err.client_message(), "金额必须为正数");
    }

    #[test]
    fn billing_unavailable_uses_explicit_client_message() {
        let err = AppError::BillingUnavailable;
        assert_eq!(err.status_code(), StatusCode::PAYMENT_REQUIRED);
        assert_eq!(err.client_message(), "预存余额不足，请联系管理员充值");
    }

    #[test]
    fn external_api_detail_is_not_leaked_to_client() {
        let err = AppError::ExternalApi {
            provider: "rms".into(),
            detail: "secret-token=abc123 failed".into(),
        };
        let msg = err.client_message();
        assert!(!msg.contains("secret-token"));
        assert!(!msg.contains("abc123"));
    }

    #[test]
    fn db_error_detail_is_not_leaked_to_client() {
        let err = AppError::Db(sqlx::Error::RowNotFound);
        let msg = err.client_message();
        assert_eq!(msg, "服务器内部错误");
        assert!(!msg.to_lowercase().contains("row"));
    }

    #[test]
    fn migration_batch_id_is_not_leaked_to_client() {
        let err = AppError::MigrationBatchFailed {
            batch_id: "tenant-7-2021".into(),
        };
        assert!(!err.client_message().contains("tenant-7-2021"));
    }

    #[test]
    fn html_escape_neutralizes_markup() {
        let escaped = html_escape("<script>alert('x')</script>");
        assert!(!escaped.contains("<script>"));
        assert!(escaped.contains("&lt;script&gt;"));
    }

    #[test]
    fn fragment_and_page_render_distinctly() {
        let fragment = render_fragment("失败");
        let page = render_page(StatusCode::NOT_FOUND, "资源不存在");
        assert!(fragment.contains("app-error"));
        assert!(!fragment.contains("<!DOCTYPE html>"));
        assert!(page.contains("<!DOCTYPE html>"));
        assert!(page.contains("404"));
    }

    #[test]
    fn from_sqlx_error_constructs_db_variant() {
        let err: AppError = sqlx::Error::RowNotFound.into();
        assert!(matches!(err, AppError::Db(_)));
    }
}
