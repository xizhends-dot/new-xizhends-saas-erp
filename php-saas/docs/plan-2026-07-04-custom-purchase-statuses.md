# 方案：租户自定义采购状态（系统状态锁定 + 自由增删排序）

> 日期：2026-07-04。状态：方案定稿，待 Codex 实施。
> 需求：采购状态清单目前硬编码。改为租户可自定义——被自动化（定时任务/物流同步/批量动作）
> 依赖的"系统状态"锁定不可改名/删除；其余状态可新增、删除、上下移动排序
> （例如新增"AAA"并插到「国内采购-已采购」和「发货中」之间）。

## 现状事实（已核实）

- 权威清单：`AppService::PURCHASE_STATUSES`（`AppService.php:14`，17 个），
  经 `AppService::purchaseStatuses()`（:62）暴露，`OrderAjaxService.php:34` 消费。
- 另有**两份独立硬编码副本**（本次要消灭）：
  - `app/Views/tenant/orders.php:70` `$statusOptions`（13 个，与主清单不一致）；
  - `app/Views/tenant/order_detail.php:4` `$statusOptions`（又一份）。
  - `app/Views/tenant/partials/order_block.php:14` 用 `$statusOptions ?? []`（由包含它的视图传入）。
- 默认隐藏清单 `AppService::DEFAULT_HIDDEN_PURCHASE_STATUSES`（:35，`:1152` 消费）——本次不动。
- XLSX 采购表导出里有「采购状态」下拉数据校验（`SpreadsheetExportService.php:672` 附近）。
- **被自动化逻辑按精确字符串依赖的状态**（= 锁定集合的依据）：
  | 状态 | 依赖点 |
  |------|--------|
  | 未处理的订单 | 平台订单同步默认写入（Mercari/Qoo10 等 SyncService）、仪表盘待处理统计 |
  | 国内采购-准备 | 货源改判自动写入（JsonStore:997 等）、采购事件表 enter_prepare 映射 |
  | 国内采购-已采购 | 1688 物流同步触发（Alibaba1688LogisticsService:332）、快递同步触发（ExpressLogisticsService:633）、采购事件 complete_purchase |
  | 国内采购-TB/PDD已采购 | 快递同步触发、采购事件 complete_purchase |
  | 发货中 | 快递物流同步**自动写入**（ExpressLogisticsService:479/514）+ 同步触发 |
  | 已到货 | 快递物流同步**自动写入**（ExpressLogisticsService:474/518） |
  | 已发货代订单 | 日本物流同步触发（JapanLogisticsService:251）、发货判定（AppService:1332） |
  | 已发日本 | 批量「已发日本」写入（TenantController:1845 附近、app.js:102）、日本物流同步触发 |
  | 已发出荷通知 | 出荷通知流程、日本物流同步触发、发货判定 |

  另：`日本仓库已处理` 被 JapanLogisticsService:251 引用但**不在** 17 个主清单里（历史遗留），
  本次不纳入可管理清单、逻辑不动。
- 存储机制有现成先例：`tenantSettings['export_templates']` —— 数字索引列表键在
  `saveTenantSettings` 里做**整体替换**（`MysqlStore.php:1862`），且 MysqlStore 持久化有
  section 白名单（`MysqlStore.php:1872`），新键必须加入白名单否则 MySQL 驱动静默丢失。
  JsonStore 同样有 export_templates 整体替换特判，需镜像处理。
- 设置页：`app/Views/tenant/settings.php`（tab 结构，`$sections` 在 :12），保存走
  `POST /settings/save`。设置页权限：`management.settings` 功能 + 公司设置/系统设置权限点。

## 设计

### A. 存储：`tenantSettings['purchase_statuses']`

- 值 = **完整的有序状态名列表**（含系统状态），例如
  `["未处理的订单", ..., "国内采购-已采购", "AAA", "发货中", ...]`——顺序即展示顺序，
  这样才能支持"插到任意两个状态之间"。
- 键不存在/为空/校验不过 → 回退默认 `PURCHASE_STATUSES` 顺序（老租户零迁移）。
- `MysqlStore::saveTenantSettings`：section 白名单加 `purchase_statuses`，并与
  `export_templates` 同样做整体替换特判；`JsonStore::saveTenantSettings` 镜像。

### B. 新服务 `app/Services/PurchaseStatusService.php`

- `const SYSTEM_STATUSES = [...]`：上表 9 个锁定状态（值 => 锁定原因短语，供 UI 提示）。
- `defaultStatuses(): array`：种子清单 = 现 `PURCHASE_STATUSES` 17 项顺序。
- `statusesFor(string $tenantKey): array`：读设置 → `sanitize` → 不合法回退默认。
- `saveStatuses(string $tenantKey, array $names): array{ok:bool, message:string}`：校验后整体替换保存。
- 校验规则（纯静态方法，便于无 DB 单测）：
  - 每项 trim 后非空、mb 长度 ≤ 32、不含换行；全列表去重后数量不变（不允许重名）；
  - 总数 ≤ 50；
  - **9 个系统状态必须全部在场且名称逐字未变**（顺序可以变——移动位置不破坏自动化）；
  - 非法则整体拒绝并给出具体错误信息。

