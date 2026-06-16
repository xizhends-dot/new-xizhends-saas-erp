//! 外部能力 trait 契约（Task 13.1，对应设计 6.2）。
//!
//! 本模块只定义**契约**：每类外部能力一个 `#[async_trait]` trait，以及其入参/出参所需的
//! 数据结构。各平台/承运商/邮件网关的**具体实现**分别在：
//! - [`PurchaseProvider`] => `purchase_1688.rs`（Task 13.2）
//! - [`PlatformOAuth`] => `oauth_yahoo.rs` / `oauth_rakuten.rs` / `oauth_wowma.rs`（Task 13.5）
//! - [`CarrierTracker`] => `ship_jp.rs`（Task 13.3）
//! - [`MailGateway`] => `mail.rs`（Task 13.6）
//!
//! 所有方法统一返回 `Result<_, AppError>`（见 `crate::error`），外部调用失败收敛为
//! [`AppError::ExternalApi`]，敏感细节只入日志、绝不外泄。
//!
//! _Requirements: 6.3, 11.6_

use async_trait::async_trait;
use serde::{Deserialize, Serialize};

use crate::error::AppError;

// ============================================================================
// 1688 采购能力（对应 old/plugins/1688api）
// ============================================================================

/// 1688 物流轨迹查询结果（`query_logistics` 出参）。
///
/// 复刻 `logisticstatus` + `logisticstrace`：`status` 为归一后的物流状态，
/// `steps` 为逐条轨迹（时间 + 描述），`signed` 标识是否已签收（二次判定后的结论）。
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct LogisticsTrace {
    /// 物流状态（如「运输中」「已签收」）。
    pub status: String,
    /// 轨迹明细，按时间顺序。
    pub steps: Vec<LogisticsStep>,
    /// 是否已签收（经轨迹关键词二次判定）。
    pub signed: bool,
}

/// 单条物流轨迹（时间 + 描述）。
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct LogisticsStep {
    /// 轨迹时间点（原样保留平台返回的时间字符串）。
    pub time: String,
    /// 轨迹描述文本。
    pub description: String,
}

/// 1688 商品信息（`fetch_product` 出参）。
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct ProductInfo {
    /// 1688 商品 ID（item_id）。
    pub item_id: String,
    /// 商品标题。
    pub title: String,
    /// 主图 URL 列表。
    pub image_urls: Vec<String>,
    /// 价格（原样字符串，避免精度/币种歧义）。
    pub price: Option<String>,
}

/// 1688 采购能力：物流查询与商品抓取。
///
/// 实现侧负责凭证轮换（`apikeys.conf` 多组 `key|token|username`）与有界重试，
/// 见 Task 13.2。
#[async_trait]
pub trait PurchaseProvider: Send + Sync {
    /// 查询 1688 订单物流（凭证轮换：apikeys.conf 多组 key|token|username）。
    async fn query_logistics(&self, order_no: &str) -> Result<LogisticsTrace, AppError>;

    /// 抓取 1688 商品信息（标题/主图/价格）。
    async fn fetch_product(&self, item_id: &str) -> Result<ProductInfo, AppError>;
}

// ============================================================================
// 平台 OAuth（Yahoo / 乐天 RMS / Wowma）
// ============================================================================

/// OAuth 令牌集合（`exchange_code` / `refresh` 出参）。
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct TokenSet {
    /// 访问令牌。
    pub access_token: String,
    /// 刷新令牌（部分平台可能为空）。
    pub refresh_token: Option<String>,
    /// 有效期（秒）。
    pub expires_in: Option<i64>,
    /// 令牌类型（如 `Bearer`）。
    pub token_type: Option<String>,
}

/// 平台 OAuth（Yahoo / 乐天 RMS / Wowma）。
///
/// 各平台授权端点/参数不同，但共享同一套「授权 URL → 换码 → 刷新」流程，见 Task 13.5。
#[async_trait]
pub trait PlatformOAuth: Send + Sync {
    /// 构造授权跳转 URL（携带 `state` 防 CSRF）。
    fn authorize_url(&self, state: &str) -> String;

