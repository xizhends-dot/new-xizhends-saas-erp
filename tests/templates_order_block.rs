#![recursion_limit = "256"]

//! 模板加载与渲染测试（Task 10.1）。
//!
//! 校验：
//! 1. `src/templates/**/*.html` 全部能被 Tera 解析（`Tera::new` 成功 ⇒ 无语法错误）；
//! 2. 共享订单块 `partials/order_block.html` 能为三视图（platform/purchase/jpstock）渲染；
//! 3. 多子订单在 B1 区逐行重复（每个 order_item 一行）；
//! 4. B2 区按货源分流：cn_purchase 显示采购信息、jp_stock 显示出库信息；
//! 5. 操作日志面板被渲染。
//!
//! 对应 Requirements 9.1 / 9.3 / 9.4。

use serde_json::json;
use tera::{Context, Tera};

/// 构造覆盖两种货源 + 多子商品 + 国际物流 + 日志的样本订单上下文。
fn sample_context(view: &str) -> Context {
    let order = json!({
        "id": 1,
        "platform": "y",
        "platform_order_id": "ORD-1001",
        "order_status": "新規受付",
        "order_date": "2024-01-02",
        "imported_at": "2024-01-02 10:00:00",
        "customer_name": "山田太郎",
        "customer_kana": "ヤマダタロウ",
        "customer_zip": "100-0001",
        "customer_address": "東京都千代田区1-1",
        "customer_phone": "09000000000",
        "customer_mail": "taro@example.com",
        "pay_method": "クレジット",
        "ship_method": "宅急便",
        "review_invited": true,
        "reviewed": false,
        "items": [
            {
                "id": 11,
                "source_type": "cn_purchase",
                "purchase_status": "国内采购-已采购",
                "item_code": "A-001",
                "jp_warehouse_id": null,
                "product_title": "商品甲",
                "item_option": "色:红",
                "chinese_option": "红色",
                "quantity": 2,
                "weight": "1.200",
                "material": "cotton",
                "amount": "1980",
                "main_image": "https://example.com/a.jpg",
                "purchase": {
                    "caigou_user": "张三",
                    "caigou_time": "2024-01-03 09:00:00",
                    "tabaono": "1688-XYZ",
                    "cn_amount": "120.00",
                    "caigou_link": "https://detail.1688.com/x",
                    "cn_ship_number": "SF123"
                },
                "domestic_shipments": [
                    { "ship_company": "佐川", "ship_number": "SGW-1" },
                    { "ship_company": "ヤマト", "ship_number": "YMT-2" }
                ],
                "intl_shipments": [
                    { "intl_number": "INTL-9", "intl_status": "运输中", "intl_fee": "30", "intl_qty": 1, "intl_weight": "1.2", "comment": "test" }
                ]
            },
            {
                "id": 12,
                "source_type": "jp_stock",
                "purchase_status": "已发日本",
                "item_code": "B-002",
                "jp_warehouse_id": "JPW-77",
                "product_title": "商品乙",
                "item_option": "size:L",
                "chinese_option": "大号",
                "quantity": 1,
                "weight": "0.800",
                "material": "polyester",
                "amount": "2980",
                "main_image": "",
                "jp_shipment": {
                    "out_status": "待分配",
                    "assignee": "",
                    "operator": "",
                    "out_time": null,
                    "location": "",
                    "out_no": "",
                    "out_cost": ""
                },
                "intl_shipments": []
            }
        ],
        "logs": [
            {
                "created_at": "2024-01-02 11:00:00",
                "operator": "系统",
                "action_type": "货源判定",
                "field_name": "source_type",
                "old_value": "",
                "new_value": "cn_purchase",
                "ip": "127.0.0.1"
            }
        ]
    });

    let mut ctx = Context::new();
    ctx.insert("order", &order);
    ctx.insert("view", &view);
    ctx.insert("tenant_name", &"测试租户");
    ctx.insert(
        "purchase_status_options",
        &json!(["国内采购-已采购", "已发日本", "待处理"]),
    );
    ctx
}

