# 方案：租户一键开通（新增租户页面 + 自动建库 + DSN 动态解析）

> 日期：2026-07-04。状态：方案定稿，待 Codex 实施。
> 背景：部署目标为 `saas.xizhends.com`（超管）+ `{subdomain}.xizhends.com`（租户）。
> 现状痛点：php-saas 超管后台没有新增租户功能；租户库 DSN 只能靠环境变量
> `MYSQL_TENANT_DSN_<KEY>` 接线，每加一个租户都要手动建库、跑迁移、改服务器配置并重启。

## 目标

1. 超管后台新增「新增租户」页面，一次表单提交完成：主库登记 + 自动建库 + 跑租户迁移 + 默认平台授权 + 计费账户 + 初始公司管理员。
2. 租户库连接改为从主库 `tenants.db_dsn_enc` 动态解析（环境变量仍可覆盖，向后兼容），新增租户**无需改配置、无需重启**。
3. `JsonStore` 与 `MysqlStore` 行为保持一致（JSON 模式无建库概念，只登记档案）。

## 现状事实（已核实）

- 租户识别：`app/Core/helpers.php:17` `tenant_key_from_host()` 从 Host 解析子域名作为
  tenant key，`saas.xizhends.com` 硬编码为超管域名；key 与主库 `tenants.subdomain` 匹配。
- `tenants` 表（`migrations/master/0002` + `0008` 扩展）：`company_name / company_short_name /
  subdomain(唯一) / db_dsn_enc / plan / status / staff_count / contact_* / address / remark`。
- `MysqlStore::tenantPdo()`（`MysqlStore.php:2611`）目前只认 `Config::tenantDsn()` →
  环境变量 `MYSQL_TENANT_DSN_<KEY>`，`db_dsn_enc` 仅作列表页展示标签。
- 所有连接（主库+租户库）共用 `MYSQL_USER` / `MYSQL_PASSWORD`（`MysqlStore::connect()`）。
  ⇒ **DSN 本身不含凭据**，`db_dsn_enc` 存明文 DSN（`mysql:host=..;dbname=..;charset=utf8mb4`）
  即可，无需引入加密（列名保留不动，避免改 schema）。
- 租户迁移脚本：`../migrations/tenant/0001~0007`，纯 `CREATE TABLE IF NOT EXISTS` / `ALTER` /
  `INSERT`，无 DELIMITER/触发器/存储过程 ⇒ 可用「去注释 + 按 `;` 分割」的简单执行器。
- 超管路由现状：只有 `GET /admin/tenants`（只读列表）。
- 初始管理员：租户库 `users` 表，`password_hash`（Argon2id，与 `AuthService` 一致）、
  `is_company_admin=1`、`is_active=1`。
- 默认平台授权：主库 `platforms.default_enabled=1` 的平台应插入 `tenant_platform`。
- 计费账户：`MysqlStore::ensureBillingAccount()` 已存在，可复用；初始积分走
  `adjustTenantPoints()` 记流水。

## 设计

### A. DSN 动态解析（MysqlStore::tenantPdo 改造）

解析顺序：

1. `Config::tenantDsn($key)`（环境变量 `MYSQL_TENANT_DSN_<KEY>`，向后兼容 erp/tokyo/demo）；
2. 为空则查主库：`SELECT db_dsn_enc FROM tenants WHERE subdomain = ?`，非空且以 `mysql:`
   开头则用它连接；
3. 都没有 → 返回 null（行为同现状）。

按 tenantKey 缓存结果（含"查过但没有"的负缓存，避免每请求重复查主库）。

### B. 新增租户页面

- 路由：`GET /admin/tenants/create`（表单）、`POST /admin/tenants/create`（提交），
  注册在 `public/index.php`；`AdminController` 新增 `tenantCreateForm()` / `tenantCreate()`，
  均 `requireAdmin()`。
- 列表页 `app/Views/admin/tenants.php` 顶部加「新增租户」按钮。
- 表单字段：
  | 字段 | 必填 | 校验 |
  |------|------|------|
  | 公司名 company_name | ✅ | 非空，≤128 字符 |
  | 公司简称 company_short_name | ❌ | ≤64 |
  | 子域名 subdomain | ✅ | `^[a-z0-9][a-z0-9-]{0,62}$`；保留字黑名单 `saas,www,admin,mail,api,static`；主库唯一性查重 |
  | 数据库名 db_name | ✅ | 默认自动填 `xizhen_tenant_{subdomain}`（`-`→`_`）；`^[a-z0-9_]{1,64}$` |
  | 数据库主机 db_host | ✅ | 默认 `127.0.0.1`（或从主库 DSN 解析出的 host） |
  | 套餐 plan | ✅ | basic/pro/ent 下拉 |
  | 联系人/电话/邮箱/微信/地址/备注 | ❌ | 长度限制 |
  | 初始管理员用户名 admin_username | ✅ | 非空 ≤128 |
  | 初始管理员密码 admin_password | ✅ | ≥8 位 |
  | 初始积分 initial_points | ❌ | ≥0 整数，默认 0 |
