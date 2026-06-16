//! 统计服务：采购员业绩统计（caigou_stats）/ 采购状态统计 / 利润核算（profit accounting）。
//!
//! 对应 Task 15.1 / Requirements 6.2。本模块忠实复刻旧 PHP 系统的三套统计口径，并把可在
//! 无数据库环境下验证的核心公式抽成**纯函数**（利润核算、运费/邮费分摊、参与利润核算的
//! 状态集合），SQL 聚合函数则针对**租户库**（`&MySqlPool`）以 SQLx 运行时 API
//! （`query` / `query_as` + 显式 SQL，不使用 `query!` 宏）实现。
//!
//! 旧系统在 6 张镜像宽表 `ph_order{y,r,w,m,q,yp}` 上做统计，新系统已规范化：
//! 旧列 → 新列映射：
//! - `caigou_user`（采购人）   → `purchases.caigou_user`
//! - `caigoutime`（采购时间）  → `purchases.caigou_time`
//! - `amount`（1688 采购金额）→ `purchases.cn_amount`
//! - `tabaono`（1688 订单号）  → `purchases.tabaono`
//! - `comamount`（佣金额）     → `purchases.com_amount`
//! - `beizhu`（流程状态）      → `order_items.purchase_status`（已剥离货源语义）
//! - 平台（旧靠表名区分）      → `orders.platform`
//!
//! 三套口径：
//! 1. **采购员业绩统计**（`plugins/caigou_stats/index.php`）：按采购人聚合完成采购单数、
//!    采购金额（SUM）与去重 1688 订单数（COUNT DISTINCT tabaono）。
//! 2. **采购状态统计**（`cron/caigou_status_stats.php`）：按流程状态分组计数的快照。
//! 3. **利润核算**（`plugins/profit-analysis/common.php` 的 `calculateProfit`）：
//!    售价×扣点%×汇率 − 运费 − 采购金额 = 实际利润。

use serde::Serialize;
use sqlx::{FromRow, MySqlPool};

use crate::error::AppError;
use crate::models::order::PurchaseStatus;

// ============================================================================
// 利润核算口径（纯函数，复刻 profit-analysis/common.php::calculateProfit）
// ============================================================================

/// 利润计算默认扣点（百分比）。旧系统 `ph_user.profit_deduction` 默认 70。
pub const DEFAULT_PROFIT_DEDUCTION: f64 = 70.0;

/// 利润计算默认固定汇率（JPY→CNY）。旧 `setting.ini`【利润计算设置】默认 0.0480。
pub const DEFAULT_EXCHANGE_RATE: f64 = 0.0480;

/// 利润计算默认运费（CNY）。旧 `setting.ini`【利润计算设置】默认 40。
pub const DEFAULT_SHIPPING: f64 = 40.0;

/// 单条记录的利润核算输入（组件金额）。
///
/// 字段语义忠实对应旧 `calculateProfit($row, ...)` 的入参：
/// - `item_price`：单价（旧 `itemPrice`）。Mercari/雅虎拍卖无单价，传 0 即回退到 `total_item_price`。
/// - `total_item_price`：商品总价（旧 `totalItemPrice`），作为 `item_price<=0` 时的回退。
/// - `postage_price`：邮费（旧 `postagePrice`，已完成分摊，见 [`share_postage`]）。
/// - `shipping`：国际运费（已按订单内记录数分摊，见 [`share_shipping`]，单位 CNY）。
/// - `cost`：采购金额（旧 `amount`，新 `purchases.cn_amount`，单位 CNY）。
/// - `deduction`：店铺扣点（百分比，0–100）。
/// - `exchange_rate`：汇率（JPY→CNY）。
#[derive(Debug, Clone, Copy)]
pub struct ProfitInput {
    pub item_price: f64,
    pub total_item_price: f64,
    pub postage_price: f64,
    pub shipping: f64,
    pub cost: f64,
    pub deduction: f64,
    pub exchange_rate: f64,
}

/// 单条记录的利润核算结果。
#[derive(Debug, Clone, Copy, Serialize, PartialEq)]
pub struct ProfitResult {
    /// 实际采用的单价（`item_price>0` 时取之，否则回退 `total_item_price`）。
    pub unit_price: f64,
    /// 售价 = 单价 + 邮费（JPY）。
    pub sale_price: f64,
    /// 实际收入 = 售价 × 扣点% × 汇率（CNY）。
    pub actual_income: f64,
    /// 参考采购价 = 实际收入 − 运费（CNY）。
    pub ref_cost: f64,
    /// 实际利润 = 参考采购价 − 采购金额（CNY）。
    pub real_profit: f64,
    /// 实际利润率 = 实际利润 / 汇率 / 售价 × 100（百分比）。
    pub real_profit_rate: f64,
}

