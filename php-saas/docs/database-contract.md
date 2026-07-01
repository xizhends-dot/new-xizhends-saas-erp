# 数据库契约与 Rust 版本复用说明

## 结论

可以继续沿用原 Rust 重构版的数据库设计。那些 SQL 迁移文件本质是 MySQL schema，不依赖 Rust 语言本身，PHP 新系统可以直接读取同一套表结构。

当前本机 PHP 只有 `PDO` 和 `mysqlnd`，没有 `pdo_mysql` / `mysqli`，所以开发版先使用 `storage/data/app.json`。代码已经加了 `DATA_DRIVER=json|mysql` 配置层；等服务器启用 `pdo_mysql` 后，页面层不需要重写，只切换数据驱动和 DSN。

## Schema 来源

主库迁移：

- `migrations/master/0001_create_platforms.sql`：平台目录。
- `migrations/master/0002_create_tenants.sql`：租户档案。
- `migrations/master/0003_create_tenant_platform.sql`：租户平台授权。
- `migrations/master/0006_create_announcements.sql`：系统公告。
- `migrations/master/0007_create_billing.sql`：预付费计费。
- `migrations/master/0008_extend_tenant_profile.sql`：租户联系人资料。

租户库迁移：

- `migrations/tenant/0001_init_tenant_schema.sql`：店铺、员工、订单、子商品、采购、日本仓、物流、审计日志。
- `migrations/tenant/0002_extend_stores_rakuten_rms.sql`：乐天 RMS 凭证和同步状态。
- `migrations/tenant/0003_extend_user_store_media.sql`：员工扩展、店铺分配、订单附件、图片落点。
- `migrations/tenant/0004_settings_import_export.sql`：租户设置保存、导入导出日志。

## PHP 数据层对应

- `StoreInterface`：页面与服务层依赖的统一数据契约。
- `JsonStore`：开发期 JSON 数据实现。
- `MysqlStore`：按 Rust MySQL schema 读取主库与租户库。
- `StoreFactory`：根据 `DATA_DRIVER` 自动选择实现。
- `Config`：读取环境变量并在缺少扩展时回退 JSON。

当前 PHP 数据层已覆盖：

- 租户侧权限拦截和员工店铺范围过滤。
- 公司资料、订单参数、利润参数、物流映射保存。
- CSV 导出和导入解析日志。
- 利润统计读取汇率、默认国际运费、平台扣点和店铺扣点。

## 推荐迁移顺序

1. 在服务器确认 PHP 开启 `pdo_mysql`。
2. 创建主库 `xizhends_master`，执行 `migrations/master/*.sql`。
3. 为每个租户创建独立库，例如 `xizhends_tenant_erp`，执行 `migrations/tenant/*.sql`。
4. 设置 `DATA_DRIVER=mysql`、`MYSQL_DSN`、`MYSQL_USER`、`MYSQL_PASSWORD`。
5. 为每个租户设置 `MYSQL_TENANT_DSN_{TENANT_KEY}`，例如 `MYSQL_TENANT_DSN_ERP`。
6. 访问 `/admin/system` 确认实际驱动为 `mysql`。

## 不建议直接复用旧系统宽表

`/old` 的 6 个平台表是镜像宽表：`ph_ordery`、`ph_orderr`、`ph_orderw`、`ph_orderm`、`ph_orderq`、`ph_orderyp`。新系统应把这些数据导入 Rust 设计好的规范化表：

- 订单级信息进入 `orders`。
- 子商品进入 `order_items`。
- 国内采购进入 `purchases`。
- 日本仓出库进入 `jp_shipments`。
- 日本国内物流进入 `domestic_shipments`。
- 国际物流进入 `intl_shipments`。
- 备注和操作记录进入 `order_logs`。

这样能继续支持旧系统的业务能力，同时避免 6 套平台目录重复开发。