fn tenant_shell_context(active_nav: &str) -> Context {
    let mut ctx = Context::new();
    ctx.insert("tenant_name", &"测试租户");
    ctx.insert("tenant_id", &7i64);
    ctx.insert("active_nav", &active_nav);
    ctx.insert("active_platform", &Option::<String>::None);
    ctx.insert("purchase_count", &4);
    ctx.insert("jpstock_count", &2);
    ctx.insert(
        "platform_menu",
        &json!([
            { "code": "y", "name": "Yahoo", "state": "Normal" },
            { "code": "r", "name": "Rakuten", "state": "Normal" },
            { "code": "m", "name": "Mercari", "state": "Locked" }
        ]),
    );
    ctx
}

fn render_optional_template(tera: &Tera, template_name: &str, ctx: &Context) -> Option<String> {
    if !tera.get_template_names().any(|name| name == template_name) {
        eprintln!("{template_name} 尚不存在，跳过约定上下文渲染检查");
        return None;
    }

    Some(
        tera.render(template_name, ctx)
            .unwrap_or_else(|err| panic!("{template_name} 应能使用约定上下文渲染: {err}")),
    )
}

fn assert_contains_any(html: &str, candidates: &[&str], message: &str) {
    assert!(
        candidates.iter().any(|candidate| html.contains(candidate)),
        "{message}，候选片段: {candidates:?}"
    );
}

fn assert_tenant_management_style(html: &str, page_title: &str) {
    assert!(
        html.contains("tenant-sidebar"),
        "租户页应继承 tenant/layout.html"
    );
    assert!(html.contains("tenant-main"), "租户页应渲染主内容壳");
    assert!(
        html.contains("page-head"),
        "{page_title} 应复用页面头部样式"
    );
    assert_contains_any(
        html,
        &[
            r#"class="panel"#,
            r#"class="filter"#,
            r#"class="grid"#,
            r#"class="stats"#,
        ],
        &format!("{page_title} 应复用既有 panel/filter/grid/stats 样式体系"),
    );
}