/// 核心利润核算公式（纯函数）。忠实复刻 `calculateProfit`：
///
/// ```text
/// unitPrice      = itemPrice > 0 ? itemPrice : totalItemPrice
/// salePrice      = unitPrice + postagePrice
/// actualIncome   = salePrice × (deduction / 100) × exchangeRate
/// refCost        = actualIncome − shipping            // 参考采购价
/// realProfit     = refCost − cost                     // 实际利润
/// realProfitRate = (exchangeRate>0 && salePrice>0)
///                  ? realProfit / exchangeRate / salePrice × 100
///                  : 0
/// ```
pub fn calculate_profit(input: &ProfitInput) -> ProfitResult {
    // 单价：优先 item_price，否则回退 total_item_price（Mercari/雅虎拍卖无单价列）。
    let unit_price = if input.item_price > 0.0 {
        input.item_price
    } else {
        input.total_item_price
    };

    let sale_price = unit_price + input.postage_price;
    let actual_income = sale_price * (input.deduction / 100.0) * input.exchange_rate;
    let ref_cost = actual_income - input.shipping;
    let real_profit = ref_cost - input.cost;
    let real_profit_rate = if input.exchange_rate > 0.0 && sale_price > 0.0 {
        real_profit / input.exchange_rate / sale_price * 100.0
    } else {
        0.0
    };

    ProfitResult {
        unit_price,
        sale_price,
        actual_income,
        ref_cost,
        real_profit,
        real_profit_rate,
    }
}

/// 邮费分摊（纯函数）。复刻旧 Y/R 平台逻辑：当订单内商品数 > 1 且本单有邮费时，
/// 总邮费按订单内记录数平均分摊；否则按记录原始邮费返回。
///
/// - `should_share == true`：返回 `total_postage / order_count`（`order_count<=0` 时返回 0）。
/// - `should_share == false`：返回 `record_postage`（记录自身邮费，不分摊）。
pub fn share_postage(
    total_postage: f64,
    record_postage: f64,
    order_count: i64,
    should_share: bool,
) -> f64 {
    if should_share {
        if order_count > 0 {
            total_postage / order_count as f64
        } else {
            0.0
        }
    } else {
        record_postage
    }
}

/// 国际运费分摊（纯函数）。复刻旧逻辑：
/// - 有实际运费：`total_actual_shipping / order_count`（按订单内记录数平均分摊）。
/// - 无实际运费：`default_shipping / order_count`（默认运费的平均值）。
///
/// `order_count<=0` 时返回 0，避免除零。
pub fn share_shipping(
    total_actual_shipping: f64,
    default_shipping: f64,
    order_count: i64,
    has_actual_shipping: bool,
) -> f64 {
    if order_count <= 0 {
        return 0.0;
    }
    if has_actual_shipping {
        total_actual_shipping / order_count as f64
    } else {
        default_shipping / order_count as f64
    }
}

/// 建议售价计算结果。
#[derive(Debug, Clone, Copy, Serialize, PartialEq)]
pub struct SuggestedPrice {
    /// 是否需要调价（设置了阈值且利润率低于阈值且有采购成本）。
    pub need_adjust: bool,
    /// 建议售价（向上取整）；分母 ≤ 0 时无解返回 `None`。
    pub suggested_price: Option<f64>,
}

/// 建议售价（纯函数）。复刻 `calculateSuggestedPrice`：
///
/// ```text
/// needAdjust 当且仅当 threshold 已设置 ∧ realProfitRate < threshold ∧ cost > 0
/// totalCost   = cost + shipping
/// denominator = exchangeRate × (deduction/100 − targetRate/100)
/// suggested   = denominator > 0 ? ceil(totalCost / denominator) : 无解
/// ```
pub fn calculate_suggested_price(
    real_profit_rate: f64,
    profit_threshold: Option<f64>,
    cost: f64,
    shipping: f64,
    target_profit_rate: f64,
    deduction: f64,
    exchange_rate: f64,
) -> SuggestedPrice {
    let need_adjust = match profit_threshold {
        Some(th) => real_profit_rate < th && cost > 0.0,
        None => false,
    };

    let mut suggested_price = None;
    if need_adjust {
        let total_cost = cost + shipping;
        let target_rate = target_profit_rate / 100.0;
        let deduction_rate = deduction / 100.0;
        let denominator = exchange_rate * (deduction_rate - target_rate);
        if denominator > 0.0 {
            suggested_price = Some((total_cost / denominator).ceil());
        }
    }

    SuggestedPrice {
        need_adjust,
        suggested_price,
    }
}

