//! `PurchaseProvider`（1688）实现：`query_logistics` / `fetch_product`，
//! **凭证轮换 +有界重试**，失败收敛为 [`AppError::ExternalApi`]（记样本、不中断整批）。
//!
//! 复刻 old `plugins/1688api`（`func.php` + `apikeys.conf`）与
//! `cron/update_1688_logistics.php` 的两段核心逻辑：
//!
//! 1. **凭证轮换**：`apikeys.conf` 存在多组账号（同一账号也可有多个 token），
//!    单组凭证调用失败（权限/限流/过期）时自动切换到下一组凭证重试，对应 PHP 中
//!    `get_all_cg_user_configs()` + 遍历重试逻辑。本实现以 [`Credential`] 列表 +
//!    轮换游标实现，跨调用「黏住」上一次可用凭证，失败时前进游标。
//!
//! 2. **签收二次判定**（`logisticsTrace_hasWord`）：接口返回「已签收」并不直接采信，
//!    需在物流轨迹中命中签收类关键词才确认签收；命中不到则回落「运输中」；
//!    完全没有轨迹则清空状态。见 [`determine_signed_status`]。
//!
//! ## HTTP 边界（可注入 seam）
//!
//! 当前 `Cargo.toml` 未引入 HTTP 客户端（如 `reqwest`），因此真实的 1688 OpenAPI
//! 调用被抽象到 [`Logistics1688Api`] trait 之后。`Purchase1688` 只负责
//! **凭证轮换 + 有界重试 + 签收判定**这类纯业务编排，对网络无依赖、可离线测试。
//! 将来接入真实 HTTP 客户端时，只需为某个 transport 类型实现 [`Logistics1688Api`]
//! 并注入 `Purchase1688::new(...)` 即可，无需改动编排与判定逻辑。
//!
//! _Requirements: 6.3, 6.4_

use std::sync::atomic::{AtomicUsize, Ordering};

use async_trait::async_trait;

use crate::error::AppError;
use crate::integrations::traits::{LogisticsStep, LogisticsTrace, ProductInfo, PurchaseProvider};

/// 错误日志中标识来源平台。
pub const PROVIDER_NAME: &str = "1688";

/// 默认有界重试上限（attempt 数）。轮换游标会在每次失败后前进，
/// 当 `max_attempts > 凭证组数` 时即对凭证列表循环重试。
pub const DEFAULT_MAX_ATTEMPTS: usize = 3;

/// 签收判定关键词（复刻 `update_1688_logistics.php` 的 `logisticsTrace_hasWord` 列表）。
/// 接口返回「已签收」时，只有当轨迹文本命中其中任一关键词，才确认真正签收。
const SIGNED_KEYWORDS: &[&str] = &[
    "签收",
    "妈妈",
    "自提点",
    "派送成功",
    "菜鸟驿站",
    "兔喜快递超市",
    "已投递",
    "已妥投",
    "喵站",
    "兔喜生活",
];

/// 接口返回的「已签收」字面值。
const STATUS_SIGNED: &str = "已签收";
/// 二次判定回落的「运输中」字面值。
const STATUS_IN_TRANSIT: &str = "运输中";

// ============================================================================
// 凭证
// ============================================================================

/// 一组 1688 API 凭证（对应 `apikeys.conf` 的一行）。
///
/// 任务约定的最小三元组为 `key | token | username`；真实 1688 OpenAPI 另需 `secret`
/// 参与签名，故此处保留可选的 `secret` 字段（`apikeys.conf` 为
/// `账号名 app_key app_secret access_token` 四列）。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Credential {
    /// APP_KEY。
    pub key: String,
    /// APP_SECRET（参与签名；离线/测试场景可为空）。
    pub secret: Option<String>,
    /// ACCESS_TOKEN。
    pub token: String,
    /// 账号名（apikeys.conf 的账号名称，用于日志与归属）。
    pub username: String,
}

