//! 日本仓发货工作流处理器（Task 10.4 / Requirements 9.6）。
//!
//! 设计依据 `design.md` 3.7（`jp_shipments` 出库状态机）与 7.4（日本仓发货视图交互）：
//! `jp_stock` 子商品不经 1688 采购，改走日本仓出库工作流——**先分配发货员，再出库，
//! 最后交国际物流**。`jp_shipments.out_status` 状态机（设计 3.7）：
//!
//! ```text
//! [*] → 待分配
//! 待分配  → 已分配  : 分配发货员（assignee）
//! 已分配  → 已出库  : 出库（填仓位 location / 出库单号 out_no / 出库人 operator / 出库时间 out_time / 出库成本 out_cost）
//! 已出库  → 已发货  : 交国际物流
//! 已发货  → [*]
//! ```
//!
//! 视图交互（设计 7.4 / Requirements 9.6）：日本仓发货视图列出全部 `jp_stock` 子商品，
//! 复用订单块，B2 区呈现出库信息；按 `out_status` 推进；**未分配（待分配）的行高亮提示**
//! （原型 `assign-sel.unassigned` 橙色描边）；支持「按发货员分配」「批量出库」。
//!
//! 分层取舍（与 `order_list` / `order_save` 一致）：
//! - **纯状态机逻辑**（[`OutStatus`] / [`validate_transition`] / [`is_unassigned`] /
//!   [`parse_id_list`] / [`render_status_cell`]）与 DB 副作用、Tera 渲染严格分离，可独立单测；
//! - **仓储式更新函数**（[`assign`] / [`mark_out`] / [`mark_shipped`]）就近放在本模块，
//!   统一以 SQLx 运行时 API（`query` + 显式 SQL，**不用 `query!` 宏**，租户库编译期不存在）
//!   写入 `jp_shipments`，且每次写入前都先校验状态机迁移合法性（拒绝非法跳变）；
//! - **axum handler** 仅负责解析表单 / 装载数据 / 调用上述纯逻辑与仓储函数 / 渲染回填。
//!
//! 读取一律复用既有 [`order_repo`]（`load_jp_shipment` 等）与 `order_list` 的货源过滤
//! （[`ViewKind::JpWarehouse`] / [`filter_orders_for_view`]），不重复造轮子。

use axum::{
    extract::{Extension, State},
    response::Html,
    routing::{get, post},
    Form, Router,
};
use serde::Deserialize;
use sqlx::MySqlPool;
use tera::Context;

use crate::error::{AppError, HtmxError};
use crate::handlers::order_list::{filter_orders_for_view, ViewKind};
use crate::middleware::tenant::TenantContext;
use crate::models::order::Order;
use crate::repository::order_repo;
use crate::state::AppState;

/// 列表装载的订单条数上限（与 `order_list` 同口径，避免一次性把整库读入内存）。
const LIST_LIMIT: i64 = 200;

/// 日本仓发货视图模板名（相对 `src/templates/`）。缺失时回退内置 HTML 构造，见 [`render_list`]。
const JP_WAREHOUSE_TEMPLATE: &str = "jp_warehouse.html";

// 路由路径常量（集中管理，便于片段 `hx-*` 与路由保持一致）。
const LIST_PATH: &str = "/jp-warehouse";
const ASSIGN_PATH: &str = "/jp-warehouse/assign";
const BATCH_ASSIGN_PATH: &str = "/jp-warehouse/batch-assign";
const BATCH_OUT_PATH: &str = "/jp-warehouse/batch-out";
const ADVANCE_PATH: &str = "/jp-warehouse/advance";

// ============================================================================
// OutStatus —— 日本仓出库状态机（设计 3.7，纯逻辑）
// ============================================================================

/// 日本仓出库状态。与 `jp_shipments.out_status` 列字符串一一对应（设计 3.7）。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Hash)]
pub enum OutStatus {
    /// 待分配（初始态）：尚未分配发货员。**列表中该状态的行需高亮提示。**
    Unassigned,
    /// 已分配：已分配发货员（assignee），待出库。
    Assigned,
    /// 已出库：已填仓位 / 出库单号 / 出库人 / 出库时间 / 出库成本，待交国际物流。
    ShippedOut,
    /// 已发货（终态）：已交国际物流。
    Shipped,
}

