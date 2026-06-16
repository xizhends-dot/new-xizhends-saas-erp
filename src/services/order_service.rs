//! 订单服务：`predict_source` 货源预判、履约分流路由、货源改判、运单号拆分。
//!
//! 已实现：`split_ship_numbers`（Task 8.13）、`predict_source` 货源预判 + 货源判定日志
//! （Task 8.7）。履约分流路由 / caigou_user 规则见 Task 8.9 / 8.11（后续追加）。

use std::collections::HashSet;

use sqlx::MySqlPool;

use crate::error::AppError;
use crate::models::order::SourceType;

/// 拆分逗号分隔的国内运单号字符串。
///
/// 忠实复刻旧系统 `update_jpship_logistics.php` 的拆分逻辑：
///
/// ```php
/// $shipnumber_list = array_map('trim', explode(',', $shipnumber));
/// $shipnumber_list = array_filter($shipnumber_list, function($v) { return $v !== ''; });
/// $shipnumber_list = array_values($shipnumber_list);
/// ```
///
/// 即：按逗号切分，对每段去除首尾空白，丢弃去空白后为空的段，保持原有顺序。
///
/// 纯函数，便于属性测试（Task 8.14）独立验证。
///
/// 后置条件（对应 Requirement 5.1 / 设计 Property 5）：返回的每个非空运单号
/// 恰好对应一条 `domestic_shipments` 记录，记录数量等于去除空段后的运单号个数。
pub fn split_ship_numbers(ship_number: &str) -> Vec<String> {
    ship_number
        .split(',')
        .map(|segment| segment.trim())
        .filter(|segment| !segment.is_empty())
        .map(|segment| segment.to_string())
        .collect()
}

// ============================================================================
// Task 8.7 —— predict_source 货源预判（设计 3.5 / Requirements 3.3、3.4）
//
// 纯逻辑（predict_source / ImportedItem / JpStockIndex）与 DB 副作用
// （log_source_decision 写「货源判定」order_logs）严格分离：
//   - 纯函数便于属性测试（Task 8.8）独立验证「结果完备」（Property 8）；
//   - 日志写入单独成函数，由导入流程在预判后调用（Requirements 3.4）。
// ============================================================================

/// 货源预判的轻量输入视图。
///
/// 仅承载预判所需的最小字段——不是落库的 [`crate::models::order::OrderItem`]，而是
/// 导入层从原始记录解析出的中间体（设计 3.5「item 已解析出 jp_warehouse_id」）。
///
/// - `jp_warehouse_id`：日本仓 ID（原型「日本仓ID」列），用于查日本仓现货；可空。
/// - `caigou_link` / `buhuo_link`：1688 采购链接 / 补货链接，任一非空即视为「可采购」。
/// - `tabaono`：1688 订单号；若已有订单号同样说明该子商品走采购路径。
#[derive(Debug, Clone, Default)]
pub struct ImportedItem {
    /// 日本仓 ID（货源预判用），可空。
    pub jp_warehouse_id: Option<String>,
    /// 采购链接（1688）。
    pub caigou_link: Option<String>,
    /// 补货链接（1688）。
    pub buhuo_link: Option<String>,
    /// 1688 订单号 `tabaono`。
    pub tabaono: Option<String>,
}

impl ImportedItem {
    /// 是否存在可采购线索：采购链接 / 补货链接 / 1688 订单号任一非空（去空白后非空）。
    ///
    /// 用于 [`predict_source`]：未命中日本仓现货但「可采购」时判为 `cn_purchase`。
    pub fn has_purchasable_link(&self) -> bool {
        fn non_blank(v: &Option<String>) -> bool {
            v.as_deref().map(|s| !s.trim().is_empty()).unwrap_or(false)
        }
        non_blank(&self.caigou_link) || non_blank(&self.buhuo_link) || non_blank(&self.tabaono)
    }
}

/// 日本仓现货索引：判定某日本仓 ID 是否有可用现货。
///
/// 以 `HashSet<String>` 背书（便于单测 / 属性测试构造），后续可替换为查库实现而不影响
/// [`predict_source`] 的纯逻辑签名。
#[derive(Debug, Clone, Default)]
pub struct JpStockIndex {
    available: HashSet<String>,
}

