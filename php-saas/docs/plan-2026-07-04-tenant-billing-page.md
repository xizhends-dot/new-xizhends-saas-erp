# 方案：租户端「积分账单」页面（仅公司管理员可见）

> 日期：2026-07-04。状态：方案定稿，待 Codex 实施。
> 背景：租户按 pt 积分付费（开店 50pt、每店每月 50pt、欠至 -300pt 自动停用），
> 但租户目前只能在「店铺管理」页顶部看到余额数字，看不到流水明细与月费订阅，
> 且该入口有"店铺新增"权限门槛。需要一个独立的「积分账单」页，**仅公司管理员可见**。

## 目标

1. 新增租户端「积分账单」页 `/billing`，展示：当前余额、收费规则、每笔积分流水（充值/扣费明细）、
   店铺月费订阅列表（含下次扣费日）、接近停用线时的醒目警告。
2. 访问控制：**仅公司管理员**（`is_company_admin=1` 或 role=`公司管理员`）。
   即便普通员工被授予了某权限点也不能进——不能用现有权限点机制（公司管理员对所有权限点恒为 true，
   会误放行被授权的员工）。
3. 纯读页面，不引入新的数据层方法。

## 现状事实（已核实）

- 数据层已就绪，三个接口超管后台已在用：
  - `tenantBillingAccount($key)` → 余额、`store_add_fee`、`store_monthly_fee`、`debt_suspend_threshold`
  - `tenantBillingLedger($key, $limit)` → 流水（type/amount/balance_after/note/operator/created_at）
  - `tenantBillingSubscriptions($key)` → 店铺月费订阅（store_name/amount_points/next_charge_at/status）
- 余额目前仅 `app/Views/tenant/stores.php:16` 顶部提示栏显示，且入口在
  `layouts/tenant.php:73` —— 受 `management.stores` 功能开关 + `店铺新增` 权限点双重限制。
- 权限判定：`Permission::has()`（`app/Core/Permission.php:18`）中公司管理员/`is_company_admin`
  对任意权限点返回 true。`AuthService` 现有守卫：`requireTenant`、`requireTenantPermission`、
  `requireAnyTenantPermission`、`tenantCan`——**没有**"仅管理员"守卫。
- 侧栏导航 `layouts/tenant.php` 用 `$can()` / `$featureEnabled()` 控制每一项显隐；
  `$currentUser` 在布局里可用，含 `is_company_admin` / `role`。
- 常量在 `MysqlStore`/`JsonStore` 均为 `STORE_ADD_FEE=50`、`STORE_MONTHLY_FEE=50`、
  `DEBT_SUSPEND_THRESHOLD=-300`，已通过 `tenantBillingAccount` 返回，视图直接读。

## 设计

### A. 新增"仅公司管理员"守卫（AuthService）

不复用权限点。新增两个方法：

```php
public function isTenantCompanyAdmin(string $tenantKey): bool
{
    $user = $this->currentTenantUser($tenantKey);
    if ($user === null) {
        return false;
    }
    return ($user['is_company_admin'] ?? false)
        || \Xizhen\Core\Permission::normalizeRole($user['role'] ?? '') === '公司管理员';
}

public function requireTenantCompanyAdmin(string $tenantKey): void
{
    $this->requireTenant($tenantKey);              // 先确保已登录 + 租户 active
    if ($this->isTenantCompanyAdmin($tenantKey)) {
        return;
    }
    $this->deny('公司管理员');                       // 走现有 deny() 拒绝
}
```

（判定逻辑与 `Permission::has()` 里对公司管理员的识别口径一致，避免两处标准不一。）

### B. 路由 + 控制器

- `public/index.php` 注册 `GET /billing` → `[$tenant, 'billing']`。
  （注意：`/admin/billing` 是超管路由，不冲突；租户端用 `/billing`。）