impl OutStatus {
    /// 全部取值（与设计 3.7 状态流转顺序一致）。
    pub const ALL: [OutStatus; 4] = [
        OutStatus::Unassigned,
        OutStatus::Assigned,
        OutStatus::ShippedOut,
        OutStatus::Shipped,
    ];

    /// 数据库 / 展示用中文标签。
    pub fn as_str(&self) -> &'static str {
        match self {
            OutStatus::Unassigned => "待分配",
            OutStatus::Assigned => "已分配",
            OutStatus::ShippedOut => "已出库",
            OutStatus::Shipped => "已发货",
        }
    }

    /// 由字符串解析，未知值返回 `None`。
    pub fn from_str(s: &str) -> Option<OutStatus> {
        OutStatus::ALL.into_iter().find(|st| st.as_str() == s)
    }

    /// 状态机的下一前进态：`待分配→已分配→已出库→已发货`，终态 `已发货` 返回 `None`。
    pub fn next(self) -> Option<OutStatus> {
        match self {
            OutStatus::Unassigned => Some(OutStatus::Assigned),
            OutStatus::Assigned => Some(OutStatus::ShippedOut),
            OutStatus::ShippedOut => Some(OutStatus::Shipped),
            OutStatus::Shipped => None,
        }
    }
}

impl Default for OutStatus {
    fn default() -> Self {
        OutStatus::Unassigned
    }
}

/// 校验出库状态机迁移是否合法（设计 3.7）。
///
/// 仅允许**单步前进**的三条迁移：
/// `待分配→已分配`、`已分配→已出库`、`已出库→已发货`。
/// 其余一切（自环、回退、跨级跳变、从终态再迁移）均为非法，返回 [`AppError::Validation`]
/// （字段级提示可安全回显）。
pub fn validate_transition(from: OutStatus, to: OutStatus) -> Result<(), AppError> {
    if from.next() == Some(to) {
        Ok(())
    } else {
        Err(AppError::Validation(format!(
            "非法的出库状态流转：{} → {}",
            from.as_str(),
            to.as_str()
        )))
    }
}

/// 行高亮判定（设计 7.4 / Requirements 9.6）：**未分配（`待分配`）的行需高亮**。
pub fn is_unassigned(status: OutStatus) -> bool {
    status == OutStatus::Unassigned
}

/// 解析逗号分隔的 `order_item_id` 列表（批量出库表单用，**纯函数**）。
///
/// 容错：忽略空段与首尾空白，跳过无法解析为 `i64` 的段；保持出现顺序并去重（保留首次）。
/// 全部非法 / 为空时返回空 `Vec`（调用方据此判定「无有效条目」）。
pub fn parse_id_list(raw: &str) -> Vec<i64> {
    let mut out: Vec<i64> = Vec::new();
    for seg in raw.split(',') {
        let seg = seg.trim();
        if seg.is_empty() {
            continue;
        }
        if let Ok(id) = seg.parse::<i64>() {
            if !out.contains(&id) {
                out.push(id);
            }
        }
    }
    out
}

// ============================================================================
// 行视图模型 + 纯渲染（无 DB）
// ============================================================================

/// 日本仓发货列表的一行视图模型（一个 `jp_stock` 子商品 + 其出库信息）。
#[derive(Debug, Clone, serde::Serialize)]
pub struct JpShipmentRow {
    pub order_id: i64,
    pub order_item_id: i64,
    pub item_code: String,
    pub product_title: String,
    /// 出库状态字符串（`out_status`）。
    pub out_status: String,
    pub assignee: String,
    pub operator: String,
    pub location: String,
    pub out_no: String,
    pub out_cost: String,
    /// 是否高亮（未分配行）。
    pub highlight: bool,
}

