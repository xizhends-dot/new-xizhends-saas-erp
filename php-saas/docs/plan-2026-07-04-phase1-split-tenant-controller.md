# 方案：架构重构阶段 1 —— 拆分 TenantController（纯搬家）

> 日期：2026-07-04。状态：方案定稿，待 Codex 实施。
> 属于 [plan-2026-07-04-architecture-roadmap.md](plan-2026-07-04-architecture-roadmap.md) 的阶段 1。
> 前提：阶段 0 已验收提交。开工时工作区必须干净。

## 目标

- `app/Controllers/TenantController.php`（3311 行）按领域拆为 ~11 个控制器，
  全部迁入 `app/Http/Controllers/Tenant/`；
- `app/Controllers/AdminController.php` 原样迁入 `app/Http/Controllers/Admin/`（不拆，体量未超标）；
- URL 一条不变、行为一字不变、全部测试通过。

## 铁律

1. **方法体逐字搬移**。任何"顺手优化"（重命名、改逻辑、格式重排）一律禁止——
   本阶段 diff 必须呈现为"纯移动"，便于审查与回滚。
2. 拆完后 `app/Controllers/` 目录删除；`routes.php` 是唯一需要改指向的地方。
3. 单文件目标 <800 行；若某控制器搬完仍超标（预计 ImportExportController 可能），
   允许在**同域**内再拆一个副控制器（如 ImportController/ExportController），仍是纯搬家。

## 目标结构与路由归属

依据 `app/Http/routes.php` 现有 121 条路由，按路径前缀归домах：

| 新控制器（Xizhen\Http\Controllers\Tenant\） | 承接路由 |
|---|---|
| AuthController | /login /logout /password/* |
| DashboardController | / /features /search |
| OrderController | /orders /orders/detail /orders/source /orders/batch /orders/export /orders/send-jp /orders/xizhen-delivery/export /orders/logistics/update /orders/platform/sync /orders/rakuten/sync /orders/item/save /orders/attachments/* /orders/images/upload |
| OrderAjaxController | /orders/ajax/* |
| LogisticsController | /logistics/* |
| MailController | /mail /mail/* |
| ImportExportController | /import-export /import-export/* |
| StatsController | /analytics/profit /performance* /stats/* /price-calculator* |
| StoreController | /stores /stores/* /oauth/yahoo/callback |
| UserController | /users /users/* /assignments /assignments/* |
| SettingsController | /settings /settings/* /notices /notices/* /billing /media /jobs /logs |

Admin 侧：`Xizhen\Http\Controllers\Admin\AdminController`（整体搬移改命名空间，不拆）。

## 共享上下文的处理

TenantController 现有跨域私有辅助（`forbid`、`requireTenantFeature`、
`ensurePlatformFeatureAccess`、`currentUserName`、`tenantStorageKey`、批量选择解析等）：

- 新建抽象基类 `app/Http/Controllers/Tenant/TenantBaseController.php`：
  - 构造函数与现 TenantController 相同（store/view/auth），内部按现状构造共享服务
    （AppService 等），属性改 `protected`；
  - **只被 ≥2 个新控制器使用**的私有辅助方法迁入基类（改 protected），逐字搬移；
  - 只被单一域使用的辅助方法跟随该域控制器走，保持 private。
- 各域控制器 `extends TenantBaseController`；域内独享的服务（MailService、
  CsvImportService 等）移到对应控制器构造/惰性初始化，保持现有构造方式不变。

## routes.php 改造

- 构造段：`$tenant = new TenantController(...)` 替换为 11 个新控制器实例化
  （依赖同现状：store/view/auth）；
- 121 条路由逐条把 `[$tenant, 'method']` 换成对应新控制器实例；
  **路径字符串一个字符都不许动**；
- `$admin` 改为新命名空间的 AdminController。

## 执行顺序建议（小步可验证）

1. 建基类 + AuthController（最小域）→ 改 routes 指向 → 冒烟登录/登出；
2. 逐域搬移（每搬一个域跑一次 php -l + 相关冒烟）；
3. 全部域搬完 → 删除 app/Controllers/ 旧文件 → composer dump-autoload；
4. 全量测试 + 完整冒烟。

## 明确不做

- 不改任何业务逻辑、权限判定、渲染参数；
- 不引入容器/中间件（阶段 3）；
- 不动 Services / Store 层（阶段 2）。

## 测试与验收

- 既有 `tests/*.php` 全部通过（本阶段不新增测试——无新逻辑）。
- `php -l` 全部新文件通过。
- 冒烟清单（php -S 环境逐项验证并在小结报告）：
  租户登录/登出、首页、平台订单列表、订单详情、子项保存、批量改状态、采购视图、
  日本仓视图、1688 物流页、邮件中心、导入导出页、业绩面板、利润分析、店铺管理、
  员工管理、系统设置（含采购状态 tab 保存）、通知公告、积分账单、
  超管登录、超管租户/计费/平台/新增租户各页。
- 验收：
  - [ ] app/Controllers/ 目录已删除；
  - [ ] 每个新控制器 <800 行；
  - [ ] routes.php 路径与阶段 0 版本逐字节一致（仅 handler 目标变化）；
  - [ ] diff 呈"纯移动"形态，无逻辑改动；
  - [ ] 测试全绿 + 冒烟全过。