impl JpStockIndex {
    /// 空索引（无任何现货）。
    pub fn new() -> Self {
        Self {
            available: HashSet::new(),
        }
    }

    /// 由一组有现货的日本仓 ID 构造索引。
    pub fn from_ids<I, S>(ids: I) -> Self
    where
        I: IntoIterator<Item = S>,
        S: Into<String>,
    {
        Self {
            available: ids.into_iter().map(Into::into).collect(),
        }
    }

    /// 该日本仓 ID 是否有可用现货。
    pub fn has_available(&self, id: &str) -> bool {
        self.available.contains(id)
    }
}

/// 导入时货源地预判（设计 3.5）。**纯函数**，无副作用。
///
/// 后置条件（Requirements 3.3 / Property 8）：对任意输入恒返回
/// `{jp_stock, cn_purchase, pending}` 之一——
/// - 命中日本仓现货 ⟹ `jp_stock`；
/// - 未命中现货但「可采购」⟹ `cn_purchase`；
/// - 信息不足无法判定 ⟹ `pending`。
///
/// 货源判定日志（Requirements 3.4）由 [`log_source_decision`] 在预判后单独写入。
pub fn predict_source(item: &ImportedItem, jp_stock: &JpStockIndex) -> SourceType {
    match &item.jp_warehouse_id {
        Some(id) if jp_stock.has_available(id) => SourceType::JpStock,
        Some(_) | None if item.has_purchasable_link() => SourceType::CnPurchase,
        _ => SourceType::Pending,
    }
}

/// 写入一条「货源判定」`order_logs` 记录（Requirements 3.4）。
///
/// 操作人固定为 `system`、类型固定为 `货源判定`，`new_value` 记预判得到的货源地字符串。
/// 与 [`predict_source`] 解耦：纯逻辑可离线单测/属性测试，本函数只负责落库副作用，
/// 由导入流程在预判后调用（针对租户库，遵循 `query` 运行时 API）。
///
/// `order_item_id` 可空（订单级变更为空；货源判定为子商品级，通常带 id）。
pub async fn log_source_decision(
    pool: &MySqlPool,
    order_id: i64,
    order_item_id: Option<i64>,
    predicted: SourceType,
) -> Result<(), AppError> {
    sqlx::query(
        "INSERT INTO `order_logs` \
         (`order_id`, `order_item_id`, `operator`, `action_type`, `field_name`, \
          `old_value`, `new_value`, `ip`, `created_at`) \
         VALUES (?, ?, 'system', '货源判定', 'source_type', NULL, ?, '', NOW())",
    )
    .bind(order_id)
    .bind(order_item_id)
    .bind(predicted.as_str())
    .execute(pool)
    .await?;

    Ok(())
}

// ============================================================================
// Task 8.9 —— 履约分流路由 + 货源改判（设计 3.5 / Requirements 3.1、3.5）
//
// 纯路由逻辑（route_of / route_to_purchase_queue / route_to_jp_shipment_queue）
// 与 DB 副作用（rejudge_source 改判 + 写审计日志）严格分离：
//   - 纯函数恒按 source_type 分流，互斥且 pending 不入任何队列，便于属性测试
//     （Task 8.10 / Property 1）独立验证；
//   - 改判落库（更新 source_type + 写「货源改判」order_logs）单独成异步函数，
//     由前端改判 handler（Task 10.5）调用，针对租户库走 `query` 运行时 API。
// ============================================================================

use crate::models::order::OrderItem;
use crate::repository::order_repo;
/// 履约队列：子商品按货源地路由到的目标队列。
///
/// 与 `source_type` 一一对应（设计 3.5 分流图）：
/// `cn_purchase → Purchase`、`jp_stock → JpShipment`、`pending → 不入任何队列`。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum FulfillmentQueue {
    /// 采购订单队列（仅 `cn_purchase`）。
    Purchase,
    /// 日本仓发货队列（仅 `jp_stock`）。
    JpShipment,
}

