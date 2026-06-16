//! 定时任务：更新日本境内/国际物流，`jpship_completed_at` 仅首次写入（Task 14.3）。
//!
//! 忠实复刻 `old/cron/update_jpship_logistics.php` 的 `run_platform` 语义
//! （见 `design.md` 6.4 伪代码）：
//!
//! 1. **状态过滤**：仅处理 `purchase_status ∈ {已发货代订单, 已发日本, 已发出荷通知}`；
//!    平台为乐天（`r`）时额外纳入「日本仓库已处理」（Requirements 5.4）。
//! 2. **多运单号拆分**：逗号分隔的运单号经 [`split_ship_numbers`] 拆分后逐一查询
//!    （Requirements 5.1）。
//! 3. **承运商识别**：每个运单号由 [`CarrierTracker::detect_carrier`] 前缀匹配承运商，
//!    无法识别则跳过（Requirements 5.3）。
//! 4. **随机取一**：在「有物流状态结果」的查询里随机取一个作为该子商品的物流状态
//!    （复刻 PHP `array_rand`，Requirements 5.5）；随机源被抽象为可注入的
//!    [`IndexSelector`]，使其在测试中确定可复现。
//! 5. **完成时间仅首次写入**：选中状态命中「配達完了 / お客様引渡完了」且
//!    `jpship_completed_at` 为空时写入完成时间（物流无日期时以当前时间兜底），
//!    已有值保持不变——幂等由 [`order_repo::update_jpship`] 的 `CASE` 在 SQL 层保证
//!    （Requirements 5.2 / Property 9）。
//!
//! ## 关于乐天的「日本仓库已处理」
//! 新系统的 [`PurchaseStatus`]（设计 3.6）**不含**「日本仓库已处理」取值——该状态属
//! 平台特有语义，统一模型把它下沉到 `platform_extra` JSON（设计 3.4）。因此
//! [`status_filter_for_platform`] 把它作为「额外原始状态字符串」单独返回
//! （[`StatusFilter::extra_raw`]）并予以保留/文档化，而 [`order_repo::find_pending_intl`]
//! 只接受强类型的 [`PurchaseStatus`] 列表，故当前取数仅按基础三状态过滤。
//!
//! ## 可测试性接缝
//! 触达网络的承运商查询收敛在 [`CarrierTracker`] trait 之后、随机取一收敛在
//! [`IndexSelector`] trait 之后，二者均以 `dyn` 注入；核心判定逻辑
//! （[`query_valid_results`] / [`pick_result`] / [`resolve_completion`]）不依赖 DB，
//! 因此可在**无真实 MySQL / 无网络**前提下单测。
//!
//! _Requirements: 5.2, 5.4, 5.5_

use std::sync::Arc;
use std::time::{Duration, SystemTime, UNIX_EPOCH};

use async_trait::async_trait;
use sqlx::types::chrono::NaiveDateTime;

use crate::error::AppError;
use crate::integrations::traits::{CarrierTracker, TrackResult};
use crate::jobs::scheduler::{Job, JobContext};
use crate::models::order::PurchaseStatus;
use crate::repository::order_repo;
use crate::services::order_service::split_ship_numbers;

// ============================================================================
// 完成态关键词（复刻 PHP `strpos($status, '配達完了') / 'お客様引渡完了'`）
// ============================================================================

/// 物流「完成」关键词：状态文本包含其一即视为送达完成。
const COMPLETED_KEYWORDS: [&str; 2] = ["配達完了", "お客様引渡完了"];

/// 完成时间兜底解析尝试的时间格式（物流返回的日期字符串形态不一）。
const COMPLETED_DATE_FORMATS: [&str; 4] = [
    "%Y-%m-%d %H:%M:%S",
    "%Y/%m/%d %H:%M:%S",
    "%Y-%m-%d",
    "%Y/%m/%d",
];

// ============================================================================
// 平台状态过滤（复刻 run_platform 步骤 1）
// ============================================================================