- 页面显示访问地址预览：`https://{subdomain}.xizhends.com`。
- 校验失败回显错误与已填值；成功后跳转 `/admin/tenants?message=...`。

### C. 开通流程（新服务 `app/Services/TenantProvisioningService.php`）

MySQL 模式下 `POST` 依序执行：

1. **校验**：字段规则 + `subdomain` 查重 + 目标库不存在（或存在但无表，提示冲突）。
2. **建库**：`CREATE DATABASE IF NOT EXISTS \`{db}\` CHARACTER SET utf8mb4 COLLATE
   utf8mb4_unicode_ci`。库名不能参数化，**必须**先过 `^[a-z0-9_]{1,64}$` 白名单再反引号拼接。
3. **跑迁移**：按文件名顺序执行 `BASE_PATH/../migrations/tenant/*.sql`。
   执行器：逐行剥离 `--` 注释 → 按 `;` 分割 → 逐条 `exec()`；空语句跳过。
4. **主库登记**（事务内）：INSERT `tenants`（status=active、staff_count=0、
   `db_dsn_enc = "mysql:host={db_host};dbname={db};charset=utf8mb4"`）；
   按 `platforms.default_enabled` 初始化 `tenant_platform`；`ensureBillingAccount`；
   初始积分>0 时 `adjustTenantPoints(..., 'recharge', '开通初始积分', 操作人)`。
5. **初始管理员**：向新租户库 `users` 插入公司管理员（Argon2id、is_company_admin=1）。
6. **失败补偿**：主库事务回滚；若数据库是本次新建的，迁移/后续步骤失败时 `DROP DATABASE`
   清理，把底层错误信息带回表单。**绝不 DROP 已存在的库**。

JSON 模式（`JsonStore`）：无建库概念，`createTenant` 仅追加租户档案 + 默认平台授权 +
计费账户 + 在该租户命名空间下建初始管理员，返回结构与 MySQL 版一致。

### D. 接口契约

`StoreInterface` 新增：

```php
/** @param array<string,mixed> $data
 *  @return array{ok: bool, message: string} */
public function createTenant(array $data): array;
```

两个实现必须行为一致（CLAUDE.md 双驱动约定）。建库/迁移等 MySQL 特有逻辑放
`TenantProvisioningService`，由 `MysqlStore::createTenant()` 调用；纯校验逻辑
（子域名/库名正则、保留字、SQL 分割器）做成可无 DB 单测的静态方法。

### E. 顺带项（小而值得）

- `helpers.php` 域名后缀改为可配置：`getenv('TENANT_BASE_DOMAIN') ?: 'xizhends.com'`、
  `getenv('SAAS_ADMIN_HOST') ?: 'saas.xizhends.com'`，默认值保持现行为不变。

### 范围外（本次不做）

- 编辑/停用/删除租户 UI；租户库连接池管理页；`db_dsn_enc` 加密。

## 运维前提（代码之外，部署时人工确认）

- `MYSQL_USER` 需要 `CREATE` 权限及对 `xizhen_tenant_*` 库的全部权限
  （如 `GRANT ALL ON \`xizhen\_tenant\_%\`.* TO ...`）。
- DNS 泛解析 `*.xizhends.com` → 服务器；Nginx `server_name saas.xizhends.com *.xizhends.com`
  同指 php-saas `public/`；泛域名 SSL 证书。

## 测试要求

沿用 `php-saas/tests/*.php` 纯 PHP 断言脚本风格，新增 `tenant_provisioning_test.php`：

- 子域名校验：合法/非法/保留字用例。
- 库名校验与默认生成（`abc-shop` → `xizhen_tenant_abc_shop`）。
- SQL 迁移文件分割器：注释剥离、多语句分割、字符串内分号不误切（现有迁移文件无此场景，
  但至少覆盖行内 `--` 注释与空语句）。
- `JsonStore::createTenant`：成功登记、子域名重复拒绝、初始管理员可用
  `AuthService` 口令校验通过。

## 验收清单

- [ ] MySQL 模式：表单提交一次 → 新库建成且 7 个迁移全部生效 → `tenants` 行存在 →
      默认平台授权 + 计费账户 + 初始积分流水 → 新库 `users` 有公司管理员。
- [ ] 不改任何环境变量、不重启 PHP 的前提下，`{subdomain}.xizhends.com`
      （本地用 `?tenant={subdomain}`）可用初始管理员直接登录。
- [ ] 子域名重复 / 库名非法 / 迁移失败时：表单回显错误，主库无残留行，本次新建的库被清理。
- [ ] `MYSQL_TENANT_DSN_ERP` 等既有环境变量接线仍然优先生效（回归不破坏）。
- [ ] JSON 模式下新增租户同样可登录（双驱动行为一致）。
- [ ] 所有新增测试与既有 `tests/*.php` 全部通过。
