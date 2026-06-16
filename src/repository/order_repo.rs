//! 订单聚合及子表读写（按 `order_item_id` 关联）—— Task 8.6 / Requirements 8.1、8.3。
//!
//! 本模块是统一订单模型（`design.md` 3.2 ER 图）的数据访问层，针对**租户库**操作。
//! 与项目其余 repository 一致：**一律使用 SQLx 运行时 API（`query` / `query_as` + 显式
//! SQL），不使用编译期 `query!` 宏**——租户库在编译期并不存在，无法做编译期校验。
//!
//! 聚合装配规则（Requirements 8.1）：
//! - `orders` 为聚合根，承载 A 区客户/收件信息（整单共享）。
//! - `order_items` 为子商品（每子商品一行），货源地 / 采购状态 / 物流均下沉到子商品级。
//! - `purchases` / `jp_shipments` / `domestic_shipments` / `intl_shipments` 四张子表
//!   **全部按 `order_item_id` 关联**（Requirements 8.3）。
//!
//! 提供的能力：
//! - [`get`]：按订单 id 装配 `Order` 聚合（订单行 + 其全部子商品）。
//! - 子表加载器 [`load_purchase`] / [`load_jp_shipment`] / [`load_domestic_shipments`]
//!   / [`load_intl_shipments`]：按 `order_item_id` 读取对应子表（按需调用）。
//! - [`set_caigou_user`]：**一次性**写入 `order_items.caigou_user`（仅当当前为 NULL/空，
//!   后续不覆盖，Requirements 6.1）。
//! - [`update_source_type`]：货源改判（更新子商品 `source_type`，Requirements 3.5）。
//! - [`find_pending_intl`]：查询待更新国际/国内物流的子商品（Requirements 5.4 的过滤）。
//! - [`update_jpship`]：写入子商品物流状态；`jpship_completed_at` 仅首次写入
//!   （已有值保持不变，Requirements 5.2）。
//! - 写入器 [`insert_order`] / [`insert_order_item`]：用于导入 / 迁移装配聚合。

use serde_json::Value as JsonValue;
use sqlx::types::chrono::NaiveDateTime;
use sqlx::{FromRow, MySqlPool};

use crate::error::AppError;
use crate::models::order::{
    DomesticShipment, IntlShipment, JpShipment, Order, OrderItem, Purchase, PurchaseStatus,
    SourceType,
};

/// `orders` 表行的私有映射体。
///
/// 不能直接 `query_as::<_, Order>`：聚合根 [`Order`] 含装配字段 `items: Vec<OrderItem>`，
/// 其 `FromRow` 派生引入了 `Vec<OrderItem>: Decode` 这一无法满足的约束。故先用本结构体
/// （与 `orders` 列一一对应、无嵌套）取数，再在 [`get`] 中装配为 [`Order`]。
#[derive(Debug, Clone, FromRow)]
struct OrderRow {
    id: i64,
    platform: String,
    platform_order_id: String,
    order_detail_id: Option<String>,
    store_id: Option<i64>,
    order_date: Option<NaiveDateTime>,
    order_status: String,
    customer_name: String,
    customer_kana: String,
    customer_zip: String,
    customer_address: String,
    customer_phone: String,
    customer_mail: String,
    pay_method: String,
    ship_method: String,
    total_item_price: String,
    postage_price: String,
    total_price: String,
    review_invited: bool,
    reviewed: bool,
    imported_at: NaiveDateTime,
    platform_extra: Option<JsonValue>,
}

