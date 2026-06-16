//! 订单保存处理器，含货源改判 HTMX 片段（Task 10.5 / Requirements 3.5）。
//!
//! 平台订单视图（design.md 7.1 / 7.2）是履约分流入口：B1 区每个子商品行的「货源地」
//! 列在该视图呈现为**下拉**，客服可把货源在 `cn_purchase / jp_stock / pending` 间改判。
//! 本模块提供该下拉改判的 axum handler：
//! 1. 解析表单（`order_id` / `order_item_id` / `old_source` / `new_source`），
//!    货源字符串经 [`SourceType::from_str`] 校验，非法值 → [`AppError::Validation`]；
//! 2. 从请求扩展取租户连接池（[`TenantContext`]，由租户中间件注入）与操作人
//!    （[`Principal`]，由会话中间件注入；缺失时回退为安全占位串，见 [`operator_label`]）；
//! 3. 调用 [`order_service::rejudge_source`] 改判并写「货源改判」审计日志，
//!    子商品不存在（`Ok(false)`）→ [`AppError::NotFound`]；
//! 4. 返回反映**新货源状态**的 B1 行 HTMX 片段（[`render_b1_source_fragment`]），
//!    供 HTMX 就地替换该单元格。
//!
//! 纯渲染 / 解析逻辑（[`render_b1_source_fragment`] / [`parse_source_field`] /
//! [`operator_label`] / [`source_label`]）与 DB 副作用分离，便于单元测试独立验证。

use axum::{extract::Extension, response::Html, routing::post, Form};
use serde::Deserialize;
use sqlx::types::chrono::NaiveDateTime;
use sqlx::MySqlPool;

use crate::error::{AppError, HtmxError};
use crate::middleware::tenant::TenantContext;
use crate::models::order::{PurchaseStatus, SourceType};
use crate::models::user::Principal;
use crate::services::{order_service, purchase_service};
use crate::state::AppState;

/// 货源改判 HTMX 端点路径（B1 区下拉 `hx-post` 目标）。
const REJUDGE_PATH: &str = "/orders/source/rejudge";

/// B1 区货源下拉改判表单。
///
/// 字段与 [`render_b1_source_fragment`] 渲染的隐藏域 / 下拉 `name` 一一对应：
/// - `order_id` / `order_item_id`：定位被改判的子商品；
/// - `old_source`：改判前货源（写审计日志的 `old_value`）；
/// - `new_source`：下拉选中的新货源（写审计日志的 `new_value`）。
///
/// 货源字段以字符串接收，在 handler 内经 [`parse_source_field`] 校验为 [`SourceType`]，
/// 以便对非法取值返回明确的 [`AppError::Validation`]（而非反序列化层的 400）。
#[derive(Debug, Deserialize)]
pub struct RejudgeForm {
    /// 订单 ID。
    pub order_id: i64,
    /// 子商品 ID（`order_items.id`）。
    pub order_item_id: i64,
    /// 改判前货源字符串（`cn_purchase / jp_stock / pending`）。
    pub old_source: String,
    /// 改判后货源字符串（`cn_purchase / jp_stock / pending`）。
    pub new_source: String,
}

/// 本模块路由：`POST /orders/source/rejudge` → [`rejudge_source_handler`]。
///
/// 以 `Router<AppState>` 形式返回，便于在 `main.rs`（Task 18.1）合并进受租户 + 会话
/// 中间件保护的路由树（handler 依赖中间件注入的 [`TenantContext`] / [`Principal`]）。
pub fn routes() -> axum::Router<AppState> {
    axum::Router::new()
        .route(REJUDGE_PATH, post(rejudge_source_handler))
        .route("/orders/purchase/save", post(save_purchase_handler))
        .route(
            "/orders/purchase/batch-status",
            post(batch_purchase_status_handler),
        )
}