/// 渲染单个出库状态单元格片段（**纯函数**，HTMX 回填用）。
///
/// 外层 `span.out-status` 携带 `data-status`；未分配时追加 `unassigned` 类名以触发高亮
/// （对应原型 `assign-sel.unassigned` 橙色描边，Requirements 9.6）。
pub fn render_status_cell(order_item_id: i64, status: OutStatus) -> String {
    let highlight_class = if is_unassigned(status) {
        " unassigned"
    } else {
        ""
    };
    format!(
        "<span class=\"out-status{cls}\" data-order-item-id=\"{oid}\" data-status=\"{st}\">{label}</span>",
        cls = highlight_class,
        oid = order_item_id,
        st = status.as_str(),
        label = status.as_str(),
    )
}

/// 内置 HTML 列表回退渲染（**纯函数**，当 Tera 模板缺失时使用，保证视图始终可用）。
///
/// 每行一个 `tr.jp-row`，未分配行追加 `unassigned` 类名以高亮。
fn render_rows_fallback(tenant_name: &str, rows: &[JpShipmentRow]) -> String {
    let mut html = String::new();
    html.push_str(&format!(
        "<section class=\"jp-warehouse\" data-tenant=\"{}\"><table><tbody>",
        html_escape(tenant_name)
    ));
    for r in rows {
        let row_cls = if r.highlight { " unassigned" } else { "" };
        html.push_str(&format!(
            "<tr class=\"jp-row{cls}\" data-order-item-id=\"{oid}\">\
<td class=\"item-code\">{code}</td>\
<td class=\"title\">{title}</td>\
<td class=\"assignee\">{assignee}</td>\
<td class=\"status\">{status_cell}</td>\
</tr>",
            cls = row_cls,
            oid = r.order_item_id,
            code = html_escape(&r.item_code),
            title = html_escape(&r.product_title),
            assignee = html_escape(&r.assignee),
            status_cell = render_status_cell(
                r.order_item_id,
                OutStatus::from_str(&r.out_status).unwrap_or_default()
            ),
        ));
    }
    html.push_str("</tbody></table></section>");
    html
}

/// 最小 HTML 转义，避免文本破坏页面结构。
fn html_escape(s: &str) -> String {
    s.replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&#39;")
}

// ============================================================================
// 仓储式更新函数（新 SQL 就近放在本模块；写入前校验状态机）
// ============================================================================

/// 读取某 `jp_stock` 子商品当前出库状态。
///
/// 复用 [`order_repo::load_jp_shipment`]：无出库行 → [`AppError::NotFound`]；
/// `out_status` 为空 / 未知串时回退为 [`OutStatus::Unassigned`]（视为初始态）。
async fn current_status(pool: &MySqlPool, order_item_id: i64) -> Result<OutStatus, AppError> {
    let shipment = order_repo::load_jp_shipment(pool, order_item_id)
        .await?
        .ok_or(AppError::NotFound)?;
    Ok(OutStatus::from_str(&shipment.out_status).unwrap_or_default())
}

/// 确保 `jp_stock` 子商品有一条出库记录。迁移早期数据可能尚未生成 `jp_shipments` 行，
/// 首次分配时按需创建初始状态，避免 UI 看似可操作但直接 NotFound。
async fn ensure_jp_shipment_row(pool: &MySqlPool, order_item_id: i64) -> Result<(), AppError> {
    let exists: Option<(i64,)> =
        sqlx::query_as("SELECT `id` FROM `jp_shipments` WHERE `order_item_id` = ? LIMIT 1")
            .bind(order_item_id)
            .fetch_optional(pool)
            .await?;
    if exists.is_some() {
        return Ok(());
    }

    let source_type: Option<String> =
        sqlx::query_scalar("SELECT `source_type` FROM `order_items` WHERE `id` = ?")
            .bind(order_item_id)
            .fetch_optional(pool)
            .await?;
    match source_type.as_deref() {
        Some("jp_stock") => {
            sqlx::query("INSERT INTO `jp_shipments` (`order_item_id`, `out_status`) VALUES (?, ?)")
                .bind(order_item_id)
                .bind(OutStatus::Unassigned.as_str())
                .execute(pool)
                .await?;
            Ok(())
        }
        Some(_) => Err(AppError::Validation(
            "只有日本仓现货子商品可以创建出库记录".to_string(),
        )),
        None => Err(AppError::NotFound),
    }
}

