//! 会话校验中间件（Session_Middleware）：查 `sessions` 表，加载 `Principal` 注入上下文
//! （Task 5.5 / Requirements 2.6、2.7）。
//!
//! 职责（design.md 2.2 请求生命周期「SessionMiddleware」环节）：
//! 1. 从请求 `Cookie` 头读取会话 token（Cookie 名见 [`SESSION_COOKIE_NAME`]）。
//! 2. 用 [`session_repo::find_valid_by_token`] 在**主库**查询**未过期**会话；命中后用
//!    [`session_repo::touch_last_seen`] 滑动续期 `last_seen_at`（Requirements 2.6）。
//! 3. 据会话 `principal_kind`（+ `tenant_id`）装配 [`Principal`] 并注入请求扩展，供下游
//!    权限中间件 / handler 通过 `Extension<Principal>` 提取。
//! 4. token 无效 / 已过期 / 已吊销（查询返回 `None`）→ 清除会话 Cookie 并要求重新登录
//!    （Requirements 2.7）。
//!
//! ## Cookie 名选择
//! Cookie 名与清除逻辑**复用 Task 5.3 认证服务**已落地的契约，确保读（本中间件）/
//! 写（登录下发、登出清除）两端口径一致、不漂移：
//! - 名字：[`auth_service::SESSION_COOKIE_NAME`]`= "xizhends_session"`。
//! - 清除：[`auth_service::build_expired_cookie`]（`Max-Age=0` + 同套安全属性），其文档已
//!   明确「供 Task 5.5 会话中间件在 token 无效/过期/吊销时清 Cookie 使用」。
//! 本模块不再自定义 Cookie 名，避免与认证服务出现两个真相源。
//!
//! ## 重新登录的响应形态
//! 无有效会话时统一**清 Cookie + 要求重登**，按请求来源区分呈现（与 error.rs 的
//! HTMX / 普通请求双分支一致）：
//! - 普通请求：`303 See Other` 重定向到 [`LOGIN_PATH`]（浏览器据 `Location` 跳转登录页）。
//! - HTMX 请求（带 `HX-Request` 头）：`401 Unauthorized` + `HX-Redirect: /login`
//!   （HTMX 据此整页跳转，而非把登录页塞进局部片段）。
//! 两种情形都附带把会话 Cookie 置为过期的 `Set-Cookie`（`Max-Age=0`）。
//!
//! ## Principal 装配范围（本任务实现到何处）
//! - `SuperAdmin`：直接由会话 `principal_kind` 构造（无需额外查库）。
//! - `CompanyAdmin`：由会话 `principal_kind` + `tenant_id` 构造。
//! - `Employee`：经 [`AppState::pools`]→`pool_for(tenant_id)` 取租户库连接，从租户库
//!   `users` 行加载 `role` / `permissions`(overrides) / `is_active` / `dpqz`(store_scope)
//!   构造完整主体。`is_active=false` 视同被吊销 → 清 Cookie 要求重登（Requirements 2.7、
//!   呼应 2.9 禁用员工吊销会话的语义）。
//!
//! > 关于 `store_scope`：租户库 `users.dpqz` 既可能是「店铺缩写」也可能是「逗号分隔的
//! > store_id」。本中间件做**保守解析**：空值 → `StoreScope::All`（无限制，沿用旧系统
//! > 空店铺范围=全部的语义）；非空 → 解析其中的数字 `store_id` 形成 `Restricted`。把
//! > 「店铺缩写 → store_id」的完整解析（需联表 `stores`）**委托**给店铺/权限层
//! > （Task 6.x / 8.6），此处仅装配可直接得到的部分。

use std::collections::HashMap;

use axum::extract::{Request, State};
use axum::http::{header, HeaderName, HeaderValue, StatusCode};
use axum::middleware::Next;
use axum::response::{IntoResponse, Redirect, Response};

use crate::error::AppError;
use crate::models::user::{FeatureModule, Principal, Role, StoreScope, TenantId};
use crate::repository::session_repo::{self, PrincipalKind, Session};
use crate::services::auth_service::{build_expired_cookie, SESSION_COOKIE_NAME};
use crate::state::AppState;

/// 未认证时跳转的登录路径。
const LOGIN_PATH: &str = "/login";

/// HTMX 整页跳转响应头名（小写，`HeaderName::from_static` 要求小写）。
const HX_REDIRECT: HeaderName = HeaderName::from_static("hx-redirect");