impl Credential {
    /// 显式构造一组凭证。
    pub fn new(
        key: impl Into<String>,
        secret: Option<String>,
        token: impl Into<String>,
        username: impl Into<String>,
    ) -> Self {
        Self {
            key: key.into(),
            secret,
            token: token.into(),
            username: username.into(),
        }
    }

    /// 解析竖线分隔的凭证串：
    /// - 三段 `key|token|username`（任务约定的最小形态，`secret` 置空）；
    /// - 四段 `key|secret|token|username`（含签名密钥）。
    ///
    /// 段数不符或关键字段为空时返回 [`AppError::Validation`]。
    pub fn parse(line: &str) -> Result<Self, AppError> {
        let parts: Vec<&str> = line.split('|').map(|p| p.trim()).collect();
        let cred = match parts.as_slice() {
            [key, token, username] => Credential::new(
                (*key).to_string(),
                None,
                (*token).to_string(),
                (*username).to_string(),
            ),
            [key, secret, token, username] => Credential::new(
                (*key).to_string(),
                Some((*secret).to_string()),
                (*token).to_string(),
                (*username).to_string(),
            ),
            _ => {
                return Err(AppError::Validation(
                    "凭证格式应为 key|token|username 或 key|secret|token|username".to_string(),
                ))
            }
        };
        if cred.key.is_empty() || cred.token.is_empty() {
            return Err(AppError::Validation("凭证 key/token 不能为空".to_string()));
        }
        Ok(cred)
    }

    /// 批量解析多行凭证（忽略空行与 `#` 注释行，复刻 `apikeys.conf` 习惯）。
    pub fn parse_many(text: &str) -> Result<Vec<Credential>, AppError> {
        text.lines()
            .map(|l| l.trim())
            .filter(|l| !l.is_empty() && !l.starts_with('#'))
            .map(Credential::parse)
            .collect()
    }
}

// ============================================================================
// HTTP 边界（可注入 seam）
// ============================================================================

/// 单组凭证下、单次 1688 物流接口的原始返回（解析后、判定前）。
#[derive(Debug, Clone, Default)]
pub struct RawLogistics {
    /// 接口原始物流状态（如「运输中」「已签收」）。
    pub status: String,
    /// 物流轨迹明细。
    pub steps: Vec<LogisticsStep>,
}

/// 1688 OpenAPI 传输层抽象（**HTTP seam**）。
///
/// `Purchase1688` 通过本 trait 发起单次调用，自身只负责凭证轮换、有界重试与签收判定。
/// 真实实现接入 HTTP 客户端即可；测试用桩可在离线模拟成功/失败/轮换。
///
/// 约定：`Err(detail)` 表示**本次凭证调用失败**（权限/限流/过期/网络），
/// `detail` 仅用于日志，最终会被收敛进 [`AppError::ExternalApi`] 的 `detail`，不外泄客户端。
#[async_trait]
pub trait Logistics1688Api: Send + Sync {
    /// 用指定凭证查询某 1688 订单的物流。
    async fn fetch_logistics(
        &self,
        cred: &Credential,
        order_no: &str,
    ) -> Result<RawLogistics, String>;

    /// 用指定凭证抓取某 1688 商品信息。
    async fn fetch_product(&self, cred: &Credential, item_id: &str) -> Result<ProductInfo, String>;
}

// ============================================================================
// 签收二次判定（纯函数，便于单测）
// ============================================================================

/// 物流状态二次判定，复刻 `update_1688_logistics.php`：
/// - 接口非「已签收」：原样返回，`signed = false`；
/// - 接口「已签收」且轨迹命中签收关键词：确认签收 `("已签收", true)`；
/// - 接口「已签收」但轨迹存在却无签收关键词：回落 `("运输中", false)`；
/// - 接口「已签收」但**无任何轨迹**：清空 `("", false)`。
pub fn determine_signed_status(raw_status: &str, steps: &[LogisticsStep]) -> (String, bool) {
    if raw_status != STATUS_SIGNED {
        return (raw_status.to_string(), false);
    }
    if steps.is_empty() {
        // 接口返回[已签收]但没有物流轨迹 -> 重设为空
        return (String::new(), false);
    }
    let has_signed_word = steps
        .iter()
        .any(|s| SIGNED_KEYWORDS.iter().any(|kw| s.description.contains(kw)));
    if has_signed_word {
        (STATUS_SIGNED.to_string(), true)
    } else {
        // 接口返回[已签收]但经过二次判定重设为[运输中]
        (STATUS_IN_TRANSIT.to_string(), false)
    }
}

