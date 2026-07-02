# 发货单自定义导出模板 设计文档

日期：2026-07-03
状态：设计已获用户确认，待实施
取代：`docs/audit-2026-07-02-fix-tasks.md` 第 7 项（"outexcel 平台专用发货单导出格式覆盖不全"）的原定"逐个复刻老模板"方案

## 背景与目标

老系统有 10+ 种写死的 `outexcel*` 发货单导出模板（按货代/平台各一个 PHP 文件）。php-saas 目前只复刻了其中 5 个（riya/盛欣/万达/Qoo10/Wowma），全部是代码内写死的表头和字段映射（`PlatformExportService`）。

业务方决定**不再逐个复刻剩余老模板**，改为做一套"用户自定义导出列"机制：

- 租户管理员从系统提供的字段清单中勾选要导出的列；
- 可拖动/调整列顺序、修改每列显示名；
- 可添加"固定值列"（自定义表头 + 固定输出内容，如"订购国家=JP"）；
- 导出按钮按保存的模板配置生成文件。

这样各公司对接不同货代时自行配置格式，无需改代码。

## 已确认的关键决策（业务方 2026-07-03）

1. **字段来源**：预定义字段字典为主（友好中文名 + 内置取值逻辑），同时允许高级用户添加"原始字段路径"列——两者结合。
2. **模板归属**：租户级共享，由**租户管理员**配置，每个公司各有自己的模板集；员工用现有导出权限使用模板。
3. **老模板去留**：5 个写死模板**转成预置模板数据**，与自定义模板走同一套导出引擎（统一引擎，不留两套逻辑）。
4. **文件格式**：支持 XLSX（图片列真实嵌入图片）；每个模板带"格式"属性（CSV / XLSX），默认 XLSX。预置 Qoo10/Wowma 模板保持 CSV（这两种文件要回传上传到平台后台，平台只收 CSV）。

## 架构

```
TenantController（路由/权限/HTTP 收发）
   ├─ ExportTemplateService（模板 CRUD + 校验 + 预置模板定义）
   │     └─ tenantSettings['export_templates']（JsonStore/MysqlStore 现有接口，无新表）
   ├─ PlatformExportService（统一渲染引擎：template + orders → headers/rows）
   │     └─ ExportFieldRegistry（可导出字段注册表：key/中文名/分组/resolver/类型）
   └─ SpreadsheetExportService（XLSX 输出 + 图片嵌入，复用采购表已验证逻辑）
```

### 组件职责

| 组件 | 职责 | 依赖 |
|------|------|------|
| `ExportFieldRegistry`（新） | 全部可导出字段的唯一清单：`key` → 中文名/分组/取值 resolver/类型（text·image·date） | 无（纯函数） |
| `ExportTemplateService`（新） | 模板增删改查、结构校验、预置模板常量、旧 `variant` 参数兼容映射 | StoreInterface（tenantSettings） |
| `PlatformExportService`（改造） | `render(template, orders)` 统一渲染；三种列类型取值；CSV 防注入 | ExportFieldRegistry |
| `SpreadsheetExportService`（扩展） | 新增发货单 XLSX 方法：表头样式、图片列嵌图 | PhpSpreadsheet |
| `TenantController`（扩展） | 模板管理页/编辑页/保存/删除路由 + 导出路由改造 | 上述服务 |

## 字段注册表

每个字段：`key`（稳定标识，形如 `组.名`）、默认中文显示名、分组、resolver（接收 order/item/customer 上下文返回值）、类型。

字段分组与清单（覆盖现有 5 个模板用到的全部取值逻辑）：

