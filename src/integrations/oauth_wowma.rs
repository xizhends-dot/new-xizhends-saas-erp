//! `PlatformOAuth`（Wowma / au PAY マーケット）实现（Task 13.5）。
//!
//! 背景：old `plugins/wmshopapi/func.php` 的 wmshopapi **不是重定向式 OAuth2**，而是用静态
//! Bearer 令牌：`Authorization: Bearer <token>`（见 `'Authorization' => 'Bearer ' . dpapi_config`，
//! 端点如 `https://api.manager.wowma.jp/wmshopapi/searchTradeInfoListProc`）。
//!
//! 为统一纳入 [`PlatformOAuth`] 契约（授权 URL → 换码 → 刷新），本实现：
//! - `authorize_url`：按标准 OAuth2 形态构造授权跳转 URL（`response_type=code` + `client_id`
//!   + `redirect_uri` + `state` + `scope`），端点可配置；
//! - `exchange_code` / `refresh`：经注入的 [`TokenHttp`] 边界 `POST` 令牌端点并解析为
//!   [`TokenSet`]，请求携带标准 `client_id` / `client_secret` 表单字段。
//!
//! 端点默认值（沿用 Wowma 管理域 `api.manager.wowma.jp`）可经 [`WowmaOAuth::with_endpoints`]
//! 覆盖。与 Yahoo / 乐天一致，令牌 HTTP 被抽象到注入边界，无需 `reqwest` 即可离线单测。
//!
//! _Requirements: 6.3_

use std::sync::Arc;

use async_trait::async_trait;

use crate::error::AppError;
use crate::integrations::oauth_yahoo::{form_urlencode, parse_token_json, TokenHttp};
use crate::integrations::traits::{PlatformOAuth, TokenSet};

/// Wowma 授权端点默认值（可配置；legacy 为静态 Bearer 令牌，无重定向授权页）。
pub const WOWMA_AUTH_ENDPOINT: &str = "https://api.manager.wowma.jp/wmshopapi/oauth/authorize";
/// Wowma 令牌端点默认值（可配置）。
pub const WOWMA_TOKEN_ENDPOINT: &str = "https://api.manager.wowma.jp/wmshopapi/oauth/token";

/// Wowma OAuth。
///
/// 令牌 HTTP 经 [`TokenHttp`] 注入，便于离线单测与运行时替换。
pub struct WowmaOAuth {
    client_id: String,
    client_secret: String,
    redirect_uri: String,
    auth_endpoint: String,
    token_endpoint: String,
    scope: Option<String>,
    http: Arc<dyn TokenHttp>,
}

impl WowmaOAuth {
    /// 用应用凭证 + 注入的令牌 HTTP 构造，端点取 Wowma 默认值。
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
            auth_endpoint: WOWMA_AUTH_ENDPOINT.to_string(),
            token_endpoint: WOWMA_TOKEN_ENDPOINT.to_string(),
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
        vec![(
            "Content-Type".to_string(),
            "application/x-www-form-urlencoded".to_string(),
        )]
    }
}

#[async_trait]
impl PlatformOAuth for WowmaOAuth {
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
        parse_token_json("wowma", &raw)
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
        parse_token_json("wowma", &raw)
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

    fn wowma(http: Arc<dyn TokenHttp>) -> WowmaOAuth {
        WowmaOAuth::new(
            "wowma-client",
            "wowma-secret",
            "https://w.example.com/callback",
            http,
        )
    }

    #[test]
    fn wowma_authorize_url_has_endpoint_and_required_params() {
        let oauth = wowma(Arc::new(RecordingHttp::new("{}")));
        let url = oauth.authorize_url("state-w");
        assert!(url.starts_with(WOWMA_AUTH_ENDPOINT));
        assert!(url.contains("response_type=code"));
        assert!(url.contains("client_id=wowma-client"));
        assert!(url.contains("state=state-w"));
        assert!(url.contains("redirect_uri=https%3A%2F%2Fw.example.com%2Fcallback"));
    }

    #[test]
    fn wowma_authorize_url_includes_scope_when_set() {
        let oauth = wowma(Arc::new(RecordingHttp::new("{}"))).with_scope("trade");
        let url = oauth.authorize_url("s");
        assert!(url.contains("scope=trade"));
    }

    #[tokio::test]
    async fn wowma_exchange_code_builds_request_and_parses_token() {
        let http = Arc::new(RecordingHttp::new(
            r#"{"access_token":"AT","refresh_token":"RT","expires_in":3600,"token_type":"Bearer"}"#,
        ));
        let oauth = wowma(http.clone());
        let token = oauth.exchange_code("c-1").await.unwrap();
        assert_eq!(token.access_token, "AT");
        assert_eq!(token.token_type.as_deref(), Some("Bearer"));

        let (url, _headers, form) = http.last.lock().unwrap().clone().unwrap();
        assert_eq!(url, WOWMA_TOKEN_ENDPOINT);
        assert!(form
            .iter()
            .any(|(k, v)| k == "grant_type" && v == "authorization_code"));
        assert!(form.iter().any(|(k, v)| k == "code" && v == "c-1"));
        assert!(form
            .iter()
            .any(|(k, v)| k == "client_id" && v == "wowma-client"));
    }

    #[tokio::test]
    async fn wowma_refresh_builds_request_and_parses_token() {
        let http = Arc::new(RecordingHttp::new(r#"{"access_token":"NEW2"}"#));
        let oauth = wowma(http.clone());
        let token = oauth.refresh("rt-w").await.unwrap();
        assert_eq!(token.access_token, "NEW2");

        let (_, _, form) = http.last.lock().unwrap().clone().unwrap();
        assert!(form
            .iter()
            .any(|(k, v)| k == "grant_type" && v == "refresh_token"));
        assert!(form
            .iter()
            .any(|(k, v)| k == "refresh_token" && v == "rt-w"));
    }

    #[tokio::test]
    async fn wowma_exchange_code_errors_on_missing_access_token() {
        let http = Arc::new(RecordingHttp::new(r#"{"error":"invalid_grant"}"#));
        let oauth = wowma(http);
        let err = oauth.exchange_code("bad").await.unwrap_err();
        assert!(matches!(err, AppError::ExternalApi { .. }));
    }
}