/// 平台状态过滤结果：强类型的基础流程状态 + 平台特有的额外原始状态字符串。
///
/// `statuses` 可直接传给 [`order_repo::find_pending_intl`]；`extra_raw` 承载无法映射到
/// [`PurchaseStatus`] 的平台特有状态（当前仅乐天的「日本仓库已处理」），予以保留以忠实
/// 复刻 old 语义、并供未来按 `platform_extra` 过滤时取用。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StatusFilter {
    /// 基础流程状态（所有平台共有）：已发货代订单 / 已发日本 / 已发出荷通知。
    pub statuses: Vec<PurchaseStatus>,
    /// 平台特有的额外原始状态字符串（乐天追加「日本仓库已处理」；其它平台为空）。
    pub extra_raw: Vec<&'static str>,
}

/// 复刻 `run_platform` 的状态过滤：基础三状态，乐天（`r`）额外含「日本仓库已处理」。
///
/// **纯函数**，便于单测（Requirements 5.4）。
pub fn status_filter_for_platform(platform: &str) -> StatusFilter {
    let statuses = vec![
        PurchaseStatus::ShippedAgentOrder, // 已发货代订单
        PurchaseStatus::ShippedToJapan,    // 已发日本
        PurchaseStatus::ShippedNotice,     // 已发出荷通知
    ];

    // 乐天平台额外纳入「日本仓库已处理」——该状态在统一模型中不属 PurchaseStatus，
    // 作为额外原始状态字符串保留（见模块文档）。
    let extra_raw = if platform == "r" {
        vec!["日本仓库已处理"]
    } else {
        Vec::new()
    };

    StatusFilter {
        statuses,
        extra_raw,
    }
}

// ============================================================================
// 随机取一接缝（复刻 PHP array_rand，可注入以便确定性测试）
// ============================================================================

/// 从 `[0, len)` 中选取一个下标的策略（抽象 PHP `array_rand`）。
///
/// 抽象成 trait 以便：生产用基于时钟种子的伪随机实现，测试注入固定下标实现，
/// 使「随机取一」在单测中确定可复现。
pub trait IndexSelector: Send + Sync {
    /// 从 `[0, len)` 选取一个下标；`len == 0` 返回 `None`。
    fn pick_index(&self, len: usize) -> Option<usize>;
}

/// 生产用随机选择器：以系统时钟纳秒作种子对长度取模。
///
/// 无需引入额外随机数依赖；候选数通常仅个位数，分布足够均匀，契合 old `array_rand` 语义。
#[derive(Debug, Default, Clone, Copy)]
pub struct TimeSeedSelector;

impl IndexSelector for TimeSeedSelector {
    fn pick_index(&self, len: usize) -> Option<usize> {
        if len == 0 {
            return None;
        }
        let seed = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|d| d.subsec_nanos() as usize)
            .unwrap_or(0);
        Some(seed % len)
    }
}

// ============================================================================
// 核心判定逻辑（无 DB / 无网络，可单测）
// ============================================================================

/// 逐一查询多个运单号，收集「成功且状态非空」的查询结果（复刻 run_platform 步骤 3）。
///
/// 对每个运单号：先用 [`CarrierTracker::detect_carrier`] 前缀识别承运商，无法识别则跳过；
/// 否则查询，仅当 `success && !status.is_empty()` 时纳入有效结果。查询出错（`Err`）按
/// 「该运单查询失败」处理——跳过、不中断其余运单（与 PHP 逐单容错一致）。
pub async fn query_valid_results(
    tracker: &dyn CarrierTracker,
    ship_numbers: &[String],
) -> Vec<TrackResult> {
    let mut valid = Vec::new();
    for sn in ship_numbers {
        let Some(carrier) = tracker.detect_carrier(sn) else {
            continue; // 无法识别承运商 → 跳过该运单
        };
        match tracker.track(carrier, sn).await {
            Ok(result) if result.success && !result.status.is_empty() => valid.push(result),
            Ok(_) => {} // 查询成功但无状态 → 不纳入
            Err(e) => {
                tracing::warn!(ship_number = sn.as_str(), error = %e, "运单查询失败，跳过");
            }
        }
    }
    valid
}