/// 平台订单视图 B1 区货源改判 handler（Requirements 3.5）。
///
/// 成功返回反映新货源的 B1 行片段（HTMX `outerHTML` 就地替换）；失败以 [`HtmxError`]
/// 渲染错误片段（脱敏），契合 HTMX 局部回填语义。
///
/// 操作人来源：会话中间件注入的 [`Principal`]。该扩展缺失（理论上受保护路由不会发生，
/// 但为稳健起见仍处理）时回退为 `system`（见 [`operator_label`]）。
async fn rejudge_source_handler(
    Extension(ctx): Extension<TenantContext>,
    principal: Option<Extension<Principal>>,
    Form(form): Form<RejudgeForm>,
) -> Result<Html<String>, HtmxError> {
    // 1) 校验货源字段：非法字符串 → Validation（可安全回显字段级提示）。
    let old_source = parse_source_field(&form.old_source, "old_source")?;
    let new_source = parse_source_field(&form.new_source, "new_source")?;

    // 2) 解析操作人（缺失主体时回退安全占位）。
    let operator = operator_label(principal.as_ref().map(|Extension(p)| p));

    // 3) 改判落库 + 写审计日志；子商品不存在 → NotFound。
    let updated = order_service::rejudge_source(
        &ctx.pool,
        form.order_id,
        form.order_item_id,
        old_source,
        new_source,
        &operator,
    )
    .await?;
    if !updated {
        return Err(AppError::NotFound.into());
    }

    // 4) 返回反映新货源状态的 B1 行片段。
    Ok(Html(render_b1_source_fragment(
        form.order_id,
        form.order_item_id,
        new_source,
    )))
}

/// 解析货源字符串字段为 [`SourceType`]，非法值返回字段级 [`AppError::Validation`]。
fn parse_source_field(raw: &str, field: &str) -> Result<SourceType, AppError> {
    SourceType::from_str(raw.trim())
        .ok_or_else(|| AppError::Validation(format!("{field} 取值非法：{raw}")))
}

#[derive(Debug, Deserialize)]
pub struct SavePurchaseForm {
    pub order_id: i64,
    pub order_item_id: i64,
    pub purchase_status: String,
    #[serde(default)]
    pub tabaono: String,
    #[serde(default)]
    pub caigou_link: String,
    #[serde(default)]
    pub buhuo_link: String,
    #[serde(default)]
    pub caigou_ordernums: String,
    #[serde(default)]
    pub cn_amount: String,
    #[serde(default)]
    pub com_amount: String,
    #[serde(default)]
    pub cn_ship_number: String,
    #[serde(default)]
    pub caigou_time: String,
}

#[derive(Debug, Deserialize)]
pub struct BatchPurchaseStatusForm {
    pub order_item_ids: String,
    pub purchase_status: String,
}

async fn save_purchase_handler(
    Extension(ctx): Extension<TenantContext>,
    principal: Option<Extension<Principal>>,
    Form(form): Form<SavePurchaseForm>,
) -> Result<Html<String>, HtmxError> {
    let status = parse_purchase_status_field(&form.purchase_status)?;
    let caigou_time = parse_optional_datetime(&form.caigou_time)?;
    let operator = operator_label(principal.as_ref().map(|Extension(p)| p));

    update_purchase_status(
        &ctx.pool,
        form.order_id,
        form.order_item_id,
        status,
        &operator,
    )
    .await?;

    let input = purchase_service::SavePurchaseInput {
        order_item_id: form.order_item_id,
        tabaono: form.tabaono.trim().to_string(),
        current_user: operator,
        new_status: status,
        caigou_link: form.caigou_link.trim().to_string(),
        buhuo_link: form.buhuo_link.trim().to_string(),
        caigou_ordernums: form.caigou_ordernums.trim().to_string(),
        cn_amount: normalize_decimal_text(&form.cn_amount),
        com_amount: normalize_decimal_text(&form.com_amount),
        cn_ship_number: form.cn_ship_number.trim().to_string(),
        caigou_time,
    };
    let outcome = purchase_service::on_save_purchase(&ctx.pool, &input).await?;

    Ok(Html(render_purchase_save_result(
        form.order_item_id,
        status,
        outcome,
    )))
}

