//! 订单导入落库服务。
//!
//! 手动 CSV 导入和平台 API 同步都先映射为 [`CsvImportRecord`]，再由本服务统一装配
//! `orders -> order_items -> purchases/domestic_shipments/intl_shipments -> order_logs`。
//! 这样可以保证多商品订单只创建一个订单聚合根，子商品逐行追加。

use std::collections::HashMap;

use chrono::{NaiveDate, NaiveDateTime};
use serde::Serialize;
use sqlx::MySqlPool;

use crate::error::AppError;
use crate::models::order::{
    DomesticShipment, IntlShipment, Order, OrderItem, Purchase, PurchaseStatus, SourceType,
};
use crate::repository::order_repo;
use crate::repository::store_repo::StoreSummary;
use crate::services::import::{CsvImportRecord, ImportPreview, ImportPreviewSeverity, MappedOrder};
use crate::services::order_service::split_ship_numbers;

#[derive(Debug, Clone, Serialize)]
pub struct ImportReport {
    pub preview: ImportPreview,
    pub attempted_rows: usize,
    pub imported_rows: usize,
    pub skipped_rows: usize,
    pub failed_rows: usize,
    pub imported_order_ids: Vec<i64>,
    pub issues: Vec<ImportIssue>,
    pub dry_run: bool,
}

#[derive(Debug, Clone, Serialize)]
pub struct ImportIssue {
    pub row: usize,
    pub kind: String,
    pub message: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ImportOperator {
    Manual,
    RakutenSync,
}

impl ImportOperator {
    fn label(self) -> &'static str {
        match self {
            ImportOperator::Manual => "手动导入",
            ImportOperator::RakutenSync => "乐天 RMS 同步",
        }
    }
}

pub fn dry_run_report(preview: ImportPreview, attempted_rows: usize) -> ImportReport {
    ImportReport {
        preview,
        attempted_rows,
        imported_rows: 0,
        skipped_rows: 0,
        failed_rows: 0,
        imported_order_ids: Vec::new(),
        issues: Vec::new(),
        dry_run: true,
    }
}

pub async fn import_records(
    pool: &MySqlPool,
    store: &StoreSummary,
    preview: ImportPreview,
    records: Vec<CsvImportRecord>,
    operator: ImportOperator,
) -> Result<ImportReport, AppError> {
    let mut report = ImportReport {
        attempted_rows: records.len(),
        imported_rows: 0,
        skipped_rows: 0,
        failed_rows: 0,
        imported_order_ids: Vec::new(),
        issues: Vec::new(),
        preview,
        dry_run: false,
    };

    if report
        .preview
        .errors
        .iter()
        .any(|issue| matches!(issue.severity, ImportPreviewSeverity::Error))
    {
        report.failed_rows = report.attempted_rows;
        report.issues.push(ImportIssue {
            row: 1,
            kind: "failed".to_string(),
            message: "导入预检存在错误，未执行落库。请先根据问题列表修正必填字段。".to_string(),
        });
        return Ok(report);
    }

    if records.is_empty() {
        report.issues.push(ImportIssue {
            row: 1,
            kind: "skipped".to_string(),
            message: "没有可导入的数据行。".to_string(),
        });
        return Ok(report);
    }

    for group in group_records(records) {
        match import_group(pool, store, &group, operator).await {
            Ok(ImportGroupOutcome::Imported {
                order_id,
                row_count,
            }) => {
                report.imported_rows += row_count;
                report.imported_order_ids.push(order_id);
            }
            Ok(ImportGroupOutcome::Skipped { row_count, message }) => {
                report.skipped_rows += row_count;
                report.issues.push(ImportIssue {
                    row: group.first_row(),
                    kind: "skipped".to_string(),
                    message,
                });
            }
            Err(err) => {
                report.failed_rows += group.records.len();
                report.issues.push(ImportIssue {
                    row: group.first_row(),
                    kind: "failed".to_string(),
                    message: err.client_message(),
                });
            }
        }
    }

    Ok(report)
}

#[derive(Debug, Clone)]
struct RecordGroup {
    order_id: String,
    records: Vec<CsvImportRecord>,
}

impl RecordGroup {
    fn first_row(&self) -> usize {
        self.records
            .first()
            .map(|record| record.csv_row)
            .unwrap_or(1)
    }
}

enum ImportGroupOutcome {
    Imported { order_id: i64, row_count: usize },
    Skipped { row_count: usize, message: String },
}

