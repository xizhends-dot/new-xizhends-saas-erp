# 方案：架构重构阶段 2 —— 拆分数据层（MysqlStore → Db + 9 个领域 Repository）

> 日期：2026-07-04。状态：方案定稿，待 Codex 实施。
> 属于 [plan-2026-07-04-architecture-roadmap.md](plan-2026-07-04-architecture-roadmap.md) 的阶段 2。
> 前提：阶段 1 已验收提交，开工时 git status 干净。

## 目标

1. 连接管理从 MysqlStore 抽出为 `app/Core/Db.php`；
2. MysqlStore（4056 行）的全部 SQL 按领域搬入 `app/Repositories/` 下 9 个 Repository；
3. MysqlStore 保留为**薄门面**：继续实现 StoreInterface，每个方法一行委托给对应 Repository
   ——**本阶段不改任何控制器/服务的调用方式**；
4. JsonStore 冻结降级为"演示/测试替身"，CLAUDE.md 双驱动契约条款改写；
5. AppService 拆分**不在本阶段**（顺延为阶段 2b，避免单次任务过大）。

## 铁律（同阶段 1）

- SQL 与方法体**逐字搬移**，仅允许的机械替换：
  `$this->master()` → `$this->db->master()`；`$this->tenantPdo($k)` → `$this->db->tenantPdo($k)`；
  私有辅助改用基类/依赖注入的等价调用。禁止改 SQL、改逻辑、改返回结构。
- 每个 Repository <800 行；某域超标允许同域再拆（如 OrderImportRepository），仍纯搬移。
- 验收时用与阶段 1 相同的"方法体归一化比对"校验搬移完整性。

## 设计

### A. `app/Core/Db.php`（连接管理，从 MysqlStore 抽出）

- `master(): \PDO`——惰性建连（现 `MysqlStore::master()`）；
- `tenantPdo(string $tenantKey): ?\PDO`——含环境变量优先、主库 `db_dsn_enc` 回退、
  负缓存（现 `MysqlStore::tenantPdo()` 逐字搬移）；
- `connect(string $dsn): \PDO`（私有）；
- 构造依赖 Config。

### B. `app/Repositories/`（namespace `Xizhen\Repositories`）

共享基类 `BaseRepository`：构造注入 Db；`tableExists()`/`columnExists()` 等跨域私有辅助
从 MysqlStore 迁入（protected）。

| Repository | 承接 MysqlStore 方法域 | 库 |
|---|---|---|
| TenantRepository | tenants/tenant/tenantId/tenantPlatforms/tenantFeatures/togglePlatform/toggleTenantFeature/createTenant（委托 TenantProvisioningService） | 主库 |
| BillingRepository | tenantBillingAccount/Ledger/Subscriptions、adjust/chargeTenantPoints、processDueTenantBilling、ensureBillingAccount、createStoreBillingSubscription、月费相关 | 主库 |
| AdminRepository | adminByUsername/touchAdminLogin/announcements/全局设置 | 主库 |
| OrderRepository | orders/ordersByYear/ordersForStores/order/mapOrder/batchUpdateItems/子项保存/归档/purchaseStatusEvents | 租户库 |
| StoreRepository | stores/store/addStore/updateStore/mergeStoreApiConfig | 租户库 |
| UserRepository | users/user/tenantUserByUsername/addUser/updateUser/密码/权限覆盖/assignments | 租户库 |
| MailRepository | 全部 mail* 方法（含 mailPdo 建表检查） | 租户库 |
| MediaRepository | 附件/图片相关 | 租户库 |
| SettingsRepository | tenantSettings/saveTenantSettings/import_export_logs/notices/客服扣点等设置类 | 租户库 |

依赖规则：Repository 可以构造注入 Db + 少量兄弟 Repository（如 StoreRepository 需要
BillingRepository.createStoreBillingSubscription、多数租户库仓库需要 TenantRepository.tenantId）。
禁止环形依赖；如出现，把共用小方法（如 tenantId）放 BaseRepository 或 Db。

### C. MysqlStore 薄门面

- 构造函数建 Db + 9 个 Repository；
- StoreInterface 的每个方法变为一行委托；
- 拆完后 MysqlStore 预期 <900 行（门面允许略超 800 的通用上限，接口就有 ~100 个方法）；
- **StoreInterface 与所有调用方（控制器/服务）本阶段零改动**——门面保证兼容。

### D. JsonStore 冻结（只改文档与约定，不动代码）

- CLAUDE.md"双存储驱动"条款改写为：
  - MySQL 是唯一生产驱动；JsonStore 仅作本地演示与测试替身；
  - 新功能**不再要求** JsonStore 对齐实现，允许其方法退化（返回空/抛异常），
    但被现有 tests/*.php 使用的方法必须保持可用；
- JsonStore 代码本阶段不动（4202 行留在原地，后续按需瘦身）。

## 执行顺序建议

1. 抽 Db.php → MysqlStore 内部改用 Db（其余不动）→ 测试+冒烟；
2. 逐域搬移：主库三仓（Tenant/Billing/Admin）→ 租户库六仓，每搬一域跑 php -l + 相关冒烟；
3. 全部委托完成 → 方法体完整性校验 → CLAUDE.md 更新；
4. composer dump-autoload、全量测试、完整冒烟。

## 测试与验收

- 既有 tests/*.php 全部通过（多数走 JsonStore，天然不受影响；csrf/导出等照常）。
- php -l 全部新文件。
- 冒烟清单同阶段 1（重点：登录、订单三视图、子项保存、批量、店铺新增扣费、
  积分账单、设置保存、采购状态保存、邮件页、导入导出页、超管各页 + 新增租户表单校验路径）。
- 方法体归一化比对：MysqlStore(HEAD) 的全部方法 vs（新 Repository ∪ Db ∪ 门面），
  除允许的机械替换外零差异——Codex 自行校验并在小结报告，验收时我方复验。
- 验收清单：
  - [ ] Db.php 承载全部连接逻辑，MysqlStore 内不再出现 new \PDO；
  - [ ] 9 个 Repository 各 <800 行；MysqlStore 门面 <900 行且只含委托；
  - [ ] StoreInterface 及全部调用方零改动（git diff 确认）；
  - [ ] CLAUDE.md 契约已改写；
  - [ ] 测试全绿 + 冒烟全过 + 搬移完整性校验通过。
