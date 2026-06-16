//! 三视图订单列表处理器（平台 / 采购 / 日本仓发货）与货源过滤
//! （Task 10.2 / Requirements 9.1、9.2）。
//!
//! 设计依据 `design.md` 3.5（货源分流）与 7.1（三视图共享同一套订单模板）：
//! 平台订单 / 采购订单 / 日本仓发货**三个视图共用同一个订单块渲染模板**
//! （`partials/order_block.html`，见 Task 10.1），三者只有**货源过滤条件**与**默认展开区**不同：
//!
//! | 视图 | 货源过滤（source_type） | 默认显示 |
//! |------|------------------------|----------|
//! | 平台订单 `Platform`    | 全部（不过滤）   | A + B1 区 |
//! | 采购订单 `Purchase`    | 仅 `cn_purchase` | B1 + B2 区 |
//! | 日本仓发货 `JpWarehouse` | 仅 `jp_stock`  | B1 + B2(出库) 区 |
//!
//! **Requirements 9.2**：某订单在某视图「无符合该视图货源的子商品」时，整个订单块
//! 不在该视图渲染（原型 `items.length === 0 → 不渲染`）。
//!
//! 分层取舍：货源过滤逻辑 [`ViewKind`] / [`filter_orders_for_view`] 是**纯函数**，
//! 与 DB 副作用（装载订单）和 Tera 渲染严格分离，便于单元测试覆盖三视图过滤与空块剔除。
//! handler 仅负责：从租户库装载订单 → 调用纯过滤 → 用共享模板逐块渲染 → 拼装响应。

use std::collections::HashMap;

use axum::{
    extract::{Query, State},
    response::Html,
    routing::get,
    Extension, Router,
};
use serde::{Deserialize, Serialize};
use serde_json::{json, Value as JsonValue};
use sqlx::types::chrono::NaiveDateTime;
use sqlx::MySqlPool;
use tera::{Context, Tera};

use crate::error::AppError;
use crate::middleware::tenant::TenantContext;
use crate::models::order::{Order, PurchaseStatus, SourceType};
use crate::models::user::{DataAction, Principal};
use crate::repository::order_repo;
use crate::services::platform_auth_service;
use crate::state::AppState;

/// 列表装载的订单条数上限（避免一次性把整库订单读入内存）。
///
/// 真正的分页 / 筛选在 Task 10.3「筛选与列表交互区」落地；本任务聚焦三视图货源过滤，
/// 故先以一个保守上限装载最近订单。
const LIST_LIMIT: i64 = 200;

/// 共享订单块模板名（相对 `src/templates/` 的 Tera 模板名，见 Task 10.1）。
const ORDER_BLOCK_TEMPLATE: &str = "partials/order_block.html";

/// 列表外壳模板名（含筛选与列表交互区 + 工具条，见 Task 10.3 / 设计 7.5）。
///
/// 模板尚在迭代中，渲染路径**容错**：该模板缺失或渲染失败时回退为「纯订单块片段」，
/// 保证三视图列表在模板补齐前仍可用（见 [`render_list_view`]）。
const LIST_SHELL_TEMPLATE: &str = "order_list.html";

fn purchase_status_options() -> Vec<&'static str> {
    PurchaseStatus::ALL
        .iter()
        .map(PurchaseStatus::as_str)
        .collect()
}

// ============================================================================
// ViewKind —— 三视图维度（设计 7.1）与其货源过滤映射（纯逻辑）
// ============================================================================

/// 订单三视图。三者共用同一订单块模板，仅以货源过滤条件与默认展开区相区分（设计 7.1）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum ViewKind {
    /// 平台订单视图：**不过滤**货源（展示全部子商品），是货源改判入口。
    Platform,
    /// 采购订单视图：**仅** `cn_purchase` 子商品。
    Purchase,
    /// 日本仓发货视图：**仅** `jp_stock` 子商品。
    JpWarehouse,
}

impl ViewKind {
    /// 本视图的货源过滤条件：
    /// - `Platform` ⟹ `None`（不过滤，保留全部货源）；
    /// - `Purchase` ⟹ `Some(CnPurchase)`；
    /// - `JpWarehouse` ⟹ `Some(JpStock)`。
    pub fn source_filter(self) -> Option<SourceType> {
        match self {
            ViewKind::Platform => None,
            ViewKind::Purchase => Some(SourceType::CnPurchase),
            ViewKind::JpWarehouse => Some(SourceType::JpStock),
        }
    }

    /// 该视图是否接纳给定货源的子商品。
    ///
    /// 平台视图接纳一切货源；采购 / 日本仓视图仅接纳与各自过滤条件相等的货源。
    pub fn accepts(self, source_type: SourceType) -> bool {
        match self.source_filter() {
            None => true,
            Some(allowed) => source_type == allowed,
        }
    }

    /// 共享模板使用的视图名（与 `order_block.html` 中 `view` 取值约定一致）。
    ///
    /// `order_block.html` 以 `view == "platform"` 判定是否为分流入口（A 区显示 / B1 货源下拉）。
    pub fn template_name(self) -> &'static str {
        match self {
            ViewKind::Platform => "platform",
            ViewKind::Purchase => "purchase",
            ViewKind::JpWarehouse => "jpstock",
        }
    }
}

// ============================================================================
// 纯函数：按视图过滤订单（设计 7.1 / Requirements 9.1、9.2）
// ============================================================================

/// 按视图货源过滤订单，产出可直接渲染的视图模型（**纯函数**）。
///
/// 对每个订单：仅保留 `source_type` 被该视图接纳（[`ViewKind::accepts`]）的子商品；
/// 随后**丢弃过滤后无任何子商品的订单**（Requirements 9.2：无符合货源子商品的订单块不渲染）。
///
/// - `Platform`：不过滤货源，订单原样保留（仅当其本身无子商品时被丢弃）。
/// - `Purchase`：每个订单只留 `cn_purchase` 子商品；无 `cn_purchase` 的订单被丢弃。
/// - `JpWarehouse`：每个订单只留 `jp_stock` 子商品；无 `jp_stock` 的订单被丢弃。
///
/// 保序：订单与子商品的相对顺序保持不变。返回的订单为过滤后的副本，可安全用于渲染。
pub fn filter_orders_for_view(orders: Vec<Order>, view: ViewKind) -> Vec<Order> {
    orders
        .into_iter()
        .filter_map(|mut order| {
            order.items.retain(|item| view.accepts(item.source_type));
            if order.items.is_empty() {
                // Requirements 9.2：该视图下无符合货源的子商品 ⟹ 不渲染该订单块。
                None
            } else {
                Some(order)
            }
        })
        .collect()
}

// ============================================================================
// 货源地下拉（设计 7.5 / Requirements 9.5）—— 平台视图筛选项（纯逻辑）
// ============================================================================

/// 平台订单视图筛选中的**货源地下拉**选择项（设计 7.5：全部 / 日本仓 / 国内采购）。
///
/// 与 [`ViewKind`] 不同：[`ViewKind`] 是「访问哪个视图」的路由维度（平台/采购/日本仓发货），
/// [`SourceSelection`] 是平台视图内部**叠加**在视图过滤之上的货源下拉筛选——它把平台视图
/// 临时收窄到某一货源，供客服分流时按货源浏览。映射为可选货源过滤条件后，叠加到视图过滤之上
/// （见 [`apply_source_selection`]）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Default, Serialize, Deserialize)]
pub enum SourceSelection {
    /// 全部（不按货源收窄；保留视图过滤后的全部子商品）。
    #[default]
    All,
    /// 日本仓 ⟹ 仅 `jp_stock` 子商品。
    JpWarehouse,
    /// 国内采购 ⟹ 仅 `cn_purchase` 子商品。
    CnPurchase,
}

impl SourceSelection {
    /// 下拉的三个选项（与设计 7.5 顺序一致：全部 / 日本仓 / 国内采购）。
    pub const ALL: [SourceSelection; 3] = [
        SourceSelection::All,
        SourceSelection::JpWarehouse,
        SourceSelection::CnPurchase,
    ];

    /// 下拉选择对应的货源过滤条件（**纯映射**，本任务核心）：
    /// - `全部`     ⟹ `None`（不过滤）；
    /// - `日本仓`   ⟹ `Some(JpStock)`；
    /// - `国内采购` ⟹ `Some(CnPurchase)`。
    pub fn to_source_filter(self) -> Option<SourceType> {
        match self {
            SourceSelection::All => None,
            SourceSelection::JpWarehouse => Some(SourceType::JpStock),
            SourceSelection::CnPurchase => Some(SourceType::CnPurchase),
        }
    }