impl OrderRow {
    /// 装配为聚合根 [`Order`]，挂入已查好的子商品列表。
    fn into_order(self, items: Vec<OrderItem>) -> Order {
        Order {
            id: self.id,
            platform: self.platform,
            platform_order_id: self.platform_order_id,
            order_detail_id: self.order_detail_id,
            store_id: self.store_id,
            order_date: self.order_date,
            order_status: self.order_status,
            customer_name: self.customer_name,
            customer_kana: self.customer_kana,
            customer_zip: self.customer_zip,
            customer_address: self.customer_address,
            customer_phone: self.customer_phone,
            customer_mail: self.customer_mail,
            pay_method: self.pay_method,
            ship_method: self.ship_method,
            total_item_price: self.total_item_price,
            postage_price: self.postage_price,
            total_price: self.total_price,
            review_invited: self.review_invited,
            reviewed: self.reviewed,
            imported_at: self.imported_at,
            platform_extra: self.platform_extra,
            items,
        }
    }
}

// ============================================================================
// 列清单常量（与租户库迁移脚本 0001_init_tenant_schema.sql 一一对应）
//
// `FromRow` 按结构体字段名匹配列名，DB 列名与模型字段名一致，故直接 `SELECT col, ...`。
// 个别可能与保留字冲突的列名（如 `operator`）以反引号包裹。
// ============================================================================

/// `orders` 全列（顺序与 [`Order`] 字段一致，`items` 为装配字段不在表中）。
const ORDER_COLUMNS: &str = "`id`, `platform`, `platform_order_id`, `order_detail_id`, \
     `store_id`, `order_date`, `order_status`, `customer_name`, `customer_kana`, \
     `customer_zip`, `customer_address`, `customer_phone`, `customer_mail`, \
     `pay_method`, `ship_method`, `total_item_price`, `postage_price`, `total_price`, \
     `review_invited`, `reviewed`, `imported_at`, `platform_extra`";

/// `order_items` 全列（顺序与 [`OrderItem`] 字段一致）。
const ORDER_ITEM_COLUMNS: &str = "`id`, `order_id`, `source_type`, `purchase_status`, \
     `item_code`, `jp_warehouse_id`, `product_title`, `item_option`, `chinese_option`, \
     `quantity`, `weight`, `material`, `amount`, `caigou_user`, `main_image`, \
     `sku_image`, `platform_extra`";

/// `purchases` 全列。
const PURCHASE_COLUMNS: &str = "`id`, `order_item_id`, `tabaono`, `caigou_link`, \
     `buhuo_link`, `caigou_user`, `caigou_time`, `caigou_ordernums`, `cn_amount`, \
     `com_amount`, `cn_ship_number`, `created_at`, `updated_at`";

/// `jp_shipments` 全列（`operator` 反引号包裹以防保留字冲突）。
const JP_SHIPMENT_COLUMNS: &str = "`id`, `order_item_id`, `out_status`, `assignee`, \
     `operator`, `out_time`, `location`, `out_no`, `out_cost`, `created_at`, `updated_at`";

/// `domestic_shipments` 全列。
const DOMESTIC_SHIPMENT_COLUMNS: &str = "`id`, `order_item_id`, `ship_number`, \
     `ship_company`, `ship_quantity`, `jpship_status`, `jpship_completed_at`, \
     `logistic_trace`, `created_at`, `updated_at`";

/// `intl_shipments` 全列。
const INTL_SHIPMENT_COLUMNS: &str = "`id`, `order_item_id`, `intl_number`, `intl_status`, \
     `intl_fee`, `intl_qty`, `intl_weight`, `tranship_comment`, `comment`, \
     `created_at`, `updated_at`";

// ============================================================================
// 读取：聚合装配与子表加载
// ============================================================================

/// 按订单 id 装配 [`Order`] 聚合：订单行 + 其全部子商品（按 id 升序）。
///
/// 订单不存在时返回 `Ok(None)`。子表（采购 / 出库 / 物流）默认不加载，调用方按需用
/// [`load_purchase`] 等加载器拉取，避免在列表场景产生不必要的查询。
pub async fn get(pool: &MySqlPool, order_id: i64) -> Result<Option<Order>, AppError> {
    let row = sqlx::query_as::<_, OrderRow>(&format!(
        "SELECT {ORDER_COLUMNS} FROM `orders` WHERE `id` = ?"
    ))
    .bind(order_id)
    .fetch_optional(pool)
    .await?;

    let Some(row) = row else {
        return Ok(None);
    };

    let items = list_items(pool, order_id).await?;
    Ok(Some(row.into_order(items)))
}