/// 在「有物流状态结果」里随机取一（复刻 PHP `array_rand`，Requirements 5.5）。
///
/// 经注入的 [`IndexSelector`] 选取下标；空集返回 `None`。返回引用，避免克隆。
pub fn pick_result<'a>(
    selector: &dyn IndexSelector,
    results: &'a [TrackResult],
) -> Option<&'a TrackResult> {
    let idx = selector.pick_index(results.len())?;
    results.get(idx)
}

/// 判断物流状态文本是否命中「完成态」（含「配達完了」或「お客様引渡完了」）。
pub fn is_completed_status(status: &str) -> bool {
    COMPLETED_KEYWORDS
        .iter()
        .any(|keyword| status.contains(keyword))
}

/// 解析完成时间（Requirements 5.2）。
///
/// - 非完成态 ⟹ `None`（不写完成时间）。
/// - 完成态：尝试解析 `completed_date`（多种格式）；解析失败或无日期时以 `now` 兜底
///   （复刻 PHP「配達完了但物流未给日期则用当前时间」）。
///
/// 返回 `Some(_)` 时由 [`order_repo::update_jpship`] 的 `CASE` 保证仅在
/// `jpship_completed_at` 为空时落库，已有值不变。
pub fn resolve_completion(
    status: &str,
    completed_date: Option<&str>,
    now: NaiveDateTime,
) -> Option<NaiveDateTime> {
    if !is_completed_status(status) {
        return None;
    }
    let parsed = completed_date
        .map(str::trim)
        .filter(|s| !s.is_empty())
        .and_then(parse_completed_date);
    Some(parsed.unwrap_or(now))
}

/// **`order_repo::update_jpship` 中 `jpship_completed_at` 的 `CASE` 在 SQL 层语义的纯模型**。
///
/// 忠实复刻该 SQL：
/// ```sql
/// jpship_completed_at = CASE
///     WHEN jpship_completed_at IS NULL THEN ?   -- ? 即 incoming
///     ELSE jpship_completed_at                  -- 已有值保持不变
/// END
/// ```
/// 即「完成时间仅首次写入」：当前为空（`None`）时取 `incoming`，否则保持 `current`。
/// 与写入次数、`incoming` 取值均无关——一旦写入便不再被覆盖（幂等，Property 9）。
///
/// 该函数无副作用、不依赖 DB，使「首次写入」规则可作为纯属性被 [`proptest`] 验证；
/// 真实落库的幂等仍由 `order_repo::update_jpship` 的 `CASE` 在 SQL 层保证，此处仅为其模型。
///
/// _Requirements: 5.2 / Property 9_
pub fn first_write_only(
    current: Option<NaiveDateTime>,
    incoming: Option<NaiveDateTime>,
) -> Option<NaiveDateTime> {
    match current {
        // 已有完成时间 → 保持不变（忽略 incoming）。
        Some(existing) => Some(existing),
        // 尚未写入 → 采用本次候选（可能仍为 None，表示无完成时间）。
        None => incoming,
    }
}

/// 尝试用多种格式解析物流返回的完成时间字符串，全部失败返回 `None`。
fn parse_completed_date(raw: &str) -> Option<NaiveDateTime> {
    for fmt in COMPLETED_DATE_FORMATS {
        // 仅含日期的格式补 00:00:00 后再解析。
        if fmt == "%Y-%m-%d" || fmt == "%Y/%m/%d" {
            let sep = if fmt.contains('/') { '/' } else { '-' };
            let with_time = format!("{raw} 00:00:00");
            let full_fmt = format!("%Y{sep}%m{sep}%d %H:%M:%S");
            if let Ok(dt) = NaiveDateTime::parse_from_str(&with_time, &full_fmt) {
                return Some(dt);
            }
        } else if let Ok(dt) = NaiveDateTime::parse_from_str(raw, fmt) {
            return Some(dt);
        }
    }
    None
}

