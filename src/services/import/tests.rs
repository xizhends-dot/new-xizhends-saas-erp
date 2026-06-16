//! import mapper 单元测试（对应 tasks 8.5 / design 3.3.1）。
//!
//! 覆盖四类断言（见 design 3.3.1「映射规则」与 task 8.5）：
//! 1. 两套命名家族（Ship 系 y/r、sender 系 w/m/q/yp）的同义列 → **同一组规范列**。
//! 2. 平台/家族独有列 → `platform_extra` JSON，**键名保留原始列名**。
//! 3. 地址拼接：Ship 系分列拼接、sender 系单列、Mercari 例外分列拼接。
//! 4. 商品编码归一：按 `Platform::item_code_field()` 选列写入 `item_code`。
//!
//! 这些测试用真实列名（design 3.3.1 固化的旧系统 SELECT 列名）构造原始记录，
//! 不使用 mock，直接驱动 [`map_record`]。

use super::*;
use crate::models::platform::Platform;

/// 构造原始记录的小工具：键=原始列名，值=单元格文本。
fn raw(pairs: &[(&str, &str)]) -> RawRecord {
    pairs
        .iter()
        .map(|(k, v)| (k.to_string(), v.to_string()))
        .collect()
}

/// 从 `platform_extra` 中取某键的字符串值（断言用）。
fn extra_str(extra: &Option<serde_json::Value>, key: &str) -> Option<String> {
    extra
        .as_ref()
        .and_then(|v| v.get(key))
        .and_then(|v| v.as_str())
        .map(|s| s.to_string())
}

// ---------------------------------------------------------------------------
// 1. 命名家族归一：两家族 → 同一组规范列
// ---------------------------------------------------------------------------

#[test]
fn naming_family_classification_matches_design() {
    assert_eq!(NamingFamily::of(Platform::Yahoo), NamingFamily::Ship);
    assert_eq!(NamingFamily::of(Platform::Rakuten), NamingFamily::Ship);
    assert_eq!(NamingFamily::of(Platform::Wowma), NamingFamily::Sender);
    assert_eq!(NamingFamily::of(Platform::Mercari), NamingFamily::Sender);
    assert_eq!(NamingFamily::of(Platform::Qoo10), NamingFamily::Sender);
    assert_eq!(
        NamingFamily::of(Platform::YahooAuction),
        NamingFamily::Sender
    );
}

#[test]
fn both_families_map_to_same_canonical_columns() {
    // Ship 系（乐天 r）：贴平台 API 命名。
    let ship = raw(&[
        ("ShipName", "山田太郎"),
        ("senderKana", "ヤマダタロウ"),
        ("ShipZipCode", "1000001"),
        ("ShipPhoneNumber", "09012345678"),
        ("BillMailAddress", "taro@example.com"),
        ("PayMethodName", "クレジットカード"),
        ("OrderStatus", "新規"),
        ("OrderTime", "2024-01-02 10:00:00"),
        ("ItemId", "R-ABC-001"),
    ]);
    let ship_mapped = map_record(Platform::Rakuten, &ship);

    // sender 系（Wowma w）：表达完全相同语义，但用另一套列名。
    let sender = raw(&[
        ("senderName", "山田太郎"),
        ("senderKana", "ヤマダタロウ"),
        ("senderZipCode", "1000001"),
        ("senderPhoneNumber1", "09012345678"),
        ("mailAddress", "taro@example.com"),
        ("settlementName", "クレジットカード"),
        ("orderStatus", "新規"),
        ("orderDate", "2024-01-02 10:00:00"),
        ("itemCode", "W-ABC-001"),
    ]);
    let sender_mapped = map_record(Platform::Wowma, &sender);

    // 同义列翻译到同一组规范列后，除「平台代码 / 商品编码（按平台不同列取）」外应一致。
    assert_eq!(ship_mapped.customer_name, sender_mapped.customer_name);
    assert_eq!(ship_mapped.customer_kana, sender_mapped.customer_kana);
    assert_eq!(ship_mapped.customer_zip, sender_mapped.customer_zip);
    assert_eq!(ship_mapped.customer_phone, sender_mapped.customer_phone);
    assert_eq!(ship_mapped.customer_mail, sender_mapped.customer_mail);
    assert_eq!(ship_mapped.pay_method, sender_mapped.pay_method);
    assert_eq!(ship_mapped.order_status, sender_mapped.order_status);
    assert_eq!(ship_mapped.order_date, sender_mapped.order_date);

    // 具体规范值正确。
    assert_eq!(ship_mapped.customer_name, "山田太郎");
    assert_eq!(ship_mapped.customer_kana, "ヤマダタロウ");
    assert_eq!(ship_mapped.customer_mail, "taro@example.com");
    assert_eq!(ship_mapped.pay_method, "クレジットカード");
    assert_eq!(ship_mapped.order_status, "新規");
}

