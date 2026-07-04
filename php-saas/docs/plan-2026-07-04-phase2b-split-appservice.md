# 方案：架构重构阶段 2b —— 拆分 AppService（门面过渡，调用方零改动）

> 日期：2026-07-04。状态：方案定稿，待 Codex 实施。
> 属于 [plan-2026-07-04-architecture-roadmap.md](plan-2026-07-04-architecture-roadmap.md) 阶段 2 的收尾子任务。
> 前提：阶段 2 已提交，git status 干净。

## 目标

- `app/Services/AppService.php`（1593 行，大杂烩）按领域把方法体搬到 3 个独立 Service；
- **AppService 保留为门面**：继续实现现有全部 public 方法签名，内部委托新 Service；
- 全部调用方（13 个控制器 + ExpressLogisticsService / ShippingAnomalyService /
  WaybillCheckService）**零改动**——门面保证兼容。

## 铁律（同阶段 1/2）

- 方法体逐字搬移。私有辅助方法（无外部调用方）随所属领域整块搬走；
  public 方法在 AppService 里保留为一行委托。
- 禁止改逻辑、改签名、改返回结构。
- 验收用方法体归一化比对校验搬移完整性。

## 现状事实（已核实）

- AppService 被 5 处 `new AppService($store)` 构造：AdminController、TenantBaseController、
  ExpressLogisticsService、ShippingAnomalyService、WaybillCheckService；
  控制器里以 `$this->service->xxx()` / `$this->app->xxx()` 调用 100+ 处。
- 方法可归为 4 类：
  - **平台/菜单/租户目录/仪表盘**（轻量，留在门面）：purchaseStatuses、platformMenu、
    tenantFeatureEnabled、tenantFeatureMap、platformEnabled、enabledPlatformCodes、
    tenantPlatformNames、storesForTenant、dashboard、ordersForUser、platformNames、
    tenantsWithPlatformLabels、featureGroups、globalSearchResults、mailAccounts、
    importExportJobs(ForTenant)、mediaTasks、tenantMediaLibrary、jobDefinitions、
    auditLogs、settingsGroups、rolePermissionMatrix、logisticsRows、purchaseStats、
    logisticsUpdateStatus/Name 及其私有辅助。
  - **利润计算域** → ProfitService
  - **订单筛选域** → OrderFilterService
  - **导出数据集域** → ExportDatasetService

## 设计

### A. 三个新 Service（namespace `Xizhen\Services`）

**1) ProfitService**（构造注入 StoreInterface）
- public：`profitSummary`、`profitSummaryForOrders`
- 私有搬入：storeForProfitOrder、japanPostageForOrder、intlFeeForOrder、
  salesForProfitOrder、itemSales、firstPositiveItemMoney、positiveItemMoneyTotal、
  allocationsByQuantity、deductionFeeRatio 等利润专用辅助。

**2) OrderFilterService**（可无状态/构造注入 StoreInterface，按需）
- public：`filterOrdersForView`、`itemIdsForLogisticsUpdate`、`ordersForExport`
- 私有搬入：orderMatchesFilters、itemMatchesFilters、containsFilterValue、filterTerms、
  dateInRange、dateStartsWith、dateOnly、booleanFilterValue、usesDefaultPendingStatus、
  isDeliveredLogistics、itemMatchesLogisticsType、isLateShipItem、
  restrictOrdersToSelection、filterOrdersByTenantPlatforms、flattenItems。

**3) ExportDatasetService**（构造注入 StoreInterface + 依赖 OrderFilterService）
- public：`exportDataset`
- 私有搬入：platformExportRows、deliveryNoticeRows。

### B. 共享数值辅助

`moneyFloat`、`positiveFloat`、`intList`、`itemQuantity`、`itemsQuantity` 被多个域引用。
做成一个 `trait OrderMathHelpers`（`app/Services/Concerns/OrderMathHelpers.php`），
三个新 Service 与 AppService 门面按需 `use` 该 trait（逐字搬移方法体）。
避免跨 Service 循环依赖。

### C. AppService 门面

- 构造函数：`new AppService($store)` 内部再建 ProfitService/OrderFilterService/
  ExportDatasetService（用同一个 $store），保持 5 处调用点的构造方式不变；
- 被搬走的 public 方法改为一行委托；
- 门面预期 <800 行；调用方与被 new 的 5 处**零改动**（git diff 自证）。

### 依赖方向

AppService门面 → {ProfitService, OrderFilterService, ExportDatasetService}
ExportDatasetService → OrderFilterService
三者与门面 → OrderMathHelpers(trait)
禁止环形依赖。

## 测试与验收

- 既有 tests/*.php 全部通过（多走 JsonStore + 纯计算，天然覆盖 filter/profit 逻辑）。
- php -l 全部新文件。
- 方法体归一化比对：AppService(HEAD) 全部方法 vs（3 新 Service ∪ trait ∪ 门面），
  除委托折叠外零差异，报告方法数。
- 登录态冒烟（JSON 模式即可，本任务不涉 MySQL 特有路径）：
  订单三视图、订单详情、利润分析页、采购统计、业绩相关页、导入导出各导出入口、
  全局搜索、仪表盘首页——均正常渲染无 Fatal。
- 验收清单：
  - [ ] AppService 门面 <800 行且只含委托 + 轻量目录方法；
  - [ ] 3 个新 Service 各 <800 行，trait 独立；
  - [ ] 全部调用方与 5 处 new 零改动（git diff 确认）；
  - [ ] 方法体比对一致；测试全绿 + 冒烟全过。
