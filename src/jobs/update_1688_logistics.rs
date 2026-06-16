//! 定时任务：更新 1688 物流（含 00:00–08:00 禁运窗口）—— Task 14.2。
//!
//! 忠实复刻 `old/cron/update_1688_logistics.php` 的运行语义（见 `design.md` 6.3 任务表
//! 与「后台任务隔离」错误处理表）：
//!
//! 1. **禁运窗口（Requirements 11.2）**：当前小时落在 `00:00–08:00` 时整轮**直接跳过**，
//!    且**不计为错误**——复刻 PHP 顶部 `if ($current_hour >= 0 && $current_hour < 8) exit(0)`。
//!    判定收敛为纯函数 [`is_embargoed`]，便于单测；运行期由 [`current_hour`] 取当前小时。
//! 2. **取数过滤**：只处理「有 1688 订单号（`tabaono`）」且采购状态属
//!    `{国内采购-已采购, 发货中}` 的子商品（复刻 PHP `beizhu IN(...)`），可叠加
//!    `--platform / --limit / --1688id` 过滤。
//! 3. **逐条容错（Requirements 6.4）**：对每个待更新子商品调用注入的
//!    [`PurchaseProvider::query_logistics`]；**单条 API 失败只记失败样本并继续下一条，
//!    绝不中断整批**（凭证轮换 + 有界重试已在 `Purchase1688` 内完成，失败收敛为
//!    [`AppError::ExternalApi`]）。
//! 4. **请求间隔**：相邻请求间按 `--delay` 休眠，避免触发 1688 限流（复刻 `usleep`）。
//!
//! ## 可测试性接缝
//! 真正触达网络的「查物流」收敛在 [`PurchaseProvider`] trait 之后，触达 DB 的
//! 「列举待更新 / 落库结果」收敛在 [`LogisticsStore`] trait 之后，二者均以 `dyn` 注入。
//! 核心批处理 [`run_batch`] 只依赖这两个 trait 与纯 [`Options`]，因此可在
//! **无真实 MySQL / 无网络**前提下，断言「逐条失败隔离」「过滤/限制」「禁运跳过」等行为。
//! 生产侧 [`SqlxLogisticsStore`] 用租户库连接池以运行时 SQLx 读写。
//!
//! _Requirements: 11.2, 6.3, 6.4_

use std::time::{Duration, SystemTime, UNIX_EPOCH};

use async_trait::async_trait;
use sqlx::MySqlPool;

use crate::error::AppError;
use crate::integrations::traits::{LogisticsTrace, PurchaseProvider};
use crate::jobs::scheduler::{Job, JobContext};

// ============================================================================
// 常量
// ============================================================================

/// 默认处理的平台代码（复刻 PHP 默认 `w,m,r,y,yp`）。
const DEFAULT_PLATFORMS: [&str; 5] = ["w", "m", "r", "y", "yp"];

/// 合法平台集合（参数校验用，复刻 PHP `$valid_platforms`）。
const VALID_PLATFORMS: [&str; 5] = ["w", "m", "r", "y", "yp"];

/// 仅更新这两种采购状态的子商品（复刻 PHP `$include_status`）。
/// 取 [`PurchaseStatus`](crate::models::order::PurchaseStatus) 的中文字面值，
/// 与租户库 `order_items.purchase_status` 存储一致。
const INCLUDE_STATUSES: [&str; 2] = ["国内采购-已采购", "发货中"];

/// 默认请求间隔（毫秒），复刻 PHP `--delay` 默认 500。
const DEFAULT_DELAY_MS: u64 = 500;

/// 禁运窗口下界（含）小时。
const EMBARGO_START_HOUR: u32 = 0;
/// 禁运窗口上界（不含）小时：`[0, 8)` 即 00:00–08:00 跳过。
const EMBARGO_END_HOUR: u32 = 8;

/// 落库审计日志的操作人（复刻 PHP `$_SESSION['username'] = 'autorun_task_api'`）。
const TASK_OPERATOR: &str = "autorun_task_api";

// ============================================================================
// 禁运窗口判定（纯函数，Requirements 11.2）
// ============================================================================