/// 会话校验中间件主体。
///
/// 以 `axum::middleware::from_fn_with_state(state, require_session)` 形式挂载（Task 18.1）。
///
/// 流程：读 Cookie token → 查有效会话 → 续期 `last_seen_at` → 装配 `Principal` 注入扩展 →
/// 放行；任一环节判定「需重新登录」时清 Cookie 并返回重登响应。
pub async fn require_session(
    State(state): State<AppState>,
    mut req: Request,
    next: Next,
) -> Response {
    // 以 HX-Request 头区分 HTMX 与普通请求，决定重登响应形态。
    let is_htmx = req.headers().get("HX-Request").is_some();

    // 1. 从 Cookie 头提取会话 token。
    let token = req
        .headers()
        .get(header::COOKIE)
        .and_then(|v| v.to_str().ok())
        .and_then(|cookies| parse_cookie_value(cookies, SESSION_COOKIE_NAME));

    let token = match token {
        Some(t) if !t.is_empty() => t,
        // 无 Cookie / 空 token → 要求登录。
        _ => return relogin_response(is_htmx),
    };

    // 2. 查询未过期会话（过期 / 不存在 / 已吊销均返回 None）。
    let session = match session_repo::find_valid_by_token(state.master_pool(), &token).await {
        Ok(Some(s)) => s,
        Ok(None) => return relogin_response(is_htmx),
        Err(e) => return e.into_response_with(is_htmx),
    };

    // 3. 滑动续期 last_seen_at（仅对未过期会话生效，见 session_repo）。
    if let Err(e) = session_repo::touch_last_seen(state.master_pool(), &token).await {
        return e.into_response_with(is_htmx);
    }

    // 4. 装配 Principal 并注入请求扩展。
    match load_principal(&state, &session).await {
        // 成功：注入主体后放行。
        Ok(Some(principal)) => {
            req.extensions_mut().insert(principal);
            next.run(req).await
        }
        // 数据不一致 / 主体已停用 → 视同需重新登录，清 Cookie。
        Ok(None) => relogin_response(is_htmx),
        // 主库 / 租户库 / 连接池错误 → 统一错误响应（脱敏）。
        Err(e) => e.into_response_with(is_htmx),
    }
}

/// 据会话装配 `Principal`。
///
/// 返回：
/// - `Ok(Some(principal))`：装配成功，可注入。
/// - `Ok(None)`：会话指向的主体不可用（`principal_kind` 非法、非超管却缺 `tenant_id`、
///   员工记录缺失或已停用）→ 上层清 Cookie 要求重登。
/// - `Err(_)`：底层数据访问错误（主库 / 租户库 / 连接池）。
async fn load_principal(
    state: &AppState,
    session: &Session,
) -> Result<Option<Principal>, AppError> {
    let kind = match session.principal_kind_enum() {
        Some(k) => k,
        // principal_kind 存储值非法 → 无法判定主体。
        None => return Ok(None),
    };

    match kind {
        PrincipalKind::SuperAdmin => Ok(Some(Principal::SuperAdmin)),
        PrincipalKind::CompanyAdmin => match session.tenant_id {
            Some(tid) => Ok(Some(Principal::CompanyAdmin {
                tenant_id: TenantId(tid),
            })),
            // 公司管理员必须归属某租户；缺失视为数据不一致。
            None => Ok(None),
        },
        PrincipalKind::Employee => {
            let tenant_id = match session.tenant_id {
                Some(tid) => tid,
                None => return Ok(None),
            };
            load_employee_principal(state, tenant_id, session.principal_id).await
        }
    }
}

/// 租户库 `users` 行的最小投影（仅装配 `Principal::Employee` 所需列）。
#[derive(Debug, sqlx::FromRow)]
struct EmployeeRow {
    role: String,
    is_company_admin: bool,
    is_active: bool,
    /// `permissions` JSON 列（可空）。
    permissions: Option<sqlx::types::Json<serde_json::Value>>,
    dpqz: String,
}

