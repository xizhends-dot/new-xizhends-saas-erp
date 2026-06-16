//! `CarrierTracker` 实现：日本国内承运商（佐川 / 日本邮政 / 大和）识别 + 查询。
//!
//! 复刻 old 行为（Task 13.3，对应需求 5.3 / 11.6）：
//! - `detect_carrier`：按**运单号前缀**识别承运商，采用「最长前缀优先」匹配，
//!   复刻 `old/inc/functions.php::findStrBestMatchValue`（按 key 长度降序、`strpos===0`）；
//!   前缀→承运商映射取自 `old/setting.ini` 的「日本快递公司」表。
//! - `track`：查询运单状态并归一化文本；当状态命中「配達完了 / お客様引渡完了」
//!   （日本邮政的「お届け済み」亦归一为「配達完了」）时返回 `completed_date`，
//!   复刻 `old/plugins/jpshipinfo/libs.php` 的状态归一逻辑。
//!
//! 实际 HTTP 查询通过可注入的异步边界 [`CarrierQuery`] 抽象，**不依赖 reqwest**，
//! 因此本模块可离线、确定性地进行单元测试。外部失败统一收敛为
//! [`AppError::ExternalApi`]（敏感细节只入日志、不外泄）。
//!
//! _Requirements: 5.3, 11.6_

use async_trait::async_trait;

use crate::error::AppError;
use crate::integrations::traits::{Carrier, CarrierTracker, TrackResult};

/// 默认「前缀 → 承运商」映射表。
///
/// 取自 `old/setting.ini` 的「日本快递公司」配置（`单号前缀=承运商名`）。
/// 注意映射存在「前缀包含关系」（如 `36`→佐川 而 `368`→大和），因此匹配必须
/// **最长前缀优先**，由 [`JpCarrierTracker::with_prefix_map`] 在构造时按长度降序排序保证。
const DEFAULT_PREFIX_MAP: &[(&str, Carrier)] = &[
    ("4905", Carrier::Sagawa),
    ("6557", Carrier::Yamato),
    ("6556", Carrier::Yamato),
    ("6555", Carrier::Yamato),
    ("5707", Carrier::Sagawa),
    ("5701", Carrier::Sagawa),
    ("6550", Carrier::Yamato),
    ("5702", Carrier::Sagawa),
    ("5706", Carrier::Sagawa),
    ("5068", Carrier::Yamato),
    ("5073", Carrier::Yamato),
    ("4398", Carrier::Yamato),
    ("5040", Carrier::Yamato),
    ("5070", Carrier::Yamato),
    ("4956", Carrier::Yamato),
    ("5064", Carrier::Yamato),
    ("5057", Carrier::Yamato),
    ("3642", Carrier::Sagawa),
    ("4952", Carrier::Yamato),
    ("4901", Carrier::Sagawa),
    ("4900", Carrier::Sagawa),
    ("4920", Carrier::Sagawa),
    ("7641", Carrier::Yamato),
    ("7648", Carrier::Yamato),
    ("7649", Carrier::Yamato),
    ("282", Carrier::Yamato),
    ("766", Carrier::Yamato),
    ("361", Carrier::Sagawa),
    ("363", Carrier::Sagawa),
    ("368", Carrier::Yamato),
    ("654", Carrier::Yamato),
    ("597", Carrier::Yamato),
    ("651", Carrier::Yamato),
    ("763", Carrier::Yamato),
    ("723", Carrier::JapanPost),
    ("35", Carrier::Sagawa),
    ("28", Carrier::Yamato),
    ("54", Carrier::Yamato),
    ("46", Carrier::Yamato),
    ("44", Carrier::Yamato),
    ("47", Carrier::Yamato),
    ("01", Carrier::Sagawa),
    ("56", Carrier::Sagawa),
    ("40", Carrier::Sagawa),
    ("51", Carrier::Sagawa),
    ("32", Carrier::JapanPost),
    ("52", Carrier::JapanPost),
    ("82", Carrier::JapanPost),
    ("42", Carrier::JapanPost),
    ("48", Carrier::Yamato),
    ("37", Carrier::Yamato),
    ("39", Carrier::Yamato),
    ("76", Carrier::Yamato),
    ("62", Carrier::JapanPost),
    ("36", Carrier::Sagawa),
];