/// 当前小时是否处于禁运窗口 `[00:00, 08:00)`。
///
/// 复刻 PHP `if ($current_hour >= 0 && $current_hour < 8)`：命中即整轮跳过、不计错误。
/// 纯函数，便于穷举单测。
pub fn is_embargoed(hour: u32) -> bool {
    hour >= EMBARGO_START_HOUR && hour < EMBARGO_END_HOUR
}

/// 取当前小时（0–23）。
///
/// chrono 未启用 `clock` 特性（与 `update_jpship_logistics.rs` 一致），故由
/// [`SystemTime`] 推导。业务运营窗口以日本时区（JST, UTC+9）为准——西阵为日本跨境团队，
/// 故在 UTC 基础上加 9 小时换算到 JST 后取小时数。禁运判定本身由纯 [`is_embargoed`]
/// 完成，本函数只负责「现在几点」。
fn current_hour() -> u32 {
    const JST_OFFSET_SECS: u64 = 9 * 3600;
    let secs = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0);
    (((secs + JST_OFFSET_SECS) / 3600) % 24) as u32
}

// ============================================================================
// 运行选项（复刻 --platform / --limit / --delay / --1688id）
// ============================================================================

/// 任务运行选项，镜像 PHP `getopt('', ['platform::','limit::','delay::','1688id::'])`。
///
/// 字段保持「原样可空」语义；默认值与合法性收敛在 [`Options::platforms`] /
/// [`Options::delay`] / [`Options::effective_limit`] 等访问器中，便于单测。
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct Options {
    /// `--platform=w,m,r`：逗号分隔的平台列表；`None` 表示全部。
    pub platform: Option<String>,
    /// `--limit=100`：每平台最多更新条数；`None` 或 `0` 表示不限制。
    pub limit: Option<u32>,
    /// `--delay=500`：相邻请求间隔毫秒；`None` 表示用默认 500。
    pub delay_ms: Option<u64>,
    /// `--1688id=xxx`：仅处理单个 1688 订单号（测试/定向）。
    pub tabaono: Option<String>,
}

impl Options {
    /// 从命令行风格参数解析，支持 `--key=value` 与 `--key value` 两种形态。
    ///
    /// 未知参数被忽略（容错）；数值字段非法时返回 [`AppError::Validation`]。
    pub fn parse<I, S>(args: I) -> Result<Self, AppError>
    where
        I: IntoIterator<Item = S>,
        S: AsRef<str>,
    {
        let tokens: Vec<String> = args.into_iter().map(|s| s.as_ref().to_string()).collect();
        let mut opts = Options::default();

        let mut i = 0;
        while i < tokens.len() {
            let tok = tokens[i].trim();
            let stripped = tok.strip_prefix("--").unwrap_or(tok);

            // 拆出 key 与（可选）内联 value。
            let (key, inline_val) = match stripped.split_once('=') {
                Some((k, v)) => (k, Some(v.to_string())),
                None => (stripped, None),
            };

            // 取值：优先内联 `=value`，否则取下一个非 `--` 前缀的 token。
            let take_value = |i: &mut usize| -> Option<String> {
                if let Some(v) = inline_val.clone() {
                    return Some(v);
                }
                if let Some(next) = tokens.get(*i + 1) {
                    if !next.starts_with("--") {
                        *i += 1;
                        return Some(next.clone());
                    }
                }
                None
            };

            match key {
                "platform" => {
                    if let Some(v) = take_value(&mut i) {
                        opts.platform = Some(v);
                    }
                }
                "limit" => {
                    if let Some(v) = take_value(&mut i) {
                        let n: u32 = v.trim().parse().map_err(|_| {
                            AppError::Validation(format!("--limit 必须为非负整数：{v}"))
                        })?;
                        opts.limit = Some(n);
                    }
                }
                "delay" => {
                    if let Some(v) = take_value(&mut i) {
                        let n: u64 = v.trim().parse().map_err(|_| {
                            AppError::Validation(format!("--delay 必须为非负整数（毫秒）：{v}"))
                        })?;
                        opts.delay_ms = Some(n);
                    }
                }
                "1688id" => {
                    if let Some(v) = take_value(&mut i) {
                        let v = v.trim().to_string();
                        opts.tabaono = if v.is_empty() { None } else { Some(v) };
                    }
                }
                _ => { /* 未知参数忽略 */ }
            }
            i += 1;
        }

        Ok(opts)
    }