fn group_records(records: Vec<CsvImportRecord>) -> Vec<RecordGroup> {
    let mut groups = Vec::<RecordGroup>::new();
    let mut index = HashMap::<String, usize>::new();

    for record in records {
        let key = record.order_id.clone();
        if let Some(pos) = index.get(&key).copied() {
            groups[pos].records.push(record);
        } else {
            index.insert(key.clone(), groups.len());
            groups.push(RecordGroup {
                order_id: key,
                records: vec![record],
            });
        }
    }

    groups
}

async fn import_group(
    pool: &MySqlPool,
    store: &StoreSummary,
    group: &RecordGroup,
    operator: ImportOperator,
) -> Result<ImportGroupOutcome, AppError> {
    let platform = store.platform.as_str();
    if order_repo::find_order_id_by_identity(pool, platform, Some(store.id), &group.order_id, None)
        .await?
        .is_some()
    {
        return Ok(ImportGroupOutcome::Skipped {
            row_count: group.records.len(),
            message: format!("订单 {} 已存在，已跳过。", group.order_id),
        });
    }

    let first = group
        .records
        .first()
        .ok_or_else(|| AppError::Validation("导入记录为空，无法创建订单。".to_string()))?;
    let mapped = &first.mapped;
    let order = Order {
        platform: platform.to_string(),
        platform_order_id: group.order_id.clone(),
        order_detail_id: None,
        store_id: Some(store.id),
        order_date: parse_datetime(&mapped.order_date),
        order_status: default_text(&mapped.order_status, "导入"),
        customer_name: mapped.customer_name.clone(),
        customer_kana: mapped.customer_kana.clone(),
        customer_zip: mapped.customer_zip.clone(),
        customer_address: mapped.customer_address.clone(),
        customer_phone: mapped.customer_phone.clone(),
        customer_mail: mapped.customer_mail.clone(),
        pay_method: mapped.pay_method.clone(),
        ship_method: mapped.ship_method.clone(),
        total_item_price: decimal_text(&mapped.total_item_price),
        postage_price: decimal_text(&mapped.postage_price),
        total_price: decimal_text(&mapped.total_price),
        imported_at: parse_datetime(&mapped.imported_at)
            .or_else(|| parse_datetime(&mapped.order_date))
            .unwrap_or_else(fallback_imported_at),
        platform_extra: mapped.order_platform_extra.clone(),
        ..Default::default()
    };

    let order_id = order_repo::insert_order(pool, &order).await?;
    let mut imported_items = 0usize;

    for record in &group.records {
        let item_id = insert_item(pool, store, order_id, record).await?;
        order_repo::insert_order_log(
            pool,
            order_id,
            Some(item_id),
            operator.label(),
            operator.label(),
            "import_row",
            None,
            Some(&format!("第 {} 行", record.csv_row)),
        )
        .await?;
        imported_items += 1;
    }

    Ok(ImportGroupOutcome::Imported {
        order_id,
        row_count: imported_items,
    })
}