fn tenant_staff_context() -> Context {
    let mut ctx = tenant_shell_context("staff");
    let store_options = json!([
        {
            "id": 10,
            "platform": "y",
            "platform_name": "Yahoo",
            "dpqz": "y_shop",
            "dpquancheng": "Yahoo测试店",
            "is_hidden": false
        },
        {
            "id": 11,
            "platform": "r",
            "platform_name": "Rakuten",
            "dpqz": "r_shop",
            "dpquancheng": "乐天测试店",
            "is_hidden": false
        }
    ]);
    let staff_users = json!([
        {
            "id": 101,
            "username": "buyer01",
            "role": "buyer",
            "role_value": "buyer",
            "role_label": "采购",
            "is_company_admin": false,
            "is_active": true,
            "dpqz": "y_shop",
            "dpquancheng": "Yahoo测试店",
            "scope_label": "Yahoo测试店",
            "all_stores": false,
            "selected_store_ids": [10],
            "selected_store_ids_csv": "10",
            "selected_permissions_csv": "orders_view,orders_edit,purchase_manage",
            "permissions": [
                { "key": "orders_view", "label": "查看订单", "checked": true },
                { "key": "orders_edit", "label": "编辑订单", "checked": true },
                { "key": "import_orders", "label": "导入订单", "checked": false },
                { "key": "purchase_manage", "label": "采购管理", "checked": true },
                { "key": "export_orders", "label": "导出订单", "checked": false },
                { "key": "manage_staff", "label": "员工管理", "checked": false },
                { "key": "manage_stores", "label": "店铺管理", "checked": false }
            ],
            "created_at": "2026-06-16 09:00:00",
            "updated_at": "2026-06-16 10:00:00"
        },
        {
            "id": 102,
            "username": "service01",
            "role": "service_staff",
            "role_value": "service",
            "role_label": "客服",
            "is_company_admin": false,
            "is_active": false,
            "dpqz": "y_shop,r_shop",
            "dpquancheng": "Yahoo测试店,乐天测试店",
            "scope_label": "Yahoo测试店、乐天测试店",
            "all_stores": false,
            "selected_store_ids": [10, 11],
            "selected_store_ids_csv": "10,11",
            "selected_permissions_csv": "orders_view",
            "permissions": [
                { "key": "orders_view", "label": "查看订单", "checked": true },
                { "key": "orders_edit", "label": "编辑订单", "checked": false },
                { "key": "import_orders", "label": "导入订单", "checked": false },
                { "key": "purchase_manage", "label": "采购管理", "checked": false },
                { "key": "export_orders", "label": "导出订单", "checked": false },
                { "key": "manage_staff", "label": "员工管理", "checked": false },
                { "key": "manage_stores", "label": "店铺管理", "checked": false }
            ],
            "created_at": "2026-06-15 09:00:00",
            "updated_at": "2026-06-15 10:00:00"
        },
        {
            "id": 103,
            "username": "admin01",
            "role": "company_admin",
            "role_value": "buyer",
            "role_label": "公司管理员",
            "is_company_admin": true,
            "is_active": true,
            "dpqz": "",
            "dpquancheng": "全部店铺",
            "scope_label": "全部店铺",
            "all_stores": true,
            "selected_store_ids": [],
            "selected_store_ids_csv": "",
            "selected_permissions_csv": "orders_view,orders_edit,import_orders,purchase_manage,export_orders,manage_staff,manage_stores",
            "permissions": [
                { "key": "orders_view", "label": "查看订单", "checked": true },
                { "key": "orders_edit", "label": "编辑订单", "checked": true },
                { "key": "import_orders", "label": "导入订单", "checked": true },
                { "key": "purchase_manage", "label": "采购管理", "checked": true },
                { "key": "export_orders", "label": "导出订单", "checked": true },
                { "key": "manage_staff", "label": "员工管理", "checked": true },
                { "key": "manage_stores", "label": "店铺管理", "checked": true }
            ],
            "created_at": "2026-06-14 09:00:00",
            "updated_at": "2026-06-14 10:00:00"
        }
    ]);

    ctx.insert("staff_users", &staff_users);
    ctx.insert("users", &staff_users);
    ctx.insert("store_options", &store_options);
    ctx.insert("stores", &store_options);
    ctx.insert(
        "role_options",
        &json!([
            { "value": "buyer", "label": "采购账号", "hint": "采购" },
            { "value": "service", "label": "客服账号", "hint": "客服" },
            { "value": "item_checker", "label": "品检账号", "hint": "品检" }
        ]),
    );
    ctx.insert(
        "permission_options",
        &json!([
            { "key": "orders_view", "label": "查看订单", "group": "订单", "checked": true },
            { "key": "orders_edit", "label": "编辑订单", "group": "订单", "checked": true },
            { "key": "import_orders", "label": "导入订单", "group": "工具", "checked": false },
            { "key": "purchase_manage", "label": "采购管理", "group": "履约", "checked": false },
            { "key": "export_orders", "label": "导出订单", "group": "数据", "checked": false },
            { "key": "manage_staff", "label": "员工管理", "group": "管理", "checked": false },
            { "key": "manage_stores", "label": "店铺管理", "group": "管理", "checked": false }
        ]),
    );
    ctx.insert(
        "filters",
        &json!({
            "q": "",
            "role": "",
            "status": "all",
            "store_id": ""
        }),
    );
    ctx.insert(
        "staff_stats",
        &json!({
            "total": 3,
            "active": 2,
            "inactive": 1,
            "company_admins": 1,
            "restricted": 2
        }),
    );
    ctx.insert("staff_count", &2);
    ctx.insert("csrf_token", &"test-csrf");
    ctx
}

