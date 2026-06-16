//! 导入层映射器（import mapper）。
//!
//! 把各平台**原始宽表记录**（键=原始列名，值=字符串）翻译成一组**规范字段**
//! （可直接填充 [`Order`](crate::models::order::Order) /
//! [`OrderItem`](crate::models::order::OrderItem)），并把平台/家族独有列收进
//! `platform_extra` JSON（键名保留原始列名）。
//!
//! 对应设计：3.3.1 平台字段映射与命名家族、3.4 平台差异容纳策略、8.3 商品编码归一。
//! 迁移工具（17.2）复用本套映射器，保证导入与迁移字段口径一致。
//!
//! # 关键规则（见 design 3.3.1「映射规则」）
//! 1. **共有语义 → 规范列**：两套命名家族（Ship 系 `y/r`、sender 系 `w/m/q/yp`）
//!    表达同一语义的列翻译进同一组规范列；系统自有的 16 个后半段列 1:1 直映。
//! 2. **独有语义 → `platform_extra` JSON**：仅个别平台/家族才有的列收进 `platform_extra`，
//!    键名保留原始列名，不新增稀疏列、不使用平台前缀列。
//! 3. **平台区分只靠 `platform`**：唯一的平台区分维度是 [`Platform`]，
//!    绝不依赖 `Ship*`/`sender*` 列名前缀来判平台。

use std::collections::HashMap;

use serde::Serialize;
use serde_json::{Map as JsonMap, Value as JsonValue};

use crate::models::platform::Platform;

/// 原始记录：键=平台原始列名，值=单元格文本。
///
/// 用字符串承载是因为旧宽表导出 / CSV / 平台 API 文本协议均以字符串形态到达；
/// 类型转换（金额→decimal、时间解析等）由调用方在落库阶段完成。
pub type RawRecord = HashMap<String, String>;

/// 手动导入预检结果。
///
/// 该结构只描述上传内容是否“看起来可以导入”，不访问数据库、不判断重复订单，
/// 供 handler 在真正落库前展示表头映射、样例值和基础错误。
#[derive(Debug, Clone, Default, PartialEq, Serialize)]
pub struct ImportPreview {
    /// CSV 数据行数，不包含表头和全空行。
    pub total_rows: usize,
    /// 基础字段检查通过的行数。
    pub importable_rows: usize,
    /// 表头、列数、必填字段等轻量错误/警告。
    pub errors: Vec<ImportPreviewError>,
    /// 已识别字段的映射预览，按 CSV 表头顺序输出。
    pub field_mapping_preview: Vec<FieldMappingPreview>,
    /// 建议作为订单级数据使用的规范字段。
    pub suggested_order_fields: Vec<String>,
    /// 建议作为商品行级数据使用的规范字段。
    pub suggested_item_fields: Vec<String>,
}

/// 预检发现的问题。
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct ImportPreviewError {
    /// 1-based CSV 行号；表头为第 1 行。表头级错误为 `None`。
    pub row: Option<usize>,
    /// 相关规范字段或原始表头。
    pub field: Option<String>,
    /// 严重程度。
    pub severity: ImportPreviewSeverity,
    /// 可直接展示给用户的简短说明。
    pub message: String,
}

/// 预检问题严重程度。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
pub enum ImportPreviewSeverity {
    Error,
    Warning,
}

/// 单个 CSV 表头到规范字段的识别结果。
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct FieldMappingPreview {
    /// 原始 CSV 表头。
    pub source: String,
    /// 规范字段名，尽量与 [`MappedOrder`] 字段或导入关键字段保持一致。
    pub target: String,
    /// 首个非空样例值。
    pub sample: String,
    /// 订单级 / 商品级 / 额外字段。
    pub scope: ImportFieldScope,
}

/// 字段所属层级。
#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize)]
pub enum ImportFieldScope {
    Order,
    Item,
    Extra,
}

/// 一次 CSV 导入解析的结果：预检报告 + 可导入记录。
///
/// 记录只包含基础校验通过的非空数据行。真正落库前仍应由 handler/repository 做权限、
/// 店铺范围、重复订单等业务判断。
#[derive(Debug, Clone, Default, PartialEq)]
pub struct CsvImportBatch {
    pub preview: ImportPreview,
    pub records: Vec<CsvImportRecord>,
}