    /// 解析后的有效平台列表：取 `--platform` 与合法集合的交集，保持合法集合顺序；
    /// 为空（未指定或全非法）时回退到全部默认平台（复刻 PHP 校验回退逻辑）。
    pub fn platforms(&self) -> Vec<String> {
        let Some(raw) = self.platform.as_deref() else {
            return DEFAULT_PLATFORMS.iter().map(|p| p.to_string()).collect();
        };
        let requested: Vec<&str> = raw
            .split(',')
            .map(|p| p.trim())
            .filter(|p| !p.is_empty())
            .collect();

        // 以合法集合顺序求交集，去重、忽略非法项。
        let selected: Vec<String> = VALID_PLATFORMS
            .iter()
            .filter(|valid| requested.iter().any(|r| r == *valid))
            .map(|p| p.to_string())
            .collect();

        if selected.is_empty() {
            DEFAULT_PLATFORMS.iter().map(|p| p.to_string()).collect()
        } else {
            selected
        }
    }

    /// 有效请求间隔：`--delay` 指定则用之，否则默认 500ms。
    pub fn delay(&self) -> Duration {
        Duration::from_millis(self.delay_ms.unwrap_or(DEFAULT_DELAY_MS))
    }

    /// 有效条数限制：`None` 或 `0` 归一为「不限制」（`None`）。
    pub fn effective_limit(&self) -> Option<u32> {
        match self.limit {
            Some(0) | None => None,
            Some(n) => Some(n),
        }
    }
}

// ============================================================================
// 数据访问接缝（可注入 dyn，离线测试用）
// ============================================================================

/// 一条待更新 1688 物流的子商品视图（取数结果）。
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct PendingPurchase {
    /// 子商品 id（关联 purchases/order_items）。
    pub order_item_id: i64,
    /// 所属订单 id（写审计日志用）。
    pub order_id: i64,
    /// 平台代码。
    pub platform: String,
    /// 1688 订单号（`purchases.tabaono`）。
    pub tabaono: String,
}

/// 1688 物流取数 + 落库抽象（**DB seam**）。
///
/// 把唯一触达租户库的两处操作收敛于此：列举待更新子商品、落库一次物流结果。
/// 生产实现 [`SqlxLogisticsStore`] 用连接池以运行时 SQLx 执行；测试可注入内存替身。
#[async_trait]
pub trait LogisticsStore: Send + Sync {
    /// 列举某平台下「有 tabaono 且采购状态命中 [`INCLUDE_STATUSES`]」的待更新子商品。
    ///
    /// `tabaono` 为 `Some` 时进一步只取该 1688 订单号；`limit` 为 `Some(n)` 时最多 `n` 条。
    async fn list_pending(
        &self,
        platform: &str,
        tabaono: Option<&str>,
        limit: Option<u32>,
    ) -> Result<Vec<PendingPurchase>, AppError>;

    /// 落库一次物流查询结果（写入结构化审计日志，复刻 old `log_1688api_add`）。
    async fn save_logistics(
        &self,
        item: &PendingPurchase,
        trace: &LogisticsTrace,
    ) -> Result<(), AppError>;
}

/// 生产实现：以租户库连接池用运行时 SQLx 读写。
///
/// 与项目其余 repository 一致——租户库编译期不存在，故一律使用 `query` / `query_as`
/// + 显式 SQL，不用编译期宏。
pub struct SqlxLogisticsStore<'a> {
    pool: &'a MySqlPool,
}

impl<'a> SqlxLogisticsStore<'a> {
    /// 由租户库连接池构造。
    pub fn new(pool: &'a MySqlPool) -> Self {
        Self { pool }
    }
}

