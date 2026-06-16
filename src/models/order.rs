//! 统一订单领域模型：`Order` 聚合根 + `OrderItem` 子商品，以及四张子表
//! `Purchase` / `JpShipment` / `DomesticShipment` / `IntlShipment`（均按 `order_item_id` 关联）。
//!
//! 同时定义两个正交维度枚举（见设计 3.5 / 3.6）：
//! - [`SourceType`]：货源地（`cn_purchase` / `jp_stock` / `pending`）。
//! - [`PurchaseStatus`]：采购/出库**流程进度**，已与货源解耦——**不含**「精品 / 日本库存」
//!   这类货源语义取值（旧系统 `beizhu` 把货源混进了流程，新系统拆开）。
//!
//! 对应设计：3.2 ER 图（字段全集）、3.6 采购状态取值、3.7 日本仓发货模型。
//! 字段命名与租户库迁移脚本 `migrations/tenant/0001_init_tenant_schema.sql` 一一对应。
//!
//! 金额 / 重量列在租户库为 `DECIMAL`，但当前 sqlx 未启用 `decimal`/`bigdecimal` 特性，
//! 为不引入额外依赖且保留精度，这里统一用 `String` 承载（MySQL 文本协议下 `DECIMAL`
//! 即以字符串返回，可无损往返）。

use serde::de::{self, Deserializer};
use serde::{Deserialize, Serialize, Serializer};
use serde_json::Value as JsonValue;

use sqlx::database::HasArguments;
use sqlx::encode::IsNull;
use sqlx::error::BoxDynError;
use sqlx::mysql::{MySqlTypeInfo, MySqlValueRef};
use sqlx::types::chrono::NaiveDateTime;
use sqlx::{Decode, Encode, MySql, Type};

/// 货源语义关键词。这些子串属于**货源地**语义（旧 `beizhu` 把货源混进了流程文本），
/// 在新系统中只能由 [`SourceType`] 表达：
/// - `purchase_status`（[`PurchaseStatus`]）流程枚举的任何取值都**绝不**包含它们；
/// - 数据迁移（Task 17.3 货源回填）据此从旧 `beizhu` 识别「日本仓现货」并剥离该语义。
///
/// 单一事实来源：迁移工具与流程枚举测试均引用本常量，避免两处分别硬编码而漂移。
pub const SOURCE_SEMANTICS: [&str; 2] = ["精品", "日本库存"];

// ============================================================================
// SourceType —— 货源地维度（设计 3.5）
// ============================================================================

/// 子商品货源地。整个履约分流的核心维度，与流程进度（[`PurchaseStatus`]）正交。
///
/// 三个取值与租户库 `order_items.source_type` 列字符串一一对应：
/// `cn_purchase`（国内采购）/ `jp_stock`（日本仓现货）/ `pending`（信息不足待定）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum SourceType {
    /// `cn_purchase` —— 国内采购。
    CnPurchase,
    /// `jp_stock` —— 日本仓现货。
    JpStock,
    /// `pending` —— 信息不足，货源待定。
    Pending,
}

impl SourceType {
    /// 全部取值。
    pub const ALL: [SourceType; 3] = [
        SourceType::CnPurchase,
        SourceType::JpStock,
        SourceType::Pending,
    ];

    /// 数据库/JSON 字符串表示。
    pub fn as_str(&self) -> &'static str {
        match self {
            SourceType::CnPurchase => "cn_purchase",
            SourceType::JpStock => "jp_stock",
            SourceType::Pending => "pending",
        }
    }

    /// 由字符串解析，未知值返回 `None`。
    pub fn from_str(s: &str) -> Option<SourceType> {
        match s {
            "cn_purchase" => Some(SourceType::CnPurchase),
            "jp_stock" => Some(SourceType::JpStock),
            "pending" => Some(SourceType::Pending),
            _ => None,
        }
    }
}

impl Default for SourceType {
    fn default() -> Self {
        SourceType::Pending
    }
}

impl Serialize for SourceType {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        serializer.serialize_str(self.as_str())
    }
}

impl<'de> Deserialize<'de> for SourceType {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        let s = String::deserialize(deserializer)?;
        SourceType::from_str(&s).ok_or_else(|| de::Error::custom(format!("未知 source_type: {s}")))
    }
}

impl Type<MySql> for SourceType {
    fn type_info() -> MySqlTypeInfo {
        <str as Type<MySql>>::type_info()
    }
    fn compatible(ty: &MySqlTypeInfo) -> bool {
        <&str as Type<MySql>>::compatible(ty)
    }
}

