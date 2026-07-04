# 方案：订单页交互回归——把订单重新放回系统中心

> 日期：2026-07-05。状态：方案定稿，待 Codex 分批实施。
> 背景：新系统重构时按"技术类型"归类功能（核价进独立页、导入导出进中心、模板进模板管理），
> 导致操作订单时动线被打散。旧系统（20251217，按平台分目录 orderr/ordery/...）是
> "以订单为中心"，核价/导入导出就近发生。本方案把这些交互回归订单/店铺页。
> 用户决策已定：①悬停单价弹核价浮层（还原旧系统）②店铺 API 按平台动态表单
> ③订单页也能按平台导入导出 ④先出完整方案再逐项实施。

## 现状与旧系统对照（已核实）

| 功能 | 旧系统 | 新系统现状 | 差距 |
|---|---|---|---|
| 单价核价 | 悬停单价 qtip 浮层，售价/运费/扣点可改实时算（`orderr/price_tooltip_ajax.php`，各平台1份） | 仅独立页 `/price-calculator` 手动输入 | 订单流内即时核价缺失 |
| 店铺 API 配置 | 按平台字段：Wowma 1(令牌码)/Yahoo 2(AppID,Secret)/Rakuten 2(serviceSecret,licenseKey)/1688 3，prompt 向导 | 单个 `api_config` 文本框填 JSON | 无平台区分与引导 |
| 导入导出 | 内嵌各平台订单页顶部（`inc_list_default.php` L573-634），平台由目录、店铺由会话定 | 集中在导入导出中心；店铺侧已加单店导入(上个任务) | 订单页无平台内嵌入口 |
| 自定义导出字段 | **旧系统无**（列硬编码） | 已实现"导出模板"(`export_template_edit.php`)，但埋在导入导出中心 | 功能在，入口不可见 |

**关键利好**：新系统 `PriceCalculatorService::calculateRow()` 的核价公式与旧系统
`price_tooltip_ajax.php` **逐字一致**（`actualIncome = 售价 × 扣点% × 汇率`、
`利润率 = 利润 / 汇率 / 售价 × 100`）。⇒ 悬停核价**后端算法零开发**，只需薄 AJAX 端点。

---

## 任务 A：订单单价悬停核价浮层（还原旧系统）

**后端**（复用现有服务，不重写算法）：
- 新 AJAX 路由 `GET /orders/ajax/price-quote`（TenantAuth 组，参照现有 `/orders/ajax/*`）；
- `OrderAjaxController::priceQuote()`：读 `item_id`（或直接收 sale_price/shipping/deduction/cost
  参数），拿该子项所属店铺的 `profit_deduction`、租户 `profit` 设置的汇率/默认运费，
  委托 `PriceCalculatorService::calculateRow()` 算出 `actualIncome/profit/profitRate/
  realProfit/realProfitRate`，返回 JSON（沿用 ErrorHandler 的 AJAX JSON 约定）；
- 运费优先级同旧系统：该订单实际国际运费（com_amount）> 租户默认运费。

**前端**（`app/Views/tenant/partials/order_block.php` + `order_detail.php` 单价单元格）：
- 单价数字包成可悬停元素，`data-item-id`（或 data-sale-price/shipping/deduction/cost）；
- `public/assets/order-ajax.js` 加轻量浮层：hover（桌面）触发 AJAX 拉核价，
  浮层内售价/运费/扣点为可编辑 input + "重新计算"按钮（改值再请求）；
  移动端降级为点击触发（用户选了"悬停"，但触屏无 hover，点击兜底）；
- 不引入 qtip 等第三方库，用原生小浮层（项目零框架惯例）；输出转义。
- 权限：无"订单查看"不显示；核价只读展示，不写库。

**测试**：`tests/price_quote_test.php`——同一组输入，priceQuote 结果与
`PriceCalculatorService::calculateRow` 一致；运费回退优先级正确。

---

## 任务 B：店铺 API 配置——按平台动态表单

**平台字段定义**（新建 `app/Services/StoreApiFieldRegistry.php`，单一权威）：

| 平台 code | 字段 | 存储 JSON 键 | 提示 |
|---|---|---|---|
| r 乐天 | serviceSecret、licenseKey | `{"Secret":..,"Key":..}` | 乐天 RMS：serviceSecret + licenseKey |
| y/yp 雅虎 | AppID(ClientID)、Secret | `{"AppID":..,"Secret":..}` | 雅虎接口 AppID + 密钥 |
| w Wowma | token | 裸串或 `{"Token":..}`（**统一为 JSON**，见下） | Wowma 店铺接口令牌码 |
| m 煤炉/q Qoo10 | 按需（无则不显示 API 区） | — | — |