/// 单次承运商查询的**原始**结果（HTTP 边界出参，未归一）。
///
/// `status` 为承运商页面/接口解析得到的原始状态文本；`completed_date` 为从同一响应中
/// 解析到的「完成日期」（如有）。归一与「是否保留完成日期」的判定由 [`JpCarrierTracker::track`] 完成。
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct RawTrack {
    /// 承运商返回的原始状态文本。
    pub status: String,
    /// 从响应中解析到的完成日期（如有）。
    pub completed_date: Option<String>,
}

/// 可注入的承运商查询边界（实际 HTTP 调用）。
///
/// 把网络访问从识别/归一逻辑中分离出来：生产实现用 HTTP 客户端访问各承运商页面，
/// 测试则注入桩实现以保持离线、确定性。失败统一返回 [`AppError::ExternalApi`]。
#[async_trait]
pub trait CarrierQuery: Send + Sync {
    /// 查询指定承运商的运单原始状态。
    async fn query(&self, carrier: Carrier, ship_number: &str) -> Result<RawTrack, AppError>;
}

/// 日本国内承运商跟踪器：前缀识别 + 状态归一。
///
/// 通过泛型注入 [`CarrierQuery`] 边界以解耦 HTTP。前缀映射在构造时按长度降序排序，
/// 保证 [`CarrierTracker::detect_carrier`] 的「最长前缀优先」语义。
pub struct JpCarrierTracker<Q: CarrierQuery> {
    /// 已按前缀长度降序排序的「前缀 → 承运商」映射。
    prefix_map: Vec<(String, Carrier)>,
    /// 实际查询边界。
    query: Q,
}

impl<Q: CarrierQuery> JpCarrierTracker<Q> {
    /// 使用内置默认前缀映射（取自 old `setting.ini`）构造。
    pub fn new(query: Q) -> Self {
        let map = DEFAULT_PREFIX_MAP
            .iter()
            .map(|(p, c)| ((*p).to_string(), *c))
            .collect();
        Self::with_prefix_map(query, map)
    }

    /// 使用自定义前缀映射构造（便于按租户配置覆盖）。
    ///
    /// 构造时按前缀长度降序排序，确保最长前缀优先匹配（复刻 `findStrBestMatchValue`）。
    pub fn with_prefix_map(query: Q, mut prefix_map: Vec<(String, Carrier)>) -> Self {
        prefix_map.sort_by(|a, b| b.0.len().cmp(&a.0.len()));
        Self { prefix_map, query }
    }
}

/// 去除字符串中的所有空白字符（复刻 old `preg_replace('/\s+/', '', ...)`）。
fn strip_whitespace(s: &str) -> String {
    s.chars().filter(|c| !c.is_whitespace()).collect()
}

/// 判定状态文本是否表示「已送达完成」。
///
/// 复刻 old：命中「配達完了」或「お客様引渡完了」即视为完成。
fn is_completed(status: &str) -> bool {
    status.contains("配達完了") || status.contains("お客様引渡完了")
}

/// 归一化承运商状态文本（复刻 `old/plugins/jpshipinfo/libs.php`）。
///
/// - 先移除所有空白与换行；
/// - 命中「お客様引渡完了」→ 统一为「お客様引渡完了」；
/// - 命中「配達完了」或日本邮政的「お届け済み」→ 统一为「配達完了」；
/// - 其余原样返回（已去空白）。
fn normalize_status(raw: &str) -> String {
    let s = strip_whitespace(raw);
    if s.contains("お客様引渡完了") {
        "お客様引渡完了".to_string()
    } else if s.contains("配達完了") || s.contains("お届け済み") {
        "配達完了".to_string()
    } else {
        s
    }
}

#[async_trait]
impl<Q: CarrierQuery> CarrierTracker for JpCarrierTracker<Q> {
    /// 由运单号前缀识别承运商（最长前缀优先，复刻 old `findStrBestMatchValue`）。
    ///
    /// 先去除运单号中的空白，再按已排序（长→短）的前缀表逐一前缀匹配，命中即返回；
    /// 无任何前缀命中（或运单号为空）时返回 `None`。
    fn detect_carrier(&self, ship_number: &str) -> Option<Carrier> {
        let s = strip_whitespace(ship_number);
        if s.is_empty() {
            return None;
        }
        self.prefix_map
            .iter()
            .find(|(prefix, _)| s.starts_with(prefix.as_str()))
            .map(|(_, carrier)| *carrier)
    }