/// 单行 CSV 数据在导入层的中间结果。
#[derive(Debug, Clone, PartialEq)]
pub struct CsvImportRecord {
    /// CSV 1-based 行号，表头为第 1 行。
    pub csv_row: usize,
    /// 订单号，来自 `orderId` / `OrderId` / 平台别名。
    pub order_id: String,
    /// 明细行号，来自 `orderDetailId` 等别名；可能为空。
    pub line_id: Option<String>,
    /// 原始行，键名保持上传表头。
    pub raw: RawRecord,
    /// 映射到统一模型的字段。
    pub mapped: MappedOrder,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
struct ImportFieldAlias {
    source: &'static str,
    target: &'static str,
    scope: ImportFieldScope,
}

/// 解析 CSV 文本并生成手动导入预检。
///
/// 解析器支持常见 RFC4180 写法：逗号分隔、双引号包裹、`""` 转义、CRLF/LF 换行。
/// 不做字符集转换；上传层如需处理 Shift-JIS，应先转换为 UTF-8 再调用本函数。
pub fn preview_csv_import(platform: Platform, csv_text: &str) -> ImportPreview {
    match parse_csv(csv_text) {
        Ok(rows) => preview_csv_rows(platform, &rows),
        Err(message) => ImportPreview {
            errors: vec![ImportPreviewError {
                row: None,
                field: None,
                severity: ImportPreviewSeverity::Error,
                message,
            }],
            ..Default::default()
        },
    }
}

/// 解析 CSV 文本，返回预检报告和可导入记录。
pub fn parse_csv_import(platform: Platform, csv_text: &str) -> CsvImportBatch {
    let rows = match parse_csv(csv_text) {
        Ok(rows) => rows,
        Err(message) => {
            return CsvImportBatch {
                preview: ImportPreview {
                    errors: vec![ImportPreviewError {
                        row: None,
                        field: None,
                        severity: ImportPreviewSeverity::Error,
                        message,
                    }],
                    ..Default::default()
                },
                records: Vec::new(),
            };
        }
    };

    if rows.is_empty() {
        return CsvImportBatch {
            preview: preview_csv_rows(platform, &rows),
            records: Vec::new(),
        };
    }

    let upload_headers = trim_row(&rows[0]);
    let legacy_rakuten_fixed = is_legacy_rakuten_fixed_csv(platform, &upload_headers);
    let headers = if legacy_rakuten_fixed {
        legacy_rakuten_fixed_headers()
    } else {
        upload_headers
    };
    let raw_records = if legacy_rakuten_fixed {
        records_from_legacy_rakuten_rows(&rows[1..])
    } else {
        records_from_csv_rows(&headers, &rows[1..])
    };
    let preview = preview_records(platform, &headers, &raw_records);
    if preview.errors.iter().any(|err| {
        err.severity == ImportPreviewSeverity::Error && err.row.is_none_or(|row| row == 1)
    }) {
        return CsvImportBatch {
            preview,
            records: Vec::new(),
        };
    }

    let aliases = import_field_aliases(platform);
    let records = raw_records
        .into_iter()
        .filter_map(|(csv_row, raw)| {
            if is_blank_record(&raw) {
                return None;
            }
            let order_id = value_by_target(&raw, aliases, "order_id");
            if order_id.trim().is_empty() {
                return None;
            }
            let line_id = non_empty(value_by_target(&raw, aliases, "line_id"));
            Some(CsvImportRecord {
                csv_row,
                order_id,
                line_id,
                mapped: map_record(platform, &raw),
                raw,
            })
        })
        .collect();

    CsvImportBatch { preview, records }
}

/// 基于已拆分的 CSV 行生成预检结果。
pub fn preview_csv_rows(platform: Platform, rows: &[Vec<String>]) -> ImportPreview {
    if rows.is_empty() {
        return ImportPreview {
            errors: vec![ImportPreviewError {
                row: None,
                field: None,
                severity: ImportPreviewSeverity::Error,
                message: "CSV 内容为空".to_string(),
            }],
            ..Default::default()
        };
    }

    let upload_headers = trim_row(&rows[0]);
    if upload_headers.iter().all(|h| h.is_empty()) {
        return ImportPreview {
            errors: vec![ImportPreviewError {
                row: Some(1),
                field: None,
                severity: ImportPreviewSeverity::Error,
                message: "CSV 表头为空".to_string(),
            }],
            ..Default::default()
        };
    }

    let legacy_rakuten_fixed = is_legacy_rakuten_fixed_csv(platform, &upload_headers);
    let headers = if legacy_rakuten_fixed {
        legacy_rakuten_fixed_headers()
    } else {
        upload_headers
    };
    let records = if legacy_rakuten_fixed {
        records_from_legacy_rakuten_rows(&rows[1..])
    } else {
        records_from_csv_rows(&headers, &rows[1..])
    };
    preview_records(platform, &headers, &records)
}

/// 基于表头和原始记录生成预检结果，适合 handler 已自行解析上传内容时复用。
pub fn preview_records(
    platform: Platform,
    headers: &[String],
    records: &[(usize, RawRecord)],
) -> ImportPreview {
    let aliases = import_field_aliases(platform);
    let mut errors = Vec::new();
    let mut recognized = Vec::new();

    for (index, header) in headers.iter().enumerate() {
        if header.is_empty() {
            continue;
        }
        if let Some(alias) = find_alias(aliases, header) {
            recognized.push((index, alias));
        }
    }

    if find_target(&recognized, "order_id").is_none() {
        errors.push(ImportPreviewError {
            row: Some(1),
            field: Some("order_id".to_string()),
            severity: ImportPreviewSeverity::Error,
            message: "缺少订单号字段：orderId / OrderId / 注文番号".to_string(),
        });
    }

    if recognized.is_empty() {
        errors.push(ImportPreviewError {
            row: Some(1),
            field: None,
            severity: ImportPreviewSeverity::Error,
            message: "未识别到可导入字段".to_string(),
        });
    }

    if find_item_identity(&recognized).is_none() {
        errors.push(ImportPreviewError {
            row: Some(1),
            field: Some("item_code".to_string()),
            severity: ImportPreviewSeverity::Warning,
            message: format!(
                "未识别到商品字段：{} / orderDetailId / itemCode / lotnumber",
                platform.item_code_field()
            ),
        });
    }

    let mut total_rows = 0;
    let mut importable_rows = 0;
    for (csv_row, record) in records {
        if is_blank_record(record) {
            continue;
        }
        total_rows += 1;

        if record.len() != headers.iter().filter(|h| !h.is_empty()).count() {
            errors.push(ImportPreviewError {
                row: Some(*csv_row),
                field: None,
                severity: ImportPreviewSeverity::Warning,
                message: "该行列数与表头不一致，已按现有列尽量预览".to_string(),
            });
        }

        let order_id = value_by_target(record, aliases, "order_id");
        if order_id.trim().is_empty() {
            errors.push(ImportPreviewError {
                row: Some(*csv_row),
                field: Some("order_id".to_string()),
                severity: ImportPreviewSeverity::Error,
                message: "订单号为空，不能导入该行".to_string(),
            });
            continue;
        }

        importable_rows += 1;
    }

    let field_mapping_preview = recognized
        .iter()
        .filter_map(|(index, alias)| {
            let source = headers.get(*index)?.clone();
            Some(FieldMappingPreview {
                sample: first_sample(records, &source),
                source,
                target: alias.target.to_string(),
                scope: alias.scope,
            })
        })
        .collect::<Vec<_>>();

    ImportPreview {
        total_rows,
        importable_rows,
        errors,
        suggested_order_fields: unique_targets(&recognized, ImportFieldScope::Order),
        suggested_item_fields: unique_targets(&recognized, ImportFieldScope::Item),
        field_mapping_preview,
    }
}

/// 命名家族。差异仅源于旧系统各平台 API 字段命名习惯，**不作为平台区分依据**。
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum NamingFamily {
    /// Ship 系：Yahoo购物 `y`、乐天 `r`（`Ship*`/`Pay*`/`Order*` 驼峰大写）。
    Ship,
    /// sender 系：Wowma `w`、Qoo10 `q`、Mercari `m`、雅虎拍卖 `yp`（`sender*`/`*Name`）。
    Sender,
}

impl NamingFamily {
    /// 由平台推导命名家族（仅 `y/r` 属 Ship 系）。
    pub fn of(platform: Platform) -> NamingFamily {
        match platform {
            Platform::Yahoo | Platform::Rakuten => NamingFamily::Ship,
            Platform::Wowma | Platform::Mercari | Platform::Qoo10 | Platform::YahooAuction => {
                NamingFamily::Sender
            }
        }
    }
}

/// 一条记录映射后的规范结果。字段命名与领域模型
/// [`Order`](crate::models::order::Order) / [`OrderItem`](crate::models::order::OrderItem) 对齐，
/// 调用方据此装配聚合根、子商品与子表。
///
/// 所有取值为原始文本（已 `trim`）。空字符串表示该列缺失/为空。
#[derive(Debug, Clone, Default, PartialEq)]
pub struct MappedOrder {
    /// 平台代码 `y/r/w/m/q/yp`（唯一平台区分维度）。
    pub platform: String,