/// 单个子商品的履约路由（**纯函数**，设计 3.5 / Property 1）。
///
/// - `cn_purchase` ⟹ `Some(Purchase)`；
/// - `jp_stock`    ⟹ `Some(JpShipment)`；
/// - `pending`     ⟹ `None`（不入任何队列）。
///
/// 由 `source_type` 唯一决定，故任一子商品至多归属一个队列（两队列互斥）。
pub fn route_of(source_type: SourceType) -> Option<FulfillmentQueue> {
    match source_type {
        SourceType::CnPurchase => Some(FulfillmentQueue::Purchase),
        SourceType::JpStock => Some(FulfillmentQueue::JpShipment),
        SourceType::Pending => None,
    }
}

/// 采购订单队列分流（Requirements 3.1）：**仅**保留 `source_type == cn_purchase` 的子商品。
///
/// 纯函数、保序、不复制子商品（返回引用），便于互斥属性测试（Task 8.10）。
pub fn route_to_purchase_queue(items: &[OrderItem]) -> Vec<&OrderItem> {
    items
        .iter()
        .filter(|item| route_of(item.source_type) == Some(FulfillmentQueue::Purchase))
        .collect()
}

/// 日本仓发货队列分流（Requirements 3.1）：**仅**保留 `source_type == jp_stock` 的子商品。
///
/// 纯函数、保序、不复制子商品（返回引用），便于互斥属性测试（Task 8.10）。
pub fn route_to_jp_shipment_queue(items: &[OrderItem]) -> Vec<&OrderItem> {
    items
        .iter()
        .filter(|item| route_of(item.source_type) == Some(FulfillmentQueue::JpShipment))
        .collect()
}

/// 货源改判：更新子商品 `source_type` 并写一条「货源改判」审计日志（Requirements 3.5）。
///
/// 平台订单视图（B1 区下拉）是分流入口；客服把货源地在 `cn_purchase / jp_stock / pending`
/// 间改判时调用。两步副作用：
/// 1. 经 [`order_repo::update_source_type`] 把 `order_items.source_type` 落库为 `new_source`；
/// 2. 写一条 `order_logs`：`operator=<改判人>`、`action_type='货源改判'`、
///    `field_name='source_type'`、`old_value=<旧货源>`、`new_value=<新货源>`。
///
/// `old_source` 由调用方（已加载该子商品）传入以记录改判前的取值。`new_source` 与
/// `old_source` 相同也照常写日志与执行（语义上为「确认/无变更」的可追溯记录）。
///
/// # 返回
/// `Ok(true)` 表示子商品存在且已更新（随后写入审计日志）；`Ok(false)` 表示子商品
/// 不存在，此时**不**写审计日志。
pub async fn rejudge_source(
    pool: &MySqlPool,
    order_id: i64,
    order_item_id: i64,
    old_source: SourceType,
    new_source: SourceType,
    operator: &str,
) -> Result<bool, AppError> {
    // 1) 落库新货源；子商品不存在则直接返回，不写日志。
    let updated = order_repo::update_source_type(pool, order_item_id, new_source).await?;
    if !updated {
        return Ok(false);
    }

    // 2) 写「货源改判」审计日志，记录新旧货源（Requirements 3.5）。
    sqlx::query(
        "INSERT INTO `order_logs` \
         (`order_id`, `order_item_id`, `operator`, `action_type`, `field_name`, \
          `old_value`, `new_value`, `ip`, `created_at`) \
         VALUES (?, ?, ?, '货源改判', 'source_type', ?, ?, '', NOW())",
    )
    .bind(order_id)
    .bind(order_item_id)
    .bind(operator)
    .bind(old_source.as_str())
    .bind(new_source.as_str())
    .execute(pool)
    .await?;

    Ok(true)
}

#[cfg(test)]
mod tests {
    use super::*;
    use proptest::prelude::*;

    #[test]
    fn splits_multiple_numbers() {
        assert_eq!(split_ship_numbers("123,456,789"), vec!["123", "456", "789"]);
    }

    #[test]
    fn drops_empty_segments() {
        // "a,,b," -> 空段与尾随逗号产生的空段被去除
        assert_eq!(split_ship_numbers("a,,b,"), vec!["a", "b"]);
    }

    #[test]
    fn trims_whitespace_around_each_number() {
        assert_eq!(
            split_ship_numbers(" 123 , 456 ,  , 789 "),
            vec!["123", "456", "789"]
        );
    }

    #[test]
    fn handles_single_number() {
        assert_eq!(split_ship_numbers("SAGAWA123"), vec!["SAGAWA123"]);
    }