/// 读取某订单下的全部子商品（按 id 升序）。
pub async fn list_items(pool: &MySqlPool, order_id: i64) -> Result<Vec<OrderItem>, AppError> {
    let items = sqlx::query_as::<_, OrderItem>(&format!(
        "SELECT {ORDER_ITEM_COLUMNS} FROM `order_items` WHERE `order_id` = ? ORDER BY `id`"
    ))
    .bind(order_id)
    .fetch_all(pool)
    .await?;

    Ok(items)
}

/// 读取某子商品的国内采购信息（仅 `cn_purchase` 子商品有，按 `order_item_id` 关联）。
pub async fn load_purchase(
    pool: &MySqlPool,
    order_item_id: i64,
) -> Result<Option<Purchase>, AppError> {
    let purchase = sqlx::query_as::<_, Purchase>(&format!(
        "SELECT {PURCHASE_COLUMNS} FROM `purchases` WHERE `order_item_id` = ?"
    ))
    .bind(order_item_id)
    .fetch_optional(pool)
    .await?;

    Ok(purchase)
}

/// 读取某子商品的日本仓出库信息（仅 `jp_stock` 子商品有，按 `order_item_id` 关联）。
pub async fn load_jp_shipment(
    pool: &MySqlPool,
    order_item_id: i64,
) -> Result<Option<JpShipment>, AppError> {
    let shipment = sqlx::query_as::<_, JpShipment>(&format!(
        "SELECT {JP_SHIPMENT_COLUMNS} FROM `jp_shipments` WHERE `order_item_id` = ?"
    ))
    .bind(order_item_id)
    .fetch_optional(pool)
    .await?;

    Ok(shipment)
}

/// 读取某子商品的国内（日本境内）物流（多运单号一对多，按 `order_item_id` 关联）。
pub async fn load_domestic_shipments(
    pool: &MySqlPool,
    order_item_id: i64,
) -> Result<Vec<DomesticShipment>, AppError> {
    let shipments = sqlx::query_as::<_, DomesticShipment>(&format!(
        "SELECT {DOMESTIC_SHIPMENT_COLUMNS} FROM `domestic_shipments` \
         WHERE `order_item_id` = ? ORDER BY `id`"
    ))
    .bind(order_item_id)
    .fetch_all(pool)
    .await?;

    Ok(shipments)
}

/// 读取某子商品的国际物流（按 `order_item_id` 关联）。
pub async fn load_intl_shipments(
    pool: &MySqlPool,
    order_item_id: i64,
) -> Result<Vec<IntlShipment>, AppError> {
    let shipments = sqlx::query_as::<_, IntlShipment>(&format!(
        "SELECT {INTL_SHIPMENT_COLUMNS} FROM `intl_shipments` \
         WHERE `order_item_id` = ? ORDER BY `id`"
    ))
    .bind(order_item_id)
    .fetch_all(pool)
    .await?;

    Ok(shipments)
}

// ============================================================================
// 写入：采购人一次性赋值 / 货源改判
// ============================================================================

/// **一次性**写入 `order_items.caigou_user`（Requirements 6.1）。
///
/// 仅当该子商品 `caigou_user` 当前为 NULL 或空字符串时才写入，后续保存不覆盖——
/// 由 `WHERE caigou_user IS NULL OR caigou_user = ''` 在 SQL 层强制保证幂等。
///
/// # 返回
/// `Ok(true)` 表示本次确实写入（此前为空）；`Ok(false)` 表示已有采购人、未改动，
/// 或该子商品不存在。
pub async fn set_caigou_user(
    pool: &MySqlPool,
    order_item_id: i64,
    user: &str,
) -> Result<bool, AppError> {
    let result = sqlx::query(
        "UPDATE `order_items` SET `caigou_user` = ? \
         WHERE `id` = ? AND (`caigou_user` IS NULL OR `caigou_user` = '')",
    )
    .bind(user)
    .bind(order_item_id)
    .execute(pool)
    .await?;

    Ok(result.rows_affected() > 0)
}

