# 方案：架构重构阶段 3 —— 微内核补齐（容器 / 中间件管道 / 路由升级 / 统一错误处理）

> 日期：2026-07-04。状态：方案定稿，待 Codex 实施。
> 属于 [plan-2026-07-04-architecture-roadmap.md](plan-2026-07-04-architecture-roadmap.md) 的阶段 3（最后一站）。
> 前提：阶段 2b 已提交（02a64bd），git status 干净。

## 与前几阶段的本质区别（先读懂再动手）

阶段 0-2b 是"纯搬家"；**阶段 3 是结构改造**，没有"逐字搬移"可依赖。
因此本阶段的硬约束改为**行为等价**：

> 每一条路由改造前后，对同一请求（同 URL/方法/登录态/权限）必须产生同样的
> 响应（状态码、跳转目标、渲染页面、错误文案）。守卫上收必须"镜像现状"，
> 不允许借机"顺便修正"任何看起来不合理的现有行为——发现疑似问题记录到小结，不改。

## 目标交付物

```
app/Core/
├── Container.php          # 极简构造注入容器（~120行）
├── Pipeline.php           # 中间件管道（~50行）
├── Middleware/
│   ├── MiddlewareInterface.php
│   ├── CsrfMiddleware.php         # 从 Router::dispatch 内联校验迁出
│   ├── AdminAuthMiddleware.php    # 镜像 AuthService::requireAdmin
│   └── TenantAuthMiddleware.php   # 镜像 AuthService::requireTenant
├── Router.php             # 升级：参数路由 + 分组 + 组级中间件 + 惰性控制器解析
└── ErrorHandler.php       # 统一异常/错误/shutdown 捕获 → 日志 + 友好错误页
```

## 设计

### A. Container（极简，不造轮子）

- `bind(string $id, callable $factory)` / `singleton(string $id, callable $factory)` /
  `make(string $id)`；
- `make` 对未注册的类走**反射自动装配**：构造参数按类型 `make()` 递归解析；
  遇到无类型/标量参数直接抛异常（要求显式注册工厂）；
- 显式注册（index.php 引导段）：
  - `Config` singleton → `Config::load(BASE_PATH)`
  - `StoreInterface` singleton → `StoreFactory::make($config)`
  - `View` singleton → `new View(BASE_PATH . '/app/Views')`
  - `AuthService` singleton → 自动装配即可
- 13 个控制器**不再在 routes.php 里预先 new**：路由表存
  `[控制器类名::class, '方法名']`，Router 命中后经 Container 惰性实例化
  （附带收益：每请求只构造命中的那 1 个控制器，不再全量构造 13 个）。

### B. Router 升级

- 保留精确匹配（现有 121 条不变）；新增参数段匹配：`/orders/{id}` 风格，
  参数注入 `$_GET`（`$_GET['id'] = 匹配值`，与现有"从 $_GET 取参"的控制器习惯兼容）
  ——**本阶段不迁移任何现有 URL**，仅提供能力；
- `group(array $middleware, callable $register)`：组内注册的路由挂上该组中间件；
- dispatch 流程：匹配路由 → 组装 `[全局中间件..., 组中间件...]` → Pipeline 依次执行 →
  终点经 Container 解析控制器并调用；
- 404 行为保持现状（后续由 ErrorHandler 美化，见 D）。

### C. 中间件与守卫上收（行为等价的核心区）

**镜像规则**：中间件内部**直接调用 AuthService 的现有方法**，不重写判定逻辑：

- `CsrfMiddleware`：把 Router::dispatch 里的 POST 校验 + 419 页迁出为全局中间件，
  文案与 419 页 HTML 保持逐字一致；
- `AdminAuthMiddleware`：调用 `$auth->requireAdmin()`（其内部 redirect+return 逻辑原样生效）；
- `TenantAuthMiddleware`：调用 `$auth->requireTenant(current_tenant_key())`。

**分组归属必须按"该方法现状是否调用粗粒度守卫"逐条核对**（不是按路径拍脑袋）：