    /// 查询参数 / 表单值 ⟹ 选择项。容错解析：接受货源代码、简写与中文标签；
    /// 未知值（含缺省）一律回退 `All`（不收窄），避免筛选误伤。
    pub fn from_param(s: &str) -> SourceSelection {
        match s.trim() {
            "jp_stock" | "jp" | "日本仓" => SourceSelection::JpWarehouse,
            "cn_purchase" | "cn" | "国内采购" => SourceSelection::CnPurchase,
            // ""/"all"/"全部"/未知 → 全部
            _ => SourceSelection::All,
        }
    }

    /// 表单 / 查询参数序列化值（与 [`from_param`](Self::from_param) 互逆的规范值）。
    pub fn as_param(self) -> &'static str {
        match self {
            SourceSelection::All => "all",
            SourceSelection::JpWarehouse => "jp_stock",
            SourceSelection::CnPurchase => "cn_purchase",
        }
    }

    /// 中文显示标签（设计 7.5 下拉文案）。
    pub fn label(self) -> &'static str {
        match self {
            SourceSelection::All => "全部",
            SourceSelection::JpWarehouse => "日本仓",
            SourceSelection::CnPurchase => "国内采购",
        }
    }
}

/// 货源下拉的单个选项视图模型（供模板渲染 `<option>`：值 / 文案 / 是否选中）。
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct SourceOption {
    /// 提交值（`as_param`）。
    pub value: &'static str,
    /// 显示文案（`label`）。
    pub label: &'static str,
    /// 是否为当前选中项。
    pub selected: bool,
}

/// 构造货源下拉三个选项（标记 `selected` 为当前选择），供模板渲染。
pub fn source_options(selected: SourceSelection) -> Vec<SourceOption> {
    SourceSelection::ALL
        .into_iter()
        .map(|opt| SourceOption {
            value: opt.as_param(),
            label: opt.label(),
            selected: opt == selected,
        })
        .collect()
}

// ============================================================================
// 筛选模型（设计 7.5 / Requirements 9.5）—— 常用（默认显示）+ 高级（默认折叠）
// ============================================================================

/// 列表筛选模型。区分**常用搜索区**（默认显示）与**高级搜索区**（默认折叠），
/// 并在平台视图携带货源地下拉（设计 7.5）。
///
/// 各字段为可选文本筛选；`None` / 空串表示「不筛选该项」。本结构是 handler 与模板之间的
/// 纯视图模型：由查询参数解析（[`ListFilter::from_query`]）得到，可直接注入 Tera 上下文。
#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct ListFilter {
    // —— 常用搜索区（默认显示）——
    /// 关键字（订单号 / 商品名）。
    pub keyword: Option<String>,
    /// 订单状态。
    pub order_status: Option<String>,
    /// 平台代码 `y/r/w/m/q/yp`。
    pub platform: Option<String>,
    /// 1688 订单号 / 采购单号。
    pub purchase_no: Option<String>,
    /// 运送方式。
    pub ship_method: Option<String>,
    /// 子商品采购/履约流程状态。
    pub purchase_status: Option<String>,
    /// 日本仓出库状态。
    pub out_status: Option<String>,
    /// 日本仓发货员。
    pub assignee: Option<String>,
    /// 日本仓仓位。
    pub location: Option<String>,
    /// 货源地下拉（平台视图生效；其余视图忽略，见 [`render_list_view`]）。
    pub source: SourceSelection,

    // —— 高级搜索区（默认折叠，设计 7.5）——
    /// ItemId / 商品编码。
    pub item_id: Option<String>,
    /// 邮箱。
    pub email: Option<String>,
    /// 收件人。
    pub recipient: Option<String>,
    /// 电话。
    pub phone: Option<String>,
    /// 片假名。
    pub kana: Option<String>,
    /// 运单号。
    pub tracking_no: Option<String>,
    /// 采购人。
    pub buyer: Option<String>,
    /// 邀评 / 评价状态。
    pub review_status: Option<String>,
    /// 导入时间区间（起）。
    pub imported_from: Option<String>,
    /// 导入时间区间（止）。
    pub imported_to: Option<String>,
}

/// 取查询参数并归一为「去空白后的非空值」；缺省 / 空白 ⟹ `None`（视为不筛选）。
fn param(params: &HashMap<String, String>, key: &str) -> Option<String> {
    params
        .get(key)
        .map(|v| v.trim())
        .filter(|v| !v.is_empty())
        .map(|v| v.to_string())
}

impl ListFilter {
    /// 由查询参数装配筛选模型（**纯函数**）。未提供的项回退为「不筛选」。
    pub fn from_query(params: &HashMap<String, String>) -> ListFilter {
        ListFilter {
            keyword: param(params, "keyword"),
            order_status: param(params, "order_status"),
            platform: param(params, "platform"),
            purchase_no: param(params, "purchase_no"),
            ship_method: param(params, "ship_method"),
            purchase_status: param(params, "purchase_status"),
            out_status: param(params, "out_status"),
            assignee: param(params, "assignee"),
            location: param(params, "location"),
            source: params
                .get("source")
                .map(|s| SourceSelection::from_param(s))
                .unwrap_or_default(),
            item_id: param(params, "item_id"),
            email: param(params, "email"),
            recipient: param(params, "recipient"),
            phone: param(params, "phone"),
            kana: param(params, "kana"),
            tracking_no: param(params, "tracking_no"),
            buyer: param(params, "buyer"),
            review_status: param(params, "review_status"),
            imported_from: param(params, "imported_from"),
            imported_to: param(params, "imported_to"),
        }
    }

    /// 高级搜索区是否存在任一已填项——决定模板是否需要**默认展开**高级区
    /// （设计 7.5：高级区默认折叠，但带值时应展开以可见当前条件）。
    pub fn has_advanced(&self) -> bool {
        self.item_id.is_some()
            || self.email.is_some()
            || self.recipient.is_some()
            || self.phone.is_some()
            || self.kana.is_some()
            || self.purchase_no.is_some()
            || self.ship_method.is_some()
            || self.out_status.is_some()
            || self.assignee.is_some()
            || self.location.is_some()
            || self.tracking_no.is_some()
            || self.buyer.is_some()
            || self.review_status.is_some()
            || self.imported_from.is_some()
            || self.imported_to.is_some()
    }
}

#[derive(Debug, Clone, Serialize, sqlx::FromRow)]
struct OrderLogView {
    created_at: NaiveDateTime,
    operator: String,
    action_type: String,
    field_name: String,
    old_value: Option<String>,
    new_value: Option<String>,
    ip: String,
}

async fn load_order_logs(pool: &MySqlPool, order_id: i64) -> Result<Vec<OrderLogView>, AppError> {
    let logs = sqlx::query_as::<_, OrderLogView>(
        "SELECT `created_at`, `operator`, `action_type`, `field_name`, \
                `old_value`, `new_value`, `ip` \
         FROM `order_logs` \
         WHERE `order_id` = ? \
         ORDER BY `id` DESC \
         LIMIT 50",
    )
    .bind(order_id)
    .fetch_all(pool)
    .await?;

    Ok(logs)
}

// ============================================================================
// 纯函数：货源下拉叠加在视图过滤之上（设计 7.5 / Requirements 9.5）
// ============================================================================

/// 先按视图过滤货源（[`filter_orders_for_view`]），再叠加货源下拉收窄（**纯函数**）。
///
/// 语义：
/// 1. 始终先应用视图过滤（平台视图不收窄；采购 / 日本仓视图按各自货源收窄）；
/// 2. 再按下拉选择 [`SourceSelection::to_source_filter`] 进一步收窄：
///    - `全部` ⟹ 不再收窄，与 [`filter_orders_for_view`] 等价；
///    - `日本仓` / `国内采购` ⟹ 仅保留对应货源子商品，过滤后无子商品的订单块被丢弃
///      （Requirements 9.2 一致语义）。
///
/// 平台视图叠加 `日本仓` / `国内采购` 即「平台视图内按货源浏览」；其余视图通常以 `全部`
/// 调用（货源已由视图过滤约束），叠加相斥货源会得到空结果——符合「按视图浏览」预期。
pub fn apply_source_selection(
    orders: Vec<Order>,
    view: ViewKind,
    selection: SourceSelection,
) -> Vec<Order> {
    let filtered = filter_orders_for_view(orders, view);
    match selection.to_source_filter() {
        None => filtered,
        Some(allowed) => filtered
            .into_iter()
            .filter_map(|mut order| {
                order.items.retain(|item| item.source_type == allowed);
                if order.items.is_empty() {
                    None
                } else {
                    Some(order)
                }
            })
            .collect(),
    }
}

// ============================================================================
// 列表工具条权限模型（设计 7.5 / Requirements 9.5）—— 按 Principal 计算可用动作
// ============================================================================