/// 从租户库 `users` 行装配员工主体（经租户连接池）。
///
/// - 记录不存在 → `Ok(None)`（要求重登）。
/// - `is_active=false`（员工被禁用）→ `Ok(None)`（呼应 Requirements 2.9：禁用即失效登录态）。
/// - `is_company_admin=true` → 即便会话标记为 employee，也按公司管理员装配（自洽兜底）。
async fn load_employee_principal(
    state: &AppState,
    tenant_id: i64,
    user_id: i64,
) -> Result<Option<Principal>, AppError> {
    let pool = state.pools().pool_for(tenant_id).await?;

    let row = sqlx::query_as::<_, EmployeeRow>(
        "SELECT `role`, `is_company_admin`, `is_active`, `permissions`, `dpqz` \
         FROM `users` WHERE `id` = ?",
    )
    .bind(user_id)
    .fetch_optional(&pool)
    .await?;

    let row = match row {
        Some(r) => r,
        None => return Ok(None),
    };

    // 已停用的员工：登录态失效，要求重登。
    if !row.is_active {
        return Ok(None);
    }

    // 自洽兜底：DB 标记为公司管理员时按公司管理员装配。
    if row.is_company_admin {
        return Ok(Some(Principal::CompanyAdmin {
            tenant_id: TenantId(tenant_id),
        }));
    }

    let role = parse_role(&row.role);
    let overrides = row
        .permissions
        .map(|j| parse_overrides(&j.0))
        .unwrap_or_default();
    let store_scope = parse_store_scope(&row.dpqz);

    Ok(Some(Principal::Employee {
        tenant_id: TenantId(tenant_id),
        user_id,
        role,
        overrides,
        store_scope,
    }))
}

/// 角色字符串（`采购` / `客服` / `品检`）→ [`Role`]。
///
/// 未知值按**最小权限**回退到 `品检`（`ItemChecker`，默认全关），避免误授权。
fn parse_role(s: &str) -> Role {
    match s.trim() {
        "采购" => Role::Buyer,
        "客服" => Role::ServiceStaff,
        "品检" => Role::ItemChecker,
        _ => Role::ItemChecker,
    }
}

/// 把 `permissions` JSON（形如 `{"system_settings": true, ...}`）解析为显式开关表。
///
/// 复用 [`FeatureModule`] 的 serde 键名映射：键能反序列化为已知模块且值为布尔时才纳入；
/// 未知键 / 非布尔值一律忽略，保证健壮（不因脏数据而 panic 或误判）。
fn parse_overrides(value: &serde_json::Value) -> HashMap<FeatureModule, bool> {
    let mut map = HashMap::new();
    if let Some(obj) = value.as_object() {
        for (k, v) in obj {
            if let (Ok(module), Some(b)) = (
                serde_json::from_value::<FeatureModule>(serde_json::Value::String(k.clone())),
                v.as_bool(),
            ) {
                map.insert(module, b);
            }
        }
    }
    map
}

/// 解析 `users.dpqz` 为 [`StoreScope`]（保守策略，见模块级文档）。
///
/// - 空白 → [`StoreScope::All`]（无限制）。
/// - 非空 → 取其中可解析为整数的 `store_id` 组成 [`StoreScope::Restricted`]；
///   「店铺缩写 → store_id」的完整解析委托给店铺/权限层。
fn parse_store_scope(dpqz: &str) -> StoreScope {
    let trimmed = dpqz.trim();
    if trimmed.is_empty() {
        return StoreScope::All;
    }
    let ids: Vec<i64> = trimmed
        .split(',')
        .map(|s| s.trim())
        .filter(|s| !s.is_empty())
        .filter_map(|s| s.parse::<i64>().ok())
        .collect();
    StoreScope::Restricted(ids)
}

/// 从 `Cookie` 头里取出指定名字的 Cookie 值（未找到返回 `None`）。
///
/// 按 RFC 6265 的 `name=value; name2=value2` 形态切分；对名字做精确匹配，
/// 对名值两侧空白做修剪。值本身不做百分号解码（会话 token 为十六进制，无需解码）。
fn parse_cookie_value(cookie_header: &str, name: &str) -> Option<String> {
    for pair in cookie_header.split(';') {
        let pair = pair.trim();
        if let Some((k, v)) = pair.split_once('=') {
            if k.trim() == name {
                return Some(v.trim().to_string());
            }
        }
    }
    None
}

/// 构造「清 Cookie + 要求重新登录」响应。
///
/// - HTMX 请求：`401` + `HX-Redirect: /login`（前端整页跳转）。
/// - 普通请求：`303 See Other` 重定向到 `/login`。
/// 两者都附带作废会话 Cookie 的 `Set-Cookie`（复用 [`build_expired_cookie`]）。
fn relogin_response(is_htmx: bool) -> Response {
    let mut resp = if is_htmx {
        let mut r = StatusCode::UNAUTHORIZED.into_response();
        r.headers_mut()
            .insert(HX_REDIRECT, HeaderValue::from_static(LOGIN_PATH));
        r
    } else {
        Redirect::to(LOGIN_PATH).into_response()
    };

    if let Ok(cookie) = HeaderValue::from_str(&build_expired_cookie()) {
        resp.headers_mut().append(header::SET_COOKIE, cookie);
    }
    resp
}

#[cfg(test)]
mod tests {
    use super::*;

    // ---- Cookie 解析（纯逻辑）-------------------------------------------------