async fn batch_purchase_status_handler(
    Extension(ctx): Extension<TenantContext>,
    principal: Option<Extension<Principal>>,
    Form(form): Form<BatchPurchaseStatusForm>,
) -> Result<Html<String>, HtmxError> {
    let ids = parse_id_list(&form.order_item_ids);
    if ids.is_empty() {
        return Err(AppError::Validation("请选择需要改状态的子商品".to_string()).into());
    }

    let status = parse_purchase_status_field(&form.purchase_status)?;
    let operator = operator_label(principal.as_ref().map(|Extension(p)| p));
    let mut succeeded = 0usize;
    let mut failed = 0usize;

    for item_id in ids {
        match load_order_id_for_item(&ctx.pool, item_id).await {
            Ok(Some(order_id)) => {
                match update_purchase_status(&ctx.pool, order_id, item_id, status, &operator).await
                {
                    Ok(()) => succeeded += 1,
                    Err(e) => {
                        failed += 1;
                        tracing::warn!(error = %e, order_item_id = item_id, "批量采购状态更新失败，已跳过");
                    }
                }
            }
            Ok(None) => failed += 1,
            Err(e) => {
                failed += 1;
                tracing::warn!(error = %e, order_item_id = item_id, "批量采购状态读取失败，已跳过");
            }
        }
    }

    Ok(Html(format!(
        "<div class=\"batch-result ok\">已更新 {succeeded} 件，跳过 {failed} 件。刷新后可查看最新状态。</div>"
    )))
}

fn parse_purchase_status_field(raw: &str) -> Result<PurchaseStatus, AppError> {
    PurchaseStatus::from_str(raw.trim())
        .ok_or_else(|| AppError::Validation(format!("采购状态取值非法：{raw}")))
}

fn parse_optional_datetime(raw: &str) -> Result<Option<NaiveDateTime>, AppError> {
    let raw = raw.trim();
    if raw.is_empty() {
        return Ok(None);
    }

    NaiveDateTime::parse_from_str(raw, "%Y-%m-%d %H:%M:%S")
        .or_else(|_| NaiveDateTime::parse_from_str(raw, "%Y-%m-%dT%H:%M"))
        .map(Some)
        .map_err(|_| AppError::Validation("采购时间格式应为 YYYY-MM-DD HH:MM:SS".to_string()))
}

fn normalize_decimal_text(raw: &str) -> String {
    let raw = raw.trim();
    if raw.is_empty() {
        "0".to_string()
    } else {
        raw.to_string()
    }
}

fn parse_id_list(raw: &str) -> Vec<i64> {
    let mut ids = Vec::new();
    for seg in raw.split(',') {
        if let Ok(id) = seg.trim().parse::<i64>() {
            if !ids.contains(&id) {
                ids.push(id);
            }
        }
    }
    ids
}

async fn load_order_id_for_item(
    pool: &MySqlPool,
    order_item_id: i64,
) -> Result<Option<i64>, AppError> {
    let order_id =
        sqlx::query_scalar::<_, i64>("SELECT `order_id` FROM `order_items` WHERE `id` = ?")
            .bind(order_item_id)
            .fetch_optional(pool)
            .await?;
    Ok(order_id)
}

async fn update_purchase_status(
    pool: &MySqlPool,
    order_id: i64,
    order_item_id: i64,
    new_status: PurchaseStatus,
    operator: &str,
) -> Result<(), AppError> {
    let old_status: Option<String> = sqlx::query_scalar(
        "SELECT `purchase_status` FROM `order_items` WHERE `id` = ? AND `order_id` = ?",
    )
    .bind(order_item_id)
    .bind(order_id)
    .fetch_optional(pool)
    .await?;

    let Some(old_status) = old_status else {
        return Err(AppError::NotFound);
    };

    sqlx::query("UPDATE `order_items` SET `purchase_status` = ? WHERE `id` = ? AND `order_id` = ?")
        .bind(new_status.as_str())
        .bind(order_item_id)
        .bind(order_id)
        .execute(pool)
        .await?;

    if old_status != new_status.as_str() {
        sqlx::query(
            "INSERT INTO `order_logs` \
             (`order_id`, `order_item_id`, `operator`, `action_type`, `field_name`, \
              `old_value`, `new_value`, `ip`, `created_at`) \
             VALUES (?, ?, ?, '采购保存', 'purchase_status', ?, ?, '', NOW())",
        )
        .bind(order_id)
        .bind(order_item_id)
        .bind(operator)
        .bind(old_status)
        .bind(new_status.as_str())
        .execute(pool)
        .await?;
    }

    Ok(())
}

