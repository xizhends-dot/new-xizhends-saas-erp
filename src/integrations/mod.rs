//! 外部能力适配（trait 契约 + 各平台/承运商实现）。
//!
//! trait 契约见 [`traits`]（Task 13.1）；各平台/承运商实现见对应子模块。

pub mod traits;

pub mod mail;
pub mod oauth_rakuten;
pub mod oauth_wowma;
pub mod oauth_yahoo;
pub mod purchase_1688;
pub mod rakuten_rms;
pub mod ship_jp;

// 重导出 trait 契约与配套数据类型，便于实现侧与调用侧统一引用。
pub use traits::{
    Carrier, CarrierTracker, LogisticsStep, LogisticsTrace, MailAccount, MailFolder, MailGateway,
    PlatformOAuth, ProductInfo, PurchaseProvider, ReplyResult, SyncReport, TokenSet, TrackResult,
};
