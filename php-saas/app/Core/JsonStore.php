<?php

declare(strict_types=1);

namespace Xizhen\Core;

use Xizhen\Services\AuthService;
use Xizhen\Services\TenantProvisioningService;

final class JsonStore implements StoreInterface
{
    private const STORE_ADD_FEE = 50;
    private const STORE_MONTHLY_FEE = 50;
    private const DEBT_SUSPEND_THRESHOLD = -300;
    private const PURCHASE_EVENT_STATUSES = [
        '国内采购-准备' => 'enter_prepare',
        '国内采购-已采购' => 'complete_purchase',
        '国内采购-TB/PDD已采购' => 'complete_purchase',
    ];

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    public function __construct(private readonly string $file)
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }

        if (!is_file($this->file)) {
            $this->data = $this->seed();
            $this->hydrateMissingTenantData();
            $this->save();
            return $this->data;
        }

        $raw = file_get_contents($this->file);
        $decoded = json_decode($raw === false ? '' : $raw, true);
        $this->data = is_array($decoded) ? $decoded : $this->seed();
        $this->hydrateMissingTenantData();
        return $this->data;
    }

    /** @return array<int, array<string, mixed>> */
    public function tenants(): array
    {
        return $this->all()['tenants'];
    }

    /** @param array<string, mixed> $data @return array{ok: bool, message: string} */
    public function createTenant(array $data): array
    {
        $data = TenantProvisioningService::normalizeInput($data);
        $errors = TenantProvisioningService::validateInput($data);
        if ($errors) {
            return ['ok' => false, 'message' => implode('；', $errors)];
        }

        foreach ($this->tenants() as $tenant) {
            if (($tenant['key'] ?? '') === $data['subdomain'] || ($tenant['subdomain'] ?? '') === $data['subdomain']) {
                return ['ok' => false, 'message' => '子域名已存在，请更换后重试。'];
            }
        }

        $all = $this->all();
        $this->ensureBillingStructure($all);
        $subdomain = (string) $data['subdomain'];
        $platforms = array_map(static fn (array $platform): array => [
            'code' => (string) ($platform['code'] ?? ''),
            'enabled' => (bool) ($platform['default_enabled'] ?? true),
            'locked' => false,
        ], array_values(array_filter(
            (array) ($all['platforms'] ?? []),
            static fn (array $platform): bool => (bool) ($platform['default_enabled'] ?? 1)
        )));

        $all['tenants'][] = [
            'id' => $this->nextId(is_array($all['tenants'] ?? null) ? $all['tenants'] : []),
            'key' => $subdomain,
            'company_name' => (string) $data['company_name'],
            'short_name' => (string) $data['company_short_name'],
            'subdomain' => $subdomain,
            'db_name' => (string) $data['db_name'],
            'plan' => (string) $data['plan'],
            'status' => 'active',
            'staff_count' => 0,
            'balance' => 0,
            'billing_updated_at' => '',
            'contact' => (string) $data['contact_name'],
            'phone' => (string) $data['contact_phone'],
            'contact_email' => (string) $data['contact_email'],
            'contact_wechat' => (string) $data['contact_wechat'],
            'address' => (string) $data['address'],
            'remark' => (string) $data['remark'],
            'platforms' => $platforms,
            'features' => TenantFeature::defaultRows(),
        ];
        $all['stores'][$subdomain] = [];
        $all['orders'][$subdomain] = [];
        $all['users'][$subdomain] = [[
            'id' => 1,
            'name' => (string) (($data['contact_name'] ?? '') ?: '公司管理员'),
            'username' => (string) $data['admin_username'],
            'role' => '公司管理员',
            'password_hash' => AuthService::makePasswordHash((string) $data['admin_password']),
            'legacy_password' => '',
            'password_reset' => '',
            'password_reset_at' => date('Y-m-d H:i:s'),
            'last_login_at' => '',
            'preference_module' => 'dashboard',
            'api_1688_config' => '',
            'is_company_admin' => true,
            'permissions' => $this->permissionsForRole('公司管理员'),
            'permission_overrides' => ['allow' => [], 'deny' => []],
            'stores' => ['全部店铺'],
            'status' => 'active',
            'created_at' => date('Y-m-d H:i'),
        ]];
        $all['assignments'][$subdomain] = [];
        $all['attachments'][$subdomain] = [];
        $all['settings']['tenant'][$subdomain] = $this->defaultTenantSettings(end($all['tenants']) ?: []);
        $all['import_export_logs'][$subdomain] = [];
        $all['purchase_status_events'][$subdomain] = [];
        foreach (['accounts', 'folders', 'messages', 'replies', 'rules'] as $bucket) {
            $all['mail'][$bucket][$subdomain] = [];
        }
        $all['mail']['settings'][$subdomain] = [
            'autosync_enabled' => 1,
            'autosync_interval_sec' => 180,
        ];
        $all['billing']['ledger'][$subdomain] = [];
        $all['billing']['subscriptions'][$subdomain] = [];

        $this->data = $all;
        $initialPoints = max(0, (int) $data['initial_points']);
        if ($initialPoints > 0) {
            $this->writeTenantPointEntry($subdomain, $initialPoints, 'recharge', '开通初始积分', (string) $data['operator']);
        } else {
            $this->save();
        }

        return ['ok' => true, 'message' => '租户已开通：' . $subdomain];
    }

    /** @return array<string, mixed>|null */
    public function adminByUsername(string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        foreach (($this->all()['admins'] ?? []) as $admin) {
            if (is_array($admin) && hash_equals((string) ($admin['username'] ?? ''), $username)) {
                return $admin;
            }
        }

        return null;
    }

    public function touchAdminLogin(int $adminId): void
    {
        if ($adminId <= 0) {
            return;
        }

        $all = $this->all();
        foreach (($all['admins'] ?? []) as &$admin) {
            if ((int) ($admin['id'] ?? 0) === $adminId) {
                $admin['last_login_at'] = date('Y-m-d H:i:s');
                break;
            }
        }
        unset($admin);

        $this->data = $all;
        $this->save();
    }

    /** @return array<string, mixed> */
    public function tenant(string $key): array
    {
        foreach ($this->tenants() as $tenant) {
            if (($tenant['key'] ?? '') === $key) {
                return $tenant;
            }
        }

        return $this->tenants()[0];
    }

    /** @return array<int, array<string, mixed>> */
    public function platforms(): array
    {
        return $this->all()['platforms'];
    }

    /** @return array<int, array<string, mixed>> */
    public function orders(string $tenantKey): array
    {
        return $this->all()['orders'][$tenantKey] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function ordersByYear(string $tenantKey, int $year): array
    {
        return array_values(array_filter(
            $this->orders($tenantKey),
            static function (array $order) use ($year): bool {
                $date = trim((string) ($order['imported_at'] ?? ''));
                if ($date === '') {
                    $date = trim((string) ($order['order_date'] ?? ''));
                }

                return substr($date, 0, 4) === (string) $year;
            }
        ));
    }

    /**
     * @param array<int, string> $stores
     * @return array<int, array<string, mixed>>
     */
    public function ordersForStores(string $tenantKey, array $stores): array
    {
        $stores = array_values(array_filter(array_map('trim', $stores)));
        if (!$stores || in_array('全部店铺', $stores, true)) {
            return $this->orders($tenantKey);
        }

        return array_values(array_filter(
            $this->orders($tenantKey),
            fn (array $order): bool => in_array((string) ($order['store'] ?? ''), $stores, true)
        ));
    }

    /** @return array<string, mixed>|null */
    public function order(string $tenantKey, int $orderId): ?array
    {
        foreach ($this->orders($tenantKey) as $order) {
            if ((int) ($order['id'] ?? 0) === $orderId) {
                return $order;
            }
        }

        return null;
    }

    /** @return array<int, array<string, mixed>> */
    public function announcements(): array
    {
        return $this->all()['announcements'];
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantPlatforms(string $tenantKey): array
    {
        $tenant = $this->tenant($tenantKey);
        return $tenant['platforms'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantFeatures(string $tenantKey): array
    {
        $tenant = $this->tenant($tenantKey);
        return TenantFeature::normalizeRows(is_array($tenant['features'] ?? null) ? $tenant['features'] : []);
    }

    /** @return array<string, mixed> */
    public function tenantBillingAccount(string $tenantKey): array
    {
        $tenant = $this->tenant($tenantKey);
        $image = trim((string) ($item['image'] ?? $item['main_image'] ?? ''));

        return [
            'tenant_key' => (string) ($tenant['key'] ?? $tenantKey),
            'balance' => (int) ($tenant['balance'] ?? 0),
            'unit' => 'pt',
            'store_add_fee' => self::STORE_ADD_FEE,
            'store_monthly_fee' => self::STORE_MONTHLY_FEE,
            'debt_suspend_threshold' => self::DEBT_SUSPEND_THRESHOLD,
            'updated_at' => (string) ($tenant['billing_updated_at'] ?? ''),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantBillingLedger(string $tenantKey, int $limit = 50): array
    {
        $rows = $this->all()['billing']['ledger'][$tenantKey] ?? [];
        $rows = is_array($rows) ? $rows : [];
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
        $tenant = $this->tenant($tenantKey);
        $tenantName = (string) (($tenant['company_name'] ?? '') ?: $tenantKey);
        foreach ($rows as &$row) {
            $row['tenant_key'] = (string) ($row['tenant_key'] ?? $tenantKey);
            $row['tenant_name'] = (string) (($row['tenant_name'] ?? '') ?: $tenantName);
        }
        unset($row);

        return array_slice($rows, 0, max(1, $limit));
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantBillingSubscriptions(string $tenantKey): array
    {
        $rows = $this->all()['billing']['subscriptions'][$tenantKey] ?? [];
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        usort($rows, static fn (array $a, array $b): int => strcmp((string) ($a['next_charge_at'] ?? ''), (string) ($b['next_charge_at'] ?? '')));

        return $rows;
    }

    /** @return array<string, mixed> */
    public function processDueTenantBilling(string $tenantKey, string $operator = 'system'): array
    {
        $result = [
            'processed' => 0,
            'charged' => 0,
            'amount' => 0,
            'balance_after' => (int) ($this->tenantBillingAccount($tenantKey)['balance'] ?? 0),
            'needs_recharge' => false,
            'suspended' => false,
            'message' => '暂无到期店铺月费。',
        ];

        $today = new \DateTimeImmutable('today');
        foreach ($this->tenantBillingSubscriptions($tenantKey) as $subscription) {
            if (($subscription['status'] ?? 'active') !== 'active') {
                continue;
            }

            $nextChargeAt = trim((string) ($subscription['next_charge_at'] ?? ''));
            if ($nextChargeAt === '' || strtotime($nextChargeAt) === false) {
                continue;
            }

            $due = new \DateTimeImmutable($nextChargeAt);
            $cycles = 0;
            while ($due <= $today && $cycles < 24) {
                $amount = max(0, (int) ($subscription['amount'] ?? self::STORE_MONTHLY_FEE));
                if ($amount <= 0) {
                    break;
                }

                $balanceAfter = null;
                $storeName = (string) (($subscription['store_name'] ?? '') ?: ('店铺 #' . (string) ($subscription['store_id'] ?? '')));
                $ok = $this->writeTenantPointEntry(
                    $tenantKey,
                    -$amount,
                    'charge',
                    "店铺月费：{$storeName}（{$due->format('Y-m-d')}）",
                    $operator,
                    false,
                    true,
                    true,
                    $balanceAfter
                );
                if (!$ok) {
                    break;
                }

                $nextDue = $this->nextMonthlyDate($due, (int) ($subscription['billing_day'] ?? 0) ?: null);
                $this->markSubscriptionCharged($tenantKey, (int) ($subscription['id'] ?? 0), $due->format('Y-m-d'), $nextDue->format('Y-m-d'));

                $result['processed']++;
                $result['charged']++;
                $result['amount'] += $amount;
                $result['balance_after'] = $balanceAfter ?? $result['balance_after'];

                if (($balanceAfter ?? 0) <= self::DEBT_SUSPEND_THRESHOLD) {
                    $result['suspended'] = true;
                    break 2;
                }

                $due = $nextDue;
                $cycles++;
            }
        }

        $result['needs_recharge'] = (int) $result['balance_after'] < self::STORE_MONTHLY_FEE;
        if ((int) $result['charged'] > 0) {
            $message = '已处理 ' . (int) $result['charged'] . ' 笔店铺月费，共扣除 ' . (int) $result['amount'] . 'pt。';
            if ($result['suspended']) {
                $message .= ' 余额已达到 ' . self::DEBT_SUSPEND_THRESHOLD . 'pt 停用线，租户已自动停用。';
            } elseif ($result['needs_recharge']) {
                $message .= ' 当前余额不足一次月费，请提醒租户充值。';
            }
            $result['message'] = $message;
        }

        return $result;
    }

    public function adjustTenantPoints(string $tenantKey, int $amount, string $type, string $note, string $operator): void
    {
        if ($amount === 0) {
            return;
        }

        $this->writeTenantPointEntry($tenantKey, $amount, $type, $note, $operator);
    }

    public function chargeTenantPoints(string $tenantKey, int $amount, string $note, string $operator): bool
    {
        $amount = abs($amount);
        if ($amount <= 0) {
            return true;
        }

        return $this->writeTenantPointEntry($tenantKey, -$amount, 'charge', $note, $operator, true, false);
    }

    /** @return array<int, array<string, mixed>> */
    public function stores(string $tenantKey): array
    {
        $data = $this->all();
        return $data['stores'][$tenantKey] ?? [];
    }

    /** @return array<string, mixed>|null */
    public function store(string $tenantKey, int $storeId): ?array
    {
        foreach ($this->stores($tenantKey) as $store) {
            if ((int) ($store['id'] ?? 0) === $storeId) {
                return $store;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public function addStore(string $tenantKey, array $data): bool
    {
        $store = [
            'id' => $this->nextId($this->stores($tenantKey)),
            'legacy_dpid' => trim((string) ($data['legacy_dpid'] ?? '')),
            'platform' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? '')) ?: 'y',
            'short' => trim((string) ($data['short'] ?? '')),
            'name' => trim((string) ($data['name'] ?? '')),
            'status' => in_array(($data['status'] ?? 'visible'), ['visible', 'hidden'], true) ? $data['status'] : 'visible',
            'api_status' => trim((string) ($data['api_status'] ?? '未配置')),
            'api_config' => trim((string) ($data['api_config'] ?? '')),
            'profit_deduction' => $this->normalizePercent($data['profit_deduction'] ?? 70),
            'hidden_reason' => trim((string) ($data['hidden_reason'] ?? '')),
            'created_by' => '租户管理员',
            'created_at' => date('Y-m-d H:i'),
        ];

        if ($store['short'] === '' || $store['name'] === '') {
            return false;
        }

        $all = $this->all();
        $all['stores'][$tenantKey][] = $store;
        $this->ensureBillingStructure($all);
        $this->upsertStoreBillingSubscription($all, $tenantKey, $store, false);
        $this->data = $all;
        $this->save();
        return true;
    }

    /** @param array<string, mixed> $all */
    private function ensureBillingStructure(array &$all): void
    {
        if (!isset($all['billing']) || !is_array($all['billing'])) {
            $all['billing'] = [];
        }
        if (!isset($all['billing']['ledger']) || !is_array($all['billing']['ledger'])) {
            $all['billing']['ledger'] = [];
        }
        if (!isset($all['billing']['subscriptions']) || !is_array($all['billing']['subscriptions'])) {
            $all['billing']['subscriptions'] = [];
        }
    }

    /**
     * @param array<string, mixed> $all
     * @param array<string, mixed> $store
     */
    private function upsertStoreBillingSubscription(array &$all, string $tenantKey, array $store, bool $legacySeed): void
    {
        $this->ensureBillingStructure($all);
        $storeId = (int) ($store['id'] ?? 0);
        if ($storeId <= 0) {
            return;
        }

        $rows = $all['billing']['subscriptions'][$tenantKey] ?? [];
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        foreach ($rows as $row) {
            if ((int) ($row['store_id'] ?? 0) === $storeId && ($row['status'] ?? 'active') === 'active') {
                return;
            }
        }

        $createdAt = trim((string) ($store['created_at'] ?? ''));
        $startDate = $this->parseStoreDate($createdAt) ?? new \DateTimeImmutable('today');
        $nextCharge = $legacySeed ? $this->nextMonthlyDate(new \DateTimeImmutable('today')) : $this->nextMonthlyDate($startDate);
        $rows[] = [
            'id' => $this->nextId($rows),
            'tenant_key' => $tenantKey,
            'store_id' => $storeId,
            'store_name' => (string) (($store['name'] ?? '') ?: ($store['short'] ?? ('店铺 #' . $storeId))),
            'amount' => self::STORE_MONTHLY_FEE,
            'cycle' => 'monthly',
            'billing_day' => (int) $startDate->format('j'),
            'next_charge_at' => $nextCharge->format('Y-m-d'),
            'last_charge_at' => '',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'note' => $legacySeed ? '历史店铺自动补齐月费订阅' : '新增店铺自动创建月费订阅',
        ];
        $all['billing']['subscriptions'][$tenantKey] = $rows;
    }

    private function markSubscriptionCharged(string $tenantKey, int $subscriptionId, string $lastChargeAt, string $nextChargeAt): void
    {
        if ($subscriptionId <= 0) {
            return;
        }

        $all = $this->all();
        $this->ensureBillingStructure($all);
        if (!isset($all['billing']['subscriptions'][$tenantKey]) || !is_array($all['billing']['subscriptions'][$tenantKey])) {
            return;
        }

        foreach ($all['billing']['subscriptions'][$tenantKey] as &$subscription) {
            if ((int) ($subscription['id'] ?? 0) !== $subscriptionId) {
                continue;
            }
            $subscription['last_charge_at'] = $lastChargeAt;
            $subscription['next_charge_at'] = $nextChargeAt;
            $subscription['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
        unset($subscription);

        $this->data = $all;
        $this->save();
    }

    private function parseStoreDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '' || strtotime($value) === false) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    private function nextMonthlyDate(\DateTimeImmutable $from, ?int $billingDay = null): \DateTimeImmutable
    {
        $billingDay ??= (int) $from->format('j');
        $base = $from->modify('first day of next month');
        $lastDay = (int) $base->format('t');
        $day = min(max(1, $billingDay), $lastDay);

        return $base->setDate((int) $base->format('Y'), (int) $base->format('m'), $day);
    }

    /** @param array<string, mixed> $data */
    public function updateStore(string $tenantKey, int $storeId, array $data): void
    {
        if ($storeId <= 0) {
            return;
        }

        $short = trim((string) ($data['short'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($short === '' || $name === '') {
            return;
        }

        $all = $this->all();
        if (!isset($all['stores'][$tenantKey]) || !is_array($all['stores'][$tenantKey])) {
            return;
        }

        foreach ($all['stores'][$tenantKey] as &$store) {
            if ((int) ($store['id'] ?? 0) !== $storeId) {
                continue;
            }

            $store['platform'] = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? $store['platform'] ?? '')) ?: 'y';
            $store['legacy_dpid'] = trim((string) ($data['legacy_dpid'] ?? $store['legacy_dpid'] ?? ''));
            $store['short'] = $short;
            $store['name'] = $name;
            $store['status'] = in_array(($data['status'] ?? 'visible'), ['visible', 'hidden'], true) ? $data['status'] : 'visible';
            $store['api_status'] = trim((string) ($data['api_status'] ?? $store['api_status'] ?? '未配置')) ?: '未配置';
            $store['api_config'] = trim((string) ($data['api_config'] ?? $store['api_config'] ?? ''));
            $store['profit_deduction'] = $this->normalizePercent($data['profit_deduction'] ?? $store['profit_deduction'] ?? 70);
            $store['hidden_reason'] = trim((string) ($data['hidden_reason'] ?? $store['hidden_reason'] ?? ''));
            $store['updated_by'] = '租户管理员';
            $store['updated_at'] = date('Y-m-d H:i');
            break;
        }
        unset($store);

        $this->data = $all;
        $this->save();
    }

    /** @param array<string, mixed> $patch */
    public function mergeStoreApiConfig(string $tenantKey, int $storeId, array $patch, string $apiStatus = '已配置'): void
    {
        if ($storeId <= 0 || !$patch) {
            return;
        }

        $all = $this->all();
        if (!isset($all['stores'][$tenantKey]) || !is_array($all['stores'][$tenantKey])) {
            return;
        }

        foreach ($all['stores'][$tenantKey] as &$store) {
            if ((int) ($store['id'] ?? 0) !== $storeId) {
                continue;
            }

            $config = $this->storeApiConfig((string) ($store['api_config'] ?? ''));
            foreach ($patch as $key => $value) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                $config[$key] = is_scalar($value) ? (string) $value : $value;
            }
            $store['api_config'] = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $store['api_status'] = $apiStatus !== '' ? $apiStatus : ($store['api_status'] ?? '已配置');
            $store['updated_by'] = 'Yahoo OAuth';
            $store['updated_at'] = date('Y-m-d H:i');
            break;
        }
        unset($store);

        $this->data = $all;
        $this->save();
    }

    /** @return array<int, array<string, mixed>> */
    public function users(string $tenantKey): array
    {
        $data = $this->all();
        $users = is_array($data['users'][$tenantKey] ?? null) ? $data['users'][$tenantKey] : [];
        foreach ($users as &$user) {
            if (!is_array($user)) {
                continue;
            }
            $user['role'] = !empty($user['is_company_admin'])
                ? '公司管理员'
                : Permission::normalizeRole($user['role'] ?? '客服');
        }
        unset($user);

        return array_values(array_filter($users, 'is_array'));
    }

    /** @return array<string, mixed>|null */
    public function user(string $tenantKey, int $userId): ?array
    {
        foreach ($this->users($tenantKey) as $user) {
            if ((int) ($user['id'] ?? 0) === $userId) {
                return $user;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function tenantUserByUsername(string $tenantKey, string $username): ?array
    {
        $username = trim($username);
        if ($username === '') {
            return null;
        }

        foreach ($this->users($tenantKey) as $user) {
            if (hash_equals((string) ($user['username'] ?? ''), $username)) {
                return $user;
            }
        }

        return null;
    }

    public function updateTenantUserPassword(string $tenantKey, int $userId, string $passwordHash): void
    {
        if ($userId <= 0 || $passwordHash === '') {
            return;
        }

        $all = $this->all();
        if (!isset($all['users'][$tenantKey]) || !is_array($all['users'][$tenantKey])) {
            return;
        }

        foreach ($all['users'][$tenantKey] as &$user) {
            if ((int) ($user['id'] ?? 0) !== $userId) {
                continue;
            }

            $user['password_hash'] = $passwordHash;
            $user['legacy_password'] = '';
            $user['password_reset'] = '';
            $user['password_reset_at'] = date('Y-m-d H:i:s');
            break;
        }
        unset($user);

        $this->data = $all;
        $this->save();
    }

    public function touchTenantUserLogin(string $tenantKey, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $all = $this->all();
        if (!isset($all['users'][$tenantKey]) || !is_array($all['users'][$tenantKey])) {
            return;
        }

        foreach ($all['users'][$tenantKey] as &$user) {
            if ((int) ($user['id'] ?? 0) === $userId) {
                $user['last_login_at'] = date('Y-m-d H:i:s');
                break;
            }
        }
        unset($user);

        $this->data = $all;
        $this->save();
    }

    /** @param array<string, mixed> $data */
    public function addUser(string $tenantKey, array $data): void
    {
        $role = Permission::normalizeRole($data['role'] ?? '客服');

        $user = [
            'id' => $this->nextId($this->users($tenantKey)),
            'name' => trim((string) ($data['name'] ?? '')),
            'username' => trim((string) ($data['username'] ?? '')),
            'role' => $role,
            'password_hash' => $this->hashPassword((string) (($data['password_reset'] ?? '') ?: $this->defaultTenantPassword())),
            'legacy_password' => '',
            'password_reset' => '',
            'password_reset_at' => trim((string) ($data['password_reset'] ?? '')) !== '' ? date('Y-m-d H:i:s') : '',
            'last_login_at' => '',
            'preference_module' => trim((string) ($data['preference_module'] ?? '')),
            'api_1688_config' => array_key_exists('api_1688_config', $data) ? trim((string) $data['api_1688_config']) : '',
            'is_company_admin' => $role === '公司管理员',
            'permissions' => $this->permissionsForRole($role, $data['permissions'] ?? []),
            'permission_overrides' => ['allow' => [], 'deny' => []],
            'stores' => array_values(array_filter(array_map('trim', (array) ($data['stores'] ?? [])))),
            'status' => in_array(($data['status'] ?? 'active'), ['active', 'disabled'], true) ? $data['status'] : 'active',
            'created_at' => date('Y-m-d H:i'),
        ];

        if ($user['name'] === '' || $user['username'] === '') {
            return;
        }

        $all = $this->all();
        $all['users'][$tenantKey][] = $user;
        $this->data = $all;
        $this->save();
    }

    /** @param array<string, mixed> $data */
    public function updateUser(string $tenantKey, int $userId, array $data): void
    {
        if ($userId <= 0) {
            return;
        }

        $name = trim((string) ($data['name'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        if ($name === '' || $username === '') {
            return;
        }

        $role = Permission::normalizeRole($data['role'] ?? '客服');

        $all = $this->all();
        if (!isset($all['users'][$tenantKey]) || !is_array($all['users'][$tenantKey])) {
            return;
        }

        foreach ($all['users'][$tenantKey] as &$user) {
            if ((int) ($user['id'] ?? 0) !== $userId) {
                continue;
            }

            $user['name'] = $name;
            $user['username'] = $username;
            $user['role'] = $role;
            if (trim((string) ($data['password_reset'] ?? '')) !== '') {
                $user['password_hash'] = $this->hashPassword(trim((string) $data['password_reset']));
                $user['legacy_password'] = '';
                $user['password_reset'] = '';
                $user['password_reset_at'] = date('Y-m-d H:i:s');
            }
            $user['preference_module'] = trim((string) ($data['preference_module'] ?? $user['preference_module'] ?? ''));
            if (array_key_exists('api_1688_config', $data)) {
                $user['api_1688_config'] = trim((string) $data['api_1688_config']);
            }
            $user['is_company_admin'] = $role === '公司管理员';
            $user['permissions'] = $this->permissionsForRole($role, $data['permissions'] ?? []);
            $user['stores'] = array_values(array_filter(array_map('trim', (array) ($data['stores'] ?? []))));
            $user['status'] = in_array(($data['status'] ?? 'active'), ['active', 'disabled'], true) ? $data['status'] : 'active';
            $user['updated_at'] = date('Y-m-d H:i');
            break;
        }
        unset($user);

        $this->data = $all;
        $this->save();
    }

    /** @param array{allow?: array<int, string>, deny?: array<int, string>} $overrides */
    public function updateUserPermissionOverrides(string $tenantKey, int $userId, array $overrides, string $operator): void
    {
        if ($userId <= 0) {
            return;
        }

        $normalized = $this->normalizePermissionOverrides($overrides);
        $all = $this->all();
        if (!isset($all['users'][$tenantKey]) || !is_array($all['users'][$tenantKey])) {
            return;
        }

        foreach ($all['users'][$tenantKey] as &$user) {
            if ((int) ($user['id'] ?? 0) !== $userId) {
                continue;
            }

            $role = (string) ($user['role'] ?? '客服');
            $flat = array_values(array_unique(array_merge(
                Permission::roleDefaults()[Permission::normalizeRole($role)] ?? Permission::roleDefaults()['客服'],
                $normalized['allow']
            )));
            $user['permission_overrides'] = $normalized;
            $user['permissions'] = array_values(array_diff($flat, $normalized['deny']));
            $user['updated_by'] = $operator;
            $user['updated_at'] = date('Y-m-d H:i');
            break;
        }
        unset($user);

        $this->data = $all;
        $this->save();
    }

    /** @return array<int, array<string, mixed>> */
    public function assignments(string $tenantKey): array
    {
        $data = $this->all();
        return $data['assignments'][$tenantKey] ?? [];
    }

    /** @param array<int, int> $supportUserIds */
    public function saveAssignmentByBuyer(string $tenantKey, int $buyerUserId, array $supportUserIds): void
    {
        if ($buyerUserId <= 0) {
            return;
        }

        $all = $this->all();
        $rows = array_values(array_filter(
            $all['assignments'][$tenantKey] ?? [],
            fn (array $row): bool => (int) ($row['buyer_user_id'] ?? 0) !== $buyerUserId
        ));

        foreach (array_values(array_unique(array_map('intval', $supportUserIds))) as $supportUserId) {
            if ($supportUserId <= 0) {
                continue;
            }
            $rows[] = $this->assignmentRow($tenantKey, $buyerUserId, $supportUserId);
        }

        $all['assignments'][$tenantKey] = $rows;
        $this->data = $all;
        $this->save();
    }

    /** @param array<int, int> $buyerUserIds */
    public function saveAssignmentBySupport(string $tenantKey, int $supportUserId, array $buyerUserIds): void
    {
        if ($supportUserId <= 0) {
            return;
        }

        $all = $this->all();
        $rows = array_values(array_filter(
            $all['assignments'][$tenantKey] ?? [],
            fn (array $row): bool => (int) ($row['support_user_id'] ?? 0) !== $supportUserId
        ));

        foreach (array_values(array_unique(array_map('intval', $buyerUserIds))) as $buyerUserId) {
            if ($buyerUserId <= 0) {
                continue;
            }
            $rows[] = $this->assignmentRow($tenantKey, $buyerUserId, $supportUserId);
        }

        $all['assignments'][$tenantKey] = $rows;
        $this->data = $all;
        $this->save();
    }

    public function togglePlatform(string $tenantKey, string $platformCode, string $field): void
    {
        $data = $this->all();
        foreach ($data['tenants'] as &$tenant) {
            if (($tenant['key'] ?? '') !== $tenantKey) {
                continue;
            }

            foreach ($tenant['platforms'] as &$platform) {
                if (($platform['code'] ?? '') === $platformCode && in_array($field, ['enabled', 'locked'], true)) {
                    $platform[$field] = !($platform[$field] ?? false);
                }
            }
            unset($platform);
        }
        unset($tenant);

        $this->data = $data;
        $this->save();
    }

    public function toggleTenantFeature(string $tenantKey, string $featureKey): void
    {
        if (!TenantFeature::isKnown($featureKey)) {
            return;
        }

        $data = $this->all();
        foreach ($data['tenants'] as &$tenant) {
            if (($tenant['key'] ?? '') !== $tenantKey) {
                continue;
            }

            $features = TenantFeature::mapFromRows(is_array($tenant['features'] ?? null) ? $tenant['features'] : []);
            $features[$featureKey] = !($features[$featureKey] ?? false);
            $tenant['features'] = array_map(
                static fn (string $key, bool $enabled): array => ['key' => $key, 'enabled' => $enabled],
                array_keys($features),
                array_values($features)
            );
            break;
        }
        unset($tenant);

        $this->data = $data;
        $this->save();
    }

    public function changeItemSource(string $tenantKey, int $itemId, string $source): void
    {
        if (!in_array($source, ['cn_purchase', 'jp_stock', 'pending'], true)) {
            return;
        }

        $data = $this->all();
        if (!isset($data['orders'][$tenantKey]) || !is_array($data['orders'][$tenantKey])) {
            return;
        }

        foreach ($data['orders'][$tenantKey] as &$order) {
            foreach ($order['items'] as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId) {
                    continue;
                }

                $old = $item['source_type'] ?? 'pending';
                if ($old === $source) {
                    continue;
                }

                $oldStatus = (string) ($item['purchase_status'] ?? '');
                $status = match ($source) {
                    'cn_purchase' => '国内采购-准备',
                    'jp_stock' => '日本库存订单',
                    default => '待处理',
                };
                $item['source_type'] = $source;
                $item['logs'][] = [
                    'time' => date('m-d H:i'),
                    'user' => '系统管理员',
                    'action' => '货源改判',
                    'field' => 'source_type',
                    'old' => $old,
                    'new' => $source,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                ];
                if ($oldStatus !== $status) {
                    $item['purchase_status'] = $status;
                    $item['logs'][] = [
                        'time' => date('m-d H:i'),
                        'user' => '系统管理员',
                        'action' => '货源改判',
                        'field' => 'purchase_status',
                        'old' => $oldStatus,
                        'new' => $status,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    ];
                    $this->recordJsonPurchaseStatusEvent($data, $tenantKey, $order, $item, $oldStatus, $status, '货源改判', '系统管理员');
                }
            }
            unset($item);
        }
        unset($order);

        $this->data = $data;
        $this->save();
    }

    /**
     * @param array<int, int> $itemIds
     * @param array<int, int> $orderIds
     * @param array<string, mixed> $changes
     */
    public function batchUpdateItems(
        string $tenantKey,
        array $itemIds,
        array $orderIds,
        array $changes,
        string $operator = '系统管理员',
        string $action = '批量更新'
    ): void {
        $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$itemIds && !$orderIds) {
            return;
        }

        $data = $this->all();
        if (!isset($data['orders'][$tenantKey]) || !is_array($data['orders'][$tenantKey])) {
            return;
        }

        foreach ($data['orders'][$tenantKey] as &$order) {
            $orderSelected = in_array((int) ($order['id'] ?? 0), $orderIds, true);
            foreach ($order['items'] as &$item) {
                if (!$orderSelected && !in_array((int) ($item['id'] ?? 0), $itemIds, true)) {
                    continue;
                }

                $oldStatus = (string) ($item['purchase_status'] ?? '');
                $this->applyItemChanges($item, $changes, (int) ($order['id'] ?? 0), $action, $operator);
                $this->recordJsonPurchaseStatusEvent($data, $tenantKey, $order, $item, $oldStatus, (string) ($item['purchase_status'] ?? ''), $action, $operator);
            }
            unset($item);
        }
        unset($order);

        $this->data = $data;
        $this->save();
    }

    /**
     * @param array<int, int> $itemIds
     */
    public function transitionItemPurchaseStatus(
        string $tenantKey,
        array $itemIds,
        string $fromStatus,
        string $toStatus,
        string $operator = '系统管理员',
        string $action = '状态流转'
    ): int {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        $fromStatus = trim($fromStatus);
        $toStatus = trim($toStatus);
        if (!$itemIds || $fromStatus === '' || $toStatus === '' || $fromStatus === $toStatus) {
            return 0;
        }

        $data = $this->all();
        if (!isset($data['orders'][$tenantKey]) || !is_array($data['orders'][$tenantKey])) {
            return 0;
        }

        $itemSet = array_flip($itemIds);
        $updated = 0;
        foreach ($data['orders'][$tenantKey] as &$order) {
            foreach ($order['items'] as &$item) {
                $itemId = (int) ($item['id'] ?? 0);
                if ($itemId <= 0 || !isset($itemSet[$itemId])) {
                    continue;
                }
                if ((string) ($item['purchase_status'] ?? '') !== $fromStatus) {
                    continue;
                }

                $this->applyItemChanges($item, ['purchase_status' => $toStatus], (int) ($order['id'] ?? 0), $action, $operator);
                $this->recordJsonPurchaseStatusEvent($data, $tenantKey, $order, $item, $fromStatus, (string) ($item['purchase_status'] ?? ''), $action, $operator);
                if ((string) ($item['purchase_status'] ?? '') === $toStatus) {
                    $updated++;
                }
            }
            unset($item);
        }
        unset($order);

        if ($updated > 0) {
            $this->data = $data;
            $this->save();
        }

        return $updated;
    }

    /**
     * @param array<int, int> $itemIds
     */
    public function updateItemsLogistics(string $tenantKey, array $itemIds, string $status, string $action, string $operator): int
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (!$itemIds) {
            return 0;
        }

        $status = trim($status);
        $action = trim($action) ?: '物流更新';
        $operator = trim($operator) ?: '系统';
        $data = $this->all();
        if (!isset($data['orders'][$tenantKey]) || !is_array($data['orders'][$tenantKey])) {
            return 0;
        }

        $updated = 0;
        foreach ($data['orders'][$tenantKey] as &$order) {
            foreach ($order['items'] as &$item) {
                if (!in_array((int) ($item['id'] ?? 0), $itemIds, true)) {
                    continue;
                }

                $oldValue = (string) ($item['logistics'] ?? '');
                if ($oldValue === $status) {
                    continue;
                }

                $item['logistics'] = $status;
                $item['logs'][] = [
                    'time' => date('m-d H:i'),
                    'user' => $operator,
                    'action' => $action,
                    'field' => 'logistics',
                    'old' => $oldValue,
                    'new' => $status,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'order_id' => (int) ($order['id'] ?? 0),
                ];
                $updated++;
            }
            unset($item);
        }
        unset($order);

        $this->data = $data;
        $this->save();

        return $updated;
    }

    /** @param array<int, int> $orderIds */
    public function deleteOrders(string $tenantKey, array $orderIds): void
    {
        $orderIds = array_values(array_unique(array_map('intval', $orderIds)));
        if (!$orderIds) {
            return;
        }

        $data = $this->all();
        $data['orders'][$tenantKey] = array_values(array_filter(
            $data['orders'][$tenantKey] ?? [],
            fn (array $order): bool => !in_array((int) ($order['id'] ?? 0), $orderIds, true)
        ));

        $this->data = $data;
        $this->save();
    }

    /** @param array<string, bool> $flags */
    public function updateOrderFlags(string $tenantKey, int $orderId, array $flags, string $operator): void
    {
        if ($orderId <= 0) {
            return;
        }

        $allowed = ['review_invited', 'reviewed'];
        $data = $this->all();
        foreach ($data['orders'][$tenantKey] ?? [] as &$order) {
            if ((int) ($order['id'] ?? 0) !== $orderId) {
                continue;
            }
            foreach ($allowed as $field) {
                if (!array_key_exists($field, $flags)) {
                    continue;
                }
                $old = !empty($order[$field]);
                $new = (bool) $flags[$field];
                if ($old === $new) {
                    continue;
                }
                $order[$field] = $new;
                foreach ($order['items'] ?? [] as &$item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $item['logs'][] = [
                        'time' => date('m-d H:i'),
                        'user' => $operator,
                        'action' => '评价状态切换',
                        'field' => $field,
                        'old' => $old ? '1' : '0',
                        'new' => $new ? '1' : '0',
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        'order_id' => $orderId,
                    ];
                }
                unset($item);
            }
            break;
        }
        unset($order);

        $this->data = $data;
        $this->save();
    }

    /** @param array<string, mixed> $data */
    public function insertExternalOrder(string $tenantKey, array $data, string $operator): int
    {
        $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? 'external')) ?: 'external';
        $platformOrderId = trim((string) ($data['platform_order_id'] ?? ''));
        $tracking = trim((string) ($data['tracking'] ?? ''));
        $storeId = max(0, (int) ($data['store_id'] ?? 0));
        $storeName = trim((string) ($data['store'] ?? ''));
        if ($platformOrderId === '' || $tracking === '' || $storeId <= 0 || $storeName === '') {
            return 0;
        }

        $all = $this->all();
        $orders = is_array($all['orders'][$tenantKey] ?? null) ? $all['orders'][$tenantKey] : [];
        foreach ($orders as $order) {
            if ((string) ($order['platform'] ?? '') === $platform && (int) ($order['store_id'] ?? 0) === $storeId && (string) ($order['platform_order_id'] ?? '') === $platformOrderId) {
                return (int) ($order['id'] ?? 0);
            }
        }

        $orderId = $this->nextId($orders);
        $itemId = $this->nextJsonItemId($orders);
        $orders[] = [
            'id' => $orderId,
            'platform' => $platform,
            'platform_order_id' => $platformOrderId,
            'order_date' => date('Y-m-d H:i:s'),
            'imported_at' => date('Y-m-d H:i:s'),
            'status' => '外部插入',
            'store_id' => $storeId,
            'store' => $storeName,
            'customer' => [
                'name' => trim((string) ($data['customer_name'] ?? '')),
                'phone' => trim((string) ($data['phone'] ?? '')),
                'zip' => '',
                'address' => trim((string) ($data['address'] ?? '')),
                'mail' => '',
                'kana' => '',
            ],
            'total' => 0,
            'review_invited' => false,
            'reviewed' => false,
            'items' => [[
                'id' => $itemId,
                'item_code' => trim((string) ($data['item_code'] ?? '')),
                'jp_warehouse_id' => '',
                'title' => '外部插入订单',
                'option' => '',
                'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
                'source_type' => 'pending',
                'purchase_status' => '外部插入',
                'buyer' => '',
                'purchase_time' => '',
                'purchase_link' => '',
                'amount' => 0,
                'ship_company' => '',
                'ship_number' => $tracking,
                'intl_number' => $tracking,
                'intl_status' => '',
                'image' => '/assets/no-image.svg',
                'logs' => [[
                    'time' => date('m-d H:i'),
                    'user' => $operator,
                    'action' => '外部插入订单',
                    'field' => 'platform_order_id',
                    'old' => '-',
                    'new' => $platformOrderId,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'order_id' => $orderId,
                ]],
            ]],
        ];

        $all['orders'][$tenantKey] = $orders;
        $this->data = $all;
        $this->save();

        return $orderId;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function upsertPlatformOrders(string $tenantKey, array $orders, string $operator): array
    {
        $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'items_inserted' => 0, 'items_updated' => 0];
        if (!$orders) {
            return $result;
        }

        $data = $this->all();
        $existing = is_array($data['orders'][$tenantKey] ?? null) ? $data['orders'][$tenantKey] : [];
        $nextOrderId = $this->nextId($existing);
        $nextItemId = $this->nextJsonItemId($existing);

        foreach ($orders as $order) {
            $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($order['platform'] ?? '')) ?: '';
            $platformOrderId = trim((string) ($order['platform_order_id'] ?? ''));
            $storeId = max(0, (int) ($order['store_id'] ?? 0));
            if ($storeId <= 0) {
                $storeId = $this->defaultJsonStoreId($tenantKey, $platform);
                $order['store_id'] = $storeId;
            }
            if ($platform === '' || $platformOrderId === '') {
                $result['skipped']++;
                continue;
            }

            $store = $this->store($tenantKey, $storeId) ?? [];
            $normalized = $this->jsonPlatformOrder($order, $store, $nextOrderId);
            $orderIndex = $this->findJsonPlatformOrderIndex($existing, $platform, $storeId, $platformOrderId);
            if ($orderIndex >= 0) {
                $normalized['id'] = (int) ($existing[$orderIndex]['id'] ?? $nextOrderId);
                $normalized['items'] = $this->mergeJsonItems(
                    is_array($existing[$orderIndex]['items'] ?? null) ? $existing[$orderIndex]['items'] : [],
                    is_array($order['items'] ?? null) ? $order['items'] : [],
                    $nextItemId,
                    (int) $normalized['id'],
                    $operator,
                    $result
                );
                $existing[$orderIndex] = array_replace($existing[$orderIndex], $normalized);
                $result['updated']++;
            } else {
                $normalized['items'] = $this->mergeJsonItems([], is_array($order['items'] ?? null) ? $order['items'] : [], $nextItemId, (int) $normalized['id'], $operator, $result);
                $existing[] = $normalized;
                $nextOrderId++;
                $result['inserted']++;
            }
        }

        $data['orders'][$tenantKey] = $existing;
        $this->data = $data;
        $this->save();

        return $result;
    }

    public function markStoreSync(string $tenantKey, int $storeId, string $status, string $message): void
    {
        $data = $this->all();
        if (!isset($data['stores'][$tenantKey]) || !is_array($data['stores'][$tenantKey])) {
            return;
        }

        foreach ($data['stores'][$tenantKey] as &$store) {
            if ((int) ($store['id'] ?? 0) !== $storeId) {
                continue;
            }
            $store['last_sync_at'] = date('Y-m-d H:i:s');
            $store['last_sync_status'] = $this->shortText($status, 64);
            $store['last_sync_message'] = $this->shortText($message, 1024);
            $store['api_status'] = $status;
            break;
        }
        unset($store);

        $this->data = $data;
        $this->save();
    }

    /** @param array<string, mixed> $data */
    public function updateOrderItem(
        string $tenantKey,
        int $itemId,
        array $data,
        string $operator = '系统管理员',
        string $action = '保存明细'
    ): void {
        if ($itemId <= 0) {
            return;
        }

        $store = $this->all();
        if (!isset($store['orders'][$tenantKey]) || !is_array($store['orders'][$tenantKey])) {
            return;
        }

        foreach ($store['orders'][$tenantKey] as &$order) {
            foreach ($order['items'] as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId) {
                    continue;
                }

                $oldStatus = (string) ($item['purchase_status'] ?? '');
                $this->applyItemChanges($item, $data, (int) ($order['id'] ?? 0), $action, $operator);
                $this->recordJsonPurchaseStatusEvent($store, $tenantKey, $order, $item, $oldStatus, (string) ($item['purchase_status'] ?? ''), $action, $operator);
            }
            unset($item);
        }
        unset($order);

        $this->data = $store;
        $this->save();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function purchaseStatusEvents(string $tenantKey, string $date, ?array $user = null, string $platform = ''): array
    {
        $date = trim($date);
        if ($date === '') {
            return [];
        }

        $rows = is_array($this->all()['purchase_status_events'][$tenantKey] ?? null)
            ? $this->all()['purchase_status_events'][$tenantKey]
            : [];
        $platform = trim($platform);

        $events = array_values(array_filter(
            array_map(fn (array $row): array => $this->normalizePurchaseStatusEventRow($row), array_filter($rows, 'is_array')),
            fn (array $row): bool => (string) ($row['created_date'] ?? '') <= $date
                && ($platform === '' || (string) ($row['platform'] ?? '') === $platform)
                && $this->eventVisibleToUser($row, $user)
        ));
        usort($events, static fn (array $a, array $b): int => [
            (int) ($a['order_item_id'] ?? 0),
            (string) ($a['created_at'] ?? ''),
            (int) ($a['id'] ?? 0),
        ] <=> [
            (int) ($b['order_item_id'] ?? 0),
            (string) ($b['created_at'] ?? ''),
            (int) ($b['id'] ?? 0),
        ]);

        return $events;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importPlatformOrders(string $tenantKey, array $records, string $operator): array
    {
        $report = $this->emptyImportReport();
        $orders = [];

        foreach ($records as $record) {
            $orderData = is_array($record['order'] ?? null) ? $record['order'] : [];
            $itemData = is_array($record['item'] ?? null) ? $record['item'] : [];
            $platform = trim((string) ($orderData['platform'] ?? ''));
            $platformOrderId = trim((string) ($orderData['platform_order_id'] ?? ''));
            if ($platform === '' || $platformOrderId === '') {
                $this->importReportFail($report, (int) ($record['row'] ?? 0), '平台或订单号为空。');
                continue;
            }

            if (isset($orderData['extra']) && !isset($orderData['platform_extra'])) {
                $orderData['platform_extra'] = $orderData['extra'];
            }
            if (isset($itemData['extra']) && !isset($itemData['platform_extra'])) {
                $itemData['platform_extra'] = $itemData['extra'];
            }
            $key = $platform . "\n" . (int) ($orderData['store_id'] ?? 0) . "\n" . $platformOrderId;
            if (!isset($orders[$key])) {
                $orderData['items'] = [];
                $orders[$key] = $orderData;
            } else {
                $orders[$key] = $this->mergeJsonImportOrder($orders[$key], $orderData);
            }
            if ($itemData) {
                $orders[$key]['items'][] = $itemData;
            }
        }

        if (!$orders) {
            return $report;
        }

        $result = $this->upsertPlatformOrders($tenantKey, array_values($orders), $operator);
        $report['inserted'] = (int) ($result['inserted'] ?? 0);
        $report['updated'] = (int) ($result['updated'] ?? 0) + (int) ($result['items_inserted'] ?? 0) + (int) ($result['items_updated'] ?? 0);
        $report['skipped'] = (int) ($result['skipped'] ?? 0);

        return $report;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importPurchaseRows(string $tenantKey, array $records, string $operator): array
    {
        $report = $this->emptyImportReport();
        $data = $this->all();
        if (!isset($data['orders'][$tenantKey]) || !is_array($data['orders'][$tenantKey])) {
            return $report;
        }

        $changed = false;
        foreach ($records as $record) {
            $identity = is_array($record['identity'] ?? null) ? $record['identity'] : [];
            $changes = is_array($record['changes'] ?? null) ? $record['changes'] : [];
            $target = $this->findJsonTargetItem($data['orders'][$tenantKey], $identity);
            if ($target === null) {
                $report['failed']++;
                $this->importReportMessage($report, (int) ($record['row'] ?? 0), '未找到匹配的订单商品，采购导入未更新。');
                continue;
            }

            [$orderIndex, $itemIndex] = $target;
            $oldStatus = (string) ($data['orders'][$tenantKey][$orderIndex]['items'][$itemIndex]['purchase_status'] ?? '');
            if (!$this->canAdvancePurchaseStatus($oldStatus)) {
                $report['skipped']++;
                $this->importReportMessage($report, (int) ($record['row'] ?? 0), "当前采购状态为 {$oldStatus}，未覆盖。");
                continue;
            }

            if (isset($changes['purchase_status'])) {
                $changes['purchase_status'] = $this->normalizePurchaseStatus((string) $changes['purchase_status']);
            }
            $changes['source_type'] = 'cn_purchase';
            $before = $data['orders'][$tenantKey][$orderIndex]['items'][$itemIndex];
            $oldStatus = (string) ($before['purchase_status'] ?? '');
            $this->applyItemChanges(
                $data['orders'][$tenantKey][$orderIndex]['items'][$itemIndex],
                $changes,
                (int) ($data['orders'][$tenantKey][$orderIndex]['id'] ?? 0),
                '采购导入',
                $operator
            );
            if ($before === $data['orders'][$tenantKey][$orderIndex]['items'][$itemIndex]) {
                $report['skipped']++;
            } else {
                $this->recordJsonPurchaseStatusEvent(
                    $data,
                    $tenantKey,
                    $data['orders'][$tenantKey][$orderIndex],
                    $data['orders'][$tenantKey][$orderIndex]['items'][$itemIndex],
                    $oldStatus,
                    (string) ($data['orders'][$tenantKey][$orderIndex]['items'][$itemIndex]['purchase_status'] ?? ''),
                    '采购导入',
                    $operator
                );
                $report['updated']++;
                $changed = true;
            }
        }

        if ($changed) {
            $this->data = $data;
            $this->save();
        }

        return $report;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importShippingRows(string $tenantKey, array $records, string $operator): array
    {
        $report = $this->emptyImportReport();
        $data = $this->all();
        if (!isset($data['orders'][$tenantKey]) || !is_array($data['orders'][$tenantKey])) {
            return $report;
        }

        $changed = false;
        foreach ($records as $record) {
            $identity = is_array($record['identity'] ?? null) ? $record['identity'] : [];
            $changes = is_array($record['changes'] ?? null) ? $record['changes'] : [];
            $targets = $this->findJsonTargetItems($data['orders'][$tenantKey], $identity);
            if (!$targets) {
                $report['failed']++;
                $this->importReportMessage($report, (int) ($record['row'] ?? 0), '未找到匹配的订单商品，国际运单导入未更新。');
                continue;
            }

            $rowUpdated = 0;
            foreach ($targets as [$orderIndex, $itemIndex]) {
                $item =& $data['orders'][$tenantKey][$orderIndex]['items'][$itemIndex];
                if (isset($changes['intl_number']) && empty($changes['reset_tracking'])) {
                    $changes['intl_number'] = $this->mergeTrackingNumbers((string) ($item['intl_number'] ?? ''), (string) $changes['intl_number']);
                }
                unset($changes['reset_tracking']);
                $before = $item;
                $oldStatus = (string) ($item['purchase_status'] ?? '');
                $this->applyItemChanges($item, $changes, (int) ($data['orders'][$tenantKey][$orderIndex]['id'] ?? 0), '国际运单导入', $operator);
                if ($before !== $item) {
                    $this->recordJsonPurchaseStatusEvent(
                        $data,
                        $tenantKey,
                        $data['orders'][$tenantKey][$orderIndex],
                        $item,
                        $oldStatus,
                        (string) ($item['purchase_status'] ?? ''),
                        '国际运单导入',
                        $operator
                    );
                    $rowUpdated++;
                }
                unset($item);
            }

            if ($rowUpdated > 0) {
                $report['updated'] += $rowUpdated;
                $changed = true;
            } else {
                $report['skipped']++;
            }
        }

        if ($changed) {
            $this->data = $data;
            $this->save();
        }

        return $report;
    }

    public function updateOrderItemImage(string $tenantKey, int $itemId, string $kind, string $path): void
    {
        if ($itemId <= 0 || !in_array($kind, ['main', 'sku'], true)) {
            return;
        }

        $field = $kind === 'sku' ? 'sku_image' : 'image';
        $store = $this->all();
        if (!isset($store['orders'][$tenantKey]) || !is_array($store['orders'][$tenantKey])) {
            return;
        }

        foreach ($store['orders'][$tenantKey] as &$order) {
            if (!isset($order['items']) || !is_array($order['items'])) {
                continue;
            }

            foreach ($order['items'] as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId) {
                    continue;
                }

                $oldValue = (string) ($item[$field] ?? '');
                $item[$field] = $path;
                if ($kind === 'main') {
                    $item['main_image'] = $path;
                }
                $item['logs'][] = [
                    'time' => date('m-d H:i'),
                    'user' => '系统管理员',
                    'action' => $kind === 'sku' ? '替换SKU图' : '替换主图',
                    'field' => $field,
                    'old' => $oldValue,
                    'new' => $path,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'order_id' => (int) ($order['id'] ?? 0),
                ];
            }
            unset($item);
        }
        unset($order);

        $this->data = $store;
        $this->save();
    }

    public function deleteOrderItemImage(string $tenantKey, int $itemId, string $kind): ?string
    {
        if ($itemId <= 0 || !in_array($kind, ['main', 'sku'], true)) {
            return null;
        }

        $field = $kind === 'sku' ? 'sku_image' : 'image';
        $store = $this->all();
        if (!isset($store['orders'][$tenantKey]) || !is_array($store['orders'][$tenantKey])) {
            return null;
        }

        $oldValue = null;
        foreach ($store['orders'][$tenantKey] as &$order) {
            if (!isset($order['items']) || !is_array($order['items'])) {
                continue;
            }

            foreach ($order['items'] as &$item) {
                if ((int) ($item['id'] ?? 0) !== $itemId) {
                    continue;
                }

                $oldValue = (string) ($item[$field] ?? '');
                $item[$field] = '';
                if ($kind === 'main') {
                    $item['main_image'] = '';
                }
                $item['logs'][] = [
                    'time' => date('m-d H:i'),
                    'user' => '系统管理员',
                    'action' => $kind === 'sku' ? '删除SKU图' : '删除主图',
                    'field' => $field,
                    'old' => $oldValue,
                    'new' => '',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                    'order_id' => (int) ($order['id'] ?? 0),
                ];
            }
            unset($item);
        }
        unset($order);

        if ($oldValue === null) {
            return null;
        }

        $this->data = $store;
        $this->save();

        return $oldValue;
    }

    /** @return array<int, array<string, mixed>> */
    public function orderAttachments(string $tenantKey, int $orderId): array
    {
        $data = $this->all();
        $attachments = $data['attachments'][$tenantKey][$orderId] ?? [];
        return is_array($attachments) ? $attachments : [];
    }

    /** @param array<string, mixed> $data */
    public function addOrderAttachment(string $tenantKey, int $orderId, array $data): void
    {
        if ($orderId <= 0) {
            return;
        }

        $title = trim((string) ($data['title'] ?? ''));
        $path = trim((string) ($data['path'] ?? ''));
        if ($title === '' || $path === '') {
            return;
        }

        $all = $this->all();
        $rows = $all['attachments'][$tenantKey][$orderId] ?? [];
        $rows[] = [
            'id' => $this->nextId($rows),
            'order_id' => $orderId,
            'order_item_id' => (int) ($data['order_item_id'] ?? 0),
            'type' => trim((string) ($data['type'] ?? '附件')) ?: '附件',
            'title' => $title,
            'path' => $path,
            'source' => trim((string) ($data['source'] ?? '手工登记')) ?: '手工登记',
            'uploaded_by' => trim((string) ($data['uploaded_by'] ?? '租户管理员')) ?: '租户管理员',
            'size' => trim((string) ($data['size'] ?? '')),
            'created_at' => date('Y-m-d H:i'),
        ];

        $all['attachments'][$tenantKey][$orderId] = $rows;
        $this->data = $all;
        $this->save();
    }

    public function deleteOrderAttachment(string $tenantKey, int $orderId, int $attachmentId): void
    {
        if ($orderId <= 0 || $attachmentId <= 0) {
            return;
        }

        $all = $this->all();
        $rows = $all['attachments'][$tenantKey][$orderId] ?? [];
        if (!is_array($rows)) {
            return;
        }
        $all['attachments'][$tenantKey][$orderId] = array_values(array_filter(
            $rows,
            fn (array $row): bool => (int) ($row['id'] ?? 0) !== $attachmentId
        ));

        $this->data = $all;
        $this->save();
    }

    /** @return array<string, mixed> */
    public function globalSettings(): array
    {
        $settings = $this->all()['settings']['global'] ?? [];

        return array_replace_recursive($this->defaultGlobalSettings(), is_array($settings) ? $settings : []);
    }

    /** @param array<string, mixed> $data */
    public function saveGlobalSettings(array $data): void
    {
        $all = $this->all();
        if (!isset($all['settings']) || !is_array($all['settings'])) {
            $all['settings'] = [];
        }
        $settings = array_replace_recursive($this->globalSettings(), $this->normalizeGlobalSettingsInput($data));
        $settings['updated_at'] = date('Y-m-d H:i:s');
        $all['settings']['global'] = $settings;

        $this->data = $all;
        $this->save();
    }

    /** @return array<string, mixed> */
    public function tenantSettings(string $tenantKey): array
    {
        $tenant = $this->tenant($tenantKey);
        $defaults = $this->defaultTenantSettings($tenant);
        $settings = $this->all()['settings']['tenant'][$tenantKey] ?? [];

        return array_replace_recursive($defaults, is_array($settings) ? $settings : []);
    }

    /** @param array<string, mixed> $data */
    public function saveTenantSettings(string $tenantKey, array $data): void
    {
        $all = $this->all();
        $current = $this->tenantSettings($tenantKey);
        $settings = array_replace_recursive($current, $data);
        if (array_key_exists('export_templates', $data)) {
            $settings['export_templates'] = $data['export_templates'];
        }
        if (array_key_exists('purchase_statuses', $data)) {
            $settings['purchase_statuses'] = $data['purchase_statuses'];
        }
        if (array_key_exists('jp_stock_purchase_statuses', $data)) {
            $settings['jp_stock_purchase_statuses'] = $data['jp_stock_purchase_statuses'];
        }
        if (array_key_exists('order_export_tools', $data)) {
            $settings['order_export_tools'] = $data['order_export_tools'];
        }
        $settings['updated_at'] = date('Y-m-d H:i:s');
        $all['settings']['tenant'][$tenantKey] = $settings;

        foreach ($all['tenants'] as &$tenant) {
            if (($tenant['key'] ?? '') !== $tenantKey) {
                continue;
            }

            $company = $settings['company'] ?? [];
            $tenant['company_name'] = trim((string) ($company['company_name'] ?? $tenant['company_name'] ?? '')) ?: ($tenant['company_name'] ?? $tenantKey);
            $tenant['short_name'] = trim((string) ($company['short_name'] ?? $tenant['short_name'] ?? '')) ?: ($tenant['short_name'] ?? $tenantKey);
            $tenant['contact'] = trim((string) ($company['contact'] ?? $tenant['contact'] ?? ''));
            $tenant['phone'] = trim((string) ($company['phone'] ?? $tenant['phone'] ?? ''));
            $tenant['address'] = trim((string) ($company['address'] ?? $tenant['address'] ?? ''));
            $tenant['remark'] = trim((string) ($company['note'] ?? $tenant['remark'] ?? ''));
            break;
        }
        unset($tenant);

        $this->data = $all;
        $this->save();
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantNotices(string $tenantKey): array
    {
        $settings = $this->tenantSettings($tenantKey);
        $rows = is_array($settings['notices']['items'] ?? null) ? $settings['notices']['items'] : [];
        usort($rows, static function (array $left, array $right): int {
            if (!empty($left['is_pinned']) !== !empty($right['is_pinned'])) {
                return !empty($left['is_pinned']) ? -1 : 1;
            }

            return strcmp((string) ($right['published_at'] ?? ''), (string) ($left['published_at'] ?? ''));
        });

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function tenantNotice(string $tenantKey, int $noticeId): ?array
    {
        foreach ($this->tenantNotices($tenantKey) as $notice) {
            if ((int) ($notice['id'] ?? 0) === $noticeId) {
                return $notice;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public function saveTenantNotice(string $tenantKey, array $data): int
    {
        $settings = $this->tenantSettings($tenantKey);
        $notices = is_array($settings['notices']['items'] ?? null) ? $settings['notices']['items'] : [];
        $noticeId = (int) ($data['id'] ?? 0);
        if ($noticeId <= 0) {
            $noticeId = $this->nextId($notices);
            $data['id'] = $noticeId;
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        $data['tenant_key'] = $tenantKey;
        $data['updated_at'] = date('Y-m-d H:i:s');

        $updated = false;
        foreach ($notices as &$notice) {
            if ((int) ($notice['id'] ?? 0) !== $noticeId) {
                continue;
            }
            $notice = array_replace($notice, $data);
            $updated = true;
            break;
        }
        unset($notice);

        if (!$updated) {
            $notices[] = $data;
        }

        $this->saveTenantSettings($tenantKey, ['notices' => ['items' => $notices]]);

        return $noticeId;
    }

    public function deleteTenantNotice(string $tenantKey, int $noticeId): void
    {
        if ($noticeId <= 0) {
            return;
        }

        $settings = $this->tenantSettings($tenantKey);
        $notices = is_array($settings['notices']['items'] ?? null) ? $settings['notices']['items'] : [];
        $notices = array_values(array_filter(
            $notices,
            static fn (array $notice): bool => (int) ($notice['id'] ?? 0) !== $noticeId
        ));
        $this->saveTenantSettings($tenantKey, ['notices' => ['items' => $notices]]);
    }

    public function toggleTenantNoticePinned(string $tenantKey, int $noticeId, bool $pinned): void
    {
        if ($noticeId <= 0) {
            return;
        }

        $settings = $this->tenantSettings($tenantKey);
        $notices = is_array($settings['notices']['items'] ?? null) ? $settings['notices']['items'] : [];
        foreach ($notices as &$notice) {
            if ((int) ($notice['id'] ?? 0) !== $noticeId) {
                continue;
            }
            $notice['is_pinned'] = $pinned;
            $notice['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
        unset($notice);

        $this->saveTenantSettings($tenantKey, ['notices' => ['items' => $notices]]);
    }

    /** @return array<int, array<string, mixed>> */
    public function importExportLogs(string $tenantKey): array
    {
        $rows = $this->all()['import_export_logs'][$tenantKey] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        return array_slice(array_reverse($rows), 0, 30);
    }

    /** @param array<string, mixed> $data */
    public function addImportExportLog(string $tenantKey, array $data): void
    {
        $all = $this->all();
        $rows = $all['import_export_logs'][$tenantKey] ?? [];
        $rows = is_array($rows) ? $rows : [];
        $rows[] = [
            'id' => $this->nextId($rows),
            'type' => trim((string) ($data['type'] ?? 'import')),
            'name' => trim((string) ($data['name'] ?? '')),
            'status' => trim((string) ($data['status'] ?? '已记录')),
            'file_name' => trim((string) ($data['file_name'] ?? '')),
            'rows' => (int) ($data['rows'] ?? 0),
            'message' => trim((string) ($data['message'] ?? '')),
            'preview' => array_slice(is_array($data['preview'] ?? null) ? $data['preview'] : [], 0, 5),
            'created_by' => trim((string) ($data['created_by'] ?? '系统')),
            'created_at' => date('Y-m-d H:i:s'),
        ];

        $all['import_export_logs'][$tenantKey] = array_slice($rows, -100);
        $this->data = $all;
        $this->save();
    }

    /** @return array<int, array<string, mixed>> */
    public function mailAccounts(string $tenantKey): array
    {
        $rows = $this->all()['mail']['accounts'][$tenantKey] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        usort($rows, fn (array $a, array $b): int => [(int) ($a['sort'] ?? 0), (int) ($a['id'] ?? 0)] <=> [(int) ($b['sort'] ?? 0), (int) ($b['id'] ?? 0)]);

        $folders = $this->mailFolders($tenantKey);
        $synced = [];
        foreach ($folders as $folder) {
            if ((int) ($folder['sync_enabled'] ?? 0) !== 1) {
                continue;
            }
            $aid = (int) ($folder['account_id'] ?? 0);
            $synced[$aid] = ($synced[$aid] ?? 0) + 1;
        }

        return array_map(function (array $row) use ($synced): array {
            $row['synced_folders'] = $synced[(int) ($row['id'] ?? 0)] ?? 0;
            return $row;
        }, $rows);
    }

    /** @return array<string, mixed>|null */
    public function mailAccount(string $tenantKey, int $accountId): ?array
    {
        foreach ($this->mailAccounts($tenantKey) as $account) {
            if ((int) ($account['id'] ?? 0) === $accountId) {
                return $account;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    public function saveMailAccount(string $tenantKey, array $data): int
    {
        $all = $this->all();
        $rows = $all['mail']['accounts'][$tenantKey] ?? [];
        $rows = is_array($rows) ? $rows : [];
        $id = (int) ($data['id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $existing = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                $existing = $row;
                break;
            }
        }
        if ($id <= 0) {
            $id = $this->nextId($rows);
        }

        $record = [
            'id' => $id,
            'shop_dpqz' => trim((string) ($data['shop_dpqz'] ?? $existing['shop_dpqz'] ?? '')),
            'shop_name' => trim((string) ($data['shop_name'] ?? $existing['shop_name'] ?? '')),
            'platform' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? $existing['platform'] ?? '')),
            'imap_host' => trim((string) ($data['imap_host'] ?? $existing['imap_host'] ?? '')),
            'imap_port' => max(1, (int) ($data['imap_port'] ?? $existing['imap_port'] ?? 993)),
            'imap_ssl' => (int) (($data['imap_ssl'] ?? $existing['imap_ssl'] ?? 1) ? 1 : 0),
            'imap_user' => trim((string) ($data['imap_user'] ?? $existing['imap_user'] ?? '')),
            'imap_pass' => (string) ($data['imap_pass'] ?? $existing['imap_pass'] ?? ''),
            'smtp_host' => trim((string) ($data['smtp_host'] ?? $existing['smtp_host'] ?? '')),
            'smtp_port' => max(1, (int) ($data['smtp_port'] ?? $existing['smtp_port'] ?? 465)),
            'smtp_secure' => in_array(($data['smtp_secure'] ?? $existing['smtp_secure'] ?? 'ssl'), ['ssl', 'tls', 'none'], true) ? (string) ($data['smtp_secure'] ?? $existing['smtp_secure'] ?? 'ssl') : 'ssl',
            'smtp_user' => trim((string) ($data['smtp_user'] ?? $existing['smtp_user'] ?? '')),
            'smtp_pass' => (string) ($data['smtp_pass'] ?? $existing['smtp_pass'] ?? ''),
            'sent_folder' => trim((string) ($data['sent_folder'] ?? $existing['sent_folder'] ?? 'Sent')),
            'enabled' => (int) (($data['enabled'] ?? $existing['enabled'] ?? 1) ? 1 : 0),
            'sort' => (int) ($data['sort'] ?? $existing['sort'] ?? $id),
            'last_sync_at' => (string) ($existing['last_sync_at'] ?? ''),
            'created_at' => (string) ($existing['created_at'] ?? $now),
            'updated_at' => $now,
        ];

        $saved = false;
        foreach ($rows as &$row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                $row = $record;
                $saved = true;
                break;
            }
        }
        unset($row);
        if (!$saved) {
            $rows[] = $record;
        }

        $all['mail']['accounts'][$tenantKey] = $rows;
        $this->data = $all;
        $this->save();

        return $id;
    }

    public function deleteMailAccount(string $tenantKey, int $accountId): void
    {
        if ($accountId <= 0) {
            return;
        }
        $all = $this->all();
        foreach (['accounts', 'folders', 'messages', 'rules', 'replies'] as $bucket) {
            $rows = $all['mail'][$bucket][$tenantKey] ?? [];
            if (!is_array($rows)) {
                continue;
            }
            $all['mail'][$bucket][$tenantKey] = array_values(array_filter($rows, function (array $row) use ($bucket, $accountId): bool {
                if ($bucket === 'rules') {
                    $ids = array_map('intval', (array) ($row['account_ids'] ?? []));
                    return !in_array($accountId, $ids, true) && (int) ($row['account_id'] ?? 0) !== $accountId;
                }
                return (int) ($row['account_id'] ?? $row['id'] ?? 0) !== $accountId;
            }));
        }

        $this->data = $all;
        $this->save();
    }

    /** @return array<int, array<string, mixed>> */
    public function mailFolders(string $tenantKey, ?int $accountId = null, bool $onlySynced = false): array
    {
        $rows = $this->all()['mail']['folders'][$tenantKey] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $rows = array_values(array_filter($rows, function (array $row) use ($accountId, $onlySynced): bool {
            if ($accountId !== null && (int) ($row['account_id'] ?? 0) !== $accountId) {
                return false;
            }
            if ($onlySynced && (int) ($row['sync_enabled'] ?? 0) !== 1) {
                return false;
            }
            return true;
        }));

        usort($rows, fn (array $a, array $b): int => [(int) ($a['account_id'] ?? 0), (int) ($a['sort'] ?? 0), (int) ($a['id'] ?? 0)] <=> [(int) ($b['account_id'] ?? 0), (int) ($b['sort'] ?? 0), (int) ($b['id'] ?? 0)]);

        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function mailFolder(string $tenantKey, int $folderId): ?array
    {
        foreach ($this->mailFolders($tenantKey) as $folder) {
            if ((int) ($folder['id'] ?? 0) === $folderId) {
                return $folder;
            }
        }

        return null;
    }

    /** @param array<int, string> $folders */
    public function upsertMailFolders(string $tenantKey, int $accountId, array $folders): void
    {
        $account = $this->mailAccount($tenantKey, $accountId);
        if (!$account) {
            return;
        }

        $all = $this->all();
        $rows = $all['mail']['folders'][$tenantKey] ?? [];
        $rows = is_array($rows) ? $rows : [];
        $next = $this->nextId($rows);
        $now = date('Y-m-d H:i:s');
        $sort = 1;
        foreach (array_values(array_unique(array_filter(array_map('trim', $folders)))) as $path) {
            $found = false;
            foreach ($rows as &$row) {
                if ((int) ($row['account_id'] ?? 0) === $accountId && (string) ($row['imap_path'] ?? '') === $path) {
                    $row['sort'] = (int) ($row['sort'] ?? $sort);
                    $row['updated_at'] = $now;
                    $found = true;
                    break;
                }
            }
            unset($row);
            if (!$found) {
                $rows[] = [
                    'id' => $next++,
                    'account_id' => $accountId,
                    'shop_dpqz' => (string) ($account['shop_dpqz'] ?? ''),
                    'imap_path' => $path,
                    'display_name' => $this->mailFolderLeaf($path),
                    'role' => strtoupper($path) === 'INBOX' ? 'inbox' : 'custom',
                    'sync_enabled' => strtoupper($path) === 'INBOX' ? 1 : 0,
                    'sort' => $sort,
                    'last_uid' => 0,
                    'last_uidnext' => 0,
                    'last_exists' => 0,
                    'uidvalidity' => 0,
                    'backfill_done' => 0,
                    'msg_count' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $sort++;
        }

        $all['mail']['folders'][$tenantKey] = $rows;
        $this->data = $all;
        $this->save();
    }

    /** @param array<string, mixed> $data */
    public function updateMailFolder(string $tenantKey, int $folderId, array $data): void
    {
        if ($folderId <= 0) {
            return;
        }

        $all = $this->all();
        $rows = $all['mail']['folders'][$tenantKey] ?? [];
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as &$row) {
            if ((int) ($row['id'] ?? 0) !== $folderId) {
                continue;
            }
            if (array_key_exists('display_name', $data)) {
                $row['display_name'] = trim((string) $data['display_name']);
            }
            if (array_key_exists('role', $data)) {
                $role = trim((string) $data['role']);
                $row['role'] = in_array($role, ['inbox', 'sent', 'junk', 'inquiry', 'notice', 'custom'], true) ? $role : 'custom';
            }
            if (array_key_exists('sync_enabled', $data)) {
                $row['sync_enabled'] = (int) ($data['sync_enabled'] ? 1 : 0);
            }
            if (array_key_exists('sort', $data)) {
                $row['sort'] = (int) $data['sort'];
            }
            $row['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
        unset($row);

        $all['mail']['folders'][$tenantKey] = $rows;
        $this->data = $all;
        $this->save();
    }

    /** @return array<string, mixed> */
    public function mailFolderCounts(string $tenantKey): array
    {
        $unread = [];
        $total = [];
        $totalUnread = 0;
        $totalAll = 0;

        foreach (($this->all()['mail']['messages'][$tenantKey] ?? []) as $message) {
            if (!is_array($message) || (int) ($message['is_deleted'] ?? 0) === 1) {
                continue;
            }
            $fid = (int) ($message['folder_id'] ?? 0);
            $total[$fid] = ($total[$fid] ?? 0) + 1;
            $totalAll++;
            if ((int) ($message['is_read'] ?? 0) === 0) {
                $unread[$fid] = ($unread[$fid] ?? 0) + 1;
                $totalUnread++;
            }
        }

        return [
            'unread_map' => $unread,
            'total_map' => $total,
            'total_unread' => $totalUnread,
            'total_all' => $totalAll,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, page_size: int, total_pages: int}
     */
    public function mailMessages(string $tenantKey, array $filters, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $accountId = (int) ($filters['account_id'] ?? 0);
        $folderId = (int) ($filters['folder_id'] ?? 0);
        $qRaw = trim((string) ($filters['q'] ?? ''));
        $q = function_exists('mb_strtolower') ? mb_strtolower($qRaw, 'UTF-8') : strtolower($qRaw);
        $onlyUnread = (bool) ($filters['unread'] ?? false);
        $onlyImportant = (bool) ($filters['important'] ?? false);
        $scopeInboxIds = [];
        if ($accountId <= 0 && $folderId <= 0) {
            foreach ($this->mailFolders($tenantKey, null, true) as $folder) {
                if (strtoupper((string) ($folder['imap_path'] ?? '')) === 'INBOX') {
                    $scopeInboxIds[] = (int) ($folder['id'] ?? 0);
                }
            }
        }

        $rows = [];
        foreach (($this->all()['mail']['messages'][$tenantKey] ?? []) as $message) {
            if (!is_array($message) || (int) ($message['is_deleted'] ?? 0) === 1) {
                continue;
            }
            if ($folderId > 0 && (int) ($message['folder_id'] ?? 0) !== $folderId) {
                continue;
            }
            if ($folderId <= 0 && $accountId > 0 && (int) ($message['account_id'] ?? 0) !== $accountId) {
                continue;
            }
            if ($folderId <= 0 && $accountId <= 0 && $scopeInboxIds && !in_array((int) ($message['folder_id'] ?? 0), $scopeInboxIds, true)) {
                continue;
            }
            if ($onlyUnread && (int) ($message['is_read'] ?? 0) === 1) {
                continue;
            }
            if ($onlyImportant && (int) ($message['is_important'] ?? 0) !== 1) {
                continue;
            }
            if ($q !== '') {
                $haystackRaw = implode(' ', [
                    $message['subject'] ?? '',
                    $message['from_addr'] ?? '',
                    $message['from_name'] ?? '',
                    $message['to_addr'] ?? '',
                ]);
                $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystackRaw, 'UTF-8') : strtolower($haystackRaw);
                if (!str_contains($haystack, $q)) {
                    continue;
                }
            }
            $rows[] = $this->mailHydrateMessage($tenantKey, $message);
        }

        usort($rows, function (array $a, array $b): int {
            return [strtotime((string) ($b['mail_date'] ?? '')) ?: 0, (int) ($b['id'] ?? 0)] <=> [strtotime((string) ($a['mail_date'] ?? '')) ?: 0, (int) ($a['id'] ?? 0)];
        });

        $total = count($rows);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;

        return [
            'rows' => array_slice($rows, $offset, $pageSize),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => $totalPages,
        ];
    }

    /** @return array<string, mixed>|null */
    public function mailMessage(string $tenantKey, int $messageId): ?array
    {
        foreach (($this->all()['mail']['messages'][$tenantKey] ?? []) as $message) {
            if (is_array($message) && (int) ($message['id'] ?? 0) === $messageId) {
                return $this->mailHydrateMessage($tenantKey, $message);
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array{inserted: int, inserted_ids: array<int, int>, max_uid: int}
     */
    public function insertMailMessages(string $tenantKey, int $accountId, int $folderId, array $messages): array
    {
        $account = $this->mailAccount($tenantKey, $accountId);
        if (!$account || !$this->mailFolder($tenantKey, $folderId)) {
            return ['inserted' => 0, 'inserted_ids' => [], 'max_uid' => 0];
        }

        $all = $this->all();
        $rows = $all['mail']['messages'][$tenantKey] ?? [];
        $rows = is_array($rows) ? $rows : [];
        $next = $this->nextId($rows);
        $insertedIds = [];
        $maxUid = 0;
        foreach ($messages as $message) {
            $uid = (int) ($message['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $maxUid = max($maxUid, $uid);
            $exists = false;
            foreach ($rows as $row) {
                if ((int) ($row['folder_id'] ?? 0) === $folderId && (int) ($row['uid'] ?? 0) === $uid) {
                    $exists = true;
                    break;
                }
            }
            if ($exists) {
                continue;
            }

            $id = $next++;
            $seen = (int) (($message['seen'] ?? 0) ? 1 : 0);
            $rows[] = [
                'id' => $id,
                'account_id' => $accountId,
                'shop_dpqz' => (string) ($account['shop_dpqz'] ?? ''),
                'folder_id' => $folderId,
                'uid' => $uid,
                'message_id' => substr((string) ($message['message_id'] ?? ''), 0, 512),
                'from_addr' => substr((string) ($message['from_addr'] ?? ''), 0, 320),
                'from_name' => substr((string) ($message['from_name'] ?? ''), 0, 320),
                'to_addr' => (string) ($message['to_addr'] ?? ''),
                'cc_addr' => $message['cc_addr'] ?? null,
                'subject' => substr((string) ($message['subject'] ?? ''), 0, 1000),
                'body_text' => (string) ($message['body_text'] ?? ''),
                'body_html' => (string) ($message['body_html'] ?? ''),
                'mail_date' => (string) ($message['mail_date'] ?? date('Y-m-d H:i:s')),
                'seen' => $seen,
                'is_read' => $seen,
                'has_attachment' => (int) (($message['has_attachment'] ?? 0) ? 1 : 0),
                'attachments' => is_array($message['attachments'] ?? null) ? $message['attachments'] : [],
                'body_loaded' => (int) (($message['body_loaded'] ?? 0) ? 1 : 0),
                'is_important' => 0,
                'is_deleted' => 0,
                'replied' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ];
            $insertedIds[] = $id;
        }

        $all['mail']['messages'][$tenantKey] = $rows;
        $this->data = $all;
        $this->save();

        return ['inserted' => count($insertedIds), 'inserted_ids' => $insertedIds, 'max_uid' => $maxUid];
    }

    /** @param array<string, int> $status */
    public function updateMailFolderAfterSync(string $tenantKey, int $folderId, int $lastUid, int $messageCount, array $status = []): void
    {
        $all = $this->all();
        $rows = $all['mail']['folders'][$tenantKey] ?? [];
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as &$row) {
            if ((int) ($row['id'] ?? 0) !== $folderId) {
                continue;
            }
            $row['last_uid'] = max((int) ($row['last_uid'] ?? 0), $lastUid);
            $row['msg_count'] = max(0, $messageCount);
            foreach (['last_uidnext', 'last_exists', 'uidvalidity', 'backfill_done'] as $key) {
                if (array_key_exists($key, $status)) {
                    $row[$key] = (int) $status[$key];
                }
            }
            $row['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
        unset($row);

        $all['mail']['folders'][$tenantKey] = $rows;
        $this->data = $all;
        $this->save();
    }

    public function updateMailAccountLastSync(string $tenantKey, int $accountId): void
    {
        $all = $this->all();
        $rows = $all['mail']['accounts'][$tenantKey] ?? [];
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as &$row) {
            if ((int) ($row['id'] ?? 0) === $accountId) {
                $row['last_sync_at'] = date('Y-m-d H:i:s');
                break;
            }
        }
        unset($row);
        $all['mail']['accounts'][$tenantKey] = $rows;
        $this->data = $all;
        $this->save();
    }

    /** @param array<string, mixed> $body */
    public function saveMailMessageBody(string $tenantKey, int $messageId, array $body): void
    {
        $changes = [
            'body_text' => (string) ($body['body_text'] ?? ''),
            'body_html' => (string) ($body['body_html'] ?? ''),
            'cc_addr' => (string) ($body['cc_addr'] ?? ''),
            'has_attachment' => (int) (($body['has_attachment'] ?? 0) ? 1 : 0),
            'attachments' => is_array($body['attachments'] ?? null) ? $body['attachments'] : [],
            'body_loaded' => 1,
        ];
        $this->updateMailMessages($tenantKey, [$messageId], $changes);
    }

    /**
     * @param array<int, int> $messageIds
     * @param array<string, mixed> $changes
     */
    public function updateMailMessages(string $tenantKey, array $messageIds, array $changes): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if (!$ids || !$changes) {
            return 0;
        }
        $allowed = ['folder_id', 'is_read', 'is_important', 'is_deleted', 'replied', 'body_text', 'body_html', 'cc_addr', 'has_attachment', 'attachments', 'body_loaded'];
        $all = $this->all();
        $rows = $all['mail']['messages'][$tenantKey] ?? [];
        if (!is_array($rows)) {
            return 0;
        }
        $count = 0;
        foreach ($rows as &$row) {
            if (!in_array((int) ($row['id'] ?? 0), $ids, true)) {
                continue;
            }
            foreach ($allowed as $field) {
                if (array_key_exists($field, $changes)) {
                    $row[$field] = $changes[$field];
                }
            }
            $row['updated_at'] = date('Y-m-d H:i:s');
            $count++;
        }
        unset($row);

        $all['mail']['messages'][$tenantKey] = $rows;
        $this->data = $all;
        $this->save();

        return $count;
    }

    /** @return array<int, array<string, mixed>> */
    public function mailRules(string $tenantKey): array
    {
        $rows = $this->all()['mail']['rules'][$tenantKey] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        usort($rows, fn (array $a, array $b): int => [(int) ($a['priority'] ?? 0), (int) ($a['id'] ?? 0)] <=> [(int) ($b['priority'] ?? 0), (int) ($b['id'] ?? 0)]);
        return $rows;
    }

    /** @param array<string, mixed> $data */
    public function saveMailRule(string $tenantKey, array $data): int
    {
        $all = $this->all();
        $rows = $all['mail']['rules'][$tenantKey] ?? [];
        $rows = is_array($rows) ? $rows : [];
        $id = (int) ($data['id'] ?? 0);
        $existing = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                $existing = $row;
                break;
            }
        }
        if ($id <= 0) {
            $id = $this->nextId($rows);
        }

        $accountIds = array_values(array_unique(array_filter(array_map('intval', (array) ($data['account_ids'] ?? $existing['account_ids'] ?? [])))));
        $record = [
            'id' => $id,
            'name' => trim((string) ($data['name'] ?? $existing['name'] ?? '')),
            'account_id' => $accountIds[0] ?? 0,
            'apply_all' => (int) (($data['apply_all'] ?? $existing['apply_all'] ?? 0) ? 1 : 0),
            'account_ids' => $accountIds,
            'platforms' => trim((string) ($data['platforms'] ?? $existing['platforms'] ?? '')),
            'priority' => (int) ($data['priority'] ?? $existing['priority'] ?? $id),
            'enabled' => (int) (($data['enabled'] ?? $existing['enabled'] ?? 1) ? 1 : 0),
            'match_from' => trim((string) ($data['match_from'] ?? $existing['match_from'] ?? '')),
            'match_subject' => trim((string) ($data['match_subject'] ?? $existing['match_subject'] ?? '')),
            'match_to' => trim((string) ($data['match_to'] ?? $existing['match_to'] ?? '')),
            'target_folder_id' => (int) ($data['target_folder_id'] ?? $existing['target_folder_id'] ?? 0),
            'target_folder_name' => trim((string) ($data['target_folder_name'] ?? $existing['target_folder_name'] ?? '')),
            'auto_create_folder' => (int) (($data['auto_create_folder'] ?? $existing['auto_create_folder'] ?? 1) ? 1 : 0),
            'mark_read' => (int) (($data['mark_read'] ?? $existing['mark_read'] ?? 0) ? 1 : 0),
            'mark_important' => (int) (($data['mark_important'] ?? $existing['mark_important'] ?? 0) ? 1 : 0),
            'stop_on_match' => (int) (($data['stop_on_match'] ?? $existing['stop_on_match'] ?? 1) ? 1 : 0),
            'created_at' => (string) ($existing['created_at'] ?? date('Y-m-d H:i:s')),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $saved = false;
        foreach ($rows as &$row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                $row = $record;
                $saved = true;
                break;
            }
        }
        unset($row);
        if (!$saved) {
            $rows[] = $record;
        }

        $all['mail']['rules'][$tenantKey] = $rows;
        $this->data = $all;
        $this->save();

        return $id;
    }

    public function deleteMailRule(string $tenantKey, int $ruleId): void
    {
        $all = $this->all();
        $rows = $all['mail']['rules'][$tenantKey] ?? [];
        if (!is_array($rows)) {
            return;
        }
        $all['mail']['rules'][$tenantKey] = array_values(array_filter($rows, fn (array $row): bool => (int) ($row['id'] ?? 0) !== $ruleId));
        $this->data = $all;
        $this->save();
    }

    /** @param array<string, mixed> $data */
    public function addMailReply(string $tenantKey, array $data): void
    {
        $all = $this->all();
        $rows = $all['mail']['replies'][$tenantKey] ?? [];
        $rows = is_array($rows) ? $rows : [];
        $rows[] = [
            'id' => $this->nextId($rows),
            'message_id' => (int) ($data['message_id'] ?? 0),
            'account_id' => (int) ($data['account_id'] ?? 0),
            'to_addr' => trim((string) ($data['to_addr'] ?? '')),
            'cc_addr' => trim((string) ($data['cc_addr'] ?? '')),
            'bcc_addr' => trim((string) ($data['bcc_addr'] ?? '')),
            'subject' => trim((string) ($data['subject'] ?? '')),
            'body' => (string) ($data['body'] ?? ''),
            'operator' => trim((string) ($data['operator'] ?? '')),
            'success' => (int) (($data['success'] ?? 0) ? 1 : 0),
            'error_msg' => trim((string) ($data['error_msg'] ?? '')),
            'appended' => (int) (($data['appended'] ?? 0) ? 1 : 0),
            'has_attach' => (int) (($data['has_attach'] ?? 0) ? 1 : 0),
            'sent_at' => date('Y-m-d H:i:s'),
        ];

        $all['mail']['replies'][$tenantKey] = array_slice($rows, -500);
        $this->data = $all;
        $this->save();
    }

    private function save(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $this->file,
            json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function writeTenantPointEntry(
        string $tenantKey,
        int $amount,
        string $type,
        string $note,
        string $operator,
        bool $requireEnough = false,
        bool $allowDebt = false,
        bool $autoSuspend = false,
        ?int &$balanceAfterOut = null
    ): bool
    {
        $all = $this->all();
        $this->ensureBillingStructure($all);

        $balanceAfter = null;
        $tenantName = $tenantKey;
        foreach ($all['tenants'] as &$tenant) {
            if (($tenant['key'] ?? '') !== $tenantKey) {
                continue;
            }
            $tenantName = (string) (($tenant['company_name'] ?? '') ?: $tenantKey);
            $current = (int) ($tenant['balance'] ?? 0);
            $balanceAfter = $current + $amount;
            if ($amount < 0 && ($requireEnough || !$allowDebt) && $balanceAfter < 0) {
                return false;
            }
            $tenant['balance'] = $balanceAfter;
            $tenant['billing_updated_at'] = date('Y-m-d H:i:s');
            if ($autoSuspend && $balanceAfter <= self::DEBT_SUSPEND_THRESHOLD) {
                $tenant['status'] = 'suspended';
                $tenant['suspended_reason'] = '积分余额达到 ' . self::DEBT_SUSPEND_THRESHOLD . 'pt，系统自动停用';
                $tenant['suspended_at'] = date('Y-m-d H:i:s');
            }
            break;
        }
        unset($tenant);

        if ($balanceAfter === null) {
            return false;
        }
        $balanceAfterOut = $balanceAfter;

        $rows = $all['billing']['ledger'][$tenantKey] ?? [];
        $rows = is_array($rows) ? $rows : [];
        if ($autoSuspend && $balanceAfter <= self::DEBT_SUSPEND_THRESHOLD) {
            $note = trim($note) . '；余额达到 ' . self::DEBT_SUSPEND_THRESHOLD . 'pt 自动停用租户';
        }
        $rows[] = [
            'id' => $this->nextId($rows),
            'tenant_key' => $tenantKey,
            'tenant_name' => $tenantName,
            'type' => in_array($type, ['recharge', 'adjustment', 'charge'], true) ? $type : 'adjustment',
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'note' => trim($note) !== '' ? trim($note) : '积分调整',
            'operator' => trim($operator) !== '' ? trim($operator) : 'system',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $all['billing']['ledger'][$tenantKey] = array_slice($rows, -500);

        $this->data = $all;
        $this->save();
        return true;
    }

    private function hydrateMissingTenantData(): void
    {
        if (!is_array($this->data)) {
            return;
        }

        $changed = false;
        if (!isset($this->data['admins']) || !is_array($this->data['admins']) || count($this->data['admins']) === 0) {
            $this->data['admins'] = $this->seedAdmins();
            $changed = true;
        } else {
            foreach ($this->data['admins'] as &$admin) {
                $admin += [
                    'display_name' => '超级管理员',
                    'status' => 'active',
                    'created_at' => '2026-06-18 09:00:00',
                    'last_login_at' => '',
                ];
                if (trim((string) ($admin['password_hash'] ?? '')) === '') {
                    $admin['password_hash'] = $this->hashPassword($this->defaultAdminPassword());
                    $changed = true;
                }
            }
            unset($admin);
        }

        if (!isset($this->data['billing']) || !is_array($this->data['billing'])) {
            $this->data['billing'] = ['ledger' => []];
            $changed = true;
        }
        if (!isset($this->data['billing']['ledger']) || !is_array($this->data['billing']['ledger'])) {
            $this->data['billing']['ledger'] = [];
            $changed = true;
        }
        if (!isset($this->data['billing']['subscriptions']) || !is_array($this->data['billing']['subscriptions'])) {
            $this->data['billing']['subscriptions'] = [];
            $changed = true;
        }
        if (!isset($this->data['settings']) || !is_array($this->data['settings'])) {
            $this->data['settings'] = [];
            $changed = true;
        }
        if (!isset($this->data['settings']['tenant']) || !is_array($this->data['settings']['tenant'])) {
            $this->data['settings']['tenant'] = [];
            $changed = true;
        }
        $globalSettings = is_array($this->data['settings']['global'] ?? null) ? $this->data['settings']['global'] : [];
        $normalizedGlobal = array_replace_recursive($this->defaultGlobalSettings(), $globalSettings);
        if (($this->data['settings']['global'] ?? null) !== $normalizedGlobal) {
            $this->data['settings']['global'] = $normalizedGlobal;
            $changed = true;
        }

        foreach ($this->tenantsFromData($this->data) as $tenant) {
            $key = (string) ($tenant['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $tenantName = (string) (($tenant['company_name'] ?? '') ?: $key);

            foreach ($this->data['tenants'] as &$tenantRow) {
                if (($tenantRow['key'] ?? '') !== $key) {
                    continue;
                }
                $normalizedFeatures = TenantFeature::normalizeRows(is_array($tenantRow['features'] ?? null) ? $tenantRow['features'] : []);
                if (($tenantRow['features'] ?? []) !== $normalizedFeatures) {
                    $tenantRow['features'] = $normalizedFeatures;
                    $changed = true;
                }
                if (!isset($tenantRow['balance']) || !is_numeric($tenantRow['balance'])) {
                    $tenantRow['balance'] = 0;
                    $changed = true;
                }
                if (!isset($tenantRow['billing_updated_at'])) {
                    $tenantRow['billing_updated_at'] = '';
                    $changed = true;
                }
                break;
            }
            unset($tenantRow);

            if (!isset($this->data['billing']['ledger'][$key]) || !is_array($this->data['billing']['ledger'][$key])) {
                $initialBalance = (int) ($tenant['balance'] ?? 0);
                $this->data['billing']['ledger'][$key] = $initialBalance > 0 ? [[
                    'id' => 1,
                    'tenant_key' => $key,
                    'tenant_name' => $tenantName,
                    'type' => 'recharge',
                    'amount' => $initialBalance,
                    'balance_after' => $initialBalance,
                    'note' => '开发数据初始积分',
                    'operator' => 'system',
                    'created_at' => '2026-06-18 09:00:00',
                ]] : [];
                $changed = true;
            } else {
                foreach ($this->data['billing']['ledger'][$key] as &$ledgerRow) {
                    if (!is_array($ledgerRow)) {
                        continue;
                    }
                    if (($ledgerRow['tenant_name'] ?? '') === '') {
                        $ledgerRow['tenant_name'] = $tenantName;
                        $changed = true;
                    }
                    if (($ledgerRow['tenant_key'] ?? '') === '') {
                        $ledgerRow['tenant_key'] = $key;
                        $changed = true;
                    }
                }
                unset($ledgerRow);
            }

            if (!isset($this->data['stores'][$key]) || !is_array($this->data['stores'][$key]) || count($this->data['stores'][$key]) === 0) {
                $this->data['stores'][$key] = $this->seedStoresForTenant($tenant, $this->data['platforms'] ?? []);
                $changed = true;
            } else {
                foreach ($this->data['stores'][$key] as &$store) {
                    $store += [
                        'legacy_dpid' => '',
                        'api_config' => '',
                        'profit_deduction' => 70,
                        'hidden_reason' => ($store['status'] ?? '') === 'hidden' ? '平台锁定或旧系统隐藏店铺' : '',
                    ];
                }
                unset($store);
            }
            if (isset($this->data['orders'][$key]) && is_array($this->data['orders'][$key])) {
                $changed = $this->hydrateLegacyOrderFields($this->data['orders'][$key]) || $changed;
            }
            $subscriptionCount = count(is_array($this->data['billing']['subscriptions'][$key] ?? null) ? $this->data['billing']['subscriptions'][$key] : []);
            foreach ($this->data['stores'][$key] as $storeRow) {
                if (is_array($storeRow)) {
                    $this->upsertStoreBillingSubscription($this->data, $key, $storeRow, true);
                }
            }
            $newSubscriptionCount = count(is_array($this->data['billing']['subscriptions'][$key] ?? null) ? $this->data['billing']['subscriptions'][$key] : []);
            if ($newSubscriptionCount !== $subscriptionCount) {
                $changed = true;
            }
            if (!isset($this->data['users'][$key]) || !is_array($this->data['users'][$key]) || count($this->data['users'][$key]) === 0) {
                $this->data['users'][$key] = $this->seedUsersForTenant($tenant);
                $changed = true;
            } else {
                foreach ($this->data['users'][$key] as &$user) {
                    $user += [
                        'password_reset' => '',
                        'legacy_password' => '',
                        'password_reset_at' => '',
                        'last_login_at' => '',
                        'preference_module' => '',
                        'api_1688_config' => '',
                    ];
                    if (trim((string) ($user['password_hash'] ?? '')) === '') {
                        $user['password_hash'] = $this->hashPassword($this->defaultTenantPassword());
                        $changed = true;
                    }
                    $role = (string) ($user['role'] ?? '客服');
                    $permissions = $this->permissionsForRole($role, $user['permissions'] ?? []);
                    if (($user['permissions'] ?? []) !== $permissions) {
                        $user['permissions'] = $permissions;
                        $changed = true;
                    }
                }
                unset($user);
            }
            if (!isset($this->data['assignments'][$key]) || !is_array($this->data['assignments'][$key])) {
                $this->data['assignments'][$key] = $this->seedAssignmentsForTenant($key);
                $changed = true;
            }
            if (!isset($this->data['attachments'][$key]) || !is_array($this->data['attachments'][$key])) {
                $this->data['attachments'][$key] = $this->seedAttachmentsForTenant($key);
                $changed = true;
            }
            if (!isset($this->data['settings']['tenant'][$key]) || !is_array($this->data['settings']['tenant'][$key])) {
                $this->data['settings']['tenant'][$key] = $this->defaultTenantSettings($tenant);
                $changed = true;
            }
            if (!isset($this->data['import_export_logs'][$key]) || !is_array($this->data['import_export_logs'][$key])) {
                $this->data['import_export_logs'][$key] = [];
                $changed = true;
            }
            if (!isset($this->data['purchase_status_events']) || !is_array($this->data['purchase_status_events'])) {
                $this->data['purchase_status_events'] = [];
                $changed = true;
            }
            if (!isset($this->data['purchase_status_events'][$key]) || !is_array($this->data['purchase_status_events'][$key])) {
                $this->data['purchase_status_events'][$key] = [];
                $changed = true;
            }
            if (!isset($this->data['mail']) || !is_array($this->data['mail'])) {
                $this->data['mail'] = [];
                $changed = true;
            }
            foreach (['accounts', 'folders', 'messages', 'replies', 'rules'] as $bucket) {
                if (!isset($this->data['mail'][$bucket]) || !is_array($this->data['mail'][$bucket])) {
                    $this->data['mail'][$bucket] = [];
                    $changed = true;
                }
                if (!isset($this->data['mail'][$bucket][$key]) || !is_array($this->data['mail'][$bucket][$key])) {
                    $this->data['mail'][$bucket][$key] = [];
                    $changed = true;
                }
            }
            if (!isset($this->data['mail']['settings']) || !is_array($this->data['mail']['settings'])) {
                $this->data['mail']['settings'] = [];
                $changed = true;
            }
            if (!isset($this->data['mail']['settings'][$key]) || !is_array($this->data['mail']['settings'][$key])) {
                $this->data['mail']['settings'][$key] = [
                    'autosync_enabled' => 1,
                    'autosync_interval_sec' => 180,
                ];
                $changed = true;
            }
        }

        if ($changed) {
            $this->save();
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function tenantsFromData(array $data): array
    {
        return is_array($data['tenants'] ?? null) ? $data['tenants'] : [];
    }

    /** @param array<int, array<string, mixed>> $orders */
    private function hydrateLegacyOrderFields(array &$orders): bool
    {
        $changed = false;
        foreach ($orders as &$order) {
            if (!is_array($order)) {
                continue;
            }

            $orderDefaults = [
                'order_detail_id' => '',
                'imported_at' => (string) ($order['order_date'] ?? ''),
                'pay_method' => '',
                'ship_method' => '',
                'total_item_price' => (float) ($order['total'] ?? 0),
                'postage_price' => 0,
                'pay_charge' => 0,
                'review_invited' => false,
                'reviewed' => false,
            ];
            foreach ($orderDefaults as $field => $value) {
                if (!array_key_exists($field, $order)) {
                    $order[$field] = $value;
                    $changed = true;
                }
            }

            if (!isset($order['customer']) || !is_array($order['customer'])) {
                $order['customer'] = [];
                $changed = true;
            }
            if (!array_key_exists('kana', $order['customer'])) {
                $order['customer']['kana'] = '';
                $changed = true;
            }

            if (!isset($order['items']) || !is_array($order['items'])) {
                continue;
            }

            foreach ($order['items'] as &$item) {
                if (!is_array($item)) {
                    continue;
                }

                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $unitPrice = (float) ($item['unit_price'] ?? ($item['amount'] ?? 0));
                $lineTotal = (float) ($item['line_total'] ?? ($unitPrice * $quantity));
                $itemDefaults = [
                    'order_detail_id' => '',
                    'line_id' => '',
                    'lot_number' => '',
                    'item_management_id' => '',
                    'chinese_option' => '',
                    'unit_price' => $unitPrice,
                    'postage_price' => 0,
                    'pay_charge' => 0,
                    'line_total' => $lineTotal,
                    'purchase_amount' => (float) ($item['amount'] ?? 0),
                    'cn_amount' => 0,
                    'com_amount' => 0,
                    'buhuo_link' => '',
                    'caigou_ordernums' => '',
                    'material' => '',
                    'weight' => 0,
                    'comment' => '',
                    'ship_quantity' => 0,
                    'logistic_trace' => '',
                    'jpship_completed_at' => '',
                    'out_time' => '',
                    'location' => '',
                    'out_no' => '',
                    'out_cost' => 0,
                    'intl_number' => '',
                    'intl_status' => '',
                    'intl_fee' => 0,
                    'intl_qty' => 0,
                    'intl_weight' => 0,
                    'tranship_comment' => '',
                    'intl_comment' => '',
                ];
                foreach ($itemDefaults as $field => $value) {
                    if (!array_key_exists($field, $item)) {
                        $item[$field] = $value;
                        $changed = true;
                    }
                }
            }
            unset($item);
        }
        unset($order);

        return $changed;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function nextId(array $rows): int
    {
        $ids = array_map(fn (array $row): int => (int) ($row['id'] ?? 0), $rows);
        return ($ids ? max($ids) : 0) + 1;
    }

    /** @param array<string, mixed> $message */
    private function mailHydrateMessage(string $tenantKey, array $message): array
    {
        $account = $this->mailAccount($tenantKey, (int) ($message['account_id'] ?? 0)) ?? [];
        $folder = $this->mailFolder($tenantKey, (int) ($message['folder_id'] ?? 0)) ?? [];

        $message['account_shop_name'] = (string) ($account['shop_name'] ?? '');
        $message['account_shop_dpqz'] = (string) ($account['shop_dpqz'] ?? '');
        $message['account_email'] = (string) ($account['imap_user'] ?? '');
        $message['account_platform'] = (string) ($account['platform'] ?? '');
        $message['sent_folder'] = (string) ($account['sent_folder'] ?? 'Sent');
        $message['folder_name'] = (string) (($folder['display_name'] ?? '') ?: ($folder['imap_path'] ?? ''));
        $message['folder_path'] = (string) ($folder['imap_path'] ?? '');
        $message['is_sent'] = $this->mailIsSentFolder($account, $folder);
        $message['attachments'] = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];

        return $message;
    }

    /** @param array<string, mixed> $account @param array<string, mixed> $folder */
    private function mailIsSentFolder(array $account, array $folder): bool
    {
        $sent = trim((string) ($account['sent_folder'] ?? 'Sent'));
        if ($sent === '') {
            return false;
        }
        return in_array($sent, [
            trim((string) ($folder['imap_path'] ?? '')),
            trim((string) ($folder['display_name'] ?? '')),
        ], true);
    }

    private function mailFolderLeaf(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $segments = preg_split('/[\.\/]/', $path);
        if (!is_array($segments) || !$segments) {
            return $path;
        }
        $leaf = end($segments);
        return is_string($leaf) && $leaf !== '' ? $leaf : $path;
    }

    /** @return array<int, array<string, mixed>> */
    private function seedAdmins(): array
    {
        return [
            [
                'id' => 1,
                'username' => 'superadmin',
                'password_hash' => $this->hashPassword($this->defaultAdminPassword()),
                'display_name' => '超级管理员',
                'status' => 'active',
                'created_at' => '2026-06-18 09:00:00',
                'last_login_at' => '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<int, array<string, mixed>>
     */
    private function seedStoresForTenant(array $tenant, array $platformRows = []): array
    {
        $platformNames = [];
        foreach ($platformRows as $platform) {
            $platformNames[$platform['code']] = $platform['name'];
        }

        $stores = [];
        $index = 1;
        foreach ($tenant['platforms'] ?? [] as $auth) {
            if (!($auth['enabled'] ?? false)) {
                continue;
            }

            $code = (string) ($auth['code'] ?? '');
            $stores[] = [
                'id' => $index,
                'legacy_dpid' => (string) $index,
                'platform' => $code,
                'short' => strtoupper($code) . '-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'name' => (($tenant['short_name'] ?? $tenant['company_name'] ?? '租户') . ' ' . ($platformNames[$code] ?? $code) . ' 店'),
                'status' => ($auth['locked'] ?? false) ? 'hidden' : 'visible',
                'api_status' => ($auth['locked'] ?? false) ? '平台锁定' : '待配置',
                'api_config' => '',
                'profit_deduction' => 70,
                'hidden_reason' => ($auth['locked'] ?? false) ? '平台锁定' : '',
                'created_by' => '系统初始化',
                'created_at' => '2026-06-17 09:00',
            ];
            $index++;
        }

        return $stores;
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<int, array<string, mixed>>
     */
    private function seedUsersForTenant(array $tenant): array
    {
        return [
            [
                'id' => 1,
                'name' => (string) (($tenant['contact'] ?? '') ?: '公司管理员'),
                'username' => 'admin-' . ($tenant['key'] ?? 'tenant'),
                'role' => '公司管理员',
                'password_hash' => $this->hashPassword($this->defaultTenantPassword()),
                'legacy_password' => '',
                'password_reset' => '',
                'password_reset_at' => '',
                'last_login_at' => '',
                'preference_module' => 'dashboard',
                'api_1688_config' => '',
                'is_company_admin' => true,
                'permissions' => $this->permissionsForRole('公司管理员'),
                'stores' => ['全部店铺'],
                'status' => 'active',
                'created_at' => '2026-06-17 09:00',
            ],
            [
                'id' => 2,
                'name' => '采购一号',
                'username' => 'buyer-' . ($tenant['key'] ?? 'tenant'),
                'role' => '采购',
                'password_hash' => $this->hashPassword($this->defaultTenantPassword()),
                'legacy_password' => '',
                'password_reset' => '',
                'password_reset_at' => '',
                'last_login_at' => '',
                'preference_module' => 'purchase',
                'api_1688_config' => '',
                'is_company_admin' => false,
                'permissions' => $this->permissionsForRole('采购'),
                'stores' => ['全部店铺'],
                'status' => 'active',
                'created_at' => '2026-06-17 09:00',
            ],
            [
                'id' => 3,
                'name' => '客服一号',
                'username' => 'support-' . ($tenant['key'] ?? 'tenant'),
                'role' => '客服',
                'password_hash' => $this->hashPassword($this->defaultTenantPassword()),
                'legacy_password' => '',
                'password_reset' => '',
                'password_reset_at' => '',
                'last_login_at' => '',
                'preference_module' => 'platform',
                'api_1688_config' => '',
                'is_company_admin' => false,
                'permissions' => $this->permissionsForRole('客服'),
                'stores' => ['全部店铺'],
                'status' => 'active',
                'created_at' => '2026-06-17 09:00',
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function seedAssignmentsForTenant(string $tenantKey): array
    {
        $users = $this->users($tenantKey);
        $buyer = null;
        $support = null;
        foreach ($users as $user) {
            if (Permission::normalizeRole($user['role'] ?? '') === '采购' && $buyer === null) {
                $buyer = $user;
            }
            if (($user['role'] ?? '') === '客服' && $support === null) {
                $support = $user;
            }
        }

        if (!$buyer || !$support) {
            return [];
        }

        return [$this->assignmentRow($tenantKey, (int) $buyer['id'], (int) $support['id'])];
    }

    /** @return array<int, array<string, mixed>> */
    private function seedAttachmentsForTenant(string $tenantKey): array
    {
        $attachments = [];
        foreach (array_slice($this->orders($tenantKey), 0, 2) as $order) {
            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }
            $attachments[$orderId] = [
                [
                    'id' => 1,
                    'order_id' => $orderId,
                    'order_item_id' => (int) (($order['items'][0]['id'] ?? 0)),
                    'type' => '订单图片',
                    'title' => '旧系统 ph_img 多图迁移占位',
                    'path' => "storage/tenants/{$tenantKey}/images/uploads/{$orderId}/legacy-1.jpg",
                    'source' => '旧系统 ph_img',
                    'uploaded_by' => '系统迁移',
                    'size' => '待扫描',
                    'created_at' => '2026-06-17 09:00',
                ],
            ];
        }

        return $attachments;
    }

    /** @return array<string, mixed> */
    private function assignmentRow(string $tenantKey, int $buyerUserId, int $supportUserId): array
    {
        $buyer = $this->user($tenantKey, $buyerUserId) ?? [];
        $support = $this->user($tenantKey, $supportUserId) ?? [];

        return [
            'id' => $buyerUserId . '-' . $supportUserId,
            'buyer_user_id' => $buyerUserId,
            'buyer_name' => (string) ($buyer['name'] ?? $buyer['username'] ?? ''),
            'buyer_role' => (string) ($buyer['role'] ?? ''),
            'support_user_id' => $supportUserId,
            'support_name' => (string) ($support['name'] ?? $support['username'] ?? ''),
            'support_username' => (string) ($support['username'] ?? ''),
            'support_stores' => $support['stores'] ?? [],
            'created_at' => date('Y-m-d H:i'),
        ];
    }

    private function normalizePercent(mixed $value): float
    {
        $number = is_numeric($value) ? (float) $value : 70.0;
        return max(0.0, min(100.0, round($number, 2)));
    }

    /** @return array<string, mixed> */
    private function storeApiConfig(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $config = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '') {
                $config[$key] = trim($value);
            }
        }

        return $config;
    }

    private function shortText(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }

    /** @param array<int, array<string, mixed>> $orders */
    private function nextJsonItemId(array $orders): int
    {
        $max = 0;
        foreach ($orders as $order) {
            foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
                $max = max($max, (int) ($item['id'] ?? 0));
            }
        }

        return $max + 1;
    }

    /** @param array<int, array<string, mixed>> $orders */
    private function findJsonPlatformOrderIndex(array $orders, string $platform, int $storeId, string $platformOrderId): int
    {
        foreach ($orders as $index => $order) {
            if ((string) ($order['platform'] ?? '') !== $platform) {
                continue;
            }
            if ((string) ($order['platform_order_id'] ?? '') !== $platformOrderId) {
                continue;
            }
            if ((int) ($order['store_id'] ?? 0) !== $storeId && (int) ($order['store_id'] ?? 0) > 0) {
                continue;
            }

            return (int) $index;
        }

        return -1;
    }

    private function defaultJsonStoreId(string $tenantKey, string $platform): int
    {
        foreach ($this->stores($tenantKey) as $store) {
            if ((string) ($store['platform'] ?? '') === $platform && ($store['status'] ?? 'visible') !== 'hidden') {
                return (int) ($store['id'] ?? 0);
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $store */
    private function jsonPlatformOrder(array $order, array $store, int $id): array
    {
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];

        return [
            'id' => $id,
            'platform' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($order['platform'] ?? '')) ?: '',
            'platform_order_id' => trim((string) ($order['platform_order_id'] ?? '')),
            'order_detail_id' => trim((string) ($order['order_detail_id'] ?? '')),
            'store_id' => (int) ($order['store_id'] ?? 0),
            'store' => (string) (($store['name'] ?? '') ?: ($order['store'] ?? '')),
            'order_date' => $this->jsonDateTime($order['order_date'] ?? null),
            'imported_at' => date('Y-m-d H:i:s'),
            'status' => trim((string) ($order['status'] ?? $order['order_status'] ?? '')),
            'customer' => [
                'name' => trim((string) ($customer['name'] ?? '')),
                'kana' => trim((string) ($customer['kana'] ?? '')),
                'phone' => trim((string) ($customer['phone'] ?? '')),
                'zip' => trim((string) ($customer['zip'] ?? '')),
                'address' => trim((string) ($customer['address'] ?? '')),
                'mail' => trim((string) ($customer['mail'] ?? '')),
            ],
            'pay_method' => trim((string) ($order['pay_method'] ?? '')),
            'ship_method' => trim((string) ($order['ship_method'] ?? '')),
            'total_item_price' => $this->jsonMoney($order['total_item_price'] ?? 0),
            'postage_price' => $this->jsonMoney($order['postage_price'] ?? 0),
            'pay_charge' => $this->jsonMoney($order['pay_charge'] ?? 0),
            'total' => $this->jsonMoney($order['total'] ?? $order['total_price'] ?? 0),
            'review_invited' => !empty($order['review_invited']),
            'reviewed' => !empty($order['reviewed']),
            'platform_extra' => is_array($order['platform_extra'] ?? null) ? $order['platform_extra'] : [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $existing
     * @param array<int, array<string, mixed>> $incoming
     * @param array{inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int} $result
     * @return array<int, array<string, mixed>>
     */
    private function mergeJsonItems(array $existing, array $incoming, int &$nextItemId, int $orderId, string $operator, array &$result): array
    {
        foreach ($incoming as $index => $item) {
            $normalized = $this->jsonPlatformItem($item, $nextItemId, $orderId, $index, $operator);
            $itemIndex = $this->findJsonItemIndex($existing, $normalized);
            if ($itemIndex >= 0) {
                $normalized['id'] = (int) ($existing[$itemIndex]['id'] ?? $nextItemId);
                $normalized['logs'] = is_array($existing[$itemIndex]['logs'] ?? null) ? $existing[$itemIndex]['logs'] : [];
                $existing[$itemIndex] = array_replace($existing[$itemIndex], $normalized);
                $result['items_updated']++;
            } else {
                $existing[] = $normalized;
                $nextItemId++;
                $result['items_inserted']++;
            }
        }

        return $existing;
    }

    /** @param array<string, mixed> $item */
    private function jsonPlatformItem(array $item, int $id, int $orderId, int $index, string $operator): array
    {
        $quantity = max(0, (int) ($item['quantity'] ?? 0));
        $unitPrice = $this->jsonMoney($item['unit_price'] ?? 0);
        $postage = $this->jsonMoney($item['postage_price'] ?? 0);
        $payCharge = $this->jsonMoney($item['pay_charge'] ?? 0);
        $lineTotal = $this->jsonMoney($item['line_total'] ?? 0);
        if ($lineTotal <= 0 && $unitPrice > 0) {
            $lineTotal = ($unitPrice * max(1, $quantity)) + $postage + $payCharge;
        }
        $image = trim((string) ($item['image'] ?? $item['main_image'] ?? ''));

        return [
            'id' => $id,
            'order_detail_id' => trim((string) ($item['order_detail_id'] ?? '')),
            'line_id' => trim((string) (($item['line_id'] ?? '') !== '' ? $item['line_id'] : (string) ($index + 1))),
            'item_code' => trim((string) ($item['item_code'] ?? '')),
            'lot_number' => trim((string) ($item['lot_number'] ?? '')),
            'item_management_id' => trim((string) ($item['item_management_id'] ?? '')),
            'jp_warehouse_id' => trim((string) ($item['jp_warehouse_id'] ?? '')),
            'title' => trim((string) ($item['title'] ?? $item['product_title'] ?? '')),
            'option' => trim((string) ($item['option'] ?? $item['item_option'] ?? '')),
            'chinese_option' => trim((string) ($item['chinese_option'] ?? '')),
            'quantity' => $quantity,
            'source_type' => in_array(($item['source_type'] ?? 'pending'), ['cn_purchase', 'jp_stock', 'pending'], true) ? (string) $item['source_type'] : 'pending',
            'purchase_status' => trim((string) ($item['purchase_status'] ?? '未处理的订单')),
            'buyer' => trim((string) ($item['buyer'] ?? '')),
            'purchase_time' => trim((string) ($item['purchase_time'] ?? '')),
            'purchase_link' => trim((string) ($item['purchase_link'] ?? '')),
            'buhuo_link' => trim((string) ($item['buhuo_link'] ?? '')),
            'amount' => $this->jsonMoney($item['amount'] ?? 0),
            'purchase_amount' => $this->jsonMoney($item['purchase_amount'] ?? $item['amount'] ?? 0),
            'cn_amount' => $this->jsonMoney($item['cn_amount'] ?? 0),
            'com_amount' => $this->jsonMoney($item['com_amount'] ?? 0),
            'caigou_ordernums' => trim((string) ($item['caigou_ordernums'] ?? '')),
            'unit_price' => $unitPrice,
            'postage_price' => $postage,
            'pay_charge' => $payCharge,
            'line_total' => $lineTotal,
            'material' => trim((string) ($item['material'] ?? '')),
            'weight' => $this->jsonMoney($item['weight'] ?? 0),
            'comment' => trim((string) ($item['comment'] ?? $item['item_comment'] ?? '')),
            'tabaono' => trim((string) ($item['tabaono'] ?? '')),
            'ship_company' => trim((string) ($item['ship_company'] ?? '')),
            'ship_number' => trim((string) ($item['ship_number'] ?? '')),
            'ship_quantity' => (int) ($item['ship_quantity'] ?? 0),
            'logistics' => trim((string) ($item['logistics'] ?? '')),
            'logistic_trace' => trim((string) ($item['logistic_trace'] ?? '')),
            'jpship_completed_at' => trim((string) ($item['jpship_completed_at'] ?? '')),
            'assignee' => $item['assignee'] ?? null,
            'out_status' => $item['out_status'] ?? null,
            'out_time' => trim((string) ($item['out_time'] ?? '')),
            'location' => trim((string) ($item['location'] ?? '')),
            'out_no' => trim((string) ($item['out_no'] ?? '')),
            'out_cost' => $this->jsonMoney($item['out_cost'] ?? 0),
            'intl_number' => trim((string) ($item['intl_number'] ?? '')),
            'intl_status' => trim((string) ($item['intl_status'] ?? '')),
            'intl_fee' => $this->jsonMoney($item['intl_fee'] ?? 0),
            'intl_qty' => (int) ($item['intl_qty'] ?? 0),
            'intl_weight' => $this->jsonMoney($item['intl_weight'] ?? 0),
            'tranship_comment' => trim((string) ($item['tranship_comment'] ?? '')),
            'intl_comment' => trim((string) ($item['intl_comment'] ?? '')),
            'image' => $image !== '' ? $image : '/assets/no-image.svg',
            'platform_extra' => is_array($item['platform_extra'] ?? null) ? $item['platform_extra'] : [],
            'logs' => [
                ['time' => date('m-d H:i'), 'user' => $operator, 'action' => '平台API导入', 'field' => 'source', 'old' => '-', 'new' => 'Rakuten RMS', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 'order_id' => $orderId],
            ],
        ];
    }

    /** @param array<int, array<string, mixed>> $items @param array<string, mixed> $needle */
    private function findJsonItemIndex(array $items, array $needle): int
    {
        foreach ($items as $index => $item) {
            foreach (['order_detail_id', 'line_id'] as $key) {
                if (($needle[$key] ?? '') !== '' && (string) ($item[$key] ?? '') === (string) $needle[$key]) {
                    return (int) $index;
                }
            }
            if ((string) ($item['item_code'] ?? '') === (string) ($needle['item_code'] ?? '')
                && (string) ($item['option'] ?? '') === (string) ($needle['option'] ?? '')) {
                return (int) $index;
            }
        }

        return -1;
    }

    private function jsonMoney(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $normalized = preg_replace('/[^\d.\-]+/', '', str_replace(',', '', (string) ($value ?? '')));
        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function jsonDateTime(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        return $raw !== '' && strtotime($raw) !== false ? date('Y-m-d H:i:s', strtotime($raw)) : '';
    }

    /** @return array<string, mixed> */
    private function defaultGlobalSettings(): array
    {
        return [
            'logistics_mapping' => [
                'yahoo' => '',
                'rakuten' => '',
                'wowma' => '',
                'jp_carrier' => '',
                'tracking_query' => '',
            ],
            'showapi' => [
                'app_id' => '',
                'sign' => '',
                'baidu_enabled' => false,
                'enabled' => false,
            ],
            'proxy' => [
                'rotation_proxy' => '',
                'enabled' => false,
            ],
            'updated_at' => '',
        ];
    }

    /** @param array<string, mixed> $data */
    private function normalizeGlobalSettingsInput(array $data): array
    {
        $mapping = is_array($data['logistics_mapping'] ?? null) ? $data['logistics_mapping'] : [];
        $showapi = is_array($data['showapi'] ?? null) ? $data['showapi'] : [];
        $proxy = is_array($data['proxy'] ?? null) ? $data['proxy'] : [];

        return [
            'logistics_mapping' => [
                'yahoo' => trim((string) ($mapping['yahoo'] ?? '')),
                'rakuten' => trim((string) ($mapping['rakuten'] ?? '')),
                'wowma' => trim((string) ($mapping['wowma'] ?? '')),
                'jp_carrier' => trim((string) ($mapping['jp_carrier'] ?? '')),
                'tracking_query' => trim((string) ($mapping['tracking_query'] ?? '')),
            ],
            'showapi' => [
                'app_id' => trim((string) ($showapi['app_id'] ?? '')),
                'sign' => trim((string) ($showapi['sign'] ?? '')),
                'baidu_enabled' => !empty($showapi['baidu_enabled']),
                'enabled' => !empty($showapi['enabled']),
            ],
            'proxy' => [
                'rotation_proxy' => trim((string) ($proxy['rotation_proxy'] ?? '')),
                'enabled' => !empty($proxy['enabled']),
            ],
        ];
    }

    /** @param array<string, mixed> $tenant */
    private function defaultTenantSettings(array $tenant): array
    {
        return [
            'company' => [
                'company_name' => (string) ($tenant['company_name'] ?? ''),
                'short_name' => (string) ($tenant['short_name'] ?? ''),
                'contact' => (string) ($tenant['contact'] ?? ''),
                'phone' => (string) ($tenant['phone'] ?? ''),
                'address' => (string) ($tenant['address'] ?? ''),
                'note' => (string) ($tenant['remark'] ?? ''),
            ],
            'orders' => [
                'default_page_size' => 200,
                'default_query_days' => 30,
                'archive_days' => 180,
                'price_warning_index' => 0,
                'platform_sync_default_days' => 7,
            ],
            'profit' => [
                'exchange_rate' => 0.046,
                'exchange_rate_mode' => 'fixed',
                'fixed_exchange_rate' => 0.046,
                'default_intl_fee' => 820,
                'platform_deductions' => [
                    'y' => 70,
                    'r' => 70,
                    'w' => 70,
                    'm' => 70,
                    'q' => 70,
                    'yp' => 70,
                ],
                'store_deduction_enabled' => true,
                'excluded_purchase_statuses' => ['已取消', '客人取消订单'],
            ],
            'logistics' => [
                'domestic_receive_places' => '',
                'carrier_mapping' => '',
                'tracking_prefix_mapping' => '',
            ],
            'notices' => [
                'items' => [],
            ],
            'api_1688' => [
                'enabled' => false,
                'config_file' => 'storage/tenants/' . $this->tenantStorageKey((string) ($tenant['key'] ?? '')) . '/config/1688/apikeys.conf',
                'config_content' => '',
            ],
            'jp_stock_purchase_statuses' => [],
            'updated_at' => '',
        ];
    }

    private function tenantStorageKey(string $tenantKey): string
    {
        $tenantKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantKey) ?? '';
        return $tenantKey !== '' ? $tenantKey : 'erp';
    }

    private function defaultAdminPassword(): string
    {
        return 'Admin@2026';
    }

    private function defaultTenantPassword(): string
    {
        return 'Tenant@2026';
    }

    private function hashPassword(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algorithm);
    }

    /**
     * @param mixed $overrides
     * @return array<int, string>
     */
    private function permissionsForRole(string $role, mixed $overrides = []): array
    {
        $defaults = Permission::roleDefaults();

        $permissions = $defaults[Permission::normalizeRole($role)] ?? $defaults['客服'];
        $extra = array_values(array_filter(array_map('trim', (array) $overrides)));
        return array_values(array_unique(array_merge($permissions, $extra)));
    }

    /**
     * @param array{allow?: array<int, string>, deny?: array<int, string>} $overrides
     * @return array{allow: array<int, string>, deny: array<int, string>}
     */
    private function normalizePermissionOverrides(array $overrides): array
    {
        return [
            'allow' => array_values(array_unique(array_filter(array_map('trim', (array) ($overrides['allow'] ?? []))))),
            'deny' => array_values(array_unique(array_filter(array_map('trim', (array) ($overrides['deny'] ?? []))))),
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $changes
     */
    private function applyItemChanges(array &$item, array $changes, int $orderId, string $action = '批量更新', string $operator = '系统管理员'): void
    {
        $allowed = [
            'source_type',
            'purchase_status',
            'buyer',
            'purchase_time',
            'purchase_link',
            'buhuo_link',
            'amount',
            'cn_amount',
            'com_amount',
            'tabaono',
            'caigou_ordernums',
            'ship_company',
            'ship_number',
            'ship_quantity',
            'receipt_city',
            'logistics',
            'logistic_trace',
            'material',
            'weight',
            'chinese_option',
            'comment',
            'assignee',
            'out_status',
            'jp_warehouse_id',
            'intl_number',
            'intl_status',
            'intl_fee',
            'intl_qty',
            'intl_weight',
            'tranship_comment',
            'intl_comment',
        ];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }

            $newValue = is_string($changes[$field]) ? trim($changes[$field]) : $changes[$field];
            if ($field === 'source_type' && !in_array($newValue, ['cn_purchase', 'jp_stock', 'pending'], true)) {
                continue;
            }
            if ($field === 'out_status' && !in_array($newValue, ['待分配', '已分配', '已出库', '已发货'], true)) {
                continue;
            }
            if (in_array($field, ['amount', 'cn_amount', 'com_amount', 'intl_fee', 'intl_weight', 'weight'], true)) {
                $newValue = (float) $newValue;
            }
            if (in_array($field, ['ship_quantity', 'intl_qty'], true)) {
                $newValue = (int) $newValue;
            }
            if ($field === 'purchase_status') {
                $newValue = $this->normalizePurchaseStatus((string) $newValue);
            }

            $oldValue = $item[$field] ?? '';
            if ((string) $oldValue === (string) $newValue) {
                continue;
            }

            $item[$field] = $newValue;
            $statusLog = null;
            if ($field === 'source_type') {
                $statusOldValue = (string) ($item['purchase_status'] ?? '');
                $nextStatus = array_key_exists('purchase_status', $changes)
                    ? $this->normalizePurchaseStatus((string) $changes['purchase_status'])
                    : match ($newValue) {
                    'cn_purchase' => '国内采购-准备',
                    'jp_stock' => '日本库存订单',
                    default => '待处理',
                };
                if ($statusOldValue !== $nextStatus) {
                    $item['purchase_status'] = $nextStatus;
                    $statusLog = [
                        'time' => date('m-d H:i'),
                        'user' => $operator,
                        'action' => $action,
                        'field' => 'purchase_status',
                        'old' => $statusOldValue,
                        'new' => $nextStatus,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        'order_id' => $orderId,
                    ];
                }
            }

            $item['logs'][] = [
                'time' => date('m-d H:i'),
                'user' => $operator,
                'action' => $action,
                'field' => $field,
                'old' => (string) $oldValue,
                'new' => (string) $newValue,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'order_id' => $orderId,
            ];
            if ($statusLog !== null) {
                $item['logs'][] = $statusLog;
            }
        }
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function recordJsonPurchaseStatusEvent(array &$data, string $tenantKey, array $order, array $item, string $oldStatus, string $newStatus, string $source, string $operator): void
    {
        $actionType = self::PURCHASE_EVENT_STATUSES[$newStatus] ?? null;
        if ($actionType === null || $oldStatus === $newStatus) {
            return;
        }

        if (!isset($data['purchase_status_events']) || !is_array($data['purchase_status_events'])) {
            $data['purchase_status_events'] = [];
        }
        if (!isset($data['purchase_status_events'][$tenantKey]) || !is_array($data['purchase_status_events'][$tenantKey])) {
            $data['purchase_status_events'][$tenantKey] = [];
        }

        $now = date('Y-m-d H:i:s');
        $data['purchase_status_events'][$tenantKey][] = [
            'id' => $this->nextId($data['purchase_status_events'][$tenantKey]),
            'platform' => (string) ($order['platform'] ?? ''),
            'order_id' => (int) ($order['id'] ?? 0),
            'order_item_id' => (int) ($item['id'] ?? 0),
            'platform_order_id' => (string) ($order['platform_order_id'] ?? ''),
            'item_code' => (string) (($item['item_code'] ?? '') ?: ($item['lot_number'] ?? '')),
            'store_name' => (string) ($order['store'] ?? ''),
            'operator' => $operator,
            'user_type' => '',
            'buyer' => (string) ($item['buyer'] ?? ''),
            'action_type' => $actionType,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'source' => $source,
            'tabaono' => (string) ($item['tabaono'] ?? ''),
            'cn_amount' => (float) ($item['cn_amount'] ?? $item['amount'] ?? 0),
            'caigou_time' => (string) ($item['purchase_time'] ?? ''),
            'created_at' => $now,
            'created_date' => substr($now, 0, 10),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function normalizePurchaseStatusEventRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'platform' => (string) ($row['platform'] ?? ''),
            'order_id' => (int) ($row['order_id'] ?? 0),
            'order_item_id' => (int) ($row['order_item_id'] ?? 0),
            'platform_order_id' => (string) ($row['platform_order_id'] ?? ''),
            'item_code' => (string) ($row['item_code'] ?? ''),
            'store_name' => (string) ($row['store_name'] ?? ''),
            'operator' => (string) ($row['operator'] ?? ''),
            'user_type' => (string) ($row['user_type'] ?? ''),
            'buyer' => (string) ($row['buyer'] ?? ''),
            'action_type' => (string) ($row['action_type'] ?? ''),
            'old_status' => (string) ($row['old_status'] ?? ''),
            'new_status' => (string) ($row['new_status'] ?? ''),
            'source' => (string) ($row['source'] ?? ''),
            'tabaono' => (string) ($row['tabaono'] ?? ''),
            'cn_amount' => (float) ($row['cn_amount'] ?? 0),
            'caigou_time' => (string) ($row['caigou_time'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_date' => (string) ($row['created_date'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $row @param array<string, mixed>|null $user */
    private function eventVisibleToUser(array $row, ?array $user): bool
    {
        return $user === null || Permission::canAccessStore($user, (string) ($row['store_name'] ?? ''));
    }

    /** @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>} */
    private function emptyImportReport(): array
    {
        return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];
    }

    /** @param array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>} $report */
    private function importReportMessage(array &$report, int $row, string $message): void
    {
        if (count($report['messages']) >= 30) {
            return;
        }

        $report['messages'][] = $row > 0 ? "第 {$row} 行：{$message}" : $message;
    }

    /** @param array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>} $report */
    private function importReportFail(array &$report, int $row, string $message): void
    {
        $report['failed']++;
        $this->importReportMessage($report, $row, $message);
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $incoming @return array<string, mixed> */
    private function mergeJsonImportOrder(array $base, array $incoming): array
    {
        foreach ($incoming as $field => $value) {
            if ($field === 'items') {
                continue;
            }
            if ($value === '' || $value === 0 || $value === 0.0 || $value === null) {
                continue;
            }
            if ($field === 'customer' && is_array($value)) {
                $base['customer'] = array_replace(is_array($base['customer'] ?? null) ? $base['customer'] : [], $value);
                continue;
            }
            if ($field === 'extra' && is_array($value)) {
                $base['platform_extra'] = array_replace(is_array($base['platform_extra'] ?? null) ? $base['platform_extra'] : [], $value);
                continue;
            }
            $base[$field] = $value;
        }

        return $base;
    }

    /** @param array<int, array<string, mixed>> $orders @param array<string, mixed> $identity @return array{0: int, 1: int}|null */
    private function findJsonTargetItem(array $orders, array $identity): ?array
    {
        $targets = $this->findJsonTargetItems($orders, $identity);
        return $targets[0] ?? null;
    }

    /** @param array<int, array<string, mixed>> $orders @param array<string, mixed> $identity @return array<int, array{0: int, 1: int}> */
    private function findJsonTargetItems(array $orders, array $identity): array
    {
        $platform = trim((string) ($identity['platform'] ?? ''));
        $orderId = trim((string) ($identity['platform_order_id'] ?? ''));
        $orderDetailId = trim((string) ($identity['order_detail_id'] ?? ''));
        $lineId = trim((string) ($identity['line_id'] ?? ''));
        $itemCode = trim((string) ($identity['item_code'] ?? ''));
        $targets = [];

        foreach ($orders as $orderIndex => $order) {
            if ($orderId !== '' && (string) ($order['platform_order_id'] ?? '') !== $orderId) {
                continue;
            }
            if ($platform !== '' && (string) ($order['platform'] ?? '') !== $platform) {
                continue;
            }

            foreach ((array) ($order['items'] ?? []) as $itemIndex => $item) {
                if ($orderDetailId !== '' && (string) ($item['order_detail_id'] ?? '') !== $orderDetailId) {
                    continue;
                }
                if ($lineId !== '' && (string) ($item['line_id'] ?? '') !== $lineId) {
                    continue;
                }
                if ($itemCode !== '' && (string) ($item['item_code'] ?? '') !== $itemCode && (string) ($item['lot_number'] ?? '') !== $itemCode) {
                    continue;
                }
                $targets[] = [$orderIndex, $itemIndex];
            }
        }

        return $targets;
    }

    private function canAdvancePurchaseStatus(string $status): bool
    {
        return in_array($status, ['', '待处理', '未采购', '国内采购-准备'], true);
    }

    private function normalizePurchaseStatus(string $status): string
    {
        return match ($status) {
            '', '未采购' => '国内采购-准备',
            '已采购' => '国内采购-已采购',
            default => $status,
        };
    }

    private function mergeTrackingNumbers(string $current, string $incoming): string
    {
        $incoming = trim($incoming);
        if ($incoming === '') {
            return $current;
        }

        $numbers = array_values(array_filter(array_map('trim', preg_split('/[,，\s]+/u', $current) ?: [])));
        if (!in_array($incoming, $numbers, true)) {
            $numbers[] = $incoming;
        }

        return implode(',', $numbers);
    }

    /** @return array<string, mixed> */
    private function seed(): array
    {
        return [
            'admins' => $this->seedAdmins(),
            'platforms' => [
                ['code' => 'y', 'name' => 'Yahoo 购物', 'short' => 'Yahoo', 'color' => '#ef4444'],
                ['code' => 'r', 'name' => '乐天 Rakuten', 'short' => 'Rakuten', 'color' => '#2563eb'],
                ['code' => 'w', 'name' => 'Wowma', 'short' => 'Wowma', 'color' => '#14b8a6'],
                ['code' => 'm', 'name' => 'Mercari', 'short' => 'Mercari', 'color' => '#06b6d4'],
                ['code' => 'q', 'name' => 'Qoo10', 'short' => 'Qoo10', 'color' => '#8b5cf6'],
                ['code' => 'yp', 'name' => '雅虎拍卖', 'short' => 'Y Auction', 'color' => '#64748b'],
            ],
            'tenants' => [
                [
                    'id' => 1,
                    'key' => 'erp',
                    'company_name' => '西阵电商',
                    'short_name' => '西阵',
                    'subdomain' => 'erp',
                    'db_name' => 'xizhends_tenant_erp',
                    'plan' => 'Pro',
                    'status' => 'active',
                    'staff_count' => 18,
                    'balance' => 6800,
                    'contact' => '陈经理',
                    'phone' => '138-0000-0001',
                    'platforms' => [
                        ['code' => 'y', 'enabled' => true, 'locked' => false],
                        ['code' => 'r', 'enabled' => true, 'locked' => false],
                        ['code' => 'w', 'enabled' => true, 'locked' => false],
                        ['code' => 'm', 'enabled' => true, 'locked' => false],
                        ['code' => 'q', 'enabled' => true, 'locked' => true],
                        ['code' => 'yp', 'enabled' => false, 'locked' => false],
                    ],
                    'features' => TenantFeature::defaultRows(),
                ],
                [
                    'id' => 2,
                    'key' => 'tokyo',
                    'company_name' => '东京仓代运营',
                    'short_name' => '东京仓',
                    'subdomain' => 'tokyo',
                    'db_name' => 'xizhends_tenant_tokyo',
                    'plan' => 'Basic',
                    'status' => 'active',
                    'staff_count' => 7,
                    'balance' => 2100,
                    'contact' => '山田',
                    'phone' => '03-0000-0000',
                    'platforms' => [
                        ['code' => 'y', 'enabled' => true, 'locked' => false],
                        ['code' => 'r', 'enabled' => true, 'locked' => false],
                        ['code' => 'w', 'enabled' => false, 'locked' => false],
                        ['code' => 'm', 'enabled' => true, 'locked' => false],
                        ['code' => 'q', 'enabled' => false, 'locked' => false],
                        ['code' => 'yp', 'enabled' => false, 'locked' => false],
                    ],
                    'features' => TenantFeature::defaultRows(),
                ],
                [
                    'id' => 3,
                    'key' => 'demo',
                    'company_name' => '演示租户',
                    'short_name' => 'Demo',
                    'subdomain' => 'demo',
                    'db_name' => 'xizhends_tenant_demo',
                    'plan' => 'Basic',
                    'status' => 'suspended',
                    'staff_count' => 3,
                    'balance' => 0,
                    'contact' => '测试员',
                    'phone' => '000',
                    'platforms' => [
                        ['code' => 'y', 'enabled' => true, 'locked' => true],
                        ['code' => 'r', 'enabled' => true, 'locked' => true],
                        ['code' => 'w', 'enabled' => false, 'locked' => false],
                        ['code' => 'm', 'enabled' => false, 'locked' => false],
                        ['code' => 'q', 'enabled' => false, 'locked' => false],
                        ['code' => 'yp', 'enabled' => false, 'locked' => false],
                    ],
                    'features' => TenantFeature::defaultRows(),
                ],
            ],
            'announcements' => [
                ['kind' => '维护', 'title' => '5月20日系统升级维护通知', 'scope' => '全部租户', 'date' => '2026-05-14 10:20', 'body' => '系统将于5月20日凌晨 2:00-4:00 进行维护升级，期间暂停服务。'],
                ['kind' => '新功能', 'title' => '批量导入功能已上线', 'scope' => '全部租户', 'date' => '2026-05-10 09:00', 'body' => '支持 CSV 格式批量导入订单，导入后可在平台订单视图进行货源改判。'],
                ['kind' => '通知', 'title' => '乐天 RMS API 鉴权方式变更提醒', 'scope' => '指定租户(1)', 'date' => '2026-05-06 16:30', 'body' => '请相关公司在月底前完成 API 凭证更新。'],
            ],
            'orders' => [
                'erp' => $this->seedOrders(),
                'tokyo' => $this->seedOrders('T'),
                'demo' => [],
            ],
            'stores' => [],
            'users' => [],
            'assignments' => [],
            'attachments' => [],
            'settings' => [
                'global' => $this->defaultGlobalSettings(),
                'tenant' => [],
            ],
            'import_export_logs' => [],
            'purchase_status_events' => [],
            'mail' => [
                'accounts' => [],
                'folders' => [],
                'messages' => [],
                'replies' => [],
                'rules' => [],
                'settings' => [],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function seedOrders(string $prefix = 'R'): array
    {
        return [
            [
                'id' => 1001,
                'platform' => 'r',
                'platform_order_id' => "{$prefix}-202606170041",
                'order_date' => '2026-06-17 09:12',
                'status' => '未处理的订单',
                'store' => '乐天旗舰店',
                'customer' => [
                    'name' => '佐藤 美咲',
                    'phone' => '080-1234-5678',
                    'zip' => '150-0001',
                    'address' => '東京都渋谷区神宮前 1-1-1',
                    'mail' => 'sato@example.jp',
                ],
                'total' => 12800,
                'items' => [
                    $this->item(501, 'NB-996-BK', 'JP-STOCK-8842', 'New Balance 996 黑色', '26.5cm / 黑色', 1, 'cn_purchase', '国内采购-准备', '王五', 'T2026061700912', '运输中'),
                    $this->item(502, 'MUJI-BAG-12', 'JP-A12-5520', '无印良品 单肩包', '米色 / L', 1, 'jp_stock', '日本库存订单', '', '', '日本仓库已处理'),
                ],
            ],
            [
                'id' => 1002,
                'platform' => 'y',
                'platform_order_id' => 'Y-202606170116',
                'order_date' => '2026-06-17 10:28',
                'status' => '国内采购-已采购',
                'store' => 'Yahoo 一号店',
                'customer' => [
                    'name' => '田中 太郎',
                    'phone' => '070-0000-9999',
                    'zip' => '530-0001',
                    'address' => '大阪府大阪市北区梅田 2-2-2',
                    'mail' => 'tanaka@example.jp',
                ],
                'total' => 8200,
                'items' => [
                    $this->item(503, 'ITEM-84002', '', '户外折叠椅', '银色 / 标准款', 2, 'cn_purchase', '国内采购-已采购', '李四', 'T2026061700771', '已发货代订单'),
                ],
            ],
            [
                'id' => 1003,
                'platform' => 'm',
                'platform_order_id' => 'M-202606170221',
                'order_date' => '2026-06-17 11:06',
                'status' => '待分配',
                'store' => 'Mercari 精选',
                'customer' => [
                    'name' => '高桥 翼',
                    'phone' => '090-2222-3333',
                    'zip' => '460-0008',
                    'address' => '愛知県名古屋市中区栄 3-3-3',
                    'mail' => 'takahashi@example.jp',
                ],
                'total' => 19800,
                'items' => [
                    $this->item(504, 'LOT-3321', 'JP-WH-9033', '中古相机镜头', 'Nikon / 状态A', 1, 'jp_stock', '日本库存订单', '', '', '日本仓库已处理'),
                ],
            ],
            [
                'id' => 1004,
                'platform' => 'w',
                'platform_order_id' => 'W-202606170301',
                'order_date' => '2026-06-17 12:40',
                'status' => '待处理',
                'store' => 'Wowma 综合店',
                'customer' => [
                    'name' => '中村 葵',
                    'phone' => '080-5555-1111',
                    'zip' => '810-0001',
                    'address' => '福岡県福岡市中央区天神 4-4-4',
                    'mail' => 'nakamura@example.jp',
                ],
                'total' => 5600,
                'items' => [
                    $this->item(505, 'W-ITEM-901', '', '厨房收纳架', '白色 / 三层', 1, 'pending', '待处理', '', '', ''),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function item(
        int $id,
        string $code,
        string $warehouse,
        string $title,
        string $option,
        int $quantity,
        string $source,
        string $purchaseStatus,
        string $buyer,
        string $tabaono,
        string $logistics
    ): array {
        return [
            'id' => $id,
            'item_code' => $code,
            'jp_warehouse_id' => $warehouse,
            'title' => $title,
            'option' => $option,
            'quantity' => $quantity,
            'source_type' => $source,
            'purchase_status' => $purchaseStatus,
            'buyer' => $buyer,
            'purchase_time' => $buyer ? '2026-06-17 13:20' : '',
            'purchase_link' => $source === 'cn_purchase' ? 'https://detail.1688.com/item/' . $code : '',
            'amount' => 1280,
            'tabaono' => $tabaono,
            'ship_company' => $logistics ? '佐川急便' : '',
            'ship_number' => $logistics ? '1234-5678-9012' : '',
            'logistics' => $logistics,
            'assignee' => $source === 'jp_stock' ? '' : null,
            'out_status' => $source === 'jp_stock' ? '待分配' : null,
            'image' => 'https://picsum.photos/seed/' . $id . '/72/72',
            'logs' => [
                ['time' => '06-17 09:30', 'user' => '系统', 'action' => '导入订单', 'field' => '-', 'old' => '-', 'new' => '来源：平台 API', 'ip' => 'API自动'],
                ['time' => '06-17 09:35', 'user' => '系统', 'action' => '货源判定', 'field' => 'source_type', 'old' => 'pending', 'new' => $source, 'ip' => 'API自动'],
            ],
        ];
    }
}
