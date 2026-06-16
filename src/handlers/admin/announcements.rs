//! 超管后台「系统公告」处理器（Task 11.4 / Requirements 10.3）。
//!
//! 提供两类操作，全部落主库 `announcements`（见 design.md 超管后台「系统公告」与迁移 0006）：
//! - 发布公告：发布全局 / 指定租户公告（标题 / 类型 / 可见范围 / 内容），写主库公告表。
//! - 已发布列表：按时间倒序返回已发布公告（最新在前）。
//!
//! 设计取舍：DB 访问下沉到 [`crate::repository::announcement_repo`]（仅访问主库），handler 仅做
//! 入参校验、调用仓储、装配响应。当前模板尚未落地，故发布操作返回结构化 JSON 结果，
//! 列表返回 JSON 数据；待身份界面模板补全后可切换为 Tera 渲染。所有错误经统一
//! [`AppError`] 呈现，绝不外泄底层细节。

use axum::{
    extract::State,
    response::{Html, IntoResponse, Json, Redirect, Response},
    routing::get,
    Form, Router,
};
use serde::{Deserialize, Serialize};
use tera::Context;

use crate::error::AppError;
use crate::repository::{announcement_repo, tenant_repo};
use crate::state::AppState;

const ANNOUNCEMENTS_TEMPLATE: &str = "admin/announcements.html";

/// 发布公告表单（来自超管「系统公告」发布对话框）。
///
/// `kind` 省略时由本层回退为 `info`（与迁移 0006 列默认值一致）。
/// `scope = "tenant"` 时必须提供 `tenant_id`；`scope = "global"` 时 `tenant_id` 被忽略。
#[derive(Debug, Deserialize)]
pub struct CreateAnnouncementForm {
    pub title: String,
    #[serde(default)]
    pub kind: Option<String>,
    pub scope: String,
    #[serde(default)]
    pub tenant_id: Option<i64>,
    pub content: String,
}

/// 发布公告的结构化结果。
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct AnnouncementActionResponse {
    pub id: i64,
    /// 落库后的可见范围（`global` / `tenant`）。
    pub scope: String,
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

/// 归一类型取值：`None` 或纯空白 → 默认 `info`；否则取去空白后的值。
fn normalize_kind(kind: Option<String>) -> String {
    match kind {
        Some(k) if !k.trim().is_empty() => k.trim().to_string(),
        _ => "info".to_string(),
    }
}

/// 组装超管后台系统公告的路由（前缀 `/admin/announcements`）。
///
/// - `GET  /admin/announcements` → 已发布列表（最新在前）
/// - `POST /admin/announcements` → 发布公告（全局 / 指定租户）
pub fn routes() -> Router<AppState> {
    Router::new().route(
        "/admin/announcements",
        get(list_announcements).post(create_announcement),
    )
}

/// 已发布公告列表（Requirements 10.3）。返回时间倒序的公告数据。
pub async fn list_announcements(State(state): State<AppState>) -> Result<Response, AppError> {
    let announcements = announcement_repo::list_announcements(state.master_pool()).await?;
    let tenants = tenant_repo::list_tenants(state.master_pool()).await?;
    let mut ctx = Context::new();
    ctx.insert("announcements", &announcements);
    ctx.insert("tenants", &tenants);
    match state.tera().render(ANNOUNCEMENTS_TEMPLATE, &ctx) {
        Ok(html) => Ok(Html(html).into_response()),
        Err(e) => {
            tracing::warn!(error = %e, template = ANNOUNCEMENTS_TEMPLATE, "公告模板渲染失败，回退 JSON");
            Ok(Json(announcements).into_response())
        }
    }
}

/// 发布公告：写主库 `announcements`（Requirements 10.3）。
///
/// 校验标题 / 内容非空，并由仓储层 [`announcement_repo::validate_scope`] 校验可见范围与
/// 租户组合（`scope=tenant` 必须带 `tenant_id`）。`scope=global` 时 `tenant_id` 强制落 `NULL`。
pub async fn create_announcement(
    State(state): State<AppState>,
    Form(form): Form<CreateAnnouncementForm>,
) -> Result<Redirect, AppError> {
    require_nonempty("标题", &form.title)?;
    require_nonempty("内容", &form.content)?;

    let kind = normalize_kind(form.kind);

    let id = announcement_repo::create_announcement(
        state.master_pool(),
        form.title.trim(),
        &kind,
        &form.scope,
        form.tenant_id,
        &form.content,
    )
    .await?;

    tracing::info!(announcement_id = id, scope = %form.scope, "公告已发布");
    Ok(Redirect::to("/admin/announcements"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn require_nonempty_rejects_blank_and_whitespace() {
        assert!(require_nonempty("标题", "").is_err());
        assert!(require_nonempty("标题", "   ").is_err());
        assert!(require_nonempty("标题", "\t\n").is_err());
        assert!(require_nonempty("标题", "维护通知").is_ok());
    }

    #[test]
    fn require_nonempty_error_names_the_field() {
        let err = require_nonempty("内容", "").unwrap_err();
        assert!(matches!(&err, AppError::Validation(_)));
        assert!(err.client_message().contains("内容"));
    }

    #[test]
    fn normalize_kind_defaults_to_info_when_missing_or_blank() {
        assert_eq!(normalize_kind(None), "info");
        assert_eq!(normalize_kind(Some("".into())), "info");
        assert_eq!(normalize_kind(Some("   ".into())), "info");
        assert_eq!(normalize_kind(Some(" warning ".into())), "warning");
        assert_eq!(normalize_kind(Some("maintenance".into())), "maintenance");
    }

    #[test]
    fn action_response_serializes_to_expected_json() {
        let resp = AnnouncementActionResponse {
            id: 7,
            scope: "tenant".into(),
            message: "公告已发布".into(),
        };
        let json = serde_json::to_value(&resp).unwrap();
        assert_eq!(json["id"], 7);
        assert_eq!(json["scope"], "tenant");
        assert_eq!(json["message"], "公告已发布");
    }
}
