# CLAUDE.md — php-saas（西阵订单 SaaS PHP 重构版）

本文件给未来的 Claude 会话提供项目上下文。每次加载都会注入，因此只写**长期稳定**的信息（用途、架构、约定、启动方式）；会随时间变化的待办/审查发现请看 `docs/` 下的独立文档。

## 这个项目是做什么的

西阵跨境电商**订单管理 SaaS 系统**。一套代码服务多个租户（公司），每个租户有自己的员工、店铺、订单、邮箱。核心业务流：

- **多平台订单接入**：乐天（Rakuten/RMS API）等平台订单同步进来
- **货源改判**：把订单子项的采购货源在 1688 等渠道间改判，并自动驱动下游采购/发货队列
- **三视图工作流**：平台订单 → 采购订单 → 日本仓发货
- **物流**：1688 国内物流、日本物流、国际物流
- **邮件中心**：租户绑定自己的 IMAP/SMTP 邮箱，收发客服邮件、规则分拣
- **财务**：利润分析、采购统计、按店铺数量计费（开店费/月费/积分账本/欠费停用）
- **导入导出**：CSV 导入解析预览、导出
- **权限**：基于角色（公司管理员/采购/客服）+ 细粒度权限点 + 员工店铺范围过滤（历史"品检"角色已并入"采购"，见 [docs/audit-2026-07-02-fix-tasks.md](docs/audit-2026-07-02-fix-tasks.md) 第 6 项）

注：`saas.xizhends.com` 是超管后台，`{tenant}.xizhends.com` 是各租户订单系统。

## 技术栈与约定（重要）

- **PHP 8.4，零框架，Composer 仅用于 Excel 导出依赖**。当前已引入 `phpoffice/phpspreadsheet` 生成财务/客户资料 `.xlsx`；其余业务仍保持极简，先用 PHP 内置服务器跑通核心。
- **双存储驱动**：通过 `DATA_DRIVER=json|mysql` 切换。`StoreInterface` 是契约，`JsonStore` 与 `MysqlStore` 两套实现必须**行为一致**。
  - 缺 `pdo_mysql` 扩展或 DSN 未配置时自动回退 JSON，并在 `/admin/system` 显示原因。
  - JSON 数据落在 `storage/data/app.json`（单一大文件，所有租户共用）。
- **命名空间** `Xizhen\`，PSR-4 风格自定义自动加载（`public/index.php` 内），`app/` 为根。
- **路由**：全部在 `public/index.php` 显式注册，`Router` 只支持精确路径匹配（无参数路由）。
- **视图**：纯 PHP 模板（`app/Views/`），**所有输出用全局 `e()` 函数转义**（`htmlspecialchars` + ENT_QUOTES + UTF-8）。新增模板务必沿用。
- **租户识别**：子域名优先，本地开发用 `?tenant=erp` 兜底（见 `helpers.php` 的 `current_tenant_key()`）。
- **密码**：Argon2id（`AuthService`）；登录有失败限流、`session_regenerate_id`、httponly + SameSite=Lax cookie。
- **未来迁移目标**：Laravel（JsonStore→Eloquent，Router→routes，Views→Blade，app.json→migrations）。

## 启动

```bash
cd php-saas
php -S 127.0.0.1:8090 -t public
```

- 租户订单系统：http://127.0.0.1:8090/?tenant=erp
- 超管后台：http://127.0.0.1:8090/admin

切 MySQL（需 `pdo_mysql`）：设置 `DATA_DRIVER=mysql` 及 `MYSQL_DSN` / `MYSQL_USER` / `MYSQL_PASSWORD` / `MYSQL_TENANT_DSN_<TENANT>` 环境变量。主库用 `../migrations/master/`，租户库用 `../migrations/tenant/`。

## 目录结构

- `public/` — Web 入口（`index.php` 含路由表）、`assets/`（CSS/JS）
- `app/Core/` — 基础设施：`Router`、`View`、`Config`、`Permission`、`TenantFeature`、`StoreInterface` + `JsonStore`/`MysqlStore`/`StoreFactory`
- `app/Controllers/` — `AdminController`（超管）、`TenantController`（租户，最大）
- `app/Services/` — 业务服务：`AuthService`、`AppService`（订单/利润聚合）、`MailService`（IMAP/SMTP）、`RakutenOrderService`、`CsvImportService`、`LegacySettingsService`
- `app/Views/` — `admin/`、`tenant/`、`auth/`、`layouts/`、`tenant/partials/`
- `storage/` — `data/app.json`（JSON 模式数据）、`tenants/<key>/`（租户上传图片、1688 配置）、日志
- `config/app.php` — 驱动与 MySQL 连接配置
- `composer.json` / `composer.lock` — Excel 导出依赖（`PhpSpreadsheet`）；部署后执行 `composer install --no-dev`
- `docs/` — 数据库契约、迁移蓝图、路线图等

## 已知问题 / 待办

⚠️ 2026-06-20 做过一次全盘代码审查，发现若干安全与并发问题（含分级与修复优先级，也澄清了几处自动审查的误报）。动手改这些之前**务必先读**：

- [docs/code-review-2026-06-20.md](docs/code-review-2026-06-20.md)

两个最关键的硬伤（上生产前必须解决）：
1. **JsonStore 无文件锁/非原子写入**，高并发会丢数据（`JsonStore::save()`/`all()`）。
2. **全站无 CSRF 防护**（SameSite=Lax 仅部分缓解）。

### 功能迁移进行中（old → php-saas）

php-saas 是对 `../old/`（远古 PHP 单租户系统）的重构，目标是把 old 的全部业务功能迁过来。**邮件中心已补全**，但仍有不少功能未迁移。完整可勾选清单（按 P0/P1/P2 优先级）见：

- [docs/migration-todo-from-old.md](docs/migration-todo-from-old.md)

⚠️ 上面这份清单已全部标 ✅，但 2026-07-02 做了一次代码级复核审计，发现清单的✅**不代表字段/场景100%对齐老系统**，挖出一批此前未记录的真实缺口（邮件中心 MySQL 建表脚本缺失会静默失效、Yahoo Shop OAuth 回调路由缺失、财务导出 Yahoo购物模板字段错误等）。修复任务清单见：

- [docs/audit-2026-07-02-fix-tasks.md](docs/audit-2026-07-02-fix-tasks.md)

> 注：`migration-todo-from-old.md` 里"平台同步全缺""物流只有UI壳"等表述是 2026-06-20 的旧结论，已在 2026-07-02 审计中证实过时——各平台同步与 1688/日本物流均是真实 API 对接，不是空壳。当前真实缺口以 [docs/audit-2026-07-02-fix-tasks.md](docs/audit-2026-07-02-fix-tasks.md) 为准。新增/对照功能时请先查该清单，做完一项勾一项。

修改数据层时记住：`JsonStore` 与 `MysqlStore` 是同一接口的两套实现，**改一个通常要同步改另一个**，否则两种驱动行为会不一致。
