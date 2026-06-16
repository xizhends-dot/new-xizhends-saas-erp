//! `PlatformOAuth`（乐天 RMS）实现（Task 13.5）。
//!
//! 背景：old `plugins/rakuten-rms-api/func.php` 的 RMS WEB SERVICE **不是重定向式 OAuth2**，
//! 而是用静态凭证签名头：`Authorization: ESA base64(serviceSecret:licenseKey)`
//! （见 `RAKUTEN_RMS_API_SECRET_KEY`=`Secret` / `RAKUTEN_RMS_API_LICENSEKEY_KEY`=`Key`，
//! 端点如 `https://api.rms.rakuten.co.jp/es/2.0/order/searchOrder/`）。
//!
//! 为统一纳入 [`PlatformOAuth`] 契约（授权 URL → 换码 → 刷新），本实现：
//! - `authorize_url`：按标准 OAuth2 形态构造授权跳转 URL（`response_type=code` + `client_id`
//!   + `redirect_uri` + `state` + `scope`），端点可配置；
//! - `exchange_code` / `refresh`：经注入的 [`TokenHttp`] 边界 `POST` 令牌端点并解析为
//!   [`TokenSet`]，请求附带乐天风格的 `Authorization: ESA base64(client_id:client_secret)` 头
//!   （对应 RMS 的 serviceSecret:licenseKey）。
//!
//! 端点默认值可经 [`RakutenOAuth::with_endpoints`] 覆盖，以适配租户实际配置。
//! 与 Yahoo 一致，令牌 HTTP 被抽象到注入边界，无需 `reqwest` 即可离线单测。
//!
//! _Requirements: 6.3_

use std::sync::Arc;

use async_trait::async_trait;

use crate::error::AppError;
use crate::integrations::oauth_yahoo::{
    base64_encode, form_urlencode, parse_token_json, TokenHttp,
};
use crate::integrations::traits::{PlatformOAuth, TokenSet};

/// 乐天 RMS 授权端点默认值（可配置；legacy 为静态 ESA 凭证，无重定向授权页）。
pub const RAKUTEN_AUTH_ENDPOINT: &str = "https://api.rms.rakuten.co.jp/engine/auth/request";
/// 乐天 RMS 令牌端点默认值（可配置）。
pub const RAKUTEN_TOKEN_ENDPOINT: &str = "https://api.rms.rakuten.co.jp/engine/auth/token";

/// 构造乐天 RMS 风格的 `Authorization: ESA base64(client_id:client_secret)` 头。
///
/// 对应 old `func.php` 的 `'ESA ' . base64_encode(Secret . ':' . Key)`。
pub fn esa_auth_header(client_id: &str, client_secret: &str) -> (String, String) {
    let raw = format!("{client_id}:{client_secret}");
    (
        "Authorization".to_string(),
        format!("ESA {}", base64_encode(raw.as_bytes())),
    )
}

/// 乐天 RMS OAuth。
///
/// `client_id` / `client_secret` 对应 RMS 的 serviceSecret / licenseKey；
/// 令牌 HTTP 经 [`TokenHttp`] 注入，便于离线单测与运行时替换。
pub struct RakutenOAuth {
    client_id: String,
    client_secret: String,
    redirect_uri: String,
    auth_endpoint: String,
    token_endpoint: String,
    scope: Option<String>,
    http: Arc<dyn TokenHttp>,
}

impl RakutenOAuth {
    /// 用应用凭证 + 注入的令牌 HTTP 构造，端点取乐天默认值。
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
            auth_endpoint: RAKUTEN_AUTH_ENDPOINT.to_string(),
            token_endpoint: RAKUTEN_TOKEN_ENDPOINT.to_string(),
            scope: None,
            http,
        }
    }

    /// 覆盖授权/令牌端点（适配租户实际配置或测试）。
    pub fn with_endpoints(
        mut self,
        auth_endpoint: impl Into<String>,
        token_endpoint: impl Into<String>,
    ) -> Self {
        self.auth_endpoint = auth_endpoint.into();
        self.token_endpoint = token_endpoint.into();
        self
    }

    /// 设置 scope（默认不带 scope 参数）。
    pub fn with_scope(mut self, scope: impl Into<String>) -> Self {
        self.scope = Some(scope.into());
        self
    }

    fn token_headers(&self) -> Vec<(String, String)> {
        vec![
            (
                "Content-Type".to_string(),
                "application/x-www-form-urlencoded".to_string(),
            ),
            esa_auth_header(&self.client_id, &self.client_secret),
        ]
    }
}