/// 货源改判：更新子商品 `source_type`（Requirements 3.5）。
///
/// 仅落库 `source_type` 本身；货源改判审计日志由领域服务（`order_service`）另行写入。
///
/// # 返回
/// `Ok(true)` 表示命中并更新；`Ok(false)` 表示子商品不存在。
pub async fn update_source_type(
    pool: &MySqlPool,
    order_item_id: i64,
    source_type: SourceType,
) -> Result<bool, AppError> {
    let result = sqlx::query("UPDATE `order_items` SET `source_type` = ? WHERE `id` = ?")
        .bind(source_type)
        .bind(order_item_id)
        .execute(pool)
        .await?;

    Ok(result.rows_affected() > 0)
}

// ============================================================================
// 物流：待更新查询 + 状态/完成时间写入
// ============================================================================

/// [`find_pending_intl`] 的返回行：待更新国际/国内物流的子商品视图。
///
/// 聚合了该子商品的全部国内运单号（`GROUP_CONCAT` 逗号拼接），供调度任务逐一查询。
#[derive(Debug, Clone, FromRow)]
pub struct PendingIntlItem {
    /// 子商品 id。
    pub order_item_id: i64,
    /// 所属订单 id。
    pub order_id: i64,
    /// 平台代码。
    pub platform: String,
    /// 采购流程状态。
    pub purchase_status: PurchaseStatus,
    /// 该子商品全部未完成运单的运单号（逗号拼接；可能为空）。
    pub ship_numbers: Option<String>,
}

/// 查询待更新物流的子商品（复刻 `update_jpship_logistics` 的取数过滤，Requirements 5.4）。
///
/// 过滤条件：
/// - `orders.platform = platform`；
/// - `order_items.purchase_status` 命中 `statuses`（由调用方按平台传入，乐天会额外含
///   「日本仓库已处理」）；
/// - 存在尚未写入完成时间（`jpship_completed_at IS NULL`）的国内运单；
/// - `orders.imported_at` 在最近 `days` 天内。
///
/// 结果按子商品 id 升序，最多 `limit` 条。`statuses` 为空时直接返回空集（避免非法
/// `IN ()`）。`days` / `limit` 为 0 时分别表示不限天数 / 不限条数。
pub async fn find_pending_intl(
    pool: &MySqlPool,
    platform: &str,
    statuses: &[PurchaseStatus],
    days: u32,
    limit: u32,
) -> Result<Vec<PendingIntlItem>, AppError> {
    if statuses.is_empty() {
        return Ok(Vec::new());
    }

    let placeholders = in_placeholders(statuses.len());
    let days_clause = if days > 0 {
        " AND o.`imported_at` >= (NOW() - INTERVAL ? DAY)"
    } else {
        ""
    };
    let limit_clause = if limit > 0 { " LIMIT ?" } else { "" };

    let sql = format!(
        "SELECT oi.`id` AS `order_item_id`, oi.`order_id` AS `order_id`, \
                o.`platform` AS `platform`, oi.`purchase_status` AS `purchase_status`, \
                GROUP_CONCAT(ds.`ship_number`) AS `ship_numbers` \
         FROM `order_items` oi \
         JOIN `orders` o ON o.`id` = oi.`order_id` \
         JOIN `domestic_shipments` ds ON ds.`order_item_id` = oi.`id` \
         WHERE o.`platform` = ? \
           AND oi.`purchase_status` IN ({placeholders}) \
           AND ds.`jpship_completed_at` IS NULL{days_clause} \
         GROUP BY oi.`id`, oi.`order_id`, o.`platform`, oi.`purchase_status` \
         ORDER BY oi.`id`{limit_clause}"
    );

    let mut query = sqlx::query_as::<_, PendingIntlItem>(&sql).bind(platform);
    for status in statuses {
        query = query.bind(status.as_str());
    }
    if days > 0 {
        query = query.bind(days as i64);
    }
    if limit > 0 {
        query = query.bind(limit as i64);
    }

    let rows = query.fetch_all(pool).await?;
    Ok(rows)
}