fn render_purchase_save_result(
    order_item_id: i64,
    status: PurchaseStatus,
    outcome: purchase_service::SavePurchaseOutcome,
) -> String {
    let caigou_user_note = if outcome.caigou_user_written {
        "，已记录采购人"
    } else {
        ""
    };
    let record_note = if outcome.caigou_record_upserted {
        "，采购记录已同步"
    } else {
        ""
    };
    format!(
        "<span class=\"save-ok\" data-order-item-id=\"{order_item_id}\" data-status=\"{status}\">已保存{caigou_user_note}{record_note}</span>",
        status = status.as_str(),
    )
}

/// 货源地中文标签（用于下拉选项展示）。
fn source_label(source: SourceType) -> &'static str {
    match source {
        SourceType::CnPurchase => "国内采购",
        SourceType::JpStock => "日本仓现货",
        SourceType::Pending => "待定",
    }
}

/// 由请求主体解析审计日志的「操作人」串。
///
/// 设计取舍：审计日志期望记录可读的操作人。[`Principal::Employee`] 当前仅携带
/// `user_id`（不含用户名，会话中间件未加载显示名），故以 `用户#<id>` 形式记录，
/// 便于回溯到具体员工；`SuperAdmin` / `CompanyAdmin` 记录其角色名。主体扩展缺失
/// （受保护路由理论上不会发生）时回退 `system`，确保审计链不中断。
fn operator_label(principal: Option<&Principal>) -> String {
    match principal {
        Some(Principal::SuperAdmin) => "超级管理员".to_string(),
        Some(Principal::CompanyAdmin { .. }) => "公司管理员".to_string(),
        Some(Principal::Employee { user_id, .. }) => format!("用户#{user_id}"),
        None => "system".to_string(),
    }
}