fn tenant_stores_context() -> Context {
    let mut ctx = tenant_shell_context("stores");
    let stores = json!([
        {
            "id": 10,
            "platform": "y",
            "platform_name": "Yahoo",
            "platform_label": "Yahoo",
            "dpqz": "y_shop",
            "dpquancheng": "Yahoo测试店",
            "is_hidden": false,
            "has_rms_credentials": false,
            "masked_service_secret": "未保存",
            "masked_license_key": "未保存",
            "rms_credentials_updated_at": null,
            "last_sync_at": null,
            "last_sync_status": null,
            "last_sync_message": null,
            "order_count": 23,
            "staff_count": 2,
            "created_at": "2026-06-16 09:00:00",
            "updated_at": "2026-06-16 10:00:00"
        },
        {
            "id": 11,
            "platform": "r",
            "platform_name": "Rakuten",
            "platform_label": "乐天",
            "dpqz": "r_shop",
            "dpquancheng": "乐天测试店",
            "is_hidden": true,
            "has_rms_credentials": true,
            "masked_service_secret": "abcd****wxyz",
            "masked_license_key": "lic1****lic9",
            "rms_credentials_updated_at": "2026-06-15 10:00:00",
            "last_sync_at": "2026-06-16 11:00:00",
            "last_sync_status": "ready",
            "last_sync_message": "本地凭证检查通过",
            "order_count": 0,
            "staff_count": 1,
            "created_at": "2026-06-15 09:00:00",
            "updated_at": "2026-06-15 10:00:00"
        }
    ]);

    ctx.insert("stores", &stores);
    ctx.insert("store_options", &stores);
    ctx.insert("flash", &Option::<String>::None);
    ctx.insert("error", &Option::<String>::None);
    ctx.insert(
        "platform_options",
        &json!([
            { "code": "y", "name": "Yahoo", "enabled": true },
            { "code": "r", "name": "Rakuten", "enabled": true },
            { "code": "m", "name": "Mercari", "enabled": false }
        ]),
    );
    ctx.insert(
        "filters",
        &json!({
            "platform": "",
            "visibility": "all",
            "q": ""
        }),
    );
    ctx.insert(
        "store_stats",
        &json!({
            "total": 2,
            "visible": 1,
            "hidden": 1,
            "bound_staff": 3
        }),
    );
    ctx.insert("csrf_token", &"test-csrf");
    ctx
}