impl<'r> Decode<'r, MySql> for SourceType {
    fn decode(value: MySqlValueRef<'r>) -> Result<Self, BoxDynError> {
        let s = <&str as Decode<MySql>>::decode(value)?;
        SourceType::from_str(s).ok_or_else(|| format!("未知 source_type: {s}").into())
    }
}

impl<'q> Encode<'q, MySql> for SourceType {
    fn encode_by_ref(&self, buf: &mut <MySql as HasArguments<'q>>::ArgumentBuffer) -> IsNull {
        <&str as Encode<MySql>>::encode_by_ref(&self.as_str(), buf)
    }
}

// ============================================================================
// PurchaseStatus —— 流程进度维度（设计 3.6，已剥离货源语义）
// ============================================================================

/// 采购 / 出库**流程进度**。忠实复刻原型 `beizhu` 筛选下拉中的流程取值，
/// 但**删除了「精品 / 日本库存」这组货源语义**——货源改由 [`SourceType`] 表达。
///
/// 取值集合（设计 3.6）：
/// `待处理 / 国内采购-准备 / 国内采购--问题 / 国内采购-已采购 /
///  国内采购-TB/PDD已采购 / 发货中 / 已到货 / 已发货代订单 / 已发日本 /
///  已发出荷通知 / 已到货问题件 / 问题订单(后台处理) / 已取消`。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum PurchaseStatus {
    /// 待处理
    Pending,
    /// 国内采购-准备
    CnPreparing,
    /// 国内采购--问题
    CnProblem,
    /// 国内采购-已采购
    CnPurchased,
    /// 国内采购-TB/PDD已采购
    CnPurchasedTbPdd,
    /// 发货中
    Shipping,
    /// 已到货
    Arrived,
    /// 已发货代订单
    ShippedAgentOrder,
    /// 已发日本
    ShippedToJapan,
    /// 已发出荷通知
    ShippedNotice,
    /// 已到货问题件
    ArrivedProblem,
    /// 问题订单(后台处理)
    ProblemOrder,
    /// 已取消
    Cancelled,
}

impl PurchaseStatus {
    /// 全部取值（与设计 3.6 下拉顺序一致）。
    pub const ALL: [PurchaseStatus; 13] = [
        PurchaseStatus::Pending,
        PurchaseStatus::CnPreparing,
        PurchaseStatus::CnProblem,
        PurchaseStatus::CnPurchased,
        PurchaseStatus::CnPurchasedTbPdd,
        PurchaseStatus::Shipping,
        PurchaseStatus::Arrived,
        PurchaseStatus::ShippedAgentOrder,
        PurchaseStatus::ShippedToJapan,
        PurchaseStatus::ShippedNotice,
        PurchaseStatus::ArrivedProblem,
        PurchaseStatus::ProblemOrder,
        PurchaseStatus::Cancelled,
    ];

    /// 数据库/JSON 字符串表示（中文流程名）。
    pub fn as_str(&self) -> &'static str {
        match self {
            PurchaseStatus::Pending => "待处理",
            PurchaseStatus::CnPreparing => "国内采购-准备",
            PurchaseStatus::CnProblem => "国内采购--问题",
            PurchaseStatus::CnPurchased => "国内采购-已采购",
            PurchaseStatus::CnPurchasedTbPdd => "国内采购-TB/PDD已采购",
            PurchaseStatus::Shipping => "发货中",
            PurchaseStatus::Arrived => "已到货",
            PurchaseStatus::ShippedAgentOrder => "已发货代订单",
            PurchaseStatus::ShippedToJapan => "已发日本",
            PurchaseStatus::ShippedNotice => "已发出荷通知",
            PurchaseStatus::ArrivedProblem => "已到货问题件",
            PurchaseStatus::ProblemOrder => "问题订单(后台处理)",
            PurchaseStatus::Cancelled => "已取消",
        }
    }

    /// 由字符串解析，未知值返回 `None`。
    pub fn from_str(s: &str) -> Option<PurchaseStatus> {
        PurchaseStatus::ALL.into_iter().find(|st| st.as_str() == s)
    }
}

impl Default for PurchaseStatus {
    fn default() -> Self {
        PurchaseStatus::Pending
    }
}

impl Serialize for PurchaseStatus {
    fn serialize<S: Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        serializer.serialize_str(self.as_str())
    }
}

impl<'de> Deserialize<'de> for PurchaseStatus {
    fn deserialize<D: Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        let s = String::deserialize(deserializer)?;
        PurchaseStatus::from_str(&s)
            .ok_or_else(|| de::Error::custom(format!("未知 purchase_status: {s}")))
    }
}