/// 分配发货员（`待分配 → 已分配`，设计 3.7）。
///
/// 校验当前状态可迁移至 `已分配`（拒绝非法跳变 → [`AppError::Validation`]），随后写入
/// `out_status=已分配` 与 `assignee`。子商品无出库行 → [`AppError::NotFound`]。
pub async fn assign(pool: &MySqlPool, order_item_id: i64, assignee: &str) -> Result<(), AppError> {
    ensure_jp_shipment_row(pool, order_item_id).await?;
    let from = current_status(pool, order_item_id).await?;
    validate_transition(from, OutStatus::Assigned)?;

    sqlx::query(
        "UPDATE `jp_shipments` SET `out_status` = ?, `assignee` = ?, `updated_at` = NOW() \
         WHERE `order_item_id` = ?",
    )
    .bind(OutStatus::Assigned.as_str())
    .bind(assignee)
    .bind(order_item_id)
    .execute(pool)
    .await?;

    Ok(())
}

/// 出库（`已分配 → 已出库`，设计 3.7）。
///
/// 校验状态机后写入 `out_status=已出库` 及仓位 / 出库单号 / 出库人 / 出库成本，并把
/// `out_time` 置为当前时间（出库时间）。子商品无出库行 → [`AppError::NotFound`]。
pub async fn mark_out(
    pool: &MySqlPool,
    order_item_id: i64,
    operator: &str,
    location: &str,
    out_no: &str,
    out_cost: &str,
) -> Result<(), AppError> {
    let from = current_status(pool, order_item_id).await?;
    validate_transition(from, OutStatus::ShippedOut)?;

    sqlx::query(
        "UPDATE `jp_shipments` SET `out_status` = ?, `operator` = ?, `location` = ?, \
             `out_no` = ?, `out_cost` = ?, `out_time` = NOW(), `updated_at` = NOW() \
         WHERE `order_item_id` = ?",
    )
    .bind(OutStatus::ShippedOut.as_str())
    .bind(operator)
    .bind(location)
    .bind(out_no)
    .bind(out_cost)
    .bind(order_item_id)
    .execute(pool)
    .await?;

    Ok(())
}

/// 交国际物流（`已出库 → 已发货`，设计 3.7）。
///
/// 校验状态机后写入 `out_status=已发货`。子商品无出库行 → [`AppError::NotFound`]。
pub async fn mark_shipped(pool: &MySqlPool, order_item_id: i64) -> Result<(), AppError> {
    let from = current_status(pool, order_item_id).await?;
    validate_transition(from, OutStatus::Shipped)?;

    sqlx::query(
        "UPDATE `jp_shipments` SET `out_status` = ?, `updated_at` = NOW() \
         WHERE `order_item_id` = ?",
    )
    .bind(OutStatus::Shipped.as_str())
    .bind(order_item_id)
    .execute(pool)
    .await?;

    Ok(())
}

// ============================================================================
// 数据装载（租户库）—— 复用 order_repo 与 order_list 货源过滤
// ============================================================================

/// 装载日本仓发货视图的全部行：最近订单 → 仅留 `jp_stock` 子商品 → 拼装出库信息。
///
/// 复用 [`order_repo::get`] 装配订单聚合、[`filter_orders_for_view`] +
/// [`ViewKind::JpWarehouse`] 过滤货源（Requirements 9.1、9.2），再逐子商品
/// [`order_repo::load_jp_shipment`] 取出库信息装配为 [`JpShipmentRow`]。
async fn load_jp_rows(pool: &MySqlPool) -> Result<Vec<JpShipmentRow>, AppError> {
    let ids: Vec<(i64,)> = sqlx::query_as("SELECT `id` FROM `orders` ORDER BY `id` DESC LIMIT ?")
        .bind(LIST_LIMIT)
        .fetch_all(pool)
        .await?;

    let mut orders: Vec<Order> = Vec::with_capacity(ids.len());
    for (id,) in ids {
        if let Some(order) = order_repo::get(pool, id).await? {
            orders.push(order);
        }
    }

    let filtered = filter_orders_for_view(orders, ViewKind::JpWarehouse);

    let mut rows = Vec::new();
    for order in &filtered {
        for item in &order.items {
            let shipment = order_repo::load_jp_shipment(pool, item.id).await?;
            let (out_status, assignee, operator, location, out_no, out_cost) = match shipment {
                Some(s) => (
                    s.out_status,
                    s.assignee,
                    s.operator,
                    s.location,
                    s.out_no,
                    s.out_cost,
                ),
                None => (
                    OutStatus::Unassigned.as_str().to_string(),
                    String::new(),
                    String::new(),
                    String::new(),
                    String::new(),
                    String::new(),
                ),
            };
            let status = OutStatus::from_str(&out_status).unwrap_or_default();
            rows.push(JpShipmentRow {
                order_id: order.id,
                order_item_id: item.id,
                item_code: item.item_code.clone(),
                product_title: item.product_title.clone(),
                out_status,
                assignee,
                operator,
                location,
                out_no,
                out_cost,
                highlight: is_unassigned(status),
            });
        }
    }

    Ok(rows)
}

