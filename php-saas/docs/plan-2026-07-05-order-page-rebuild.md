# 方案：订单页重建——旧布局内核 + 现代样式 + 逐平台差异化

> 日期：2026-07-05。状态：方案定稿，待 Codex 分批实施。
> 用户诊断：新系统订单页"违背了原设计、不实用"。经逐平台核对旧系统 20251217 证实：
> ①筛选区被压成窄栏、三栏分明的结构丢了 ②导出/物流被折叠+按钮太小(旧系统是常驻大字独立列)
> ③Lotnumber 被当通用字段(实际仅 Mercari/雅拍有) ④筛选区店铺下拉与顶部重复 ⑤搜索项应8+个。
> 用户决策：旧布局内核+现代样式 / 逐平台差异化字段 / 去掉筛选区店铺下拉 / 先出完整方案。

## 核心认知：6 平台差异远大于预期（子代理逐平台核实）

旧系统是"每平台一套独立页"(orderr/ordery/orderyp/orderw/orderm/orderq)，已各自漂移。
关键差异（完整矩阵见调研，此处提炼驱动设计的部分）：

**字段名逐平台不同**（最大的坑）：
- 订单号：乐天/Yahoo购物 `orderId`，其余 `ziid`
- ItemId：乐天/Yahoo `ItemId`，Wowma/Mercari/Qoo10 `itemManagementId`，雅拍无
- 运送方式：乐天 `yunshu`、Wowma/Mercari/Qoo10 `deliveryName`、Yahoo `PayStatus`(历史错配)、雅拍无
- 片假名：乐天 `pianjiaming`，其余 `senderKana`，雅拍无
- 订单时间：乐天/Yahoo `OrderTime`，其余 `orderDate`

**平台特有筛选字段**：
- 乐天独有：店铺选择、配達中、配達完了；邀评/评价(乐天+Yahoo)
- Yahoo购物独有：采购链接查询
- 雅拍独有：标题查询、lotnumber+lotnumber_empty
- Wowma独有：支付方式查询(settlementName)
- Mercari独有：lotnumber（无empty）
- Qoo10(停运)：纯减法，无飞兔/国际运单状态/邀评

**采购状态(beizhu)选项逐平台不同**：乐天精品组有"日本仓库已处理"，其余有"已发出荷通知"；
刷单两项仅 Yahoo/Wowma；Mercari/Qoo10 无"已发货代订单"、分组无标签。
→ **这正是任务"自定义采购状态"的延伸**：状态清单本就该按平台/租户配置。

**导出工具显示条件三类**：`客服`(订单同步/导入)、`采购`(采购单导入,仅乐天)、
`caiwu/xizhends`(财务/客户资料导出)。

## 设计总纲：字段清单配置化 + 三栏布局 + 平台驱动渲染

不复制 6 份页面。做**一套 orders.php + 一份"平台订单页配置"**，按当前平台渲染差异化字段。
这与已完成的"店铺API动态表单""自定义采购状态"是同一思路，架构自洽。

### 新增：OrderPageConfigRegistry（平台订单页配置权威）

`app/Services/OrderPageConfigRegistry.php`：
- `filterFieldsFor(string $platform): array` —— 该平台筛选字段定义
  `[{key, label, type(text/select/checkbox/date), options?, colspan?}]`，
  含通用字段(订单号/1688单号/收件人/电话/邮箱/国内外单号/采购状态/签收地/每页) +
  平台特有字段(按上表)；字段的**内部统一 key**(如 order_no) 映射到该平台真实查询字段名
  (乐天 orderId / 其余 ziid)——映射也在此配置，控制器筛选时用；
- `exportToolsFor(string $platform, array $user): array` —— 该平台导出/导入工具定义
  `[{key, label, action, needsDateRange, visibleWhen(role/username条件)}]`；
- `purchaseStatusOptionsFor(string $platform, string $tenantKey): array` —— 采购状态选项
  （平台默认 + 租户自定义合并，复用现有 PurchaseStatusService，按平台调整分组/特有项）。

配置以子代理矩阵为准逐平台填。**先实现乐天(r)/Yahoo购物(y)/Wowma(w)/Mercari(m) 四个主力平台**，
雅拍(yp)/Qoo10(q,停运) 用通用兜底 + 标注 TODO（Qoo10 停运不急）。

### 视图：三栏布局（旧内核 + 现代样式）

