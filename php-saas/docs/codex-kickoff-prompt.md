# Codex 启动提示词 — php-saas 功能迁移

> 用法：把下面「===== 提示词开始 / 结束 =====」之间的全部内容，复制粘贴给 codex 作为首条消息。
> codex 作为「主控 agent」先打地基，再派出多个子 agent 并行实现互不冲突的任务，自己负责合并、解冲突、回写清单。
> 想让它推进下一批时，回复「继续下一批」即可。

---

===== 提示词开始 =====

你是一名资深 PHP 工程师，接手一个**功能迁移**任务：把老系统 `old/` 的业务功能逐项迁移到新系统 `php-saas/`。

## 0. 第一步：先读，别急着写

动手前，**必须按顺序读完**以下文件，建立上下文：

1. `php-saas/CLAUDE.md` — 项目用途、技术栈、架构、约定（最重要）
2. `php-saas/docs/migration-todo-from-old.md` — 完整迁移待办清单（你要做的事）
3. `php-saas/docs/code-review-2026-06-20.md` — 已知安全/并发债（别加重它们）
4. `php-saas/public/index.php` — 路由总表
5. `php-saas/app/Core/StoreInterface.php` — 数据层契约
6. 你当前要做的那一项任务，对应的 `old/` 源文件（清单里每项都标了 old 文件路径）

读完后，用 3-5 句话向我复述你对项目架构和本次任务边界的理解，确认无误再开工。

## 1. 项目速览