fn tenant_import_context() -> Context {
    let mut ctx = tenant_shell_context("import");
    let store_options = json!([
        {
            "id": 10,
            "platform": "y",
            "platform_name": "Yahoo",
            "dpqz": "y_shop",
            "dpquancheng": "Yahoo测试店",
            "is_hidden": false
        },
        {
            "id": 11,
            "platform": "r",
            "platform_name": "Rakuten",
            "dpqz": "r_shop",
            "dpquancheng": "乐天测试店",
            "is_hidden": false
        }
    ]);

    ctx.insert("store_options", &store_options);
    ctx.insert("stores", &store_options);
    ctx.insert(
        "store",
        &json!({
            "id": 11,
            "platform": "r",
            "platform_label": "乐天",
            "dpqz": "r_shop",
            "dpquancheng": "乐天测试店",
            "is_hidden": false,
            "has_rms_credentials": true,
            "masked_service_secret": "abcd****wxyz",
            "masked_license_key": "lic1****lic9",
            "rms_credentials_updated_at": "2026-06-15 10:00:00",
            "last_sync_at": "2026-06-16 11:00:00",
            "last_sync_status": "ready",
            "last_sync_message": "本地凭证检查通过",
            "created_at": "2026-06-15 09:00:00",
            "updated_at": "2026-06-16 10:00:00"
        }),
    );
    ctx.insert(
        "report",
        &json!({
            "store_id": 11,
            "store_name": "乐天测试店",
            "platform": "r",
            "ok": true,
            "status": "ready",
            "message": "本地凭证检查通过；当前按钮只生成任务报告，未访问乐天 RMS 外网。",
            "checked_steps": ["确认店铺平台为乐天 r", "检查 RMS serviceSecret", "检查 RMS licenseKey"]
        }),
    );
    ctx.insert("flash", &Option::<String>::None);
    ctx.insert("error", &Option::<String>::None);
    ctx.insert(
        "platform_options",
        &json!([
            {
                "code": "y",
                "name": "Yahoo",
                "enabled": true,
                "item_code_field": "ItemId"
            },
            {
                "code": "r",
                "name": "Rakuten",
                "enabled": true,
                "item_code_field": "ItemId"
            },
            {
                "code": "w",
                "name": "Wowma",
                "enabled": false,
                "item_code_field": "itemCode"
            }
        ]),
    );
    ctx.insert("selected_platform", &"y");
    ctx.insert("selected_store_id", &10i64);
    ctx.insert(
        "upload_constraints",
        &json!({
            "max_size_mb": 20,
            "accepted_extensions": ["csv", "xlsx"]
        }),
    );
    ctx.insert(
        "required_columns",
        &json!(["orderId", "ItemId", "ShipName", "ShipPhoneNumber", "cdate"]),
    );
    ctx.insert(
        "mapping_preview",
        &json!([
            { "source": "orderId", "target": "orders.platform_order_id" },
            { "source": "ItemId", "target": "order_items.item_code" },
            { "source": "ShipName", "target": "orders.customer_name" },
            { "source": "cdate", "target": "orders.imported_at" }
        ]),
    );
    ctx.insert(
        "recent_imports",
        &json!([
            {
                "id": 501,
                "platform": "y",
                "platform_name": "Yahoo",
                "store_id": 10,
                "store_name": "Yahoo测试店",
                "file_name": "Yahoo订单样例.csv",
                "status": "completed",
                "status_label": "已完成",
                "imported_count": 120,
                "error_count": 0,
                "operator": "admin01",
                "created_at": "2026-06-16 11:00:00"
            },
            {
                "id": 502,
                "platform": "r",
                "platform_name": "Rakuten",
                "store_id": 11,
                "store_name": "乐天测试店",
                "file_name": "rakuten-error.xlsx",
                "status": "failed",
                "status_label": "失败",
                "imported_count": 0,
                "error_count": 3,
                "operator": "buyer01",
                "created_at": "2026-06-15 11:00:00"
            }
        ]),
    );
    ctx.insert(
        "import_stats",
        &json!({
            "today_files": 2,
            "today_orders": 120,
            "failed_jobs": 1,
            "supported_platforms": 2
        }),
    );
    ctx.insert("csrf_token", &"test-csrf");
    ctx
}

#[test]
fn all_templates_parse() {
    // Tera::new 在解析阶段即报语法错误；成功即说明 layout 与订单块模板语法正确。
    let tera = Tera::new("src/templates/**/*.html").expect("模板应能被 Tera 解析");
    let names: Vec<&str> = tera.get_template_names().collect();
    assert!(
        names.iter().any(|n| n.contains("layout.html")),
        "应加载到 layout.html，实际: {names:?}"
    );
    assert!(
        names.iter().any(|n| n.contains("order_block.html")),
        "应加载到 order_block.html，实际: {names:?}"
    );
}

#[test]
fn order_block_renders_for_platform_view() {
    let tera = Tera::new("src/templates/**/*.html").expect("解析模板");
    let html = tera
        .render("partials/order_block.html", &sample_context("platform"))
        .expect("平台视图应能渲染订单块");

    // 平台视图：A 区客户信息显示，且货源列为可改判下拉。
    assert!(html.contains("A 区 · 客户信息"), "平台视图应渲染 A 区");
    assert!(html.contains("山田太郎"), "应包含客户名");
    assert!(html.contains("<select"), "平台视图 B1 区货源应为下拉可改判");

    // 多子商品逐行重复：两个 item_code 都出现。
    assert!(
        html.contains("A-001") && html.contains("B-002"),
        "应逐行渲染两个子商品"
    );

    // B2 区按货源分流：cn_purchase 采购信息 + jp_stock 出库信息均渲染。
    assert!(
        html.contains("1688-XYZ"),
        "cn_purchase 子商品应渲染采购单号"
    );
    assert!(html.contains("出库状态"), "jp_stock 子商品应渲染出库信息");

    // C 区国际物流 + 操作日志面板。
    assert!(html.contains("INTL-9"), "应渲染国际物流运单号");
    assert!(
        html.contains("操作日志") && html.contains("货源判定"),
        "应渲染操作日志面板"
    );

    // HTML 转义：Tera 默认对 .html 自动转义（此处样本无危险字符，确保结构完整即可）。
    assert!(html.contains("order-block"), "应输出订单块根元素");
}