impl Type<MySql> for PurchaseStatus {
    fn type_info() -> MySqlTypeInfo {
        <str as Type<MySql>>::type_info()
    }
    fn compatible(ty: &MySqlTypeInfo) -> bool {
        <&str as Type<MySql>>::compatible(ty)
    }
}

impl<'r> Decode<'r, MySql> for PurchaseStatus {
    fn decode(value: MySqlValueRef<'r>) -> Result<Self, BoxDynError> {
        let s = <&str as Decode<MySql>>::decode(value)?;
        PurchaseStatus::from_str(s).ok_or_else(|| format!("未知 purchase_status: {s}").into())
    }
}

impl<'q> Encode<'q, MySql> for PurchaseStatus {
    fn encode_by_ref(&self, buf: &mut <MySql as HasArguments<'q>>::ArgumentBuffer) -> IsNull {
        <&str as Encode<MySql>>::encode_by_ref(&self.as_str(), buf)
    }
}

// ============================================================================
// Order —— 订单聚合根（orders 表；A 区客户/收件信息整单共享）
// ============================================================================

/// 订单聚合根。对应 `orders` 表（A 区客户/收件信息整单级共享），
/// 并聚合其下的子商品 [`OrderItem`] 列表（不属于 `orders` 表列，查询时单独装配）。
#[derive(Debug, Clone, Default, Serialize, Deserialize, sqlx::FromRow)]
pub struct Order {
    pub id: i64,
    /// 平台代码 `y/r/w/m/q/yp`。
    pub platform: String,
    /// 平台订单号 `orderId`。
    pub platform_order_id: String,
    /// `orderDetailId`，可空。
    pub order_detail_id: Option<String>,
    /// 所属店铺 id，可空。
    pub store_id: Option<i64>,
    /// 平台下单时间，可空。
    pub order_date: Option<NaiveDateTime>,
    /// 平台原始状态。
    pub order_status: String,
    // —— A 区：客户 / 收件信息（整单共享） ——
    pub customer_name: String,
    pub customer_kana: String,
    pub customer_zip: String,
    pub customer_address: String,
    pub customer_phone: String,
    pub customer_mail: String,
    pub pay_method: String,
    pub ship_method: String,
    // —— 金额（DECIMAL，以字符串无损承载） ——
    pub total_item_price: String,
    pub postage_price: String,
    pub total_price: String,
    // —— 评价 ——
    pub review_invited: bool,
    pub reviewed: bool,
    /// `cdate`；归档按年份切分。
    pub imported_at: NaiveDateTime,
    /// 平台特有字段（键名保留原列名）。
    pub platform_extra: Option<JsonValue>,
    /// 聚合的子商品列表（非 `orders` 表列，查询时单独装配）。
    #[sqlx(default)]
    pub items: Vec<OrderItem>,
}

// ============================================================================
// OrderItem —— 子商品（order_items 表；B1 区，每子商品一行）
// ============================================================================

/// 订单子商品。货源地 / 采购流程 / 商品编码 / 日本仓 ID / 采购人均下沉到子商品级。
#[derive(Debug, Clone, Serialize, Deserialize, sqlx::FromRow)]
pub struct OrderItem {
    pub id: i64,
    /// 所属订单 id。
    pub order_id: i64,
    /// 货源地维度。
    pub source_type: SourceType,
    /// 采购/出库流程进度（已与货源解耦）。
    pub purchase_status: PurchaseStatus,
    /// `ItemId`/`itemCode`/`lotnumber` 归一后的商品编码。
    pub item_code: String,
    /// 日本仓 SKU/ID（货源预判用），可空。
    pub jp_warehouse_id: Option<String>,
    pub product_title: String,
    pub item_option: String,
    pub chinese_option: String,
    pub quantity: i32,
    /// 重量（DECIMAL，以字符串无损承载）。
    pub weight: String,
    pub material: String,
    /// 金额（DECIMAL，以字符串无损承载）。
    pub amount: String,
    /// 首次写 `tabaono` 时赋值一次，后续不覆盖；可空。
    pub caigou_user: Option<String>,
    /// `zhutu` 主图。
    pub main_image: String,
    /// `skuimg`。
    pub sku_image: String,
    /// 子商品平台特有字段。
    pub platform_extra: Option<JsonValue>,
}

// ============================================================================
// Purchase —— 国内采购信息（purchases 表；仅 cn_purchase 子商品，按 order_item_id 关联）
// ============================================================================