// ============================================================================
// 表单
// ============================================================================

/// 「按发货员分配」表单（`待分配 → 已分配`）。
#[derive(Debug, Deserialize)]
pub struct AssignForm {
    /// 子商品 id。
    pub order_item_id: i64,
    /// 发货员。
    pub assignee: String,
}

/// 「批量分配发货员」表单（多个 `待分配 → 已分配`）。
#[derive(Debug, Deserialize)]
pub struct BatchAssignForm {
    /// 逗号分隔的子商品 id 列表。
    pub order_item_ids: String,
    /// 发货员。
    pub assignee: String,
}

/// 「批量出库」表单（`已分配 → 已出库`）。
///
/// `order_item_ids` 为逗号分隔的子商品 id 列表，由 [`parse_id_list`] 解析。
#[derive(Debug, Deserialize)]
pub struct BatchOutForm {
    /// 逗号分隔的子商品 id 列表。
    pub order_item_ids: String,
    /// 出库人。
    pub operator: String,
    /// 仓位。
    #[serde(default)]
    pub location: String,
    /// 出库单号。
    #[serde(default)]
    pub out_no: String,
    /// 出库成本。
    #[serde(default)]
    pub out_cost: String,
}

/// 「推进出库状态」表单（`已出库 → 已发货`，交国际物流）。
#[derive(Debug, Deserialize)]
pub struct AdvanceForm {
    /// 子商品 id。
    pub order_item_id: i64,
}

// ============================================================================
// axum handlers
// ============================================================================

/// 日本仓发货视图（`GET /jp-warehouse`）：列出全部 `jp_stock` 子商品的出库信息。
async fn list_handler(
    State(state): State<AppState>,
    Extension(ctx): Extension<TenantContext>,
) -> Result<Html<String>, AppError> {
    let rows = load_jp_rows(&ctx.pool).await?;
    Ok(Html(render_list(&state, &ctx.company_name, &rows)))
}

/// 用 Tera 渲染列表；模板缺失（迭代期）则回退内置 HTML（[`render_rows_fallback`]）。
fn render_list(state: &AppState, tenant_name: &str, rows: &[JpShipmentRow]) -> String {
    let mut tctx = Context::new();
    tctx.insert("rows", rows);
    tctx.insert("tenant_name", tenant_name);

    match state.tera().render(JP_WAREHOUSE_TEMPLATE, &tctx) {
        Ok(html) => html,
        Err(e) => {
            tracing::warn!(error = %e, "日本仓发货模板渲染失败，回退内置 HTML");
            render_rows_fallback(tenant_name, rows)
        }
    }
}

/// 分配发货员（`POST /jp-warehouse/assign`）：`待分配 → 已分配`。
async fn assign_handler(
    Extension(ctx): Extension<TenantContext>,
    Form(form): Form<AssignForm>,
) -> Result<Html<String>, HtmxError> {
    let assignee = form.assignee.trim();
    if assignee.is_empty() {
        return Err(AppError::Validation("发货员不能为空".to_string()).into());
    }
    assign(&ctx.pool, form.order_item_id, assignee).await?;
    Ok(Html(render_status_cell(
        form.order_item_id,
        OutStatus::Assigned,
    )))
}