#[test]
fn order_block_renders_for_purchase_and_jpstock_views() {
    let tera = Tera::new("src/templates/**/*.html").expect("解析模板");

    // 采购视图：A 区默认不渲染，货源为只读标签（非下拉）。
    let purchase = tera
        .render("partials/order_block.html", &sample_context("purchase"))
        .expect("采购视图应能渲染");
    assert!(
        !purchase.contains("A 区 · 客户信息"),
        "采购视图默认不渲染 A 区"
    );
    assert!(purchase.contains("source-tag"), "采购视图货源应为只读标签");

    // 日本仓视图：未分配行高亮类存在。
    let jpstock = tera
        .render("partials/order_block.html", &sample_context("jpstock"))
        .expect("日本仓视图应能渲染");
    assert!(jpstock.contains("row-unassigned"), "未分配出库行应高亮");
}

#[test]
fn admin_tenant_edit_page_renders_as_standalone_form() {
    let tera = Tera::new("src/templates/**/*.html").expect("解析模板");
    let mut ctx = Context::new();
    ctx.insert(
        "tenant",
        &json!({
            "id": 7,
            "company_name": "西阵科技有限公司",
            "company_short_name": "西阵科技",
            "contact_name": "张三",
            "contact_phone": "09000000000",
            "contact_email": "ops@example.com",
            "contact_wechat": "xizhen",
            "address": "东京",
            "remark": "重点客户",
            "subdomain": "xizhen",
            "db_label": "127.0.0.1:3306/xizhends_tenant",
            "plan": "pro",
            "authorized_platforms": ["y", "r"],
            "staff_count": 5,
            "status": "active",
            "created_at": "2026-06-16 10:00:00"
        }),
    );

    let html = tera
        .render("admin/tenant_edit.html", &ctx)
        .expect("租户编辑页应能渲染");
    assert!(html.contains("编辑租户"));
    assert!(html.contains("action=\"/admin/tenants/7\""));
    assert!(html.contains("留空则保持现有连接"));
    assert!(html.contains("127.0.0.1:3306"));
    assert!(html.contains("xizhends_tenant"));
}

#[test]
fn admin_tenants_list_links_to_edit_page_without_inline_popup() {
    let tera = Tera::new("src/templates/**/*.html").expect("解析模板");
    let mut ctx = Context::new();
    ctx.insert(
        "tenants",
        &json!([
            {
                "id": 7,
                "company_name": "西阵科技有限公司",
                "company_short_name": "西阵科技",
                "contact_name": "张三",
                "contact_phone": "09000000000",
                "contact_email": "ops@example.com",
                "contact_wechat": "xizhen",
                "address": "东京",
                "remark": "重点客户",
                "subdomain": "xizhen",
                "db_label": "127.0.0.1:3306/xizhends_tenant",
                "plan": "pro",
                "authorized_platforms": ["y", "r"],
                "staff_count": 5,
                "status": "active",
                "created_at": "2026-06-16 10:00:00"
            }
        ]),
    );
    ctx.insert(
        "filters",
        &json!({
            "status": "",
            "plan": "",
            "q": ""
        }),
    );
    ctx.insert("total_tenants", &1);

    let html = tera
        .render("admin/tenants.html", &ctx)
        .expect("租户列表页应能渲染");
    assert!(html.contains("href=\"/admin/tenants/7/edit\""));
    assert!(!html.contains("<details class=\"edit-pop\""));
    assert!(!html.contains("<form class=\"edit-form\""));
}

