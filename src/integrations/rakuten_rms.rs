//! 乐天 RMS Order API 适配边界。
//!
//! old `plugins/rakuten-rms-api` 使用 RMS WEB SERVICE 的两步流程：
//! `searchOrder` 查订单号列表，再按 5 单一批 `getOrder(version=8)` 拉详单；
//! 鉴权头为 `Authorization: ESA base64(serviceSecret:licenseKey)`。
//!
//! 本模块不直接绑定 HTTP 客户端，运行时通过 [`RakutenRmsProvider`] 注入真实实现，
//! 便于本地离线测试和后续服务器按实际 API 凭证接线。

use async_trait::async_trait;
use chrono::NaiveDateTime;
use serde::{Deserialize, Serialize};
use serde_json::{json, Value as JsonValue};

use crate::error::AppError;
use crate::integrations::oauth_yahoo::base64_encode;
use crate::models::platform::Platform;
use crate::services::import::{self, CsvImportBatch, CsvImportRecord, RawRecord};

pub const SEARCH_ORDER_ENDPOINT: &str = "https://api.rms.rakuten.co.jp/es/2.0/order/searchOrder/";
pub const GET_ORDER_ENDPOINT: &str = "https://api.rms.rakuten.co.jp/es/2.0/order/getOrder/";
pub const DEFAULT_PAGE_SIZE: usize = 1000;
pub const DEFAULT_BATCH_SIZE: usize = 5;

