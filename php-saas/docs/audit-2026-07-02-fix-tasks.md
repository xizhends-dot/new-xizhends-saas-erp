# php-saas 迁移审计修复任务清单（2026-07-02）

来源：对 `migration-todo-from-old.md` 全部标 ✅ 之后的一次复核审计（7 个方向并行代码级核实，逐项与 `old/` 实际代码比对，不采信任何既有文档的完成度描述）。本清单只收录**这次审计新确认的真实问题**，已验证为真实完成的部分不再重复列出。

排除范围（已由用户明确决定不迁移，不要处理）：OBAPI（OneBound 淘宝/拼多多代采）、两步验证密码确认（`basic_auth2`）、动态组件配置 component-ini。

## 给 Codex 的使用说明

- `old/` 是老系统，只读参考，**不要修改 `old/` 下任何文件**。
- 每完成一项，把标题前的 ☐ 改成 ✅ 并在条目末尾注明 commit hash，保持清单与代码同步（参照 `migration-todo-from-old.md` 的约定）。
- 数据层改动注意：`JsonStore` 与 `MysqlStore` 是同一接口的两套实现，改一个通常要同步改另一个，否则两种驱动行为不一致。
- 涉及外部 API/密钥的任务，不要把 `old/` 里任何硬编码的密钥、Key、账号密码抄进新代码（尤其是 P2 第 15 项，那组密钥已确认泄露）。
- 不确定验收标准或业务口径的任务（标了"需业务确认"的），先向用户确认再动工，不要自行假设。

状态图例：☐ 未做　◐ 部分实现/进行中　✅ 已完成

---

## P0 — 高优先级（数据完整性 / 生产可用性风险）

### ✅ 1. 邮件中心 MySQL 驱动下建表脚本缺失，会静默失效
**现象**：`migrations/tenant/` 下没有 `ph_mail_account`/`ph_mail_folder`/`ph_mail_message`/`ph_mail_reply`（或等价命名）的建表 SQL。`MysqlStore.php` 中邮件相关写方法（如 `saveMailAccount`，约 1866+ 行区域）在表不存在时走 `tableExists` 判断静默 `return 0`，不报错。一旦生产环境切到 `DATA_DRIVER=mysql`，邮件中心所有功能（账号配置/同步/回复）会悄悄失效，界面不会有任何报错提示。

**参考**：`php-saas/app/Core/MysqlStore.php`（mail 相关方法）；字段设计参考老表结构 `old/kefu_mail/install.php`（含 SHOW COLUMNS 自检 + ALTER 补列逻辑）。

**要求**：
- 新增迁移 SQL（如 `migrations/tenant/0006_create_mail_tables.sql`），建 4 张表，字段覆盖 `MysqlStore.php` 里 mail 相关方法实际读写的全部字段。
- `MysqlStore` 邮件相关写方法在表缺失时应改为报错或在 `/admin/system` 页面显式提示"邮件表未建"，不能再静默丢数据。

完成提交：`30679eb`

---

### ✅ 2. Yahoo Shop OAuth 授权回调路由缺失
**现象**：老系统 `callback.php` 处理"授权码 code → access_token"的首次 OAuth 交换（依赖 `plugins/yahooshop-api/func.php` 的 token 换取逻辑）。php-saas 目前只有 `YahooShopOrderSyncService.php` 内部的 `refresh_token` 刷新逻辑，`public/index.php` 路由表里没有 `/callback` 等价路由。新店铺接入 Yahoo Shop 目前必须先在别处手工拿到 token 再填入 `api_config`，界面走不通"点击授权 → 跳转 Yahoo → 回调自动拿 token"的完整流程。

**参考**：`old/callback.php`、`old/plugins/yahooshop-api/func.php`；`php-saas/app/Services/YahooShopOrderSyncService.php`。

**要求**：
- 在 `public/index.php` 新增 `/oauth/yahoo/callback`（或同等）路由，接收 `code`，调用 Yahoo token 端点换取 `access_token`/`refresh_token`，写回该店铺的 `api_config`。
- 店铺编辑页（Yahoo 平台）增加"发起授权"入口，跳转到 Yahoo 授权页并带上正确的 redirect_uri。

完成提交：`abe0b93`

---