/// 列表工具条动作（设计 7.5）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum ToolbarAction {
    /// 全选（纯 UI：勾选当前列表所有行，不涉及数据权限）。
    SelectAll,
    /// 展开详情（纯 UI：整列表切换 B2/C 区显隐，不涉及数据权限）。
    ExpandDetails,
    /// 批量改状态（数据写操作，需 [`DataAction::Edit`]）。
    BatchStatus,
    /// 批量分配（数据写操作，需 [`DataAction::Edit`]）。
    BatchAssign,
    /// 批量删除（数据删除操作，需 [`DataAction::Delete`]）。
    BatchDelete,
}

impl ToolbarAction {
    /// 全部工具条动作（与设计 7.5 顺序一致）。
    pub const ALL: [ToolbarAction; 5] = [
        ToolbarAction::SelectAll,
        ToolbarAction::ExpandDetails,
        ToolbarAction::BatchStatus,
        ToolbarAction::BatchAssign,
        ToolbarAction::BatchDelete,
    ];

    /// 该工具条动作所需的数据操作权限：
    /// - `全选` / `展开详情`：纯前端交互，**无需**数据权限 ⟹ `None`；
    /// - `批量改状态` / `批量分配`：写操作 ⟹ `Some(Edit)`；
    /// - `批量删除`：删除操作 ⟹ `Some(Delete)`。
    pub fn required_action(self) -> Option<DataAction> {
        match self {
            ToolbarAction::SelectAll | ToolbarAction::ExpandDetails => None,
            ToolbarAction::BatchStatus | ToolbarAction::BatchAssign => Some(DataAction::Edit),
            ToolbarAction::BatchDelete => Some(DataAction::Delete),
        }
    }
}

/// 主体是否可对「当前列表涉及的全部店铺」执行某数据操作。
///
/// 列表级批量操作横跨列表中出现的多个店铺，因此采取**保守**判定：当且仅当主体对其中
/// 每个店铺都获 [`Principal::can_operate`] 放行时才启用（任一店铺越权即禁用整条批量动作）。
/// `store_ids` 为空（列表无可操作店铺）时按 `all` 的真空真处理——批量按钮显示但无作用对象。
fn can_batch_over(principal: &Principal, store_ids: &[i64], action: DataAction) -> bool {
    store_ids
        .iter()
        .all(|&sid| principal.can_operate(sid, action))
}

/// 计算给定主体在当前列表下**可见 / 可用**的工具条动作集合（**纯函数**，本任务核心）。
///
/// 规则（设计 7.5「批量…（按权限）」）：
/// - 主体缺失（未注入鉴权）：仅保留纯 UI 动作（`全选` / `展开详情`），杜绝越权批量；
/// - `SuperAdmin` / `CompanyAdmin`：[`Principal::can_operate`] 恒放行 ⟹ 全部动作可用；
/// - `Employee`：纯 UI 动作恒可用；需数据权限的动作按其 [`ToolbarAction::required_action`]
///   经 [`can_batch_over`] 对列表涉及店铺逐一判定（受限范围内越权则该动作不可用）。
///
/// `store_ids` 为当前列表中出现的去重店铺集合（见 [`distinct_store_ids`]）。
pub fn visible_toolbar_actions(
    principal: Option<&Principal>,
    store_ids: &[i64],
) -> Vec<ToolbarAction> {
    ToolbarAction::ALL
        .into_iter()
        .filter(|action| match action.required_action() {
            // 纯 UI 动作：恒可用（即便主体缺失）。
            None => true,
            // 需数据权限：主体须存在且对列表涉及店铺有相应操作权限。
            Some(required) => match principal {
                Some(p) => can_batch_over(p, store_ids, required),
                None => false,
            },
        })
        .collect()
}

/// 工具条视图模型（按动作可用性铺平为布尔位，便于注入 Tera 上下文渲染）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
pub struct ToolbarModel {
    /// 全选可用。
    pub select_all: bool,
    /// 展开详情可用。
    pub expand_details: bool,
    /// 批量改状态可用。
    pub batch_status: bool,
    /// 批量分配可用。
    pub batch_assign: bool,
    /// 批量删除可用。
    pub batch_delete: bool,
}

impl ToolbarModel {
    /// 由 [`visible_toolbar_actions`] 的结果铺平为布尔位视图模型。
    pub fn for_principal(principal: Option<&Principal>, store_ids: &[i64]) -> ToolbarModel {
        let actions = visible_toolbar_actions(principal, store_ids);
        let has = |a: ToolbarAction| actions.contains(&a);
        ToolbarModel {
            select_all: has(ToolbarAction::SelectAll),
            expand_details: has(ToolbarAction::ExpandDetails),
            batch_status: has(ToolbarAction::BatchStatus),
            batch_assign: has(ToolbarAction::BatchAssign),
            batch_delete: has(ToolbarAction::BatchDelete),
        }
    }
}

/// 收集列表中出现的去重店铺 id（保序、忽略 `store_id` 为空的订单）。
///
/// 供工具条权限判定使用（列表级批量操作横跨这些店铺）。
fn distinct_store_ids(orders: &[Order]) -> Vec<i64> {
    let mut seen = Vec::new();
    for order in orders {
        if let Some(id) = order.store_id {
            if !seen.contains(&id) {
                seen.push(id);
            }
        }
    }
    seen
}

fn count_items(orders: &[Order]) -> usize {
    orders.iter().map(|order| order.items.len()).sum()
}

/// 给订单模板补齐子表对象，避免列表场景访问缺失字段导致模板失败。
async fn enrich_order_for_template(pool: &MySqlPool, order: &Order) -> Result<JsonValue, AppError> {
    let mut value = serde_json::to_value(order).unwrap_or_else(|_| json!({}));
    let logs = load_order_logs(pool, order.id).await.unwrap_or_else(|e| {
        tracing::warn!(error = %e, order_id = order.id, "订单日志加载失败，渲染为空日志");
        Vec::new()
    });
    if let Some(obj) = value.as_object_mut() {
        obj.insert(
            "logs".to_string(),
            serde_json::to_value(logs).unwrap_or_else(|_| json!([])),
        );
    }

    if let Some(items) = value.get_mut("items").and_then(|v| v.as_array_mut()) {
        for item in items {
            if let Some(obj) = item.as_object_mut() {
                let item_id = obj.get("id").and_then(|v| v.as_i64()).unwrap_or_default();
                let purchase = order_repo::load_purchase(pool, item_id)
                    .await?
                    .map(|p| json!(p))
                    .unwrap_or_else(|| {
                        json!({
                            "caigou_user": "",
                            "caigou_time": "",
                            "tabaono": "",
                            "cn_amount": "",
                            "com_amount": "",
                            "caigou_link": "",
                            "buhuo_link": "",
                            "caigou_ordernums": "",
                            "cn_ship_number": ""
                        })
                    });
                let jp_shipment = order_repo::load_jp_shipment(pool, item_id)
                    .await?
                    .map(|s| json!(s))
                    .unwrap_or_else(|| {
                        json!({
                            "out_status": "待分配",
                            "assignee": "",
                            "operator": "",
                            "out_time": "",
                            "location": "",
                            "out_no": "",
                            "out_cost": ""
                        })
                    });
                let domestic_shipments = order_repo::load_domestic_shipments(pool, item_id).await?;
                let intl_shipments = order_repo::load_intl_shipments(pool, item_id).await?;

                obj.insert("purchase".to_string(), purchase);
                obj.insert("jp_shipment".to_string(), jp_shipment);
                obj.insert(
                    "domestic_shipments".to_string(),
                    serde_json::to_value(domestic_shipments).unwrap_or_else(|_| json!([])),
                );
                obj.insert(
                    "intl_shipments".to_string(),
                    serde_json::to_value(intl_shipments).unwrap_or_else(|_| json!([])),
                );
            }
        }
    }
    Ok(value)
}

/// 把**已过滤**的一组订单用共享订单块模板逐块渲染并拼接为 HTML 片段。
///
/// 入参订单应已完成视图 / 货源过滤（见 [`apply_source_selection`]）；本函数只负责渲染，
/// 不再二次过滤。无订单时返回空串。单个订单块渲染失败时**容错跳过**（记一条警告日志，
/// 不中断整个列表）——模板尚在迭代中，优先保证列表整体可用。
async fn render_order_blocks(
    pool: &MySqlPool,
    tera: &Tera,
    tenant_name: &str,
    view: ViewKind,
    orders: &[Order],
) -> String {
    let mut html = String::new();

    for order in orders {
        let order_value = match enrich_order_for_template(pool, order).await {
            Ok(value) => value,
            Err(e) => {
                tracing::warn!(
                    error = %e,
                    order_id = order.id,
                    view = view.template_name(),
                    "订单子表加载失败，已跳过该订单"
                );
                continue;
            }
        };
        let mut ctx = Context::new();
        ctx.insert("order", &order_value);
        ctx.insert("view", view.template_name());
        ctx.insert("tenant_name", tenant_name);
        ctx.insert("purchase_status_options", &purchase_status_options());

        match tera.render(ORDER_BLOCK_TEMPLATE, &ctx) {
            Ok(block) => html.push_str(&block),
            Err(e) => {
                tracing::warn!(
                    error = %e,
                    order_id = order.id,
                    view = view.template_name(),
                    "订单块渲染失败，已跳过该订单"
                );
            }
        }
    }

    html
}