#[test]
fn tenant_staff_management_template_renders_when_present() {
    let tera = Tera::new("src/templates/**/*.html").expect("解析模板");
    let Some(html) = render_optional_template(&tera, "tenant/users.html", &tenant_staff_context())
    else {
        return;
    };

    assert_tenant_management_style(&html, "员工管理页");
    assert_contains_any(
        &html,
        &["员工管理", "员工", "staff", "users"],
        "员工管理页应显示页面主题",
    );
    assert!(html.contains("buyer01"), "应渲染员工登录名");
    assert!(html.contains("service01"), "应渲染客服登录名");
    assert!(html.contains("Yahoo测试店"), "应渲染员工可见店铺范围");
    assert_contains_any(
        &html,
        &["查看订单", "orders_view", "import_orders", "导入订单"],
        "应渲染权限项或权限字段",
    );
    assert_contains_any(
        &html,
        &["name=\"username\"", "name='username'"],
        "员工新增/编辑表单应包含 username 字段",
    );
    assert_contains_any(
        &html,
        &["name=\"role\"", "name='role'"],
        "员工新增/编辑表单应包含 role 字段",
    );
    assert_contains_any(
        &html,
        &["name=\"store_ids\"", "name='store_ids'", "store_scope"],
        "员工新增/编辑表单应包含店铺范围字段",
    );
}

#[test]
fn tenant_store_management_template_renders_when_present() {
    let tera = Tera::new("src/templates/**/*.html").expect("解析模板");
    let Some(html) =
        render_optional_template(&tera, "tenant/stores.html", &tenant_stores_context())
    else {
        return;
    };

    assert_tenant_management_style(&html, "店铺管理页");
    assert_contains_any(
        &html,
        &["店铺管理", "店铺", "stores"],
        "店铺管理页应显示页面主题",
    );
    assert!(html.contains("y_shop"), "应渲染店铺缩写 dpqz");
    assert!(html.contains("Yahoo测试店"), "应渲染店铺全称 dpquancheng");
    assert_contains_any(&html, &["Rakuten", "r_shop"], "应渲染第二个店铺");
    assert_contains_any(
        &html,
        &["隐藏", "is_hidden", "visibility"],
        "应渲染隐藏店铺状态或筛选",
    );
    assert_contains_any(
        &html,
        &["action=\"/settings/stores/rakuten\"", "平台代码 r"],
        "店铺新增表单应固定创建乐天店铺",
    );
    assert_contains_any(
        &html,
        &["name=\"dpqz\"", "name='dpqz'"],
        "店铺新增/编辑表单应包含 dpqz 字段",
    );
    assert_contains_any(
        &html,
        &["name=\"dpquancheng\"", "name='dpquancheng'"],
        "店铺新增/编辑表单应包含 dpquancheng 字段",
    );
}

#[test]
fn tenant_import_template_renders_when_present() {
    let tera = Tera::new("src/templates/**/*.html").expect("解析模板");
    let Some(html) =
        render_optional_template(&tera, "tenant/store_import.html", &tenant_import_context())
    else {
        return;
    };

    assert_tenant_management_style(&html, "导入页");
    assert_contains_any(
        &html,
        &["导入订单", "订单导入", "导入", "import"],
        "导入页应显示页面主题",
    );
    assert!(html.contains("乐天测试店"), "应渲染可导入店铺");
    assert!(
        html.contains("serviceSecret"),
        "应渲染 RMS serviceSecret 字段"
    );
    assert!(html.contains("licenseKey"), "应渲染 RMS licenseKey 字段");
    assert!(html.contains("RMS WEB SERVICE"), "应渲染乐天 RMS 申请说明");
    assert!(html.contains("searchOrder"), "应说明后续同步流程");
    assert_contains_any(
        &html,
        &["手动导入订单", "同步订单"],
        "导入页应包含手动导入和同步入口",
    );
    assert_contains_any(
        &html,
        &["name=\"service_secret\"", "name='service_secret'"],
        "凭证表单应包含 service_secret 字段",
    );
    assert_contains_any(
        &html,
        &["name=\"license_key\"", "name='license_key'"],
        "凭证表单应包含 license_key 字段",
    );
}