### ✅ 3. 采购表导入导出从"样式化 XLS + 嵌图 + 超链接 + 锁定"降级为纯 CSV
**现象**：老系统 `caigou_export.php`/`caigou_import.php` 是双向往返的 XLS 工作流：导出表格内嵌 1688 商品图片、商品链接做超链接、部分单元格锁定保护，采购员/品检靠图片和链接核对 1688 商品是否对应。php-saas 的 `CsvImportService::purchaseRecord`（字段列位置对应完整，约 192-199 行固定列映射）已降级为纯 CSV，没有图片、没有超链接、没有锁定保护。

**参考**：`old/orderm/caigou_export.php`、`caigou_import.php`；`php-saas/app/Services/CsvImportService.php`；已有 PhpSpreadsheet 嵌图经验可参考 `php-saas/app/Services/SpreadsheetExportService.php` 的 `financeWorkbook`/`customerWorkbook`。

**要求**：
- 用 `SpreadsheetExportService` 同款 PhpSpreadsheet 方式重做采购表导出为 XLSX，嵌入商品图片、1688 链接做超链接、关键字段单元格保护/锁定。
- 采购表导入端支持解析 XLSX（不只是 CSV）。如果业务方确认纯 CSV 可接受，则本项降级为文档说明，不用代码改造——**需先问用户**。

完成提交：`c28e4e4`；验证脚本 `tests/purchase_xlsx_workflow_test.php` 在补齐 zip/mbstring/gd 扩展后实际跑通，发现并修复锁定列缺少显式 `PROTECTION_PROTECTED` 声明的问题（`508825d`）。

---

### ✅ 4. 财务导出（outcwexcel）Yahoo 购物（ordery）平台专属模板未做，字段是错的
**现象**：`old/{orderm,orderq,orderr,orderw,ordery,orderyp}/outcwexcel.php` 中，ordery（Yahoo 购物）版本是完全不同的模板：14 列，**没有**国内单号/国内运费/采购证据图片/淘宝订单号，多一列"产品总价（单价×数量）+利润"。而 `SpreadsheetExportService::financeWorkbook`（约 32-135 行）目前对所有平台统一用一套 17 列通用格式（对应 orderm/orderr/orderq/orderyp 默认版），**Yahoo 购物财务导出用的字段集是错的**。另外 orderw 版本比通用版多一列"单价"（应为 18 列）未覆盖；`old/orderr/outcwexcel-weier.php` 变体（图片列替换为"店铺名称"）也未覆盖。

**参考**：`old/ordery/outcwexcel.php`、`old/orderw/outcwexcel.php`、`old/orderr/outcwexcel-weier.php`；`php-saas/app/Services/SpreadsheetExportService.php`。

**要求**：
- `financeWorkbook` 按平台分支输出对应列结构（至少 ordery / orderw / orderr-weier 三种特殊情况），而不是所有平台共用一套 17 列格式。
- 逐一核对每种模板的列名、列序与老系统一致。

完成提交：`415b40b`

---

### ✅ 5. `ph_caigou_record` 结构化采购事件审计表未迁移
**现象**：老系统 `set_beizhustatus_log()` 在采购状态变为"国内采购-准备/已采购"时，把 `action_type`/`old_status`/`new_status`/`caigou_time`/`cnamount` 等写入 `ph_caigou_record` 表，可精确复原"某商品在某时刻的采购状态快照"。php-saas 目前只有泛化的 `order_logs` 文本历史（`MysqlStore::insertItemLog`）和 `PurchaseStatsService::statusSnapshot()`（这是"当日发生采购动作"的实时过滤，不是事件级历史表），无法做真正的历史时点状态分布回溯。

**参考**：`old/inc/functions.php` 的 `set_beizhustatus_log()`；`php-saas/app/Core/MysqlStore.php`（`insertItemLog` 附近）；`php-saas/app/Services/PurchaseStatsService.php`（`statusSnapshot`）。

**要求**：
- 新增结构化表（如 `purchase_status_events`），在采购状态变更为"国内采购-准备/已采购"（或等价新状态值）时写入 action_type/old_status/new_status/caigou_time/cnamount 等快照字段。
- `PurchaseStatsService` 增加基于该表的"任意历史日期的状态分布"查询能力，区别于现有"当日发生"过滤逻辑。

完成提交：`6e06f0b`

---

## P1 — 中优先级

### ✅ 6. 品检（pinjian）角色作废，权限点并入采购角色
**口径已确认（2026-07-03，业务方决定）**：本次审计原本发现"品检可编辑字段集变窄 + 默认列表未按状态收窄"，但与业务方核对后，结论不是补权限，而是**新系统不再保留"品检"这个独立角色**。品检目前持有的"日本仓发货"相关权限点（日本仓发货/物流查看/日本物流日志/图片管理，含图片上传/图片删除）**并入"采购"角色**，以后由采购角色统一承担日本仓发货相关操作。