/// 某流程状态是否参与利润核算（纯函数）。
///
/// 忠实复刻 `profit-analysis/common.php` 的 `$caigou_status_list`（11 项，**不含**「待处理」
/// 与「已取消」——已取消订单不参与利润核算，待处理尚未进入采购流程）：
///
/// ```text
/// 国内采购-准备 / 国内采购--问题 / 国内采购-已采购 / 国内采购-TB/PDD已采购 /
/// 发货中 / 已到货 / 已发货代订单 / 已发日本 / 已发出荷通知 / 问题订单(后台处理) / 已到货问题件
/// ```
pub fn participates_in_profit(status: PurchaseStatus) -> bool {
    !matches!(status, PurchaseStatus::Pending | PurchaseStatus::Cancelled)
}

/// 参与利润核算的全部流程状态（用于 SQL `IN (...)` 过滤）。
pub fn profit_status_labels() -> Vec<&'static str> {
    PurchaseStatus::ALL
        .iter()
        .filter(|s| participates_in_profit(**s))
        .map(|s| s.as_str())
        .collect()
}

/// 把 DECIMAL（以字符串无损承载）解析为 `f64`；空/非法值按 0 处理。
fn parse_decimal(s: &str) -> f64 {
    s.trim().parse::<f64>().unwrap_or(0.0)
}

// ============================================================================
// 采购员业绩统计（caigou_stats）—— 复刻 plugins/caigou_stats/index.php
// ============================================================================

/// 单个采购员的业绩统计结果（在日期范围内）。
///
/// 复刻旧系统口径：
/// - `order_count`：完成采购单数 = 该采购员名下、`caigou_time` 非空的采购行数
///   （旧 `COUNT(*) WHERE caigou_user!='' AND caigoutime!=''`）。
/// - `total_amount`：采购金额合计 = `SUM(cn_amount)`，仅计入 `tabaono` 非空且 `cn_amount>0`
///   的行（旧采购金额统计的附加过滤）。
/// - `unique_orders`：去重 1688 订单数 = `COUNT(DISTINCT tabaono)`（同上过滤）。
#[derive(Debug, Clone, Serialize, FromRow, PartialEq)]
pub struct CaigouUserStat {
    /// 采购人。
    pub caigou_user: String,
    /// 完成采购单数。
    pub order_count: i64,
    /// 采购金额合计（DECIMAL 以字符串无损承载）。
    pub total_amount: String,
    /// 去重 1688 订单数（COUNT DISTINCT tabaono）。
    pub unique_orders: i64,
}

/// 采购员业绩统计：在 `[date_start, date_end]`（按 `purchases.caigou_time` 的日期）范围内，
/// 按采购人聚合完成采购单数、采购金额与去重 1688 订单数（Requirements 6.2）。
///
/// 与旧 `caigou_stats` 一致的过滤：`caigou_user` 非空、`caigou_time` 非空；采购金额/去重订单
/// 数附加 `tabaono` 非空且 `cn_amount>0`。结果按完成采购单数降序（旧 `uasort` by total）。
///
/// `platform` 为 `Some(code)` 时仅统计该平台（`orders.platform`），`None` 统计全部平台。
/// 日期以 `YYYY-MM-DD` 字符串传入（与旧系统一致），SQL 侧用 `DATE(caigou_time)` 比较。
pub async fn caigou_stats(
    pool: &MySqlPool,
    date_start: &str,
    date_end: &str,
    platform: Option<&str>,
) -> Result<Vec<CaigouUserStat>, AppError> {
    // 完成采购单数用基础过滤；金额/去重订单数用附加过滤（tabaono 非空 & cn_amount>0），
    // 用条件聚合在一条 SQL 内同时复刻两套口径。
    let mut sql = String::from(
        "SELECT p.caigou_user AS caigou_user, \
                COUNT(*) AS order_count, \
                COALESCE(SUM(CASE WHEN p.tabaono <> '' AND p.cn_amount > 0 \
                                  THEN p.cn_amount ELSE 0 END), 0) AS total_amount, \
                COUNT(DISTINCT CASE WHEN p.tabaono <> '' AND p.cn_amount > 0 \
                                    THEN p.tabaono END) AS unique_orders \
         FROM `purchases` p \
         JOIN `order_items` oi ON oi.id = p.order_item_id \
         JOIN `orders` o ON o.id = oi.order_id \
         WHERE p.caigou_user IS NOT NULL AND p.caigou_user <> '' \
           AND p.caigou_time IS NOT NULL \
           AND DATE(p.caigou_time) BETWEEN ? AND ?",
    );
    if platform.is_some() {
        sql.push_str(" AND o.platform = ?");
    }
    sql.push_str(" GROUP BY p.caigou_user ORDER BY order_count DESC");

    let mut query = sqlx::query_as::<_, CaigouUserStat>(&sql)
        .bind(date_start)
        .bind(date_end);
    if let Some(code) = platform {
        query = query.bind(code);
    }

    Ok(query.fetch_all(pool).await?)
}

