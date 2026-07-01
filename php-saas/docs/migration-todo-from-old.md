# php-saas 功能迁移待办（old → php-saas）

目标：把老系统 `old/` 的全部业务功能迁移到 php-saas。本清单是 5 个并行 gap 分析 + 人工核实后的结果。
邮件中心（kefu_mail）已补全，不在此清单。

- **分析日期**：2026-06-20
- **状态图例**：☐ 未做　◐ 部分实现/有壳　✅ 已完成
- 勾选某项后请注明 commit，保持清单与代码同步。

> 已确认 php-saas 做得**不比 old 差或更好**的部分（不算缺失）：全局搜索、操作日志、隐藏店铺（已改为 DB 的 status=hidden）、系统设置多租户化、邮件中心、店铺/员工/店铺分配的基础 CRUD。

---

## P0 — 核心缺失（影响日常主流程，上生产前必做）

### 订单核心
- ✅ **订单详情字段补全** — old `inc_order_detail_default.php` 字段远多于 php-saas。缺失字段：material(材质)、tranship_comment(跨境备注)、tabaono(1688单号)、caigoulink(采购链接)、caigoutime(采购时间)、caigou_ordernums、cnamount/comamount、weight 等。**这是其他功能的数据基础，先做。**（commit: feat: 补全订单详情字段）
- ✅ **订单状态变更日志** — old 改 beizhu 状态时自动写状态变更历史（set_beizhustatus_log）。php-saas 操作日志有，但状态流转历史粒度需补。（commit: feat: 补齐订单状态流转日志）
- ✅ **采购人自动记录** — old 采购员首次填 1688 单号(tabaono)时自动记 caigou_user。php-saas 有 buyer 字段但无自动赋值逻辑。（commit: feat: 迁移订单保存规则）
- ✅ **同品项同步修改** — old 改 material/tranship_comment 时自动同步同 ItemId 的其他行。php-saas 缺。（commit: feat: 迁移订单保存规则）
- ✅ **发货流程 sendjp / sendxizhends** — old 批量"已发日本"状态 + 西阵发货(调1688物流API)。php-saas 有批量框架但无发货端点。（commit: feat: 迁移发货流程 sendjp/sendxizhends）
- ✅ **订单高级筛选条件** — old `inc_list_default.php` 有 25+ 筛选（运单号、采购链接、采购备注、客服备注、发件人/电话、收货城市、超时发货、邀评、已评价、日期范围…）。php-saas 仅基础几个。（commit: feat: 迁移订单高级筛选条件）

### 平台订单同步（最大缺口）
- ✅ **Wowma 订单同步服务** — old `plugins/wmshopapi/`。php-saas 只有 RakutenOrderService。（commit: feat: 迁移 Wowma 订单同步服务）
- ✅ **Yahoo Shop 订单同步服务** — old `plugins/yahooshop-api/`。（commit: feat: 迁移 Yahoo Shop 订单同步服务）
- ✅ **Mercari 订单同步服务** — old 支持平台 m。（commit: feat: 迁移 Mercari 订单同步服务）
- ✅ **Qoo10 订单同步服务** — old 支持平台 q。（commit: feat: 迁移 Qoo10 订单同步服务）
- ✅ **雅虎拍卖订单同步服务** — old 支持平台 yp。（commit: feat: 迁移雅虎拍卖订单同步服务）
  > 建议：抽象一个 `PlatformOrderSyncInterface`，RakutenOrderService 作为范本，各平台实现。

### 物流（php-saas 当前只有 UI 壳，无真实 API 对接）
- ✅ **1688 采购物流：真实 API 对接 + 自动同步** — 已接 old `plugins/1688api/func.php`(1716行) + `cron/update_1688_logistics.php`(每2h) 的 1688 OpenAPI 查询、签收二次判定与 CLI 自动同步。（commit: feat: 迁移 1688 采购物流同步）
- ✅ **日本国际物流：佐川/日本邮政/雅玛多查询 + 自动同步** — 已接 old `plugins/jpshipinfo/`、`sagawa-shipinfo/` + `cron/update_jpship_logistics.php`(每天3次) 的承运商识别、三家查询与 CLI 自动同步。（commit: feat: 迁移日本国际物流同步）
- ✅ **定时任务(cron)框架** — 已提供系统 cron 可调用的 `php-saas/bin/cron.php` CLI 入口和任务注册约定。（commit: feat: 增加定时任务 CLI 框架）

### 导入导出 & 经营分析
- ✅ **订单导入字段映射核对** — old `orderinsert.php` 各平台 20+ 字段。需确认 CsvImportService 映射完整（尤其新补的详情字段）。（commit: feat: 核对并补齐订单导入字段映射）
- ✅ **利润分析：运费/邮费分摊 + 店铺扣点优先级** — old `profit-analysis`(1949行) 多商品订单按数量分摊国际运费、Y/R平台分摊日本邮费、按店铺 profit_deduction 优先。php-saas profit 只算单商品、无分摊、无邮费概念。（commit: feat: 迁移利润分析分摊口径）

---

## P1 — 常用缺失（高频使用，尽快补）

### 物流
- ✅ **国内快递查询 ShowAPI(TB/PDD)**（commit: ce22d22） — old `plugins/express-showapi/`。php-saas 有 logistics.express 开关但无对接。
- ✅ **异常运费检测**（commit: ce22d22） — old `plugins/shipping-anomaly/`(同商品同数量运费不一致检测+导出)。php-saas TenantFeature 标 stats.shipping_anomaly=false，未实现。
- ✅ **运单核对 checkyd / 日本快递跳转 jpyd-check**（commit: ce22d22） — old 提供运单核对与快递官网跳转。