#[async_trait]
impl LogisticsStore for SqlxLogisticsStore<'_> {
    async fn list_pending(
        &self,
        platform: &str,
        tabaono: Option<&str>,
        limit: Option<u32>,
    ) -> Result<Vec<PendingPurchase>, AppError> {
        // 采购状态 IN (?, ?) 占位符。
        let status_placeholders = INCLUDE_STATUSES
            .iter()
            .map(|_| "?")
            .collect::<Vec<_>>()
            .join(", ");

        let tabaono_clause = if tabaono.is_some() {
            " AND p.`tabaono` = ?"
        } else {
            ""
        };
        let limit_clause = if limit.is_some() { " LIMIT ?" } else { "" };

        let sql = format!(
            "SELECT p.`order_item_id` AS `order_item_id`, oi.`order_id` AS `order_id`, \
                    o.`platform` AS `platform`, p.`tabaono` AS `tabaono` \
             FROM `purchases` p \
             JOIN `order_items` oi ON oi.`id` = p.`order_item_id` \
             JOIN `orders` o ON o.`id` = oi.`order_id` \
             WHERE p.`tabaono` <> '' \
               AND o.`platform` = ? \
               AND oi.`purchase_status` IN ({status_placeholders}){tabaono_clause} \
             ORDER BY oi.`id` DESC{limit_clause}"
        );

        let mut query = sqlx::query_as::<_, (i64, i64, String, String)>(&sql).bind(platform);
        for status in INCLUDE_STATUSES {
            query = query.bind(status);
        }
        if let Some(t) = tabaono {
            query = query.bind(t);
        }
        if let Some(n) = limit {
            query = query.bind(n as i64);
        }

        let rows = query.fetch_all(self.pool).await?;
        Ok(rows
            .into_iter()
            .map(
                |(order_item_id, order_id, platform, tabaono)| PendingPurchase {
                    order_item_id,
                    order_id,
                    platform,
                    tabaono,
                },
            )
            .collect())
    }

    async fn save_logistics(
        &self,
        item: &PendingPurchase,
        trace: &LogisticsTrace,
    ) -> Result<(), AppError> {
        // 复刻 old `log_1688api_add`：把本次物流结果写入结构化审计日志。
        // 统一模型未为「1688 中国段物流状态」单设列，故以 order_logs 承载（设计 3.2）。
        sqlx::query(
            "INSERT INTO `order_logs` \
             (`order_id`, `order_item_id`, `operator`, `action_type`, `field_name`, \
              `old_value`, `new_value`, `ip`) \
             VALUES (?, ?, ?, '1688物流更新', 'logisticstatus', NULL, ?, '')",
        )
        .bind(item.order_id)
        .bind(item.order_item_id)
        .bind(TASK_OPERATOR)
        .bind(&trace.status)
        .execute(self.pool)
        .await?;
        Ok(())
    }
}

// ============================================================================
// 批处理统计 & 核心逻辑（无 DB / 无网络，可单测）
// ============================================================================

/// 单轮批处理统计（便于日志/监控/测试断言）。
#[derive(Debug, Clone, Default, PartialEq, Eq)]
pub struct BatchOutcome {
    /// 成功更新（查询 + 落库均成功）条数。
    pub success: usize,
    /// 失败条数（查询失败或落库失败；已被隔离，不中断整批）。
    pub failed: usize,
    /// 跳过条数（如 1688 订单号格式非法）。
    pub skipped: usize,
}

impl BatchOutcome {
    /// 已尝试处理的总条数。
    pub fn total(&self) -> usize {
        self.success + self.failed + self.skipped
    }
}

/// 简单校验 1688 订单号格式（复刻 `verify_1688orderid` 的核心：非空纯数字）。
fn is_valid_1688_orderid(tabaono: &str) -> bool {
    let t = tabaono.trim();
    !t.is_empty() && t.chars().all(|c| c.is_ascii_digit())
}