/// 采购金额汇总（跨全部采购员）。便于概览卡片展示。
#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct CaigouAmountSummary {
    /// 采购总金额。
    pub total_amount: f64,
    /// 去重 1688 订单数合计。
    pub total_unique_orders: i64,
    /// 平均每单金额（`total_amount / total_unique_orders`，去重订单数为 0 时为 0）。
    pub avg_per_order: f64,
}

/// 由采购员业绩列表汇总出总金额/总订单数/平均每单（纯函数，便于复用与单测）。
///
/// 复刻旧「采购金额」标签页汇总卡片：总金额求和、去重订单数求和、平均每单 = 总金额 / 去重订单数。
pub fn summarize_caigou_amount(stats: &[CaigouUserStat]) -> CaigouAmountSummary {
    let total_amount: f64 = stats.iter().map(|s| parse_decimal(&s.total_amount)).sum();
    let total_unique_orders: i64 = stats.iter().map(|s| s.unique_orders).sum();
    let avg_per_order = if total_unique_orders > 0 {
        total_amount / total_unique_orders as f64
    } else {
        0.0
    };
    CaigouAmountSummary {
        total_amount,
        total_unique_orders,
        avg_per_order,
    }
}

// ============================================================================
// 采购状态统计 —— 复刻 cron/caigou_status_stats.php（按流程状态分组计数的快照）
// ============================================================================

/// 单个采购状态的计数（快照）。
#[derive(Debug, Clone, Serialize, FromRow, PartialEq)]
pub struct PurchaseStatusStat {
    /// 流程状态名（`order_items.purchase_status`）。
    pub status_name: String,
    /// 该状态的子商品数量。
    pub status_count: i64,
}

/// 采购状态统计结果：分组明细 + 总数。
#[derive(Debug, Clone, Serialize, PartialEq)]
pub struct PurchaseStatusStatsResult {
    /// 按状态分组的计数（按数量降序）。
    pub stats: Vec<PurchaseStatusStat>,
    /// 全部子商品总数（各状态计数之和）。
    pub total: i64,
}

/// 采购状态统计（快照）：按 `order_items.purchase_status` 分组计数（Requirements 6.2）。
///
/// 复刻旧 `caigou_status_stats.php` 的 `SELECT IFNULL(beizhu,'未设置'), COUNT(*) GROUP BY beizhu
/// ORDER BY status_count DESC`，但作用于规范化后的 `order_items.purchase_status`（取值恒非空，
/// 默认「待处理」，故无需 `IFNULL`）。`platform` 为 `Some(code)` 时仅统计该平台。
pub async fn caigou_status_stats(
    pool: &MySqlPool,
    platform: Option<&str>,
) -> Result<PurchaseStatusStatsResult, AppError> {
    let mut sql = String::from(
        "SELECT oi.purchase_status AS status_name, COUNT(*) AS status_count \
         FROM `order_items` oi",
    );
    if platform.is_some() {
        sql.push_str(" JOIN `orders` o ON o.id = oi.order_id WHERE o.platform = ?");
    }
    sql.push_str(" GROUP BY oi.purchase_status ORDER BY status_count DESC");

    let mut query = sqlx::query_as::<_, PurchaseStatusStat>(&sql);
    if let Some(code) = platform {
        query = query.bind(code);
    }

    let stats = query.fetch_all(pool).await?;
    let total = stats.iter().map(|s| s.status_count).sum();
    Ok(PurchaseStatusStatsResult { stats, total })
}

// ============================================================================
// 利润核算聚合（profit accounting）—— 在租户库上套用纯利润公式
// ============================================================================

/// 利润核算聚合参数。
#[derive(Debug, Clone)]
pub struct ProfitReportParams {
    /// 起始日期（按 `orders.order_date` 的日期，`YYYY-MM-DD`）。
    pub date_start: String,
    /// 结束日期（`YYYY-MM-DD`）。
    pub date_end: String,
    /// 平台过滤（`orders.platform`），`None` 为全部平台。
    pub platform: Option<String>,
    /// 店铺扣点（百分比）。
    pub deduction: f64,
    /// 汇率（JPY→CNY）。
    pub exchange_rate: f64,
    /// 默认国际运费（CNY，每订单一次）。
    pub default_shipping: f64,
}

impl Default for ProfitReportParams {
    fn default() -> Self {
        ProfitReportParams {
            date_start: String::new(),
            date_end: String::new(),
            platform: None,
            deduction: DEFAULT_PROFIT_DEDUCTION,
            exchange_rate: DEFAULT_EXCHANGE_RATE,
            default_shipping: DEFAULT_SHIPPING,
        }
    }
}