    // —— A 区：客户 / 收件信息（整单共享，规范列） ——
    pub customer_name: String,
    pub customer_kana: String,
    pub customer_zip: String,
    pub customer_address: String,
    pub customer_phone: String,
    pub customer_mail: String,
    pub pay_method: String,
    pub ship_method: String,
    pub order_status: String,
    /// 下单时间原始文本（Ship 系 `OrderTime` / sender 系 `orderDate`），由调用方解析。
    pub order_date: String,

    // —— 金额（规范列；原始文本，落库转 decimal） ——
    pub total_item_price: String,
    pub postage_price: String,
    pub total_price: String,

    // —— B1 区：子商品（规范列） ——
    /// 归一后的商品编码（按 [`Platform::item_code_field`] 选列）。
    pub item_code: String,
    pub product_title: String,
    pub item_option: String,
    pub chinese_option: String,
    pub quantity: String,
    pub weight: String,
    pub amount: String,

    // —— 系统自有 16 列 1:1 直映（采购 / 物流 / 数量 / 状态等） ——
    /// `shipnumber` → `domestic_shipments.ship_number`。
    pub ship_number: String,
    /// `shipno` → `purchases.cn_ship_number`（国内运单号）。
    pub cn_ship_number: String,
    /// `jpshipdetails` → `domestic_shipments.jpship_status`。
    pub jpship_status: String,
    /// `jpship_completed_at` → `domestic_shipments.jpship_completed_at`。
    pub jpship_completed_at: String,
    /// `shipquantity` → 发货数量（`domestic_shipments`）。
    pub ship_quantity: String,
    /// `cnamount` → `purchases.cn_amount`。
    pub cn_amount: String,
    /// `comamount` → `purchases.com_amount`。
    pub com_amount: String,
    /// `beizhu` → `order_items.purchase_status`（采购状态机原始文本，见 3.6）。
    pub beizhu: String,
    /// `caigoutime` → `purchases.caigou_time`。
    pub caigou_time: String,
    /// `caigoulink` → `purchases.caigou_link`。
    pub caigou_link: String,
    /// `buhuolink` → `purchases.buhuo_link`。
    pub buhuo_link: String,
    /// `tabaono` → `purchases.tabaono`（1688 订单号）。
    pub tabaono: String,
    /// `cdate` → `orders.imported_at`。
    pub imported_at: String,