重写 `app/Views/tenant/orders.php` 筛选区为**三栏 grid**（旧系统三列 33% 的现代化）：

- **左栏·搜索表单**（占比最大）：按 `filterFieldsFor` 动态渲染，4列×2行=**≥8个搜索项**
  的网格排布；字段少的平台自然少行，字段多的(乐天)多行；GET 提交到 /orders；
  **移除筛选区内的店铺下拉**(line 190-197，顶部已有店铺切换，去重)；
  lotnumber 只在配置含它的平台(m/yp)出现——不再全平台硬塞。
- **中栏·信息**：实时汇率(复用现有)、通知公告(复用 tenantNotices)。
- **右栏·导出物流工具**（**常驻显示、不折叠、大字**）：按 `exportToolsFor` 渲染，
  每个导入/导出/物流动作占一行，字号明显大于当前(参照旧系统 label 大字体)，
  按用户角色/用户名显示对应工具；同步订单、平台导入、发货表/财务/客户资料导出、
  国际运单导入、飞兔推送/拉取等（指向已有控制器动作，缺参数的按任务C思路补）。

现代样式：三栏用 CSS grid + 卡片；窄屏堆叠为单列；导出区按钮用现有 .btn 体系但尺寸调大；
全部输出 e() 转义。**保留任务A的悬停核价、任务B无关**。

### 控制器：OrderController::orders() 适配

- 向视图传 `filterFields`(当前平台) / `exportTools` / `platformStatusOptions`，
  取代现在硬编码的筛选/状态传参；
- 筛选处理：用 Registry 的 key→真实字段名映射解析 $_GET，
  Yahoo `PayStatus` 错配字段特判（label 运送方式、name PayStatus）；
- **不改后端查询/权限逻辑本身**，只改"哪些字段可筛、字段名映射、传给视图什么"；
- 顶部店铺切换与去重后的筛选区不冲突（顶部 platform+store 决定数据范围，
  筛选区只做字段级过滤）。

## 分阶段实施（各自独立任务 + 提交）

由于工作量大且平台多，拆成 3 个子任务：

**任务 D1：三栏布局骨架 + 通用字段 + 去店铺下拉 + 导出区常驻大字**
- 建 Registry 骨架（先只放全平台通用字段 + 通用导出工具）；
- 重写 orders.php 为三栏，导出区常驻；移除筛选区店铺下拉；
- 此步不涉平台差异，先把"布局对、导出不隐藏、字大、店铺不重复"落地。

**任务 D2：逐平台差异化字段**
- Registry 填入 r/y/w/m 四平台的特有筛选字段 + 字段名映射 + 采购状态选项；
- lotnumber 仅 m/yp；邀评仅 r/y；配達中/完了仅 r；等等按矩阵；
- 控制器字段名映射 + PayStatus 特判。

**任务 D3：导出工具权限差异化 + 平台特有导出**
- exportToolsFor 按角色(客服/采购/caiwu-xizhends)显示；
- 平台特有导出(Mercari新版导入、雅拍复用Qoo10出荷表等)按矩阵；
- 与任务C(订单页导入导出入口)合并考虑，避免重复。

## 不做 / 边界

- 不复制旧系统 6 份页面代码；
- Qoo10(停运)不做特有项，通用兜底即可；
- 不改后端订单查询 SQL 与权限判定，只改字段可见性/映射/布局；
- 采购状态复用现有 PurchaseStatusService，仅加平台维度，不重写。

## 测试与验收

- 每任务：真实 MySQL 冒烟(切不同平台看筛选字段/导出工具是否按平台正确变化) + 既有测试全绿；
- 新增 tests/order_page_config_test.php：各平台 filterFieldsFor 含正确字段
  (乐天有邀评、Mercari有lotnumber、雅拍无ItemId等)、字段名映射正确、导出工具权限条件正确。
- 验收：
  - [ ] 筛选区三栏布局，搜索项≥8、导出物流区常驻大字不折叠；
  - [ ] 筛选区无店铺下拉(顶部唯一)；
  - [ ] lotnumber 只在 Mercari/雅拍出现，乐天/Yahoo无；
  - [ ] 切换平台时筛选字段与导出工具按矩阵正确变化；
  - [ ] 采购状态选项按平台正确(乐天"日本仓库已处理"等)；
  - [ ] 悬停核价(任务A)仍正常；测试全绿。