- **订单**：订单号（platform_order_id）、订单明细ID（order_detail_id）、店铺名、订单时间、平台。
- **收件人**：姓名、电话（无前导 0 时自动补 0）、邮编（不足 7 位左补 0）、拼接地址（都道府县+市区町村+地址1+地址2，空则回退 address）、都道府县、市区町村、地址1、地址2。
- **商品**：商品标题、规格（option）、中文规格（chinese_option 回退 option）、数量（最小 1）、重量、材质、备注（comment）、转运备注（tranship_comment）。
- **物流**：国内快递公司、国内运单号、国内单号拼接（公司+空格+单号）、国际运单号（intl_number 回退 ship_number）、国际运单状态（intl_status 回退 logistics）、物流轨迹/签收地（logistic_trace）。
- **金额**：单价、USD 折算单价（÷2÷145，≤0 输出空）、采购金额（amount）、国内运费（cn_amount）、佣金额（com_amount）。
- **图片**：商品图片（sku_image → main_image → image 回退链；类型=image，XLSX 时嵌图，CSV 时输出 URL/路径）。
- **生成值**：今天（m-d）、今天（Y/m/d）、Wowma 运送公司代码（按运单号前缀映射表推断，无匹配回退快递公司名）。

老模板中的特殊转换（电话补 0、邮编补齐、USD 折算、Wowma 代码推断等）全部收敛为注册表内的具名字段，自定义模板可直接勾选复用。新增可导出字段只改注册表一处。

## 模板数据结构

存储位置：`tenantSettings` 的 `export_templates` 键（数组）。JsonStore 与 MysqlStore 已实现 `tenantSettings`/`saveTenantSettings`，**无需数据库迁移**，双驱动行为天然一致。

```json
{
  "id": "tpl_a1b2c3",
  "name": "盛欣发货单",
  "format": "xlsx",
  "columns": [
    {"type": "field", "key": "order.platform_order_id", "label": "订单号"},
    {"type": "const", "label": "订购国家", "value": "JP"},
    {"type": "raw",   "path": "item.tabaono", "label": "淘宝单号"}
  ],
  "created_at": 1751500000,
  "updated_at": 1751500000
}
```

三种列类型：

| type | 含义 | 字段 |
|------|------|------|
| `field` | 注册表字段（可改显示名） | `key`（必须在注册表内）、`label`（缺省用字段默认名） |
| `const` | 固定值列（自定义表头 + 固定内容） | `label`、`value` |
| `raw` | 原始路径列（高级） | `path`（必须以 `order.` / `item.` / `customer.` 开头）、`label` |

### 预置模板

现有 5 个写死模板改写为上述 JSON 结构的代码内常量（`ExportTemplateService` 内定义），id 为 `builtin_riya` / `builtin_sx` / `builtin_wd` / `builtin_qoo10` / `builtin_wowma`：

- 列表中标注"系统预置"，**只读不可删不可改**；
- 管理员可一键"复制为自定义模板"后修改；
- `builtin_qoo10` / `builtin_wowma` 的 `format` 为 `csv`（平台回传格式），其余预置为 `xlsx`；
- 旧导出 URL 的 `variant=riya` 等参数兼容映射到对应 `builtin_*` 模板 id，不断链。

## 导出引擎

`PlatformExportService::render(template, orders)`：

- 行粒度与现状一致：每个订单的每个商品项一行（order × item）；
- 逐列按 `type` 取值：`field` → 注册表 resolver；`const` → 固定 `value`；`raw` → 点路径从 order/item/customer 数组取值（缺失输出空字符串）；
- 输出 `{headers, rows, imageColumns}`（imageColumns 标记哪些列是图片类型，供 XLSX 嵌图用）。

输出管道：

- `format=csv`：沿用现有 `sendCsvDataset`（含 `safeCell` 公式注入防护）；
- `format=xlsx`：`SpreadsheetExportService` 新增方法，复用采购表导出（`c28e4e4`/`508825d`）已验证的 PhpSpreadsheet 嵌图逻辑——本地图片（租户目录内）直接嵌入，远程 http(s) 图片下载（5 秒超时），失败降级为在单元格输出 URL 文本，不中断整个导出。

## 界面与路由

### 模板管理

入口：「非 Excel 导入导出」页（`import_export_non_excel.php`）增加"发货单导出模板"区块——列出预置 + 本公司自定义模板，提供 新建 / 复制预置 / 编辑 / 删除（删除需确认，预置不可删）。

权限：

- 模板增删改：要求 **"公司设置"** 权限（租户管理员）；
- 使用模板导出：现有 **"导入导出"** 权限，员工照常导出。

### 模板编辑器

新视图 `app/Views/tenant/export_template_edit.php`，零框架、纯 PHP 表单 + 原生 JS：