    // —— 独有列：platform_extra JSON（键名保留原始列名） ——
    /// 订单级独有列（如 `PayStatus`/`PayDate`/`EntryPoint`/`ShipCharge` 等）。
    pub order_platform_extra: Option<JsonValue>,
    /// 子商品级独有列（如 `itemOptionCommission1..5`/`ItemManagerId`/`selectedChoice` 等）。
    pub item_platform_extra: Option<JsonValue>,
}

/// 把一条平台原始记录映射为规范结构。
///
/// 平台区分**仅靠** `platform` 参数（不看列名前缀）。命名家族只决定「同义列」从哪个
/// 原始列名取值，最终都落到同一组规范列。
///
/// 见 design 3.3.1。
pub fn map_record(platform: Platform, raw: &RawRecord) -> MappedOrder {
    let family = NamingFamily::of(platform);
    let mut out = MappedOrder {
        platform: platform.code().to_string(),
        ..Default::default()
    };

    // ---- A 区：客户/收件信息（两家族 → 同一组规范列） ----
    match family {
        NamingFamily::Ship => {
            out.customer_name = get(raw, "ShipName");
            out.customer_zip = get(raw, "ShipZipCode");
            out.customer_phone = get(raw, "ShipPhoneNumber");
            out.customer_mail = get(raw, "BillMailAddress");
            out.pay_method = get(raw, "PayMethodName");
            out.order_status = get(raw, "OrderStatus");
            out.order_date = get(raw, "OrderTime");
            out.item_option = first_non_empty(raw, &["SubCodeOption", "itemOption"]);
            out.chinese_option = get(raw, "selectedChoice");
            // 片假名：Ship 系仅乐天提供，列名同为 `senderKana`。
            out.customer_kana = get(raw, "senderKana");
            // 地址：Ship 系分列拼接 ShipPrefecture+ShipCity+ShipAddress1+ShipAddress2。
            out.customer_address = concat_parts(&[
                get(raw, "ShipPrefecture"),
                get(raw, "ShipCity"),
                get(raw, "ShipAddress1"),
                get(raw, "ShipAddress2"),
            ]);
            // Ship 系商品名列（取常见列名，缺失则空）。
            out.product_title = first_non_empty(raw, &["ItemName", "product_title"]);
        }
        NamingFamily::Sender => {
            out.customer_name = get(raw, "senderName");
            out.customer_kana = get(raw, "senderKana");
            out.customer_zip = get(raw, "senderZipCode");
            out.customer_phone = get(raw, "senderPhoneNumber1");
            out.customer_mail = get(raw, "mailAddress");
            out.pay_method = get(raw, "settlementName");
            out.ship_method = get(raw, "deliveryName");
            out.order_status = get(raw, "orderStatus");
            out.order_date = get(raw, "orderDate");
            // 地址：sender 系单列 senderAddress；Mercari 例外，分列拼接 shipping_*。
            out.customer_address = if platform == Platform::Mercari {
                concat_parts(&[
                    get(raw, "shipping_state"),
                    get(raw, "shipping_city"),
                    get(raw, "shipping_postal_code"),
                    get(raw, "shipping_address_1"),
                    get(raw, "shipping_address_2"),
                ])
            } else {
                get(raw, "senderAddress")
            };
            out.product_title = get(raw, "product_title");
            out.item_option = get(raw, "itemOption");
            out.chinese_option = get(raw, "chinese_option");
        }
    }

    // ---- 商品编码归一：按平台选列（与 Platform::item_code_field 一致）----
    out.item_code = get(raw, platform.item_code_field());

    // ---- 金额（规范列，两家族同名直取）----
    out.total_item_price = first_non_empty(raw, &["totalItemPrice", "TotalItemPrice"]);
    out.postage_price = first_non_empty(raw, &["postagePrice", "ShipCharge"]);
    out.total_price = first_non_empty(raw, &["totalPrice", "TotalPrice"]);

    // ---- 系统自有 16 列 1:1 直映 ----
    out.ship_number = get(raw, "shipnumber");
    out.cn_ship_number = get(raw, "shipno");
    out.jpship_status = get(raw, "jpshipdetails");
    out.jpship_completed_at = get(raw, "jpship_completed_at");
    out.ship_quantity = get(raw, "shipquantity");
    out.cn_amount = get(raw, "cnamount");
    out.com_amount = get(raw, "comamount");
    out.quantity = first_non_empty(raw, &["quantity", "Quantity", "unit"]);
    out.weight = get(raw, "weight");
    out.amount = first_non_empty(raw, &["amount", "UnitPrice", "unitPrice"]);
    out.beizhu = get(raw, "beizhu");
    out.caigou_time = get(raw, "caigoutime");
    out.caigou_link = get(raw, "caigoulink");
    out.buhuo_link = get(raw, "buhuolink");
    out.tabaono = get(raw, "tabaono");
    out.imported_at = get(raw, "cdate");

    // ---- 独有列 → platform_extra（键名保留原始列名）----
    out.order_platform_extra = collect_extra(raw, order_unique_columns(platform));
    out.item_platform_extra = collect_extra(raw, item_unique_columns(platform));

    out
}

/// 该平台「订单级」独有列（→ `orders.platform_extra`，键名保留原名）。见 design 3.3.1。
fn order_unique_columns(platform: Platform) -> &'static [&'static str] {
    match platform {
        // Ship 系共有：PayStatus/PayDate（sender 系无对应语义）。
        Platform::Yahoo => &["PayStatus", "PayDate", "EntryPoint"],
        Platform::Rakuten => &[
            "PayStatus",
            "PayDate",
            "ShipCharge",
            "PayCharge",
            "requestPrice",
            "ShipRequestDate",
            "ShipRequestTime",
            "ShipNotes",
            "QuantityDetail",
        ],
        _ => &[],
    }
}