#[test]
fn platform_distinction_is_only_via_platform_code() {
    // 同一组 sender 系列名喂给不同 sender 系平台，平台代码各自正确，规范列一致。
    let cols = raw(&[
        ("senderName", "佐藤花子"),
        ("senderAddress", "東京都新宿区1-2-3"),
        ("orderStatus", "発送済"),
    ]);
    let w = map_record(Platform::Wowma, &cols);
    let q = map_record(Platform::Qoo10, &cols);

    assert_eq!(w.platform, "w");
    assert_eq!(q.platform, "q");
    // 平台不同，但同义列映射结果一致（区分平台不靠列名前缀）。
    assert_eq!(w.customer_name, q.customer_name);
    assert_eq!(w.customer_address, q.customer_address);
    assert_eq!(w.order_status, q.order_status);
}

// ---------------------------------------------------------------------------
// 2. 独有列 → platform_extra（键名保留原名）
// ---------------------------------------------------------------------------

#[test]
fn rakuten_unique_columns_land_in_platform_extra() {
    let r = raw(&[
        ("ShipName", "山田"),
        ("ItemId", "R-1"),
        // 订单级独有
        ("PayStatus", "入金済"),
        ("PayDate", "2024-01-03"),
        ("ShipCharge", "500"),
        ("PayCharge", "0"),
        ("requestPrice", "12000"),
        // 子商品级独有
        ("ItemManagerId", "MGR-9"),
        ("SubCodeOption", "color:red"),
        ("selectedChoice", "L"),
        ("UnitPrice", "3000"),
    ]);
    let m = map_record(Platform::Rakuten, &r);

    // 订单级独有列入 order_platform_extra，键名原样保留。
    assert_eq!(
        extra_str(&m.order_platform_extra, "PayStatus"),
        Some("入金済".into())
    );
    assert_eq!(
        extra_str(&m.order_platform_extra, "PayDate"),
        Some("2024-01-03".into())
    );
    assert_eq!(
        extra_str(&m.order_platform_extra, "ShipCharge"),
        Some("500".into())
    );
    assert_eq!(
        extra_str(&m.order_platform_extra, "PayCharge"),
        Some("0".into())
    );
    assert_eq!(
        extra_str(&m.order_platform_extra, "requestPrice"),
        Some("12000".into())
    );

    // 子商品级独有列入 item_platform_extra。
    assert_eq!(
        extra_str(&m.item_platform_extra, "ItemManagerId"),
        Some("MGR-9".into())
    );
    assert_eq!(
        extra_str(&m.item_platform_extra, "SubCodeOption"),
        Some("color:red".into())
    );
    assert_eq!(
        extra_str(&m.item_platform_extra, "selectedChoice"),
        Some("L".into())
    );
    assert_eq!(
        extra_str(&m.item_platform_extra, "UnitPrice"),
        Some("3000".into())
    );

    // 独有列不应泄漏成规范列（这里仅验证它们确实只在 extra 中）。
    assert!(m.item_code == "R-1");
}

#[test]
fn yahoo_unique_entrypoint_lands_in_platform_extra() {
    let y = raw(&[
        ("ShipName", "鈴木"),
        ("ItemId", "Y-1"),
        ("EntryPoint", "smartphone"),
        ("PayStatus", "未入金"),
    ]);
    let m = map_record(Platform::Yahoo, &y);
    assert_eq!(
        extra_str(&m.order_platform_extra, "EntryPoint"),
        Some("smartphone".into())
    );
    assert_eq!(
        extra_str(&m.order_platform_extra, "PayStatus"),
        Some("未入金".into())
    );
}