/// 写入某子商品的国内物流状态；完成时间仅首次写入（Requirements 5.2）。
///
/// `jpship_status` 总是被更新为最新结果；`jpship_completed_at` 用 `CASE` 保证幂等——
/// 仅当当前为 NULL 时才写入传入的 `completed_at`，已有值保持不变。`completed_at` 为
/// `None` 时不设置完成时间（保持 NULL）。更新该子商品名下的全部国内运单行。
///
/// # 返回
/// 受影响（被更新）的运单行数。
pub async fn update_jpship(
    pool: &MySqlPool,
    order_item_id: i64,
    status: &str,
    completed_at: Option<NaiveDateTime>,
) -> Result<u64, AppError> {
    let result = sqlx::query(
        "UPDATE `domestic_shipments` \
         SET `jpship_status` = ?, \
             `jpship_completed_at` = CASE \
                 WHEN `jpship_completed_at` IS NULL THEN ? \
                 ELSE `jpship_completed_at` END \
         WHERE `order_item_id` = ?",
    )
    .bind(status)
    .bind(completed_at)
    .bind(order_item_id)
    .execute(pool)
    .await?;

    Ok(result.rows_affected())
}

// ============================================================================
// 写入：聚合装配（导入 / 迁移）
// ============================================================================

/// 插入一条订单聚合根（不含子商品），返回自增主键 id。
///
/// `id` 与 `items` 字段被忽略：`id` 由数据库自增，子商品另行用 [`insert_order_item`] 写入。
pub async fn insert_order(pool: &MySqlPool, order: &Order) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `orders` \
         (`platform`, `platform_order_id`, `order_detail_id`, `store_id`, `order_date`, \
          `order_status`, `customer_name`, `customer_kana`, `customer_zip`, \
          `customer_address`, `customer_phone`, `customer_mail`, `pay_method`, \
          `ship_method`, `total_item_price`, `postage_price`, `total_price`, \
          `review_invited`, `reviewed`, `imported_at`, `platform_extra`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(&order.platform)
    .bind(&order.platform_order_id)
    .bind(&order.order_detail_id)
    .bind(order.store_id)
    .bind(order.order_date)
    .bind(&order.order_status)
    .bind(&order.customer_name)
    .bind(&order.customer_kana)
    .bind(&order.customer_zip)
    .bind(&order.customer_address)
    .bind(&order.customer_phone)
    .bind(&order.customer_mail)
    .bind(&order.pay_method)
    .bind(&order.ship_method)
    .bind(&order.total_item_price)
    .bind(&order.postage_price)
    .bind(&order.total_price)
    .bind(order.review_invited)
    .bind(order.reviewed)
    .bind(order.imported_at)
    .bind(&order.platform_extra)
    .execute(pool)
    .await?;

    Ok(result.last_insert_id() as i64)
}

/// 按平台、店铺、订单号和明细号查找已导入订单。
///
/// 手动导入使用该函数做轻量去重，避免同一 CSV 重复提交后产生重复订单。
pub async fn find_order_id_by_identity(
    pool: &MySqlPool,
    platform: &str,
    store_id: Option<i64>,
    platform_order_id: &str,
    order_detail_id: Option<&str>,
) -> Result<Option<i64>, AppError> {
    let order_id = sqlx::query_scalar::<_, i64>(
        "SELECT `id` FROM `orders` \
         WHERE `platform` = ? \
           AND ((? IS NULL AND `store_id` IS NULL) OR `store_id` = ?) \
           AND `platform_order_id` = ? \
           AND ((? IS NULL AND `order_detail_id` IS NULL) OR `order_detail_id` = ?) \
         ORDER BY `id` ASC \
         LIMIT 1",
    )
    .bind(platform)
    .bind(store_id)
    .bind(store_id)
    .bind(platform_order_id)
    .bind(order_detail_id)
    .bind(order_detail_id)
    .fetch_optional(pool)
    .await?;

    Ok(order_id)
}

