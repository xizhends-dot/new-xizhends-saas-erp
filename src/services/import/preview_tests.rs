use super::*;
use crate::models::platform::Platform;

#[test]
fn preview_rakuten_csv_recognizes_ship_family_fields() {
    let csv = concat!(
        "OrderId,LineId,OrderTime,ItemId,ShipName,ShipZipCode,TotalPrice,Quantity,SubCodeOption\n",
        "123-1,1,2026-06-01 10:00:00,R-SKU-1,Taro,100-0001,3980,2,\"color:red, size:L\"\n",
        ",2,2026-06-01 10:01:00,R-SKU-2,Taro,100-0001,1200,1,\n"
    );

    let preview = preview_csv_import(Platform::Rakuten, csv);

    assert_eq!(preview.total_rows, 2);
    assert_eq!(preview.importable_rows, 1);
    assert!(preview
        .errors
        .iter()
        .any(|err| err.row == Some(3) && err.field.as_deref() == Some("order_id")));
    assert!(preview
        .suggested_order_fields
        .contains(&"order_id".to_string()));
    assert!(preview
        .suggested_item_fields
        .contains(&"item_code".to_string()));

    let option_mapping = preview
        .field_mapping_preview
        .iter()
        .find(|mapping| mapping.source == "SubCodeOption")
        .unwrap();
    assert_eq!(option_mapping.target, "item_option");
    assert_eq!(option_mapping.sample, "color:red, size:L");
}

#[test]
fn preview_wowma_csv_recognizes_sender_family_fields() {
    let csv = concat!(
        "controlType,orderId,orderDetailId,orderDate,itemCode,senderName,senderZipCode,",
        "senderPhoneNumber1,settlementName,totalItemPrice,postagePrice,totalPrice,unit,deliveryName\n",
        "new,W-100,D-1,2026-06-02 09:00:00,W-SKU,Taro,1000001,09012345678,card,1000,500,1500,3,Yamato\n"
    );

    let preview = preview_csv_import(Platform::Wowma, csv);

    assert_eq!(preview.total_rows, 1);
    assert_eq!(preview.importable_rows, 1);
    assert!(!preview
        .errors
        .iter()
        .any(|err| err.severity == ImportPreviewSeverity::Error));
    assert!(preview
        .suggested_order_fields
        .contains(&"ship_method".to_string()));
    assert!(preview
        .suggested_item_fields
        .contains(&"quantity".to_string()));
}

#[test]
fn preview_csv_reports_empty_and_unclosed_quote_errors() {
    let empty = preview_csv_import(Platform::Yahoo, "");
    assert_eq!(empty.total_rows, 0);
    assert!(empty
        .errors
        .iter()
        .any(|err| err.message.contains("CSV 内容为空")));

    let broken = preview_csv_import(Platform::Yahoo, "orderId,ItemId\n\"Y-1,SKU");
    assert!(broken
        .errors
        .iter()
        .any(|err| err.message.contains("CSV 引号未闭合")));
}

#[test]
fn preview_keeps_quoted_newline_inside_cell() {
    let csv = "orderId,ItemId,selectedChoice\nR-1,SKU-1,\"color:red\nsize:L\"\n";
    let preview = preview_csv_import(Platform::Rakuten, csv);

    assert_eq!(preview.total_rows, 1);
    assert_eq!(preview.importable_rows, 1);
    assert_eq!(
        preview
            .field_mapping_preview
            .iter()
            .find(|mapping| mapping.source == "selectedChoice")
            .unwrap()
            .sample,
        "color:red\nsize:L"
    );
}

#[test]
fn legacy_rakuten_162_column_csv_maps_fixed_indexes() {
    let mut header = vec!["".to_string(); 162];
    header[0] = "注文番号".to_string();
    let mut row = vec!["".to_string(); 162];
    row[0] = "R-100".to_string();
    row[1] = "300".to_string();
    row[4] = "2026-06-01 10:00:00".to_string();
    row[12] = "カード".to_string();
    row[27] = "500".to_string();
    row[30] = "3980".to_string();
    row[47] = "buyer@example.com".to_string();
    row[58] = "100".to_string();
    row[59] = "0001".to_string();
    row[60] = "千代田区".to_string();
    row[61] = "東京都".to_string();
    row[62] = "1-1".to_string();
    row[63] = "山田".to_string();
    row[64] = "太郎".to_string();
    row[65] = "ヤマダ".to_string();
    row[66] = "タロウ".to_string();
    row[67] = "090".to_string();
    row[68] = "1111".to_string();
    row[69] = "2222".to_string();
    row[73] = "MGR-1".to_string();
    row[74] = "SKU-1".to_string();
    row[75] = "3480".to_string();
    row[76] = "2".to_string();
    row[80] = "2026-06-01".to_string();
    row[157] = "color:red".to_string();

    let csv = format!("{}\n{}\n", header.join(","), row.join(","));
    let batch = parse_csv_import(Platform::Rakuten, &csv);

    assert_eq!(batch.preview.total_rows, 1);
    assert_eq!(batch.preview.importable_rows, 1);
    assert_eq!(batch.records.len(), 1);
    let record = &batch.records[0];
    assert_eq!(record.order_id, "R-100");
    assert_eq!(record.mapped.customer_name, "山田 太郎");
    assert_eq!(record.mapped.customer_zip, "100-0001");
    assert_eq!(record.mapped.customer_phone, "090-1111-2222");
    assert_eq!(record.mapped.customer_address, "東京都千代田区1-1");
    assert_eq!(record.mapped.item_code, "SKU-1");
    assert_eq!(record.mapped.item_option, "color:red");
    assert_eq!(record.mapped.quantity, "2");
    assert_eq!(record.mapped.amount, "3480");
    assert_eq!(record.mapped.postage_price, "500");
    assert_eq!(record.mapped.total_price, "3980");
}