**现状核实**（2026-07-03）：
- 角色定义：`php-saas/app/Core/Permission.php:83` `roleDefaults()['品检']`。
- `role` 是自由文本字段（`migrations/tenant/0001_init_tenant_schema.sql` 里 `VARCHAR(32)`），代码里**没有硬编码的角色白名单校验**，删除"品检"选项不会破坏既有校验逻辑。
- 当前 JSON 数据（`storage/data/app.json`）里**没有任何用户的 `role` 是"品检"**，只有 公司管理员/客服/采购，无需做历史数据迁移/转换脚本（但正式上生产前建议用 `MysqlStore` 也确认一遍租户库没有遗留品检用户）。
- 代码里"品检"字符串出现在 14 个文件（`grep -rln 品检 app/` 结果），包括 `Permission.php`、`JsonStore.php`、`MysqlStore.php`、`TenantFeature.php`、`AppService.php`、`TenantController.php`、`TenantNoticeService.php`，以及视图 `assignments.php`/`media.php`/`order_detail.php`/`tenant_notices.php`/`users.php`/`user_edit.php`/`system_status.php`。
- `TenantController.php:847` `assignments()` 方法里 `buyers` 列表目前是 `in_array($role, ['采购','品检'])` 过滤——这行逻辑本身已经隐含"采购和品检共用店铺分配范围"的语义，删除品检角色后这里改成只保留 `['采购']` 即可，行为自然衔接。

**要求**：
1. `Permission::roleDefaults()` 删除 `'品检'` 这一项；把品检原有的权限点（日本仓发货、物流查看、日本物流日志、图片管理、图片上传、图片删除）合并进 `roleDefaults()['采购']`（与"采购"现有权限点去重合并，不要重复项）。
2. 全局搜索并清理上述 14 个文件里所有"品检"相关的角色分支、UI 文案、下拉选项（如 `users.php`/`user_edit.php` 的角色选择器、`assignments.php` 的岗位说明文案）——删除"品检"作为可选角色，不要留下选了就报错或静默失效的死选项。
3. `TenantController::allowedOrderItemPostData()` 里如果有单独判断 `role === '品检'` 的分支，按并入采购的口径合并处理（采购角色已经能通过"采购状态"权限点编辑的字段不用重复开，只需确保"日本仓发货"权限点下的字段对采购角色也生效）。
4. 检查 `UserPermissionOverrideService`、`TenantNoticeService`、`system_status.php` 等文件里对"品检"的引用，同步清理或改写为"采购"。
5. 如果现有测试/文档（如 `docs/migration-todo-from-old.md`）提到品检角色，一并更新表述。
6. 不需要写数据迁移脚本（当前无历史品检用户数据），但改完后建议手工确认：如果某个租户的 MySQL 库里确实存在 `role='品检'` 的员工记录，登录后角色显示、权限计算不会报错（至少要优雅处理"未知角色"，不能是空白权限导致锁死账号——如果发现这种记录，按"视为采购角色"处理）。

完成提交：`84e098b`

---

### ✅ 7. `outexcel` 平台专用发货单导出格式覆盖不全
**现象**：`php-saas/app/Services/PlatformExportService.php` 只做了 riya/sx/wd/qoo10/wowma 5 个变体，老系统实际还有默认版 `outexcel.php`、`outexcel2.php`、`outexcel3.php`（EDM 变量表，orderr 独有）、`outexcel4.php`（佐川 EDI，orderr 独有）、`outexcel_shipment.php`（orderm+orderyp 独有）等至少 5 类未迁移。

**参考**：先用 Grep 在 `old/` 全库搜索 `outexcel` 确认完整清单（分布在 `old/{orderm,orderq,orderr,orderw,ordery,orderyp}/` 下）；`php-saas/app/Services/PlatformExportService.php`。

**要求**：为遗漏的每种格式在 `PlatformExportService` 增加对应导出方法，字段与老版逐列核对。

**方案变更(2026-07-03,业务方决定)**:不再逐个复刻老 outexcel 变体,改为"字段注册表 + 租户自定义导出模板"统一机制(自选列/排序/固定值列/CSV·XLSX 嵌图),5 个已迁移模板转为预置模板。设计见 `docs/specs/2026-07-03-custom-export-templates-design.md`。老系统剩余变体(EDM/佐川 EDI 等)由各公司管理员用该机制自行配置,配不出来的再评估。