async fn insert_item(
    pool: &MySqlPool,
    store: &StoreSummary,
    order_id: i64,
    record: &CsvImportRecord,
) -> Result<i64, AppError> {
    let mapped = &record.mapped;
    let item = OrderItem {
        id: 0,
        order_id,
        source_type: infer_source_type(mapped),
        purchase_status: PurchaseStatus::from_str(mapped.beizhu.trim())
            .unwrap_or(PurchaseStatus::Pending),
        item_code: mapped.item_code.clone(),
        jp_warehouse_id: non_empty(get_raw(&record.raw, "jp_warehouse_id")),
        product_title: mapped.product_title.clone(),
        item_option: mapped.item_option.clone(),
        chinese_option: mapped.chinese_option.clone(),
        quantity: parse_i32(&mapped.quantity),
        weight: decimal_text_scale(&mapped.weight, 3),
        material: get_raw(&record.raw, "material"),
        amount: decimal_text(&mapped.amount),
        caigou_user: non_empty(get_raw(&record.raw, "caigou_user")),
        main_image: get_raw(&record.raw, "zhutu"),
        sku_image: get_raw(&record.raw, "skuimg"),
        platform_extra: item_extra_with_identity(record, store.platform.as_str()),
    };
    let item_id = order_repo::insert_order_item(pool, &item).await?;

    if has_purchase(mapped, &record.raw) {
        let purchase = Purchase {
            order_item_id: item_id,
            tabaono: mapped.tabaono.clone(),
            caigou_link: mapped.caigou_link.clone(),
            buhuo_link: mapped.buhuo_link.clone(),
            caigou_user: item.caigou_user.clone().unwrap_or_default(),
            caigou_time: parse_datetime(&mapped.caigou_time),
            caigou_ordernums: get_raw(&record.raw, "caigou_ordernums"),
            cn_amount: decimal_text(&mapped.cn_amount),
            com_amount: decimal_text(&mapped.com_amount),
            cn_ship_number: mapped.cn_ship_number.clone(),
            ..Default::default()
        };
        order_repo::insert_purchase(pool, item_id, &purchase).await?;
    }

    let ship_company = get_raw(&record.raw, "shipcompany");
    let logistic_trace = non_empty(get_raw(&record.raw, "logisticstrace"));
    let jpship_completed_at = parse_datetime(&mapped.jpship_completed_at);
    for ship_number in split_ship_numbers(&mapped.ship_number) {
        let shipment = DomesticShipment {
            order_item_id: item_id,
            ship_number,
            ship_company: ship_company.clone(),
            ship_quantity: parse_i32(&mapped.ship_quantity),
            jpship_status: mapped.jpship_status.clone(),
            jpship_completed_at,
            logistic_trace: logistic_trace.clone(),
            ..Default::default()
        };
        order_repo::insert_domestic_shipment(pool, item_id, &shipment).await?;
    }

    if let Some(intl) = build_intl_shipment(&record.raw, item_id) {
        order_repo::insert_intl_shipment(pool, item_id, &intl).await?;
    }

    Ok(item_id)
}

fn item_extra_with_identity(record: &CsvImportRecord, platform: &str) -> Option<serde_json::Value> {
    let mut extra = record
        .mapped
        .item_platform_extra
        .clone()
        .and_then(|value| value.as_object().cloned())
        .unwrap_or_default();

    if let Some(identity) = import_detail_identity(platform, record) {
        extra.insert(
            "import_detail_identity".to_string(),
            serde_json::Value::String(identity),
        );
    }

    if extra.is_empty() {
        None
    } else {
        Some(serde_json::Value::Object(extra))
    }
}

pub fn import_detail_identity(platform: &str, record: &CsvImportRecord) -> Option<String> {
    if let Some(line_id) = record.line_id.clone().and_then(non_empty) {
        return Some(line_id);
    }

    let item_code = record.mapped.item_code.trim();
    if item_code.is_empty() {
        return None;
    }

    if platform == "r" {
        let option = record.mapped.item_option.trim();
        if !option.is_empty() {
            return Some(format!("{item_code}|{option}"));
        }
    }

    Some(item_code.to_string())
}

fn build_intl_shipment(
    raw: &std::collections::HashMap<String, String>,
    item_id: i64,
) -> Option<IntlShipment> {
    let intl_number = get_raw(raw, "intl_number");
    let intl_status = get_raw(raw, "intl_status");
    let intl_fee = decimal_text(&get_raw(raw, "intl_fee"));
    let intl_qty = parse_i32(&get_raw(raw, "intl_qty"));
    let intl_weight = decimal_text_scale(&get_raw(raw, "intl_weight"), 3);
    let tranship_comment = get_raw(raw, "tranship_comment");
    let comment = get_raw(raw, "comment");

    if [&intl_number, &intl_status, &tranship_comment, &comment]
        .iter()
        .all(|value| value.trim().is_empty())
        && intl_qty == 0
        && intl_fee == "0.00"
        && intl_weight == "0.000"
    {
        return None;
    }

    Some(IntlShipment {
        order_item_id: item_id,
        intl_number,
        intl_status,
        intl_fee,
        intl_qty,
        intl_weight,
        tranship_comment,
        comment,
        ..Default::default()
    })
}

fn has_purchase(mapped: &MappedOrder, raw: &std::collections::HashMap<String, String>) -> bool {
    [
        &mapped.tabaono,
        &mapped.caigou_link,
        &mapped.buhuo_link,
        &mapped.cn_amount,
        &mapped.com_amount,
        &mapped.caigou_time,
        &mapped.cn_ship_number,
        &get_raw(raw, "caigou_ordernums"),
    ]
    .iter()
    .any(|value| !value.trim().is_empty())
}