/// 利润核算聚合结果（跨订单合计）。
#[derive(Debug, Clone, Copy, Serialize, PartialEq)]
pub struct ProfitReport {
    /// 参与核算的订单数。
    pub order_count: i64,
    /// 售价合计（Σ salePrice，JPY）。
    pub total_revenue: f64,
    /// 采购成本合计（Σ cost，CNY）。
    pub total_cost: f64,
    /// 参考采购价合计（Σ refCost，CNY）。
    pub total_ref_cost: f64,
    /// 实际利润合计（Σ realProfit，CNY）。
    pub total_profit: f64,
    /// 加权平均实际利润率（百分比）：Σ realProfit / 汇率 / Σ salePrice × 100。
    pub avg_profit_rate: f64,
}

/// 利润核算 SQL 行（按订单聚合后的组件金额）。
#[derive(Debug, Clone, FromRow)]
struct ProfitOrderRow {
    /// 订单商品合计售价（`orders.total_item_price`，JPY）。
    total_item_price: String,
    /// 订单邮费（`orders.postage_price`，JPY）。
    postage_price: String,
    /// 该订单参与核算子商品的采购金额合计（Σ `purchases.cn_amount`，CNY）。
    cost: String,
}

/// 利润核算聚合（Requirements 6.2）：在日期范围内对**含参与核算子商品**的订单逐单套用
/// 利润公式（[`calculate_profit`]）后汇总。
///
/// 口径说明（旧宽表 → 新规范化模型的对应）：
/// - 旧宽表每行即一条订单明细，售价列 `itemPrice/totalItemPrice/postagePrice` 与采购金额
///   `amount` 同行。新模型把售价（A 区金额）整单共享放在 `orders`（`total_item_price` /
///   `postage_price`），采购金额下沉到 `purchases.cn_amount`（子商品级）。
/// - 因此本聚合以**订单**为售价单位：售价 = `total_item_price + postage_price`，采购成本 =
///   该订单全部参与核算子商品的 `cn_amount` 之和，国际运费按每订单一次 `default_shipping`
///   计（一单一次国际发货）。随后套用与旧 `calculateProfit` 完全一致的公式：
///   `实际收入 = 售价 × 扣点% × 汇率`，`参考采购价 = 实际收入 − 运费`，
///   `实际利润 = 参考采购价 − 采购金额`。
/// - 仅纳入 `purchase_status` 属于 [`profit_status_labels`]（11 项，不含待处理/已取消）的子商品；
///   一个订单只要含至少一条这类子商品即纳入核算。
pub async fn profit_report(
    pool: &MySqlPool,
    params: &ProfitReportParams,
) -> Result<ProfitReport, AppError> {
    let labels = profit_status_labels();
    // 动态构造状态 IN (...) 占位符。
    let placeholders = std::iter::repeat("?")
        .take(labels.len())
        .collect::<Vec<_>>()
        .join(", ");

    let mut sql = format!(
        "SELECT o.total_item_price AS total_item_price, \
                o.postage_price AS postage_price, \
                COALESCE(SUM(p.cn_amount), 0) AS cost \
         FROM `orders` o \
         JOIN `order_items` oi ON oi.order_id = o.id AND oi.purchase_status IN ({placeholders}) \
         LEFT JOIN `purchases` p ON p.order_item_id = oi.id \
         WHERE o.order_date IS NOT NULL \
           AND DATE(o.order_date) BETWEEN ? AND ?"
    );
    if params.platform.is_some() {
        sql.push_str(" AND o.platform = ?");
    }
    sql.push_str(" GROUP BY o.id, o.total_item_price, o.postage_price");

    let mut query = sqlx::query_as::<_, ProfitOrderRow>(&sql);
    for label in &labels {
        query = query.bind(*label);
    }
    query = query.bind(&params.date_start).bind(&params.date_end);
    if let Some(code) = &params.platform {
        query = query.bind(code);
    }

    let rows = query.fetch_all(pool).await?;
    Ok(aggregate_profit_rows(&rows, params))
}