完成提交:`e309a78`

---

### ☐ 8. 6 个平台订单同步均未接入定时任务
**现象**：`TenantController.php`（约 1699-1719 行）里平台同步只能手动点击按钮触发，`CronTaskRegistry` 只注册了 `Sync1688LogisticsTask`、`SyncJapanLogisticsTask` 两项，没有平台订单同步的定时任务。

**参考**：`php-saas/app/Services/CronTaskRegistry.php`；`php-saas/app/Services/AbstractPlatformOrderSyncService.php`；`php-saas/bin/cron.php`。

**要求**：为 6 个平台订单同步各建一个 `CronTaskInterface` 实现（参考 `Sync1688LogisticsTask` 写法），注册进 `CronTaskRegistry`，`bin/cron.php list` 应能看到。

---

### ☐ 9. `order_monitor`/`order_archive`/主图下载/图片清理脚本均未迁移
**现象**：老系统 `cron/order_monitor.php`（自动填充订单信息）、`cron/order_archive.php`（按年度归档到 `ph_order{tag}_{year}`）、`cron/zhutu_downloader.php`（下载商品主图）、`cron/cleanup_old_images.php`/`cleanup_old_images_preview.php`（图片清理），php-saas 无任何对应实现。

**参考**：`old/cron/order_monitor.php`、`order_archive.php`、`zhutu_downloader.php`、`cleanup_old_images.php`。

**要求**（**需先确认这几项当前是否必需**——数据量、图片存储压力是否已到需要处理的阶段）：需要的话按 `CronTaskInterface` 各自实现并注册。

---

### ☐ 10. 1688 物流"00:00-08:00 禁止运行"规则代码层未强制
**现象**：老 `cron/update_1688_logistics.php` 在脚本入口硬编码时段校验，命中直接 `exit(0)`。php-saas `Sync1688LogisticsTask::schedule()` 只是文本描述这个规则，`run()` 里没有任何时段判断，若误配置在该时段触发会照常执行。

**参考**：`old/cron/update_1688_logistics.php`（约 29-34 行）；`php-saas/app/Services/Sync1688LogisticsTask.php`。

**要求**：在 `Sync1688LogisticsTask::run()`（或更上层的 cron 调度入口）加入 00:00-08:00 时段判断，命中则直接跳过。

---

### ☐ 11. 邮件中心无定时同步任务
**现象**：老 `cron/mail_sync.php` 定时增量拉取各店铺邮箱邮件，php-saas 无对应脚本/`CronTaskInterface` 实现，只能靠人工点击"同步"按钮。

**参考**：`old/cron/mail_sync.php`；`php-saas/app/Services/MailService.php`（已有真实 IMAP 拉取逻辑可复用）。

**要求**：新增 `MailSyncTask implements CronTaskInterface`，复用 `MailService` 现有同步方法，注册进 `CronTaskRegistry`。（注意：先确认第 1 项 MySQL 建表问题已解决，否则此任务在 mysql 驱动下同样会静默无效。）

---

### ☐ 12. 日本仓导入/刷单导出/运单外部插入 字段语义与老版不一致
**现象**：`JapanWarehouseImportService`（对应老 `ydinsert.php`）、`LegacyEdgeToolService::brushOrderDataset`（对应老 `outshuadan.php`）、对应老 `ydorderinsert.php` 的实现，字段集/匹配策略相比老版本发生了较大改动（如 ydinsert 从 5 列精确匹配变成 8 列 token 索引匹配；outshuadan 从 4 列固定 EDI 格式变成 11 列通用 CSV + 启发式筛选），属于"重新设计"，不是"复刻"。

**参考**：`old/ydinsert.php`、`old/outshuadan.php`（如存在）、`old/ydorderinsert.php`；`php-saas/app/Services/JapanWarehouseImportService.php`、`LegacyEdgeToolService.php`。

**要求**（**需先与业务方核对当前真实使用的模板/文件格式**）：确认新实现的字段和匹配逻辑是否满足实际业务需求，不满足则按老版语义调整。

---

## P2 — 低优先级 / 需业务确认后再排期

### ☐ 13. 超管跨平台核对工具无等价物
`old/order/*.php`（超管专用，w/y/r 三平台简化列表 + 受限编辑 + ShowAPI 失效运单批量检测 + 共享物流轨迹页）在 php-saas 无等价实现，且该工具本身只覆盖 3/6 平台。**先找用户确认是否需要补齐，不确定前不要开工。**

---