    #[test]
    fn parse_cookie_value_finds_named_cookie() {
        let header = "session=abc123; theme=dark; lang=zh";
        assert_eq!(
            parse_cookie_value(header, "session"),
            Some("abc123".to_string())
        );
        assert_eq!(
            parse_cookie_value(header, "theme"),
            Some("dark".to_string())
        );
        assert_eq!(parse_cookie_value(header, "lang"), Some("zh".to_string()));
    }

    #[test]
    fn parse_cookie_value_trims_surrounding_whitespace() {
        // 各对之间常带空格，名值两侧也可能有空白。
        let header = "  session = tok-with-spaces ;  other=1 ";
        assert_eq!(
            parse_cookie_value(header, "session"),
            Some("tok-with-spaces".to_string())
        );
    }

    #[test]
    fn parse_cookie_value_returns_none_when_absent() {
        assert_eq!(parse_cookie_value("a=1; b=2", "session"), None);
        assert_eq!(parse_cookie_value("", "session"), None);
    }

    #[test]
    fn parse_cookie_value_handles_value_with_equals_sign() {
        // 值中包含 '=' 时只在第一个 '=' 处切分，余下原样保留。
        let header = "session=a=b=c";
        assert_eq!(
            parse_cookie_value(header, "session"),
            Some("a=b=c".to_string())
        );
    }

    #[test]
    fn parse_cookie_value_exact_name_match_only() {
        // 不应把 "xsession" 误当作 "session"。
        let header = "xsession=nope; session=yes";
        assert_eq!(
            parse_cookie_value(header, "session"),
            Some("yes".to_string())
        );
    }

    #[test]
    fn parse_cookie_value_empty_value_returns_empty_string() {
        // 空值（如被清除的 Cookie 残留）应返回空串，由调用方按「无 token」处理。
        assert_eq!(
            parse_cookie_value("session=", "session"),
            Some(String::new())
        );
    }

    // ---- 清除 Cookie（复用 auth_service::build_expired_cookie）-----------------

    #[test]
    fn reused_expired_cookie_expires_immediately() {
        let c = build_expired_cookie();
        // 复用认证服务的清除 Cookie：名字匹配且立即过期。
        assert!(
            c.starts_with(&format!("{SESSION_COOKIE_NAME}=;")),
            "应针对会话 Cookie 且值为空: {c}"
        );
        assert!(c.contains("Max-Age=0"), "应含 Max-Age=0: {c}");
    }

    #[test]
    fn reused_expired_cookie_preserves_security_attributes() {
        let c = build_expired_cookie();
        assert!(c.contains("HttpOnly"), "应保留 HttpOnly: {c}");
        assert!(c.contains("Secure"), "应保留 Secure: {c}");
        assert!(c.contains("SameSite=Lax"), "应保留 SameSite: {c}");
        assert!(c.contains("Path=/"), "应限定 Path=/: {c}");
    }

    #[test]
    fn reused_expired_cookie_is_valid_header_value() {
        // 清除 Cookie 串必须可作为 HTTP 头值。
        let c = build_expired_cookie();
        assert!(HeaderValue::from_str(&c).is_ok(), "应为合法头值: {c}");
    }

    #[test]
    fn parse_then_clear_use_same_cookie_name() {
        // 读端（parse）与写端（清除）必须用同一个 Cookie 名，避免清不掉。
        let header = format!("{SESSION_COOKIE_NAME}=tok123; other=1");
        assert_eq!(
            parse_cookie_value(&header, SESSION_COOKIE_NAME),
            Some("tok123".to_string())
        );
        assert!(build_expired_cookie().starts_with(&format!("{SESSION_COOKIE_NAME}=;")));
    }

    // ---- 写端→读端 Cookie 往返（build_session_cookie → parse_cookie_value）---------
    // 登录下发的 Set-Cookie（写端，auth_service）与中间件读取（读端，本模块）必须能闭环：
    // 从 build_session_cookie 产出的串里，parse_cookie_value 必须能取回原始 token，
    // 且不被 Path=/、Max-Age、HttpOnly 等属性段干扰（Req 2.3 安全属性 + 2.6 读会话）。

    #[test]
    fn round_trip_parses_token_from_built_session_cookie() {
        use crate::services::auth_service::build_session_cookie;
        use std::time::Duration;

        let token = "a3f1c0deadbeef0011223344556677889900aabbccddeeff0011223344556677";
        let set_cookie = build_session_cookie(token, Duration::from_secs(604800));

        // build_session_cookie 产出形如 "name=token; Path=/; Max-Age=...; HttpOnly; Secure; SameSite=Lax"。
        // 读端按 ';' 切分后，第一段即 name=token；属性段（无 '=' 或键非 name）不应被误当作会话值。
        assert_eq!(
            parse_cookie_value(&set_cookie, SESSION_COOKIE_NAME),
            Some(token.to_string()),
            "应能从下发的 Set-Cookie 中取回原始 token: {set_cookie}"
        );
    }