/// 把订单级组件金额行聚合为利润报表（纯函数，便于离线单测）。
fn aggregate_profit_rows(rows: &[ProfitOrderRow], params: &ProfitReportParams) -> ProfitReport {
    let mut order_count: i64 = 0;
    let mut total_revenue = 0.0;
    let mut total_cost = 0.0;
    let mut total_ref_cost = 0.0;
    let mut total_profit = 0.0;

    for row in rows {
        order_count += 1;
        let item_price = parse_decimal(&row.total_item_price);
        let postage = parse_decimal(&row.postage_price);
        let cost = parse_decimal(&row.cost);

        let input = ProfitInput {
            // 订单级售价已是总价，直接作为单价，邮费单列；total_item_price 同值作回退。
            item_price,
            total_item_price: item_price,
            postage_price: postage,
            shipping: params.default_shipping,
            cost,
            deduction: params.deduction,
            exchange_rate: params.exchange_rate,
        };
        let r = calculate_profit(&input);

        total_revenue += r.sale_price;
        total_cost += cost;
        total_ref_cost += r.ref_cost;
        total_profit += r.real_profit;
    }

    // 加权平均利润率：与单条公式一致，用合计利润 / 汇率 / 合计售价。
    let avg_profit_rate = if params.exchange_rate > 0.0 && total_revenue > 0.0 {
        total_profit / params.exchange_rate / total_revenue * 100.0
    } else {
        0.0
    };

    ProfitReport {
        order_count,
        total_revenue,
        total_cost,
        total_ref_cost,
        total_profit,
        avg_profit_rate,
    }
}

// ============================================================================
// 单元测试 / 属性测试（纯公式逻辑，无需 DB）
//
// SQL 聚合函数（caigou_stats / caigou_status_stats / profit_report）依赖租户库连接，
// 无法离线运行；此处覆盖其背后的纯口径函数：利润公式、运费/邮费分摊、参与核算的状态集合、
// 金额汇总、订单级利润聚合。
// ============================================================================
#[cfg(test)]
mod tests {
    use super::*;
    use proptest::prelude::*;

    fn approx(a: f64, b: f64) {
        assert!((a - b).abs() < 1e-6, "expected {b}, got {a}");
    }

    // --- calculate_profit：与 PHP calculateProfit 逐项核对 ---

    #[test]
    fn profit_matches_php_formula_negative_case() {
        // itemPrice=1000, postage=0, deduction=70, rate=0.048, shipping=40, cost=20
        let r = calculate_profit(&ProfitInput {
            item_price: 1000.0,
            total_item_price: 1000.0,
            postage_price: 0.0,
            shipping: 40.0,
            cost: 20.0,
            deduction: 70.0,
            exchange_rate: 0.048,
        });
        approx(r.unit_price, 1000.0);
        approx(r.sale_price, 1000.0);
        approx(r.actual_income, 33.6); // 1000 * 0.70 * 0.048
        approx(r.ref_cost, -6.4); // 33.6 - 40
        approx(r.real_profit, -26.4); // -6.4 - 20
        approx(r.real_profit_rate, -55.0); // -26.4 / 0.048 / 1000 * 100
    }

    #[test]
    fn profit_matches_php_formula_positive_case() {
        // itemPrice=5000, postage=500, deduction=70, rate=0.048, shipping=40, cost=100
        let r = calculate_profit(&ProfitInput {
            item_price: 5000.0,
            total_item_price: 5000.0,
            postage_price: 500.0,
            shipping: 40.0,
            cost: 100.0,
            deduction: 70.0,
            exchange_rate: 0.048,
        });
        approx(r.sale_price, 5500.0);
        approx(r.actual_income, 184.8); // 5500 * 0.7 * 0.048
        approx(r.ref_cost, 144.8); // 184.8 - 40
        approx(r.real_profit, 44.8); // 144.8 - 100
                                     // 44.8 / 0.048 / 5500 * 100
        approx(r.real_profit_rate, 44.8 / 0.048 / 5500.0 * 100.0);
    }

    #[test]
    fn profit_unit_price_falls_back_to_total_when_item_price_nonpositive() {
        // Mercari/雅虎拍卖：itemPrice=0 → 用 totalItemPrice。
        let r = calculate_profit(&ProfitInput {
            item_price: 0.0,
            total_item_price: 3000.0,
            postage_price: 0.0,
            shipping: 40.0,
            cost: 50.0,
            deduction: 70.0,
            exchange_rate: 0.048,
        });
        approx(r.unit_price, 3000.0);
        approx(r.sale_price, 3000.0);
    }

    #[test]
    fn profit_rate_is_zero_when_sale_price_zero() {
        let r = calculate_profit(&ProfitInput {
            item_price: 0.0,
            total_item_price: 0.0,
            postage_price: 0.0,
            shipping: 40.0,
            cost: 0.0,
            deduction: 70.0,
            exchange_rate: 0.048,
        });
        approx(r.sale_price, 0.0);
        approx(r.real_profit_rate, 0.0);
    }

    // --- 邮费 / 运费分摊 ---