/// 渲染 B1 区货源单元格片段（反映 `current` 货源状态）。
///
/// **纯函数**：产出一个最小且合法的 HTML 片段——外层 `div.b1-source` 携带
/// `data-source` 便于断言 / 调试，内部含定位用隐藏域与货源下拉。下拉的 `hx-*` 属性
/// 使「change」即触发改判 `POST`，并以 `outerHTML` 就地替换本单元格，形成闭环：
/// 改判成功后服务端再次返回本片段（`current` 即新货源），下拉选中项随之更新。
///
/// `old_source` 隐藏域取 `current`（当前已落库的货源），即下次改判时的「改判前」值。
fn render_b1_source_fragment(order_id: i64, order_item_id: i64, current: SourceType) -> String {
    let options: String = SourceType::ALL
        .iter()
        .map(|s| {
            let selected = if *s == current { " selected" } else { "" };
            format!(
                "<option value=\"{value}\"{selected}>{label}</option>",
                value = s.as_str(),
                selected = selected,
                label = source_label(*s),
            )
        })
        .collect();

    format!(
        "<div class=\"b1-source\" data-order-item-id=\"{oid}\" data-source=\"{src}\">\
<input type=\"hidden\" name=\"order_id\" value=\"{order_id}\">\
<input type=\"hidden\" name=\"order_item_id\" value=\"{oid}\">\
<input type=\"hidden\" name=\"old_source\" value=\"{src}\">\
<select name=\"new_source\" class=\"source-select\" hx-post=\"{path}\" hx-trigger=\"change\" \
hx-target=\"closest .b1-source\" hx-swap=\"outerHTML\" hx-include=\"closest .b1-source\">\
{options}</select>\
</div>",
        oid = order_item_id,
        src = current.as_str(),
        order_id = order_id,
        path = REJUDGE_PATH,
        options = options,
    )
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::models::user::{Role, StoreScope, TenantId};
    use std::collections::HashMap;

    // ---- 表单货源字段解析 ----------------------------------------------------

    #[test]
    fn parse_source_field_accepts_all_valid_values() {
        assert_eq!(
            parse_source_field("cn_purchase", "new_source").unwrap(),
            SourceType::CnPurchase
        );
        assert_eq!(
            parse_source_field("jp_stock", "new_source").unwrap(),
            SourceType::JpStock
        );
        assert_eq!(
            parse_source_field("pending", "new_source").unwrap(),
            SourceType::Pending
        );
    }

    #[test]
    fn parse_source_field_trims_surrounding_whitespace() {
        assert_eq!(
            parse_source_field("  jp_stock  ", "old_source").unwrap(),
            SourceType::JpStock
        );
    }

    #[test]
    fn parse_source_field_rejects_invalid_value_with_validation() {
        let err = parse_source_field("bogus", "new_source").unwrap_err();
        match err {
            AppError::Validation(msg) => {
                assert!(msg.contains("new_source"), "提示应含字段名: {msg}");
                assert!(msg.contains("bogus"), "提示应含非法取值: {msg}");
            }
            other => panic!("应为 Validation，实际 {other:?}"),
        }
    }

    #[test]
    fn parse_source_field_rejects_empty_value() {
        assert!(matches!(
            parse_source_field("", "old_source"),
            Err(AppError::Validation(_))
        ));
    }

    // ---- 操作人解析 ----------------------------------------------------------

    #[test]
    fn operator_label_for_each_principal_kind() {
        assert_eq!(operator_label(Some(&Principal::SuperAdmin)), "超级管理员");
        assert_eq!(
            operator_label(Some(&Principal::CompanyAdmin {
                tenant_id: TenantId(7)
            })),
            "公司管理员"
        );
        let emp = Principal::Employee {
            tenant_id: TenantId(1),
            user_id: 42,
            role: Role::Buyer,
            overrides: HashMap::new(),
            store_scope: StoreScope::All,
        };
        assert_eq!(operator_label(Some(&emp)), "用户#42");
    }

    #[test]
    fn operator_label_falls_back_to_system_when_absent() {
        assert_eq!(operator_label(None), "system");
    }

    // ---- B1 货源片段渲染 -----------------------------------------------------

    #[test]
    fn fragment_marks_current_source_selected() {
        let html = render_b1_source_fragment(100, 200, SourceType::JpStock);
        // 当前货源对应的 option 选中。
        assert!(html.contains("<option value=\"jp_stock\" selected>日本仓现货</option>"));
        // 其它 option 不选中。
        assert!(html.contains("<option value=\"cn_purchase\">国内采购</option>"));
        assert!(html.contains("<option value=\"pending\">待定</option>"));
    }

    #[test]
    fn fragment_includes_all_three_source_options() {
        let html = render_b1_source_fragment(1, 2, SourceType::Pending);
        for s in SourceType::ALL {
            assert!(
                html.contains(&format!("value=\"{}\"", s.as_str())),
                "片段应含货源选项 {}",
                s.as_str()
            );
        }
    }

    #[test]
    fn fragment_carries_locator_hidden_fields_and_data_attrs() {
        let html = render_b1_source_fragment(100, 200, SourceType::CnPurchase);
        assert!(html.contains("data-order-item-id=\"200\""));
        assert!(html.contains("data-source=\"cn_purchase\""));
        assert!(html.contains("name=\"order_id\" value=\"100\""));
        assert!(html.contains("name=\"order_item_id\" value=\"200\""));
        // old_source 隐藏域反映当前（已落库）货源，供下次改判作为改判前值。
        assert!(html.contains("name=\"old_source\" value=\"cn_purchase\""));
    }

    #[test]
    fn fragment_wires_htmx_post_to_rejudge_endpoint() {
        let html = render_b1_source_fragment(1, 2, SourceType::Pending);
        assert!(html.contains(&format!("hx-post=\"{REJUDGE_PATH}\"")));
        assert!(html.contains("hx-trigger=\"change\""));
        assert!(html.contains("hx-swap=\"outerHTML\""));
    }

    #[test]
    fn fragment_is_balanced_minimal_markup() {
        let html = render_b1_source_fragment(1, 2, SourceType::JpStock);
        // 最小且结构闭合：单一 div 容器 + 单一 select。
        assert!(html.starts_with("<div class=\"b1-source\""));
        assert!(html.ends_with("</div>"));
        assert_eq!(html.matches("<select").count(), 1);
        assert_eq!(html.matches("</select>").count(), 1);
        assert_eq!(html.matches("<div").count(), 1);
        assert_eq!(html.matches("</div>").count(), 1);
        // 三个货源选项。
        assert_eq!(html.matches("<option").count(), 3);
    }
}