/// 该平台「子商品级」独有列（→ `order_items.platform_extra`，键名保留原名）。见 design 3.3.1。
fn item_unique_columns(platform: Platform) -> &'static [&'static str] {
    match platform {
        Platform::Rakuten => &[
            "ItemManagerId",
            "SubCodeOption",
            "selectedChoice",
            "UnitPrice",
            "delvdateInfo",
            "basketId",
        ],
        // Mercari、雅虎拍卖：itemOptionCommission1..5。
        Platform::Mercari | Platform::YahooAuction => &[
            "itemOptionCommission1",
            "itemOptionCommission2",
            "itemOptionCommission3",
            "itemOptionCommission4",
            "itemOptionCommission5",
        ],
        _ => &[],
    }
}

/// 从原始记录抽取指定独有列，组装成 JSON 对象（键名保留原名，仅收非空值）。
/// 无任一非空列时返回 `None`，对齐模型中 `platform_extra: Option<JsonValue>`。
fn collect_extra(raw: &RawRecord, keys: &[&str]) -> Option<JsonValue> {
    let mut map = JsonMap::new();
    for &key in keys {
        let v = get(raw, key);
        if !v.is_empty() {
            map.insert(key.to_string(), JsonValue::String(v));
        }
    }
    if map.is_empty() {
        None
    } else {
        Some(JsonValue::Object(map))
    }
}

/// 取列值并 `trim`，缺失返回空串。
fn get(raw: &RawRecord, key: &str) -> String {
    raw.get(key)
        .map(|s| s.trim().to_string())
        .unwrap_or_default()
}

/// 按顺序取第一个非空列值。
fn first_non_empty(raw: &RawRecord, keys: &[&str]) -> String {
    for &k in keys {
        let v = get(raw, k);
        if !v.is_empty() {
            return v;
        }
    }
    String::new()
}

/// 地址分列拼接：跳过空段，非空段直接相连（日文地址惯例：都道府县+市区+番地）。
fn concat_parts(parts: &[String]) -> String {
    parts
        .iter()
        .filter(|s| !s.is_empty())
        .cloned()
        .collect::<Vec<_>>()
        .join("")
}

fn parse_csv(input: &str) -> Result<Vec<Vec<String>>, String> {
    let mut rows = Vec::new();
    let mut row = Vec::new();
    let mut field = String::new();
    let mut chars = input.chars().peekable();
    let mut in_quotes = false;

    while let Some(ch) = chars.next() {
        match ch {
            '"' if in_quotes && chars.peek() == Some(&'"') => {
                field.push('"');
                chars.next();
            }
            '"' => {
                in_quotes = !in_quotes;
            }
            ',' if !in_quotes => {
                row.push(clean_csv_cell(&field));
                field.clear();
            }
            '\n' if !in_quotes => {
                row.push(clean_csv_cell(&field));
                field.clear();
                if !row.iter().all(|cell| cell.is_empty()) {
                    rows.push(row);
                }
                row = Vec::new();
            }
            '\r' if !in_quotes => {
                if chars.peek() == Some(&'\n') {
                    chars.next();
                }
                row.push(clean_csv_cell(&field));
                field.clear();
                if !row.iter().all(|cell| cell.is_empty()) {
                    rows.push(row);
                }
                row = Vec::new();
            }
            _ => field.push(ch),
        }
    }

    if in_quotes {
        return Err("CSV 引号未闭合".to_string());
    }

    if !field.is_empty() || !row.is_empty() {
        row.push(clean_csv_cell(&field));
        if !row.iter().all(|cell| cell.is_empty()) {
            rows.push(row);
        }
    }

    Ok(rows)
}

