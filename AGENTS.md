# AGENTS.md — AI 编码代理执行准则

> 对所有在本仓库工作的 AI 编码代理生效。只放**永久性准则**，不放任何具体任务——
> 任务指令一律以对话提示词下达。项目技术约定见 [php-saas/CLAUDE.md](php-saas/CLAUDE.md)。

## php-saas 系统定位与架构

`php-saas/` 是西阵跨境电商订单管理 SaaS 系统的新 PHP 主线。它不是“零架构”系统，而是**零外部 Web 框架、但有自研轻量架构**的系统：不依赖 Laravel/Symfony 等框架，使用 PHP 8.4、Composer PSR-4 自动加载、自研 Router、DI Container、中间件、控制器、服务层、仓储层和 PHP 视图模板组织业务。

核心业务是多租户订单管理：一套代码服务多个租户（公司），每个租户有自己的员工、店铺、订单、邮箱、物流和财务数据。生产入口规划为 `saas.xizhends.com` 超管后台与 `{tenant}.xizhends.com` 租户订单系统；本地开发用 `?tenant=erp` 兜底识别租户。

### 请求链路

- Web 入口是 `php-saas/public/index.php`：加载 Composer、`app/Core/helpers.php`，启动 session，注册错误处理，配置容器，创建 Router，并加载 `app/Http/routes.php`。
- 路由集中在 `php-saas/app/Http/routes.php`，Router 只做精确路径匹配，不支持参数路由。
- 全局 CSRF 由 `CsrfMiddleware` 接入：`routes.php` 中先注册 `$router->middleware([CsrfMiddleware::class]);`，所有 POST 表单必须带 `csrf_field()`。
- 登录态由中间件分层处理：超管路由使用 `AdminAuthMiddleware`，租户后台使用 `TenantAuthMiddleware`。
- 控制器位于 `php-saas/app/Http/Controllers/`：超管为 `Admin/AdminController.php`，租户侧按业务拆分在 `Tenant/` 下，例如订单、订单 AJAX、导入导出、物流、邮箱、设置、店铺、用户、统计等控制器。

### 依赖与分层

- `app/Core/Container.php` 是轻量 DI 容器；默认通过构造函数类型自动解析依赖。
- `StoreInterface` 是控制器和服务面向的数据访问边界。
- `StoreFactory` 根据配置选择 `MysqlStore` 或 `JsonStore`。
- `MysqlStore` 是生产驱动，内部委托 `app/Repositories/*` 访问 MySQL。
- `JsonStore` 只作为本地演示和现有测试替身；不要把它当作生产设计目标，但 `tests/*.php` 已覆盖的方法必须继续可用。
- 业务逻辑优先放在 `app/Services/*`，不要把新业务继续堆进控制器。
- 订单相关公共计算辅助位于 `app/Services/Concerns/OrderMathHelpers.php`。
- 视图位于 `app/Views/*`，布局在 `app/Views/layouts/*`，租户订单局部模板在 `app/Views/tenant/partials/*`。

### 数据与存储

- MySQL 是唯一生产驱动。涉及生产行为时，以 `MysqlStore` 和 `Repositories` 为准。
- `JsonStore` 的数据文件是 `php-saas/storage/data/app.json`，只用于本地演示、冒烟和测试替身。
- 修改数据层时要优先判断是否需要改 `StoreInterface`、`MysqlStore`、相关 Repository、以及测试替身；但新功能不要求无条件做 JSON/MySQL 双驱动完整对齐。
- 只要现有 `tests/*.php` 依赖某个 `JsonStore` 方法，该方法就必须保持兼容。

### 视图、安全与表单约定

- 所有视图输出必须使用全局 `e()` 转义。
- 所有 POST 表单必须包含 `csrf_field()`。
- 控制器处理返回地址时必须使用既有 `safeReturn()` 风格，避免开放重定向。
- 权限、租户、店铺范围过滤要沿用现有 `TenantBaseController`、`Permission`、`TenantFeature`、用户店铺范围等机制，不要绕过。

### 测试与验证

- 测试入口是 `php-saas/tests/*.php`，通常按 `php php-saas/tests/xxx_test.php` 单测，最终按仓库要求跑全量 `php php-saas/tests/*.php`。
- 改 PHP 文件后先跑 `php -l`。
- 涉及页面、登录态、数据落库的改动，要做登录态冒烟；冒烟不能只看 HTTP 状态码，必须确认渲染内容和数据变化，并恢复临时数据。
- 如果任务附带 `php-saas/docs/plan-*.md` 或明显对应某份方案文档，必须先完整读取方案，再按方案执行。

## 编码四准则

1. **先想后写**：动手前把假设显式写出来；需求有多种解读时列出来问用户，禁止静默挑一种；
   存在更简单的方案必须说出来。任务附带方案文档时，先完整读方案再动手。
2. **最简实现**：只写解决当前问题的最少代码。不加未被要求的功能、单处使用不建抽象、
   不加"以后可能用到"的配置、不为不可能的场景写错误处理。函数 <50 行、单文件 <800 行。
3. **外科手术式改动**：只动任务必须动的行，每行改动可追溯到任务要求。不顺手"改进"无关
   代码/注释/格式；发现无关的疑似 bug 或死代码——记录到小结，不改。自己改动产生的孤儿
   （不再使用的 import/变量/函数）要清理。结构性搬移守"纯搬家"：逐字移动，禁止顺手优化。
4. **目标驱动**：动手前定可验证的成功标准，完成后逐条自验：
   php -l 改动文件 → 全量 `php php-saas/tests/*.php` → 登录态冒烟
   （看渲染内容与数据落库，不许只看 HTTP 状态码）。自检不过不许交付。

## 本仓库红线

- **不要 git push**。是否 commit 以任务提示词的明确要求为准；未提及则不 commit。
- **生产部署流程固定为 Git 驱动**：后续不要再直接登录服务器修改生产代码；代码改动只在本地仓库完成，本地验证后 commit，再由本地 `git push` 到 GitHub，服务器只通过 `git pull` 更新。
- 本地推送远程仓库时需要走代理：`git -c http.proxy=http://127.0.0.1:7890 -c https.proxy=http://127.0.0.1:7890 push origin main`。如果用户明确要求 push，默认使用这个代理命令。
- 服务器 Git 根目录是 `/www/wwwroot/xizhends`，不是 `/www/wwwroot/xizhends/php-saas`；服务器更新命令固定为 `cd /www/wwwroot/xizhends && git pull --ff-only origin main`。宝塔站点目录仍是 `/www/wwwroot/xizhends/php-saas/public`。
- 服务器运行时内容必须保留，不要纳入 Git 或覆盖：`php-saas/vendor/`、`php-saas/storage/`、`php-saas/config/runtime_env.php`、`php-saas/public/.user.ini`。
- 视图所有输出走 `e()` 转义；所有 POST 表单带 `csrf_field()`。
- MySQL 是唯一生产驱动；JsonStore 只作演示/测试替身，被 tests/*.php 使用的方法须保持可用。
- `20251217/`、`old/`、`rust-backup/` 为只读参考资料，禁止修改。
- 冒烟产生的临时数据（storage/data/app.json、测试订单/店铺等）用完必须恢复/清理。
- 完成后输出小结：改动文件、关键决策、自检结果、发现但未改的问题。