/// 批量分配发货员（`POST /jp-warehouse/batch-assign`）：对一组子商品执行 `待分配 → 已分配`。
async fn batch_assign_handler(
    Extension(ctx): Extension<TenantContext>,
    Form(form): Form<BatchAssignForm>,
) -> Result<Html<String>, HtmxError> {
    let ids = parse_id_list(&form.order_item_ids);
    if ids.is_empty() {
        return Err(AppError::Validation("未选择任何待分配子商品".to_string()).into());
    }

    let assignee = form.assignee.trim();
    if assignee.is_empty() {
        return Err(AppError::Validation("发货员不能为空".to_string()).into());
    }

    let mut succeeded = 0usize;
    let mut failed = 0usize;
    for id in &ids {
        match assign(&ctx.pool, *id, assignee).await {
            Ok(()) => succeeded += 1,
            Err(e) => {
                failed += 1;
                tracing::warn!(error = %e, order_item_id = id, "批量分配单条失败，已跳过");
            }
        }
    }

    Ok(Html(format!(
        "<div class=\"batch-result ok\" data-succeeded=\"{succeeded}\" data-failed=\"{failed}\">\
已分配 {succeeded} 件，跳过 {failed} 件。刷新后可查看最新状态。</div>"
    )))
}

/// 批量出库（`POST /jp-warehouse/batch-out`）：对一组子商品执行 `已分配 → 已出库`。
///
/// 解析逗号分隔的 id 列表；无有效条目 → [`AppError::Validation`]。逐条出库：单条因状态
/// 非法 / 不存在而失败时记日志并跳过（批量场景容错），返回成功条数摘要片段。
async fn batch_out_handler(
    Extension(ctx): Extension<TenantContext>,
    Form(form): Form<BatchOutForm>,
) -> Result<Html<String>, HtmxError> {
    let ids = parse_id_list(&form.order_item_ids);
    if ids.is_empty() {
        return Err(AppError::Validation("未选择任何待出库子商品".to_string()).into());
    }
    let operator = form.operator.trim();
    if operator.is_empty() {
        return Err(AppError::Validation("出库人不能为空".to_string()).into());
    }

    let mut succeeded = 0usize;
    let mut failed = 0usize;
    for id in &ids {
        match mark_out(
            &ctx.pool,
            *id,
            operator,
            form.location.trim(),
            form.out_no.trim(),
            form.out_cost.trim(),
        )
        .await
        {
            Ok(()) => succeeded += 1,
            Err(e) => {
                failed += 1;
                tracing::warn!(error = %e, order_item_id = id, "批量出库单条失败，已跳过");
            }
        }
    }

    Ok(Html(format!(
        "<div class=\"batch-out-result\" data-succeeded=\"{succeeded}\" data-failed=\"{failed}\">\
已出库 {succeeded} 项，跳过 {failed} 项</div>"
    )))
}

/// 推进出库状态（`POST /jp-warehouse/advance`）：`已出库 → 已发货`（交国际物流）。
async fn advance_handler(
    Extension(ctx): Extension<TenantContext>,
    Form(form): Form<AdvanceForm>,
) -> Result<Html<String>, HtmxError> {
    mark_shipped(&ctx.pool, form.order_item_id).await?;
    Ok(Html(render_status_cell(
        form.order_item_id,
        OutStatus::Shipped,
    )))
}

/// 组装日本仓发货工作流路由。
///
/// - `GET  /jp-warehouse`            → 列表视图（全部 `jp_stock` 子商品出库信息）
/// - `POST /jp-warehouse/assign`     → 按发货员分配（待分配→已分配）
/// - `POST /jp-warehouse/batch-assign` → 批量分配发货员（待分配→已分配）
/// - `POST /jp-warehouse/batch-out`  → 批量出库（已分配→已出库）
/// - `POST /jp-warehouse/advance`    → 推进出库（已出库→已发货）
pub fn routes() -> Router<AppState> {
    Router::new()
        .route(LIST_PATH, get(list_handler))
        .route(ASSIGN_PATH, post(assign_handler))
        .route(BATCH_ASSIGN_PATH, post(batch_assign_handler))
        .route(BATCH_OUT_PATH, post(batch_out_handler))
        .route(ADVANCE_PATH, post(advance_handler))
}