/// 当前时间（UTC，`NaiveDateTime`）。
///
/// chrono 未启用 `clock` 特性，故由 [`SystemTime`] 推导，仅用于完成时间兜底，
/// 时区误差对该用途无实质影响。
fn now_naive() -> NaiveDateTime {
    let secs = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs() as i64)
        .unwrap_or(0);
    NaiveDateTime::from_timestamp_opt(secs, 0)
        .unwrap_or_else(|| NaiveDateTime::from_timestamp_opt(0, 0).expect("epoch 必然有效"))
}

// ============================================================================
// 任务运行选项 & Job 实现
// ============================================================================

/// 国际物流更新任务运行选项（复刻 `--days --limit`）。
#[derive(Debug, Clone, Copy)]
pub struct JpShipOptions {
    /// 查询最近多少天的订单（`0` = 不限天数）。
    pub days: u32,
    /// 每平台最多更新条数（`0` = 不限制）。
    pub limit: u32,
}

impl Default for JpShipOptions {
    fn default() -> Self {
        // 复刻 PHP 默认：最近 30 天、不限条数。
        Self { days: 30, limit: 0 }
    }
}

/// 默认处理的平台代码（复刻 PHP 默认 `w,m,r,y,yp`）。
const DEFAULT_PLATFORMS: [&str; 5] = ["w", "m", "r", "y", "yp"];

/// 国际/国内物流更新定时任务（复刻 `update_jpship_logistics`）。
///
/// 承运商查询经 [`CarrierTracker`]、随机取一经 [`IndexSelector`] 注入，便于离线测试。
pub struct UpdateJpshipLogisticsJob {
    tracker: Arc<dyn CarrierTracker>,
    selector: Arc<dyn IndexSelector>,
    options: JpShipOptions,
    platforms: Vec<String>,
}

impl UpdateJpshipLogisticsJob {
    /// 生产构造：注入承运商查询实现，随机取一用 [`TimeSeedSelector`]，默认选项与平台集。
    pub fn new(tracker: Arc<dyn CarrierTracker>) -> Self {
        Self {
            tracker,
            selector: Arc::new(TimeSeedSelector),
            options: JpShipOptions::default(),
            platforms: DEFAULT_PLATFORMS.iter().map(|p| p.to_string()).collect(),
        }
    }

    /// 完整构造：可注入随机选择器、运行选项与平台集（测试用）。
    pub fn with_parts(
        tracker: Arc<dyn CarrierTracker>,
        selector: Arc<dyn IndexSelector>,
        options: JpShipOptions,
        platforms: Vec<String>,
    ) -> Self {
        Self {
            tracker,
            selector,
            options,
            platforms,
        }
    }

    /// 处理单个平台：查待更新子商品 → 逐单查询 → 随机取一 → 写状态/完成时间。
    ///
    /// 单条子商品的查询/更新失败被隔离（记日志后继续下一条），整平台始终返回成功条数。
    async fn run_platform(
        &self,
        pool: &sqlx::MySqlPool,
        platform: &str,
    ) -> Result<usize, AppError> {
        let filter = status_filter_for_platform(platform);
        let pending = order_repo::find_pending_intl(
            pool,
            platform,
            &filter.statuses,
            self.options.days,
            self.options.limit,
        )
        .await?;

        let mut updated = 0usize;
        for item in pending {
            let Some(raw) = item.ship_numbers.as_deref() else {
                continue; // 无运单号（理论上不会发生，find_pending_intl 已 JOIN 运单表）
            };
            let numbers = split_ship_numbers(raw);
            if numbers.is_empty() {
                continue;
            }

            // 逐一查询，收集有状态结果。
            let valid = query_valid_results(self.tracker.as_ref(), &numbers).await;

            // 有状态结果中随机取一。
            let Some(picked) = pick_result(self.selector.as_ref(), &valid) else {
                continue; // 无任何有状态结果 → 不更新
            };

            // 命中完成态时解析完成时间（兜底当前时间），否则不写完成时间。
            let completed_at = resolve_completion(
                &picked.status,
                picked.completed_date.as_deref(),
                now_naive(),
            );

            match order_repo::update_jpship(pool, item.order_item_id, &picked.status, completed_at)
                .await
            {
                Ok(_) => updated += 1,
                Err(e) => {
                    tracing::warn!(
                        order_item_id = item.order_item_id,
                        error = %e,
                        "写入国内物流状态失败，跳过该子商品"
                    );
                }
            }
        }

        Ok(updated)
    }
}

