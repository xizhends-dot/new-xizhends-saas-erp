# 方案：php-saas 架构重构路线图（拆巨型类 + 微内核补齐 + MySQL 单驱动化）

> 日期：2026-07-04。状态：路线图定稿。执行方式：**分阶段、每阶段一个独立 Codex 任务**，
> 阶段之间提交 git，任何阶段可暂停不影响业务开发。
> ⚠️ 开工前提：等当前进行中的"自定义采购状态"任务落地并提交后再开始，避免大面积冲突。

## 现状体量（2026-07-04 实测）

| 文件 | 行数 | 问题 |
|------|------|------|
| app/Core/JsonStore.php | 4202 | 双驱动税：每个功能写两遍 |
| app/Core/MysqlStore.php | 4056 | 上帝类：全部领域的 SQL 都在这 |
| app/Controllers/TenantController.php | 3311 | 上帝控制器：122 条路由几乎全指向它 |
| app/Services/AppService.php | 1593 | 大杂烩：订单查询/菜单/仪表盘/目录混装 |
| app/Core/StoreInterface.php | 323 | 上帝接口：~100 个方法 |
| 其余 55 个 Service | 平均 <500 | **已经拆得不错，保持现状** |

结论：问题集中在 4 个文件 + 1 个接口，不需要全面重写。

## 三个方向性决策

### 决策 1：MySQL 单驱动化——JsonStore 降级为"测试替身"，不删除

生产环境只跑 MySQL（用户已确认）。但 JsonStore 不直接删：现有 12 个测试大量依赖它做
无数据库的逻辑测试，这个价值要保留。处理方式：

- **冻结**：从此新功能不再要求 JsonStore 对齐实现（CLAUDE.md 双驱动契约条款改掉），
  JsonStore 里未被任何测试使用的方法允许退化为空实现/抛异常。
- **定位改为 in-memory 测试替身**：只对齐"被测试用到"的方法子集。
- 部署侧：`DATA_DRIVER=mysql` 固定，`/admin/system` 保留驱动诊断页用于部署自检。
- 远期（阶段 3 后）再评估是否彻底移除。

### 决策 2：不手搓"大型框架"——升级微内核，大框架直接用 Laravel

**明确反对自研大型框架**：手搓一个"大型框架"= 花数月重新发明 Laravel 的残缺版，
没有生态、没有文档、没有安全补丁，之后每个新人（包括 AI 助手）都要先学你的私有框架。
正确的两层策略：

1. **现在**：把手搓微内核补齐到"结构完备的微框架"（阶段 3）——参数路由、中间件管道、
   CSRF、简单容器、统一异常处理。总量几百行，可控、可读、够用。
2. **将来**：功能膨胀到样板代码占开发时间一半时，迁 **Laravel**（CLAUDE.md 既定目标）。
   本路线图的拆分本身就是给 Laravel 铺路：拆完后 Controller→Controller、
   Service→Service、Repository→Eloquent 几乎一一对应，迁移成本大幅下降。

### 决策 3：拆分采用"门面过渡"策略，绝不大爆炸

`StoreInterface` 有 ~100 个方法、被全部控制器和服务引用，直接拆会一次性改动全项目。
过渡方式：MysqlStore 保留为**薄门面**（方法签名不变，内部委托给新 Repository），
调用方逐步改为直接注入 Repository；某方法无人再经门面调用时，从门面删除。
每一步都是小步提交、全程测试通过。

## 目标目录结构

```
app/
├── Core/                          # 微内核（阶段3补齐后 <800 行）
│   ├── Router.php                 # 升级：参数路由 /orders/{id} + 路由分组
│   ├── Pipeline.php               # 新增：中间件管道
│   ├── Middleware/                # 新增：Csrf / AdminAuth / TenantAuth / TenantActive
│   ├── Container.php              # 新增：极简构造注入容器（~100行）
│   ├── Db.php                     # 新增：连接管理（主库 + 租户库池），从 MysqlStore 抽出
│   ├── View.php / Config.php / Permission.php / TenantFeature.php   # 保持
│   └── ErrorHandler.php           # 新增：统一异常→日志→友好错误页
├── Http/
│   ├── Controllers/
│   │   ├── Admin/                 # 超管域（AdminController 338行 → 按需拆2-3个）
│   │   │   ├── OverviewController.php
│   │   │   ├── TenantAdminController.php     # 租户列表+一键开通
│   │   │   ├── BillingAdminController.php
│   │   │   └── PlatformAdminController.php   # 平台/功能授权+公告+设置+系统
│   │   └── Tenant/                # TenantController 3311行 → 按域拆 ~11 个
│   │       ├── AuthController.php            # 登录/登出/改密
│   │       ├── DashboardController.php       # 首页/功能台/搜索
│   │       ├── OrderController.php           # 三视图列表/详情/子项保存/批量
│   │       ├── OrderAjaxController.php       # ajax row/detail/logistics/review
│   │       ├── LogisticsController.php       # 1688/快递/日本/运单核对
│   │       ├── MailController.php            # 邮件中心全部
│   │       ├── ImportExportController.php    # 导入导出全部（最大的一块）
│   │       ├── StatsController.php           # 业绩/商品/采购统计/利润/异常/核价
│   │       ├── StoreController.php           # 店铺 CRUD + Yahoo OAuth
│   │       ├── UserController.php            # 员工/权限覆盖/客服扣点/分配
│   │       └── SettingsController.php        # 设置/采购状态/通知公告/账单/媒体/任务/日志
│   └── routes.php                 # 路由表从 public/index.php 抽出，按域分组
├── Repositories/                  # MysqlStore 按域拆（阶段2）
│   ├── TenantRepository.php       # 租户档案/平台授权/功能开关（主库）
│   ├── BillingRepository.php      # 积分账户/流水/订阅/扣费（主库）
│   ├── AdminRepository.php        # 超管/会话/公告/全局设置（主库）
│   ├── OrderRepository.php        # 订单+子项 CRUD/批量/归档（租户库）
│   ├── StoreRepository.php        # 店铺（租户库）
│   ├── UserRepository.php         # 员工/权限/分配（租户库）
│   ├── MailRepository.php         # 邮件账户/邮件/规则（租户库）
│   ├── MediaRepository.php        # 附件/图片（租户库）
│   └── SettingsRepository.php     # tenantSettings/导入导出日志/采购事件（租户库）
├── Services/                      # 55 个，保持；仅拆 AppService
│   ├── Orders/OrderQueryService.php      # AppService 的筛选/聚合/隐藏状态逻辑
│   ├── Orders/OrderViewService.php       # 三视图组装/菜单
│   └── DashboardService.php              # 仪表盘统计/功能目录
└── Views/                         # 保持
```

