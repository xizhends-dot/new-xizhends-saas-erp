//! 客服邮件中心处理器（Task 15.2 / Requirements 11.5）。
//!
//! 薄 handler：解析请求参数（账户 / 文件夹 / 邮件 ID / 回复正文），调用注入的
//! [`MailGateway`]（IMAP 拉取 + SMTP 发信），以 JSON 返回结果。四类能力一一对应
//! `kefu_mail/` 前端：
//! - **文件夹列表**（`GET /mail/folders`）  → [`MailGateway::list_folders`]
//! - **邮件列表/增量同步**（`POST /mail/list`） → [`MailGateway::sync_folder`]（`last_uid` 游标，只拉头）
//! - **正文懒加载**（`GET /mail/body`）      → [`MailGateway::load_body`]（命中缓存不触网）
//! - **回复发送**（`POST /mail/reply`）       → [`MailGateway::reply`]（SMTP 发送 + IMAP APPEND Sent）
//!
//! ## 权限门禁（Requirements 4.2 / 11.5）
//! 邮件中心受 [`FeatureModule::KefuMail`] 功能开关保护。主体（[`Principal`]）由上游会话
//! 中间件注入请求扩展：
//! - 主体缺失（未认证）        → [`AppError::Unauthorized`]（401）。
//! - 主体存在但无 `kefu_mail` 权限 → [`AppError::Forbidden`]（403）。
//! - 主体存在且 `can_access(KefuMail)` 为真 → 放行。
//!
//! 判定逻辑收敛到纯函数 [`ensure_mail_access`]，便于单测。除 handler 内门禁外，路由
//! 也可在 Task 18.1 wiring 时叠加
//! [`require_feature(FeatureModule::KefuMail)`](crate::middleware::permission::require_feature)
//! 中间件做页面级守卫，二者语义一致、互为补强。
//!
//! ## 网关注入边界（Task 18 wiring）
//! [`MailGateway`] 的具体实现
//! [`MailGatewayImpl`](crate::integrations::mail::MailGatewayImpl) 需要真实 IMAP/SMTP/存储
//! 传输与凭证配置，这些尚未并入 [`AppState`]。为保持 handler 与传输实现解耦，本模块通过
//! **请求扩展**注入一个 `Arc<dyn MailGateway>`（[`SharedMailGateway`]）：
//! handler 用 `Extension<SharedMailGateway>` 提取。
//!
//! Task 18.1 装配路由时，构造好具体网关后以
//! `routes().layer(axum::Extension(gateway as SharedMailGateway))` 注入即可
//! （亦可在更外层统一 `.layer(Extension(...))`）。在该扩展注入前，相关路由不应对外开放。
//!
//! _Requirements: 11.5_

use std::sync::Arc;

use axum::{
    extract::{Extension, Query},
    response::Json,
    routing::{get, post},
    Form, Router,
};
use serde::{Deserialize, Serialize};

use crate::error::AppError;
use crate::integrations::traits::{MailAccount, MailFolder, MailGateway, ReplyResult, SyncReport};
use crate::models::user::{FeatureModule, Principal};
use crate::state::AppState;

/// 注入边界：共享的邮件网关句柄（`Arc<dyn MailGateway>`）。
///
/// 由 Task 18.1 wiring 时经请求扩展注入（见模块级文档「网关注入边界」）。`MailGateway`
/// 自带 `Send + Sync` 约束，因此该别名满足 axum `Extension` 对 `Clone + Send + Sync + 'static`
/// 的要求，可在 handler 间廉价克隆。
pub type SharedMailGateway = Arc<dyn MailGateway>;

// 路由路径常量（集中管理，便于与前端 `hx-*` / fetch 保持一致）。
const FOLDERS_PATH: &str = "/mail/folders";
const LIST_PATH: &str = "/mail/list";
const BODY_PATH: &str = "/mail/body";
const REPLY_PATH: &str = "/mail/reply";

/// 增量同步默认拉取上限（单次最多处理的邮件头数量）。
const DEFAULT_SYNC_LIMIT: u32 = 50;
/// 增量同步硬上限：防止前端传入超大 `limit` 拖垮 IMAP / 内存。
const MAX_SYNC_LIMIT: u32 = 500;

// ============================================================================
// 纯逻辑：权限门禁 / 参数校验 / 归一（无 I/O，可独立单测）
// ============================================================================