// ============================================================================
// 单元测试 —— 纯状态机 / 高亮 / 解析 / 渲染（无 DB）
// ============================================================================

#[cfg(test)]
mod tests {
    use super::*;

    // ---- OutStatus 基础 ------------------------------------------------------

    #[test]
    fn out_status_str_round_trip() {
        for st in OutStatus::ALL {
            assert_eq!(OutStatus::from_str(st.as_str()), Some(st));
        }
        assert_eq!(OutStatus::from_str("待分配"), Some(OutStatus::Unassigned));
        assert_eq!(OutStatus::from_str("已分配"), Some(OutStatus::Assigned));
        assert_eq!(OutStatus::from_str("已出库"), Some(OutStatus::ShippedOut));
        assert_eq!(OutStatus::from_str("已发货"), Some(OutStatus::Shipped));
        assert_eq!(OutStatus::from_str("bogus"), None);
        assert_eq!(OutStatus::from_str(""), None);
    }

    #[test]
    fn out_status_labels_match_design_3_7() {
        let actual: Vec<&str> = OutStatus::ALL.iter().map(|s| s.as_str()).collect();
        assert_eq!(actual, vec!["待分配", "已分配", "已出库", "已发货"]);
    }

    #[test]
    fn next_follows_state_machine() {
        assert_eq!(OutStatus::Unassigned.next(), Some(OutStatus::Assigned));
        assert_eq!(OutStatus::Assigned.next(), Some(OutStatus::ShippedOut));
        assert_eq!(OutStatus::ShippedOut.next(), Some(OutStatus::Shipped));
        assert_eq!(OutStatus::Shipped.next(), None);
    }

    // ---- validate_transition：合法迁移全部放行 -------------------------------

    #[test]
    fn valid_transitions_are_allowed() {
        assert!(validate_transition(OutStatus::Unassigned, OutStatus::Assigned).is_ok());
        assert!(validate_transition(OutStatus::Assigned, OutStatus::ShippedOut).is_ok());
        assert!(validate_transition(OutStatus::ShippedOut, OutStatus::Shipped).is_ok());
    }

    // ---- validate_transition：非法迁移全部拒绝 -------------------------------

    #[test]
    fn self_transitions_are_rejected() {
        for st in OutStatus::ALL {
            assert!(
                matches!(validate_transition(st, st), Err(AppError::Validation(_))),
                "自环 {} → {} 应被拒绝",
                st.as_str(),
                st.as_str()
            );
        }
    }

    #[test]
    fn backward_transitions_are_rejected() {
        assert!(matches!(
            validate_transition(OutStatus::Assigned, OutStatus::Unassigned),
            Err(AppError::Validation(_))
        ));
        assert!(matches!(
            validate_transition(OutStatus::ShippedOut, OutStatus::Assigned),
            Err(AppError::Validation(_))
        ));
        assert!(matches!(
            validate_transition(OutStatus::Shipped, OutStatus::ShippedOut),
            Err(AppError::Validation(_))
        ));
    }

    #[test]
    fn skip_level_transitions_are_rejected() {
        assert!(matches!(
            validate_transition(OutStatus::Unassigned, OutStatus::ShippedOut),
            Err(AppError::Validation(_))
        ));
        assert!(matches!(
            validate_transition(OutStatus::Unassigned, OutStatus::Shipped),
            Err(AppError::Validation(_))
        ));
        assert!(matches!(
            validate_transition(OutStatus::Assigned, OutStatus::Shipped),
            Err(AppError::Validation(_))
        ));
    }

    #[test]
    fn transitions_from_terminal_state_are_rejected() {
        for to in OutStatus::ALL {
            assert!(
                matches!(
                    validate_transition(OutStatus::Shipped, to),
                    Err(AppError::Validation(_))
                ),
                "终态 已发货 → {} 应被拒绝",
                to.as_str()
            );
        }
    }

    #[test]
    fn exactly_three_transitions_are_valid_over_all_pairs() {
        // 枚举全部 4×4=16 组 (from,to)，恰有 3 组合法（与状态机三条前进边一致）。
        let mut valid = 0;
        for from in OutStatus::ALL {
            for to in OutStatus::ALL {
                if validate_transition(from, to).is_ok() {
                    valid += 1;
                }
            }
        }
        assert_eq!(valid, 3, "合法迁移应恰为 3 条");
    }