约束（沿用全局编码规范）：单文件 <800 行、函数 <50 行、按领域组织。

## 分阶段执行计划

### 阶段 0：安全与地基（小，先行，部署前必做）

1. **CSRF 中间件**：session token + 所有 POST 表单注入 hidden 字段 + 校验中间件
   （已知硬伤，2026-06-20 审查清单第 2 条）。
2. **Composer PSR-4 接管自动加载**：composer.json 加 `autoload.psr-4: {"Xizhen\\": "app/"}`，
   删除 index.php 里的手写 spl_autoload_register。
3. 路由表抽到 `app/Http/routes.php`（纯搬家，index.php 只留引导）。

产出小、风险低、立刻减负。

### 阶段 1：拆 TenantController（纯搬家，无逻辑改动）

- 按上面目录把 3311 行拆成 ~11 个控制器；方法体**逐字搬移**，禁止顺手改逻辑。
- 共享的私有辅助方法（forbid/ensurePlatformFeatureAccess/currentUserName 等）
  抽到 `Http/Controllers/Tenant/Concerns/TenantContext.php` trait 或基类。
- routes.php 更新指向；URL 路径一个都不变（对用户零感知）。
- AdminController 同法拆（体量小，顺带）。
- 验收：全部测试通过 + 手工冒烟每个页面可开。

### 阶段 2：拆数据层（门面过渡）

1. 从 MysqlStore 抽 `Core/Db.php`：主库连接 + 租户连接解析（含 db_dsn_enc 回退与负缓存）。
2. 按域建 Repository（上面 9 个），SQL 逐字搬移；MysqlStore 变薄门面全部委托。
3. AppService 拆为 OrderQueryService / OrderViewService / DashboardService。
4. JsonStore 冻结降级（决策 1），CLAUDE.md 双驱动条款改写。
5. 新代码（阶段 1 拆出的控制器）改为直接注入所需 Repository；
   门面方法无人调用后删除，StoreInterface 同步瘦身直至移除。

### 阶段 3：微内核补齐（"结构完备的微框架"）

- Router 参数路由（`/orders/{id}`）+ 分组 + 每组挂中间件；
- Pipeline + Middleware（Csrf/AdminAuth/TenantAuth/TenantActive——把散在每个
  控制器方法里的 requireXxx 调用上收到路由层）;
- 极简 Container：构造注入，替代 index.php 手工 new 的对象图；
- ErrorHandler：统一异常捕获 → storage/logs + 友好错误页（不再 echo 裸错误）。

### 阶段 4（另立项目，非本路线图承诺）：Laravel 迁移

拆分完成后评估。届时 Controller/Service/Repository → Laravel 同名概念直迁，
View → Blade 逐页迁，手写迁移 SQL → Laravel migrations。

## 风险控制

- 每阶段一个 Codex 任务、独立提交；阶段内小步提交。
- 阶段 1、2 是**纯结构移动**：diff 只有"搬家"，出问题 git revert 即回。
- 每阶段完成后跑全部 tests/*.php + 手工冒烟主要页面。
- 全程不冻结业务开发：新功能落在新结构里即可（如 PurchaseStatusService 已是范例）。
- 顺序不可换：先拆（1、2）再补内核（3），否则中间件/容器要在巨型类上返工。

## 验收（整体）

- [ ] 无任何单文件 >800 行（Views 除外）。
- [ ] public/index.php 只剩引导（<50 行）；路由按域分组且挂中间件。
- [ ] 全站 POST 有 CSRF 防护。
- [ ] MysqlStore/StoreInterface 门面消失，控制器/服务直接依赖领域 Repository。
- [ ] JsonStore 仅存于测试；CLAUDE.md 契约条款已更新。
- [ ] 全部既有功能 URL 不变、行为不变、测试全绿。