    #[test]
    fn single_number_with_surrounding_whitespace() {
        assert_eq!(split_ship_numbers("   ABC123   "), vec!["ABC123"]);
    }

    #[test]
    fn empty_string_yields_no_numbers() {
        assert!(split_ship_numbers("").is_empty());
    }

    #[test]
    fn all_empty_segments_yield_no_numbers() {
        // 全是逗号与空白：去空后没有任何运单号
        assert!(split_ship_numbers(",  , ,,").is_empty());
    }

    #[test]
    fn count_equals_non_empty_segment_count() {
        // 记录数量 == 去除空段后的运单号个数
        let input = "x, ,y,,z";
        let result = split_ship_numbers(input);
        assert_eq!(result.len(), 3);
        assert_eq!(result, vec!["x", "y", "z"]);
    }

    // ------------------------------------------------------------------
    // Task 8.7 —— predict_source / has_purchasable_link / JpStockIndex
    // ------------------------------------------------------------------

    fn item(jp: Option<&str>, caigou: Option<&str>) -> ImportedItem {
        ImportedItem {
            jp_warehouse_id: jp.map(str::to_string),
            caigou_link: caigou.map(str::to_string),
            buhuo_link: None,
            tabaono: None,
        }
    }

    #[test]
    fn jp_stock_index_reports_membership() {
        let index = JpStockIndex::from_ids(["WH-1", "WH-2"]);
        assert!(index.has_available("WH-1"));
        assert!(index.has_available("WH-2"));
        assert!(!index.has_available("WH-3"));
        assert!(!JpStockIndex::new().has_available("WH-1"));
    }

    #[test]
    fn has_purchasable_link_detects_any_non_blank_source() {
        assert!(item(None, Some("https://1688/p")).has_purchasable_link());
        assert!(ImportedItem {
            buhuo_link: Some("https://1688/b".into()),
            ..Default::default()
        }
        .has_purchasable_link());
        assert!(ImportedItem {
            tabaono: Some("ABC123".into()),
            ..Default::default()
        }
        .has_purchasable_link());
        // 空 / 全空白 / None 均不算可采购
        assert!(!ImportedItem::default().has_purchasable_link());
        assert!(!item(None, Some("   ")).has_purchasable_link());
    }

    #[test]
    fn predict_source_returns_jp_stock_on_warehouse_hit() {
        let index = JpStockIndex::from_ids(["WH-1"]);
        // 命中现货：即便也有采购链接，仍优先 jp_stock
        let it = item(Some("WH-1"), Some("https://1688/p"));
        assert_eq!(predict_source(&it, &index), SourceType::JpStock);
    }

    #[test]
    fn predict_source_returns_cn_purchase_when_purchasable() {
        let index = JpStockIndex::from_ids(["WH-1"]);
        // 有日本仓 ID 但未命中现货，且可采购 → cn_purchase
        let with_id = item(Some("WH-404"), Some("https://1688/p"));
        assert_eq!(predict_source(&with_id, &index), SourceType::CnPurchase);
        // 无日本仓 ID 但可采购 → cn_purchase
        let no_id = item(None, Some("https://1688/p"));
        assert_eq!(predict_source(&no_id, &index), SourceType::CnPurchase);
    }

    #[test]
    fn predict_source_returns_pending_on_insufficient_info() {
        let index = JpStockIndex::new();
        // 无日本仓 ID 且无可采购线索 → pending
        assert_eq!(
            predict_source(&item(None, None), &index),
            SourceType::Pending
        );
        // 有日本仓 ID 但未命中现货且不可采购 → pending
        assert_eq!(
            predict_source(&item(Some("WH-404"), None), &index),
            SourceType::Pending
        );
    }

    #[test]
    fn predict_source_total_over_value_space() {
        // 完备性：任意组合都落在三取值之一（Property 8 的离散抽样）
        let index = JpStockIndex::from_ids(["HIT"]);
        for jp in [None, Some("HIT"), Some("MISS")] {
            for caigou in [None, Some(""), Some("link")] {
                let it = item(jp, caigou);
                let s = predict_source(&it, &index);
                assert!(SourceType::ALL.contains(&s));
            }
        }
    }

    // ------------------------------------------------------------------
    // Task 8.8 —— predict_source 属性测试（设计 Property 8 / Requirements 3.3）
    // ------------------------------------------------------------------