// ============================================================================
// 数据装载（租户库）
// ============================================================================

fn push_like_condition(sql: &mut String, params: &mut Vec<String>, condition: &str, value: &str) {
    sql.push_str(" AND ");
    sql.push_str(condition);
    params.push(format!("%{}%", value.trim()));
}

/// 从租户库装载最近订单聚合（订单行 + 其全部子商品），按 id 倒序，最多 [`LIST_LIMIT`] 条。
///
/// 经 [`order_repo::get`] 逐个装配聚合，确保子商品列表完整（货源过滤依赖 `items`）。
async fn load_recent_orders(pool: &MySqlPool, filter: &ListFilter) -> Result<Vec<Order>, AppError> {
    let mut sql = String::from(
        "SELECT DISTINCT o.`id` \
         FROM `orders` o \
         LEFT JOIN `order_items` oi ON oi.`order_id` = o.`id` \
         LEFT JOIN `purchases` p ON p.`order_item_id` = oi.`id` \
         LEFT JOIN `domestic_shipments` ds ON ds.`order_item_id` = oi.`id` \
         LEFT JOIN `intl_shipments` intl ON intl.`order_item_id` = oi.`id` \
         LEFT JOIN `jp_shipments` jps ON jps.`order_item_id` = oi.`id` \
         WHERE 1 = 1",
    );
    let mut params = Vec::new();

    if let Some(platform) = &filter.platform {
        sql.push_str(" AND o.`platform` = ?");
        params.push(platform.trim().to_string());
    }
    if let Some(keyword) = &filter.keyword {
        push_like_condition(
            &mut sql,
            &mut params,
            "(o.`platform_order_id` LIKE ? OR o.`customer_name` LIKE ? OR o.`customer_phone` LIKE ? OR o.`customer_mail` LIKE ? OR oi.`item_code` LIKE ? OR oi.`product_title` LIKE ?)",
            keyword,
        );
        let pattern = params.pop().unwrap_or_default();
        for _ in 0..6 {
            params.push(pattern.clone());
        }
    }
    if let Some(status) = &filter.order_status {
        push_like_condition(&mut sql, &mut params, "o.`order_status` LIKE ?", status);
    }
    if let Some(purchase_no) = &filter.purchase_no {
        push_like_condition(
            &mut sql,
            &mut params,
            "(p.`tabaono` LIKE ? OR p.`caigou_ordernums` LIKE ?)",
            purchase_no,
        );
        let pattern = params.pop().unwrap_or_default();
        params.push(pattern.clone());
        params.push(pattern);
    }
    if let Some(ship_method) = &filter.ship_method {
        push_like_condition(&mut sql, &mut params, "o.`ship_method` LIKE ?", ship_method);
    }
    if let Some(purchase_status) = &filter.purchase_status {
        sql.push_str(" AND oi.`purchase_status` = ?");
        params.push(purchase_status.trim().to_string());
    }
    if let Some(out_status) = &filter.out_status {
        sql.push_str(" AND jps.`out_status` = ?");
        params.push(out_status.trim().to_string());
    }
    if let Some(assignee) = &filter.assignee {
        push_like_condition(&mut sql, &mut params, "jps.`assignee` LIKE ?", assignee);
    }
    if let Some(location) = &filter.location {
        push_like_condition(&mut sql, &mut params, "jps.`location` LIKE ?", location);
    }
    if let Some(item_id) = &filter.item_id {
        push_like_condition(&mut sql, &mut params, "oi.`item_code` LIKE ?", item_id);
    }
    if let Some(email) = &filter.email {
        push_like_condition(&mut sql, &mut params, "o.`customer_mail` LIKE ?", email);
    }
    if let Some(recipient) = &filter.recipient {
        push_like_condition(&mut sql, &mut params, "o.`customer_name` LIKE ?", recipient);
    }
    if let Some(phone) = &filter.phone {
        push_like_condition(&mut sql, &mut params, "o.`customer_phone` LIKE ?", phone);
    }
    if let Some(kana) = &filter.kana {
        push_like_condition(&mut sql, &mut params, "o.`customer_kana` LIKE ?", kana);
    }
    if let Some(tracking_no) = &filter.tracking_no {
        push_like_condition(
            &mut sql,
            &mut params,
            "(ds.`ship_number` LIKE ? OR intl.`intl_number` LIKE ? OR p.`cn_ship_number` LIKE ?)",
            tracking_no,
        );
        let pattern = params.pop().unwrap_or_default();
        for _ in 0..3 {
            params.push(pattern.clone());
        }
    }
    if let Some(buyer) = &filter.buyer {
        push_like_condition(
            &mut sql,
            &mut params,
            "(p.`caigou_user` LIKE ? OR oi.`caigou_user` LIKE ?)",
            buyer,
        );
        let pattern = params.pop().unwrap_or_default();
        params.push(pattern.clone());
        params.push(pattern);
    }
    if let Some(from) = &filter.imported_from {
        sql.push_str(" AND o.`imported_at` >= ?");
        params.push(from.trim().to_string());
    }
    if let Some(to) = &filter.imported_to {
        sql.push_str(" AND o.`imported_at` <= CONCAT(?, ' 23:59:59')");
        params.push(to.trim().to_string());
    }
    if let Some(review) = &filter.review_status {
        if review.contains("邀") {
            sql.push_str(" AND o.`review_invited` = 1");
        } else if review.contains("未评") {
            sql.push_str(" AND o.`reviewed` = 0");
        } else if review.contains("已评") || review.contains("评价") {
            sql.push_str(" AND o.`reviewed` = 1");
        }
    }

    sql.push_str(" ORDER BY o.`id` DESC LIMIT ?");

    let mut query = sqlx::query_as::<_, (i64,)>(&sql);
    for param in &params {
        query = query.bind(param);
    }
    let ids = query.bind(LIST_LIMIT).fetch_all(pool).await?;

    let mut orders = Vec::with_capacity(ids.len());
    for (id,) in ids {
        if let Some(order) = order_repo::get(pool, id).await? {
            orders.push(order);
        }
    }
    Ok(orders)
}

// ============================================================================
// Axum handlers —— 三视图列表路由
// ============================================================================

/// 装载租户订单并按视图 + 筛选渲染列表（外壳模板 + 订单块）的公共流程。
///
/// 步骤：装载订单 → 应用视图过滤与货源下拉收窄（货源下拉仅平台视图生效）→ 由主体计算
/// 工具条可用动作 → 逐块渲染订单 → 用列表外壳模板（含筛选区 + 工具条）拼装。
/// **容错**：外壳模板缺失 / 渲染失败时回退为纯订单块片段（见 [`LIST_SHELL_TEMPLATE`]）。
async fn render_list_view(
    state: &AppState,
    ctx: &TenantContext,
    view: ViewKind,
    filter: &ListFilter,
    principal: Option<&Principal>,
) -> Result<Html<String>, AppError> {
    let orders = load_recent_orders(&ctx.pool, filter).await?;

    // 货源下拉仅在平台视图生效；其余视图货源已由视图过滤约束，强制按「全部」叠加（即不再收窄）。
    let selection = if view == ViewKind::Platform {
        filter.source
    } else {
        SourceSelection::All
    };
    let filtered = apply_source_selection(orders, view, selection);

    // 列表涉及的店铺集合 → 工具条按权限对这些店铺判定。
    let store_ids = distinct_store_ids(&filtered);
    let toolbar = ToolbarModel::for_principal(principal, &store_ids);

    // 逐块渲染（已过滤）订单。
    let blocks =
        render_order_blocks(&ctx.pool, state.tera(), &ctx.company_name, view, &filtered).await;
    let platform_menu = platform_auth_service::load_sidebar_menu(state.master_pool(), ctx.tenant_id)
        .await
        .unwrap_or_else(|e| {
            tracing::warn!(error = %e, tenant_id = ctx.tenant_id, "平台菜单加载失败，侧栏将显示基础入口");
            Vec::new()
        });

    // 列表外壳：注入筛选区（常用默认显示 / 高级默认折叠）、货源下拉、工具条与订单块。
    let mut shell = Context::new();
    shell.insert("view", view.template_name());
    shell.insert("tenant_name", &ctx.company_name);
    shell.insert("tenant_id", &ctx.tenant_id);
    shell.insert("platform_menu", &platform_menu);
    let active_nav = if view == ViewKind::Platform {
        "orders"
    } else {
        view.template_name()
    };
    shell.insert("active_nav", active_nav);
    shell.insert("active_platform", &filter.platform);
    shell.insert("filter", filter);
    shell.insert("show_source_dropdown", &(view == ViewKind::Platform));
    shell.insert("source_options", &source_options(selection));
    shell.insert("purchase_status_options", &purchase_status_options());
    shell.insert("advanced_expanded", &filter.has_advanced());
    shell.insert("toolbar", &toolbar);
    shell.insert("order_blocks", &blocks);
    shell.insert("order_count", &filtered.len());
    shell.insert("item_count", &count_items(&filtered));

    match state.tera().render(LIST_SHELL_TEMPLATE, &shell) {
        Ok(html) => Ok(Html(html)),
        Err(e) => {
            // 外壳模板尚未补齐：容错回退为纯订单块片段，保证列表可用。
            tracing::debug!(
                error = %e,
                view = view.template_name(),
                "列表外壳模板渲染失败，回退为纯订单块片段"
            );
            Ok(Html(blocks))
        }
    }
}

