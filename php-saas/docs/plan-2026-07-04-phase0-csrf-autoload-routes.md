# 方案：架构重构阶段 0 —— CSRF 防护 + Composer 自动加载 + 路由表抽出

> 日期：2026-07-04。状态：方案定稿，待 Codex 实施。
> 属于 [plan-2026-07-04-architecture-roadmap.md](plan-2026-07-04-architecture-roadmap.md) 的阶段 0。
> 前提：自定义采购状态任务已验收，开工前先把当前工作区提交。

## 目标

1. 全站 POST 请求强制 CSRF 校验（已知硬伤，上生产前必须解决）。
2. Composer PSR-4 接管自动加载，删除手写 spl_autoload_register。
3. 路由表从 public/index.php 抽到 app/Http/routes.php，入口只留引导。

## 现状事实

- 无任何 CSRF 防护，只靠 SameSite=Lax cookie 部分缓解（docs/code-review-2026-06-20.md 第 2 条）。
- 路由 122 条全部显式注册在 public/index.php；POST 路由约 70 条。
- 自动加载：public/index.php 内手写 spl_autoload_register；vendor/autoload.php 目前是
  **条件加载**（is_file 判断）——PhpSpreadsheet 已是硬依赖，条件加载已无意义。
- 会话：由 AuthService 管理（cookie httponly + SameSite=Lax），需确认 session_start 时机，
  CSRF token 需要会话先于路由分发可用。
- bin/ 下有 CLI 脚本（定时任务入口），有自己的引导，不走 Router——改造不得破坏它们。
- 视图内表单众多（tenant/admin 全部 POST form），public/assets/app.js 里也有程序化提交。

## 设计

### A. CSRF

1. **helpers.php 新增**：
   - `csrf_token(): string`——首次调用生成 32 字节随机 token 存 `$_SESSION['_csrf']`，后续复用；
   - `csrf_field(): string`——返回 `<input type="hidden" name="_token" value="...">`（已转义）。
2. **强制校验点放在 Router::dispatch**（唯一入口，不可绕过）：
   - `$method === 'POST'` 时，校验 `$_POST['_token'] ?? '' ` 与
     `$_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''` 之一等于会话 token（hash_equals）；
   - 失败：HTTP 419 + 友好错误页（"页面已过期，请刷新后重试"），不执行 handler；
   - 不设豁免清单（Yahoo OAuth 回调是 GET，不受影响；如实施中发现确需豁免的外部回调
     POST 路由，单独列出并在小结说明理由）。
3. **会话时机**：把 session 启动统一收到 public/index.php 引导段（dispatch 之前），
   与 AuthService 现有 cookie 参数保持一致（httponly + SameSite=Lax），避免两处配置漂移。
4. **表单改造**：全部 Views 里的 `<form method="post">` 内加 `<?= csrf_field() ?>`。
   用 grep 找全，不允许漏（漏一个该功能就 419）。
5. **JS 程序化提交**：两个布局（layouts/tenant.php、layouts/admin.php、layouts/auth.php）
   `<head>` 加 `<meta name="csrf-token" content="...">`；app.js 审计所有 fetch/XHR/动态
   构造 form 的 POST，统一附带 `X-CSRF-Token` 头或 `_token` 字段。
6. **登录表单同样带 token**（登录前会话已存在，token 可用；登录成功后
   session_regenerate_id 不清空 `$_SESSION`，token 自然延续，无需特殊处理——实施时验证这一点）。

### B. Composer PSR-4

1. composer.json 加：`"autoload": {"psr-4": {"Xizhen\\": "app/"}}`，跑 `composer dump-autoload`。
2. public/index.php：vendor/autoload.php 改为**无条件 require**；删除手写 spl_autoload_register。
3. 检查 bin/ 下 CLI 脚本的引导：若有自带 spl_autoload_register 或手工 require 链，
   统一改为 require vendor/autoload.php + require helpers.php。
4. 部署文档口径：composer install --no-dev 成为部署必要步骤（CLAUDE.md 已有此说明，核对即可）。

### C. 路由表抽出

1. 新建 `app/Http/routes.php`：内容为一个函数
   `return static function (Router $router, AdminController $admin, TenantController $tenant): void { ...全部 122 条注册... };`
2. public/index.php 引导段：构造依赖 → `(require BASE_PATH . '/app/Http/routes.php')($router, $admin, $tenant);` → dispatch。
3. 路由内容**逐字搬移**，一条不增一条不减；入口文件目标 <60 行。

## 明确不做（留给后续阶段）

- 参数路由/路由分组/中间件管道（阶段 3）；
- 控制器与数据层拆分（阶段 1、2）；
- 任何业务逻辑改动。

## 测试要求

- 新增 `tests/csrf_test.php`：token 生成稳定（同会话两次调用一致）、csrf_field 输出转义、
  校验函数 hash_equals 行为（正确 token 通过、错误/空 token 拒绝）。
  （Router 层 419 属 HTTP 行为，CLI 测试覆盖校验函数即可。）
- 全部既有 tests/*.php 通过。
- **手工冒烟清单**（Codex 在本地 php -S 环境逐项验证并在小结报告）：
  超管登录/登出、租户登录/登出、新增租户提交、租户设置保存、采购状态保存、
  店铺新增、订单子项保存、批量改状态、邮件设置保存——均须正常（200/302），
  并验证一次去掉 _token 的 POST 确实被 419 拒绝。

## 验收清单

- [ ] 任意无 token / 错 token 的 POST 被 419 拒绝；带 token 的全部功能正常。
- [ ] app.js 所有程序化 POST 已带 token（列出改动位置）。
- [ ] 手写 spl_autoload_register 已删除，composer dump-autoload 后全站可跑。
- [ ] bin/ CLI 脚本仍可运行。
- [ ] public/index.php <60 行；routes.php 与原路由逐条一致。
- [ ] 全部测试通过 + 冒烟清单全绿。