- `php-saas/` 是对 `old/`（远古单租户 PHP 系统，PHP5 风格 mysql_*、明文密码、无框架）的**重构版**：PHP 8.4、零框架、零 Composer 依赖、多租户 SaaS。
- 数据层双驱动：`DATA_DRIVER=json|mysql`，`JsonStore` 与 `MysqlStore` 实现同一个 `StoreInterface`。默认 JSON（`php-saas/storage/data/app.json`）。
- 视图是纯 PHP 模板（`php-saas/app/Views/`），命名空间 `Xizhen\`。
- 启动本地服务自测：`cd php-saas && php -S 127.0.0.1:8090 -t public`，访问 `http://127.0.0.1:8090/?tenant=erp`。
- `old/` 是**只读参考**，理解业务逻辑用，**绝不修改 old/**。

## 2. 铁律（不可违反）

1. **数据层双实现必须同步**：凡改 `StoreInterface`，`JsonStore.php` 和 `MysqlStore.php` 两个实现都要改，行为保持一致。漏改一个就是 bug。
2. **视图输出一律用 `e()` 转义**（`php-saas/app/Core/helpers.php`），禁止裸 `echo $变量`。
3. **不要把 old 的坏习惯带过来**：
   - 不用 `mysql_*` 函数；新系统数据访问只走 Store 层。
   - 密码不存明文（用 password_hash），凭据不写 cookie。
   - **old 里硬编码的密钥（OpenAI key、SOCKS5 代理、ShowAPI appid/secret、数据库密码等）一律不要复制进 php-saas**。需要这些配置时，走环境变量或租户配置项，值留空占位并告诉我去配。
   - SQL 一律用 PDO 预处理绑定参数，列名/表名用白名单，不拼接用户输入。
4. **多租户隔离**：所有数据读写都要带 tenantKey，别让一个租户读到另一个租户的数据。
5. **守住现有安全水位**：新增写操作沿用现有权限校验（`requireTenant`/`requireTenantPermission`/`canAccessStore`）；IDOR 要校验资源归属当前租户与用户店铺范围。
6. **零依赖优先**：能用 PHP 内置实现就别加 Composer 包。确需第三方库（例如 Excel 导出 PhpSpreadsheet）时，**先停下来问我**再引入。
7. 沿用 old 的**业务规则与字段语义**（状态名、平台代码 y/w/r/m/q/yp、扣点/汇率/运费算法等），不要自创口径。

## 3. 工作流程（主控 agent + 子 agent 并行）

你是**主控 agent**，不亲自写每一行业务代码，而是负责：打地基、拆任务、派子 agent、合并结果、解冲突、回写清单、提交。

1. **先打地基（必须串行，主控亲自做）**：先完成 P0 第 1 项「订单详情字段补全」，因为它改 `StoreInterface` + 两个 Store 实现 + 订单详情视图，是后面几乎所有任务的数据基础。地基没落地前**不要并行**，否则子 agent 全在缺字段的状态上返工。
2. **按"批"推进**：地基完成后，从待办里挑出**一批互不冲突**的任务（见第 3.5 节的分批与冲突规则），为每项派一个子 agent 并行执行。
3. **给每个子 agent 的指令必须包含**：目标任务、对应 old 源文件路径、必须先读的 php-saas 文件、第 2 节全部铁律、它**允许改哪些文件 / 禁止改哪些文件**（防冲突）、完成标准（`php -l` 通过 + 自测主路径）。
4. **主控合并**：子 agent 返回后，你逐个审查产出，合并到工作区；若多个子 agent 都需要碰同一共享文件（路由表、Store、TenantFeature），由**你统一改这个共享文件**，子 agent 只返回"需要我加的路由/方法/字段"清单。
5. **回写待办**：把 `migration-todo-from-old.md` 里完成项的 `☐` 改成 `✅`，并在该行补「(commit: <短哈希或简述>)」。
6. **提交**：每个逻辑任务一条 conventional commit（如 `feat: 补全订单详情字段（material/tabaono/采购链接等）`）。**只提交 php-saas 内改动，不碰 old/**，信息末尾不加任何署名。
7. **汇报**：一批做完后向我汇报：这批做了哪些项、派了几个子 agent、改了哪些文件、怎么测的、有没有冲突或需要我决策的点（引库、字段命名）。等我说「继续下一批」。

## 3.5 并行编排策略（关键，防止子 agent 互相覆盖）

**共享文件 = 冲突热点，由主控独占修改，子 agent 不准直接写：**
- `php-saas/public/index.php`（路由总表）
- `php-saas/app/Core/StoreInterface.php`、`JsonStore.php`、`MysqlStore.php`（数据层）
- `php-saas/app/Core/TenantFeature.php`、`Permission.php`
- `php-saas/app/Controllers/TenantController.php`（如多任务都加方法，集中由主控加，或拆分到各自的 Service/独立控制器）

子 agent 应尽量**只在自己新建的文件里写**（新 Service 类、新 View 模板、新 CLI 脚本），把"需要挂到共享文件上的东西"（路由行、Store 方法签名、Feature 开关、权限点）**以清单形式返回给主控**，由主控统一落到共享文件，避免并行写同一文件。

**并行分批建议（每批内部互不冲突，可同时派子 agent）：**

- **第 0 批（串行打地基，主控亲自做）**：P0-1 订单详情字段补全。
- **第 1 批（地基后，可并行 3 个子 agent）**：
  - 子A：P0-2 发货流程 sendjp/sendxizhends
  - 子B：P0-3 采购人自动记录 + P0-4 同品项同步修改（同属订单保存逻辑，交给同一个子 agent 串着做，避免两个 agent 抢订单保存代码）
  - 子C：P0-5 订单状态变更日志
  - （P0-6 高级筛选条件因为要动订单列表查询，与上面订单保存类任务易冲突，放到下一批或由主控收口）
- **第 2 批（平台同步，先串行再并行）**：主控先建好 `PlatformOrderSyncInterface` 抽象（P0-7 的接口部分），然后并行派 5 个子 agent，每个实现一个平台（Wowma / Yahoo / Mercari / Qoo10 / 雅虎拍卖），它们各写各的 Service 文件，天然不冲突。
- **第 3 批（物流，可并行 3 个子 agent）**：子A=1688 物流(P0-8)、子B=日本物流(P0-9)、子C=cron 框架(P0-10)。三者各写独立文件；cron 框架先定好"任务注册约定"，物流子 agent 按约定挂上去。
- **第 4 批**：P0-11 导入字段核对、P0-12 利润分摊（各自独立，可并行）。
- **P1/P2**：多数是新页面/新工具（核价计算器、各统计页、各导出），彼此独立，按每项一个子 agent 大批并行；只在共享文件落点时回主控收口。

**并行上限**：一批最多同时跑 5 个子 agent，避免你自己 review 不过来、也减少合并冲突面。每批结束 `php -l` 全量过一遍、起本地服务点主路径，确认没串味再开下一批。



## 4. 待办清单（按优先级，逐项完成）

> 状态：☐ 未做　◐ 部分　✅ 完成。每项后括号是 old 对应文件/说明。
> 权威清单以 `php-saas/docs/migration-todo-from-old.md` 为准，以它为准同步勾选。

### P0 核心（先做，按下面顺序）
1. ☐ 订单详情字段补全（old `inc_order_detail_default.php`：material 材质、tranship_comment 跨境备注、tabaono 1688单号、caigoulink 采购链接、caigoutime 采购时间、caigou_ordernums、cnamount/comamount、weight 等）—— **数据基础，必须最先做**，后面多项依赖它。
2. ☐ 发货流程 sendjp / sendxizhends（批量「已发日本」状态 + 西阵发货）
3. ☐ 采购人自动记录（首次填 tabaono 时自动记 caigou_user）
4. ☐ 同品项同步修改（改 material/tranship_comment 时同步同 ItemId 其他行）
5. ☐ 订单状态变更日志（改 beizhu 状态写流转历史）
6. ☐ 订单高级筛选条件（old `inc_list_default.php` 的 25+ 筛选项）
7. ☐ 平台订单同步服务 —— 先抽象 `PlatformOrderSyncInterface`（以 `RakutenOrderService` 为范本），再逐平台实现：Wowma(`plugins/wmshopapi/`)、Yahoo(`plugins/yahooshop-api/`)、Mercari(m)、Qoo10(q)、雅虎拍卖(yp)
8. ☐ 1688 采购物流：真实 API 对接 + 自动同步（old `plugins/1688api/func.php` + `cron/update_1688_logistics.php`）
9. ☐ 日本国际物流：佐川/日本邮政/雅玛多查询 + 自动同步（old `plugins/jpshipinfo/`、`sagawa-shipinfo/` + `cron/update_jpship_logistics.php`）
10. ☐ 定时任务(cron)框架（承载上面的物流/订单同步；建议系统 cron 调用 CLI 入口）
11. ☐ 订单导入字段映射核对（old `orderinsert.php` 各平台 20+ 字段 vs `CsvImportService`，尤其新补的详情字段）
12. ☐ 利润分析：运费/邮费分摊 + 店铺扣点优先级（old `plugins/profit-analysis/`：多商品按数量分摊国际运费、Y/R 分摊日本邮费、按店铺 profit_deduction 优先）

### P1 常用
- ☐ 国内快递查询 ShowAPI / TB·PDD（old `plugins/express-showapi/`）
- ☐ 异常运费检测（old `plugins/shipping-anomaly/`；启用 TenantFeature 的 stats.shipping_anomaly）
- ☐ 运单核对 checkyd / 日本快递跳转 jpyd-check
- ☐ 采购状态每日统计（old `plugins/caigou_status/`：状态分布+日环比+平台分别+图表）
- ☐ 业绩汇总 按店铺/平台（old `performance/summary.php`；启用 stats.performance）
- ☐ 出单商品分析 热卖排名（old `performance/product_analysis.php`；启用 stats.products）
- ☐ 业绩面板/日统计 AJAX（old `performance/index.php`）
- ☐ 核价计算器（old `price_calculator.php`，多行成本核算工具，php-saas 完全无）
- ☐ 采购统计补全（补日视图、用户追溯维度）
- ☐ 多平台特定导出（old outexcel-riya/sx/wd/weier、outexcel_qoo10、outexcel_wowma 等平台专用发货单）
- ☐ 财务导出嵌图片 + Excel 样式（old `outcwexcel.php`；需 Excel 库，先问我）
- ☐ 客户资料 Excel 样式导出（old `custinfo_export.php`）
- ☐ 财务数据导入·运单号模糊匹配（old `caiwu_new_import.php`）
- ☐ 国际运单导入：追加 vs 覆盖模式（old `shipping_import.php` 第三列控制）
- ☐ 日本仓订单导入 YD表（old `ydinsert.php`）
- ☐ 员工自助改密码（old `pwdedit.php`）
- ☐ 租户内通知公告系统（old `notice/`：租户管理员发公告 + 订单页内展示）
- ☐ 细粒度权限编辑 UI（old `user_permissions.php`，单用户权限 override）
- ☐ 客服扣点快捷编辑（old 用户列表内快编 profit_deduction）

### P2 边缘（可延后）
- ☐ AJAX 体验类：order_row 异步刷新行、order_detail 异步详情、logistics_reload 异步刷物流、toggle_review 邀评/已评切换
- ☐ 刷单导出 outshuadan.php
- ☐ 运单/订单外部插入 ydorderinsert.php
- ☐ 物流查询百度备用方案（ShowAPI 失败降级）
- ☐ 物流编号对照表 / ShowAPI / OBAPI缓存 / 两步验证密码 等 setting 项（部分由超管维护，先确认是否暴露给租户）
- ☐ 行内编辑 / 编辑侧栏（UI 优化，详情页可替代）
- ☐ 动态组件配置 component-ini（先确认是否还需要）

> 注：「品检」功能已确认不需要，不要迁移。

## 5. 现在开始

按第 0 节先读完文件并向我复述理解。确认后：
1. **你（主控）亲自串行做第 0 批地基**：P0-1 订单详情字段补全（改 StoreInterface + 两个 Store + 详情视图）。这一步不并行。
2. 地基落地、`php -l` 通过后，按第 3.5 节的「第 1 批」**并行派出子 agent**（最多 5 个），每个子 agent 给足：任务、old 源文件、必读 php-saas 文件、第 2 节铁律、允许/禁止改的文件清单、完成标准。
3. 子 agent 返回后由你合并、收口共享文件、回写清单、分别提交，然后向我汇报这一批结果，等我说「继续下一批」。

先读文件、复述理解、并把你的"第 0 批 + 第 1 批"具体编排方案列给我看，等我确认后开工。

===== 提示词结束 =====