/// 平台订单视图（`GET /orders`）：不过滤货源，展示全部子商品（Requirements 9.1）；
/// 支持货源地下拉收窄与常用/高级筛选（Requirements 9.5）。
pub async fn platform_orders(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    principal: Option<Extension<Principal>>,
    Query(params): Query<HashMap<String, String>>,
) -> Result<Html<String>, AppError> {
    let filter = ListFilter::from_query(&params);
    let principal = principal.as_ref().map(|Extension(p)| p);
    render_list_view(&state, &ctx, ViewKind::Platform, &filter, principal).await
}

/// 采购订单视图（`GET /purchase-orders`）：仅 `cn_purchase` 子商品（Requirements 9.1、9.2）。
pub async fn purchase_orders(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    principal: Option<Extension<Principal>>,
    Query(params): Query<HashMap<String, String>>,
) -> Result<Html<String>, AppError> {
    let filter = ListFilter::from_query(&params);
    let principal = principal.as_ref().map(|Extension(p)| p);
    render_list_view(&state, &ctx, ViewKind::Purchase, &filter, principal).await
}

/// 日本仓发货视图（`GET /jp-shipments`）：仅 `jp_stock` 子商品（Requirements 9.1、9.2）。
pub async fn jp_warehouse_orders(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
    principal: Option<Extension<Principal>>,
    Query(params): Query<HashMap<String, String>>,
) -> Result<Html<String>, AppError> {
    let filter = ListFilter::from_query(&params);
    let principal = principal.as_ref().map(|Extension(p)| p);
    render_list_view(&state, &ctx, ViewKind::JpWarehouse, &filter, principal).await
}

/// 组装三视图列表路由。
///
/// - `GET /orders`          → 平台订单视图（不过滤货源）
/// - `GET /purchase-orders` → 采购订单视图（仅 `cn_purchase`）
/// - `GET /jp-shipments`    → 日本仓发货视图（仅 `jp_stock`）
pub fn routes() -> Router<AppState> {
    Router::new()
        .route("/orders", get(platform_orders))
        .route("/purchase-orders", get(purchase_orders))
        .route("/jp-shipments", get(jp_warehouse_orders))
}

// ============================================================================
// 单元测试 —— 纯货源过滤（Requirements 9.1、9.2）
// ============================================================================

#[cfg(test)]
mod tests {
    use super::*;
    use crate::models::order::{OrderItem, PurchaseStatus};