    #[test]
    fn postage_sharing_divides_total_when_should_share() {
        approx(share_postage(900.0, 300.0, 3, true), 300.0);
        // 不分摊：用记录自身邮费。
        approx(share_postage(900.0, 300.0, 3, false), 300.0);
        // 分摊但 order_count<=0 → 0。
        approx(share_postage(900.0, 300.0, 0, true), 0.0);
    }

    #[test]
    fn shipping_sharing_actual_vs_default() {
        // 有实际运费：120 / 4 = 30。
        approx(share_shipping(120.0, 40.0, 4, true), 30.0);
        // 无实际运费：默认 40 / 4 = 10。
        approx(share_shipping(120.0, 40.0, 4, false), 10.0);
        // order_count<=0 → 0。
        approx(share_shipping(120.0, 40.0, 0, true), 0.0);
    }

    // --- 建议售价 ---

    #[test]
    fn suggested_price_matches_php() {
        // rate=10 < threshold=20, cost=100>0 → needAdjust
        // totalCost=140, denom=0.048*(0.70-0.25)=0.0216, ceil(140/0.0216)=6482
        let s = calculate_suggested_price(10.0, Some(20.0), 100.0, 40.0, 25.0, 70.0, 0.048);
        assert!(s.need_adjust);
        assert_eq!(s.suggested_price, Some(6482.0));
    }

    #[test]
    fn suggested_price_no_adjust_when_rate_above_threshold() {
        let s = calculate_suggested_price(30.0, Some(20.0), 100.0, 40.0, 25.0, 70.0, 0.048);
        assert!(!s.need_adjust);
        assert_eq!(s.suggested_price, None);
    }

    #[test]
    fn suggested_price_none_when_no_threshold() {
        let s = calculate_suggested_price(5.0, None, 100.0, 40.0, 25.0, 70.0, 0.048);
        assert!(!s.need_adjust);
        assert_eq!(s.suggested_price, None);
    }

    #[test]
    fn suggested_price_no_price_when_denominator_nonpositive() {
        // deduction% <= target% → denominator <= 0 → 无解（need_adjust 仍为真）。
        let s = calculate_suggested_price(5.0, Some(20.0), 100.0, 40.0, 80.0, 70.0, 0.048);
        assert!(s.need_adjust);
        assert_eq!(s.suggested_price, None);
    }

    // --- 参与利润核算的状态集合（复刻旧 caigou_status_list 的 11 项） ---

    #[test]
    fn profit_status_set_matches_legacy_list() {
        let expected = [
            "国内采购-准备",
            "国内采购--问题",
            "国内采购-已采购",
            "国内采购-TB/PDD已采购",
            "发货中",
            "已到货",
            "已发货代订单",
            "已发日本",
            "已发出荷通知",
            "已到货问题件",
            "问题订单(后台处理)",
        ];
        let mut actual = profit_status_labels();
        let mut expected_sorted = expected.to_vec();
        actual.sort();
        expected_sorted.sort();
        assert_eq!(actual, expected_sorted);
    }

    #[test]
    fn pending_and_cancelled_excluded_from_profit() {
        assert!(!participates_in_profit(PurchaseStatus::Pending));
        assert!(!participates_in_profit(PurchaseStatus::Cancelled));
        assert!(participates_in_profit(PurchaseStatus::CnPreparing));
        assert!(participates_in_profit(PurchaseStatus::ShippedToJapan));
        // 恰好 11 项参与。
        assert_eq!(profit_status_labels().len(), 11);
    }

    // --- 采购金额汇总 ---

    #[test]
    fn caigou_amount_summary_sums_and_averages() {
        let stats = vec![
            CaigouUserStat {
                caigou_user: "alice".into(),
                order_count: 5,
                total_amount: "1500.00".into(),
                unique_orders: 3,
            },
            CaigouUserStat {
                caigou_user: "bob".into(),
                order_count: 2,
                total_amount: "500.50".into(),
                unique_orders: 2,
            },
        ];
        let s = summarize_caigou_amount(&stats);
        approx(s.total_amount, 2000.50);
        assert_eq!(s.total_unique_orders, 5);
        approx(s.avg_per_order, 2000.50 / 5.0);
    }

    #[test]
    fn caigou_amount_summary_zero_orders_no_divide_by_zero() {
        let s = summarize_caigou_amount(&[]);
        approx(s.total_amount, 0.0);
        assert_eq!(s.total_unique_orders, 0);
        approx(s.avg_per_order, 0.0);
    }

    // --- 订单级利润聚合（aggregate_profit_rows 纯函数） ---