/// 批处理核心：遍历有效平台 → 取待更新子商品 → 逐条查物流 → 落库，做**逐条失败隔离**。
///
/// - 单条 [`PurchaseProvider::query_logistics`] 失败（Requirements 6.4）：记失败样本、
///   计入 `failed`，**继续下一条**，绝不中断整批。
/// - 落库失败同样隔离计入 `failed`。
/// - 1688 订单号格式非法：计入 `skipped`，不调用 API。
/// - 相邻请求间按 `options.delay()` 休眠（仅在实际发起查询后），规避 1688 限流。
/// - 某平台取数失败只记日志、跳过该平台，不影响其余平台（复刻 PHP 平台级 continue）。
pub async fn run_batch(
    store: &dyn LogisticsStore,
    provider: &dyn PurchaseProvider,
    options: &Options,
) -> BatchOutcome {
    let mut outcome = BatchOutcome::default();
    let limit = options.effective_limit();
    let delay = options.delay();

    for platform in options.platforms() {
        let pending = match store
            .list_pending(&platform, options.tabaono.as_deref(), limit)
            .await
        {
            Ok(rows) => rows,
            Err(e) => {
                tracing::warn!(platform = platform.as_str(), error = %e, "1688 物流取数失败，跳过该平台");
                continue;
            }
        };

        let count = pending.len();
        for (idx, item) in pending.into_iter().enumerate() {
            // 1688 订单号格式校验：非法则跳过（不计错误）。
            if !is_valid_1688_orderid(&item.tabaono) {
                tracing::debug!(tabaono = item.tabaono.as_str(), "1688 订单号格式非法，跳过");
                outcome.skipped += 1;
                continue;
            }

            match provider.query_logistics(&item.tabaono).await {
                Ok(trace) => {
                    // 查询成功 → 落库；落库失败被隔离计入 failed。
                    match store.save_logistics(&item, &trace).await {
                        Ok(()) => outcome.success += 1,
                        Err(e) => {
                            tracing::warn!(
                                order_item_id = item.order_item_id,
                                tabaono = item.tabaono.as_str(),
                                error = %e,
                                "1688 物流结果落库失败，跳过该条"
                            );
                            outcome.failed += 1;
                        }
                    }
                }
                Err(e) => {
                    // 单条 API 失败：记失败样本并继续下一条（Requirements 6.4）。
                    tracing::warn!(
                        order_item_id = item.order_item_id,
                        tabaono = item.tabaono.as_str(),
                        error = %e,
                        "1688 物流查询失败，已隔离继续下一条"
                    );
                    outcome.failed += 1;
                }
            }

            // 请求间隔（最后一条后不再等待）。
            if idx + 1 < count && !delay.is_zero() {
                tokio::time::sleep(delay).await;
            }
        }
    }

    outcome
}

// ============================================================================
// Job 实现（禁运检查在 run_for_tenant 内）
// ============================================================================

/// 1688 物流更新定时任务（复刻 `update_1688_logistics.php`）。
///
/// 物流查询经 [`PurchaseProvider`] 注入；运行选项可定制（默认 `--delay=500`、全平台）。
pub struct Update1688LogisticsJob<P: PurchaseProvider> {
    provider: P,
    options: Options,
}

impl<P: PurchaseProvider> Update1688LogisticsJob<P> {
    /// 生产构造：注入 1688 采购能力，使用默认运行选项。
    pub fn new(provider: P) -> Self {
        Self {
            provider,
            options: Options::default(),
        }
    }

    /// 完整构造：注入采购能力 + 自定义运行选项（CLI/测试用）。
    pub fn with_options(provider: P, options: Options) -> Self {
        Self { provider, options }
    }
}

