# 主库（master）迁移

本目录存放**主库** SQLx 迁移脚本，目标数据库为 MySQL。

迁移按文件名前缀顺序执行：

| 文件 | 表 | 作用 |
|------|----|------|
| `0001_create_platforms.sql` | `platforms` | 平台目录（code/name/sort_order/default_enabled，内置 6 平台） |
| `0002_create_tenants.sql` | `tenants` | 租户档案（company_name/subdomain/db_dsn_enc/plan/status/staff_count/created_at） |
| `0003_create_tenant_platform.sql` | `tenant_platform` | 租户↔平台授权（tenant_id/platform_code/enabled/locked） |
| `0004_create_admins.sql` | `admins` | 超级管理员 |
| `0005_create_sessions.sql` | `sessions` | 会话（principal_kind/principal_id/tenant_id/token/时间戳/审计） |
| `0006_create_announcements.sql` | `announcements` | 系统公告（title/kind/scope/content） |
| `0007_create_billing.sql` | `billing_settings` / `tenant_billing_accounts` / `tenant_billing_ledger` | 预付费计费设置、租户余额与充值/扣费流水 |
| `0008_extend_tenant_profile.sql` | `tenants` | 补全租户资料字段：公司简称、联系人、联系方式、地址、备注 |
| `0009_create_tenant_features.sql` | `tenant_features` | 超管按租户开关功能模块 |
| `0010_billing_points.sql` | `tenant_billing_accounts` / `tenant_billing_ledger` / `billing_charge_rules` | 将费用体系统一为 pt 积分，新增店铺扣 50pt，并记录操作人 |
| `0011_billing_store_subscriptions.sql` | `tenant_billing_subscriptions` / `billing_charge_rules` | 店铺月费订阅、每月 50pt 续费与 -300pt 欠费停用规则 |
| `0012_create_global_settings.sql` | `global_settings` | 超管全局设置：物流编号对照表、ShowAPI 配置、轮循代理 |

> 约定：主库迁移放在 `migrations/master/`，租户库迁移放在 `migrations/tenant/`，二者互不冲突。
> 主库迁移由迁移 runner（见 task 2.3 `src/db/migrate.rs`）在启动时套用到固定主库连接池。