/// 国内采购信息。一个 `cn_purchase` 子商品一条，按 `order_item_id` 关联。
#[derive(Debug, Clone, Default, Serialize, Deserialize, sqlx::FromRow)]
pub struct Purchase {
    pub id: i64,
    pub order_item_id: i64,
    /// 1688 订单号。
    pub tabaono: String,
    pub caigou_link: String,
    pub buhuo_link: String,
    pub caigou_user: String,
    pub caigou_time: Option<NaiveDateTime>,
    pub caigou_ordernums: String,
    /// `cnamount` 采购金额（DECIMAL → String）。
    pub cn_amount: String,
    /// `comamount`（DECIMAL → String）。
    pub com_amount: String,
    /// 国内运单号 `shipno`。
    pub cn_ship_number: String,
    pub created_at: NaiveDateTime,
    pub updated_at: NaiveDateTime,
}

// ============================================================================
// JpShipment —— 日本仓出库信息（jp_shipments 表；仅 jp_stock 子商品，按 order_item_id 关联）
// ============================================================================

/// 日本仓出库信息（设计 3.7）。`out_status` 状态机：待分配→已分配→已出库→已发货。
#[derive(Debug, Clone, Default, Serialize, Deserialize, sqlx::FromRow)]
pub struct JpShipment {
    pub id: i64,
    pub order_item_id: i64,
    /// 出库状态：待分配/已分配/已出库/已发货。
    pub out_status: String,
    /// 发货员。
    pub assignee: String,
    /// 出库人。
    pub operator: String,
    pub out_time: Option<NaiveDateTime>,
    /// 仓位。
    pub location: String,
    /// 出库单号。
    pub out_no: String,
    /// 出库成本（DECIMAL → String）。
    pub out_cost: String,
    pub created_at: NaiveDateTime,
    pub updated_at: NaiveDateTime,
}

// ============================================================================
// DomesticShipment —— 国内（日本境内）物流（domestic_shipments 表；多运单号一对多）
// ============================================================================

/// 国内（日本境内）物流。每个运单号一行，按 `order_item_id` 关联。
#[derive(Debug, Clone, Default, Serialize, Deserialize, sqlx::FromRow)]
pub struct DomesticShipment {
    pub id: i64,
    pub order_item_id: i64,
    /// `shipnumber`（每运单一行）。
    pub ship_number: String,
    /// `shipcompany`/承运商。
    pub ship_company: String,
    /// `shipquantity` 发货数量。
    pub ship_quantity: i32,
    /// `jpshipdetails`。
    pub jpship_status: String,
    /// 配達完了时间（仅首次写入）。
    pub jpship_completed_at: Option<NaiveDateTime>,
    /// 物流轨迹。
    pub logistic_trace: Option<String>,
    pub created_at: NaiveDateTime,
    pub updated_at: NaiveDateTime,
}

// ============================================================================
// IntlShipment —— 国际物流（intl_shipments 表；按 order_item_id 关联）
// ============================================================================

/// 国际物流。按 `order_item_id` 关联。
#[derive(Debug, Clone, Default, Serialize, Deserialize, sqlx::FromRow)]
pub struct IntlShipment {
    pub id: i64,
    pub order_item_id: i64,
    /// 国际运单号。
    pub intl_number: String,
    /// 运单状态。
    pub intl_status: String,
    /// 运费（DECIMAL → String）。
    pub intl_fee: String,
    /// 件数。
    pub intl_qty: i32,
    /// 重量（DECIMAL → String）。
    pub intl_weight: String,
    pub tranship_comment: String,
    pub comment: String,
    pub created_at: NaiveDateTime,
    pub updated_at: NaiveDateTime,
}

// ============================================================================
// 单元测试
// ============================================================================

#[cfg(test)]
mod tests {
    use super::*;
    use proptest::prelude::*;

    #[test]
    fn purchase_status_contains_no_source_semantics() {
        for st in PurchaseStatus::ALL {
            let label = st.as_str();
            for banned in SOURCE_SEMANTICS {
                assert!(
                    !label.contains(banned),
                    "purchase_status 取值 `{label}` 不应包含货源语义 `{banned}`"
                );
            }
        }
    }

    /// 在 `PurchaseStatus::ALL` 上构造的策略：随机选取任意一个合法流程枚举取值。
    fn any_purchase_status() -> impl Strategy<Value = PurchaseStatus> {
        prop::sample::select(PurchaseStatus::ALL.to_vec())
    }