### C. 消费点全部切到动态清单

1. `AppService::purchaseStatuses()` 改为 `purchaseStatuses(string $tenantKey)`（内部委托
   PurchaseStatusService），同步更新 `OrderAjaxService.php:34` 调用处（该处已有 tenantKey 上下文）。
2. `TenantController` 的 orders / orderDetail / ajax 渲染路径向视图传 `statusOptions`；
   删除 `orders.php:70` 与 `order_detail.php:4` 两份硬编码数组，直接用传入的 `$statusOptions`。
3. **下拉框兜底**：渲染子项的采购状态 `<select>` 时，若该子项当前 `purchase_status`
   不在清单里（此状态后来被删了/历史值），把它临时**追加为第一个 option 并选中**，
   避免保存时被悄悄改掉。orders.php、order_block.php、order_detail.php 三处 select 都要处理。
4. 批量修改采购状态的下拉（orders.php:339）同用动态清单。
5. `SpreadsheetExportService` 采购表 XLSX 的「采购状态」下拉数据校验改用租户清单
   （方法签名加参数传入，调用处从控制器/服务侧取）。
6. `DEFAULT_HIDDEN_PURCHASE_STATUSES`（默认隐藏过滤）**不动**——自定义状态默认可见。
7. 订单保存/批量接口**不新增**严格校验（维持现状接受任意字符串），避免破坏 CSV 导入的
   旧状态归一化路径。

### D. 设置 UI：系统设置页新增「采购状态」tab

- `settings.php` `$sections` 加 `['key' => 'purchase_status', 'title' => '采购状态', 'desc' => '自定义采购状态与排序']`。
- Tab 内容：一个有序列表编辑器（参考导出模板列编辑器的纯 JS 交互先例）：
  - 每行：状态名 + 【上移】【下移】按钮 + 【删除】按钮；
  - 系统状态行：名称只读、删除按钮禁用、显示 🔒「系统状态·自动化依赖」标签（title 提示原因）；
    上移/下移**可用**（移动不破坏自动化）；
  - 底部「新增状态」：输入名称 + 【添加】（JS 插入行，可再用上移/下移调整到目标位置）；
  - 提交：JS 把当前顺序序列化成 JSON 写入隐藏字段，POST 到**专用路由**
    `POST /settings/purchase-statuses/save`（不混入现有 /settings/save 的 section 逻辑）；
  - 【恢复默认】按钮：POST 同路由带 `reset=1`，清空自定义回到默认 17 项。
- 控制器 `TenantController::savePurchaseStatuses()`：权限守卫与设置页一致
  （`management.settings` 功能 + 公司设置/系统设置权限点），JSON 解析失败或校验失败时
  redirect 回设置页带错误信息；设置页渲染时把当前清单和错误/成功消息传给视图。
- 服务端为唯一权威校验（前端 JS 只是交互便利）。

### E. 删除语义（要在 UI 上写明）

删除状态**不改动任何既有订单数据**——历史子项仍保留原状态字符串，只是不再出现在下拉里；
配合 C-3 的兜底，编辑历史子项时原状态仍可见可保留。

### 范围外（本次不做）

- 状态改名联动更新历史订单（有需要另立任务）。
- 给自定义状态挂"等价系统语义"映射。
- 统计页 `PurchaseStatsService` 的显示顺序清单（未知状态它本来就会追加显示，不破）。

## 测试要求

新增 `php-saas/tests/purchase_status_service_test.php`（纯 PHP 断言风格）：

- 设置缺失 → 回退默认 17 项顺序。
- 合法保存：在「国内采购-已采购」「发货中」之间插入"AAA"→ 读回顺序正确；
  删除一个非系统状态（如"刷单订单"）→ 读回不含它；系统状态整体换序 → 合法。
- 拒绝：缺失任一系统状态；系统状态被改名（如"已发日本"→"已发日本2"）；重名；空名；
  超长（>32）；总数 >50。
- JsonStore 全链路：saveStatuses 后 statusesFor 读回一致；再次保存整体替换（无旧值残留）。

既有 `tests/*.php` 必须全部继续通过。

## 验收清单

- [ ] 设置页「采购状态」tab：能新增"AAA"，上移/下移把它放到「国内采购-已采购」与「发货中」
      之间，保存后订单页/详情页/批量下拉即刻按新顺序显示。
- [ ] 9 个系统状态：名称只读、不可删除（前端禁用 + 服务端拒绝双保险），但可移动位置。
- [ ] 删除自定义/非系统状态后：下拉不再出现；持有旧状态的历史子项编辑时原状态仍显示且不丢。
- [ ] 恢复默认按钮生效。
- [ ] `orders.php`/`order_detail.php` 两份硬编码状态数组已删除，全站下拉来源唯一。
- [ ] MySQL 与 JSON 两驱动行为一致（MysqlStore 白名单 + 整体替换已加）。
- [ ] 1688/快递/日本物流同步、批量已发日本、采购事件记录等自动化在自定义状态存在时行为不变。
- [ ] 全部测试通过。