/// 邮件中心访问门禁（纯函数）。
///
/// 后置条件（Requirements 4.2 / 11.5）：
/// - 主体缺失 → [`AppError::Unauthorized`]。
/// - 主体存在但 `can_access(KefuMail)` 为假 → [`AppError::Forbidden`]。
/// - 主体存在且拥有 `kefu_mail` 权限 → `Ok(())`。
fn ensure_mail_access(principal: Option<&Principal>) -> Result<(), AppError> {
    match principal {
        None => Err(AppError::Unauthorized),
        Some(p) if p.can_access(FeatureModule::KefuMail) => Ok(()),
        Some(_) => Err(AppError::Forbidden),
    }
}

/// 校验邮件主键（纯函数）：必须为正整数，否则 [`AppError::Validation`]（可安全回显）。
fn validate_msg_id(raw: i64) -> Result<i64, AppError> {
    if raw > 0 {
        Ok(raw)
    } else {
        Err(AppError::Validation("邮件 ID 非法".to_string()))
    }
}

/// 归一同步上限（纯函数）：缺省 / 0 回退 [`DEFAULT_SYNC_LIMIT`]，超过 [`MAX_SYNC_LIMIT`] 截断。
fn clamp_limit(requested: Option<u32>) -> u32 {
    match requested {
        None | Some(0) => DEFAULT_SYNC_LIMIT,
        Some(n) if n > MAX_SYNC_LIMIT => MAX_SYNC_LIMIT,
        Some(n) => n,
    }
}

// ============================================================================
// 请求 / 响应 DTO
// ============================================================================

/// 账户参数（`GET /mail/folders` 的查询串）——映射为 [`MailAccount`]。
#[derive(Debug, Clone, Deserialize)]
pub struct AccountQuery {
    /// 账户标识（与 `--account` 一致）。
    pub account: String,
    /// 登录邮箱地址。
    pub email: String,
    /// IMAP 主机。
    pub imap_host: String,
    /// SMTP 主机。
    pub smtp_host: String,
}

impl AccountQuery {
    /// 转为网关所需的 [`MailAccount`]（去除首尾空白）。
    fn to_account(&self) -> MailAccount {
        MailAccount {
            account: self.account.trim().to_string(),
            email: self.email.trim().to_string(),
            imap_host: self.imap_host.trim().to_string(),
            smtp_host: self.smtp_host.trim().to_string(),
        }
    }
}

/// 文件夹列表响应。
#[derive(Debug, Clone, Serialize)]
pub struct FoldersResponse {
    /// 账户下的全部文件夹（含各自 `last_uid` 游标）。
    pub folders: Vec<MailFolder>,
}

/// 邮件列表 / 增量同步表单（`POST /mail/list`）。
#[derive(Debug, Clone, Deserialize)]
pub struct MailListForm {
    /// 文件夹名称（如 `INBOX`、`Sent`）。
    pub name: String,
    /// 已同步到的 UID 游标（增量同步起点）；缺省按首次同步（0）处理。
    #[serde(default)]
    pub last_uid: u32,
    /// 单次拉取上限；缺省回退 [`DEFAULT_SYNC_LIMIT`]，上限 [`MAX_SYNC_LIMIT`]。
    #[serde(default)]
    pub limit: Option<u32>,
}

/// 正文懒加载查询（`GET /mail/body`）。
#[derive(Debug, Clone, Deserialize)]
pub struct BodyQuery {
    /// 邮件主键（`ph_mail_message.id`）。
    pub msg_id: i64,
}

/// 正文响应。
#[derive(Debug, Clone, Serialize)]
pub struct BodyResponse {
    /// 邮件主键。
    pub msg_id: i64,
    /// 正文（纯文本；命中缓存或实时拉取后缓存回库）。
    pub body: String,
}

/// 回复发送表单（`POST /mail/reply`）。
#[derive(Debug, Clone, Deserialize)]
pub struct ReplyForm {
    /// 被回复邮件的主键。
    pub msg_id: i64,
    /// 回复正文。
    pub body: String,
}

// ============================================================================
// axum handlers（薄：门禁 → 解析 → 调网关 → JSON 回填）
// ============================================================================