fn clean_csv_cell(cell: &str) -> String {
    cell.trim_matches('\u{feff}').trim().to_string()
}

fn trim_row(row: &[String]) -> Vec<String> {
    row.iter().map(|cell| clean_csv_cell(cell)).collect()
}

fn records_from_csv_rows(headers: &[String], rows: &[Vec<String>]) -> Vec<(usize, RawRecord)> {
    rows.iter()
        .enumerate()
        .map(|(i, row)| {
            let mut record = RawRecord::new();
            for (index, header) in headers.iter().enumerate() {
                if header.is_empty() {
                    continue;
                }
                let value = row
                    .get(index)
                    .map(|s| clean_csv_cell(s))
                    .unwrap_or_default();
                record.insert(header.clone(), value);
            }
            (i + 2, record)
        })
        .collect()
}

fn is_legacy_rakuten_fixed_csv(platform: Platform, headers: &[String]) -> bool {
    platform == Platform::Rakuten && headers.len() == 162
}

fn legacy_rakuten_fixed_headers() -> Vec<String> {
    [
        "OrderId",
        "OrderStatus",
        "OrderTime",
        "ShipRequestDate",
        "ShipRequestTime",
        "PayMethodName",
        "ShipAddress2",
        "ShipCharge",
        "PayCharge",
        "TotalPrice",
        "BillMailAddress",
        "ShipNotes",
        "ShipZipCode",
        "ShipCity",
        "ShipPrefecture",
        "ShipAddress1",
        "ShipName",
        "senderKana",
        "ShipPhoneNumber",
        "ItemManagerId",
        "ItemId",
        "UnitPrice",
        "Quantity",
        "PayDate",
        "SubCodeOption",
    ]
    .into_iter()
    .map(str::to_string)
    .collect()
}

fn records_from_legacy_rakuten_rows(rows: &[Vec<String>]) -> Vec<(usize, RawRecord)> {
    rows.iter()
        .enumerate()
        .map(|(i, row)| {
            let mut record = RawRecord::new();
            insert_legacy_cell(&mut record, "OrderId", row, 0);
            insert_legacy_cell(&mut record, "OrderStatus", row, 1);
            insert_legacy_cell(&mut record, "OrderTime", row, 4);
            insert_legacy_cell(&mut record, "ShipRequestDate", row, 5);
            insert_legacy_cell(&mut record, "ShipRequestTime", row, 6);
            insert_legacy_cell(&mut record, "PayMethodName", row, 12);
            insert_legacy_cell(&mut record, "ShipAddress2", row, 15);
            insert_legacy_cell(&mut record, "ShipCharge", row, 27);
            insert_legacy_cell(&mut record, "PayCharge", row, 28);
            insert_legacy_cell(&mut record, "TotalPrice", row, 30);
            insert_legacy_cell(&mut record, "BillMailAddress", row, 47);
            insert_legacy_cell(&mut record, "ShipNotes", row, 57);
            insert_legacy_join(&mut record, "ShipZipCode", row, &[58, 59], "-");
            insert_legacy_cell(&mut record, "ShipCity", row, 60);
            insert_legacy_cell(&mut record, "ShipPrefecture", row, 61);
            insert_legacy_cell(&mut record, "ShipAddress1", row, 62);
            insert_legacy_join(&mut record, "ShipName", row, &[63, 64], " ");
            insert_legacy_join(&mut record, "senderKana", row, &[65, 66], " ");
            insert_legacy_join(&mut record, "ShipPhoneNumber", row, &[67, 68, 69], "-");
            insert_legacy_cell(&mut record, "ItemManagerId", row, 73);
            insert_legacy_cell(&mut record, "ItemId", row, 74);
            insert_legacy_cell(&mut record, "UnitPrice", row, 75);
            insert_legacy_cell(&mut record, "Quantity", row, 76);
            insert_legacy_cell(&mut record, "PayDate", row, 80);
            insert_legacy_cell(&mut record, "SubCodeOption", row, 157);
            (i + 2, record)
        })
        .collect()
}

fn insert_legacy_cell(record: &mut RawRecord, key: &str, row: &[String], index: usize) {
    record.insert(
        key.to_string(),
        row.get(index)
            .map(|s| clean_csv_cell(s))
            .unwrap_or_default(),
    );
}

fn insert_legacy_join(
    record: &mut RawRecord,
    key: &str,
    row: &[String],
    indexes: &[usize],
    sep: &str,
) {
    let value = indexes
        .iter()
        .filter_map(|index| row.get(*index))
        .map(|value| clean_csv_cell(value))
        .filter(|value| !value.is_empty())
        .collect::<Vec<_>>()
        .join(sep);
    record.insert(key.to_string(), value);
}

fn is_blank_record(record: &RawRecord) -> bool {
    record.values().all(|value| value.trim().is_empty())
}