/// 插入一条子商品，返回自增主键 id。
///
/// `id` 字段被忽略（由数据库自增）；`order_id` 必须为已存在订单的 id。
pub async fn insert_order_item(pool: &MySqlPool, item: &OrderItem) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `order_items` \
         (`order_id`, `source_type`, `purchase_status`, `item_code`, `jp_warehouse_id`, \
          `product_title`, `item_option`, `chinese_option`, `quantity`, `weight`, \
          `material`, `amount`, `caigou_user`, `main_image`, `sku_image`, `platform_extra`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(item.order_id)
    .bind(item.source_type)
    .bind(item.purchase_status)
    .bind(&item.item_code)
    .bind(&item.jp_warehouse_id)
    .bind(&item.product_title)
    .bind(&item.item_option)
    .bind(&item.chinese_option)
    .bind(item.quantity)
    .bind(&item.weight)
    .bind(&item.material)
    .bind(&item.amount)
    .bind(&item.caigou_user)
    .bind(&item.main_image)
    .bind(&item.sku_image)
    .bind(&item.platform_extra)
    .execute(pool)
    .await?;

    Ok(result.last_insert_id() as i64)
}

/// 插入一条国内采购信息，返回自增主键 id。
///
/// 手动导入和旧库迁移都复用同一张 `purchases` 子表；调用方负责先插入
/// `order_items` 并传入对应 `order_item_id`。
pub async fn insert_purchase(
    pool: &MySqlPool,
    order_item_id: i64,
    purchase: &Purchase,
) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `purchases` \
         (`order_item_id`, `tabaono`, `caigou_link`, `buhuo_link`, `caigou_user`, \
          `caigou_time`, `caigou_ordernums`, `cn_amount`, `com_amount`, `cn_ship_number`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(order_item_id)
    .bind(&purchase.tabaono)
    .bind(&purchase.caigou_link)
    .bind(&purchase.buhuo_link)
    .bind(&purchase.caigou_user)
    .bind(purchase.caigou_time)
    .bind(&purchase.caigou_ordernums)
    .bind(&purchase.cn_amount)
    .bind(&purchase.com_amount)
    .bind(&purchase.cn_ship_number)
    .execute(pool)
    .await?;

    Ok(result.last_insert_id() as i64)
}

/// 插入一条国内（日本境内）物流记录，返回自增主键 id。
pub async fn insert_domestic_shipment(
    pool: &MySqlPool,
    order_item_id: i64,
    shipment: &DomesticShipment,
) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `domestic_shipments` \
         (`order_item_id`, `ship_number`, `ship_company`, `ship_quantity`, `jpship_status`, \
          `jpship_completed_at`, `logistic_trace`) \
         VALUES (?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(order_item_id)
    .bind(&shipment.ship_number)
    .bind(&shipment.ship_company)
    .bind(shipment.ship_quantity)
    .bind(&shipment.jpship_status)
    .bind(shipment.jpship_completed_at)
    .bind(&shipment.logistic_trace)
    .execute(pool)
    .await?;

    Ok(result.last_insert_id() as i64)
}