- `TenantController::billing()`：
  ```php
  $tenantKey = current_tenant_key();
  $this->auth->requireTenantCompanyAdmin($tenantKey);   // 仅公司管理员
  $this->view->render('tenant/billing', [
      'title' => '积分账单',
      'active' => 'billing',
      'tenantKey' => $tenantKey,
      'tenant' => $this->store->tenant($tenantKey),
      'menu' => $this->service->platformMenu($tenantKey),
      'tenantFeatures' => $this->service->tenantFeatureMap($tenantKey),
      'account' => $this->store->tenantBillingAccount($tenantKey),
      'ledger' => $this->store->tenantBillingLedger($tenantKey, 100),
      'subscriptions' => $this->store->tenantBillingSubscriptions($tenantKey),
      'currentUser' => $this->auth->currentTenantUser($tenantKey),
  ]);
  ```
  渲染沿用租户默认布局（与 `stores()` 一致，不传第三参即用 `layouts/tenant`）。

### C. 视图 `app/Views/tenant/billing.php`

参考超管 `app/Views/admin/billing.php` 的展示结构，但**去掉充值/扣减表单**（租户只读）：

1. **余额卡片**：当前余额 `X pt`；余额 ≤ 停用线 / < 开店费时红色警告条
   （文案：「余额不足，新增店铺需 50pt，请联系 SaaS 超级管理员充值」/
   「已达 -300pt 停用线，请立即联系超管充值，否则租户将被停用」）。
2. **收费规则说明**：开店 `store_add_fee`pt、每月每店 `store_monthly_fee`pt、
   欠至 `debt_suspend_threshold`pt 自动停用。
3. **月费订阅表**：店铺名 / 月费 pt / 下次扣费日 / 状态。空态提示"暂无店铺订阅"。
4. **流水表**：时间 / 类型（充值·扣费·月费·调整，做中文标签映射）/ 变动 pt（正负着色）/
   变动后余额 / 备注 / 操作人。空态提示"暂无积分流水"。
- 所有输出走 `e()` 转义。类型标签映射用一个局部数组，未知类型回退原值。

### D. 导航入口（layouts/tenant.php「管理」分组内）

在「管理」分组加一项，**仅公司管理员可见**：

```php
<?php if (!empty($currentUser['is_company_admin']) || \Xizhen\Core\Permission::normalizeRole($currentUser['role'] ?? '') === '公司管理员'): ?>
    <a class="<?= ($active ?? '') === 'billing' ? 'active' : '' ?>" href="/billing?tenant=<?= e($tenantKey) ?>">积分账单</a>
<?php endif; ?>
```

（不加 `$featureEnabled` 门控——计费是平台级能力，不应被租户功能开关关掉。）

### 范围外（本次不做）

- 租户自助充值/支付（充值仍只能超管后台操作）。
- 全站顶栏的余额不足横幅（可作后续增强；本次只在 `/billing` 页内告警）。
- 导出流水。

## 双驱动一致性

只新增读页面，`tenantBillingAccount/Ledger/Subscriptions` 两驱动均已实现，**无需改数据层**。
需确认 JsonStore 三个方法返回结构与 MysqlStore 字段名一致（`type`/`amount`/`balance_after`/
`note`/`operator`/`created_at`；订阅 `store_name`/`amount_points`/`next_charge_at`/`status`）——
若有差异，在视图侧做兼容读取（`?? ` 回退），不改数据层。

## 测试要求

沿用 `php-saas/tests/*.php` 纯 PHP 断言风格，新增 `tenant_billing_access_test.php`：

- `AuthService::isTenantCompanyAdmin`：公司管理员（is_company_admin=1）→ true；
  role=公司管理员 → true；普通采购/客服 → false；**即使 permissions 里含各种权限点**的普通员工 → false。
- 用 JsonStore 造数据：公司管理员用户判定 true、普通员工判定 false。

（守卫走 redirect/deny 难在 CLI 断言，重点测 `isTenantCompanyAdmin` 纯判定；
`requireTenantCompanyAdmin` 逻辑简单，靠判定方法覆盖。）

## 验收清单

- [ ] 公司管理员登录后，侧栏「管理」分组出现「积分账单」，点进去能看到余额、流水、月费订阅。
- [ ] 普通员工（采购/客服，甚至被单独授予"店铺新增"等权限点者）侧栏**看不到**该入口，
      直接访问 `/billing` 被 `deny` 拒绝。
- [ ] 余额低于开店费 / 达到 -300pt 时页面显示对应红色警告。
- [ ] 流水与月费订阅数字与超管后台 `/admin/billing` 对同一租户展示一致。
- [ ] JSON 与 MySQL 两种驱动下页面都能正常渲染。
- [ ] 新增测试与既有 `tests/*.php` 全部通过。