fn import_field_aliases(platform: Platform) -> &'static [ImportFieldAlias] {
    match platform {
        Platform::Yahoo | Platform::Rakuten => SHIP_IMPORT_ALIASES,
        Platform::Wowma | Platform::Qoo10 => SENDER_IMPORT_ALIASES,
        Platform::Mercari | Platform::YahooAuction => AUCTION_IMPORT_ALIASES,
    }
}

const SHIP_IMPORT_ALIASES: &[ImportFieldAlias] = &[
    alias("orderId", "order_id", ImportFieldScope::Order),
    alias("OrderId", "order_id", ImportFieldScope::Order),
    alias("myid", "order_id", ImportFieldScope::Order),
    alias("注文番号", "order_id", ImportFieldScope::Order),
    alias("orderDetailId", "line_id", ImportFieldScope::Item),
    alias("LineId", "line_id", ImportFieldScope::Item),
    alias("basketId", "line_id", ImportFieldScope::Item),
    alias("orderDate", "order_date", ImportFieldScope::Order),
    alias("OrderTime", "order_date", ImportFieldScope::Order),
    alias("注文日時", "order_date", ImportFieldScope::Order),
    alias("OrderStatus", "order_status", ImportFieldScope::Order),
    alias("ItemId", "item_code", ImportFieldScope::Item),
    alias("ItemManagerId", "item_manager_id", ImportFieldScope::Item),
    alias(
        "itemManagementId",
        "item_manager_id",
        ImportFieldScope::Item,
    ),
    alias("ItemName", "product_title", ImportFieldScope::Item),
    alias("itemName", "product_title", ImportFieldScope::Item),
    alias("Quantity", "quantity", ImportFieldScope::Item),
    alias("quantity", "quantity", ImportFieldScope::Item),
    alias("UnitPrice", "unit_price", ImportFieldScope::Item),
    alias("TotalPrice", "total_price", ImportFieldScope::Order),
    alias("totalPrice", "total_price", ImportFieldScope::Order),
    alias("ShipCharge", "postage_price", ImportFieldScope::Order),
    alias("postagePrice", "postage_price", ImportFieldScope::Order),
    alias("requestPrice", "request_price", ImportFieldScope::Order),
    alias("PayCharge", "charge_price", ImportFieldScope::Order),
    alias("ShipName", "customer_name", ImportFieldScope::Order),
    alias("senderKana", "customer_kana", ImportFieldScope::Order),
    alias("ShipZipCode", "customer_zip", ImportFieldScope::Order),
    alias(
        "ShipPrefecture",
        "customer_prefecture",
        ImportFieldScope::Order,
    ),
    alias("ShipCity", "customer_city", ImportFieldScope::Order),
    alias("ShipAddress1", "customer_address1", ImportFieldScope::Order),
    alias("ShipAddress2", "customer_address2", ImportFieldScope::Order),
    alias("ShipPhoneNumber", "customer_phone", ImportFieldScope::Order),
    alias("BillMailAddress", "customer_mail", ImportFieldScope::Order),
    alias("PayMethodName", "pay_method", ImportFieldScope::Order),
    alias("PayStatus", "pay_status", ImportFieldScope::Extra),
    alias("PayDate", "pay_date", ImportFieldScope::Extra),
    alias("EntryPoint", "entry_point", ImportFieldScope::Extra),
    alias("SubCodeOption", "item_option", ImportFieldScope::Item),
    alias("selectedChoice", "item_option", ImportFieldScope::Item),
];

const SENDER_IMPORT_ALIASES: &[ImportFieldAlias] = &[
    alias("orderId", "order_id", ImportFieldScope::Order),
    alias("OrderId", "order_id", ImportFieldScope::Order),
    alias("orderDetailId", "line_id", ImportFieldScope::Item),
    alias("orderDate", "order_date", ImportFieldScope::Order),
    alias("orderStatus", "order_status", ImportFieldScope::Order),
    alias("itemCode", "item_code", ImportFieldScope::Item),
    alias("ItemId", "item_code", ImportFieldScope::Item),
    alias("lotnumber", "item_code", ImportFieldScope::Item),
    alias(
        "itemManagementId",
        "item_manager_id",
        ImportFieldScope::Item,
    ),
    alias("itemName", "product_title", ImportFieldScope::Item),
    alias("product_title", "product_title", ImportFieldScope::Item),
    alias("itemOption", "item_option", ImportFieldScope::Item),
    alias("unit", "quantity", ImportFieldScope::Item),
    alias("quantity", "quantity", ImportFieldScope::Item),
    alias("itemPrice", "unit_price", ImportFieldScope::Item),
    alias(
        "totalItemPrice",
        "total_item_price",
        ImportFieldScope::Order,
    ),
    alias("postagePrice", "postage_price", ImportFieldScope::Order),
    alias("totalPrice", "total_price", ImportFieldScope::Order),
    alias("requestPrice", "request_price", ImportFieldScope::Order),
    alias("senderName", "customer_name", ImportFieldScope::Order),
    alias("senderKana", "customer_kana", ImportFieldScope::Order),
    alias("senderZipCode", "customer_zip", ImportFieldScope::Order),
    alias("senderAddress", "customer_address", ImportFieldScope::Order),
    alias(
        "senderPhoneNumber1",
        "customer_phone",
        ImportFieldScope::Order,
    ),
    alias("mailAddress", "customer_mail", ImportFieldScope::Order),
    alias("settlementName", "pay_method", ImportFieldScope::Order),
    alias("deliveryName", "ship_method", ImportFieldScope::Order),
    alias("paymentStatus", "pay_status", ImportFieldScope::Extra),
    alias("paymentDate", "pay_date", ImportFieldScope::Extra),
    alias("siteAndDevice", "entry_point", ImportFieldScope::Extra),
];

