//! `PlatformOAuth`（Yahoo購物）实现 + 三平台共享的 OAuth 基础设施（Task 13.5）。
//!
//! 复刻 old `plugins/yahooshop-api/func.php` 的 YConnect v2 授权码流程：
//! - 授权端点：`https://auth.login.yahoo.co.jp/yconnect/v2/authorization`
//! - 令牌端点：`https://auth.login.yahoo.co.jp/yconnect/v2/token`
//! - 授权 URL 参数：`response_type=code` / `client_id` / `redirect_uri` / `scope=openid` / `state`
//! - 换码 / 刷新：`POST` 令牌端点，`application/x-www-form-urlencoded`，并附
//!   `Authorization: Basic base64(client_id:client_secret)`（见 `api_token_request` /
//!   `api_refresh_token_request`）。
//!
//! 设计要点：**令牌 HTTP 调用被抽象到 [`TokenHttp`] 注入边界**，本模块只负责「构造请求参数」
//! 与「把原始令牌 JSON 解析为 [`TokenSet`]」两段纯逻辑，因此无需 `reqwest` 即可离线单测。
//! 真实运行时由上层注入一个基于 HTTP 客户端的 [`TokenHttp`] 实现。
//!
//! 本文件同时导出供 `oauth_rakuten` / `oauth_wowma` 复用的共享件：
//! [`TokenHttp`]、[`form_urlencode`]、[`base64_encode`]、[`basic_auth_header`]、[`parse_token_json`]。
//!
//! _Requirements: 6.3_

use std::sync::Arc;

use async_trait::async_trait;

use crate::error::AppError;
use crate::integrations::traits::{PlatformOAuth, TokenSet};

/// Yahoo YConnect v2 授权端点（复刻 old `API_URL_AUTH`）。
pub const YAHOO_AUTH_ENDPOINT: &str = "https://auth.login.yahoo.co.jp/yconnect/v2/authorization";
/// Yahoo YConnect v2 令牌端点（复刻 old `API_URL_TOKEN`）。
pub const YAHOO_TOKEN_ENDPOINT: &str = "https://auth.login.yahoo.co.jp/yconnect/v2/token";
/// Yahoo 默认 scope（复刻 old 授权 URL 的 `scope=openid`）。
pub const YAHOO_DEFAULT_SCOPE: &str = "openid";

// ============================================================================
// 共享件：注入式令牌 HTTP 边界（三平台复用）
// ============================================================================

/// 令牌端点 HTTP 调用的**注入边界**。
///
/// 把真正的网络 IO 收敛到此 trait，使各平台 OAuth 的「构参 + 解析」逻辑可离线单测：
/// 测试时注入返回固定 JSON 的实现，运行时注入基于 HTTP 客户端的实现。
///
/// 约定：实现侧以 `application/x-www-form-urlencoded` 形式 `POST` `form` 到 `url`，
/// 附带 `headers`，并把**原始响应体（令牌 JSON）**原样返回；任何网络/状态错误收敛为
/// [`AppError::ExternalApi`]。
#[async_trait]
pub trait TokenHttp: Send + Sync {
    /// `POST` 表单到 `url`，返回原始响应体（令牌 JSON 文本）。
    async fn post_form(
        &self,
        url: &str,
        headers: &[(String, String)],
        form: &[(String, String)],
    ) -> Result<String, AppError>;
}

// ============================================================================
// 共享件：编码与解析工具（三平台复用）
// ============================================================================

/// 对单个值做 `application/x-www-form-urlencoded` / 查询串百分号编码。
///
/// 仅保留 RFC 3986 的 unreserved 字符（`ALPHA` / `DIGIT` / `-` `_` `.` `~`），其余按 UTF-8
/// 逐字节编码为 `%XX`（大写十六进制）。空格编码为 `%20`（不使用 `+`，对查询串与表单皆安全）。
pub fn form_urlencode(value: &str) -> String {
    const HEX: &[u8; 16] = b"0123456789ABCDEF";
    let mut out = String::with_capacity(value.len());
    for &b in value.as_bytes() {
        match b {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' | b'~' => {
                out.push(b as char);
            }
            _ => {
                out.push('%');
                out.push(HEX[(b >> 4) as usize] as char);
                out.push(HEX[(b & 0x0f) as usize] as char);
            }
        }
    }
    out
}