    #[test]
    fn aggregate_profit_rows_sums_components() {
        let params = ProfitReportParams {
            deduction: 70.0,
            exchange_rate: 0.048,
            default_shipping: 40.0,
            ..Default::default()
        };
        let rows = vec![
            ProfitOrderRow {
                total_item_price: "5000.00".into(),
                postage_price: "500.00".into(),
                cost: "100.00".into(),
            },
            ProfitOrderRow {
                total_item_price: "1000.00".into(),
                postage_price: "0.00".into(),
                cost: "20.00".into(),
            },
        ];
        let report = aggregate_profit_rows(&rows, &params);
        assert_eq!(report.order_count, 2);
        approx(report.total_revenue, 5500.0 + 1000.0);
        approx(report.total_cost, 120.0);
        // 单条利润：44.8 与 -26.4（见上方单测）→ 合计 18.4
        approx(report.total_profit, 44.8 + (-26.4));
        // refCost: 144.8 + (-6.4) = 138.4
        approx(report.total_ref_cost, 138.4);
    }

    #[test]
    fn aggregate_profit_rows_empty_is_zeroed() {
        let report = aggregate_profit_rows(&[], &ProfitReportParams::default());
        assert_eq!(report.order_count, 0);
        approx(report.total_profit, 0.0);
        approx(report.avg_profit_rate, 0.0);
    }

    // --- 属性测试 ---

    fn finite_amount() -> impl Strategy<Value = f64> {
        (0.0f64..1_000_000.0).prop_map(|v| (v * 100.0).round() / 100.0)
    }

    proptest! {
        /// 利润核算恒等式：realProfit ≡ refCost − cost ≡ actualIncome − shipping − cost。
        /// 不变式来自公式定义，对任意组件金额都成立（Requirements 6.2 的口径一致性）。
        #[test]
        fn prop_profit_identity_holds(
            item_price in finite_amount(),
            postage in finite_amount(),
            shipping in finite_amount(),
            cost in finite_amount(),
            deduction in 0.0f64..=100.0,
            rate in 0.001f64..1.0,
        ) {
            let r = calculate_profit(&ProfitInput {
                item_price,
                total_item_price: item_price,
                postage_price: postage,
                shipping,
                cost,
                deduction,
                exchange_rate: rate,
            });
            // 恒等式：refCost = actualIncome - shipping。
            prop_assert!((r.ref_cost - (r.actual_income - shipping)).abs() < 1e-6);
            // 恒等式：realProfit = refCost - cost。
            prop_assert!((r.real_profit - (r.ref_cost - cost)).abs() < 1e-6);
            // sale_price = unit_price + postage。
            prop_assert!((r.sale_price - (r.unit_price + postage)).abs() < 1e-6);
        }

        /// 单调性：固定其余组件时，采购成本 cost 越大，实际利润越小（严格递减）。
        #[test]
        fn prop_profit_decreases_as_cost_increases(
            item_price in finite_amount(),
            postage in finite_amount(),
            shipping in finite_amount(),
            cost in finite_amount(),
            delta in 0.01f64..1000.0,
        ) {
            let base = ProfitInput {
                item_price,
                total_item_price: item_price,
                postage_price: postage,
                shipping,
                cost,
                deduction: 70.0,
                exchange_rate: 0.048,
            };
            let higher = ProfitInput { cost: cost + delta, ..base };
            let p_base = calculate_profit(&base).real_profit;
            let p_higher = calculate_profit(&higher).real_profit;
            prop_assert!(p_higher < p_base, "更高采购成本应带来更低利润");
            // 差值恰为 delta。
            prop_assert!((p_base - p_higher - delta).abs() < 1e-6);
        }

        /// 订单级利润聚合 = 各订单单条利润之和（聚合与逐单计算一致）。
        #[test]
        fn prop_aggregate_equals_sum_of_orders(
            comps in prop::collection::vec((finite_amount(), finite_amount(), finite_amount()), 0..12),
        ) {
            let params = ProfitReportParams {
                deduction: 70.0,
                exchange_rate: 0.048,
                default_shipping: 40.0,
                ..Default::default()
            };
            let rows: Vec<ProfitOrderRow> = comps.iter().map(|(tip, pp, c)| ProfitOrderRow {
                total_item_price: format!("{tip:.2}"),
                postage_price: format!("{pp:.2}"),
                cost: format!("{c:.2}"),
            }).collect();

            let report = aggregate_profit_rows(&rows, &params);

            let mut expected_profit = 0.0;
            for (tip, pp, c) in &comps {
                let r = calculate_profit(&ProfitInput {
                    item_price: *tip,
                    total_item_price: *tip,
                    postage_price: *pp,
                    shipping: params.default_shipping,
                    cost: *c,
                    deduction: params.deduction,
                    exchange_rate: params.exchange_rate,
                });
                expected_profit += r.real_profit;
            }
            prop_assert_eq!(report.order_count, comps.len() as i64);
            prop_assert!((report.total_profit - expected_profit).abs() < 1e-6);
        }
    }
}