    /// 生成「小字母表」的日本仓 ID（`WH-A`..`WH-C`），令现货命中/未命中都高频发生。
    /// 字母表刻意很小，使生成的 `jp_warehouse_id` 与生成的现货索引集合既可能相交也可能不相交。
    fn warehouse_id_strategy() -> impl Strategy<Value = String> {
        prop::sample::select(vec!["WH-A", "WH-B", "WH-C"]).prop_map(str::to_string)
    }

    /// 生成「可采购线索」字段：`None` / 空白串 / 非空链接，覆盖 `has_purchasable_link` 的全部分支。
    /// 空白串用于验证 `trim` 后为空不算可采购。
    fn link_field_strategy() -> impl Strategy<Value = Option<String>> {
        prop_oneof![
            Just(None),
            Just(Some(String::new())),
            Just(Some("   ".to_string())),
            Just(Some("https://1688/x".to_string())),
            Just(Some("ABC123".to_string())),
        ]
    }

    proptest! {
        /// **Property 8: 货源预判结果完备**
        ///
        /// 对任意输入（`jp_warehouse_id` 存在与否任意、可采购线索任意、日本仓现货索引任意），
        /// `predict_source` 恒满足：
        /// - **完备/全域性（totality）**：返回值必属于 `{jp_stock, cn_purchase, pending}`
        ///   三者之一（`SourceType::ALL`），无未定义返回；
        /// - **正确性（按 3.5 映射）**：
        ///   - `jp_warehouse_id` 存在且命中现货索引 ⟹ `jp_stock`；
        ///   - 否则若 `has_purchasable_link()` ⟹ `cn_purchase`；
        ///   - 否则 ⟹ `pending`。
        ///
        /// **Validates: Requirements 3.3**
        #[test]
        fn prop_predict_source_complete(
            jp in proptest::option::of(warehouse_id_strategy()),
            caigou in link_field_strategy(),
            buhuo in link_field_strategy(),
            tabaono in link_field_strategy(),
            stock_ids in prop::collection::hash_set(warehouse_id_strategy(), 0..4),
        ) {
            let it = ImportedItem {
                jp_warehouse_id: jp.clone(),
                caigou_link: caigou,
                buhuo_link: buhuo,
                tabaono,
            };
            let index = JpStockIndex::from_ids(stock_ids.iter().cloned());

            let result = predict_source(&it, &index);

            // (1) 完备/全域性：恒落在三取值之一。
            prop_assert!(
                SourceType::ALL.contains(&result),
                "predict_source 返回了不在 SourceType::ALL 内的值: {:?}",
                result
            );

            // (2) 正确性：独立按设计 3.5 算法计算期望值并比对。
            let hits_stock = jp
                .as_deref()
                .map(|id| stock_ids.contains(id))
                .unwrap_or(false);
            let expected = if hits_stock {
                SourceType::JpStock
            } else if it.has_purchasable_link() {
                SourceType::CnPurchase
            } else {
                SourceType::Pending
            };
            prop_assert_eq!(result, expected);
        }
    }

    // ------------------------------------------------------------------
    // Task 8.9 —— 履约分流路由（设计 3.5 / Requirements 3.1）
    // ------------------------------------------------------------------

    /// 构造一个仅设置 `source_type` 的最小 [`OrderItem`]，其余字段取占位默认值。
    /// 分流仅依赖 `source_type`，故其它字段对路由无影响。
    fn item_with_source(id: i64, source_type: SourceType) -> OrderItem {
        OrderItem {
            id,
            order_id: 1,
            source_type,
            purchase_status: crate::models::order::PurchaseStatus::default(),
            item_code: String::new(),
            jp_warehouse_id: None,
            product_title: String::new(),
            item_option: String::new(),
            chinese_option: String::new(),
            quantity: 1,
            weight: String::new(),
            material: String::new(),
            amount: String::new(),
            caigou_user: None,
            main_image: String::new(),
            sku_image: String::new(),
            platform_extra: None,
        }
    }