    #[test]
    fn round_trip_does_not_mistake_attributes_for_token() {
        use crate::services::auth_service::build_session_cookie;
        use std::time::Duration;

        let token = "deadbeefcafe";
        let set_cookie = build_session_cookie(token, Duration::from_secs(3600));

        // 即便属性段含 '='（如 Max-Age=3600、SameSite=Lax），按 name 精确匹配仍只取会话值。
        let parsed = parse_cookie_value(&set_cookie, SESSION_COOKIE_NAME);
        assert_eq!(parsed.as_deref(), Some(token));
        // 不应把 "Path" / "Max-Age" / "SameSite" 当成会话 Cookie。
        assert_eq!(
            parse_cookie_value(&set_cookie, "Path"),
            Some("/".to_string())
        );
    }

    #[test]
    fn round_trip_expired_cookie_parses_to_empty_token() {
        // 清除 Cookie（build_expired_cookie）经读端解析得到空串，require_session 会按「无 token」
        // 处理并要求重登（Req 2.7）。
        let cleared = build_expired_cookie();
        assert_eq!(
            parse_cookie_value(&cleared, SESSION_COOKIE_NAME),
            Some(String::new()),
            "清除后的 Cookie 值应为空串: {cleared}"
        );
    }

    // ---- 角色 / 权限覆盖 / 店铺范围解析（纯逻辑）-----------------------------

    #[test]
    fn parse_role_maps_known_values_and_defaults_to_least_privilege() {
        assert_eq!(parse_role("采购"), Role::Buyer);
        assert_eq!(parse_role("客服"), Role::ServiceStaff);
        assert_eq!(parse_role("品检"), Role::ItemChecker);
        assert_eq!(parse_role(" 采购 "), Role::Buyer, "应修剪空白");
        // 未知 / 空 → 最小权限（品检，默认全关）。
        assert_eq!(parse_role("unknown"), Role::ItemChecker);
        assert_eq!(parse_role(""), Role::ItemChecker);
    }

    #[test]
    fn parse_overrides_reads_known_keys_only() {
        let v = serde_json::json!({
            "system_settings": true,
            "kefu_mail": false,
            "1688_log": true,
            "totally_unknown": true, // 未知键应忽略
            "order_log": "yes"        // 非布尔值应忽略
        });
        let map = parse_overrides(&v);
        assert_eq!(map.get(&FeatureModule::SystemSettings), Some(&true));
        assert_eq!(map.get(&FeatureModule::KefuMail), Some(&false));
        assert_eq!(map.get(&FeatureModule::Log1688), Some(&true));
        // 非布尔 order_log 被忽略。
        assert_eq!(map.get(&FeatureModule::OrderLog), None);
        // 总数应为 3 个有效键。
        assert_eq!(map.len(), 3);
    }

    #[test]
    fn parse_overrides_handles_non_object_json() {
        // 非对象 JSON（数组 / 标量 / null）应得到空表，不 panic。
        assert!(parse_overrides(&serde_json::json!([1, 2, 3])).is_empty());
        assert!(parse_overrides(&serde_json::json!("string")).is_empty());
        assert!(parse_overrides(&serde_json::Value::Null).is_empty());
    }

    #[test]
    fn parse_store_scope_empty_means_all() {
        assert_eq!(parse_store_scope(""), StoreScope::All);
        assert_eq!(parse_store_scope("   "), StoreScope::All);
    }

    #[test]
    fn parse_store_scope_parses_numeric_ids() {
        assert_eq!(
            parse_store_scope("1,2,3"),
            StoreScope::Restricted(vec![1, 2, 3])
        );
        assert_eq!(
            parse_store_scope(" 10 , 20 ,, 30 "),
            StoreScope::Restricted(vec![10, 20, 30]),
            "应修剪空白并跳过空段"
        );
    }

    #[test]
    fn parse_store_scope_ignores_non_numeric_tokens() {
        // 店铺缩写（非数字）无法在此解析为 id，被跳过；解析委托给店铺/权限层。
        assert_eq!(
            parse_store_scope("shopA,shopB"),
            StoreScope::Restricted(vec![]),
        );
        assert_eq!(
            parse_store_scope("5,shopA,7"),
            StoreScope::Restricted(vec![5, 7]),
        );
    }
}
