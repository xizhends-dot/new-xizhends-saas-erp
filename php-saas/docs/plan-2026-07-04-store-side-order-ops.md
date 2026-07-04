# 方案：店铺侧订单操作——按店铺导入订单 / 同步订单

> 日期：2026-07-04。状态：方案定稿，待 Codex 实施。
> 需求：店铺是订单归属主体。绑定店铺后，应在【店铺管理】页对**单个店铺**执行
> "同步订单"（平台 API 拉取）与"导入订单"（上传 CSV/XLSX），
> 而不是去全局的导入导出中心操作。

## 现状事实（已核实）

- 店铺列表 `app/Views/tenant/stores.php:52` 操作列目前只有【编辑】。
- **同步**：`OrderExportController::syncPlatformOrders()`（routes: `POST /orders/platform/sync`）
  已按 `store_id` 单店铺同步：校验店铺存在、平台匹配、`Permission::canAccessStore`
  店铺范围、`days` 1-30。平台服务来自 `PlatformOrderSyncRegistry`（乐天/Qoo10/煤炉/
  Wowma/雅虎购物/雅虎拍卖等）。唯一入口在订单页顶部（仅当前平台视图显示）。
- **导入**：`ImportExportController::importCsv()`（job=platform_orders_import）走
  `CsvImportService`；其 `storeInfo()`（CsvImportService:747）支持 `context['store_id']`
  选中店铺 + 行内店铺列名称匹配，都匹配不到时落到"{平台} 导入店铺"占位（:763）。
- 店铺行数据含 `platform`/`api_status`；控制器可拿 `platformSyncServices`（代码→名称映射）。

## 设计

### A. 店铺管理页（stores.php）操作列扩展

每行操作列改为按钮组：

1. 【编辑】——现状保留；
2. 【同步订单】——行内小表单直接 POST 到**现有** `/orders/platform/sync`：
   - hidden：tenant、platform=该店铺平台、store_id=该店铺 id、return=/stores?tenant=...；
   - `days` 下拉（1/3/7/15/30，默认 7）；
   - 显示条件：该店铺平台存在同步服务（`platformSyncServices` 含其 code）；
     API 未配置（api_status ≠ 已配置）时按钮置灰并 title 提示"请先在编辑页配置 API"；
   - 后端零改动（校验/权限/返回跳转全部现成）。
3. 【导入订单】——链接到新页 `/stores/import?id={store_id}`。

`StoreController::stores()` 需向视图追加传 `platformSyncServices`
（`$this->platformOrderSyncRegistry->names()`，参照 OrderController::orders 现有取法）。

### B. 店铺专属导入页（新）

**路由**（加入 TenantAuth 组，紧邻 /stores 系列）：
- `GET /stores/import` → `StoreController::importOrdersForm()`
- `POST /stores/import` → `StoreController::importOrders()`

**守卫**（两个方法一致）：
- `requireTenantFeature('orders.platform')`；
- `requireAnyTenantPermission(['导入导出', '订单编辑'])`；
- 店铺存在性 + `Permission::canAccessStore(当前用户, 店铺名)`——店铺范围外的员工不可导。
- ⚠️ 特意**不**要求 `import_export.center` 功能开关——本需求的核心语义就是
  店铺侧操作不依赖导入导出中心。

**表单页**（新视图 `app/Views/tenant/store_import.php`）：
- 店铺信息卡（平台/缩写/全称）+ 文件上传（CSV/XLSX，accept 与导入导出中心一致）+
  说明文案（支持列格式同"平台订单导入"）+ 提交按钮；全部输出 e() 转义 + csrf_field()。

**处理逻辑（importOrders）——复用 CsvImportService，强制归属语义**：
- 解析复用 `CsvImportService`（与 importCsv 的 platform_orders_import 分支同一套解析）；
- **归属规则（本功能的关键语义，与全局导入的差异点）**：
  1. context 的 `store_id` 固定为所选店铺、`stores` 列表只传该一家；
  2. 行内"店铺"列为空 → 归属所选店铺；
  3. 行内"店铺"列非空且与所选店铺名称/缩写不匹配 → **该行跳过**并计数，
     报告提示"N 行店铺列与所选店铺不符，已跳过"——防止把混合多店铺的文件
     误导入到单一店铺造成数据污染；
  4. Codex 实施时先读 `CsvImportService::storeInfo()`（:747-763）确认 selectedId
     与行内匹配的优先级，若现有优先级无法直接表达上述规则，
     在 context 加一个开关参数（如 `restrict_to_store_id`）在 storeInfo 内实现，
     **不得改变全局导入导出中心的现有行为**（无该参数时逻辑原样）。
- 写入与报告：复用现有写入路径与 `addImportExportLog` 审计（job 标注
  `store_orders_import`，log 内含店铺名）；完成后 redirect 回
  `/stores?tenant=...&message=导入完成摘要（成功N/更新N/跳过N）`，
  stores 页顶部已有 message 展示位（没有则加一条 notice，参照其他页）。

### C. 明确不做

- 不动全局导入导出中心的任何现有行为（含其平台订单导入）；
- 不动 syncPlatformOrders 的守卫与逻辑（其 import_export 功能门控语义问题
  记录待办，不在本次改）；
- 不做异步/队列导入（文件量大时同步处理，与导入导出中心一致）。

## 测试要求

新增 `tests/store_import_test.php`（纯 PHP，走 JsonStore + CsvImportService）：
- 构造 CSV 内容：店铺列为空的行 → 归属所选店铺；
- 店铺列=所选店铺名/缩写 → 正常导入；
- 店铺列=其他店铺名 → 跳过并计数；
- 全局导入路径（无 restrict 参数）行为与改造前一致（回归用例）。

既有 18 个测试全部通过。

## 验收清单

- [ ] 店铺列表每行可见【同步订单】（带天数下拉，平台无同步服务不显示、
      API 未配置置灰）与【导入订单】；
- [ ] 同步：点击后走现有链路，成功/失败提示与订单页入口一致；
      店铺范围外员工被拒；
- [ ] 导入：店铺列为空/匹配 → 归属该店铺；不匹配行跳过并在报告中提示；
      导入结果出现在 /stores 顶部提示与导入导出日志中；
- [ ] 全局导入导出中心行为回归不变；
- [ ] 权限矩阵：无"导入导出/订单编辑"权限的员工看不到且访问被拒；
      店铺范围限制生效；
- [ ] 全部测试通过 + 登录态冒烟（stores 页、导入页、同步 POST、
      导入 POST 成功与跳过场景）。