    #[test]
    fn route_of_maps_source_to_queue() {
        assert_eq!(
            route_of(SourceType::CnPurchase),
            Some(FulfillmentQueue::Purchase)
        );
        assert_eq!(
            route_of(SourceType::JpStock),
            Some(FulfillmentQueue::JpShipment)
        );
        // pending 不入任何队列
        assert_eq!(route_of(SourceType::Pending), None);
    }

    #[test]
    fn purchase_queue_keeps_only_cn_purchase() {
        let items = vec![
            item_with_source(1, SourceType::CnPurchase),
            item_with_source(2, SourceType::JpStock),
            item_with_source(3, SourceType::Pending),
            item_with_source(4, SourceType::CnPurchase),
        ];
        let ids: Vec<i64> = route_to_purchase_queue(&items)
            .iter()
            .map(|it| it.id)
            .collect();
        assert_eq!(ids, vec![1, 4]);
    }

    #[test]
    fn jp_shipment_queue_keeps_only_jp_stock() {
        let items = vec![
            item_with_source(1, SourceType::CnPurchase),
            item_with_source(2, SourceType::JpStock),
            item_with_source(3, SourceType::Pending),
            item_with_source(4, SourceType::JpStock),
        ];
        let ids: Vec<i64> = route_to_jp_shipment_queue(&items)
            .iter()
            .map(|it| it.id)
            .collect();
        assert_eq!(ids, vec![2, 4]);
    }

    #[test]
    fn routing_queues_are_mutually_exclusive() {
        // 互斥：同一子商品不会同时出现在两个队列里。
        let items = vec![
            item_with_source(1, SourceType::CnPurchase),
            item_with_source(2, SourceType::JpStock),
            item_with_source(3, SourceType::Pending),
        ];
        let purchase_ids: HashSet<i64> = route_to_purchase_queue(&items)
            .iter()
            .map(|it| it.id)
            .collect();
        let jp_ids: HashSet<i64> = route_to_jp_shipment_queue(&items)
            .iter()
            .map(|it| it.id)
            .collect();
        // 两队列 id 集合无交集
        assert!(purchase_ids.is_disjoint(&jp_ids));
        // 各队列只含期望项
        assert_eq!(purchase_ids, HashSet::from([1]));
        assert_eq!(jp_ids, HashSet::from([2]));
    }

    #[test]
    fn pending_items_are_excluded_from_both_queues() {
        let items = vec![
            item_with_source(1, SourceType::Pending),
            item_with_source(2, SourceType::Pending),
        ];
        assert!(route_to_purchase_queue(&items).is_empty());
        assert!(route_to_jp_shipment_queue(&items).is_empty());
    }

    #[test]
    fn empty_items_yield_empty_queues() {
        let items: Vec<OrderItem> = Vec::new();
        assert!(route_to_purchase_queue(&items).is_empty());
        assert!(route_to_jp_shipment_queue(&items).is_empty());
    }

    // ------------------------------------------------------------------
    // Task 8.10 —— 货源分流互斥属性测试（设计 Property 1 / Requirements 3.1）
    // ------------------------------------------------------------------

    /// 在 `{cn_purchase, jp_stock, pending}` 三货源间均匀抽样，令三类子商品都高频出现。
    fn source_type_strategy() -> impl Strategy<Value = SourceType> {
        prop_oneof![
            Just(SourceType::CnPurchase),
            Just(SourceType::JpStock),
            Just(SourceType::Pending),
        ]
    }