// ============================================================================
// Purchase1688
// ============================================================================

/// 1688 采购能力实现：持有一组凭证 + 可注入 transport，负责凭证轮换与有界重试。
pub struct Purchase1688<T: Logistics1688Api> {
    credentials: Vec<Credential>,
    transport: T,
    max_attempts: usize,
    /// 轮换游标：跨调用「黏住」上一次可用凭证，失败时前进。
    cursor: AtomicUsize,
}

impl<T: Logistics1688Api> Purchase1688<T> {
    /// 用凭证列表与 transport 构造，采用默认重试上限。
    ///
    /// 凭证列表为空时返回 [`AppError::Validation`]（不 panic）。
    pub fn new(credentials: Vec<Credential>, transport: T) -> Result<Self, AppError> {
        Self::with_max_attempts(credentials, transport, DEFAULT_MAX_ATTEMPTS)
    }

    /// 同 [`Purchase1688::new`]，但显式指定有界重试上限（至少为 1）。
    pub fn with_max_attempts(
        credentials: Vec<Credential>,
        transport: T,
        max_attempts: usize,
    ) -> Result<Self, AppError> {
        if credentials.is_empty() {
            return Err(AppError::Validation("1688 凭证列表不能为空".to_string()));
        }
        Ok(Self {
            credentials,
            transport,
            max_attempts: max_attempts.max(1),
            cursor: AtomicUsize::new(0),
        })
    }

    /// 当前轮换游标对应的凭证下标（对凭证组数取模）。
    fn current_index(&self) -> usize {
        self.cursor.load(Ordering::Relaxed) % self.credentials.len()
    }

    /// 失败后前进轮换游标，切换到下一组凭证。
    fn advance(&self) {
        self.cursor.fetch_add(1, Ordering::Relaxed);
    }
}

#[async_trait]
impl<T: Logistics1688Api> PurchaseProvider for Purchase1688<T> {
    async fn query_logistics(&self, order_no: &str) -> Result<LogisticsTrace, AppError> {
        let mut last_detail = String::from("no attempt made");
        for _ in 0..self.max_attempts {
            let cred = &self.credentials[self.current_index()];
            match self.transport.fetch_logistics(cred, order_no).await {
                Ok(raw) => {
                    let (status, signed) = determine_signed_status(&raw.status, &raw.steps);
                    return Ok(LogisticsTrace {
                        status,
                        steps: raw.steps,
                        signed,
                    });
                }
                Err(detail) => {
                    // 记下失败细节（仅入日志），凭证轮换后继续重试。
                    last_detail = detail;
                    self.advance();
                }
            }
        }
        // 有界重试 + 凭证轮换全部耗尽：收敛为 ExternalApi。
        // 调用侧（批处理）据此记失败样本并继续下一条，不中断整批（Req 6.4）。
        Err(AppError::ExternalApi {
            provider: PROVIDER_NAME.to_string(),
            detail: format!(
                "query_logistics({order_no}) exhausted after {} attempt(s): {last_detail}",
                self.max_attempts
            ),
        })
    }