fn infer_source_type(mapped: &MappedOrder) -> SourceType {
    if mapped.beizhu.contains("日本库存") || mapped.beizhu.contains("精品") {
        SourceType::JpStock
    } else if !mapped.tabaono.trim().is_empty()
        || !mapped.caigou_link.trim().is_empty()
        || !mapped.buhuo_link.trim().is_empty()
        || mapped.beizhu.contains("国内采购")
    {
        SourceType::CnPurchase
    } else {
        SourceType::Pending
    }
}

fn parse_datetime(value: &str) -> Option<NaiveDateTime> {
    let value = value.trim();
    if value.is_empty() {
        return None;
    }
    if let Ok(dt) = NaiveDateTime::parse_from_str(value, "%Y-%m-%d %H:%M:%S") {
        return Some(dt);
    }
    if let Ok(dt) = NaiveDateTime::parse_from_str(value, "%Y/%m/%d %H:%M:%S") {
        return Some(dt);
    }
    if let Ok(dt) = NaiveDateTime::parse_from_str(value, "%Y-%m-%d %H:%M") {
        return Some(dt);
    }
    if let Ok(dt) = NaiveDateTime::parse_from_str(value, "%Y/%m/%d %H:%M") {
        return Some(dt);
    }
    if let Ok(date) = NaiveDate::parse_from_str(value, "%Y-%m-%d") {
        return date.and_hms_opt(0, 0, 0);
    }
    if let Ok(date) = NaiveDate::parse_from_str(value, "%Y/%m/%d") {
        return date.and_hms_opt(0, 0, 0);
    }
    None
}

fn fallback_imported_at() -> NaiveDateTime {
    NaiveDate::from_ymd_opt(1970, 1, 1)
        .and_then(|date| date.and_hms_opt(0, 0, 0))
        .expect("固定 fallback 时间应有效")
}

fn decimal_text(value: &str) -> String {
    decimal_text_scale(value, 2)
}

fn decimal_text_scale(value: &str, scale: usize) -> String {
    let value = value.trim().replace(',', "");
    if value.is_empty() {
        return format_zero_decimal(scale);
    }
    let Ok(parsed) = value.parse::<f64>() else {
        return format_zero_decimal(scale);
    };
    if !parsed.is_finite() {
        return format_zero_decimal(scale);
    }
    format!("{:.*}", scale, parsed)
}

fn format_zero_decimal(scale: usize) -> String {
    format!("{:.*}", scale, 0.0)
}

fn parse_i32(value: &str) -> i32 {
    value.trim().parse::<i32>().unwrap_or(0)
}

fn default_text(value: &str, fallback: &str) -> String {
    let value = value.trim();
    if value.is_empty() {
        fallback.to_string()
    } else {
        value.to_string()
    }
}

fn get_raw(raw: &std::collections::HashMap<String, String>, key: &str) -> String {
    raw.get(key)
        .map(|value| value.trim().to_string())
        .unwrap_or_default()
}

fn non_empty(value: String) -> Option<String> {
    let value = value.trim();
    if value.is_empty() {
        None
    } else {
        Some(value.to_string())
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::services::import::{CsvImportRecord, MappedOrder};

    #[test]
    fn groups_records_by_order_id_preserving_order() {
        let records = vec![record("A", 2), record("B", 3), record("A", 4)];
        let groups = group_records(records);
        assert_eq!(groups.len(), 2);
        assert_eq!(groups[0].order_id, "A");
        assert_eq!(groups[0].records.len(), 2);
        assert_eq!(groups[1].order_id, "B");
    }

    #[test]
    fn rakuten_import_identity_uses_line_id_then_item_option() {
        let mut record = record("R-1", 2);
        record.line_id = Some("1".to_string());
        record.mapped.item_code = "SKU".to_string();
        record.mapped.item_option = "色: 赤".to_string();
        assert_eq!(import_detail_identity("r", &record), Some("1".to_string()));

        record.line_id = None;
        assert_eq!(
            import_detail_identity("r", &record),
            Some("SKU|色: 赤".to_string())
        );
    }

    #[test]
    fn decimal_text_formats_invalid_values_to_zero() {
        assert_eq!(decimal_text("12.3"), "12.30");
        assert_eq!(decimal_text_scale("1.2345", 3), "1.234");
        assert_eq!(decimal_text("bad"), "0.00");
    }

    fn record(order_id: &str, csv_row: usize) -> CsvImportRecord {
        CsvImportRecord {
            csv_row,
            order_id: order_id.to_string(),
            line_id: None,
            raw: HashMap::new(),
            mapped: MappedOrder {
                item_code: "SKU".to_string(),
                ..Default::default()
            },
        }
    }
}