#[async_trait]
impl PlatformOAuth for RakutenOAuth {
    fn authorize_url(&self, state: &str) -> String {
        let mut url = format!(
            "{}?response_type=code&client_id={}&redirect_uri={}&state={}",
            self.auth_endpoint,
            form_urlencode(&self.client_id),
            form_urlencode(&self.redirect_uri),
            form_urlencode(state),
        );
        if let Some(scope) = &self.scope {
            url.push_str(&format!("&scope={}", form_urlencode(scope)));
        }
        url
    }

    async fn exchange_code(&self, code: &str) -> Result<TokenSet, AppError> {
        let headers = self.token_headers();
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
        parse_token_json("rakuten", &raw)
    }

    async fn refresh(&self, refresh_token: &str) -> Result<TokenSet, AppError> {
        let headers = self.token_headers();
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
        parse_token_json("rakuten", &raw)
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

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

    fn rakuten(http: Arc<dyn TokenHttp>) -> RakutenOAuth {
        RakutenOAuth::new(
            "svc-secret",
            "license-key",
            "https://rms.example.com/callback",
            http,
        )
    }

    #[test]
    fn esa_auth_header_uses_esa_scheme() {
        let (k, v) = esa_auth_header("a", "b");
        assert_eq!(k, "Authorization");
        // base64("a:b") == "YTpi"
        assert_eq!(v, "ESA YTpi");
    }

    #[test]
    fn rakuten_authorize_url_has_endpoint_and_required_params() {
        let oauth = rakuten(Arc::new(RecordingHttp::new("{}")));
        let url = oauth.authorize_url("st-1");
        assert!(url.starts_with(RAKUTEN_AUTH_ENDPOINT));
        assert!(url.contains("response_type=code"));
        assert!(url.contains("client_id=svc-secret"));
        assert!(url.contains("state=st-1"));
        assert!(url.contains("redirect_uri=https%3A%2F%2Frms.example.com%2Fcallback"));
        // 默认不带 scope
        assert!(!url.contains("scope="));
    }

    #[test]
    fn rakuten_authorize_url_includes_scope_when_set() {
        let oauth = rakuten(Arc::new(RecordingHttp::new("{}"))).with_scope("order");
        let url = oauth.authorize_url("st-2");
        assert!(url.contains("scope=order"));
    }

    #[tokio::test]
    async fn rakuten_exchange_code_builds_request_and_parses_token() {
        let http = Arc::new(RecordingHttp::new(
            r#"{"access_token":"AT","refresh_token":"RT","expires_in":1800,"token_type":"Bearer"}"#,
        ));
        let oauth = rakuten(http.clone());
        let token = oauth.exchange_code("code-1").await.unwrap();
        assert_eq!(token.access_token, "AT");
        assert_eq!(token.expires_in, Some(1800));

        let (url, headers, form) = http.last.lock().unwrap().clone().unwrap();
        assert_eq!(url, RAKUTEN_TOKEN_ENDPOINT);
        // 乐天 ESA 鉴权头
        assert!(headers
            .iter()
            .any(|(k, v)| k == "Authorization" && v.starts_with("ESA ")));
        assert!(form
            .iter()
            .any(|(k, v)| k == "grant_type" && v == "authorization_code"));
        assert!(form.iter().any(|(k, v)| k == "code" && v == "code-1"));
    }

    #[tokio::test]
    async fn rakuten_refresh_builds_request_and_parses_token() {
        let http = Arc::new(RecordingHttp::new(r#"{"access_token":"NEW"}"#));
        let oauth = rakuten(http.clone());
        let token = oauth.refresh("r-tok").await.unwrap();
        assert_eq!(token.access_token, "NEW");

        let (_, _, form) = http.last.lock().unwrap().clone().unwrap();
        assert!(form
            .iter()
            .any(|(k, v)| k == "grant_type" && v == "refresh_token"));
        assert!(form
            .iter()
            .any(|(k, v)| k == "refresh_token" && v == "r-tok"));
    }

    #[tokio::test]
    async fn rakuten_exchange_code_errors_on_missing_access_token() {
        let http = Arc::new(RecordingHttp::new(r#"{"error":"invalid_request"}"#));
        let oauth = rakuten(http);
        let err = oauth.exchange_code("bad").await.unwrap_err();
        assert!(matches!(err, AppError::ExternalApi { .. }));
    }
}