    proptest! {
        /// **Property 1: 货源分流互斥**
        ///
        /// 对任意「货源任意、id 互异」的子商品集合，履约分流（[`route_to_purchase_queue`] /
        /// [`route_to_jp_shipment_queue`]）恒满足：
        /// - **互斥（disjoint）**：采购队列与日本仓发货队列的 id 集合无交集——无子商品同时
        ///   出现在两队列；
        /// - **采购队列纯净**：队列内每项 `source_type == CnPurchase`；
        /// - **发货队列纯净**：队列内每项 `source_type == JpStock`；
        /// - **pending 双不入**：任何 `Pending` 子商品都不出现在两队列中；
        /// - **完备计数**：`|采购队列| + |发货队列| + pending 数 == 子商品总数`（每项恰好被
        ///   分流到至多一个队列，cn/jp 必入其一、pending 必不入）。
        ///
        /// 形式化（设计 3.5）：`route_of(item) == Purchase ⟹ source_type == cn_purchase` 且
        /// `route_of(item) == JpShipment ⟹ source_type == jp_stock`。
        ///
        /// **Validates: Requirements 3.1**
        #[test]
        fn prop_routing_queues_mutually_exclusive(
            sources in prop::collection::vec(source_type_strategy(), 0..16)
        ) {
            // 构造 id 互异（i 即 id）的子商品集合，货源由生成器决定。
            let items: Vec<OrderItem> = sources
                .iter()
                .enumerate()
                .map(|(i, &s)| item_with_source(i as i64, s))
                .collect();

            let purchase = route_to_purchase_queue(&items);
            let jp = route_to_jp_shipment_queue(&items);

            let purchase_ids: HashSet<i64> = purchase.iter().map(|it| it.id).collect();
            let jp_ids: HashSet<i64> = jp.iter().map(|it| it.id).collect();

            // (1) 互斥：两队列 id 集合无交集。
            prop_assert!(
                purchase_ids.is_disjoint(&jp_ids),
                "采购队列与日本仓发货队列出现交集: {:?} ∩ {:?}",
                purchase_ids,
                jp_ids
            );

            // (2) 采购队列只含 cn_purchase。
            for it in &purchase {
                prop_assert_eq!(it.source_type, SourceType::CnPurchase);
            }

            // (3) 发货队列只含 jp_stock。
            for it in &jp {
                prop_assert_eq!(it.source_type, SourceType::JpStock);
            }

            // (4) pending 子商品两队列均不含。
            let pending_count = sources
                .iter()
                .filter(|&&s| s == SourceType::Pending)
                .count();
            for it in &items {
                if it.source_type == SourceType::Pending {
                    prop_assert!(!purchase_ids.contains(&it.id));
                    prop_assert!(!jp_ids.contains(&it.id));
                }
            }

            // (5) 完备计数：采购 + 发货 + pending == 总数（每项恰好被分流一次或不入）。
            prop_assert_eq!(
                purchase.len() + jp.len() + pending_count,
                items.len()
            );
        }
    }

    // ------------------------------------------------------------------
    // Task 8.14 —— 多运单号拆分属性测试（设计 Property 5 / Requirements 5.1）
    // ------------------------------------------------------------------

    /// 生成单个「运单段」：不含逗号的字符串（含半角/全角空白、空串），
    /// 以覆盖去空白、空段、纯空白段等输入空间。逗号是分隔符，故段内禁止逗号。
    fn segment_strategy() -> impl Strategy<Value = String> {
        // 字符集刻意包含空白（半角空格、全角空格）以触发 trim 与空段逻辑；长度可为 0。
        prop::string::string_regex("[ \u{3000}A-Za-z0-9]{0,5}").unwrap()
    }

    proptest! {
        /// **Property 5: 多运单号拆分一一对应**
        ///
        /// 把逗号分隔的 `ship_number` 拆分后，每个「去空白后非空」的段恰好对应一条输出
        /// （= 一条 `domestic_shipments`）：输出个数 == 去空段后的段数，且按原顺序与各非空段
        /// 一一对应（trim 后内容一致，不丢、不重、不产生空串）。
        ///
        /// 形式化：令 `kept = segments.map(trim).filter(非空)`，则
        /// `split_ship_numbers(segments.join(","))  == kept` 且 `∀o ∈ 输出. o 非空`。
        ///
        /// **Validates: Requirements 5.1**
        #[test]
        fn prop_split_ship_numbers_one_to_one(segments in prop::collection::vec(segment_strategy(), 0..8)) {
            let input = segments.join(",");
            let result = split_ship_numbers(&input);

            // 参照实现：对每段去空白、丢弃空段、保持顺序。
            let expected: Vec<String> = segments
                .iter()
                .map(|s| s.trim().to_string())
                .filter(|s| !s.is_empty())
                .collect();

            // (1) 输出个数 == 去空段后的非空段个数。
            prop_assert_eq!(result.len(), expected.len());

            // (2) 按原顺序一一对应（trim 后内容一致，不丢、不重）。
            prop_assert_eq!(&result, &expected);

            // (3) 任何输出元素都非空（不产生空串）。
            for shipment in &result {
                prop_assert!(!shipment.is_empty(), "输出运单号不应为空串");
            }
        }
    }
}