    /// 查询并归一运单状态。
    ///
    /// 委托注入的 [`CarrierQuery`] 取得原始结果后：
    /// - 归一状态文本；空状态视为查询未命中（`success = false`）；
    /// - 仅当归一后状态表示完成时保留 `completed_date`，否则丢弃。
    async fn track(&self, carrier: Carrier, ship_number: &str) -> Result<TrackResult, AppError> {
        let raw = self.query.query(carrier, ship_number).await?;
        let status = normalize_status(&raw.status);

        if status.is_empty() {
            return Ok(TrackResult {
                success: false,
                status: String::new(),
                completed_date: None,
            });
        }

        let completed_date = if is_completed(&status) {
            raw.completed_date.clone()
        } else {
            None
        };

        Ok(TrackResult {
            success: true,
            status,
            completed_date,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    /// 测试桩：返回预设的 `RawTrack`，或在配置为错误时返回 `ExternalApi`。
    /// 同时记录最近一次被查询的 (carrier, ship_number)，便于断言透传。
    struct StubQuery {
        result: Result<RawTrack, ()>,
        last_call: Mutex<Option<(Carrier, String)>>,
    }

    impl StubQuery {
        fn ok(status: &str, completed_date: Option<&str>) -> Self {
            Self {
                result: Ok(RawTrack {
                    status: status.to_string(),
                    completed_date: completed_date.map(|s| s.to_string()),
                }),
                last_call: Mutex::new(None),
            }
        }

        fn err() -> Self {
            Self {
                result: Err(()),
                last_call: Mutex::new(None),
            }
        }
    }

    #[async_trait]
    impl CarrierQuery for StubQuery {
        async fn query(&self, carrier: Carrier, ship_number: &str) -> Result<RawTrack, AppError> {
            *self.last_call.lock().unwrap() = Some((carrier, ship_number.to_string()));
            self.result.clone().map_err(|_| AppError::ExternalApi {
                provider: "jpship-test".into(),
                detail: "stub error".into(),
            })
        }
    }

    fn tracker_for_detect() -> JpCarrierTracker<StubQuery> {
        JpCarrierTracker::new(StubQuery::ok("", None))
    }

    // ---- detect_carrier ----

    #[test]
    fn detect_sagawa_prefixes() {
        let t = tracker_for_detect();
        for n in ["4905123456", "01999999", "359999999", "569999", "369999"] {
            assert_eq!(t.detect_carrier(n), Some(Carrier::Sagawa), "{n}");
        }
    }

    #[test]
    fn detect_yamato_prefixes() {
        let t = tracker_for_detect();
        for n in ["655712345678", "289999999", "489999999", "766123456"] {
            assert_eq!(t.detect_carrier(n), Some(Carrier::Yamato), "{n}");
        }
    }

    #[test]
    fn detect_japanpost_prefixes() {
        let t = tracker_for_detect();
        for n in [
            "3211111111",
            "5222222222",
            "8233333333",
            "4244444444",
            "7235555555",
            "6266666666",
        ] {
            assert_eq!(t.detect_carrier(n), Some(Carrier::JapanPost), "{n}");
        }
    }

    #[test]
    fn detect_unknown_prefix_returns_none() {
        let t = tracker_for_detect();
        for n in ["99999999", "1234567890", "00111111", "abcdefg"] {
            assert_eq!(t.detect_carrier(n), None, "{n}");
        }
    }

    #[test]
    fn detect_empty_or_whitespace_returns_none() {
        let t = tracker_for_detect();
        assert_eq!(t.detect_carrier(""), None);
        assert_eq!(t.detect_carrier("   "), None);
    }

    #[test]
    fn detect_longest_prefix_wins() {
        let t = tracker_for_detect();
        // 36 -> Sagawa，但 368 -> Yamato：最长前缀优先。
        assert_eq!(t.detect_carrier("3689999999"), Some(Carrier::Yamato));
        assert_eq!(t.detect_carrier("3619999999"), Some(Carrier::Sagawa));
        assert_eq!(t.detect_carrier("3609999999"), Some(Carrier::Sagawa));
    }

    #[test]
    fn detect_ignores_surrounding_whitespace() {
        let t = tracker_for_detect();
        assert_eq!(t.detect_carrier("  4905 1234 "), Some(Carrier::Sagawa));
    }

    #[test]
    fn detect_additional_representative_prefixes() {
        // 每家承运商再覆盖若干代表性前缀（与上文用例不重复），
        // 进一步保证 detect_carrier 对完整 setting.ini 前缀表的识别面。
        let t = tracker_for_detect();
        // 佐川：4 位 / 3 位 / 2 位前缀各取代表。
        for n in [
            "5707000000",
            "3642000000",
            "4920000000",
            "3639999999",
            "4099999999",
            "5199999999",
        ] {
            assert_eq!(t.detect_carrier(n), Some(Carrier::Sagawa), "{n}");
        }
        // 大和：4 位 / 3 位 / 2 位前缀各取代表。
        for n in [
            "6556000000",
            "4398000000",
            "2829999999",
            "6549999999",
            "7639999999",
            "3799999999",
            "7699999999",
        ] {
            assert_eq!(t.detect_carrier(n), Some(Carrier::Yamato), "{n}");
        }
        // 日本邮政：4 位 / 2 位前缀代表。
        for n in ["7239999999", "6299999999"] {
            assert_eq!(t.detect_carrier(n), Some(Carrier::JapanPost), "{n}");
        }
    }

    #[test]
    fn detect_ship_number_equal_to_prefix() {
        // 边界：运单号恰好等于某前缀本身（无后续字符）仍应命中。
        let t = tracker_for_detect();
        assert_eq!(t.detect_carrier("4905"), Some(Carrier::Sagawa));
        assert_eq!(t.detect_carrier("282"), Some(Carrier::Yamato));
        assert_eq!(t.detect_carrier("723"), Some(Carrier::JapanPost));
        // 边界：比最短前缀（2 位）还短的运单号无法命中任何前缀。
        assert_eq!(t.detect_carrier("3"), None);
    }

    // ---- track ----

    #[tokio::test]
    async fn track_completed_keeps_date() {
        let t = JpCarrierTracker::new(StubQuery::ok("配達完了", Some("2024-01-02 10:00:00")));
        let r = t.track(Carrier::Sagawa, "4905123456").await.unwrap();
        assert!(r.success);
        assert_eq!(r.status, "配達完了");
        assert_eq!(r.completed_date.as_deref(), Some("2024-01-02 10:00:00"));
    }

    #[tokio::test]
    async fn track_normalizes_otodoke_to_completed() {
        // 日本邮政「お届け済み」应归一为「配達完了」。
        let t = JpCarrierTracker::new(StubQuery::ok("お届け済み", Some("2024-03-04")));
        let r = t.track(Carrier::JapanPost, "3211111111").await.unwrap();
        assert!(r.success);
        assert_eq!(r.status, "配達完了");
        assert_eq!(r.completed_date.as_deref(), Some("2024-03-04"));
    }

    #[tokio::test]
    async fn track_normalizes_otokyaku_hikiwatashi() {
        let t = JpCarrierTracker::new(StubQuery::ok(
            "お客様引渡完了\n【宅配BOX】",
            Some("2024-05-06"),
        ));
        let r = t.track(Carrier::Yamato, "655712345678").await.unwrap();
        assert!(r.success);
        assert_eq!(r.status, "お客様引渡完了");
        assert_eq!(r.completed_date.as_deref(), Some("2024-05-06"));
    }

    #[tokio::test]
    async fn track_in_transit_drops_completed_date() {
        // 未完成状态即便上游误带 completed_date 也必须丢弃。
        let t = JpCarrierTracker::new(StubQuery::ok("配達中", Some("2024-01-02")));
        let r = t.track(Carrier::Sagawa, "4905123456").await.unwrap();
        assert!(r.success);
        assert_eq!(r.status, "配達中");
        assert_eq!(r.completed_date, None);
    }

    #[tokio::test]
    async fn track_empty_status_is_failure() {
        let t = JpCarrierTracker::new(StubQuery::ok("   ", None));
        let r = t.track(Carrier::JapanPost, "3211111111").await.unwrap();
        assert!(!r.success);
        assert_eq!(r.status, "");
        assert_eq!(r.completed_date, None);
    }

    #[tokio::test]
    async fn track_propagates_external_error() {
        let t = JpCarrierTracker::new(StubQuery::err());
        let err = t.track(Carrier::Sagawa, "4905123456").await.unwrap_err();
        assert!(matches!(err, AppError::ExternalApi { .. }));
    }

    #[tokio::test]
    async fn track_forwards_carrier_and_ship_number() {
        let t = JpCarrierTracker::new(StubQuery::ok("配達中", None));
        let _ = t.track(Carrier::Yamato, "655712345678").await.unwrap();
        let call = t.query.last_call.lock().unwrap().clone();
        assert_eq!(call, Some((Carrier::Yamato, "655712345678".to_string())));
    }
}