#[test]
fn mercari_and_auction_item_option_commissions_land_in_item_extra() {
    let pairs = raw(&[
        ("senderName", "高橋"),
        ("lotnumber", "M-1"),
        ("itemOptionCommission1", "c1"),
        ("itemOptionCommission2", "c2"),
        ("itemOptionCommission3", "c3"),
        ("itemOptionCommission4", "c4"),
        ("itemOptionCommission5", "c5"),
    ]);

    for platform in [Platform::Mercari, Platform::YahooAuction] {
        let m = map_record(platform, &pairs);
        for i in 1..=5 {
            let key = format!("itemOptionCommission{i}");
            assert_eq!(
                extra_str(&m.item_platform_extra, &key),
                Some(format!("c{i}")),
                "{platform:?} 应把 {key} 收进 item_platform_extra"
            );
        }
    }
}

#[test]
fn platforms_without_unique_columns_have_empty_extra() {
    // Wowma / Qoo10 无登记独有列。
    let cols = raw(&[("senderName", "田中"), ("itemCode", "W-1")]);
    let w = map_record(Platform::Wowma, &cols);
    assert!(w.order_platform_extra.is_none());
    assert!(w.item_platform_extra.is_none());

    let q = map_record(Platform::Qoo10, &cols);
    assert!(q.order_platform_extra.is_none());
    assert!(q.item_platform_extra.is_none());
}

#[test]
fn missing_unique_columns_are_omitted_from_extra() {
    // 只给部分独有列，缺失键不应出现在 extra 中。
    let r = raw(&[("ShipName", "山田"), ("PayStatus", "入金済")]);
    let m = map_record(Platform::Rakuten, &r);
    assert_eq!(
        extra_str(&m.order_platform_extra, "PayStatus"),
        Some("入金済".into())
    );
    // PayDate 等缺失键不应存在。
    assert!(m
        .order_platform_extra
        .as_ref()
        .unwrap()
        .get("PayDate")
        .is_none());
    // 子商品级独有列全缺失 → None。
    assert!(m.item_platform_extra.is_none());
}

// ---------------------------------------------------------------------------
// 3. 地址拼接
// ---------------------------------------------------------------------------

#[test]
fn ship_family_address_is_concatenated_from_parts() {
    let y = raw(&[
        ("ShipPrefecture", "東京都"),
        ("ShipCity", "新宿区"),
        ("ShipAddress1", "西新宿1-2-3"),
        ("ShipAddress2", "ビル4F"),
    ]);
    let m = map_record(Platform::Yahoo, &y);
    assert_eq!(m.customer_address, "東京都新宿区西新宿1-2-3ビル4F");
}

#[test]
fn ship_family_address_skips_empty_parts() {
    let r = raw(&[
        ("ShipPrefecture", "大阪府"),
        ("ShipCity", "大阪市"),
        // ShipAddress1 缺失
        ("ShipAddress2", "101号"),
    ]);
    let m = map_record(Platform::Rakuten, &r);
    assert_eq!(m.customer_address, "大阪府大阪市101号");
}

#[test]
fn sender_family_address_is_single_column() {
    let w = raw(&[("senderAddress", "福岡県福岡市博多区1-1-1")]);
    let m = map_record(Platform::Wowma, &w);
    assert_eq!(m.customer_address, "福岡県福岡市博多区1-1-1");
}

#[test]
fn mercari_address_is_concatenated_from_shipping_parts() {
    let m_raw = raw(&[
        // Mercari 例外：单列 senderAddress 不应被使用，改用 shipping_* 分列拼接。
        ("senderAddress", "should-not-be-used"),
        ("shipping_state", "神奈川県"),
        ("shipping_city", "横浜市"),
        ("shipping_postal_code", "2200000"),
        ("shipping_address_1", "西区1-1"),
        ("shipping_address_2", "201"),
    ]);
    let m = map_record(Platform::Mercari, &m_raw);
    assert_eq!(m.customer_address, "神奈川県横浜市2200000西区1-1201");
}

