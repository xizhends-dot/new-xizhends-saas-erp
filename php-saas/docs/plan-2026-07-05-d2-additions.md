# D2 补充需求（用户 2026-07-05 追加，并入 D2 平台差异化）

> 这些在 D1 返工完成后、做 D2 时一并实现。原 D2 见 plan-2026-07-05-order-page-rebuild.md 任务D2。

## 追加 1：搜索字段按平台可见性（D2 本就要做，此处强调）

- 每个平台只显示它真实拥有的搜索字段。铁例：**lotnumber 仅 Mercari(m)/雅拍(yp)**，
  乐天(r)/Yahoo购物(y)/Wowma(w)/Qoo10(q) **不显示** lotnumber 搜索框。
- 邀评/评价仅乐天+Yahoo购物；支付方式查询仅 Wowma；标题查询仅雅拍；等等。
- 完整矩阵见子代理调研（订单号字段名 orderId vs ziid、ItemId vs itemManagementId 等映射也在 D2 做）。
- OrderPageConfigRegistry::filterFieldsFor(platform) 按平台返回该平台字段子集。

## 追加 2：采购状态搜索框——单独一行 + 底色标记（前端样式）

- 采购状态(status)搜索框在搜索区**单独占一行**（不与其他字段挤在网格里）；
- 加特殊底色/颜色标记（它是最常用搜索项，需醒目）；参照老系统采购状态单独一行、
  旁边带橙色"超时发货"标签的处理。
- 纯视图 + CSS，不改后端。

## 追加 3：批量改货源地（真缺失，需补）

现状核实：
- 单个订单子项可改货源地（order_block.php:166 的 src-sel 下拉 国内采购/日本仓，
  后端 changeSource 支持**单条** item_id）；
- 批量操作栏只有"批量改状态"(set_purchase_status)、"批量分配采购人"(assign_buyer)，
  **没有"批量改货源地"**——与"采购状态有批量设置"不对等。

需实现：
- 批量操作栏加"批量改货源地"入口（下拉选 国内采购/日本仓/待定 + 应用按钮），
  与"批量改状态"并列；
- 后端 batchOrders 加 batch_action='set_source'（或扩展 changeSource 支持多 item_ids）：
  收 item_ids[] + source，逐条改货源地，走现有 changeSource 的权限(货源改判)与
  下游驱动逻辑（改货源会触发采购/发货队列，不可绕过）；
- 权限：requireTenantPermission('货源改判')，与单条一致；
- 复用现有 ensureBatchAccess 做店铺范围校验。

## 验收补充

- [ ] 切到乐天/Yahoo 时搜索区无 lotnumber 框；切 Mercari/雅拍时有；
- [ ] 采购状态搜索框单独一行且有醒目底色；
- [ ] 批量操作栏有"批量改货源地"，勾选多个订单可批量改国内采购/日本仓，
      权限与单条一致、店铺范围校验生效、下游队列正常触发；
- [ ] 真实 MySQL 冒烟：批量改货源地后 DB 中 source_type 正确变更。