/// 文件夹列表（`GET /mail/folders`）：列出账户下所有 IMAP 文件夹。
async fn folders_handler(
    principal: Option<Extension<Principal>>,
    Extension(gateway): Extension<SharedMailGateway>,
    Query(query): Query<AccountQuery>,
) -> Result<Json<FoldersResponse>, AppError> {
    ensure_mail_access(principal.as_ref().map(|Extension(p)| p))?;

    let account = query.to_account();
    let folders = gateway.list_folders(&account).await?;
    Ok(Json(FoldersResponse { folders }))
}

/// 邮件列表 / 增量同步（`POST /mail/list`）：以 `last_uid` 游标增量只拉邮件头，
/// 返回本次新增数量与前进后的游标（[`SyncReport`]）。
async fn list_handler(
    principal: Option<Extension<Principal>>,
    Extension(gateway): Extension<SharedMailGateway>,
    Form(form): Form<MailListForm>,
) -> Result<Json<SyncReport>, AppError> {
    ensure_mail_access(principal.as_ref().map(|Extension(p)| p))?;

    let name = form.name.trim();
    if name.is_empty() {
        return Err(AppError::Validation("文件夹名称不能为空".to_string()));
    }
    let folder = MailFolder {
        name: name.to_string(),
        last_uid: form.last_uid,
    };
    let limit = clamp_limit(form.limit);

    let report = gateway.sync_folder(&folder, limit).await?;
    Ok(Json(report))
}

/// 正文懒加载（`GET /mail/body`）：按 `msg_id` 取正文；未缓存时实时拉取并写回库。
async fn body_handler(
    principal: Option<Extension<Principal>>,
    Extension(gateway): Extension<SharedMailGateway>,
    Query(query): Query<BodyQuery>,
) -> Result<Json<BodyResponse>, AppError> {
    ensure_mail_access(principal.as_ref().map(|Extension(p)| p))?;

    let msg_id = validate_msg_id(query.msg_id)?;
    let body = gateway.load_body(msg_id).await?;
    Ok(Json(BodyResponse { msg_id, body }))
}

/// 回复发送（`POST /mail/reply`）：SMTP 发送 + IMAP APPEND 写回 Sent，返回 [`ReplyResult`]。
async fn reply_handler(
    principal: Option<Extension<Principal>>,
    Extension(gateway): Extension<SharedMailGateway>,
    Form(form): Form<ReplyForm>,
) -> Result<Json<ReplyResult>, AppError> {
    ensure_mail_access(principal.as_ref().map(|Extension(p)| p))?;

    let msg_id = validate_msg_id(form.msg_id)?;
    let body = form.body.trim();
    if body.is_empty() {
        return Err(AppError::Validation("回复内容不能为空".to_string()));
    }

    let result = gateway.reply(msg_id, body).await?;
    Ok(Json(result))
}

/// 组装客服邮件中心路由。
///
/// - `GET  /mail/folders` → 文件夹列表
/// - `POST /mail/list`    → 邮件列表 / 增量同步（last_uid 游标）
/// - `GET  /mail/body`    → 正文懒加载
/// - `POST /mail/reply`   → 回复发送
///
/// 注意：handler 依赖请求扩展中的 [`SharedMailGateway`]（与会话注入的 [`Principal`]）。
/// Task 18.1 wiring 时须以 `.layer(axum::Extension(gateway))` 注入具体网关，并置于
/// 「租户识别 → 会话认证 → 权限校验」中间件栈之后（见模块级文档）。
pub fn routes() -> Router<AppState> {
    Router::new()
        .route(FOLDERS_PATH, get(folders_handler))
        .route(LIST_PATH, post(list_handler))
        .route(BODY_PATH, get(body_handler))
        .route(REPLY_PATH, post(reply_handler))
}

// ============================================================================
// 单元测试
// ============================================================================

#[cfg(test)]
mod tests {
    use super::*;
    use crate::models::user::{Role, StoreScope, TenantId};
    use std::collections::HashMap;

    // ---- 权限门禁（纯逻辑）---------------------------------------------------