/// 标准 Base64 编码（RFC 4648，`+/` 字母表，带 `=` 填充）。
///
/// 用于构造 `Authorization: Basic ...` 头，避免引入额外依赖。
pub fn base64_encode(input: &[u8]) -> String {
    const TABLE: &[u8; 64] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    let mut out = String::with_capacity((input.len() + 2) / 3 * 4);
    for chunk in input.chunks(3) {
        let b0 = chunk[0] as u32;
        let b1 = *chunk.get(1).unwrap_or(&0) as u32;
        let b2 = *chunk.get(2).unwrap_or(&0) as u32;
        let n = (b0 << 16) | (b1 << 8) | b2;
        out.push(TABLE[((n >> 18) & 0x3f) as usize] as char);
        out.push(TABLE[((n >> 12) & 0x3f) as usize] as char);
        if chunk.len() > 1 {
            out.push(TABLE[((n >> 6) & 0x3f) as usize] as char);
        } else {
            out.push('=');
        }
        if chunk.len() > 2 {
            out.push(TABLE[(n & 0x3f) as usize] as char);
        } else {
            out.push('=');
        }
    }
    out
}

/// 构造 `Authorization: Basic base64(client_id:client_secret)` 头键值对。
pub fn basic_auth_header(client_id: &str, client_secret: &str) -> (String, String) {
    let raw = format!("{client_id}:{client_secret}");
    (
        "Authorization".to_string(),
        format!("Basic {}", base64_encode(raw.as_bytes())),
    )
}

/// 把令牌端点返回的原始 JSON 解析为 [`TokenSet`]。
///
/// - `access_token` 缺失或非字符串 → [`AppError::ExternalApi`]（令牌不可用）。
/// - `refresh_token` / `token_type` 可缺省。
/// - `expires_in` 兼容数字或字符串数字；无法解析则置 `None`。
///
/// `provider` 仅用于错误日志归类，不外泄。
pub fn parse_token_json(provider: &str, raw: &str) -> Result<TokenSet, AppError> {
    let value: serde_json::Value =
        serde_json::from_str(raw).map_err(|e| AppError::ExternalApi {
            provider: provider.to_string(),
            detail: format!("token json parse error: {e}"),
        })?;

    let access_token = value
        .get("access_token")
        .and_then(|v| v.as_str())
        .filter(|s| !s.is_empty())
        .ok_or_else(|| AppError::ExternalApi {
            provider: provider.to_string(),
            detail: "token response missing access_token".to_string(),
        })?
        .to_string();

    let refresh_token = value
        .get("refresh_token")
        .and_then(|v| v.as_str())
        .filter(|s| !s.is_empty())
        .map(|s| s.to_string());

    let token_type = value
        .get("token_type")
        .and_then(|v| v.as_str())
        .filter(|s| !s.is_empty())
        .map(|s| s.to_string());

    let expires_in = value.get("expires_in").and_then(|v| {
        v.as_i64()
            .or_else(|| v.as_str().and_then(|s| s.trim().parse::<i64>().ok()))
    });

    Ok(TokenSet {
        access_token,
        refresh_token,
        expires_in,
        token_type,
    })
}

// ============================================================================
// YahooOAuth
// ============================================================================

/// Yahoo購物 OAuth（YConnect v2 授权码流程）。
///
/// 持有应用凭证与端点配置；令牌 HTTP 经 [`TokenHttp`] 注入，便于离线单测与运行时替换。
pub struct YahooOAuth {
    client_id: String,
    client_secret: String,
    redirect_uri: String,
    auth_endpoint: String,
    token_endpoint: String,
    scope: String,
    http: Arc<dyn TokenHttp>,
}

impl YahooOAuth {
    /// 用应用凭证 + 注入的令牌 HTTP 构造，端点取 Yahoo 默认值。
    pub fn new(
        client_id: impl Into<String>,
        client_secret: impl Into<String>,
        redirect_uri: impl Into<String>,
        http: Arc<dyn TokenHttp>,
    ) -> Self {
        Self {
            client_id: client_id.into(),
            client_secret: client_secret.into(),
            redirect_uri: redirect_uri.into(),
            auth_endpoint: YAHOO_AUTH_ENDPOINT.to_string(),
            token_endpoint: YAHOO_TOKEN_ENDPOINT.to_string(),
            scope: YAHOO_DEFAULT_SCOPE.to_string(),
            http,
        }
    }

    /// 覆盖授权/令牌端点（测试或私有部署用）。
    pub fn with_endpoints(
        mut self,
        auth_endpoint: impl Into<String>,
        token_endpoint: impl Into<String>,
    ) -> Self {
        self.auth_endpoint = auth_endpoint.into();
        self.token_endpoint = token_endpoint.into();
        self
    }

    /// 覆盖 scope。
    pub fn with_scope(mut self, scope: impl Into<String>) -> Self {
        self.scope = scope.into();
        self
    }
}