#[async_trait]
impl<P: PurchaseProvider> Job for Update1688LogisticsJob<P> {
    fn name(&self) -> &'static str {
        "update_1688_logistics"
    }

    fn interval(&self) -> Duration {
        // 复刻 PHP crontab「每 2 小时」。禁运窗口由 run_for_tenant 内的 is_embargoed 把关。
        Duration::from_secs(2 * 60 * 60)
    }

    async fn run_for_tenant(&self, ctx: &JobContext) -> Result<(), AppError> {
        // 禁运窗口（Requirements 11.2）：00:00–08:00 直接跳过且不计为错误。
        let hour = current_hour();
        if is_embargoed(hour) {
            tracing::debug!(
                tenant_id = ctx.tenant_id,
                hour,
                "处于禁运窗口（00:00–08:00），跳过 update_1688_logistics（不计错误）"
            );
            return Ok(());
        }

        let store = SqlxLogisticsStore::new(&ctx.pool);
        let outcome = run_batch(&store, &self.provider, &self.options).await;
        tracing::debug!(
            tenant_id = ctx.tenant_id,
            success = outcome.success,
            failed = outcome.failed,
            skipped = outcome.skipped,
            "1688 物流更新完成"
        );
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::integrations::traits::{LogisticsStep, ProductInfo};
    use std::collections::HashMap;
    use std::sync::Mutex;

    // ------------------------------------------------------------------
    // is_embargoed（Requirements 11.2）——穷举关键小时
    // ------------------------------------------------------------------

    #[test]
    fn embargo_window_covers_midnight_to_eight() {
        // 0..8 应禁运；8 起恢复。
        assert!(is_embargoed(0), "00:00 应禁运");
        assert!(is_embargoed(7), "07:00 应禁运");
        assert!(!is_embargoed(8), "08:00 应恢复运行");
        assert!(!is_embargoed(9), "09:00 应运行");
        assert!(!is_embargoed(23), "23:00 应运行");
    }

    #[test]
    fn embargo_holds_for_all_hours_in_window() {
        for h in 0..24u32 {
            let expected = h < 8;
            assert_eq!(is_embargoed(h), expected, "小时 {h} 的禁运判定不符");
        }
    }

    #[test]
    fn current_hour_is_in_range() {
        let h = current_hour();
        assert!(h < 24, "当前小时应落在 [0,24)");
    }

    // ------------------------------------------------------------------
    // Options 解析与默认值
    // ------------------------------------------------------------------

    #[test]
    fn parse_inline_equals_form() {
        let opts = Options::parse([
            "--platform=w,m",
            "--limit=100",
            "--delay=200",
            "--1688id=12345",
        ])
        .unwrap();
        assert_eq!(opts.platform.as_deref(), Some("w,m"));
        assert_eq!(opts.limit, Some(100));
        assert_eq!(opts.delay_ms, Some(200));
        assert_eq!(opts.tabaono.as_deref(), Some("12345"));
    }

    #[test]
    fn parse_space_separated_form() {
        let opts = Options::parse([
            "--platform",
            "r",
            "--limit",
            "5",
            "--delay",
            "0",
            "--1688id",
            "999",
        ])
        .unwrap();
        assert_eq!(opts.platform.as_deref(), Some("r"));
        assert_eq!(opts.limit, Some(5));
        assert_eq!(opts.delay_ms, Some(0));
        assert_eq!(opts.tabaono.as_deref(), Some("999"));
    }

    #[test]
    fn parse_defaults_when_absent() {
        let opts = Options::parse(Vec::<String>::new()).unwrap();
        assert_eq!(opts, Options::default());
        // 默认平台为全部 5 个，顺序与合法集合一致。
        assert_eq!(opts.platforms(), vec!["w", "m", "r", "y", "yp"]);
        // 默认请求间隔 500ms。
        assert_eq!(opts.delay(), Duration::from_millis(500));
        // 默认不限制。
        assert_eq!(opts.effective_limit(), None);
    }

    #[test]
    fn parse_rejects_non_numeric_limit_and_delay() {
        assert!(matches!(
            Options::parse(["--limit=abc"]),
            Err(AppError::Validation(_))
        ));
        assert!(matches!(
            Options::parse(["--delay=x"]),
            Err(AppError::Validation(_))
        ));
    }

    #[test]
    fn platforms_intersect_with_valid_set_and_dedup_order() {
        // 含非法项 q/zz + 重复 + 乱序；结果按合法集合顺序、忽略非法、去重。
        let opts = Options {
            platform: Some("yp,q,w,zz,w".to_string()),
            ..Default::default()
        };
        assert_eq!(opts.platforms(), vec!["w", "yp"]);
    }

    #[test]
    fn platforms_fallback_to_all_when_all_invalid() {
        let opts = Options {
            platform: Some("q,zz".to_string()),
            ..Default::default()
        };
        assert_eq!(opts.platforms(), vec!["w", "m", "r", "y", "yp"]);
    }

    #[test]
    fn effective_limit_treats_zero_as_unlimited() {
        let opts = Options {
            limit: Some(0),
            ..Default::default()
        };
        assert_eq!(opts.effective_limit(), None);
        let opts2 = Options {
            limit: Some(7),
            ..Default::default()
        };
        assert_eq!(opts2.effective_limit(), Some(7));
    }

    #[test]
    fn orderid_validation_accepts_digits_only() {
        assert!(is_valid_1688_orderid("1234567890"));
        assert!(!is_valid_1688_orderid(""));
        assert!(!is_valid_1688_orderid("   "));
        assert!(!is_valid_1688_orderid("12a45"));
        assert!(!is_valid_1688_orderid("12-45"));
    }

    // ------------------------------------------------------------------
    // 离线替身：内存 store + 可控失败 provider
    // ------------------------------------------------------------------

    /// 内存 store：按平台返回预置待更新列表，记录所有成功落库的 tabaono。
    struct MemStore {
        per_platform: HashMap<String, Vec<PendingPurchase>>,
        saved: Mutex<Vec<String>>,
        /// 落库时对这些 tabaono 故意失败（模拟 DB 写失败）。
        save_fail_for: Vec<String>,
    }

    impl MemStore {
        fn new(per_platform: HashMap<String, Vec<PendingPurchase>>) -> Self {
            Self {
                per_platform,
                saved: Mutex::new(Vec::new()),
                save_fail_for: Vec::new(),
            }
        }

        fn saved(&self) -> Vec<String> {
            self.saved.lock().unwrap().clone()
        }
    }

    #[async_trait]
    impl LogisticsStore for MemStore {
        async fn list_pending(
            &self,
            platform: &str,
            tabaono: Option<&str>,
            limit: Option<u32>,
        ) -> Result<Vec<PendingPurchase>, AppError> {
            let mut rows = self.per_platform.get(platform).cloned().unwrap_or_default();
            if let Some(t) = tabaono {
                rows.retain(|r| r.tabaono == t);
            }
            if let Some(n) = limit {
                rows.truncate(n as usize);
            }
            Ok(rows)
        }

        async fn save_logistics(
            &self,
            item: &PendingPurchase,
            _trace: &LogisticsTrace,
        ) -> Result<(), AppError> {
            if self.save_fail_for.contains(&item.tabaono) {
                return Err(AppError::Db(sqlx::Error::PoolClosed));
            }
            self.saved.lock().unwrap().push(item.tabaono.clone());
            Ok(())
        }
    }

    /// 可控 provider：对 `fail_for` 中的 tabaono 返回 ExternalApi 失败，其余成功。
    /// 记录每次被查询的 tabaono，用于断言「失败不中断整批」。
    struct MockProvider {
        fail_for: Vec<String>,
        queried: Mutex<Vec<String>>,
    }

    impl MockProvider {
        fn new(fail_for: &[&str]) -> Self {
            Self {
                fail_for: fail_for.iter().map(|s| s.to_string()).collect(),
                queried: Mutex::new(Vec::new()),
            }
        }

        fn queried(&self) -> Vec<String> {
            self.queried.lock().unwrap().clone()
        }
    }

    #[async_trait]
    impl PurchaseProvider for MockProvider {
        async fn query_logistics(&self, order_no: &str) -> Result<LogisticsTrace, AppError> {
            self.queried.lock().unwrap().push(order_no.to_string());
            if self.fail_for.iter().any(|f| f == order_no) {
                return Err(AppError::ExternalApi {
                    provider: "1688".to_string(),
                    detail: format!("simulated failure for {order_no}"),
                });
            }
            Ok(LogisticsTrace {
                status: "运输中".to_string(),
                steps: vec![LogisticsStep {
                    time: "2024-01-01 00:00:00".to_string(),
                    description: "运输途中".to_string(),
                }],
                signed: false,
            })
        }

        async fn fetch_product(&self, _item_id: &str) -> Result<ProductInfo, AppError> {
            Err(AppError::ExternalApi {
                provider: "1688".to_string(),
                detail: "fetch_product not used in this job".to_string(),
            })
        }
    }

    fn pending(order_item_id: i64, platform: &str, tabaono: &str) -> PendingPurchase {
        PendingPurchase {
            order_item_id,
            order_id: order_item_id * 10,
            platform: platform.to_string(),
            tabaono: tabaono.to_string(),
        }
    }

    fn single_platform_store(platform: &str, rows: Vec<PendingPurchase>) -> MemStore {
        let mut map = HashMap::new();
        map.insert(platform.to_string(), rows);
        MemStore::new(map)
    }

    /// 仅处理单平台、delay=0，避免遍历默认 5 平台拖慢测试。
    fn opts_single_platform(platform: &str) -> Options {
        Options {
            platform: Some(platform.to_string()),
            delay_ms: Some(0),
            ..Default::default()
        }
    }

    // ------------------------------------------------------------------
    // Requirements 6.4：单条 API 失败不应中断整批
    // ------------------------------------------------------------------

    #[tokio::test]
    async fn one_item_api_failure_does_not_abort_batch() {
        let rows = vec![
            pending(1, "w", "1001"),
            pending(2, "w", "1002"), // 这条 API 失败
            pending(3, "w", "1003"),
        ];
        let store = single_platform_store("w", rows);
        let provider = MockProvider::new(&["1002"]);

        let outcome = run_batch(&store, &provider, &opts_single_platform("w")).await;

        // 三条全部被尝试查询（失败的 1002 没有中断后续 1003）。
        assert_eq!(provider.queried(), vec!["1001", "1002", "1003"]);
        // 1001、1003 成功落库；1002 失败被隔离。
        assert_eq!(outcome.success, 2);
        assert_eq!(outcome.failed, 1);
        assert_eq!(outcome.skipped, 0);
        assert_eq!(outcome.total(), 3);
        // 仅成功的两条落库。
        assert_eq!(store.saved(), vec!["1001".to_string(), "1003".to_string()]);
    }

    #[tokio::test]
    async fn save_failure_is_isolated_and_counted() {
        let rows = vec![pending(1, "w", "2001"), pending(2, "w", "2002")];
        let mut store = single_platform_store("w", rows);
        store.save_fail_for = vec!["2002".to_string()];
        let provider = MockProvider::new(&[]);

        let outcome = run_batch(&store, &provider, &opts_single_platform("w")).await;

        // 两条都查询成功，但 2002 落库失败被隔离。
        assert_eq!(provider.queried(), vec!["2001", "2002"]);
        assert_eq!(outcome.success, 1);
        assert_eq!(outcome.failed, 1);
        assert_eq!(store.saved(), vec!["2001".to_string()]);
    }

    #[tokio::test]
    async fn invalid_orderid_is_skipped_without_api_call() {
        let rows = vec![
            pending(1, "w", "3001"),
            pending(2, "w", "bad-id"), // 非法 → 跳过、不调用 API
            pending(3, "w", "3003"),
        ];
        let store = single_platform_store("w", rows);
        let provider = MockProvider::new(&[]);

        let outcome = run_batch(&store, &provider, &opts_single_platform("w")).await;

        // 非法订单号不触发 API 查询。
        assert_eq!(provider.queried(), vec!["3001", "3003"]);
        assert_eq!(outcome.success, 2);
        assert_eq!(outcome.skipped, 1);
        assert_eq!(outcome.failed, 0);
    }

    #[tokio::test]
    async fn limit_caps_rows_per_platform() {
        let rows = vec![
            pending(1, "w", "4001"),
            pending(2, "w", "4002"),
            pending(3, "w", "4003"),
        ];
        let store = single_platform_store("w", rows);
        let provider = MockProvider::new(&[]);
        let opts = Options {
            platform: Some("w".to_string()),
            limit: Some(2),
            delay_ms: Some(0),
            ..Default::default()
        };

        let outcome = run_batch(&store, &provider, &opts).await;
        assert_eq!(outcome.total(), 2);
        assert_eq!(provider.queried().len(), 2);
    }

    #[tokio::test]
    async fn tabaono_filter_targets_single_order() {
        let rows = vec![pending(1, "w", "5001"), pending(2, "w", "5002")];
        let store = single_platform_store("w", rows);
        let provider = MockProvider::new(&[]);
        let opts = Options {
            platform: Some("w".to_string()),
            tabaono: Some("5002".to_string()),
            delay_ms: Some(0),
            ..Default::default()
        };

        let outcome = run_batch(&store, &provider, &opts).await;
        assert_eq!(provider.queried(), vec!["5002"]);
        assert_eq!(outcome.success, 1);
        assert_eq!(store.saved(), vec!["5002".to_string()]);
    }

    #[tokio::test]
    async fn empty_pending_yields_zero_outcome() {
        let store = single_platform_store("w", Vec::new());
        let provider = MockProvider::new(&[]);
        let outcome = run_batch(&store, &provider, &opts_single_platform("w")).await;
        assert_eq!(outcome, BatchOutcome::default());
        assert!(provider.queried().is_empty());
    }
}