    // ---- 高亮判定 ------------------------------------------------------------

    #[test]
    fn only_unassigned_rows_are_highlighted() {
        assert!(is_unassigned(OutStatus::Unassigned));
        assert!(!is_unassigned(OutStatus::Assigned));
        assert!(!is_unassigned(OutStatus::ShippedOut));
        assert!(!is_unassigned(OutStatus::Shipped));
    }

    // ---- id 列表解析 ---------------------------------------------------------

    #[test]
    fn parse_id_list_basic() {
        assert_eq!(parse_id_list("1,2,3"), vec![1, 2, 3]);
    }

    #[test]
    fn parse_id_list_trims_and_skips_empty_and_invalid() {
        assert_eq!(parse_id_list(" 1 , ,2,abc, 3 "), vec![1, 2, 3]);
        assert_eq!(parse_id_list(""), Vec::<i64>::new());
        assert_eq!(parse_id_list(" , , "), Vec::<i64>::new());
        assert_eq!(parse_id_list("x,y,z"), Vec::<i64>::new());
    }

    #[test]
    fn parse_id_list_dedups_preserving_first_occurrence() {
        assert_eq!(parse_id_list("5,5,3,5,3"), vec![5, 3]);
    }

    // ---- 状态单元格渲染：未分配高亮 ------------------------------------------

    #[test]
    fn status_cell_highlights_unassigned() {
        let html = render_status_cell(42, OutStatus::Unassigned);
        assert!(html.contains("out-status unassigned"));
        assert!(html.contains("data-status=\"待分配\""));
        assert!(html.contains("data-order-item-id=\"42\""));
        assert!(html.contains(">待分配<"));
    }

    #[test]
    fn status_cell_no_highlight_for_non_unassigned() {
        for st in [
            OutStatus::Assigned,
            OutStatus::ShippedOut,
            OutStatus::Shipped,
        ] {
            let html = render_status_cell(1, st);
            assert!(!html.contains("unassigned"), "{} 不应高亮", st.as_str());
            assert!(html.contains(&format!("data-status=\"{}\"", st.as_str())));
        }
    }

    // ---- 列表回退渲染 --------------------------------------------------------

    fn row(order_item_id: i64, status: OutStatus, assignee: &str) -> JpShipmentRow {
        JpShipmentRow {
            order_id: 1,
            order_item_id,
            item_code: "ITEM-1".to_string(),
            product_title: "商品A".to_string(),
            out_status: status.as_str().to_string(),
            assignee: assignee.to_string(),
            operator: String::new(),
            location: String::new(),
            out_no: String::new(),
            out_cost: String::new(),
            highlight: is_unassigned(status),
        }
    }

    #[test]
    fn fallback_render_marks_unassigned_rows() {
        let rows = vec![
            row(10, OutStatus::Unassigned, ""),
            row(11, OutStatus::Assigned, "山田"),
        ];
        let html = render_rows_fallback("公司A", &rows);
        // 未分配行高亮。
        assert!(html.contains("jp-row unassigned"));
        assert!(html.contains("data-order-item-id=\"10\""));
        // 已分配行不高亮（行类名无 unassigned，但内部状态单元格也不应有）。
        assert!(html.contains("data-order-item-id=\"11\""));
        // 租户名出现且被转义安全。
        assert!(html.contains("data-tenant=\"公司A\""));
    }

    #[test]
    fn fallback_render_escapes_text() {
        let mut r = row(1, OutStatus::Assigned, "<b>x</b>");
        r.product_title = "<script>".to_string();
        let html = render_rows_fallback("t", &[r]);
        assert!(!html.contains("<script>"));
        assert!(html.contains("&lt;script&gt;"));
        assert!(html.contains("&lt;b&gt;x&lt;/b&gt;"));
    }

    #[test]
    fn fallback_render_empty_rows_yields_table_shell() {
        let html = render_rows_fallback("t", &[]);
        assert!(html.contains("<section class=\"jp-warehouse\""));
        assert!(html.contains("</section>"));
        assert!(!html.contains("<tr"));
    }
}