    proptest! {
        /// **Property 4: 采购状态不含已废弃货源语义**
        ///
        /// 任意 `purchase_status` 取值都落在「流程进度」枚举集合内，其字符串标签绝不包含
        /// 「精品 / 日本库存」等货源语义（货源只由 `source_type` 表达）。
        ///
        /// 形式化：`∀ item. item.purchase_status ∈ PurchaseStatusEnum ∧
        /// item.purchase_status ∉ {"精品","日本库存"}`。
        ///
        /// **Validates: Requirements 3.2**
        #[test]
        fn prop_purchase_status_excludes_source_semantics(st in any_purchase_status()) {
            let label = st.as_str();

            // (1) 取值恒落在合法流程枚举集合内（可由 from_str 还原 ⇒ 属于 ALL）。
            prop_assert_eq!(PurchaseStatus::from_str(label), Some(st));

            // (2) 标签绝不包含任何货源语义子串。
            for banned in SOURCE_SEMANTICS {
                prop_assert!(
                    !label.contains(banned),
                    "purchase_status 取值 `{}` 不应包含货源语义 `{}`",
                    label,
                    banned
                );
            }
        }

        /// **Property 4（补充）**：货源语义字符串绝不是合法 `purchase_status`，
        /// `from_str` 对「精品 / 日本库存」必返回 `None`（二者已彻底解耦到 `source_type`）。
        ///
        /// **Validates: Requirements 3.2**
        #[test]
        fn prop_from_str_rejects_source_semantics(banned in prop::sample::select(SOURCE_SEMANTICS.to_vec())) {
            prop_assert_eq!(PurchaseStatus::from_str(banned), None);
        }

        /// **Property 4（补充）**：对任意字符串，`from_str` 当且仅当该串恰为 13 个已知流程
        /// 取值之一时返回 `Some`；任何含「精品 / 日本库存」货源语义子串的字符串恒返回 `None`
        /// （货源语义已被流程枚举彻底排除）。
        ///
        /// 形式化：`∀ s. from_str(s).is_some() ⇔ s ∈ valid_labels`，且
        /// `∀ s. (s 含 banned 子串) ⇒ from_str(s) = None`。
        ///
        /// **Validates: Requirements 3.2**
        #[test]
        fn prop_from_str_some_iff_known_label(s in ".*") {
            let valid_labels: Vec<&'static str> =
                PurchaseStatus::ALL.iter().map(|st| st.as_str()).collect();
            let parsed = PurchaseStatus::from_str(&s);

            // (1) Some ⇔ s 恰为某个合法流程标签。
            let is_known = valid_labels.iter().any(|label| *label == s.as_str());
            prop_assert_eq!(parsed.is_some(), is_known);
            // 且解析回的取值其标签必与输入一致（无别名/无歧义）。
            if let Some(st) = parsed {
                prop_assert_eq!(st.as_str(), s.as_str());
            }

            // (2) 任何含货源语义子串的字符串绝不可能是合法流程取值。
            //     （由于 13 个合法标签本身不含这些子串，含子串者必不在合法集内 ⇒ None。）
            for banned in SOURCE_SEMANTICS {
                if s.contains(banned) {
                    prop_assert_eq!(PurchaseStatus::from_str(&s), None);
                }
            }
        }
    }

    #[test]
    fn purchase_status_matches_design_3_6() {
        let expected = [
            "待处理",
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
            "已取消",
        ];
        let actual: Vec<&str> = PurchaseStatus::ALL.iter().map(|s| s.as_str()).collect();
        assert_eq!(actual, expected);
    }

    #[test]
    fn purchase_status_str_round_trip() {
        for st in PurchaseStatus::ALL {
            assert_eq!(PurchaseStatus::from_str(st.as_str()), Some(st));
        }
        assert_eq!(PurchaseStatus::from_str("精品"), None);
        assert_eq!(PurchaseStatus::from_str("日本库存"), None);
        assert_eq!(PurchaseStatus::from_str("unknown"), None);
    }

    #[test]
    fn purchase_status_serde_round_trip() {
        for st in PurchaseStatus::ALL {
            let json = serde_json::to_string(&st).unwrap();
            let back: PurchaseStatus = serde_json::from_str(&json).unwrap();
            assert_eq!(back, st);
        }
    }

    #[test]
    fn source_type_str_round_trip() {
        for s in SourceType::ALL {
            assert_eq!(SourceType::from_str(s.as_str()), Some(s));
        }
        assert_eq!(SourceType::CnPurchase.as_str(), "cn_purchase");
        assert_eq!(SourceType::JpStock.as_str(), "jp_stock");
        assert_eq!(SourceType::Pending.as_str(), "pending");
        assert_eq!(SourceType::from_str("bogus"), None);
    }

    #[test]
    fn source_type_serde_round_trip() {
        for s in SourceType::ALL {
            let json = serde_json::to_string(&s).unwrap();
            assert_eq!(json, format!("\"{}\"", s.as_str()));
            let back: SourceType = serde_json::from_str(&json).unwrap();
            assert_eq!(back, s);
        }
    }
}