    async fn fetch_product(&self, item_id: &str) -> Result<ProductInfo, AppError> {
        let mut last_detail = String::from("no attempt made");
        for _ in 0..self.max_attempts {
            let cred = &self.credentials[self.current_index()];
            match self.transport.fetch_product(cred, item_id).await {
                Ok(product) => return Ok(product),
                Err(detail) => {
                    last_detail = detail;
                    self.advance();
                }
            }
        }
        Err(AppError::ExternalApi {
            provider: PROVIDER_NAME.to_string(),
            detail: format!(
                "fetch_product({item_id}) exhausted after {} attempt(s): {last_detail}",
                self.max_attempts
            ),
        })
    }
}

// ============================================================================
// 测试
// ============================================================================

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    fn step(desc: &str) -> LogisticsStep {
        LogisticsStep {
            time: "2024-01-01 00:00:00".to_string(),
            description: desc.to_string(),
        }
    }

    fn cred(token: &str) -> Credential {
        Credential::new("appkey", Some("secret".to_string()), token, "acct")
    }

    /// 可配置的离线传输桩：只有 token 在 `success_tokens` 中时调用成功，
    /// 其余返回失败；记录每次实际使用的凭证 token，用于断言轮换行为。
    struct MockApi {
        success_tokens: Vec<String>,
        calls: Mutex<Vec<String>>,
        raw_status: String,
        steps: Vec<LogisticsStep>,
    }

    impl MockApi {
        fn new(success_tokens: &[&str], raw_status: &str, steps: Vec<LogisticsStep>) -> Self {
            Self {
                success_tokens: success_tokens.iter().map(|s| s.to_string()).collect(),
                calls: Mutex::new(Vec::new()),
                raw_status: raw_status.to_string(),
                steps,
            }
        }

        fn calls(&self) -> Vec<String> {
            self.calls.lock().unwrap().clone()
        }
    }

    #[async_trait]
    impl Logistics1688Api for MockApi {
        async fn fetch_logistics(
            &self,
            cred: &Credential,
            _order_no: &str,
        ) -> Result<RawLogistics, String> {
            self.calls.lock().unwrap().push(cred.token.clone());
            if self.success_tokens.contains(&cred.token) {
                Ok(RawLogistics {
                    status: self.raw_status.clone(),
                    steps: self.steps.clone(),
                })
            } else {
                Err(format!("auth failed for token {}", cred.token))
            }
        }

        async fn fetch_product(
            &self,
            cred: &Credential,
            item_id: &str,
        ) -> Result<ProductInfo, String> {
            self.calls.lock().unwrap().push(cred.token.clone());
            if self.success_tokens.contains(&cred.token) {
                Ok(ProductInfo {
                    item_id: item_id.to_string(),
                    title: "测试商品".to_string(),
                    image_urls: vec!["https://example.com/a.jpg".to_string()],
                    price: Some("9.90".to_string()),
                })
            } else {
                Err(format!("auth failed for token {}", cred.token))
            }
        }
    }

    // ---- 凭证解析 ----

    #[test]
    fn parse_three_field_credential() {
        let c = Credential::parse("KEY1|TOKEN1|alice").unwrap();
        assert_eq!(c.key, "KEY1");
        assert_eq!(c.token, "TOKEN1");
        assert_eq!(c.username, "alice");
        assert_eq!(c.secret, None);
    }

    #[test]
    fn parse_four_field_credential_with_secret() {
        let c = Credential::parse("KEY1|SECRET1|TOKEN1|alice").unwrap();
        assert_eq!(c.secret.as_deref(), Some("SECRET1"));
        assert_eq!(c.token, "TOKEN1");
    }

    #[test]
    fn parse_rejects_malformed_and_empty_fields() {
        assert!(Credential::parse("only_two|fields").is_err());
        assert!(Credential::parse("|TOKEN|user").is_err());
    }

    #[test]
    fn parse_many_skips_comments_and_blanks() {
        let text = "#comment\n\nK1|T1|u1\nK2|S2|T2|u2\n";
        let creds = Credential::parse_many(text).unwrap();
        assert_eq!(creds.len(), 2);
        assert_eq!(creds[0].token, "T1");
        assert_eq!(creds[1].token, "T2");
    }

    // ---- 凭证轮换：失败后前进到下一组凭证 ----

    #[tokio::test]
    async fn rotation_advances_credential_on_failure() {
        // 第一组凭证失败、第二组成功：应自动轮换并最终成功。
        let api = MockApi::new(&["t2"], "运输中", vec![step("运输途中")]);
        let provider =
            Purchase1688::with_max_attempts(vec![cred("t1"), cred("t2")], api, 3).unwrap();

        let trace = provider.query_logistics("ORDER-1").await.unwrap();
        assert_eq!(trace.status, "运输中");

        // 断言确实先用 t1（失败）再轮换到 t2（成功）。
        let calls = provider.transport.calls();
        assert_eq!(calls, vec!["t1".to_string(), "t2".to_string()]);
    }

    // ---- 有界重试耗尽：返回 ExternalApi（不 panic、不中断整批） ----

    #[tokio::test]
    async fn exhausted_retries_return_external_api_error() {
        // 没有任何凭证会成功，3 次重试后应返回 ExternalApi。
        let api = MockApi::new(&[], "运输中", vec![]);
        let provider =
            Purchase1688::with_max_attempts(vec![cred("t1"), cred("t2")], api, 3).unwrap();

        let err = provider.query_logistics("ORDER-X").await.unwrap_err();
        match err {
            AppError::ExternalApi {
                provider: p,
                detail,
            } => {
                assert_eq!(p, PROVIDER_NAME);
                assert!(detail.contains("exhausted"));
            }
            other => panic!("expected ExternalApi, got {other:?}"),
        }
        // 确认进行了有界次数（3 次）尝试，且对两组凭证做了轮换。
        assert_eq!(provider.transport.calls().len(), 3);
    }

    #[tokio::test]
    async fn fetch_product_exhaustion_also_returns_external_api() {
        let api = MockApi::new(&[], "", vec![]);
        let provider = Purchase1688::with_max_attempts(vec![cred("t1")], api, 2).unwrap();
        let err = provider.fetch_product("ITEM-1").await.unwrap_err();
        assert!(matches!(err, AppError::ExternalApi { .. }));
    }

    #[tokio::test]
    async fn empty_credentials_is_rejected_not_panicked() {
        let api = MockApi::new(&[], "", vec![]);
        let res = Purchase1688::new(Vec::new(), api);
        assert!(matches!(res, Err(AppError::Validation(_))));
    }

    // ---- 签收二次判定 ----

    #[test]
    fn signed_confirmed_when_trace_has_signed_keyword() {
        let steps = vec![step("您的快件已签收，签收人：张三")];
        let (status, signed) = determine_signed_status("已签收", &steps);
        assert_eq!(status, "已签收");
        assert!(signed);
    }

    #[test]
    fn signed_downgraded_to_in_transit_when_no_keyword() {
        let steps = vec![step("运输途中，已到达上海转运中心")];
        let (status, signed) = determine_signed_status("已签收", &steps);
        assert_eq!(status, "运输中");
        assert!(!signed);
    }

    #[test]
    fn signed_cleared_when_no_trace_steps() {
        let (status, signed) = determine_signed_status("已签收", &[]);
        assert_eq!(status, "");
        assert!(!signed);
    }

    #[test]
    fn non_signed_status_passes_through_unsigned() {
        let steps = vec![step("已揽收")];
        let (status, signed) = determine_signed_status("运输中", &steps);
        assert_eq!(status, "运输中");
        assert!(!signed);
    }

    #[tokio::test]
    async fn query_logistics_applies_signed_determination_on_success() {
        // 接口报「已签收」但轨迹无签收词 -> 结果应被判定为「运输中」且 signed=false。
        let api = MockApi::new(&["t1"], "已签收", vec![step("运输途中")]);
        let provider = Purchase1688::new(vec![cred("t1")], api).unwrap();
        let trace = provider.query_logistics("ORDER-2").await.unwrap();
        assert_eq!(trace.status, "运输中");
        assert!(!trace.signed);
    }
}