    /// 用授权码换取令牌集合。
    async fn exchange_code(&self, code: &str) -> Result<TokenSet, AppError>;

    /// 用刷新令牌续期。
    async fn refresh(&self, refresh_token: &str) -> Result<TokenSet, AppError>;
}

// ============================================================================
// 日本国内承运商查询（佐川 / 日本邮政 / 大和）
// ============================================================================

/// 日本国内承运商（由运单号前缀识别）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash, Serialize, Deserialize)]
pub enum Carrier {
    /// 佐川急便（Sagawa）。
    Sagawa,
    /// 日本邮政（Japan Post）。
    JapanPost,
    /// 大和运输（Yamato）。
    Yamato,
}

/// 承运商查询结果（`track` 出参，对应 old `jpshipdetails`）。
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct TrackResult {
    /// 查询是否成功（拿到有效状态）。
    pub success: bool,
    /// 物流状态文本（jpshipdetails）。
    pub status: String,
    /// 配達完了（送达完成）时间，仅命中完成态时存在。
    pub completed_date: Option<String>,
}

/// 日本国内承运商查询（佐川/日本邮政/大和），由运单号前缀识别。
#[async_trait]
pub trait CarrierTracker: Send + Sync {
    /// 由运单号前缀识别承运商（复刻 old `detect_carrier` 前缀匹配）。
    fn detect_carrier(&self, ship_number: &str) -> Option<Carrier>;

    /// 查询指定承运商的运单状态。
    async fn track(&self, carrier: Carrier, ship_number: &str) -> Result<TrackResult, AppError>;
}

// ============================================================================
// 客服邮件聚合（IMAP 拉取 + SMTP 发信，对应 old/kefu_mail）
// ============================================================================

/// 邮箱账户配置（IMAP/SMTP 连接所需）。
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct MailAccount {
    /// 账户标识（与 `--account` 参数一致）。
    pub account: String,
    /// 登录邮箱地址。
    pub email: String,
    /// IMAP 主机。
    pub imap_host: String,
    /// SMTP 主机。
    pub smtp_host: String,
}

/// 邮件文件夹（IMAP folder）。
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct MailFolder {
    /// 文件夹名称（如 `INBOX`、`Sent`）。
    pub name: String,
    /// 该文件夹已同步到的 UID 游标（增量同步起点）。
    pub last_uid: u32,
}

/// 文件夹同步报告（`sync_folder` 出参）。
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct SyncReport {
    /// 本次新增的邮件数量。
    pub new_count: u32,
    /// 同步后的最新 UID 游标。
    pub last_uid: u32,
}

/// 回复结果（`reply` 出参）。
#[derive(Debug, Clone, Default, Serialize, Deserialize)]
pub struct ReplyResult {
    /// SMTP 是否发送成功。
    pub sent: bool,
    /// 是否已 IMAP APPEND 写回 Sent。
    pub appended_to_sent: bool,
    /// 平台返回的消息 ID（如有）。
    pub message_id: Option<String>,
}

/// 客服邮件聚合（IMAP 拉取 + SMTP 发信，对应 old kefu_mail/）。
#[async_trait]
pub trait MailGateway: Send + Sync {
    /// 列出账户下的所有文件夹。
    async fn list_folders(&self, account: &MailAccount) -> Result<Vec<MailFolder>, AppError>;

    /// 增量只拉邮件头（`last_uid` 游标）。
    async fn sync_folder(&self, folder: &MailFolder, limit: u32) -> Result<SyncReport, AppError>;

    /// 懒加载正文并缓存回库（body_loaded）。
    async fn load_body(&self, msg_id: i64) -> Result<String, AppError>;

    /// SMTP 发送回复 + IMAP APPEND 写回 Sent。
    async fn reply(&self, msg_id: i64, body: &str) -> Result<ReplyResult, AppError>;
}