const RAKUTEN_SYNC_HEADERS: &[&str] = &[
    "OrderId",
    "LineId",
    "ItemId",
    "Quantity",
    "SubCodeOption",
    "selectedChoice",
    "delvdateInfo",
    "OrderTime",
    "OrderStatus",
    "ShipName",
    "ShipAddress1",
    "ShipAddress2",
    "ShipCity",
    "ShipPrefecture",
    "ShipZipCode",
    "ShipPhoneNumber",
    "ShipRequestDate",
    "ShipRequestTime",
    "ShipNotes",
    "BillMailAddress",
    "PayMethodName",
    "PayStatus",
    "PayDate",
    "QuantityDetail",
    "ShipCharge",
    "PayCharge",
    "UnitPrice",
    "TotalPrice",
    "requestPrice",
    "cdate",
    "beizhu",
    "senderKana",
    "ItemManagerId",
    "basketId",
];

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RakutenRmsCredentials {
    pub service_secret: String,
    pub license_key: String,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RakutenSearchOrderRequest {
    pub start_datetime: String,
    pub end_datetime: String,
    pub request_records_amount: usize,
    pub request_page: usize,
}

impl RakutenSearchOrderRequest {
    pub fn payload(&self) -> JsonValue {
        json!({
            "dateType": 3,
            "orderProgressList": [300],
            "startDatetime": self.start_datetime,
            "endDatetime": self.end_datetime,
            "PaginationRequestModel": {
                "requestRecordsAmount": self.request_records_amount,
                "requestPage": self.request_page,
                "SortModelList": [
                    { "sortColumn": 1, "sortDirection": 1 }
                ]
            }
        })
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RakutenGetOrderRequest {
    pub order_numbers: Vec<String>,
    pub version: i32,
}

impl RakutenGetOrderRequest {
    pub fn new(order_numbers: Vec<String>) -> Self {
        Self {
            order_numbers,
            version: 8,
        }
    }

    pub fn payload(&self) -> JsonValue {
        json!({
            "orderNumberList": self.order_numbers,
            "version": self.version,
        })
    }
}

#[derive(Debug, Clone, Default, PartialEq, Eq, Serialize, Deserialize)]
pub struct RakutenSearchOrderResponse {
    pub order_number_list: Vec<String>,
    pub total_records_amount: usize,
}

impl RakutenSearchOrderResponse {
    pub fn from_json(value: &JsonValue) -> Result<Self, AppError> {
        let order_number_list = value
            .get("orderNumberList")
            .and_then(JsonValue::as_array)
            .map(|items| {
                items
                    .iter()
                    .filter_map(json_text_opt)
                    .collect::<Vec<String>>()
            })
            .unwrap_or_default();

        let total_records_amount = value
            .get("PaginationResponseModel")
            .and_then(|v| v.get("totalRecordsAmount"))
            .and_then(json_usize)
            .unwrap_or(order_number_list.len());

        Ok(Self {
            order_number_list,
            total_records_amount,
        })
    }
}

#[derive(Debug, Clone, Default, PartialEq)]
pub struct RakutenGetOrderResponse {
    pub orders: Vec<RakutenOrderModel>,
}

impl RakutenGetOrderResponse {
    pub fn from_json(value: &JsonValue) -> Result<Self, AppError> {
        let orders = value
            .get("OrderModelList")
            .and_then(JsonValue::as_array)
            .map(|items| {
                items
                    .iter()
                    .cloned()
                    .map(|raw| RakutenOrderModel { raw })
                    .collect::<Vec<_>>()
            })
            .unwrap_or_default();
        Ok(Self { orders })
    }
}

#[derive(Debug, Clone, PartialEq)]
pub struct RakutenOrderModel {
    pub raw: JsonValue,
}

#[async_trait]
pub trait RakutenRmsProvider: Send + Sync {
    async fn search_orders(
        &self,
        credentials: &RakutenRmsCredentials,
        request: &RakutenSearchOrderRequest,
    ) -> Result<RakutenSearchOrderResponse, AppError>;

    async fn get_orders(
        &self,
        credentials: &RakutenRmsCredentials,
        request: &RakutenGetOrderRequest,
    ) -> Result<RakutenGetOrderResponse, AppError>;
}

#[derive(Debug, Clone, Copy, Default)]
pub struct UnwiredRakutenRmsProvider;

#[async_trait]
impl RakutenRmsProvider for UnwiredRakutenRmsProvider {
    async fn search_orders(
        &self,
        _credentials: &RakutenRmsCredentials,
        _request: &RakutenSearchOrderRequest,
    ) -> Result<RakutenSearchOrderResponse, AppError> {
        Err(AppError::ExternalApi {
            provider: "rakuten_rms".to_string(),
            detail: "rakuten RMS HTTP provider is not wired".to_string(),
        })
    }

    async fn get_orders(
        &self,
        _credentials: &RakutenRmsCredentials,
        _request: &RakutenGetOrderRequest,
    ) -> Result<RakutenGetOrderResponse, AppError> {
        Err(AppError::ExternalApi {
            provider: "rakuten_rms".to_string(),
            detail: "rakuten RMS HTTP provider is not wired".to_string(),
        })
    }
}

pub fn esa_authorization_header(credentials: &RakutenRmsCredentials) -> (String, String) {
    let raw = format!("{}:{}", credentials.service_secret, credentials.license_key);
    (
        "Authorization".to_string(),
        format!("ESA {}", base64_encode(raw.as_bytes())),
    )
}

pub fn import_batch_from_order_models(models: &[RakutenOrderModel]) -> CsvImportBatch {
    let mut records = Vec::new();
    let mut csv_row = 2usize;

    for order in models {
        for record in records_from_order_model(order, &mut csv_row) {
            records.push(record);
        }
    }

    let headers = RAKUTEN_SYNC_HEADERS
        .iter()
        .map(|header| (*header).to_string())
        .collect::<Vec<_>>();
    let raw_records = records
        .iter()
        .map(|record| (record.csv_row, record.raw.clone()))
        .collect::<Vec<_>>();
    let preview = import::preview_records(Platform::Rakuten, &headers, &raw_records);

    CsvImportBatch { preview, records }
}

fn records_from_order_model(
    order: &RakutenOrderModel,
    next_row: &mut usize,
) -> Vec<CsvImportRecord> {
    let root = &order.raw;
    let packages = array_child(root, "PackageModelList");
    let Some(package) = packages.first().copied() else {
        return Vec::new();
    };
    let items = array_child(package, "ItemModelList");
    let order_number = text_child(root, "orderNumber");
    if order_number.is_empty() {
        return Vec::new();
    }

    let sender = package.get("SenderModel").unwrap_or(&JsonValue::Null);
    let delivery = root.get("DeliveryModel").unwrap_or(&JsonValue::Null);
    let settlement = root.get("SettlementModel").unwrap_or(&JsonValue::Null);
    let orderer = root.get("OrdererModel").unwrap_or(&JsonValue::Null);

    let ship_name = join_non_empty(
        &[
            text_child(sender, "familyName"),
            text_child(sender, "firstName"),
        ],
        " ",
    );
    let sender_kana = join_non_empty(
        &[
            text_child(sender, "familyNameKana"),
            text_child(sender, "firstNameKana"),
        ],
        " ",
    );
    let zip = join_non_empty(
        &[
            text_child(sender, "zipCode1"),
            text_child(sender, "zipCode2"),
        ],
        "-",
    );
    let phone = join_non_empty(
        &[
            text_child(sender, "phoneNumber1"),
            text_child(sender, "phoneNumber2"),
            text_child(sender, "phoneNumber3"),
        ],
        "-",
    );
    let quantity_detail = quantity_detail(&items);
    let ship_inst = text_child(root, "shippingInstDatetime");
    let order_time = normalize_datetime(&text_child(root, "orderDatetime"));
    let ship_request_date = date_part(&ship_inst);
    let ship_request_time = time_part(&ship_inst);
    let request_price = root
        .get("TaxSummaryModelList")
        .and_then(JsonValue::as_array)
        .and_then(|items| items.first())
        .map(|v| text_child(v, "reqPrice"))
        .unwrap_or_default();
    let basket_id = text_child(package, "basketId");

    let mut out = Vec::new();
    for (index, item) in items.iter().enumerate() {
        let line_id = (index + 1).to_string();
        let mut raw = blank_raw_record();
        insert(&mut raw, "OrderId", &order_number);
        insert(&mut raw, "LineId", &line_id);
        insert(&mut raw, "ItemId", &text_child(item, "manageNumber"));
        insert(&mut raw, "Quantity", &text_child(item, "units"));
        insert(&mut raw, "SubCodeOption", &sub_code_option(item));
        insert(
            &mut raw,
            "selectedChoice",
            &text_child(item, "selectedChoice").replace("\\n", "\n"),
        );
        insert(&mut raw, "delvdateInfo", &text_child(item, "delvdateInfo"));
        insert(&mut raw, "OrderTime", &order_time);
        insert(&mut raw, "OrderStatus", &text_child(item, "orderProgress"));
        insert(&mut raw, "ShipName", &ship_name);
        insert(&mut raw, "ShipAddress1", &text_child(sender, "subAddress"));
        insert(
            &mut raw,
            "ShipAddress2",
            &text_child(delivery, "deliveryName"),
        );
        insert(&mut raw, "ShipCity", &text_child(sender, "city"));
        insert(
            &mut raw,
            "ShipPrefecture",
            &text_child(sender, "prefecture"),
        );
        insert(&mut raw, "ShipZipCode", &zip);
        insert(&mut raw, "ShipPhoneNumber", &phone);
        insert(&mut raw, "ShipRequestDate", &ship_request_date);
        insert(&mut raw, "ShipRequestTime", &ship_request_time);
        insert(&mut raw, "ShipNotes", &text_child(package, "noshi"));
        insert(
            &mut raw,
            "BillMailAddress",
            &text_child(orderer, "emailAddress"),
        );
        insert(
            &mut raw,
            "PayMethodName",
            &text_child(settlement, "settlementMethod"),
        );
        insert(&mut raw, "QuantityDetail", &quantity_detail);
        insert(&mut raw, "ShipCharge", &text_child(root, "postagePrice"));
        insert(&mut raw, "PayCharge", &text_child(root, "deliveryPrice"));
        insert(&mut raw, "UnitPrice", &text_child(item, "price"));
        insert(&mut raw, "TotalPrice", &text_child(root, "totalPrice"));
        insert(&mut raw, "requestPrice", &request_price);
        insert(&mut raw, "cdate", &order_time);
        insert(&mut raw, "beizhu", "待处理");
        insert(&mut raw, "senderKana", &sender_kana);
        insert(&mut raw, "ItemManagerId", &text_child(item, "itemNumber"));
        insert(&mut raw, "basketId", &basket_id);

        out.push(CsvImportRecord {
            csv_row: *next_row,
            order_id: order_number.clone(),
            line_id: Some(line_id),
            mapped: import::map_record(Platform::Rakuten, &raw),
            raw,
        });
        *next_row += 1;
    }

    out
}

fn blank_raw_record() -> RawRecord {
    RAKUTEN_SYNC_HEADERS
        .iter()
        .map(|header| ((*header).to_string(), String::new()))
        .collect()
}

fn insert(raw: &mut RawRecord, key: &str, value: &str) {
    raw.insert(key.to_string(), value.trim().to_string());
}

fn array_child<'a>(value: &'a JsonValue, key: &str) -> Vec<&'a JsonValue> {
    value
        .get(key)
        .and_then(JsonValue::as_array)
        .map(|items| items.iter().collect())
        .unwrap_or_default()
}

fn text_child(value: &JsonValue, key: &str) -> String {
    value.get(key).map(json_text).unwrap_or_default()
}

fn json_text_opt(value: &JsonValue) -> Option<String> {
    let text = json_text(value);
    if text.is_empty() {
        None
    } else {
        Some(text)
    }
}

fn json_text(value: &JsonValue) -> String {
    match value {
        JsonValue::String(s) => s.trim().to_string(),
        JsonValue::Number(n) => n.to_string(),
        JsonValue::Bool(v) => v.to_string(),
        _ => String::new(),
    }
}

fn json_usize(value: &JsonValue) -> Option<usize> {
    value
        .as_u64()
        .and_then(|v| usize::try_from(v).ok())
        .or_else(|| value.as_str()?.trim().parse::<usize>().ok())
}

fn join_non_empty(values: &[String], sep: &str) -> String {
    values
        .iter()
        .map(|value| value.trim())
        .filter(|value| !value.is_empty())
        .collect::<Vec<_>>()
        .join(sep)
}

fn quantity_detail(items: &[&JsonValue]) -> String {
    items
        .iter()
        .enumerate()
        .filter_map(|(index, item)| {
            let units = text_child(item, "units");
            if units.is_empty() {
                None
            } else {
                Some(format!("L{}={units}", index + 1))
            }
        })
        .collect::<Vec<_>>()
        .join("&")
}

fn sub_code_option(item: &JsonValue) -> String {
    array_child(item, "SkuModelList")
        .into_iter()
        .map(|sku| text_child(sku, "skuInfo"))
        .filter(|value| !value.trim().is_empty())
        .collect::<Vec<_>>()
        .join("; ")
}

fn normalize_datetime(value: &str) -> String {
    let value = value.trim();
    if value.is_empty() {
        return String::new();
    }
    if let Ok(ts) = value.parse::<i64>() {
        if let Some(dt) = NaiveDateTime::from_timestamp_opt(ts, 0) {
            return dt.format("%Y-%m-%d %H:%M:%S").to_string();
        }
    }
    if value.len() >= 19 && value.as_bytes().get(10) == Some(&b'T') {
        return value[..19].replace('T', " ");
    }
    value.to_string()
}

fn date_part(value: &str) -> String {
    let normalized = normalize_datetime(value);
    normalized.get(0..10).unwrap_or("").to_string()
}

fn time_part(value: &str) -> String {
    let normalized = normalize_datetime(value);
    normalized.get(11..19).unwrap_or("").to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn esa_header_matches_old_php_contract() {
        let credentials = RakutenRmsCredentials {
            service_secret: "Secret".to_string(),
            license_key: "Key".to_string(),
        };
        let (name, value) = esa_authorization_header(&credentials);
        assert_eq!(name, "Authorization");
        assert_eq!(value, "ESA U2VjcmV0OktleQ==");
    }

    #[test]
    fn search_payload_matches_old_php_defaults() {
        let request = RakutenSearchOrderRequest {
            start_datetime: "2026-06-01T00:00:00+0900".to_string(),
            end_datetime: "2026-06-08T00:00:00+0900".to_string(),
            request_records_amount: DEFAULT_PAGE_SIZE,
            request_page: 1,
        };
        let payload = request.payload();
        assert_eq!(payload["dateType"], 3);
        assert_eq!(payload["orderProgressList"][0], 300);
        assert_eq!(
            payload["PaginationRequestModel"]["requestRecordsAmount"],
            DEFAULT_PAGE_SIZE
        );
        assert_eq!(
            payload["PaginationRequestModel"]["SortModelList"][0]["sortColumn"],
            1
        );
    }

    #[test]
    fn get_order_payload_uses_version_8() {
        let request = RakutenGetOrderRequest::new(vec!["R-1".to_string()]);
        let payload = request.payload();
        assert_eq!(payload["version"], 8);
        assert_eq!(payload["orderNumberList"][0], "R-1");
    }

    #[test]
    fn maps_get_order_json_to_import_records() {
        let raw = json!({
            "orderNumber": "399439-20260601-0001",
            "orderDatetime": "2026-06-01T12:34:56+0900",
            "shippingInstDatetime": "2026-06-03T09:10:11+0900",
            "postagePrice": 500,
            "deliveryPrice": 200,
            "totalPrice": 3200,
            "OrdererModel": { "emailAddress": "buyer@example.jp" },
            "SettlementModel": { "settlementMethod": "クレジットカード" },
            "DeliveryModel": { "deliveryName": "宅配便" },
            "TaxSummaryModelList": [{ "reqPrice": 3200 }],
            "PackageModelList": [{
                "basketId": 9,
                "noshi": "memo",
                "SenderModel": {
                    "familyName": "山田",
                    "firstName": "太郎",
                    "familyNameKana": "ヤマダ",
                    "firstNameKana": "タロウ",
                    "city": "大阪市",
                    "prefecture": "大阪府",
                    "subAddress": "中央区1-1",
                    "zipCode1": "540",
                    "zipCode2": "0001",
                    "phoneNumber1": "06",
                    "phoneNumber2": "1111",
                    "phoneNumber3": "2222"
                },
                "ItemModelList": [{
                    "manageNumber": "SKU-1",
                    "itemNumber": "ITEM-MGR-1",
                    "units": 2,
                    "price": 1600,
                    "orderProgress": 300,
                    "selectedChoice": "赤",
                    "delvdateInfo": "指定なし",
                    "SkuModelList": [{ "skuInfo": "色: 赤" }, { "skuInfo": "サイズ: M" }]
                }]
            }]
        });

        let batch = import_batch_from_order_models(&[RakutenOrderModel { raw }]);
        assert_eq!(batch.records.len(), 1);
        let record = &batch.records[0];
        assert_eq!(record.order_id, "399439-20260601-0001");
        assert_eq!(record.line_id.as_deref(), Some("1"));
        assert_eq!(record.mapped.item_code, "SKU-1");
        assert_eq!(record.mapped.customer_name, "山田 太郎");
        assert_eq!(record.mapped.customer_zip, "540-0001");
        assert_eq!(record.mapped.item_option, "色: 赤; サイズ: M");
        assert_eq!(record.mapped.order_date, "2026-06-01 12:34:56");
        assert_eq!(batch.preview.importable_rows, 1);
    }
}