#[async_trait]
impl Job for UpdateJpshipLogisticsJob {
    fn name(&self) -> &'static str {
        "update_jpship_logistics"
    }

    fn interval(&self) -> Duration {
        // 复刻 PHP「每天 9/15/21 点三次」≈ 每 6 小时一轮。
        Duration::from_secs(6 * 60 * 60)
    }

    async fn run_for_tenant(&self, ctx: &JobContext) -> Result<(), AppError> {
        let mut total = 0usize;
        for platform in &self.platforms {
            match self.run_platform(&ctx.pool, platform).await {
                Ok(n) => total += n,
                Err(e) => {
                    // 单平台失败被隔离，继续其余平台（复刻 PHP 平台级 continue）。
                    tracing::warn!(
                        tenant_id = ctx.tenant_id,
                        platform = platform.as_str(),
                        error = %e,
                        "平台国际物流更新失败，跳过该平台"
                    );
                }
            }
        }
        tracing::debug!(
            tenant_id = ctx.tenant_id,
            updated = total,
            "国际物流更新完成"
        );
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::integrations::traits::Carrier;
    use std::collections::{HashMap, HashSet};

    // ------------------------------------------------------------------
    // status_filter_for_platform（Requirements 5.4）
    // ------------------------------------------------------------------

    #[test]
    fn rakuten_filter_adds_extra_status() {
        let filter = status_filter_for_platform("r");
        assert_eq!(
            filter.statuses,
            vec![
                PurchaseStatus::ShippedAgentOrder,
                PurchaseStatus::ShippedToJapan,
                PurchaseStatus::ShippedNotice,
            ]
        );
        // 乐天额外纳入「日本仓库已处理」。
        assert_eq!(filter.extra_raw, vec!["日本仓库已处理"]);
    }

    #[test]
    fn non_rakuten_filters_have_no_extra_status() {
        for platform in ["w", "m", "y", "yp", "q"] {
            let filter = status_filter_for_platform(platform);
            assert_eq!(
                filter.statuses,
                vec![
                    PurchaseStatus::ShippedAgentOrder,
                    PurchaseStatus::ShippedToJapan,
                    PurchaseStatus::ShippedNotice,
                ],
                "平台 {platform} 基础状态集应为三状态"
            );
            assert!(
                filter.extra_raw.is_empty(),
                "平台 {platform} 不应有额外状态"
            );
        }
    }

    // ------------------------------------------------------------------
    // 随机取一（Requirements 5.5）——注入固定下标使其确定可复现
    // ------------------------------------------------------------------

    /// 固定下标选择器：恒返回构造时指定的下标（空集返回 None）。
    struct FixedSelector(usize);
    impl IndexSelector for FixedSelector {
        fn pick_index(&self, len: usize) -> Option<usize> {
            if len == 0 {
                None
            } else {
                Some(self.0 % len)
            }
        }
    }

    fn status_result(status: &str) -> TrackResult {
        TrackResult {
            success: true,
            status: status.to_string(),
            completed_date: None,
        }
    }

    #[test]
    fn pick_result_uses_injected_selector() {
        let results = vec![
            status_result("配送中"),
            status_result("持戻"),
            status_result("配達完了"),
        ];
        // 注入下标 2 → 必选中第三个。
        let picked = pick_result(&FixedSelector(2), &results).expect("应选中一个结果");
        assert_eq!(picked.status, "配達完了");

        // 注入下标 0 → 必选中第一个。
        let picked0 = pick_result(&FixedSelector(0), &results).expect("应选中一个结果");
        assert_eq!(picked0.status, "配送中");
    }

    #[test]
    fn pick_result_empty_returns_none() {
        let empty: Vec<TrackResult> = Vec::new();
        assert!(pick_result(&FixedSelector(0), &empty).is_none());
        // TimeSeedSelector 对空集也必返回 None。
        assert!(pick_result(&TimeSeedSelector, &empty).is_none());
    }

    #[test]
    fn time_seed_selector_index_in_range() {
        for len in 1..8usize {
            let idx = TimeSeedSelector.pick_index(len).expect("非空必有下标");
            assert!(idx < len, "下标必落在 [0,{len})");
        }
        assert_eq!(TimeSeedSelector.pick_index(0), None);
    }

    // ------------------------------------------------------------------
    // 完成态检测与完成时间解析（Requirements 5.2）
    // ------------------------------------------------------------------

    #[test]
    fn is_completed_status_detects_both_keywords() {
        assert!(is_completed_status("配達完了"));
        assert!(is_completed_status("配達完了（宅配ボックス）"));
        assert!(is_completed_status("お客様引渡完了"));
        // 非完成态
        assert!(!is_completed_status("配送中"));
        assert!(!is_completed_status("持戻"));
        assert!(!is_completed_status(""));
    }

    #[test]
    fn resolve_completion_returns_none_for_non_completed() {
        let now = now_naive();
        assert_eq!(
            resolve_completion("配送中", Some("2024-01-02 03:04:05"), now),
            None
        );
        assert_eq!(resolve_completion("", None, now), None);
    }

    #[test]
    fn resolve_completion_parses_provided_date() {
        let now = now_naive();
        let parsed = resolve_completion("配達完了", Some("2024-01-02 03:04:05"), now)
            .expect("完成态应返回 Some");
        let expected =
            NaiveDateTime::parse_from_str("2024-01-02 03:04:05", "%Y-%m-%d %H:%M:%S").unwrap();
        assert_eq!(parsed, expected);

        // 斜杠日期格式同样可解析。
        let slash = resolve_completion("お客様引渡完了", Some("2024/05/06 07:08:09"), now)
            .expect("完成态应返回 Some");
        let expected_slash =
            NaiveDateTime::parse_from_str("2024/05/06 07:08:09", "%Y/%m/%d %H:%M:%S").unwrap();
        assert_eq!(slash, expected_slash);

        // 仅日期（无时间）补 00:00:00。
        let date_only =
            resolve_completion("配達完了", Some("2024-07-08"), now).expect("完成态应返回 Some");
        let expected_date =
            NaiveDateTime::parse_from_str("2024-07-08 00:00:00", "%Y-%m-%d %H:%M:%S").unwrap();
        assert_eq!(date_only, expected_date);
    }

    #[test]
    fn resolve_completion_falls_back_to_now_when_no_date() {
        let now =
            NaiveDateTime::parse_from_str("2030-12-31 23:59:59", "%Y-%m-%d %H:%M:%S").unwrap();
        // 完成态但无日期 → 兜底 now。
        assert_eq!(resolve_completion("配達完了", None, now), Some(now));
        // 完成态但日期为空白串 → 兜底 now。
        assert_eq!(resolve_completion("配達完了", Some("   "), now), Some(now));
        // 完成态但日期无法解析 → 兜底 now。
        assert_eq!(
            resolve_completion("配達完了", Some("not-a-date"), now),
            Some(now)
        );
    }

    // ------------------------------------------------------------------
    // query_valid_results（复刻逐单查询 + 承运商识别 + 过滤无状态结果）
    // ------------------------------------------------------------------

    /// 离线假承运商查询：按运单号映射查询结果；`unknown` 中的运单号识别不出承运商。
    struct FakeTracker {
        results: HashMap<String, TrackResult>,
        unknown: HashSet<String>,
    }

    #[async_trait]
    impl CarrierTracker for FakeTracker {
        fn detect_carrier(&self, ship_number: &str) -> Option<Carrier> {
            if self.unknown.contains(ship_number) {
                None
            } else {
                Some(Carrier::Sagawa)
            }
        }
        async fn track(
            &self,
            _carrier: Carrier,
            ship_number: &str,
        ) -> Result<TrackResult, AppError> {
            Ok(self.results.get(ship_number).cloned().unwrap_or_default())
        }
    }

    #[tokio::test]
    async fn query_valid_results_keeps_only_success_with_status() {
        let mut results = HashMap::new();
        // A：成功且有状态 → 纳入
        results.insert("A".to_string(), status_result("配送中"));
        // B：成功但状态为空 → 不纳入
        results.insert(
            "B".to_string(),
            TrackResult {
                success: true,
                status: String::new(),
                completed_date: None,
            },
        );
        // D：成功且完成态 → 纳入
        results.insert(
            "D".to_string(),
            TrackResult {
                success: true,
                status: "配達完了".to_string(),
                completed_date: Some("2024-01-02 03:04:05".to_string()),
            },
        );
        // E：查询失败 → 不纳入
        results.insert(
            "E".to_string(),
            TrackResult {
                success: false,
                status: "配送中".to_string(),
                completed_date: None,
            },
        );

        // C：无法识别承运商 → 跳过
        let tracker = FakeTracker {
            results,
            unknown: HashSet::from(["C".to_string()]),
        };

        let numbers = vec![
            "A".to_string(),
            "B".to_string(),
            "C".to_string(),
            "D".to_string(),
            "E".to_string(),
        ];
        let valid = query_valid_results(&tracker, &numbers).await;

        let statuses: Vec<&str> = valid.iter().map(|r| r.status.as_str()).collect();
        assert_eq!(statuses, vec!["配送中", "配達完了"]);
    }

    #[tokio::test]
    async fn query_then_pick_then_resolve_end_to_end() {
        let mut results = HashMap::new();
        results.insert("S1".to_string(), status_result("配送中"));
        results.insert(
            "S2".to_string(),
            TrackResult {
                success: true,
                status: "配達完了".to_string(),
                completed_date: Some("2024-02-03 04:05:06".to_string()),
            },
        );
        let tracker = FakeTracker {
            results,
            unknown: HashSet::new(),
        };

        let numbers = split_ship_numbers("S1, S2");
        let valid = query_valid_results(&tracker, &numbers).await;
        assert_eq!(valid.len(), 2);

        // 选中完成态结果（下标 1）→ 解析出完成时间。
        let picked = pick_result(&FixedSelector(1), &valid).unwrap();
        assert_eq!(picked.status, "配達完了");
        let completed = resolve_completion(
            &picked.status,
            picked.completed_date.as_deref(),
            now_naive(),
        );
        let expected =
            NaiveDateTime::parse_from_str("2024-02-03 04:05:06", "%Y-%m-%d %H:%M:%S").unwrap();
        assert_eq!(completed, Some(expected));

        // 选中非完成态结果（下标 0）→ 不写完成时间。
        let picked0 = pick_result(&FixedSelector(0), &valid).unwrap();
        assert_eq!(picked0.status, "配送中");
        assert_eq!(
            resolve_completion(
                &picked0.status,
                picked0.completed_date.as_deref(),
                now_naive()
            ),
            None
        );
    }

    // ------------------------------------------------------------------
    // Property 9: 配達完了时间仅首次写入（Validates: Requirements 5.2）
    //
    // first_write_only 是 order_repo::update_jpship 的
    // `CASE WHEN jpship_completed_at IS NULL THEN ? ELSE jpship_completed_at END`
    // 在 SQL 层语义的纯模型。以 proptest 验证：对任意写入序列，一旦设值便不再改变（幂等），
    // 且仅完成态结果（resolve_completion 返回 Some）才会产生写入候选。
    // ------------------------------------------------------------------

    use proptest::prelude::*;

    /// 在合理范围内生成 `NaiveDateTime`（1970..~2096，秒精度）。
    fn ndt_strategy() -> impl Strategy<Value = NaiveDateTime> {
        (0i64..4_000_000_000i64)
            .prop_map(|secs| NaiveDateTime::from_timestamp_opt(secs, 0).expect("范围内必有效"))
    }

    /// 生成 `Option<NaiveDateTime>`（None 表示「该次写入无完成时间候选」）。
    fn opt_ndt_strategy() -> impl Strategy<Value = Option<NaiveDateTime>> {
        prop_oneof![Just(None), ndt_strategy().prop_map(Some)]
    }

    proptest! {
        /// **Property 9（核心）**：对任意初始值与任意写入序列，折叠 `first_write_only` 的结果
        /// 恒等于「第一个非空值」——初始已有值则保持初始，否则取序列中首个 `Some`，全 `None` 则为 `None`。
        /// 这刻画了「仅首次写入、已有值永不被覆盖」。
        ///
        /// **Validates: Requirements 5.2**
        #[test]
        fn prop_first_write_only_keeps_first_set_value(
            initial in opt_ndt_strategy(),
            writes in proptest::collection::vec(opt_ndt_strategy(), 0..12),
        ) {
            // 期望值：第一个非空值（初始优先），否则 None。
            let expected = initial.or_else(|| writes.iter().copied().flatten().next());

            let mut state = initial;
            let mut locked: Option<NaiveDateTime> = None;
            for incoming in &writes {
                let before = state;
                state = first_write_only(state, *incoming);

                // 一旦写入便锁定：此后任何写入都不得改变它（幂等）。
                if let Some(v) = before {
                    prop_assert_eq!(state, Some(v), "已有完成时间不得被覆盖");
                }
                if locked.is_none() {
                    locked = state;
                } else {
                    prop_assert_eq!(state, locked, "已锁定的完成时间在后续写入中不得改变");
                }
            }

            prop_assert_eq!(state, expected, "折叠结果应为首个非空值");
        }

        /// **Property 9（幂等点性质）**：当 `current` 已为 `Some` 时，无论 `incoming` 为何，
        /// `first_write_only` 都返回 `current`；再次应用任意 `incoming` 仍不变。
        ///
        /// **Validates: Requirements 5.2**
        #[test]
        fn prop_first_write_only_idempotent_once_set(
            existing in ndt_strategy(),
            incoming1 in opt_ndt_strategy(),
            incoming2 in opt_ndt_strategy(),
        ) {
            let once = first_write_only(Some(existing), incoming1);
            prop_assert_eq!(once, Some(existing), "已设值时首次再写入应保持原值");

            let twice = first_write_only(once, incoming2);
            prop_assert_eq!(twice, once, "再次写入仍保持不变（幂等）");
        }

        /// **Property 9（写入候选来源）**：仅完成态结果才产生写入候选。
        /// 对非完成态，`resolve_completion` 返回 `None`，经 `first_write_only` 绝不会把空状态置为已完成；
        /// 对完成态，候选为 `Some`，仅当当前为空时才落入（已有值仍不变）。
        ///
        /// **Validates: Requirements 5.2**
        #[test]
        fn prop_only_completion_status_writes_completion(
            current in opt_ndt_strategy(),
            is_completed in any::<bool>(),
            date in opt_ndt_strategy(),
        ) {
            let now = NaiveDateTime::from_timestamp_opt(1_700_000_000, 0).unwrap();
            let status = if is_completed { "配達完了" } else { "配送中" };
            // 把可选日期渲染为物流字符串形态（None 表示物流未给日期）。
            let date_str = date.map(|d| d.format("%Y-%m-%d %H:%M:%S").to_string());

            let candidate = resolve_completion(status, date_str.as_deref(), now);

            if is_completed {
                // 完成态必产生写入候选（有日期取日期、否则兜底 now）。
                prop_assert!(candidate.is_some(), "完成态应产生写入候选");
            } else {
                // 非完成态绝不产生候选。
                prop_assert_eq!(candidate, None, "非完成态不得产生写入候选");
            }

            let result = first_write_only(current, candidate);
            match current {
                // 当前已有值 → 永不被覆盖。
                Some(v) => prop_assert_eq!(result, Some(v), "已有完成时间不得被覆盖"),
                // 当前为空 → 结果即候选（完成态写入、非完成态保持空）。
                None => prop_assert_eq!(result, candidate, "当前为空时结果应等于候选"),
            }
        }
    }
}