    /// 构造一个仅设置 id 与 `source_type` 的最小子商品；其余字段取占位默认值。
    /// 货源过滤仅依赖 `source_type`，其它字段对过滤结果无影响。
    fn item(id: i64, source_type: SourceType) -> OrderItem {
        OrderItem {
            id,
            order_id: 1,
            source_type,
            purchase_status: PurchaseStatus::default(),
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

    /// 构造一个带指定 id 与子商品列表的订单（其余字段默认）。
    fn order(id: i64, items: Vec<OrderItem>) -> Order {
        Order {
            id,
            items,
            ..Default::default()
        }
    }

    /// 收集某订单的子商品 id（用于断言保留了哪些子商品）。
    fn item_ids(order: &Order) -> Vec<i64> {
        order.items.iter().map(|it| it.id).collect()
    }

    /// 收集结果中的订单 id（用于断言保留 / 丢弃了哪些订单块）。
    fn order_ids(orders: &[Order]) -> Vec<i64> {
        orders.iter().map(|o| o.id).collect()
    }

    #[test]
    fn view_kind_source_filter_mapping() {
        assert_eq!(ViewKind::Platform.source_filter(), None);
        assert_eq!(
            ViewKind::Purchase.source_filter(),
            Some(SourceType::CnPurchase)
        );
        assert_eq!(
            ViewKind::JpWarehouse.source_filter(),
            Some(SourceType::JpStock)
        );
    }

    #[test]
    fn view_kind_accepts_matches_filter() {
        // 平台视图接纳一切货源。
        for s in SourceType::ALL {
            assert!(ViewKind::Platform.accepts(s));
        }
        // 采购视图仅接纳 cn_purchase。
        assert!(ViewKind::Purchase.accepts(SourceType::CnPurchase));
        assert!(!ViewKind::Purchase.accepts(SourceType::JpStock));
        assert!(!ViewKind::Purchase.accepts(SourceType::Pending));
        // 日本仓视图仅接纳 jp_stock。
        assert!(ViewKind::JpWarehouse.accepts(SourceType::JpStock));
        assert!(!ViewKind::JpWarehouse.accepts(SourceType::CnPurchase));
        assert!(!ViewKind::JpWarehouse.accepts(SourceType::Pending));
    }

    #[test]
    fn platform_view_keeps_all_orders_and_all_items() {
        // 平台视图不过滤：所有订单与子商品原样保留。
        let orders = vec![
            order(
                1,
                vec![
                    item(10, SourceType::CnPurchase),
                    item(11, SourceType::JpStock),
                ],
            ),
            order(2, vec![item(20, SourceType::Pending)]),
        ];
        let result = filter_orders_for_view(orders, ViewKind::Platform);
        assert_eq!(order_ids(&result), vec![1, 2]);
        assert_eq!(item_ids(&result[0]), vec![10, 11]);
        assert_eq!(item_ids(&result[1]), vec![20]);
    }

    #[test]
    fn purchase_view_keeps_only_cn_purchase_and_drops_orders_with_none() {
        let orders = vec![
            // 订单 1：混合货源，仅保留 cn_purchase（id=10、12）。
            order(
                1,
                vec![
                    item(10, SourceType::CnPurchase),
                    item(11, SourceType::JpStock),
                    item(12, SourceType::CnPurchase),
                ],
            ),
            // 订单 2：无 cn_purchase ⟹ 整块被丢弃（Requirements 9.2）。
            order(
                2,
                vec![item(20, SourceType::JpStock), item(21, SourceType::Pending)],
            ),
            // 订单 3：全是 cn_purchase ⟹ 全部保留。
            order(3, vec![item(30, SourceType::CnPurchase)]),
        ];
        let result = filter_orders_for_view(orders, ViewKind::Purchase);
        assert_eq!(order_ids(&result), vec![1, 3]);
        assert_eq!(item_ids(&result[0]), vec![10, 12]);
        assert_eq!(item_ids(&result[1]), vec![30]);
    }

    #[test]
    fn jp_warehouse_view_keeps_only_jp_stock_and_drops_empties() {
        let orders = vec![
            // 订单 1：无 jp_stock ⟹ 丢弃。
            order(
                1,
                vec![
                    item(10, SourceType::CnPurchase),
                    item(11, SourceType::Pending),
                ],
            ),
            // 订单 2：混合，仅保留 jp_stock（id=21）。
            order(
                2,
                vec![
                    item(20, SourceType::CnPurchase),
                    item(21, SourceType::JpStock),
                ],
            ),
        ];
        let result = filter_orders_for_view(orders, ViewKind::JpWarehouse);
        assert_eq!(order_ids(&result), vec![2]);
        assert_eq!(item_ids(&result[0]), vec![21]);
    }

    #[test]
    fn mixed_source_order_keeps_only_matching_items() {
        // 单个混合货源订单在采购 / 日本仓视图下分别只保留各自匹配的子商品。
        let mixed = || {
            order(
                7,
                vec![
                    item(1, SourceType::CnPurchase),
                    item(2, SourceType::JpStock),
                    item(3, SourceType::Pending),
                    item(4, SourceType::CnPurchase),
                    item(5, SourceType::JpStock),
                ],
            )
        };

        let purchase = filter_orders_for_view(vec![mixed()], ViewKind::Purchase);
        assert_eq!(order_ids(&purchase), vec![7]);
        assert_eq!(item_ids(&purchase[0]), vec![1, 4]);

        let jp = filter_orders_for_view(vec![mixed()], ViewKind::JpWarehouse);
        assert_eq!(order_ids(&jp), vec![7]);
        assert_eq!(item_ids(&jp[0]), vec![2, 5]);
    }

    #[test]
    fn empty_orders_yield_empty_result_for_all_views() {
        for view in [
            ViewKind::Platform,
            ViewKind::Purchase,
            ViewKind::JpWarehouse,
        ] {
            assert!(filter_orders_for_view(Vec::new(), view).is_empty());
        }
    }

    #[test]
    fn order_with_no_items_is_dropped_even_in_platform_view() {
        // 即便平台视图不过滤货源，零子商品的订单仍无可渲染内容 ⟹ 丢弃。
        let result = filter_orders_for_view(vec![order(1, vec![])], ViewKind::Platform);
        assert!(result.is_empty());
    }

    #[test]
    fn template_name_matches_order_block_contract() {
        assert_eq!(ViewKind::Platform.template_name(), "platform");
        assert_eq!(ViewKind::Purchase.template_name(), "purchase");
        assert_eq!(ViewKind::JpWarehouse.template_name(), "jpstock");
    }
}

// ============================================================================
// 单元测试 —— Task 10.3 筛选与列表交互区（设计 7.5 / Requirements 9.5）
//
// 覆盖：
//   1. 货源地下拉 → SourceType 映射（全部=>None / 日本仓=>JpStock / 国内采购=>CnPurchase）；
//   2. 货源下拉叠加在视图过滤之上的组合过滤（apply_source_selection）；
//   3. 工具条动作按主体权限的可见性门控（SuperAdmin/CompanyAdmin 全开；受限员工按数据操作门控）。
// ============================================================================
#[cfg(test)]
mod task_10_3_tests {
    use super::*;
    use crate::models::order::{OrderItem, PurchaseStatus};
    use crate::models::user::{Principal, Role, StoreScope, TenantId};
    use std::collections::HashMap;

    // —— 构造工具：最小订单 / 子商品（仅设置过滤相关字段） ——

    fn item(id: i64, source_type: SourceType) -> OrderItem {
        OrderItem {
            id,
            order_id: 1,
            source_type,
            purchase_status: PurchaseStatus::default(),
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

    fn order_with_store(id: i64, store_id: Option<i64>, items: Vec<OrderItem>) -> Order {
        Order {
            id,
            store_id,
            items,
            ..Default::default()
        }
    }

    fn order_ids(orders: &[Order]) -> Vec<i64> {
        orders.iter().map(|o| o.id).collect()
    }

    fn item_ids(order: &Order) -> Vec<i64> {
        order.items.iter().map(|it| it.id).collect()
    }

    // ----- (1) 货源下拉 → SourceType 映射 -----

    #[test]
    fn source_selection_to_source_filter_mapping() {
        assert_eq!(SourceSelection::All.to_source_filter(), None);
        assert_eq!(
            SourceSelection::JpWarehouse.to_source_filter(),
            Some(SourceType::JpStock)
        );
        assert_eq!(
            SourceSelection::CnPurchase.to_source_filter(),
            Some(SourceType::CnPurchase)
        );
    }

    #[test]
    fn source_selection_from_param_accepts_codes_and_labels() {
        // 全部（含缺省 / 未知值）
        assert_eq!(SourceSelection::from_param(""), SourceSelection::All);
        assert_eq!(SourceSelection::from_param("all"), SourceSelection::All);
        assert_eq!(SourceSelection::from_param("全部"), SourceSelection::All);
        assert_eq!(SourceSelection::from_param("bogus"), SourceSelection::All);
        // 日本仓
        assert_eq!(
            SourceSelection::from_param("jp_stock"),
            SourceSelection::JpWarehouse
        );
        assert_eq!(
            SourceSelection::from_param("日本仓"),
            SourceSelection::JpWarehouse
        );
        // 国内采购
        assert_eq!(
            SourceSelection::from_param("cn_purchase"),
            SourceSelection::CnPurchase
        );
        assert_eq!(
            SourceSelection::from_param("国内采购"),
            SourceSelection::CnPurchase
        );
    }

    #[test]
    fn source_selection_param_round_trip() {
        for sel in SourceSelection::ALL {
            assert_eq!(SourceSelection::from_param(sel.as_param()), sel);
        }
    }

    #[test]
    fn source_options_marks_current_selection() {
        let opts = source_options(SourceSelection::JpWarehouse);
        assert_eq!(opts.len(), 3);
        // 顺序：全部 / 日本仓 / 国内采购
        assert_eq!(opts[0].label, "全部");
        assert_eq!(opts[1].label, "日本仓");
        assert_eq!(opts[2].label, "国内采购");
        assert!(!opts[0].selected);
        assert!(opts[1].selected, "当前选择应为日本仓");
        assert!(!opts[2].selected);
    }

    // ----- (2) 组合过滤：货源下拉叠加在视图过滤之上 -----

    #[test]
    fn platform_view_all_selection_keeps_everything() {
        let orders = vec![
            order_with_store(
                1,
                Some(10),
                vec![
                    item(100, SourceType::CnPurchase),
                    item(101, SourceType::JpStock),
                ],
            ),
            order_with_store(2, Some(10), vec![item(200, SourceType::Pending)]),
        ];
        let result = apply_source_selection(orders, ViewKind::Platform, SourceSelection::All);
        assert_eq!(order_ids(&result), vec![1, 2]);
        assert_eq!(item_ids(&result[0]), vec![100, 101]);
    }

    #[test]
    fn platform_view_jp_warehouse_selection_keeps_only_jp_stock() {
        let orders = vec![
            order_with_store(
                1,
                Some(10),
                vec![
                    item(100, SourceType::CnPurchase),
                    item(101, SourceType::JpStock),
                    item(102, SourceType::JpStock),
                ],
            ),
            // 无 jp_stock ⟹ 整块被丢弃。
            order_with_store(2, Some(10), vec![item(200, SourceType::CnPurchase)]),
        ];
        let result =
            apply_source_selection(orders, ViewKind::Platform, SourceSelection::JpWarehouse);
        assert_eq!(order_ids(&result), vec![1]);
        assert_eq!(item_ids(&result[0]), vec![101, 102]);
    }

    #[test]
    fn platform_view_cn_purchase_selection_keeps_only_cn_purchase() {
        let orders = vec![order_with_store(
            7,
            Some(10),
            vec![
                item(1, SourceType::CnPurchase),
                item(2, SourceType::JpStock),
                item(3, SourceType::CnPurchase),
            ],
        )];
        let result =
            apply_source_selection(orders, ViewKind::Platform, SourceSelection::CnPurchase);
        assert_eq!(order_ids(&result), vec![7]);
        assert_eq!(item_ids(&result[0]), vec![1, 3]);
    }

    #[test]
    fn all_selection_is_equivalent_to_plain_view_filter() {
        // 「全部」叠加 ⟺ 仅视图过滤（对三视图均成立）。
        let make = || {
            vec![
                order_with_store(
                    1,
                    Some(10),
                    vec![
                        item(100, SourceType::CnPurchase),
                        item(101, SourceType::JpStock),
                    ],
                ),
                order_with_store(2, Some(11), vec![item(200, SourceType::Pending)]),
            ]
        };
        for view in [
            ViewKind::Platform,
            ViewKind::Purchase,
            ViewKind::JpWarehouse,
        ] {
            let combined = apply_source_selection(make(), view, SourceSelection::All);
            let plain = filter_orders_for_view(make(), view);
            assert_eq!(order_ids(&combined), order_ids(&plain), "view={view:?}");
            for (a, b) in combined.iter().zip(plain.iter()) {
                assert_eq!(item_ids(a), item_ids(b), "view={view:?} order={}", a.id);
            }
        }
    }

    #[test]
    fn contradictory_selection_on_non_platform_view_yields_empty() {
        // 采购视图叠加「日本仓」相斥 ⟹ 空结果（货源已被视图约束为 cn_purchase）。
        let orders = vec![order_with_store(
            1,
            Some(10),
            vec![item(100, SourceType::CnPurchase)],
        )];
        let result =
            apply_source_selection(orders, ViewKind::Purchase, SourceSelection::JpWarehouse);
        assert!(result.is_empty());
    }

    // ----- 筛选模型解析 -----

    #[test]
    fn list_filter_from_query_parses_common_and_advanced_fields() {
        let mut params = HashMap::new();
        params.insert("keyword".to_string(), "  订单A ".to_string());
        params.insert("source".to_string(), "jp_stock".to_string());
        params.insert("email".to_string(), "a@b.com".to_string());
        params.insert("recipient".to_string(), "".to_string()); // 空白 ⟹ None
        let f = ListFilter::from_query(&params);
        assert_eq!(f.keyword.as_deref(), Some("订单A")); // 去空白
        assert_eq!(f.source, SourceSelection::JpWarehouse);
        assert_eq!(f.email.as_deref(), Some("a@b.com"));
        assert_eq!(f.recipient, None);
        assert!(f.has_advanced(), "email 已填 ⟹ 高级区应展开");
    }

    #[test]
    fn list_filter_default_has_no_advanced_and_source_all() {
        let f = ListFilter::from_query(&HashMap::new());
        assert_eq!(f.source, SourceSelection::All);
        assert!(!f.has_advanced());
        assert_eq!(f.keyword, None);
    }

    // ----- (3) 工具条权限门控 -----

    fn employee(scope: StoreScope) -> Principal {
        Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 42,
            role: Role::Buyer,
            overrides: HashMap::new(),
            store_scope: scope,
        }
    }

    #[test]
    fn toolbar_action_required_action_mapping() {
        assert_eq!(ToolbarAction::SelectAll.required_action(), None);
        assert_eq!(ToolbarAction::ExpandDetails.required_action(), None);
        assert_eq!(
            ToolbarAction::BatchStatus.required_action(),
            Some(DataAction::Edit)
        );
        assert_eq!(
            ToolbarAction::BatchAssign.required_action(),
            Some(DataAction::Edit)
        );
        assert_eq!(
            ToolbarAction::BatchDelete.required_action(),
            Some(DataAction::Delete)
        );
    }

    #[test]
    fn super_admin_sees_all_toolbar_actions() {
        let tb = ToolbarModel::for_principal(Some(&Principal::SuperAdmin), &[1, 2, 3]);
        assert_eq!(
            tb,
            ToolbarModel {
                select_all: true,
                expand_details: true,
                batch_status: true,
                batch_assign: true,
                batch_delete: true,
            }
        );
    }

    #[test]
    fn company_admin_sees_all_toolbar_actions() {
        let p = Principal::CompanyAdmin {
            tenant_id: TenantId(7),
        };
        let tb = ToolbarModel::for_principal(Some(&p), &[5, 9]);
        assert!(tb.select_all && tb.expand_details);
        assert!(tb.batch_status && tb.batch_assign && tb.batch_delete);
    }

    #[test]
    fn restricted_employee_in_scope_sees_all_actions() {
        // 列表店铺全部落在受限范围内 ⟹ 批量动作可用。
        let p = employee(StoreScope::Restricted(vec![1, 3]));
        let tb = ToolbarModel::for_principal(Some(&p), &[1, 3]);
        assert!(tb.batch_status && tb.batch_assign && tb.batch_delete);
    }

    #[test]
    fn restricted_employee_out_of_scope_gated_off_data_actions() {
        // 列表含范围外店铺 2 ⟹ 需数据权限的批量动作被门控关闭，仅保留纯 UI 动作。
        let p = employee(StoreScope::Restricted(vec![1, 3]));
        let tb = ToolbarModel::for_principal(Some(&p), &[1, 2]);
        assert!(tb.select_all, "全选为纯 UI，恒可用");
        assert!(tb.expand_details, "展开详情为纯 UI，恒可用");
        assert!(!tb.batch_status, "范围外店铺 ⟹ 批量改状态(Edit)关闭");
        assert!(!tb.batch_assign, "范围外店铺 ⟹ 批量分配(Edit)关闭");
        assert!(!tb.batch_delete, "范围外店铺 ⟹ 批量删除(Delete)关闭");
    }

    #[test]
    fn missing_principal_only_ui_actions() {
        // 主体缺失（未注入鉴权）⟹ 杜绝越权批量，仅保留纯 UI 动作。
        let actions = visible_toolbar_actions(None, &[1, 2]);
        assert_eq!(
            actions,
            vec![ToolbarAction::SelectAll, ToolbarAction::ExpandDetails]
        );
    }

    #[test]
    fn distinct_store_ids_dedups_and_skips_null() {
        let orders = vec![
            order_with_store(1, Some(10), vec![item(1, SourceType::Pending)]),
            order_with_store(2, None, vec![item(2, SourceType::Pending)]),
            order_with_store(3, Some(10), vec![item(3, SourceType::Pending)]),
            order_with_store(4, Some(11), vec![item(4, SourceType::Pending)]),
        ];
        assert_eq!(distinct_store_ids(&orders), vec![10, 11]);
    }
}

// ============================================================================
// 集成测试 —— Task 10.6 三视图渲染（设计 7.1–7.4 / Requirements 9.1、9.2、9.4）
//
// 与上面的纯函数过滤单测互补：本模块加载真实 Tera 模板（src/templates/**/*.html，
// 与 state.rs::DEFAULT_TEMPLATE_GLOB 同一通配符），用共享订单块模板
// `partials/order_block.html` 做**端到端渲染**，断言：
//   9.1 三视图共用同一订单块模板，仅以「平台视图货源可改判（<select>）/ 非平台视图只读货源标签」
//       与货源过滤相区分；
//   9.2 经 filter_orders_for_view 过滤后无符合货源子商品的订单 ⟹ 渲染产出**零订单块**；
//   9.4 B2 区按子商品货源显隐：cn_purchase ⟹ 采购物流（b2-purchase / 采购人…），
//       jp_stock ⟹ 出库信息（b2-jpstock / 出库状态…），pending ⟹ 货源待定占位。
//
// 模板渲染依赖测试工作目录为 crate 根（cargo test 默认即 crate 根），与生产加载路径一致。
// ============================================================================
#[cfg(test)]
mod task_10_6_render_tests {
    use super::*;
    use crate::models::order::{OrderItem, PurchaseStatus};

    /// 共享订单块模板加载通配符（与 `state.rs::DEFAULT_TEMPLATE_GLOB` 保持一致）。
    const TEMPLATE_GLOB: &str = "src/templates/**/*.html";

    /// 加载真实 Tera 模板集合；加载失败直接 panic（测试需要真实模板，区别于生产容错回退）。
    fn load_real_templates() -> Tera {
        Tera::new(TEMPLATE_GLOB).expect("加载 src/templates/**/*.html 失败")
    }

    /// 构造一个带指定 id / 货源 / 编码的最小子商品（仅设置渲染断言相关字段）。
    fn item(id: i64, source_type: SourceType, item_code: &str) -> OrderItem {
        OrderItem {
            id,
            order_id: 1,
            source_type,
            purchase_status: PurchaseStatus::CnPurchased,
            item_code: item_code.to_string(),
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

    /// 构造一个带 id / 平台订单号 / 子商品列表的订单（其余字段默认）。
    fn order(id: i64, platform_order_id: &str, items: Vec<OrderItem>) -> Order {
        Order {
            id,
            platform: "y".to_string(),
            platform_order_id: platform_order_id.to_string(),
            items,
            ..Default::default()
        }
    }

    /// 把订单序列化为 JSON 并为每个子商品**装配 B2/C 区所需的子表对象**
    /// （`purchase` / `jp_shipment` / `domestic_shipments` / `intl_shipments`）。
    ///
    /// 这是共享订单块模板的**上下文契约**（见 `order_block.html` 头注释「以及上游装配的
    /// item.purchase / item.jp_shipment / item.domestic_shipments / item.intl_shipments」）：
    /// B2 区 jp_stock 分支在 `{% if item.jp_shipment.out_status == "待分配" or not item.jp_shipment %}`
    /// 中**未加 default 过滤**，故渲染前 handler 必须把按 `order_item_id` 关联的子表对象装配到
    /// 每个子商品上。本测试在上下文层面忠实复刻该装配，以驱动 B2 区按货源显隐渲染。
    fn enrich_order_json(order: &Order) -> serde_json::Value {
        let mut value = serde_json::to_value(order).expect("序列化订单失败");
        if let Some(items) = value.get_mut("items").and_then(|v| v.as_array_mut()) {
            for item in items.iter_mut() {
                let obj = item.as_object_mut().expect("子商品应为 JSON 对象");
                obj.insert(
                    "purchase".to_string(),
                    serde_json::json!({
                        "caigou_user": "郭采购",
                        "caigou_time": "2024-01-01 10:00:00",
                        "tabaono": "TB-1688-001",
                        "cn_amount": "100.00",
                        "com_amount": "0.00",
                        "caigou_link": "",
                        "buhuo_link": "",
                        "caigou_ordernums": "",
                        "cn_ship_number": "",
                    }),
                );
                obj.insert(
                    "jp_shipment".to_string(),
                    serde_json::json!({
                        "out_status": "待分配",
                        "assignee": "",
                        "operator": "",
                        "out_time": "",
                        "location": "",
                        "out_no": "",
                        "out_cost": "",
                    }),
                );
                obj.insert("domestic_shipments".to_string(), serde_json::json!([]));
                obj.insert("intl_shipments".to_string(), serde_json::json!([]));
            }
        }
        value
    }

    /// 渲染单个订单块为 HTML（直接调用共享模板，便于断言单块内容）。
    ///
    /// 按模板上下文契约装配子表对象（见 [`enrich_order_json`]），等价于生产 handler 在
    /// 渲染前完成的子表装配步骤。
    fn render_block(tera: &Tera, view: ViewKind, order: &Order) -> String {
        let mut ctx = Context::new();
        ctx.insert("order", &enrich_order_json(order));
        ctx.insert("view", view.template_name());
        ctx.insert("tenant_name", "测试租户");
        ctx.insert("tenant_id", &1i64);
        ctx.insert("active_nav", view.template_name());
        ctx.insert("active_platform", &Option::<String>::None);
        ctx.insert(
            "platform_menu",
            &Vec::<platform_auth_service::PlatformMenuItem>::new(),
        );
        ctx.insert("purchase_status_options", &purchase_status_options());
        tera.render(ORDER_BLOCK_TEMPLATE, &ctx)
            .expect("订单块模板渲染失败")
    }

    // ----- 9.1 三视图共用同一订单块模板，按视图区分货源改判 / 只读 -----

    #[test]
    fn req_9_1_three_views_share_same_order_block_template() {
        let tera = load_real_templates();
        // 模板集合中确实注册了共享订单块模板（三视图共用其名）。
        let names: Vec<&str> = tera.get_template_names().collect();
        assert!(
            names.contains(&ORDER_BLOCK_TEMPLATE),
            "应注册共享订单块模板 {ORDER_BLOCK_TEMPLATE}，实际：{names:?}"
        );
    }

    #[test]
    fn req_9_1_platform_view_renders_source_dropdown_others_readonly() {
        let tera = load_real_templates();
        let cn = || order(1, "P-1", vec![item(10, SourceType::CnPurchase, "AAA")]);

        // 平台视图：货源为可改判下拉（<select class="source-select">，改判入口 9.5/3.5）。
        let platform_html = render_block(&tera, ViewKind::Platform, &cn());
        assert!(
            platform_html.contains("source-select"),
            "平台视图 B1 区货源应为可改判下拉 <select class=\"source-select\">"
        );
        // 平台视图默认展示 A 区（客户信息整单共享）。
        assert!(
            platform_html.contains("A 区 · 客户信息"),
            "平台视图应默认渲染 A 区客户信息"
        );

        // 采购视图：货源为只读标签（无下拉）。
        let purchase_html = render_block(&tera, ViewKind::Purchase, &cn());
        assert!(
            !purchase_html.contains("source-select"),
            "采购视图货源应为只读标签，不应出现改判下拉"
        );
        assert!(
            purchase_html.contains("source-tag"),
            "采购视图 B1 区货源应渲染为只读标签 source-tag"
        );

        // 日本仓视图：同为只读标签。
        let jp_html = render_block(
            &tera,
            ViewKind::JpWarehouse,
            &order(2, "P-2", vec![item(20, SourceType::JpStock, "BBB")]),
        );
        assert!(!jp_html.contains("source-select"));
        assert!(jp_html.contains("source-tag"));

        // 三视图都渲染 B1 区（商品明细，每子商品一行）——共用同一块。
        for html in [&platform_html, &purchase_html, &jp_html] {
            assert!(
                html.contains("B1 区 · 商品明细"),
                "三视图应共用 B1 区明细表"
            );
        }
    }

    // ----- 9.2 空货源订单块不渲染（过滤后零订单 ⟹ 渲染零块） -----

    #[test]
    fn req_9_2_order_without_matching_source_renders_no_block() {
        let tera = load_real_templates();

        // 订单仅含 jp_stock 子商品：在采购视图（cn_purchase）下应被整块剔除。
        let orders = vec![order(1, "P-1", vec![item(10, SourceType::JpStock, "AAA")])];
        let filtered = filter_orders_for_view(orders, ViewKind::Purchase);
        assert!(filtered.is_empty(), "无 cn_purchase 子商品的订单应被过滤掉");

        // 按生产同一共享模板渲染；这里使用测试上下文装配子表，避免单测依赖真实 DB。
        let html = filtered
            .iter()
            .map(|o| render_block(&tera, ViewKind::Purchase, o))
            .collect::<String>();
        assert!(html.trim().is_empty(), "过滤后无订单 ⟹ 不应渲染任何订单块");
    }

    #[test]
    fn req_9_2_mixed_list_renders_only_matching_orders() {
        let tera = load_real_templates();

        // 订单 1 含 cn_purchase（采购视图保留），订单 2 仅 jp_stock（采购视图剔除）。
        let orders = vec![
            order(1, "KEEP-1", vec![item(10, SourceType::CnPurchase, "AAA")]),
            order(2, "DROP-2", vec![item(20, SourceType::JpStock, "BBB")]),
        ];
        let filtered = filter_orders_for_view(orders, ViewKind::Purchase);
        assert_eq!(filtered.len(), 1, "采购视图仅保留含 cn_purchase 的订单");

        let html = filtered
            .iter()
            .map(|o| render_block(&tera, ViewKind::Purchase, o))
            .collect::<String>();
        // 仅保留订单 1 的块（含其平台订单号），被剔除订单 2 不出现。
        assert!(html.contains("KEEP-1"), "保留订单的块应被渲染");
        assert!(
            !html.contains("DROP-2"),
            "无符合货源子商品的订单块不应渲染（Requirements 9.2）"
        );
        // 仅渲染一个订单块。
        assert_eq!(
            html.matches("class=\"order-block\"").count(),
            1,
            "应只渲染一个订单块"
        );
    }

    // ----- 9.4 B2 区按货源显隐：cn_purchase ⟹ 采购物流；jp_stock ⟹ 出库信息 -----

    #[test]
    fn req_9_4_b2_shows_purchase_logistics_for_cn_purchase() {
        let tera = load_real_templates();
        // 采购视图渲染仅含 cn_purchase 的订单。
        let o = order(1, "P-CN", vec![item(10, SourceType::CnPurchase, "AAA")]);
        let html = render_block(&tera, ViewKind::Purchase, &o);

        // B2 区呈现采购物流（采购行 + 采购人/1688单号等采购语义字段）。
        assert!(
            html.contains("b2-purchase"),
            "cn_purchase 子商品 B2 应渲染采购物流行"
        );
        assert!(html.contains("采购人"), "B2 采购物流应含「采购人」字段");
        assert!(html.contains("1688单号"), "B2 采购物流应含「1688单号」字段");
        // 不应出现出库信息行（出库为 jp_stock 专属）。
        assert!(
            !html.contains("b2-jpstock"),
            "cn_purchase 子商品 B2 不应渲染出库信息行"
        );
    }

    #[test]
    fn req_9_4_b2_shows_jp_shipment_for_jp_stock() {
        let tera = load_real_templates();
        // 日本仓视图渲染仅含 jp_stock 的订单。
        let o = order(2, "P-JP", vec![item(20, SourceType::JpStock, "BBB")]);
        let html = render_block(&tera, ViewKind::JpWarehouse, &o);

        // B2 区呈现出库信息（出库行 + 出库状态/发货员等出库语义字段）。
        assert!(
            html.contains("b2-jpstock"),
            "jp_stock 子商品 B2 应渲染出库信息行"
        );
        assert!(html.contains("出库状态"), "B2 出库信息应含「出库状态」字段");
        assert!(html.contains("发货员"), "B2 出库信息应含「发货员」字段");
        // 无出库记录的 jp_stock 行应高亮为未分配（Requirements 9.6 关联）。
        assert!(
            html.contains("row-unassigned"),
            "无出库记录的 jp_stock 行应标记未分配高亮"
        );
        // 不应出现采购物流行。
        assert!(
            !html.contains("b2-purchase"),
            "jp_stock 子商品 B2 不应渲染采购物流行"
        );
    }

    #[test]
    fn req_9_4_b2_differs_by_source_type_within_same_template() {
        let tera = load_real_templates();
        // 同一订单块模板，仅因子商品货源不同 ⟹ B2 区分流不同（平台视图同时含两种货源）。
        let mixed = order(
            3,
            "P-MIX",
            vec![
                item(30, SourceType::CnPurchase, "AAA"),
                item(31, SourceType::JpStock, "BBB"),
                item(32, SourceType::Pending, "CCC"),
            ],
        );
        let html = render_block(&tera, ViewKind::Platform, &mixed);

        // 三种货源在 B2 区分别落到采购物流 / 出库信息 / 货源待定占位。
        assert!(html.contains("b2-purchase"), "cn_purchase ⟹ 采购物流行");
        assert!(html.contains("b2-jpstock"), "jp_stock ⟹ 出库信息行");
        assert!(html.contains("b2-pending"), "pending ⟹ 货源待定占位行");
        assert!(html.contains("货源待定"), "pending 行应提示货源待定");
    }
}