// ---------------------------------------------------------------------------
// 4. 商品编码归一（按 Platform::item_code_field 选列）
// ---------------------------------------------------------------------------

#[test]
fn item_code_normalization_uses_platform_specific_column() {
    // y/r → ItemId
    let r = raw(&[("ItemId", "ID-Y"), ("itemCode", "x"), ("lotnumber", "x")]);
    assert_eq!(map_record(Platform::Yahoo, &r).item_code, "ID-Y");
    assert_eq!(map_record(Platform::Rakuten, &r).item_code, "ID-Y");

    // w/q → itemCode
    let w = raw(&[("ItemId", "x"), ("itemCode", "CODE-W"), ("lotnumber", "x")]);
    assert_eq!(map_record(Platform::Wowma, &w).item_code, "CODE-W");
    assert_eq!(map_record(Platform::Qoo10, &w).item_code, "CODE-W");

    // m/yp → lotnumber
    let m = raw(&[("ItemId", "x"), ("itemCode", "x"), ("lotnumber", "LOT-M")]);
    assert_eq!(map_record(Platform::Mercari, &m).item_code, "LOT-M");
    assert_eq!(map_record(Platform::YahooAuction, &m).item_code, "LOT-M");
}

#[test]
fn item_code_uses_field_consistent_with_platform_metadata() {
    // 归一所用列名必须与 Platform::item_code_field() 一致（导入/迁移共用同一规则）。
    for platform in Platform::ALL {
        let field = platform.item_code_field();
        let r = raw(&[(field, "VALUE-123")]);
        assert_eq!(
            map_record(platform, &r).item_code,
            "VALUE-123",
            "{platform:?} 应按 {field} 列归一 item_code"
        );
    }
}

// ---------------------------------------------------------------------------
// 5. 系统自有 16 列 1:1 直映
// ---------------------------------------------------------------------------

#[test]
fn system_owned_columns_are_mapped_one_to_one() {
    let cols = raw(&[
        ("senderName", "田中"),
        ("shipnumber", "SN-1"),
        ("shipno", "CN-1"),
        ("jpshipdetails", "配達完了"),
        ("jpship_completed_at", "2024-01-05 12:00:00"),
        ("shipquantity", "2"),
        ("cnamount", "100.50"),
        ("comamount", "10.00"),
        ("quantity", "3"),
        ("weight", "1.25"),
        ("amount", "300.00"),
        ("beizhu", "国内采购-已采购"),
        ("caigoutime", "2024-01-04 09:00:00"),
        ("caigoulink", "https://1688.com/x"),
        ("buhuolink", "https://1688.com/y"),
        ("tabaono", "TB-1"),
        ("cdate", "2024-01-01 00:00:00"),
    ]);
    let m = map_record(Platform::Wowma, &cols);

    assert_eq!(m.ship_number, "SN-1");
    assert_eq!(m.cn_ship_number, "CN-1");
    assert_eq!(m.jpship_status, "配達完了");
    assert_eq!(m.jpship_completed_at, "2024-01-05 12:00:00");
    assert_eq!(m.ship_quantity, "2");
    assert_eq!(m.cn_amount, "100.50");
    assert_eq!(m.com_amount, "10.00");
    assert_eq!(m.quantity, "3");
    assert_eq!(m.weight, "1.25");
    assert_eq!(m.amount, "300.00");
    assert_eq!(m.beizhu, "国内采购-已采购");
    assert_eq!(m.caigou_time, "2024-01-04 09:00:00");
    assert_eq!(m.caigou_link, "https://1688.com/x");
    assert_eq!(m.buhuo_link, "https://1688.com/y");
    assert_eq!(m.tabaono, "TB-1");
    assert_eq!(m.imported_at, "2024-01-01 00:00:00");
}

#[test]
fn values_are_trimmed_and_missing_columns_are_empty() {
    let cols = raw(&[("senderName", "  田中  "), ("itemCode", " C1 ")]);
    let m = map_record(Platform::Qoo10, &cols);
    assert_eq!(m.customer_name, "田中");
    assert_eq!(m.item_code, "C1");
    // 缺失列 → 空串。
    assert_eq!(m.customer_phone, "");
    assert_eq!(m.total_price, "");
}
