# Codex 启动提示词 — 发货单自定义导出模板

> 用法:把「===== 提示词开始 / 结束 =====」之间的全部内容复制粘贴给 Codex 作为首条消息。
> 本任务对应审计清单第 7 项的方案变更实施,计划文档已写好,Codex 按任务逐个执行即可。

---

===== 提示词开始 =====

你是一名资深 PHP 工程师,接手一个**已完成设计与详细计划**的功能实施任务:为 `php-saas/` 实现"发货单自定义导出模板"(字段自选/排序/固定值列/CSV·XLSX 嵌图导出),替代原有 5 个写死的平台发货单模板。

## 0. 第一步:先读,别急着写

动手前,**必须按顺序读完**:

1. `php-saas/CLAUDE.md` — 项目用途、技术栈、约定(最重要)
2. `php-saas/docs/specs/2026-07-03-custom-export-templates-design.md` — 需求规格(唯一需求来源)
3. `php-saas/docs/plans/2026-07-03-custom-export-templates-plan.md` — **实施计划,你的执行脚本**:7 个任务,每个任务带完整代码、测试代码、运行命令、提交步骤
4. `php-saas/app/Services/PlatformExportService.php` — 你将重写的现有实现
5. `php-saas/app/Services/ExportFieldRegistry.php`(计划里将创建)对应的消费方:`php-saas/app/Controllers/TenantController.php` 的 `exportPlatformSpecial()`/`importExportNonExcel()`(约 1352-1392 行)

读完后,用 3-5 句话向我复述:统一引擎的三种列类型、为什么 `tenantSettings` 的 `export_templates` 键需要整体替换语义、预置 Qoo10/Wowma 为什么保持 CSV。复述无误再开工。

## 1. 执行方式

- **严格按计划文档的 Task 1 → Task 7 顺序串行执行**,不要并行、不要跳步、不要重排。任务间有明确依赖(注册表 → 模板服务 → 引擎 → XLSX → 控制器 → 视图 → 文档)。
- 每个任务内严格走 TDD:先写计划里给出的测试 → 跑一次确认失败 → 实现 → 跑一次确认通过 → 按计划的 git 命令提交。
- 计划里的代码是**权威实现**,直接使用;只有当它与代码库现状冲突(如方法名/签名对不上)时,才按计划中标注的"用 Grep 确认实际名"指引适配,并在汇报里说明改了什么、为什么。
- 每完成一个任务,在计划文档里把该任务已完成步骤的 `- [ ]` 勾成 `- [x]`。
- 全部完成后,把 `php-saas/docs/audit-2026-07-02-fix-tasks.md` 第 7 项按计划 Task 7 的要求标 ✅ 并填入 commit hash。

## 2. 铁律(不可违反)

1. `old/` 只读,**绝不修改 old/ 下任何文件**。
2. **双驱动同步**:`JsonStore` 与 `MysqlStore` 行为必须一致——计划 Task 2 已同时列出两处改动,两处都要改,漏一个就是 bug。
3. 视图输出一律 `e()` 转义;JSON 内嵌 `<script>` 必须按计划用 `JSON_HEX_TAG` 系列 flag(防存储型 XSS),不要"简化"掉。
4. 用户输入(表头名/固定值/raw 路径)服务端校验按计划的上限执行(模板≤30、列≤50、label≤64、raw 前缀白名单),不要放宽。
5. 导出单元格一律过 `safeCell` 公式注入防护(含 const/raw 列)。
6. 不引入任何新 Composer 依赖(PhpSpreadsheet 已有)。
7. 工作区有其他未提交改动:**只 `git add` 计划里明确列出的文件,严禁 `git add -A` / `git add .`**。
8. 提交信息用约定式提交,中文描述,**末尾不加任何署名/归属信息**。

## 3. 完成标准

- 6 个测试脚本全部通过(或因缺 PHP 扩展 skipped):
  `php tests/export_field_registry_test.php`、`export_template_service_test.php`、`platform_export_render_test.php`、`shipping_xlsx_workflow_test.php`,以及既有的 `purchase_xlsx_workflow_test.php`、`rakuten_order_mapping_test.php` 不回归。
- 手工冒烟(计划 Task 6 Step 3 的 5 条路径)全部走通:模板列表/编辑器/保存/导出 XLSX 与 CSV/旧 `variant=qoo10` URL 兼容/删除。
- 每个任务一条 commit,共 7+ 条。

## 4. 汇报

全部任务完成后向我汇报:每个任务的 commit hash、测试运行结果原文、冒烟验证结果、以及执行中所有"计划与代码库现状不符需要适配"的点。有需要我决策的问题(而不是可以自行按计划推进的问题)时,随时停下来问。

===== 提示词结束 =====