1. 逐个控制器方法排查开头的粗守卫调用：
   - 方法体第一层直接调用 `$this->auth->requireAdmin()` → 该路由归入 AdminAuth 组；
   - 直接调用 `$this->auth->requireTenant(...)`，或调用的
     `requireTenantPermission / requireAnyTenantPermission / requireTenantCompanyAdmin /
     requireTenantFeature`（内部含 requireTenant）→ 归入 TenantAuth 组；
   - **两类都没有**（如 /admin/login、/login、/oauth/yahoo/callback、logout 等）→ 不挂 auth 中间件；
2. 归组后，从方法体里**只删除**与中间件重复的那一行粗守卫
   （`requireAdmin()` / 独立的 `requireTenant()`）；
   细粒度守卫（`requireTenantPermission` 等）**全部原地保留**——它们内部再调一次
   requireTenant 是幂等的，不删，避免误伤；
3. 在小结中给出《路由 → 中间件组 → 删除的守卫行》对照表，逐条可审。

### D. ErrorHandler

- `register()` 于 index.php 引导段调用：`set_exception_handler` +
  `set_error_handler`（仅 E_ERROR 级转异常，warning/notice 只记日志不改变行为）+
  `register_shutdown_function`（兜底 fatal）；
- 未捕获异常：写 `storage/logs/app-YYYY-MM-DD.log`（时间/URI/用户态摘要/堆栈），
  HTTP 500 + 友好错误页（复用 419 页同款样式；正文"系统开小差了，请稍后重试"）；
  `APP_DEBUG=1` 环境变量时页面附带异常摘要（本地调试用，生产不开）；
- AJAX 请求（`X-Requested-With: XMLHttpRequest` 或 Accept 含 application/json）
  返回 JSON `{ok:false, message:"服务器错误"}` + 500；
- 404 页面美化为同款样式（原来是裸 echo "页面不存在"）。

### E. index.php 引导段（改造后形态）

```
require autoload + helpers → start_xizhen_session() → ErrorHandler::register()
→ 构建 Container 并注册单例 → routes.php(闭包收 Container+Router)
→ $router->dispatch(method, path)
```

routes.php 改为按组注册：admin-auth 组 / tenant-auth 组 / 无守卫组，
路径字符串仍逐条与现状一致（一个字符不许动）。

### 明确不做

- 不迁移任何 URL 到参数路由；不改任何细粒度权限判定；
- 不动 Repository/Service 层；不引入第三方依赖；
- 不修 nextId 老 bug、不做胶水方法消重（列入阶段 3 后的收尾清单）。

## 测试要求

新增纯 PHP 测试（沿用 tests/*.php 风格）：

- `tests/container_test.php`：singleton 同一实例、接口绑定解析、反射自动装配、
  无类型参数抛异常；
- `tests/router_pipeline_test.php`：精确匹配优先、参数路由 `{id}` 提取、404、
  中间件执行顺序（全局→组→handler，用记录数组断言）、组隔离
  （A 组中间件不影响 B 组路由）；
- `tests/error_handler_test.php`：异常被记录到日志文件、日志含 URI 与异常类名
  （用临时日志目录，验证后清理）。

## 验收清单

- [ ] 121 条路由路径与阶段 2b 版本逐字一致（仅注册形式变化）；
- [ ] 《路由→中间件组→删除守卫行》对照表逐条与"方法现状守卫"吻合；
- [ ] 未登录访问 /admin → 跳登录带 return；未登录访问租户页 → 跳租户登录；
      停用租户访问 → 登出并提示（与现状文案一致）；
- [ ] 缺 token POST → 419（文案不变）；
- [ ] 人为制造异常（临时路由）→ 日志落盘 + 友好 500 页 + APP_DEBUG=1 显示详情；
      404 页新样式生效；
- [ ] AJAX 路由（/orders/ajax/*）鉴权失败与异常行为和现状一致；
- [ ] 每请求只实例化命中的控制器（在临时日志中验证一次后移除验证代码）；
- [ ] 全部既有 + 新增测试通过；登录态完整冒烟（三视图/详情/写路径/超管各页/
      邮件/导入导出/设置/账单）无 Fatal、行为无差异；bin/cron.php 不受影响。