const AUCTION_IMPORT_ALIASES: &[ImportFieldAlias] = &[
    alias("orderId", "order_id", ImportFieldScope::Order),
    alias("OrderId", "order_id", ImportFieldScope::Order),
    alias("注文番号", "order_id", ImportFieldScope::Order),
    alias("OID", "order_id", ImportFieldScope::Order),
    alias("orderDetailId", "line_id", ImportFieldScope::Item),
    alias("orderDate", "order_date", ImportFieldScope::Order),
    alias("注文日時", "order_date", ImportFieldScope::Order),
    alias("注文日期", "order_date", ImportFieldScope::Order),
    alias("注文日", "order_date", ImportFieldScope::Order),
    alias("注文日期", "order_date", ImportFieldScope::Order),
    alias("注文日", "order_date", ImportFieldScope::Order),
    alias("订单日期", "order_date", ImportFieldScope::Order),
    alias("ItemId", "item_code", ImportFieldScope::Item),
    alias("itemCode", "item_code", ImportFieldScope::Item),
    alias("lotnumber", "item_code", ImportFieldScope::Item),
    alias("タイトル", "product_title", ImportFieldScope::Item),
    alias("标题", "product_title", ImportFieldScope::Item),
    alias("数量", "quantity", ImportFieldScope::Item),
    alias("単価", "unit_price", ImportFieldScope::Item),
    alias("单价", "unit_price", ImportFieldScope::Item),
    alias("送料", "postage_price", ImportFieldScope::Order),
    alias("运费", "postage_price", ImportFieldScope::Order),
    alias("氏名", "customer_name", ImportFieldScope::Order),
    alias("郵便番号", "customer_zip", ImportFieldScope::Order),
    alias("住所", "customer_address", ImportFieldScope::Order),
    alias("配送方法", "ship_method", ImportFieldScope::Order),
];

const fn alias(
    source: &'static str,
    target: &'static str,
    scope: ImportFieldScope,
) -> ImportFieldAlias {
    ImportFieldAlias {
        source,
        target,
        scope,
    }
}

fn find_alias(aliases: &[ImportFieldAlias], source: &str) -> Option<ImportFieldAlias> {
    aliases
        .iter()
        .copied()
        .find(|alias| header_eq(alias.source, source))
}

fn header_eq(left: &str, right: &str) -> bool {
    normalize_header(left) == normalize_header(right)
}

fn normalize_header(value: &str) -> String {
    value
        .trim_matches('\u{feff}')
        .trim()
        .chars()
        .filter(|ch| !ch.is_whitespace() && *ch != '_' && *ch != '-' && *ch != '　')
        .flat_map(|ch| ch.to_lowercase())
        .collect()
}

fn find_target(recognized: &[(usize, ImportFieldAlias)], target: &str) -> Option<ImportFieldAlias> {
    recognized
        .iter()
        .map(|(_, alias)| *alias)
        .find(|alias| alias.target == target)
}

fn find_item_identity(recognized: &[(usize, ImportFieldAlias)]) -> Option<ImportFieldAlias> {
    recognized
        .iter()
        .map(|(_, alias)| *alias)
        .find(|alias| matches!(alias.target, "item_code" | "line_id" | "item_manager_id"))
}

fn value_by_target(record: &RawRecord, aliases: &[ImportFieldAlias], target: &str) -> String {
    for alias in aliases {
        if alias.target == target {
            for (header, value) in record {
                if header_eq(header, alias.source) {
                    let value = value.trim();
                    if !value.is_empty() {
                        return value.to_string();
                    }
                }
            }
        }
    }
    String::new()
}

fn first_sample(records: &[(usize, RawRecord)], source: &str) -> String {
    for (_, record) in records {
        let value = get(record, source);
        if !value.is_empty() {
            return value;
        }
    }
    String::new()
}

fn unique_targets(
    recognized: &[(usize, ImportFieldAlias)],
    scope: ImportFieldScope,
) -> Vec<String> {
    let mut out = Vec::new();
    for (_, alias) in recognized {
        if alias.scope == scope && !out.iter().any(|target| target == alias.target) {
            out.push(alias.target.to_string());
        }
    }
    out
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
mod tests;

#[cfg(test)]
mod preview_tests;