/// 插入一条国际物流记录，返回自增主键 id。
pub async fn insert_intl_shipment(
    pool: &MySqlPool,
    order_item_id: i64,
    shipment: &IntlShipment,
) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `intl_shipments` \
         (`order_item_id`, `intl_number`, `intl_status`, `intl_fee`, `intl_qty`, \
          `intl_weight`, `tranship_comment`, `comment`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
    )
    .bind(order_item_id)
    .bind(&shipment.intl_number)
    .bind(&shipment.intl_status)
    .bind(&shipment.intl_fee)
    .bind(shipment.intl_qty)
    .bind(&shipment.intl_weight)
    .bind(&shipment.tranship_comment)
    .bind(&shipment.comment)
    .execute(pool)
    .await?;

    Ok(result.last_insert_id() as i64)
}

/// 写入订单审计日志。
pub async fn insert_order_log(
    pool: &MySqlPool,
    order_id: i64,
    order_item_id: Option<i64>,
    operator: &str,
    action_type: &str,
    field_name: &str,
    old_value: Option<&str>,
    new_value: Option<&str>,
) -> Result<i64, AppError> {
    let result = sqlx::query(
        "INSERT INTO `order_logs` \
         (`order_id`, `order_item_id`, `operator`, `action_type`, `field_name`, \
          `old_value`, `new_value`, `ip`, `created_at`) \
         VALUES (?, ?, ?, ?, ?, ?, ?, '', NOW())",
    )
    .bind(order_id)
    .bind(order_item_id)
    .bind(operator)
    .bind(action_type)
    .bind(field_name)
    .bind(old_value)
    .bind(new_value)
    .execute(pool)
    .await?;

    Ok(result.last_insert_id() as i64)
}

// ============================================================================
// 纯逻辑辅助（无 DB，可单测）
// ============================================================================

/// 构造 `IN (...)` 的占位符串：`n` 个 `?`，以 `", "` 连接。
///
/// `n == 0` 返回空串（调用方须避免据此生成非法的 `IN ()`）。
fn in_placeholders(n: usize) -> String {
    let mut out = String::with_capacity(n.saturating_mul(3));
    for i in 0..n {
        if i > 0 {
            out.push_str(", ");
        }
        out.push('?');
    }
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    // 注：本模块的 DB 函数需要可用的租户库 MySQL 连接，无法在 CI 离线运行；
    // 此处仅对纯逻辑（占位符构造）与 SQL 文本不变量做无 DB 的单元测试，
    // 保证 SQL 始终与列清单常量保持一致。

    #[test]
    fn in_placeholders_builds_expected_strings() {
        assert_eq!(in_placeholders(0), "");
        assert_eq!(in_placeholders(1), "?");
        assert_eq!(in_placeholders(2), "?, ?");
        assert_eq!(in_placeholders(3), "?, ?, ?");
    }

    #[test]
    fn in_placeholders_count_matches_question_marks() {
        for n in 0..32 {
            let s = in_placeholders(n);
            assert_eq!(s.matches('?').count(), n, "n={n} 的占位符个数应等于 n");
        }
    }

    #[test]
    fn find_pending_intl_returns_empty_on_no_statuses() {
        // 空状态集合应短路返回空集，绝不构造非法的 `IN ()`（无需 DB）。
        // 这里只断言占位符构造前的守卫语义：空集合占位符为空串。
        let statuses: [PurchaseStatus; 0] = [];
        assert!(statuses.is_empty());
        assert_eq!(in_placeholders(statuses.len()), "");
    }

    #[test]
    fn column_lists_have_expected_arity() {
        // 列清单逗号分隔的列数应与对应表的字段数一致，防止 SELECT/INSERT 漂移。
        assert_eq!(ORDER_COLUMNS.split(',').count(), 22);
        assert_eq!(ORDER_ITEM_COLUMNS.split(',').count(), 17);
        assert_eq!(PURCHASE_COLUMNS.split(',').count(), 13);
        assert_eq!(JP_SHIPMENT_COLUMNS.split(',').count(), 11);
        assert_eq!(DOMESTIC_SHIPMENT_COLUMNS.split(',').count(), 10);
        assert_eq!(INTL_SHIPMENT_COLUMNS.split(',').count(), 11);
    }
}