```
┌─────────────────────┬──────────────────────────────────┐
│ 可用字段（按组折叠）   │ 已选列（从上到下 = 导出从左到右）    │
│ ▾ 订单               │ 1. 订单号      [显示名:订单号] ↑↓ ✕│
│   ☑ 订单号           │ 2. 订购国家    [固定值:JP]     ↑↓ ✕│
│   ☐ 店铺名           │ 3. 国内单号    [显示名:快递号] ↑↓ ✕│
│ ▸ 收件人  ▸ 商品      │ 4. 商品图片    [显示名:图片]   ↑↓ ✕│
│ ▸ 物流    ▸ 图片      │                                  │
│ [+ 添加固定值列]      │ 格式: (●) XLSX嵌图  ( ) CSV       │
│ [+ 添加原始字段列]     │ [保存模板]  [导出预览(前3行)]     │
└─────────────────────┴──────────────────────────────────┘
```

- 左侧勾选字段 → 追加到右侧已选列；
- 右侧每列：改显示名、上移/下移排序、删除；
- "添加固定值列"：表头名 + 固定内容两个输入；
- "添加原始字段列"：折叠的高级项，输入 `item.xxx` 路径；
- "导出预览"：用当前筛选条件的前 3 行数据实时渲染预览，避免盲配。

### 导出入口

导出区块改为：模板下拉框（预置 + 自定义）+ 现有平台/店铺/日期筛选 + 导出按钮 → `exportPlatformSpecial()`，参数从 `variant` 扩展为 `template_id`（旧 `variant` 值兼容映射）。

### 新增路由（`public/index.php`）

- `GET  /export-templates/edit`（新建/编辑表单，`?id=` 区分）
- `POST /export-templates/save`
- `POST /export-templates/delete`
- `GET  /export-templates/preview`（前 3 行预览）
- 现有 `GET /export/platform-special` 改造支持 `template_id`

## 校验、安全与错误处理

服务端保存时校验（`ExportTemplateService`）：

- 模板数上限 30 / 每模板列数上限 50；
- 模板名、列显示名长度 ≤ 64，非空；
- `field.key` 必须存在于注册表；`raw.path` 必须以 `order.` / `item.` / `customer.` 开头；
- `format` 只接受 `csv` / `xlsx`；
- 校验失败返回带具体错误消息的表单回显，不静默丢弃。

安全：

- 所有用户输入的表头名/固定值在视图输出经 `e()` 转义；
- 固定值列内容与 `raw` 取值导出时同样过 `safeCell`（防止管理员配置 `=CMD()` 类公式注入）；
- 嵌图仅允许租户自己目录内的本地文件或 http(s) URL；
- 模板管理全部要求"公司设置"权限，防止普通员工篡改公司模板。

## 测试

遵循项目现有 `tests/*.php` 脚本模式：

1. `ExportFieldRegistry` 每个 resolver 的单测（电话补 0、邮编补齐、USD 折算、Wowma 代码推断、图片回退链等）；
2. 三种列类型（field/const/raw）渲染测试 + 校验规则测试（超限/非法 key/非法 path 拒绝）；
3. **回归对齐测试**：5 个预置模板经统一引擎的输出，与改造前老 `PlatformExportService` 各 variant 方法输出逐列一致（保证统一引擎不改变现有行为）；
4. XLSX 嵌图 smoke 测试（仿照 `tests/purchase_xlsx_workflow_test.php`）。

## 明确不做（YAGNI）

- 不做拖拽排序（上移/下移按钮足够，零框架下更可靠）；
- 不做按员工隔离的模板（业务方已确认租户级共享）；
- 不做导出格式之外的样式自定义（列宽/颜色/字体等，预置模板内部可带必要样式）；
- 不做老系统剩余 outexcel 变体的逐个复刻（EDM/佐川 EDI 等若将来需要，由管理员用本机制自行配置，配不出来的再评估）。

## 对审计清单的影响

`docs/audit-2026-07-02-fix-tasks.md` 第 7 项的验收口径由"为遗漏的每种格式增加对应导出方法"**变更为本设计**；实施完成后在该清单第 7 项注明方案变更与 commit。