#[async_trait]
impl PlatformOAuth for YahooOAuth {
    fn authorize_url(&self, state: &str) -> String {
        format!(
            "{}?response_type=code&client_id={}&redirect_uri={}&scope={}&state={}",
            self.auth_endpoint,
            form_urlencode(&self.client_id),
            form_urlencode(&self.redirect_uri),
            form_urlencode(&self.scope),
            form_urlencode(state),
        )
    }

    async fn exchange_code(&self, code: &str) -> Result<TokenSet, AppError> {
        let headers = vec![
            (
                "Content-Type".to_string(),
                "application/x-www-form-urlencoded".to_string(),
            ),
            basic_auth_header(&self.client_id, &self.client_secret),
        ];
        let form = vec![
            ("grant_type".to_string(), "authorization_code".to_string()),
            ("code".to_string(), code.to_string()),
            ("redirect_uri".to_string(), self.redirect_uri.clone()),
            ("client_id".to_string(), self.client_id.clone()),
            ("client_secret".to_string(), self.client_secret.clone()),
        ];
        let raw = self
            .http
            .post_form(&self.token_endpoint, &headers, &form)
            .await?;
        parse_token_json("yahoo", &raw)
    }

    async fn refresh(&self, refresh_token: &str) -> Result<TokenSet, AppError> {
        let headers = vec![
            (
                "Content-Type".to_string(),
                "application/x-www-form-urlencoded".to_string(),
            ),
            basic_auth_header(&self.client_id, &self.client_secret),
        ];
        let form = vec![
            ("grant_type".to_string(), "refresh_token".to_string()),
            ("refresh_token".to_string(), refresh_token.to_string()),
            ("client_id".to_string(), self.client_id.clone()),
            ("client_secret".to_string(), self.client_secret.clone()),
        ];
        let raw = self
            .http
            .post_form(&self.token_endpoint, &headers, &form)
            .await?;
        parse_token_json("yahoo", &raw)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    /// 测试用 [`TokenHttp`]：返回预置 JSON，并记录最近一次调用的 url/headers/form，
    /// 以便对「构参逻辑」做断言。这里测的是真实的构参+解析逻辑，不是把网络结果造假。
    struct RecordingHttp {
        response: String,
        last: Mutex<Option<(String, Vec<(String, String)>, Vec<(String, String)>)>>,
    }

    impl RecordingHttp {
        fn new(response: &str) -> Self {
            Self {
                response: response.to_string(),
                last: Mutex::new(None),
            }
        }
    }

    #[async_trait]
    impl TokenHttp for RecordingHttp {
        async fn post_form(
            &self,
            url: &str,
            headers: &[(String, String)],
            form: &[(String, String)],
        ) -> Result<String, AppError> {
            *self.last.lock().unwrap() = Some((url.to_string(), headers.to_vec(), form.to_vec()));
            Ok(self.response.clone())
        }
    }

    fn yahoo(http: Arc<dyn TokenHttp>) -> YahooOAuth {
        YahooOAuth::new(
            "app-id-123",
            "secret-xyz",
            "https://shop.example.com/callback",
            http,
        )
    }

    // ---- 共享件：编码 ----

    #[test]
    fn form_urlencode_preserves_unreserved_and_encodes_others() {
        assert_eq!(form_urlencode("aZ09-_.~"), "aZ09-_.~");
        assert_eq!(form_urlencode("a b"), "a%20b");
        assert_eq!(
            form_urlencode("https://x.com/cb?a=1&b=2"),
            "https%3A%2F%2Fx.com%2Fcb%3Fa%3D1%26b%3D2"
        );
    }

    #[test]
    fn base64_encode_matches_known_vectors() {
        assert_eq!(base64_encode(b""), "");
        assert_eq!(base64_encode(b"f"), "Zg==");
        assert_eq!(base64_encode(b"fo"), "Zm8=");
        assert_eq!(base64_encode(b"foo"), "Zm9v");
        assert_eq!(base64_encode(b"foob"), "Zm9vYg==");
        assert_eq!(base64_encode(b"fooba"), "Zm9vYmE=");
        assert_eq!(base64_encode(b"foobar"), "Zm9vYmFy");
    }

    #[test]
    fn basic_auth_header_encodes_id_and_secret() {
        let (k, v) = basic_auth_header("user", "pass");
        assert_eq!(k, "Authorization");
        // base64("user:pass") == "dXNlcjpwYXNz"
        assert_eq!(v, "Basic dXNlcjpwYXNz");
    }

    // ---- 共享件：令牌 JSON 解析 ----

    #[test]
    fn parse_token_json_full_payload() {
        let raw =
            r#"{"access_token":"AT","refresh_token":"RT","expires_in":3600,"token_type":"Bearer"}"#;
        let t = parse_token_json("yahoo", raw).unwrap();
        assert_eq!(t.access_token, "AT");
        assert_eq!(t.refresh_token.as_deref(), Some("RT"));
        assert_eq!(t.expires_in, Some(3600));
        assert_eq!(t.token_type.as_deref(), Some("Bearer"));
    }

    #[test]
    fn parse_token_json_minimal_payload() {
        let t = parse_token_json("yahoo", r#"{"access_token":"only"}"#).unwrap();
        assert_eq!(t.access_token, "only");
        assert!(t.refresh_token.is_none());
        assert!(t.expires_in.is_none());
        assert!(t.token_type.is_none());
    }

    #[test]
    fn parse_token_json_expires_in_as_string() {
        let t = parse_token_json("yahoo", r#"{"access_token":"AT","expires_in":"7200"}"#).unwrap();
        assert_eq!(t.expires_in, Some(7200));
    }

    #[test]
    fn parse_token_json_missing_access_token_is_error() {
        let err = parse_token_json("yahoo", r#"{"refresh_token":"RT"}"#).unwrap_err();
        assert!(matches!(err, AppError::ExternalApi { .. }));
        // 错误细节不外泄给客户端
        assert!(!err.client_message().contains("access_token"));
    }

    #[test]
    fn parse_token_json_invalid_json_is_error() {
        let err = parse_token_json("yahoo", "not-json").unwrap_err();
        assert!(matches!(err, AppError::ExternalApi { .. }));
    }

    // ---- Yahoo authorize_url ----

    #[test]
    fn yahoo_authorize_url_has_endpoint_and_required_params() {
        let oauth = yahoo(Arc::new(RecordingHttp::new("{}")));
        let url = oauth.authorize_url("state-abc");
        assert!(url.starts_with(YAHOO_AUTH_ENDPOINT));
        assert!(url.contains("response_type=code"));
        assert!(url.contains("client_id=app-id-123"));
        assert!(url.contains("scope=openid"));
        assert!(url.contains("state=state-abc"));
        // redirect_uri 经过百分号编码
        assert!(url.contains("redirect_uri=https%3A%2F%2Fshop.example.com%2Fcallback"));
    }

    #[test]
    fn yahoo_authorize_url_encodes_state() {
        let oauth = yahoo(Arc::new(RecordingHttp::new("{}")));
        let url = oauth.authorize_url("a b&c");
        assert!(url.contains("state=a%20b%26c"));
    }

    // ---- Yahoo exchange_code / refresh ----

    #[tokio::test]
    async fn yahoo_exchange_code_builds_request_and_parses_token() {
        let http = Arc::new(RecordingHttp::new(
            r#"{"access_token":"AT","refresh_token":"RT","expires_in":3600,"token_type":"Bearer"}"#,
        ));
        let oauth = yahoo(http.clone());
        let token = oauth.exchange_code("the-code").await.unwrap();
        assert_eq!(token.access_token, "AT");
        assert_eq!(token.refresh_token.as_deref(), Some("RT"));

        let last = http.last.lock().unwrap().clone().unwrap();
        let (url, headers, form) = last;
        assert_eq!(url, YAHOO_TOKEN_ENDPOINT);
        assert!(headers.iter().any(|(k, _)| k == "Authorization"));
        assert!(form
            .iter()
            .any(|(k, v)| k == "grant_type" && v == "authorization_code"));
        assert!(form.iter().any(|(k, v)| k == "code" && v == "the-code"));
        assert!(form.iter().any(|(k, _)| k == "redirect_uri"));
    }

    #[tokio::test]
    async fn yahoo_refresh_builds_request_and_parses_token() {
        let http = Arc::new(RecordingHttp::new(r#"{"access_token":"AT2"}"#));
        let oauth = yahoo(http.clone());
        let token = oauth.refresh("the-refresh").await.unwrap();
        assert_eq!(token.access_token, "AT2");

        let last = http.last.lock().unwrap().clone().unwrap();
        let (_, _, form) = last;
        assert!(form
            .iter()
            .any(|(k, v)| k == "grant_type" && v == "refresh_token"));
        assert!(form
            .iter()
            .any(|(k, v)| k == "refresh_token" && v == "the-refresh"));
    }

    #[tokio::test]
    async fn yahoo_exchange_code_propagates_missing_access_token() {
        let http = Arc::new(RecordingHttp::new(r#"{"error":"invalid_grant"}"#));
        let oauth = yahoo(http);
        let err = oauth.exchange_code("bad").await.unwrap_err();
        assert!(matches!(err, AppError::ExternalApi { .. }));
    }
}
