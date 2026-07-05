# 方案 D4：订单页导出按钮可配置化 + 自定义导出模板上订单页

> 日期：2026-07-05。状态：方案定稿，待 Codex 实施。
> 用户需求（D3.2 后追加澄清）：不是写死哪几个折叠，而是——
> ①租户可自己配置每个导出/导入按钮在订单页的展示状态（常驻/收起到更多/隐藏）；
> ②配置入口放在【导入导出】里；
> ③自定义导出模板（勾字段调顺序做出的表格）也纳入同一配置，可配置显示在订单页导出区，
>   点击即按该模板导出——这就是"自定义导出字段"能力在订单页的落地。

## 现状（已核实）

- D3.2 的分组(primary/more)与"导入导出"权限收口已存在（Registry group + visibleWhen），
  但分组是**代码写死**，租户不可配置；
- 自定义导出模板：ExportTemplateService 已有（自建模板勾字段/调顺序/保存，存
  tenantSettings['export_templates']）；**按模板导出的动作已存在**：
  `/import-export/platform-special/export?template_id=...`（D3 雅拍出荷处理表已用
  template_id=builtin_qoo10 走通）；
- 订单页导出按钮已有携带当前筛选参数(hiddenFilters)的 exportUrl 机制。

## 设计

### A. 配置存储

`tenantSettings['order_export_tools']`：
```
{
  "<toolKey>": "primary" | "more" | "hidden",     // 内置工具，如 finance_export
  "tpl_<templateId>": "primary" | "more" | "hidden" // 自定义/预置导出模板
}
```
- 未配置的键用默认值：内置工具默认=现 D3.2 的 group；模板默认=hidden（不自动冒出来）；
- 保存走 saveTenantSettings（该键整体替换语义，参照 export_templates/purchase_statuses
  的白名单特判，MysqlStore section 白名单同步加 order_export_tools——
  注意 SettingsRepository::saveTenantSettings 两处：整体替换特判 + section 数组）。

### B. 配置 UI（放导入导出中心，呼应用户"用【导入导出】控制"）

- 导入导出中心页(import_export.php)加「订单页导出按钮管理」面板（或子页
  `/import-export/order-tools`，GET 展示+POST 保存，路由 TenantAuth 组）；
- 列出两组：①全部内置导入/导出工具（来自 Registry 完整清单，含低频四项）
  ②全部导出模板（ExportTemplateService 列表：预置 builtin_* + 自定义）；
- 每行三选一：常驻 / 收进"更多导出" / 隐藏（radio 或下拉），保存按钮；
- 权限：requireTenantPermission('公司设置')（与导出模板编辑一致——这是管理配置）；
- 默认值回显：未配置项显示其默认状态。

### C. 订单页渲染读配置

- OrderPageConfigRegistry::exportToolsFor 增加读取 order_export_tools 配置：
  配置值覆盖默认 group；hidden 的不输出；
- **自定义模板按钮**：配置为 primary/more 的模板渲染为导出按钮
  （label=模板名，action=/import-export/platform-special/export + template_id + 当前筛选参数
  hiddenFilters，与现有导出按钮同机制）；点击即按该模板+当前筛选范围导出；
- 权限叠加不变：所有导出/模板按钮仍要求"导入导出"权限点（D3.2 收口保留）；
  模板按钮额外沿用 platform-special/export 动作的现有权限；
- 展示顺序：常驻组=内置(现有顺序)在前、模板在后；更多组同理。

### D. 明确不做（仍留后续）

- 主力导出（发货表/财务表/客户资料）的字段本身接入模板引擎（改造其列定义）——
  那是更大的 D5；本次通过"自定义模板上订单页"已让用户能用自定义字段表格导出，
  若用户想要"发货表本身可改字段"，后续单独立项。

## 测试

- tests 补：order_export_tools 配置读写往返（JsonStore）；Registry 配置覆盖默认 group、
  hidden 不输出、模板键渲染；无"公司设置"权限访问配置页被拒。

## 验收

- [ ] 导入导出中心有「订单页导出按钮管理」，可把任一内置导出设为 常驻/更多/隐藏，
      保存后订单页立即生效；
- [ ] 自定义导出模板可设为常驻/更多，订单页出现该模板按钮，点击按当前筛选导出成功
      （真实 MySQL 验证下载内容按模板字段）；
- [ ] 默认行为与 D3.2 一致（未配置时四低频在"更多"、常用常驻、模板不显示）；
- [ ] "导入导出"权限收口不回退；测试全绿。