### 统计分析
- ✅ **采购状态每日统计**（commit: 41ebd0a） — old `caigou_status/`(状态分布+日环比+平台分别+图表)。已补每日状态、平台分布和环比统计。
- ✅ **业绩汇总(按店铺/平台)**（commit: 41ebd0a） — old `performance/summary.php`。已补按店铺/平台聚合。
- ✅ **出单商品分析(热卖排名)**（commit: 41ebd0a） — old `performance/product_analysis.php`(1322行,按商品code销量/金额、子码分布)。已补商品编码销量/金额/子码分布。
- ✅ **业绩面板/日统计(AJAX)**（commit: 41ebd0a） — old `performance/index.php`(按日聚合订单/采购/完成)。已补页面和 JSON 数据接口。
- ✅ **核价计算器**（commit: 41ebd0a） — old `price_calculator.php`(多行成本核算工具)。已补多行核价与目标利润反推。
- ✅ **采购统计补全**（commit: 41ebd0a） — php-saas 已有采购人/状态，已补日视图、用户追溯维度。

### 导入导出（old 用 Excel/PHPExcel，php-saas 已开始迁移 XLSX）
- ✅ **多平台特定导出**（commit: 41ebd0a，CSV 版本） — old outexcel-riya/sx/wd/weier、outexcel_qoo10、outexcel_wowma 等平台专用发货单格式。已补平台变体 CSV 导出。
- ✅ **财务导出嵌图片 + Excel 样式**（commit: 94d6866） — old outcwexcel.php 财务图片表已迁移为 PhpSpreadsheet XLSX，真实嵌入订单图片/采购凭证图片并保留行高、列宽、边框与文本单号格式。
- ✅ **客户资料 Excel 样式导出**（commit: 94d6866） — old custinfo_export.php(冻结行/列宽/保护) 已迁移为 PhpSpreadsheet XLSX，保留冻结 A2、列宽、表头、边框、保护和邮编/电话文本格式。
- ✅ **财务数据导入(运单号模糊匹配)**（commit: 41ebd0a） — old caiwu_new_import.php(精确/前缀/后缀/中间匹配)。已补预览与确认写入。
- ✅ **国际运单导入：追加 vs 覆盖模式**（commit: 41ebd0a） — old shipping_import.php 第三列控制。已补追加/覆盖解析与确认写入。
- ✅ **日本仓订单导入(YD表)**（commit: 41ebd0a） — old ydinsert.php(日本仓订单+发货员分配)。已补预览与确认写入。

### 用户/通知
- ✅ **员工自助改密码**（commit: 41ebd0a） — old `pwdedit.php`。已补员工旧密码校验和 `password_hash` 更新入口。
- ✅ **租户内通知公告系统**（commit: 41ebd0a） — old `notice/`(租户管理员发公告给员工 + 订单页内展示)。已补租户公告管理、首页和订单页展示。
- ✅ **细粒度权限编辑 UI**（commit: 41ebd0a） — old `user_permissions.php` 逐用户勾权限。已补单用户 allow/deny 覆盖编辑页。
- ✅ **客服扣点快捷编辑**（commit: 41ebd0a） — old 用户列表内快编各客服 profit_deduction。已补快捷编辑，当前保存到租户利润设置。

---

## P2 — 边缘缺失（低频，可延后）

- ✅ **AJAX 体验类**（commit: 41ebd0a）：order_row 异步刷新行、order_detail 异步详情、logistics_reload 异步刷物流、toggle_review 邀评/已评切换。
- ✅ **刷单导出 outshuadan.php**（commit: 41ebd0a）。
- ✅ **运单/订单外部插入 ydorderinsert.php**（commit: 41ebd0a）。
- ✅ **物流查询百度备用方案**（commit: 801f2e8） — ShowAPI 失败或无轨迹时，可由超管开启百度搜索页备用查询，访问 https://www.baidu.com/s?wd=物流号 {国内运单号} 并解析物流轨迹。
- ✅ **物流编号对照表 / ShowAPI 等 setting 项**（commit: bf545f6） — 物流编号对照表、ShowAPI、轮循代理确认由超管全局维护；OBAPI 缓存和两步验证密码确认不迁移。
- ✅ **行内编辑 / 编辑侧栏**（commit: ea5cafe） — 保留订单列表右侧编辑栏，复用现有明细保存、权限校验、采购人自动记录与同品项同步规则。
- ✅ **动态组件配置 component-ini**（commit: ea5cafe） — 确认不迁移旧 INI 动态编辑器，已由超管/租户结构化设置替代。

---

## 建议实施顺序

1. **先补订单详情字段**（P0 第一项）— 它是采购、利润、导入导出的数据基础，不先做后面都缺字段。
2. 再做**发货流程 + 采购人/同步逻辑**（订单主流程闭环）。
3. 然后**平台同步服务**（抽象接口 + 逐平台）和**物流 API 对接 + cron**（两条独立线，可并行）。
4. **利润分摊 + 统计模块**（业绩/商品/采购状态/异常运费/核价器）。
5. 最后**导出 Excel 化 + 用户/通知/权限补全 + AJAX 体验**。