    fn employee(role: Role, overrides: HashMap<FeatureModule, bool>) -> Principal {
        Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 42,
            role,
            overrides,
            store_scope: StoreScope::All,
        }
    }

    #[test]
    fn ensure_access_denies_when_principal_missing() {
        assert!(matches!(
            ensure_mail_access(None),
            Err(AppError::Unauthorized)
        ));
    }

    #[test]
    fn ensure_access_allows_super_admin_and_company_admin() {
        assert!(ensure_mail_access(Some(&Principal::SuperAdmin)).is_ok());
        let admin = Principal::CompanyAdmin {
            tenant_id: TenantId(7),
        };
        assert!(ensure_mail_access(Some(&admin)).is_ok());
    }

    #[test]
    fn ensure_access_allows_service_staff_by_role_default() {
        // 客服默认开放 kefu_mail。
        let p = employee(Role::ServiceStaff, HashMap::new());
        assert!(ensure_mail_access(Some(&p)).is_ok());
    }

    #[test]
    fn ensure_access_forbids_item_checker_by_role_default() {
        // 品检默认关闭 kefu_mail → Forbidden。
        let p = employee(Role::ItemChecker, HashMap::new());
        assert!(matches!(
            ensure_mail_access(Some(&p)),
            Err(AppError::Forbidden)
        ));
    }

    #[test]
    fn ensure_access_respects_explicit_override() {
        // 显式关闭客服的 kefu_mail → Forbidden。
        let mut overrides = HashMap::new();
        overrides.insert(FeatureModule::KefuMail, false);
        let p = employee(Role::ServiceStaff, overrides);
        assert!(matches!(
            ensure_mail_access(Some(&p)),
            Err(AppError::Forbidden)
        ));

        // 显式开启品检的 kefu_mail → 放行。
        let mut overrides = HashMap::new();
        overrides.insert(FeatureModule::KefuMail, true);
        let p = employee(Role::ItemChecker, overrides);
        assert!(ensure_mail_access(Some(&p)).is_ok());
    }

    // ---- msg_id 校验 ---------------------------------------------------------

    #[test]
    fn validate_msg_id_accepts_positive() {
        assert_eq!(validate_msg_id(1).unwrap(), 1);
        assert_eq!(validate_msg_id(987654321).unwrap(), 987654321);
    }

    #[test]
    fn validate_msg_id_rejects_zero_and_negative() {
        assert!(matches!(validate_msg_id(0), Err(AppError::Validation(_))));
        assert!(matches!(validate_msg_id(-5), Err(AppError::Validation(_))));
    }

    // ---- limit 归一 ----------------------------------------------------------

    #[test]
    fn clamp_limit_defaults_when_missing_or_zero() {
        assert_eq!(clamp_limit(None), DEFAULT_SYNC_LIMIT);
        assert_eq!(clamp_limit(Some(0)), DEFAULT_SYNC_LIMIT);
    }

    #[test]
    fn clamp_limit_passes_through_in_range() {
        assert_eq!(clamp_limit(Some(1)), 1);
        assert_eq!(clamp_limit(Some(100)), 100);
        assert_eq!(clamp_limit(Some(MAX_SYNC_LIMIT)), MAX_SYNC_LIMIT);
    }

    #[test]
    fn clamp_limit_caps_above_max() {
        assert_eq!(clamp_limit(Some(MAX_SYNC_LIMIT + 1)), MAX_SYNC_LIMIT);
        assert_eq!(clamp_limit(Some(u32::MAX)), MAX_SYNC_LIMIT);
    }

    // ---- DTO (反)序列化 ------------------------------------------------------

    #[test]
    fn account_query_deserializes_fields() {
        let q: AccountQuery = serde_json::from_str(
            r#"{"account":"1","email":"kefu@xizhen.jp","imap_host":"imap.example.com","smtp_host":"smtp.example.com"}"#,
        )
        .expect("应能反序列化");
        let acc = q.to_account();
        assert_eq!(acc.account, "1");
        assert_eq!(acc.email, "kefu@xizhen.jp");
        assert_eq!(acc.imap_host, "imap.example.com");
        assert_eq!(acc.smtp_host, "smtp.example.com");
    }

    #[test]
    fn account_query_to_account_trims_whitespace() {
        let q = AccountQuery {
            account: " 1 ".to_string(),
            email: " a@b.com ".to_string(),
            imap_host: " imap ".to_string(),
            smtp_host: " smtp ".to_string(),
        };
        let acc = q.to_account();
        assert_eq!(acc.account, "1");
        assert_eq!(acc.email, "a@b.com");
        assert_eq!(acc.imap_host, "imap");
        assert_eq!(acc.smtp_host, "smtp");
    }

    #[test]
    fn mail_list_form_defaults_last_uid_and_limit() {
        let f: MailListForm =
            serde_json::from_str(r#"{"name":"INBOX"}"#).expect("仅 name 也应可反序列化");
        assert_eq!(f.name, "INBOX");
        assert_eq!(f.last_uid, 0, "last_uid 缺省应为 0（首次同步）");
        assert_eq!(f.limit, None, "limit 缺省应为 None");
    }

    #[test]
    fn mail_list_form_parses_all_fields() {
        let f: MailListForm = serde_json::from_str(r#"{"name":"Sent","last_uid":42,"limit":10}"#)
            .expect("应解析全部字段");
        assert_eq!(f.name, "Sent");
        assert_eq!(f.last_uid, 42);
        assert_eq!(f.limit, Some(10));
    }

    #[test]
    fn body_query_parses_msg_id() {
        let q: BodyQuery = serde_json::from_str(r#"{"msg_id":99}"#).expect("应解析 msg_id");
        assert_eq!(q.msg_id, 99);
    }

    #[test]
    fn reply_form_parses_fields() {
        let f: ReplyForm =
            serde_json::from_str(r#"{"msg_id":7,"body":"hello world"}"#).expect("应解析回复表单");
        assert_eq!(f.msg_id, 7);
        assert_eq!(f.body, "hello world");
    }

    #[test]
    fn folders_response_serializes_to_json() {
        let resp = FoldersResponse {
            folders: vec![
                MailFolder {
                    name: "INBOX".to_string(),
                    last_uid: 10,
                },
                MailFolder {
                    name: "Sent".to_string(),
                    last_uid: 3,
                },
            ],
        };
        let json = serde_json::to_value(&resp).unwrap();
        assert_eq!(json["folders"][0]["name"], "INBOX");
        assert_eq!(json["folders"][0]["last_uid"], 10);
        assert_eq!(json["folders"][1]["name"], "Sent");
    }

    #[test]
    fn body_response_serializes_to_json() {
        let resp = BodyResponse {
            msg_id: 42,
            body: "您好".to_string(),
        };
        let json = serde_json::to_value(&resp).unwrap();
        assert_eq!(json["msg_id"], 42);
        assert_eq!(json["body"], "您好");
    }

    // ---- handler 逻辑（注入内存 Mock 网关）-----------------------------------

    use crate::integrations::traits::MailGateway;
    use async_trait::async_trait;
    use std::sync::Mutex;

    /// 记录调用并返回固定数据的内存网关 Mock。
    #[derive(Default)]
    struct MockGateway {
        reply_calls: Mutex<Vec<(i64, String)>>,
        body_calls: Mutex<Vec<i64>>,
    }

    #[async_trait]
    impl MailGateway for MockGateway {
        async fn list_folders(&self, _account: &MailAccount) -> Result<Vec<MailFolder>, AppError> {
            Ok(vec![MailFolder {
                name: "INBOX".to_string(),
                last_uid: 5,
            }])
        }

        async fn sync_folder(
            &self,
            folder: &MailFolder,
            limit: u32,
        ) -> Result<SyncReport, AppError> {
            // 回显 limit 便于断言归一逻辑生效。
            Ok(SyncReport {
                new_count: limit,
                last_uid: folder.last_uid + 1,
            })
        }

        async fn load_body(&self, msg_id: i64) -> Result<String, AppError> {
            self.body_calls.lock().unwrap().push(msg_id);
            Ok(format!("body-{msg_id}"))
        }

        async fn reply(&self, msg_id: i64, body: &str) -> Result<ReplyResult, AppError> {
            self.reply_calls
                .lock()
                .unwrap()
                .push((msg_id, body.to_string()));
            Ok(ReplyResult {
                sent: true,
                appended_to_sent: true,
                message_id: Some("<mock@id>".to_string()),
            })
        }
    }

    fn admin_ext() -> Option<Extension<Principal>> {
        Some(Extension(Principal::CompanyAdmin {
            tenant_id: TenantId(1),
        }))
    }

    fn gateway() -> (Arc<MockGateway>, SharedMailGateway) {
        let mock = Arc::new(MockGateway::default());
        let shared: SharedMailGateway = mock.clone();
        (mock, shared)
    }

    #[tokio::test]
    async fn folders_handler_returns_folders_for_authorized() {
        let (_mock, shared) = gateway();
        let q = AccountQuery {
            account: "1".into(),
            email: "a@b.com".into(),
            imap_host: "imap".into(),
            smtp_host: "smtp".into(),
        };
        let Json(resp) = folders_handler(admin_ext(), Extension(shared), Query(q))
            .await
            .expect("授权主体应成功");
        assert_eq!(resp.folders.len(), 1);
        assert_eq!(resp.folders[0].name, "INBOX");
    }

    #[tokio::test]
    async fn folders_handler_forbidden_without_permission() {
        let (_mock, shared) = gateway();
        let denied = Some(Extension(employee(Role::ItemChecker, HashMap::new())));
        let q = AccountQuery {
            account: "1".into(),
            email: "a@b.com".into(),
            imap_host: "imap".into(),
            smtp_host: "smtp".into(),
        };
        let err = folders_handler(denied, Extension(shared), Query(q))
            .await
            .expect_err("无权限应拒绝");
        assert!(matches!(err, AppError::Forbidden));
    }

    #[tokio::test]
    async fn folders_handler_unauthorized_without_principal() {
        let (_mock, shared) = gateway();
        let q = AccountQuery {
            account: "1".into(),
            email: "a@b.com".into(),
            imap_host: "imap".into(),
            smtp_host: "smtp".into(),
        };
        let err = folders_handler(None, Extension(shared), Query(q))
            .await
            .expect_err("缺主体应未认证");
        assert!(matches!(err, AppError::Unauthorized));
    }

    #[tokio::test]
    async fn list_handler_applies_default_limit_and_advances_cursor() {
        let (_mock, shared) = gateway();
        let form = MailListForm {
            name: "INBOX".into(),
            last_uid: 7,
            limit: None,
        };
        let Json(report) = list_handler(admin_ext(), Extension(shared), Form(form))
            .await
            .expect("应成功");
        assert_eq!(report.new_count, DEFAULT_SYNC_LIMIT, "缺省 limit 应被归一");
        assert_eq!(report.last_uid, 8);
    }

    #[tokio::test]
    async fn list_handler_rejects_blank_folder_name() {
        let (_mock, shared) = gateway();
        let form = MailListForm {
            name: "   ".into(),
            last_uid: 0,
            limit: None,
        };
        let err = list_handler(admin_ext(), Extension(shared), Form(form))
            .await
            .expect_err("空文件夹名应拒绝");
        assert!(matches!(err, AppError::Validation(_)));
    }

    #[tokio::test]
    async fn body_handler_loads_body_and_validates_id() {
        let (mock, shared) = gateway();
        let Json(resp) = body_handler(
            admin_ext(),
            Extension(shared.clone()),
            Query(BodyQuery { msg_id: 42 }),
        )
        .await
        .expect("应成功");
        assert_eq!(resp.msg_id, 42);
        assert_eq!(resp.body, "body-42");
        assert_eq!(*mock.body_calls.lock().unwrap(), vec![42]);

        // 非法 msg_id 在调用网关前即被拒绝。
        let err = body_handler(
            admin_ext(),
            Extension(shared),
            Query(BodyQuery { msg_id: 0 }),
        )
        .await
        .expect_err("msg_id=0 应拒绝");
        assert!(matches!(err, AppError::Validation(_)));
    }

    #[tokio::test]
    async fn reply_handler_sends_and_trims_body() {
        let (mock, shared) = gateway();
        let form = ReplyForm {
            msg_id: 7,
            body: "  您好，已处理  ".into(),
        };
        let Json(result) = reply_handler(admin_ext(), Extension(shared), Form(form))
            .await
            .expect("应成功");
        assert!(result.sent);
        assert!(result.appended_to_sent);
        let calls = mock.reply_calls.lock().unwrap();
        assert_eq!(calls.len(), 1);
        assert_eq!(calls[0].0, 7);
        assert_eq!(calls[0].1, "您好，已处理", "正文应去除首尾空白");
    }

    #[tokio::test]
    async fn reply_handler_rejects_empty_body() {
        let (_mock, shared) = gateway();
        let form = ReplyForm {
            msg_id: 7,
            body: "   ".into(),
        };
        let err = reply_handler(admin_ext(), Extension(shared), Form(form))
            .await
            .expect_err("空正文应拒绝");
        assert!(matches!(err, AppError::Validation(_)));
    }

    #[test]
    fn routes_registers_all_four_endpoints() {
        // 构造路由不应 panic；具体路径正确性由各 handler 单测覆盖。
        let _router: Router<AppState> = routes();
    }
}