### ☐ 14. SaaS 超管自助改密码入口缺失
老 `admin_save.php`+`manage_index.php` 有超管改密码表单，`php-saas/app/Controllers/AdminController.php` 无对应路由/方法。

**要求**：`AdminController` 增加改密码方法 + 路由 + 视图表单，走 `AuthService` 的 Argon2id 哈希逻辑。

---

### ☐ 15. ChatGPT 通用问答助手插件未迁移
`old/plugins/chatgpt/` 是与订单业务无关联的通用 OpenAI 问答工具，老 `config.php` **硬编码明文 OpenAI Key 和 socks5 代理账密（已确认是泄露凭证）**。这是本次审计新发现、此前任何清单都没记录过的一项。

**要求**：**先找用户确认业务上是否还需要这个功能**。需要的话必须用新的、未泄露的凭证并走环境变量配置，**绝不要把 `old/plugins/chatgpt/config.php` 里的任何密钥抄进新代码或提交到 git**。

---

### ☐ 16. 历史订单批量导入缺少 caigou_user 兼容回退
`CsvImportService.php` 的采购人字段映射（约 48 行）没有老系统 `install.php` 那种"caigou_user 为空则回退 user_type='采购' 时取 user_name"的补全逻辑，仅在批量导入 2026-05-13 前的历史订单场景下有影响。

**要求**：如果计划做老系统历史数据整体迁移导入，需要在 `CsvImportService` 或专门的一次性迁移脚本里补上这个回退逻辑；如无此计划，本项可搁置。

---

### ☐ 17. 利润分摊分母口径变化（文档说明，非代码修复）
老系统按"同订单 DB 行数"平均分摊国际运费，php-saas 改成按商品 `quantity` 比例分摊（`AppService.php` 约 1512-1537 行 `allocationsByQuantity`）。算法更合理，但数值不会跟老报表历史数据对齐。**不建议改回旧算法**，只需要在文档/发布说明里向业务方说明这个口径变更，避免有人拿新旧报表对不上的数字来找系统 bug。

---

### ☐ 18. ShowAPI 凭证无租户级配置入口
`ExpressLogisticsService`（国内快递查询）的 ShowAPI AppID/Sign 目前只能在超管全局配置里维护，没有像 1688 那样给租户自己填的入口。

**要求**：**先找用户确认这是否是有意的架构选择**（ShowAPI 通常是超管统一采购的服务，可能本来就该全局配置）。如果是有意的，在 `php-saas/CLAUDE.md` 或 `docs/system-migration-blueprint.md` 里补一句说明，避免以后又被当成"缺失"重复排查；如果不是，补齐租户级配置入口。

---

## 文档修正（不涉及代码逻辑，但需一并处理，避免继续误导后续审计/开发）

### ☐ D1. `migration-todo-from-old.md` 对 Mercari/Qoo10/雅虎拍卖同步服务的描述需改
这三个平台的 Service（`MercariOrderSyncService`/`Qoo10OrderSyncService`/`YahooAuctionOrderSyncService`）本质是"通用可配置 HTTP 适配器"（endpoint/token/字段名全部来自租户 `api_config`，代码里没有硬编码任何官方端点），跟 Rakuten/Wowma/YahooShop 那种硬编码官方端点 + 真实签名鉴权的实现不是一回事。老系统本身对这三个平台也从无官方 API 对接（纯人工 CSV 录单），所以**不算迁移缺失**，但清单不应该把这三项和前三个平台描述成同一等级的"官方 API 已对接"，容易误导后续判断。

**要求**：改成类似"通用可配置同步框架，等平台方开放官方 API 时补充默认端点"的表述。

---

### ☐ D2. `FinanceExportRequirementService.php` 需要重新核实
该文件只有约 31 行，是硬编码的"已完成"声明文本，不做任何实际数据转换，之前被 `migration-todo-from-old.md` 标记为某功能"已完成"的依据不成立。

**要求**：找出它原本对应老系统的哪个真实需求，要么补上真实实现，要么在清单里改成实际对应的服务类。

---

### ☐ D3. `LegacyEdgeToolService::logisticsFallbackProviders()` 状态描述过期
该方法把百度备用物流查询标注为 `'placeholder'`（"未复制旧密钥或代理"），但 `ExpressLogisticsService.php` 里百度查询其实已经是真实实现（约 322-484 行，真实请求 + HTML 解析 + 缓存）。该方法目前也没有调用方（死代码）。

**要求**：要么删除这段过期代码，要么更新其状态描述并接上真实调用方。