- **存储统一**：旧系统 Wowma 存裸串、其他存 JSON 不一致。新系统一律存**结构化 JSON**
  到现有 `stores.api_config` 列（`MysqlStore/JsonStore` 已有该字段），
  Registry 提供 `fieldsFor(platform)` 和 `toJson(platform, input)` / `fromJson(platform, json)`。
- **向后兼容**：`fromJson` 读旧值时兼容乐天 `{Secret,Key}` 现格式，不破坏已存数据。

**前端**（`app/Views/tenant/stores.php` 新增表单 + `store_edit.php`）：
- 平台下拉 `onchange` 用 JS 按 Registry 数据切换显示对应字段组（每字段独立 input + label + 提示）；
- 提交时 JS 或后端把字段组拼成 JSON 存入 api_config；**保留**一个"高级/原始 JSON"折叠项
  给特殊情况手填（不强制）；
- `StoreController::addStore/updateStore` 用 Registry 解析平台字段 → api_config；
  Registry 字段清单也传给视图渲染（避免前端硬编码）。

**测试**：`tests/store_api_field_test.php`——各平台 fieldsFor 字段数正确；
toJson/fromJson 往返；乐天旧格式兼容读取。

---

## 任务 C：订单页内嵌平台导入/导出入口

**目标**：平台订单视图（`/orders?view=platform&platform=r`）顶部提供该平台的
导入/导出入口，上下文（平台+可选店铺）已知，无需去导入导出中心。**不复制代码**——
全部指向现有控制器动作，仅是入口前移。

- **同步订单**：订单页已有（`platform/sync`），保留。
- **导入订单**：订单页顶部加"导入本平台订单"入口 → 复用上个任务的
  `/stores/import` 思路，但平台级（可选店铺下拉，缺省按行内店铺列匹配）；
  或直接链到导入导出中心的平台导入并预选平台参数——**二选一见"待定"**。
- **导出**：订单页顶部加"导出"下拉，列出该平台可用导出（发货表/财务/客户资料/
  自定义模板），指向现有 `ImportExportController` 各导出动作并带平台参数。
- **导入导出中心保留**为跨平台/批量的汇总入口，不删。

**待 Codex 调研后定**：现有导出动作是否都接受"平台/店铺"筛选参数；若接受则纯加入口，
若不接受需在动作里补可选参数（不改默认行为）。此任务 Codex 先出"入口清单 + 每个入口
指向的现有动作 + 需补的参数"小方案，我确认后再实施。

---

## 任务 D：自定义导出字段入口前移

- 现有"导出模板"（勾字段+排序+保存）功能保留不动，仅**增加可见入口**：
  - 订单页导出下拉里列出"我的导出模板"，点击按当前筛选/勾选范围用该模板导出；
  - 店铺页/订单页顺带给"管理导出模板"链接（指向现有 `export-templates/edit`）。
- 纯入口与串联，不改模板引擎本身。

---

## 实施顺序（逐项独立任务，各自提交）

1. **任务 A（核价浮层）**——你最在意、后端零开发、独立性强，先做；
2. **任务 B（店铺 API 动态表单）**——独立，第二做；
3. **任务 C（订单页导入导出入口）**——Codex 先出子方案我确认，再实施；
4. **任务 D（导出模板入口前移）**——依赖 C 的导出下拉，最后做。

每项：真实 MySQL 冒烟（核价数值核对、店铺配置落库读回、导入导出走通）+
既有测试全绿 + 我方复核后提交。

## 不做 / 边界

- 不照搬旧系统"每平台复制目录"结构（与多租户架构冲突）；
- 不动核价公式（新旧一致，仅暴露入口）；
- 不重写导出模板引擎；
- 旧系统 prompt 向导不迁（用正经动态表单替代，体验更好）。

## 验收总纲

- [ ] 订单明细/列表单价可悬停（触屏点击）弹核价浮层，售价/运费/扣点可改实时重算，
      数值与 /price-calculator 对同一输入一致；
- [ ] 店铺新增/编辑选平台后动态显示对应 API 字段（乐天2/雅虎2/Wowma1）带提示，
      落库为统一 JSON，旧数据兼容读取；
- [ ] 平台订单页顶部可直接导入/导出该平台订单，导入导出中心仍作汇总入口保留；
- [ ] 导出模板可从订单页导出下拉直接使用；
- [ ] 全部真实 MySQL 冒烟通过、既有测试全绿、新增测试通过。
