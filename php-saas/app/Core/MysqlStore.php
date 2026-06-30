<?php

declare(strict_types=1);

namespace Xizhen\Core;

final class MysqlStore implements StoreInterface
{
    private const STORE_ADD_FEE = 50;
    private const STORE_MONTHLY_FEE = 50;
    private const DEBT_SUSPEND_THRESHOLD = -300;

    private ?\PDO $master = null;

    /** @var array<string, \PDO> */
    private array $tenantConnections = [];

    public function __construct(private readonly Config $config)
    {
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $orders = [];
        foreach ($this->tenants() as $tenant) {
            $orders[$tenant['key']] = $this->orders((string) $tenant['key']);
        }

        return [
            'platforms' => $this->platforms(),
            'tenants' => $this->tenants(),
            'announcements' => $this->announcements(),
            'orders' => $orders,
        ];
    }

    /** @return array<string, mixed>|null */
    public function adminByUsername(string $username): ?array
    {
        $username = trim($username);
        if ($username === '' || !$this->tableExists($this->master(), 'admins')) {
            return null;
        }

        $stmt = $this->master()->prepare('SELECT id, username, password_hash, display_name, status, created_at, last_login_at FROM admins WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function touchAdminLogin(int $adminId): void
    {
        if ($adminId <= 0 || !$this->tableExists($this->master(), 'admins')) {
            return;
        }

        $this->master()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')->execute([$adminId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function tenants(): array
    {
        $master = $this->master();
        $billingSelect = '0 AS billing_balance';
        $billingJoin = '';
        if ($this->tableExists($master, 'tenant_billing_accounts')) {
            $balanceColumn = $this->columnExists($master, 'tenant_billing_accounts', 'balance_points')
                ? 'COALESCE(b.balance_points, 0)'
                : 'FLOOR(COALESCE(b.balance_cents, 0) / 100)';
            $billingSelect = "{$balanceColumn} AS billing_balance";
            $billingJoin = 'LEFT JOIN tenant_billing_accounts b ON b.tenant_id = t.id';
        }

        $sql = <<<SQL
SELECT t.*, {$billingSelect}
FROM tenants t
{$billingJoin}
ORDER BY t.id DESC
SQL;
        $rows = $this->master()->query($sql)->fetchAll();

        return array_map(function (array $row): array {
            $key = (string) ($row['subdomain'] ?? $row['id']);

            return [
                'id' => (int) $row['id'],
                'key' => $key,
                'company_name' => (string) ($row['company_name'] ?? ''),
                'short_name' => (string) (($row['company_short_name'] ?? '') ?: ($row['company_name'] ?? '')),
                'subdomain' => $key,
                'db_name' => (string) ($row['db_dsn_enc'] ?? ''),
                'plan' => (string) ($row['plan'] ?? 'basic'),
                'status' => (string) ($row['status'] ?? 'active'),
                'staff_count' => (int) ($row['staff_count'] ?? 0),
                'balance' => (int) ($row['billing_balance'] ?? 0),
                'contact' => (string) ($row['contact_name'] ?? ''),
                'phone' => (string) ($row['contact_phone'] ?? ''),
                'platforms' => $this->tenantPlatforms($key),
                'features' => $this->tenantFeatures($key),
            ];
        }, $rows);
    }

    /** @return array<string, mixed> */
    public function tenant(string $key): array
    {
        foreach ($this->tenants() as $tenant) {
            if (($tenant['key'] ?? '') === $key) {
                return $tenant;
            }
        }

        return $this->tenants()[0] ?? [
            'key' => $key,
            'company_name' => $key,
            'plan' => 'basic',
            'platforms' => [],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function platforms(): array
    {
        $rows = $this->master()
            ->query('SELECT code, name, sort_order, default_enabled FROM platforms ORDER BY sort_order, code')
            ->fetchAll();

        return array_map(fn (array $row): array => [
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'short' => (string) $row['name'],
            'color' => $this->platformColor((string) $row['code']),
            'default_enabled' => (bool) $row['default_enabled'],
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function orders(string $tenantKey): array
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return [];
        }

        $sql = <<<'SQL'
SELECT o.*, COALESCE(s.dpquancheng, s.dpqz, '') AS store_name
FROM orders o
LEFT JOIN stores s ON s.id = o.store_id
ORDER BY COALESCE(o.order_date, o.imported_at) DESC
LIMIT 200
SQL;
        $rows = $tenantPdo->query($sql)->fetchAll();

        return array_map(fn (array $row): array => $this->mapOrder($tenantPdo, $row), $rows);
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
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $orderId <= 0) {
            return null;
        }

        $stmt = $tenantPdo->prepare(<<<'SQL'
SELECT o.*, COALESCE(s.dpquancheng, s.dpqz, '') AS store_name
FROM orders o
LEFT JOIN stores s ON s.id = o.store_id
WHERE o.id = ?
LIMIT 1
SQL);
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();

        return $row ? $this->mapOrder($tenantPdo, $row) : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function announcements(): array
    {
        $rows = $this->master()
            ->query('SELECT kind, title, scope, content, created_at FROM announcements ORDER BY created_at DESC LIMIT 20')
            ->fetchAll();

        return array_map(fn (array $row): array => [
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'scope' => $row['scope'] === 'global' ? '全部租户' : '指定租户',
            'date' => (string) $row['created_at'],
            'body' => (string) $row['content'],
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantPlatforms(string $tenantKey): array
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null) {
            return [];
        }

        $stmt = $this->master()->prepare(
            'SELECT platform_code AS code, enabled, locked FROM tenant_platform WHERE tenant_id = ? ORDER BY platform_code'
        );
        $stmt->execute([$tenantId]);

        return array_map(fn (array $row): array => [
            'code' => (string) $row['code'],
            'enabled' => (bool) $row['enabled'],
            'locked' => (bool) $row['locked'],
        ], $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantFeatures(string $tenantKey): array
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null || !$this->tableExists($this->master(), 'tenant_features')) {
            return TenantFeature::defaultRows();
        }

        $insert = $this->master()->prepare(
            'INSERT IGNORE INTO tenant_features (tenant_id, feature_key, enabled) VALUES (?, ?, ?)'
        );
        foreach (TenantFeature::defaultMap() as $featureKey => $enabled) {
            $insert->execute([$tenantId, $featureKey, (int) $enabled]);
        }

        $stmt = $this->master()->prepare(
            'SELECT feature_key AS `key`, enabled FROM tenant_features WHERE tenant_id = ? ORDER BY feature_key'
        );
        $stmt->execute([$tenantId]);
        $rows = array_map(fn (array $row): array => [
            'key' => (string) $row['key'],
            'enabled' => (bool) $row['enabled'],
        ], $stmt->fetchAll());

        return TenantFeature::normalizeRows($rows);
    }

    /** @return array<string, mixed> */
    public function tenantBillingAccount(string $tenantKey): array
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null || !$this->tableExists($this->master(), 'tenant_billing_accounts')) {
            return [
                'tenant_key' => $tenantKey,
                'balance' => 0,
                'unit' => 'pt',
                'store_add_fee' => self::STORE_ADD_FEE,
                'store_monthly_fee' => self::STORE_MONTHLY_FEE,
                'debt_suspend_threshold' => self::DEBT_SUSPEND_THRESHOLD,
                'updated_at' => '',
            ];
        }

        $this->ensureBillingAccount($tenantId);
        $balanceColumn = $this->columnExists($this->master(), 'tenant_billing_accounts', 'balance_points')
            ? 'balance_points'
            : 'FLOOR(balance_cents / 100)';
        $stmt = $this->master()->prepare("SELECT {$balanceColumn} AS balance, updated_at FROM tenant_billing_accounts WHERE tenant_id = ? LIMIT 1");
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch() ?: [];

        return [
            'tenant_key' => $tenantKey,
            'balance' => (int) ($row['balance'] ?? 0),
            'unit' => 'pt',
            'store_add_fee' => self::STORE_ADD_FEE,
            'store_monthly_fee' => self::STORE_MONTHLY_FEE,
            'debt_suspend_threshold' => self::DEBT_SUSPEND_THRESHOLD,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantBillingLedger(string $tenantKey, int $limit = 50): array
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null || !$this->tableExists($this->master(), 'tenant_billing_ledger')) {
            return [];
        }

        $amountColumn = $this->columnExists($this->master(), 'tenant_billing_ledger', 'amount_points')
            ? 'l.amount_points'
            : 'FLOOR(l.amount_cents / 100)';
        $balanceColumn = $this->columnExists($this->master(), 'tenant_billing_ledger', 'balance_after_points')
            ? 'l.balance_after_points'
            : 'FLOOR(l.balance_after_cents / 100)';
        $hasOperator = $this->columnExists($this->master(), 'tenant_billing_ledger', 'operator');
        $operatorSelect = $hasOperator ? 'l.operator' : "'' AS operator";
        $limit = max(1, min(200, $limit));
        $stmt = $this->master()->prepare(
            "SELECT l.id, t.subdomain AS tenant_key, t.company_name AS tenant_name, l.entry_type AS type, {$amountColumn} AS amount, {$balanceColumn} AS balance_after, l.note, {$operatorSelect}, l.created_at FROM tenant_billing_ledger l INNER JOIN tenants t ON t.id = l.tenant_id WHERE l.tenant_id = ? ORDER BY l.created_at DESC, l.id DESC LIMIT {$limit}"
        );
        $stmt->execute([$tenantId]);

        return array_map(fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'tenant_key' => (string) ($row['tenant_key'] ?? $tenantKey),
            'tenant_name' => (string) (($row['tenant_name'] ?? '') ?: $tenantKey),
            'type' => (string) ($row['type'] ?? ''),
            'amount' => (int) ($row['amount'] ?? 0),
            'balance_after' => (int) ($row['balance_after'] ?? 0),
            'note' => (string) ($row['note'] ?? ''),
            'operator' => (string) ($row['operator'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ], $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    public function tenantBillingSubscriptions(string $tenantKey): array
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null || !$this->tableExists($this->master(), 'tenant_billing_subscriptions')) {
            return [];
        }

        $stmt = $this->master()->prepare(
            'SELECT id, tenant_id, store_id, store_name, amount_points, cycle, billing_day, next_charge_at, last_charge_at, status, note, created_at, updated_at FROM tenant_billing_subscriptions WHERE tenant_id = ? ORDER BY next_charge_at, id'
        );
        $stmt->execute([$tenantId]);

        return array_map(fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'tenant_key' => $tenantKey,
            'store_id' => (int) ($row['store_id'] ?? 0),
            'store_name' => (string) ($row['store_name'] ?? ''),
            'amount' => (int) ($row['amount_points'] ?? 0),
            'cycle' => (string) ($row['cycle'] ?? 'monthly'),
            'billing_day' => (int) ($row['billing_day'] ?? 0),
            'next_charge_at' => (string) ($row['next_charge_at'] ?? ''),
            'last_charge_at' => (string) ($row['last_charge_at'] ?? ''),
            'status' => (string) ($row['status'] ?? 'active'),
            'note' => (string) ($row['note'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ], $stmt->fetchAll());
    }

    public function adjustTenantPoints(string $tenantKey, int $amount, string $type, string $note, string $operator): void
    {
        if ($amount === 0) {
            return;
        }
        $this->writeTenantPointEntry($tenantKey, $amount, $type, $note, $operator, false, false, false);
    }

    public function chargeTenantPoints(string $tenantKey, int $amount, string $note, string $operator): bool
    {
        $amount = abs($amount);
        if ($amount <= 0) {
            return true;
        }

        return $this->writeTenantPointEntry($tenantKey, -$amount, 'charge', $note, $operator, true, false, false);
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

        if (!$this->tableExists($this->master(), 'tenant_billing_subscriptions')) {
            return $result;
        }

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
                $this->markMysqlSubscriptionCharged((int) ($subscription['id'] ?? 0), $due->format('Y-m-d'), $nextDue->format('Y-m-d'));

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

    /** @return array<int, array<string, mixed>> */
    public function stores(string $tenantKey): array
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return [];
        }

        $select = ['id', 'platform', 'dpqz', 'dpquancheng', 'is_hidden', 'created_at'];
        foreach ([
            'legacy_dpid',
            'api_config',
            'profit_deduction',
            'hidden_reason',
            'rms_service_secret',
            'rms_license_key',
            'rms_credentials_updated_at',
            'last_sync_at',
            'last_sync_status',
            'last_sync_message',
        ] as $column) {
            if ($this->columnExists($tenantPdo, 'stores', $column)) {
                $select[] = $column;
            }
        }

        $rows = $tenantPdo->query('SELECT ' . implode(', ', $select) . ' FROM stores ORDER BY platform, id')->fetchAll();
        return array_map(function (array $row): array {
            $apiConfig = $this->jsonArray($row['api_config'] ?? null);
            if (($row['rms_service_secret'] ?? '') !== '') {
                $apiConfig['Secret'] = (string) $row['rms_service_secret'];
            }
            if (($row['rms_license_key'] ?? '') !== '') {
                $apiConfig['Key'] = (string) $row['rms_license_key'];
            }

            $apiConfigJson = $apiConfig ? json_encode($apiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            $lastStatus = (string) ($row['last_sync_status'] ?? '');

            return [
                'id' => (int) $row['id'],
                'legacy_dpid' => (string) (($row['legacy_dpid'] ?? '') ?: $row['id']),
                'platform' => (string) $row['platform'],
                'short' => (string) $row['dpqz'],
                'name' => (string) $row['dpquancheng'],
                'status' => (bool) $row['is_hidden'] ? 'hidden' : 'visible',
                'api_status' => $lastStatus !== '' ? $lastStatus : ($apiConfig ? '已配置' : '未配置'),
                'api_config' => (string) ($apiConfigJson ?: ''),
                'profit_deduction' => (float) ($row['profit_deduction'] ?? 70),
                'hidden_reason' => (string) (($row['hidden_reason'] ?? '') ?: ((bool) $row['is_hidden'] ? '旧系统隐藏店铺' : '')),
                'last_sync_at' => (string) ($row['last_sync_at'] ?? ''),
                'last_sync_status' => $lastStatus,
                'last_sync_message' => (string) ($row['last_sync_message'] ?? ''),
                'created_by' => '租户管理员',
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);

        $rows = $tenantPdo->query('SELECT id, platform, dpqz, dpquancheng, is_hidden, created_at FROM stores ORDER BY platform, id')->fetchAll();
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'legacy_dpid' => (string) $row['id'],
            'platform' => (string) $row['platform'],
            'short' => (string) $row['dpqz'],
            'name' => (string) $row['dpquancheng'],
            'status' => (bool) $row['is_hidden'] ? 'hidden' : 'visible',
            'api_status' => '待配置',
            'api_config' => '',
            'profit_deduction' => 70,
            'hidden_reason' => (bool) $row['is_hidden'] ? '旧系统隐藏店铺' : '',
            'created_by' => '租户管理员',
            'created_at' => (string) $row['created_at'],
        ], $rows);
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
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return false;
        }

        $short = trim((string) ($data['short'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($short === '' || $name === '') {
            return false;
        }

        $apiConfig = $this->normalizeStoreApiConfig($data['api_config'] ?? null);
        $columns = ['platform', 'dpqz', 'dpquancheng', 'is_hidden'];
        $placeholders = ['?', '?', '?', '?'];
        $params = [
            preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? 'y')) ?: 'y',
            $short,
            $name,
            ($data['status'] ?? 'visible') === 'hidden' ? 1 : 0,
        ];

        foreach ([
            'legacy_dpid' => trim((string) ($data['legacy_dpid'] ?? '')),
            'api_config' => $apiConfig ? json_encode($apiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'profit_deduction' => $this->percentValue($data['profit_deduction'] ?? 70),
            'hidden_reason' => trim((string) ($data['hidden_reason'] ?? '')),
            'rms_service_secret' => $apiConfig['Secret'] ?? null,
            'rms_license_key' => $apiConfig['Key'] ?? null,
            'rms_credentials_updated_at' => ($apiConfig['Secret'] ?? '') !== '' || ($apiConfig['Key'] ?? '') !== '' ? date('Y-m-d H:i:s') : null,
        ] as $column => $value) {
            if (!$this->columnExists($tenantPdo, 'stores', $column)) {
                continue;
            }

            $columns[] = $column;
            $placeholders[] = '?';
            $params[] = $value;
        }

        $stmt = $tenantPdo->prepare('INSERT INTO stores (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
        $stmt->execute($params);
        $storeId = (int) $tenantPdo->lastInsertId();
        if ($storeId > 0) {
            $this->createStoreBillingSubscription($tenantKey, $storeId, $name);
        }
        return true;

        $stmt = $tenantPdo->prepare('INSERT INTO stores (platform, dpqz, dpquancheng, is_hidden) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? 'y')) ?: 'y',
            $short,
            $name,
            ($data['status'] ?? 'visible') === 'hidden' ? 1 : 0,
        ]);
        $storeId = (int) $tenantPdo->lastInsertId();
        if ($storeId > 0) {
            $this->createStoreBillingSubscription($tenantKey, $storeId, $name);
        }
        return true;
    }

    /** @param array<string, mixed> $data */
    public function updateStore(string $tenantKey, int $storeId, array $data): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $storeId <= 0) {
            return;
        }

        $short = trim((string) ($data['short'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($short === '' || $name === '') {
            return;
        }

        $apiConfig = $this->normalizeStoreApiConfig($data['api_config'] ?? null);
        $sets = ['platform = ?', 'dpqz = ?', 'dpquancheng = ?', 'is_hidden = ?'];
        $params = [
            preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? 'y')) ?: 'y',
            $short,
            $name,
            ($data['status'] ?? 'visible') === 'hidden' ? 1 : 0,
        ];

        foreach ([
            'legacy_dpid' => trim((string) ($data['legacy_dpid'] ?? '')),
            'api_config' => $apiConfig ? json_encode($apiConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'profit_deduction' => $this->percentValue($data['profit_deduction'] ?? 70),
            'hidden_reason' => trim((string) ($data['hidden_reason'] ?? '')),
            'rms_service_secret' => $apiConfig['Secret'] ?? null,
            'rms_license_key' => $apiConfig['Key'] ?? null,
            'rms_credentials_updated_at' => ($apiConfig['Secret'] ?? '') !== '' || ($apiConfig['Key'] ?? '') !== '' ? date('Y-m-d H:i:s') : null,
        ] as $column => $value) {
            if (!$this->columnExists($tenantPdo, 'stores', $column)) {
                continue;
            }

            $sets[] = "{$column} = ?";
            $params[] = $value;
        }

        $params[] = $storeId;
        $stmt = $tenantPdo->prepare('UPDATE stores SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        return;

        $stmt = $tenantPdo->prepare('UPDATE stores SET platform = ?, dpqz = ?, dpquancheng = ?, is_hidden = ? WHERE id = ?');
        $stmt->execute([
            preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? 'y')) ?: 'y',
            $short,
            $name,
            ($data['status'] ?? 'visible') === 'hidden' ? 1 : 0,
            $storeId,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function users(string $tenantKey): array
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return [];
        }

        $select = ['id', 'username', 'password_hash', 'legacy_password', 'is_company_admin', 'role', 'permissions', 'dpquancheng', 'is_active', 'created_at'];
        foreach (['display_name', 'preference_module', 'api_1688_config', 'password_reset_at', 'last_login_at'] as $column) {
            if ($this->columnExists($tenantPdo, 'users', $column)) {
                $select[] = $column;
            }
        }

        $rows = $tenantPdo->query('SELECT ' . implode(', ', $select) . ' FROM users ORDER BY id')->fetchAll();
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) (($row['display_name'] ?? '') ?: $row['username']),
            'username' => (string) $row['username'],
            'role' => (bool) $row['is_company_admin'] ? '公司管理员' : (string) $row['role'],
            'password_hash' => (string) ($row['password_hash'] ?? ''),
            'legacy_password' => (string) ($row['legacy_password'] ?? ''),
            'password_reset' => '',
            'password_reset_at' => (string) ($row['password_reset_at'] ?? ''),
            'last_login_at' => (string) ($row['last_login_at'] ?? ''),
            'preference_module' => (string) ($row['preference_module'] ?? ''),
            'api_1688_config' => is_string($row['api_1688_config'] ?? null) ? (string) $row['api_1688_config'] : json_encode($row['api_1688_config'] ?? [], JSON_UNESCAPED_UNICODE),
            'is_company_admin' => (bool) $row['is_company_admin'],
            'permissions' => json_decode((string) ($row['permissions'] ?? '[]'), true) ?: [],
            'stores' => array_filter(array_map('trim', explode(',', (string) ($row['dpquancheng'] ?? '')))),
            'status' => (bool) $row['is_active'] ? 'active' : 'disabled',
            'created_at' => (string) $row['created_at'],
        ], $rows);
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
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $userId <= 0 || $passwordHash === '') {
            return;
        }

        $assignments = [
            'password_hash = ?',
            'legacy_password = NULL',
        ];
        $params = [$passwordHash];
        if ($this->columnExists($tenantPdo, 'users', 'password_reset_at')) {
            $assignments[] = 'password_reset_at = NOW()';
        }
        $params[] = $userId;

        $stmt = $tenantPdo->prepare('UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    public function touchTenantUserLogin(string $tenantKey, int $userId): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $userId <= 0 || !$this->columnExists($tenantPdo, 'users', 'last_login_at')) {
            return;
        }

        $tenantPdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$userId]);
    }

    /** @param array<string, mixed> $data */
    public function addUser(string $tenantKey, array $data): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return;
        }

        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            return;
        }

        $role = in_array(($data['role'] ?? ''), ['公司管理员', '采购', '客服', '品检'], true) ? (string) $data['role'] : '客服';
        $permissions = $this->permissionsForRole($role, $data['permissions'] ?? []);
        $stores = implode(',', array_values(array_filter(array_map('trim', (array) ($data['stores'] ?? [])))));
        $password = trim((string) ($data['password_reset'] ?? '')) ?: 'Tenant@2026';
        $columns = ['username', 'password_hash', 'is_company_admin', 'role', 'permissions', 'dpquancheng', 'is_active'];
        $values = ['?', '?', '?', '?', '?', '?', '?'];
        $params = [
            $username,
            $this->hashPassword($password),
            $role === '公司管理员' ? 1 : 0,
            $role,
            json_encode($permissions, JSON_UNESCAPED_UNICODE),
            $stores,
            ($data['status'] ?? 'active') === 'disabled' ? 0 : 1,
        ];
        if ($this->columnExists($tenantPdo, 'users', 'display_name')) {
            $columns[] = 'display_name';
            $values[] = '?';
            $params[] = trim((string) ($data['name'] ?? ''));
        }
        if ($this->columnExists($tenantPdo, 'users', 'preference_module')) {
            $columns[] = 'preference_module';
            $values[] = '?';
            $params[] = trim((string) ($data['preference_module'] ?? ''));
        }
        if ($this->columnExists($tenantPdo, 'users', 'api_1688_config')) {
            $columns[] = 'api_1688_config';
            $values[] = '?';
            $params[] = trim((string) ($data['api_1688_config'] ?? '')) ?: null;
        }
        if ($this->columnExists($tenantPdo, 'users', 'password_reset_at')) {
            $columns[] = 'password_reset_at';
            $values[] = 'NOW()';
        }

        $stmt = $tenantPdo->prepare(
            'INSERT INTO users (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')'
        );
        $stmt->execute($params);
    }

    /** @param array<string, mixed> $data */
    public function updateUser(string $tenantKey, int $userId, array $data): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $userId <= 0) {
            return;
        }

        $username = trim((string) ($data['username'] ?? ''));
        if ($username === '') {
            return;
        }

        $role = in_array(($data['role'] ?? ''), ['公司管理员', '采购', '客服', '品检'], true) ? (string) $data['role'] : '客服';
        $permissions = $this->permissionsForRole($role, $data['permissions'] ?? []);
        $stores = array_values(array_filter(array_map('trim', (array) ($data['stores'] ?? []))));

        $assignments = [
            'username = ?',
            'is_company_admin = ?',
            'role = ?',
            'permissions = ?',
            'dpqz = ?',
            'dpquancheng = ?',
            'is_active = ?',
        ];
        $params = [
            $username,
            $role === '公司管理员' ? 1 : 0,
            $role,
            json_encode($permissions, JSON_UNESCAPED_UNICODE),
            implode(',', $stores),
            implode(',', $stores),
            ($data['status'] ?? 'active') === 'disabled' ? 0 : 1,
        ];
        if ($this->columnExists($tenantPdo, 'users', 'display_name')) {
            $assignments[] = 'display_name = ?';
            $params[] = trim((string) ($data['name'] ?? ''));
        }
        if ($this->columnExists($tenantPdo, 'users', 'preference_module')) {
            $assignments[] = 'preference_module = ?';
            $params[] = trim((string) ($data['preference_module'] ?? ''));
        }
        if ($this->columnExists($tenantPdo, 'users', 'api_1688_config')) {
            $assignments[] = 'api_1688_config = ?';
            $params[] = trim((string) ($data['api_1688_config'] ?? '')) ?: null;
        }
        if (trim((string) ($data['password_reset'] ?? '')) !== '') {
            $assignments[] = 'password_hash = ?';
            $assignments[] = 'legacy_password = NULL';
            $params[] = $this->hashPassword(trim((string) $data['password_reset']));
            if ($this->columnExists($tenantPdo, 'users', 'password_reset_at')) {
                $assignments[] = 'password_reset_at = NOW()';
            }
        }
        $params[] = $userId;

        $stmt = $tenantPdo->prepare('UPDATE users SET ' . implode(', ', $assignments) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    /** @return array<int, array<string, mixed>> */
    public function assignments(string $tenantKey): array
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'buyer_support_assignments')) {
            return [];
        }

        $rows = $tenantPdo->query(<<<'SQL'
SELECT a.id, a.buyer_user_id, a.support_user_id,
       b.username AS buyer_name, b.role AS buyer_role,
       s.username AS support_name, s.username AS support_username, s.dpquancheng AS support_stores,
       a.created_at
FROM buyer_support_assignments a
LEFT JOIN users b ON b.id = a.buyer_user_id
LEFT JOIN users s ON s.id = a.support_user_id
ORDER BY a.buyer_user_id, a.support_user_id
SQL)->fetchAll();

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'buyer_user_id' => (int) $row['buyer_user_id'],
            'buyer_name' => (string) ($row['buyer_name'] ?? ''),
            'buyer_role' => (string) ($row['buyer_role'] ?? ''),
            'support_user_id' => (int) $row['support_user_id'],
            'support_name' => (string) ($row['support_name'] ?? ''),
            'support_username' => (string) ($row['support_username'] ?? ''),
            'support_stores' => array_filter(array_map('trim', explode(',', (string) ($row['support_stores'] ?? '')))),
            'created_at' => (string) $row['created_at'],
        ], $rows);
    }

    /** @param array<int, int> $supportUserIds */
    public function saveAssignmentByBuyer(string $tenantKey, int $buyerUserId, array $supportUserIds): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'buyer_support_assignments') || $buyerUserId <= 0) {
            return;
        }

        $tenantPdo->prepare('DELETE FROM buyer_support_assignments WHERE buyer_user_id = ?')->execute([$buyerUserId]);
        $insert = $tenantPdo->prepare('INSERT INTO buyer_support_assignments (buyer_user_id, support_user_id) VALUES (?, ?)');
        foreach (array_values(array_unique(array_map('intval', $supportUserIds))) as $supportUserId) {
            if ($supportUserId > 0) {
                $insert->execute([$buyerUserId, $supportUserId]);
            }
        }
    }

    /** @param array<int, int> $buyerUserIds */
    public function saveAssignmentBySupport(string $tenantKey, int $supportUserId, array $buyerUserIds): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'buyer_support_assignments') || $supportUserId <= 0) {
            return;
        }

        $tenantPdo->prepare('DELETE FROM buyer_support_assignments WHERE support_user_id = ?')->execute([$supportUserId]);
        $insert = $tenantPdo->prepare('INSERT INTO buyer_support_assignments (buyer_user_id, support_user_id) VALUES (?, ?)');
        foreach (array_values(array_unique(array_map('intval', $buyerUserIds))) as $buyerUserId) {
            if ($buyerUserId > 0) {
                $insert->execute([$buyerUserId, $supportUserId]);
            }
        }
    }

    public function togglePlatform(string $tenantKey, string $platformCode, string $field): void
    {
        if (!in_array($field, ['enabled', 'locked'], true)) {
            return;
        }

        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null) {
            return;
        }

        $sql = "UPDATE tenant_platform SET {$field} = CASE WHEN {$field} = 1 THEN 0 ELSE 1 END WHERE tenant_id = ? AND platform_code = ?";
        $stmt = $this->master()->prepare($sql);
        $stmt->execute([$tenantId, $platformCode]);
    }

    public function toggleTenantFeature(string $tenantKey, string $featureKey): void
    {
        if (!TenantFeature::isKnown($featureKey) || !$this->tableExists($this->master(), 'tenant_features')) {
            return;
        }

        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null) {
            return;
        }

        $insert = $this->master()->prepare(
            'INSERT IGNORE INTO tenant_features (tenant_id, feature_key, enabled) VALUES (?, ?, ?)'
        );
        $insert->execute([$tenantId, $featureKey, (int) (TenantFeature::defaultMap()[$featureKey] ?? false)]);

        $stmt = $this->master()->prepare(
            'UPDATE tenant_features SET enabled = CASE WHEN enabled = 1 THEN 0 ELSE 1 END WHERE tenant_id = ? AND feature_key = ?'
        );
        $stmt->execute([$tenantId, $featureKey]);
    }

    public function changeItemSource(string $tenantKey, int $itemId, string $source): void
    {
        if (!in_array($source, ['cn_purchase', 'jp_stock', 'pending'], true)) {
            return;
        }

        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return;
        }

        $stmt = $tenantPdo->prepare('SELECT id, order_id, source_type FROM order_items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item || (string) $item['source_type'] === $source) {
            return;
        }

        $status = match ($source) {
            'cn_purchase' => '国内采购-准备',
            'jp_stock' => '待分配',
            default => '待处理',
        };

        $tenantPdo->beginTransaction();
        try {
            $update = $tenantPdo->prepare('UPDATE order_items SET source_type = ?, purchase_status = ? WHERE id = ?');
            $update->execute([$source, $status, $itemId]);

            if ($source === 'cn_purchase') {
                $this->ensureChildRow($tenantPdo, 'purchases', $itemId);
            }
            if ($source === 'jp_stock') {
                $this->ensureChildRow($tenantPdo, 'jp_shipments', $itemId);
            }

            $log = $tenantPdo->prepare(
                'INSERT INTO order_logs (order_id, order_item_id, operator, action_type, field_name, old_value, new_value, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $log->execute([
                (int) $item['order_id'],
                $itemId,
                '系统管理员',
                '货源改判',
                'source_type',
                (string) $item['source_type'],
                $source,
                $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            $tenantPdo->commit();
        } catch (\Throwable $error) {
            $tenantPdo->rollBack();
            throw $error;
        }
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
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo) {
            return;
        }

        $ids = $this->resolveItemIds($tenantPdo, $itemIds, $orderIds);
        foreach ($ids as $id) {
            $this->updateOrderItemData($tenantPdo, $id, $changes, $action, $operator);
        }
    }

    /**
     * @param array<int, int> $itemIds
     */
    public function updateItemsLogistics(string $tenantKey, array $itemIds, string $status, string $action, string $operator): int
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (!$tenantPdo || !$itemIds) {
            return 0;
        }

        $status = trim($status);
        $action = trim($action) ?: '物流更新';
        $operator = trim($operator) ?: '系统';
        $updated = 0;

        foreach ($itemIds as $itemId) {
            $snapshot = $this->itemSnapshot($tenantPdo, $itemId);
            if (!$snapshot) {
                continue;
            }

            $oldValue = (string) ($snapshot['logistics'] ?? ($snapshot['jpship_status'] ?? ''));
            if ($oldValue === $status) {
                continue;
            }

            $this->ensureChildRow($tenantPdo, 'domestic_shipments', $itemId);
            $stmt = $tenantPdo->prepare('UPDATE domestic_shipments SET jpship_status = ? WHERE order_item_id = ?');
            $stmt->execute([$status, $itemId]);
            $this->insertItemLog(
                $tenantPdo,
                (int) $snapshot['order_id'],
                $itemId,
                $action,
                'logistics',
                $oldValue,
                $status,
                $operator
            );
            $updated++;
        }

        return $updated;
    }

    /** @param array<int, int> $orderIds */
    public function deleteOrders(string $tenantKey, array $orderIds): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!$tenantPdo || !$orderIds) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $stmt = $tenantPdo->prepare("DELETE FROM orders WHERE id IN ({$placeholders})");
        $stmt->execute($orderIds);
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @return array{inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function upsertPlatformOrders(string $tenantKey, array $orders, string $operator): array
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'items_inserted' => 0, 'items_updated' => 0];
        if (!$tenantPdo || !$orders) {
            return $result;
        }

        $tenantPdo->beginTransaction();
        try {
            foreach ($orders as $order) {
                $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($order['platform'] ?? '')) ?: '';
                $platformOrderId = trim((string) ($order['platform_order_id'] ?? ''));
                $storeId = max(0, (int) ($order['store_id'] ?? 0));
                if ($platform === '' || $platformOrderId === '') {
                    $result['skipped']++;
                    continue;
                }

                $orderId = $this->findPlatformOrderId($tenantPdo, $platform, $storeId, $platformOrderId);
                $orderPayload = $this->normalizePlatformOrderPayload($order);
                if ($orderId > 0) {
                    $this->updatePlatformOrder($tenantPdo, $orderId, $orderPayload);
                    $result['updated']++;
                } else {
                    $orderId = $this->insertPlatformOrder($tenantPdo, $orderPayload);
                    $result['inserted']++;
                }

                $quantityDetail = [];
                foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $index => $item) {
                    $itemPayload = $this->normalizePlatformItemPayload($orderId, $item, $index);
                    $lineKey = $itemPayload['line_id'] !== '' ? $itemPayload['line_id'] : (string) ($index + 1);
                    $quantityDetail[] = 'L' . $lineKey . '=' . (int) $itemPayload['quantity'];
                    $itemId = $this->findPlatformOrderItemId($tenantPdo, $orderId, $itemPayload);
                    if ($itemId > 0) {
                        $this->updatePlatformOrderItem($tenantPdo, $itemId, $itemPayload);
                        $result['items_updated']++;
                    } else {
                        $itemId = $this->insertPlatformOrderItem($tenantPdo, $itemPayload);
                        $result['items_inserted']++;
                        $this->insertItemLog($tenantPdo, $orderId, $itemId, '平台API导入', 'source', '-', 'Rakuten RMS', $operator);
                    }
                }

                if ($quantityDetail) {
                    $this->mergeOrderQuantityDetail($tenantPdo, $orderId, implode('&', $quantityDetail));
                }
            }

            $tenantPdo->commit();
        } catch (\Throwable $error) {
            $tenantPdo->rollBack();
            throw $error;
        }

        return $result;
    }

    public function markStoreSync(string $tenantKey, int $storeId, string $status, string $message): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $storeId <= 0) {
            return;
        }

        $sets = [];
        $params = [];
        foreach ([
            'last_sync_at' => date('Y-m-d H:i:s'),
            'last_sync_status' => $this->shortText($status, 64),
            'last_sync_message' => $this->shortText($message, 1024),
        ] as $column => $value) {
            if (!$this->columnExists($tenantPdo, 'stores', $column)) {
                continue;
            }
            $sets[] = "{$column} = ?";
            $params[] = $value;
        }

        if (!$sets) {
            return;
        }

        $params[] = $storeId;
        $tenantPdo->prepare('UPDATE stores SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /** @param array<string, mixed> $data */
    public function updateOrderItem(
        string $tenantKey,
        int $itemId,
        array $data,
        string $operator = '系统管理员',
        string $action = '保存明细'
    ): void {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $itemId <= 0) {
            return;
        }

        $this->updateOrderItemData($tenantPdo, $itemId, $data, $action, $operator);
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
            $order = is_array($record['order'] ?? null) ? $record['order'] : [];
            $item = is_array($record['item'] ?? null) ? $record['item'] : [];
            $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($order['platform'] ?? '')) ?: '';
            $platformOrderId = trim((string) ($order['platform_order_id'] ?? ''));
            if ($platform === '' || $platformOrderId === '') {
                $this->importReportFail($report, (int) ($record['row'] ?? 0), '平台或订单号为空。');
                continue;
            }

            $order['store_id'] = $this->resolveImportStoreId($tenantKey, $platform, (int) ($order['store_id'] ?? 0));
            if (isset($order['extra']) && !isset($order['platform_extra'])) {
                $order['platform_extra'] = $order['extra'];
            }
            if (isset($item['extra']) && !isset($item['platform_extra'])) {
                $item['platform_extra'] = $item['extra'];
            }
            $key = $platform . "\n" . (int) $order['store_id'] . "\n" . $platformOrderId;
            if (!isset($orders[$key])) {
                $order['items'] = [];
                $orders[$key] = $order;
            } else {
                $orders[$key] = $this->mergeImportOrder($orders[$key], $order);
            }
            if ($item) {
                $orders[$key]['items'][] = $item;
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
        $tenantPdo = $this->tenantPdo($tenantKey);
        $report = $this->emptyImportReport();
        if (!$tenantPdo || !$records) {
            return $report;
        }

        foreach ($records as $record) {
            $identity = is_array($record['identity'] ?? null) ? $record['identity'] : [];
            $changes = is_array($record['changes'] ?? null) ? $record['changes'] : [];
            $itemId = $this->findImportItemId($tenantPdo, $identity);
            if ($itemId <= 0) {
                $report['failed']++;
                $this->importReportMessage($report, (int) ($record['row'] ?? 0), '未找到匹配的订单商品，采购导入未更新。');
                continue;
            }

            $snapshot = $this->itemSnapshot($tenantPdo, $itemId);
            $oldStatus = (string) ($snapshot['purchase_status'] ?? '');
            if (!$this->canAdvancePurchaseStatus($oldStatus)) {
                $report['skipped']++;
                $this->importReportMessage($report, (int) ($record['row'] ?? 0), "当前采购状态为 {$oldStatus}，未覆盖。");
                continue;
            }

            if (isset($changes['purchase_status'])) {
                $changes['purchase_status'] = $this->normalizePurchaseStatus((string) $changes['purchase_status']);
            }
            $changes['source_type'] = 'cn_purchase';
            $this->updateOrderItemData($tenantPdo, $itemId, $changes, '采购导入', $operator);
            $report['updated']++;
        }

        return $report;
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array{inserted: int, updated: int, skipped: int, failed: int, messages: array<int, string>}
     */
    public function importShippingRows(string $tenantKey, array $records, string $operator): array
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        $report = $this->emptyImportReport();
        if (!$tenantPdo || !$records) {
            return $report;
        }

        foreach ($records as $record) {
            $identity = is_array($record['identity'] ?? null) ? $record['identity'] : [];
            $changes = is_array($record['changes'] ?? null) ? $record['changes'] : [];
            $itemIds = $this->findImportItemIds($tenantPdo, $identity);
            if (!$itemIds && trim((string) ($changes['intl_number'] ?? '')) !== '') {
                $itemIds = $this->findImportItemIdsByIntlNumber($tenantPdo, (string) $changes['intl_number']);
            }
            if (!$itemIds) {
                $report['failed']++;
                $this->importReportMessage($report, (int) ($record['row'] ?? 0), '未找到匹配的订单商品，国际运单导入未更新。');
                continue;
            }

            foreach ($itemIds as $itemId) {
                $payload = $changes;
                if (isset($payload['intl_number']) && empty($payload['reset_tracking'])) {
                    $payload['intl_number'] = $this->mergeMysqlTrackingNumbers($tenantPdo, $itemId, (string) $payload['intl_number']);
                }
                unset($payload['reset_tracking']);
                if (isset($payload['purchase_status'])) {
                    $payload['purchase_status'] = $this->normalizePurchaseStatus((string) $payload['purchase_status']);
                }
                $this->updateOrderItemData($tenantPdo, $itemId, $payload, '国际运单导入', $operator);
                $report['updated']++;
            }
        }

        return $report;
    }

    public function updateOrderItemImage(string $tenantKey, int $itemId, string $kind, string $path): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $itemId <= 0 || !in_array($kind, ['main', 'sku'], true)) {
            return;
        }

        $column = $kind === 'sku' ? 'sku_image' : 'main_image';
        $stmt = $tenantPdo->prepare("SELECT id, order_id, {$column} AS old_path FROM order_items WHERE id = ? LIMIT 1");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) {
            return;
        }

        $update = $tenantPdo->prepare("UPDATE order_items SET {$column} = ? WHERE id = ?");
        $update->execute([$path, $itemId]);
        $this->insertItemLog(
            $tenantPdo,
            (int) $item['order_id'],
            $itemId,
            $kind === 'sku' ? '替换SKU图' : '替换主图',
            $column,
            (string) ($item['old_path'] ?? ''),
            $path
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function orderAttachments(string $tenantKey, int $orderId): array
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $orderId <= 0 || !$this->tableExists($tenantPdo, 'order_attachments')) {
            return [];
        }

        $stmt = $tenantPdo->prepare('SELECT * FROM order_attachments WHERE order_id = ? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC');
        $stmt->execute([$orderId]);

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'order_id' => (int) $row['order_id'],
            'order_item_id' => (int) ($row['order_item_id'] ?? 0),
            'type' => (string) $row['attachment_type'],
            'title' => (string) $row['title'],
            'path' => (string) $row['path'],
            'source' => (string) $row['source'],
            'uploaded_by' => (string) $row['uploaded_by'],
            'size' => (string) ($row['size_label'] ?? ''),
            'created_at' => (string) $row['created_at'],
        ], $stmt->fetchAll());
    }

    /** @param array<string, mixed> $data */
    public function addOrderAttachment(string $tenantKey, int $orderId, array $data): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $orderId <= 0 || !$this->tableExists($tenantPdo, 'order_attachments')) {
            return;
        }

        $title = trim((string) ($data['title'] ?? ''));
        $path = trim((string) ($data['path'] ?? ''));
        if ($title === '' || $path === '') {
            return;
        }

        $stmt = $tenantPdo->prepare(
            'INSERT INTO order_attachments (order_id, order_item_id, attachment_type, title, path, source, uploaded_by, size_label) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderId,
            (int) ($data['order_item_id'] ?? 0) ?: null,
            trim((string) ($data['type'] ?? '附件')) ?: '附件',
            $title,
            $path,
            trim((string) ($data['source'] ?? '手工登记')) ?: '手工登记',
            trim((string) ($data['uploaded_by'] ?? '租户管理员')) ?: '租户管理员',
            trim((string) ($data['size'] ?? '')),
        ]);
    }

    public function deleteOrderAttachment(string $tenantKey, int $attachmentId): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || $attachmentId <= 0 || !$this->tableExists($tenantPdo, 'order_attachments')) {
            return;
        }

        $tenantPdo->prepare('UPDATE order_attachments SET deleted_at = NOW() WHERE id = ?')->execute([$attachmentId]);
    }

    /** @return array<string, mixed> */
    public function globalSettings(): array
    {
        $settings = $this->defaultGlobalSettings();
        if (!$this->tableExists($this->master(), 'global_settings')) {
            return $settings;
        }

        $rows = $this->master()
            ->query('SELECT setting_key, setting_value, updated_at FROM global_settings ORDER BY setting_key')
            ->fetchAll();
        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            $value = json_decode((string) ($row['setting_value'] ?? ''), true);
            if ($key !== '' && is_array($value) && is_array($settings[$key] ?? null)) {
                $settings[$key] = array_replace_recursive($settings[$key], $value);
            }
            if (($row['updated_at'] ?? '') !== '') {
                $settings['updated_at'] = max((string) $settings['updated_at'], (string) $row['updated_at']);
            }
        }

        return $settings;
    }

    /** @param array<string, mixed> $data */
    public function saveGlobalSettings(array $data): void
    {
        if (!$this->tableExists($this->master(), 'global_settings')) {
            return;
        }

        $settings = array_replace_recursive($this->globalSettings(), $this->normalizeGlobalSettingsInput($data));
        $settings['updated_at'] = date('Y-m-d H:i:s');
        $stmt = $this->master()->prepare(
            'INSERT INTO global_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        );
        foreach (['logistics_mapping', 'showapi', 'proxy'] as $section) {
            $stmt->execute([
                $section,
                json_encode($settings[$section] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function tenantSettings(string $tenantKey): array
    {
        $settings = $this->defaultTenantSettings($this->tenant($tenantKey));
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'tenant_settings')) {
            return $settings;
        }

        $rows = $tenantPdo->query('SELECT setting_key, setting_value FROM tenant_settings')->fetchAll();
        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            $value = json_decode((string) ($row['setting_value'] ?? ''), true);
            if ($key !== '' && is_array($value)) {
                $settings[$key] = array_replace_recursive(is_array($settings[$key] ?? null) ? $settings[$key] : [], $value);
            }
        }

        return $settings;
    }

    /** @param array<string, mixed> $data */
    public function saveTenantSettings(string $tenantKey, array $data): void
    {
        $settings = array_replace_recursive($this->tenantSettings($tenantKey), $data);
        $settings['updated_at'] = date('Y-m-d H:i:s');

        $tenantPdo = $this->tenantPdo($tenantKey);
        if ($tenantPdo && $this->tableExists($tenantPdo, 'tenant_settings')) {
            $stmt = $tenantPdo->prepare(
                'INSERT INTO tenant_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
            );
            foreach (['company', 'orders', 'profit', 'logistics', 'api_1688'] as $section) {
                $stmt->execute([
                    $section,
                    json_encode($settings[$section] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        }

        $this->updateTenantProfile($tenantKey, is_array($settings['company'] ?? null) ? $settings['company'] : []);
    }

    /** @return array<int, array<string, mixed>> */
    public function importExportLogs(string $tenantKey): array
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'import_export_logs')) {
            return [];
        }

        $rows = $tenantPdo->query('SELECT * FROM import_export_logs ORDER BY created_at DESC, id DESC LIMIT 30')->fetchAll();
        return array_map(fn (array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'type' => (string) ($row['job_type'] ?? ''),
            'name' => (string) ($row['job_name'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'file_name' => (string) ($row['file_name'] ?? ''),
            'rows' => (int) ($row['row_count'] ?? 0),
            'message' => (string) ($row['message'] ?? ''),
            'preview' => json_decode((string) ($row['preview_json'] ?? '[]'), true) ?: [],
            'created_by' => (string) ($row['created_by'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ], $rows);
    }

    /** @param array<string, mixed> $data */
    public function addImportExportLog(string $tenantKey, array $data): void
    {
        $tenantPdo = $this->tenantPdo($tenantKey);
        if (!$tenantPdo || !$this->tableExists($tenantPdo, 'import_export_logs')) {
            return;
        }

        $stmt = $tenantPdo->prepare(
            'INSERT INTO import_export_logs (job_type, job_name, status, file_name, row_count, message, preview_json, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            trim((string) ($data['type'] ?? 'import')),
            trim((string) ($data['name'] ?? '')),
            trim((string) ($data['status'] ?? '已记录')),
            trim((string) ($data['file_name'] ?? '')),
            (int) ($data['rows'] ?? 0),
            trim((string) ($data['message'] ?? '')),
            json_encode(array_slice(is_array($data['preview'] ?? null) ? $data['preview'] : [], 0, 5), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            trim((string) ($data['created_by'] ?? '系统')),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function mailAccounts(string $tenantKey): array
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || !$this->tableExists($pdo, 'ph_mail_account')) {
            return [];
        }

        return $pdo->query(
            'SELECT a.*, (SELECT COUNT(*) FROM ph_mail_folder f WHERE f.account_id = a.id AND f.sync_enabled = 1) AS synced_folders FROM ph_mail_account a ORDER BY a.sort, a.id'
        )->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function mailAccount(string $tenantKey, int $accountId): ?array
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || $accountId <= 0 || !$this->tableExists($pdo, 'ph_mail_account')) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT * FROM ph_mail_account WHERE id = ? LIMIT 1');
        $stmt->execute([$accountId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $data */
    public function saveMailAccount(string $tenantKey, array $data): int
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || !$this->tableExists($pdo, 'ph_mail_account')) {
            return 0;
        }

        $id = (int) ($data['id'] ?? 0);
        $fields = [
            'shop_dpqz' => trim((string) ($data['shop_dpqz'] ?? '')),
            'shop_name' => trim((string) ($data['shop_name'] ?? '')),
            'platform' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($data['platform'] ?? '')),
            'imap_host' => trim((string) ($data['imap_host'] ?? '')),
            'imap_port' => max(1, (int) ($data['imap_port'] ?? 993)),
            'imap_ssl' => (int) (($data['imap_ssl'] ?? 1) ? 1 : 0),
            'imap_user' => trim((string) ($data['imap_user'] ?? '')),
            'imap_pass' => (string) ($data['imap_pass'] ?? ''),
            'smtp_host' => trim((string) ($data['smtp_host'] ?? '')),
            'smtp_port' => max(1, (int) ($data['smtp_port'] ?? 465)),
            'smtp_secure' => in_array(($data['smtp_secure'] ?? 'ssl'), ['ssl', 'tls', 'none'], true) ? (string) ($data['smtp_secure'] ?? 'ssl') : 'ssl',
            'smtp_user' => trim((string) ($data['smtp_user'] ?? '')),
            'smtp_pass' => (string) ($data['smtp_pass'] ?? ''),
            'sent_folder' => trim((string) ($data['sent_folder'] ?? 'Sent')),
            'enabled' => (int) (($data['enabled'] ?? 1) ? 1 : 0),
            'sort' => (int) ($data['sort'] ?? 0),
        ];
        $fields = array_filter(
            $fields,
            fn (mixed $value, string $column): bool => $this->columnExists($pdo, 'ph_mail_account', $column),
            ARRAY_FILTER_USE_BOTH
        );
        if (!$fields) {
            return $id;
        }

        if ($id > 0 && $this->mailAccount($tenantKey, $id)) {
            $sets = [];
            $params = [];
            foreach ($fields as $column => $value) {
                $sets[] = "`{$column}` = ?";
                $params[] = $value;
            }
            $params[] = $id;
            $stmt = $pdo->prepare('UPDATE ph_mail_account SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($params);
            return $id;
        }

        $columns = array_keys($fields);
        $stmt = $pdo->prepare('INSERT INTO ph_mail_account (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')');
        $stmt->execute(array_values($fields));

        return (int) $pdo->lastInsertId();
    }

    public function deleteMailAccount(string $tenantKey, int $accountId): void
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || $accountId <= 0 || !$this->tableExists($pdo, 'ph_mail_account')) {
            return;
        }

        foreach (['ph_mail_message', 'ph_mail_folder', 'ph_mail_reply'] as $table) {
            if ($this->tableExists($pdo, $table)) {
                $pdo->prepare("DELETE FROM {$table} WHERE account_id = ?")->execute([$accountId]);
            }
        }
        if ($this->tableExists($pdo, 'ph_mail_rule_account')) {
            $pdo->prepare('DELETE FROM ph_mail_rule_account WHERE account_id = ?')->execute([$accountId]);
        }
        $pdo->prepare('DELETE FROM ph_mail_account WHERE id = ?')->execute([$accountId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function mailFolders(string $tenantKey, ?int $accountId = null, bool $onlySynced = false): array
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || !$this->tableExists($pdo, 'ph_mail_folder')) {
            return [];
        }

        $where = [];
        $params = [];
        if ($accountId !== null) {
            $where[] = 'account_id = ?';
            $params[] = $accountId;
        }
        if ($onlySynced) {
            $where[] = 'sync_enabled = 1';
        }

        $stmt = $pdo->prepare('SELECT * FROM ph_mail_folder' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY account_id, sort, id');
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function mailFolder(string $tenantKey, int $folderId): ?array
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || $folderId <= 0 || !$this->tableExists($pdo, 'ph_mail_folder')) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT * FROM ph_mail_folder WHERE id = ? LIMIT 1');
        $stmt->execute([$folderId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<int, string> $folders */
    public function upsertMailFolders(string $tenantKey, int $accountId, array $folders): void
    {
        $pdo = $this->tenantPdo($tenantKey);
        $account = $this->mailAccount($tenantKey, $accountId);
        if (!$pdo || !$account || !$this->tableExists($pdo, 'ph_mail_folder')) {
            return;
        }

        $select = $pdo->prepare('SELECT id FROM ph_mail_folder WHERE account_id = ? AND imap_path = ? LIMIT 1');
        $insert = $pdo->prepare('INSERT INTO ph_mail_folder (account_id, shop_dpqz, imap_path, display_name, role, sync_enabled, sort) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $sort = 1;
        foreach (array_values(array_unique(array_filter(array_map('trim', $folders)))) as $path) {
            $select->execute([$accountId, $path]);
            if ($select->fetchColumn() === false) {
                $insert->execute([
                    $accountId,
                    (string) ($account['shop_dpqz'] ?? ''),
                    $path,
                    $this->mailFolderLeaf($path),
                    strtoupper($path) === 'INBOX' ? 'inbox' : 'custom',
                    strtoupper($path) === 'INBOX' ? 1 : 0,
                    $sort,
                ]);
            }
            $sort++;
        }
    }

    /** @param array<string, mixed> $data */
    public function updateMailFolder(string $tenantKey, int $folderId, array $data): void
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || $folderId <= 0 || !$this->tableExists($pdo, 'ph_mail_folder')) {
            return;
        }

        $sets = [];
        $params = [];
        foreach (['display_name', 'role', 'sync_enabled', 'sort'] as $column) {
            if (!array_key_exists($column, $data) || !$this->columnExists($pdo, 'ph_mail_folder', $column)) {
                continue;
            }
            $sets[] = "`{$column}` = ?";
            $params[] = in_array($column, ['sync_enabled', 'sort'], true) ? (int) $data[$column] : trim((string) $data[$column]);
        }
        if (!$sets) {
            return;
        }
        $params[] = $folderId;
        $pdo->prepare('UPDATE ph_mail_folder SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /** @return array<string, mixed> */
    public function mailFolderCounts(string $tenantKey): array
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || !$this->tableExists($pdo, 'ph_mail_message')) {
            return ['unread_map' => [], 'total_map' => [], 'total_unread' => 0, 'total_all' => 0];
        }

        $unread = [];
        $total = [];
        $totalUnread = 0;
        $totalAll = 0;
        foreach ($pdo->query('SELECT folder_id, COUNT(*) AS c, SUM(IF(is_read = 0, 1, 0)) AS u FROM ph_mail_message WHERE is_deleted = 0 GROUP BY folder_id')->fetchAll() as $row) {
            $fid = (int) $row['folder_id'];
            $total[$fid] = (int) $row['c'];
            $unread[$fid] = (int) $row['u'];
            $totalAll += (int) $row['c'];
            $totalUnread += (int) $row['u'];
        }

        return ['unread_map' => $unread, 'total_map' => $total, 'total_unread' => $totalUnread, 'total_all' => $totalAll];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: array<int, array<string, mixed>>, total: int, page: int, page_size: int, total_pages: int}
     */
    public function mailMessages(string $tenantKey, array $filters, int $page, int $pageSize): array
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || !$this->tableExists($pdo, 'ph_mail_message')) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => $pageSize, 'total_pages' => 1];
        }

        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $accountId = (int) ($filters['account_id'] ?? 0);
        $folderId = (int) ($filters['folder_id'] ?? 0);
        $where = ['m.is_deleted = 0'];
        $params = [];
        if ($folderId > 0) {
            $where[] = 'm.folder_id = ?';
            $params[] = $folderId;
        } elseif ($accountId > 0) {
            $where[] = 'm.account_id = ?';
            $params[] = $accountId;
        } elseif ($this->tableExists($pdo, 'ph_mail_folder')) {
            $ids = $pdo->query("SELECT id FROM ph_mail_folder WHERE sync_enabled = 1 AND UPPER(imap_path) = 'INBOX'")->fetchAll(\PDO::FETCH_COLUMN);
            if ($ids) {
                $where[] = 'm.folder_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                array_push($params, ...array_map('intval', $ids));
            }
        }
        if (!empty($filters['unread'])) {
            $where[] = 'm.is_read = 0';
        }
        if (!empty($filters['important'])) {
            $where[] = 'm.is_important = 1';
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(m.subject LIKE ? OR m.from_addr LIKE ? OR m.from_name LIKE ? OR m.to_addr LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $count = $pdo->prepare("SELECT COUNT(*) FROM ph_mail_message m WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $pageSize;

        $stmt = $pdo->prepare(sprintf(
            'SELECT m.*, f.display_name AS folder_name, f.imap_path AS folder_path, a.shop_name AS account_shop_name, a.shop_dpqz AS account_shop_dpqz, a.imap_user AS account_email, a.platform AS account_platform, a.sent_folder FROM ph_mail_message m LEFT JOIN ph_mail_folder f ON f.id = m.folder_id LEFT JOIN ph_mail_account a ON a.id = m.account_id WHERE %s ORDER BY m.mail_date DESC, m.id DESC LIMIT %d OFFSET %d',
            $whereSql,
            $pageSize,
            $offset
        ));
        $stmt->execute($params);

        return [
            'rows' => array_map(fn (array $row): array => $this->mailHydrateMysqlMessage($row), $stmt->fetchAll()),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'total_pages' => $totalPages,
        ];
    }

    /** @return array<string, mixed>|null */
    public function mailMessage(string $tenantKey, int $messageId): ?array
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || $messageId <= 0 || !$this->tableExists($pdo, 'ph_mail_message')) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT m.*, f.display_name AS folder_name, f.imap_path AS folder_path, a.shop_name AS account_shop_name, a.shop_dpqz AS account_shop_dpqz, a.imap_user AS account_email, a.platform AS account_platform, a.sent_folder FROM ph_mail_message m LEFT JOIN ph_mail_folder f ON f.id = m.folder_id LEFT JOIN ph_mail_account a ON a.id = m.account_id WHERE m.id = ? LIMIT 1');
        $stmt->execute([$messageId]);
        $row = $stmt->fetch();

        return $row ? $this->mailHydrateMysqlMessage($row) : null;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array{inserted: int, inserted_ids: array<int, int>, max_uid: int}
     */
    public function insertMailMessages(string $tenantKey, int $accountId, int $folderId, array $messages): array
    {
        $pdo = $this->tenantPdo($tenantKey);
        $account = $this->mailAccount($tenantKey, $accountId);
        if (!$pdo || !$account || !$this->tableExists($pdo, 'ph_mail_message')) {
            return ['inserted' => 0, 'inserted_ids' => [], 'max_uid' => 0];
        }

        $stmt = $pdo->prepare('INSERT IGNORE INTO ph_mail_message (account_id, shop_dpqz, folder_id, uid, message_id, from_addr, from_name, to_addr, subject, body_text, body_html, mail_date, seen, is_read, has_attachment, attachments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $inserted = 0;
        $insertedIds = [];
        $maxUid = 0;
        foreach ($messages as $message) {
            $uid = (int) ($message['uid'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $maxUid = max($maxUid, $uid);
            $seen = (int) (($message['seen'] ?? 0) ? 1 : 0);
            $stmt->execute([
                $accountId,
                (string) ($account['shop_dpqz'] ?? ''),
                $folderId,
                $uid,
                substr((string) ($message['message_id'] ?? ''), 0, 512),
                substr((string) ($message['from_addr'] ?? ''), 0, 320),
                substr((string) ($message['from_name'] ?? ''), 0, 320),
                (string) ($message['to_addr'] ?? ''),
                substr((string) ($message['subject'] ?? ''), 0, 1000),
                (string) ($message['body_text'] ?? ''),
                (string) ($message['body_html'] ?? ''),
                ($message['mail_date'] ?? '') !== '' ? (string) $message['mail_date'] : null,
                $seen,
                $seen,
                (int) (($message['has_attachment'] ?? 0) ? 1 : 0),
                json_encode(is_array($message['attachments'] ?? null) ? $message['attachments'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            if ($stmt->rowCount() > 0) {
                $inserted++;
                $insertedIds[] = (int) $pdo->lastInsertId();
            }
        }

        return ['inserted' => $inserted, 'inserted_ids' => $insertedIds, 'max_uid' => $maxUid];
    }

    /** @param array<string, int> $status */
    public function updateMailFolderAfterSync(string $tenantKey, int $folderId, int $lastUid, int $messageCount, array $status = []): void
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || $folderId <= 0 || !$this->tableExists($pdo, 'ph_mail_folder')) {
            return;
        }

        $sets = ['last_uid = GREATEST(last_uid, ?)', 'msg_count = ?'];
        $params = [$lastUid, $messageCount];
        foreach (['last_uidnext', 'last_exists', 'uidvalidity', 'backfill_done'] as $column) {
            if (array_key_exists($column, $status) && $this->columnExists($pdo, 'ph_mail_folder', $column)) {
                $sets[] = "`{$column}` = ?";
                $params[] = (int) $status[$column];
            }
        }
        $params[] = $folderId;
        $pdo->prepare('UPDATE ph_mail_folder SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    public function updateMailAccountLastSync(string $tenantKey, int $accountId): void
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || $accountId <= 0 || !$this->tableExists($pdo, 'ph_mail_account')) {
            return;
        }

        $pdo->prepare('UPDATE ph_mail_account SET last_sync_at = NOW() WHERE id = ?')->execute([$accountId]);
    }

    /** @param array<string, mixed> $body */
    public function saveMailMessageBody(string $tenantKey, int $messageId, array $body): void
    {
        $this->updateMailMessages($tenantKey, [$messageId], [
            'body_text' => (string) ($body['body_text'] ?? ''),
            'body_html' => (string) ($body['body_html'] ?? ''),
            'cc_addr' => (string) ($body['cc_addr'] ?? ''),
            'has_attachment' => (int) (($body['has_attachment'] ?? 0) ? 1 : 0),
            'attachments' => json_encode(is_array($body['attachments'] ?? null) ? $body['attachments'] : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'body_loaded' => 1,
        ]);
    }

    /**
     * @param array<int, int> $messageIds
     * @param array<string, mixed> $changes
     */
    public function updateMailMessages(string $tenantKey, array $messageIds, array $changes): int
    {
        $pdo = $this->tenantPdo($tenantKey);
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if (!$pdo || !$ids || !$changes || !$this->tableExists($pdo, 'ph_mail_message')) {
            return 0;
        }

        $sets = [];
        $params = [];
        foreach (['folder_id', 'is_read', 'is_important', 'is_deleted', 'replied', 'body_text', 'body_html', 'cc_addr', 'has_attachment', 'attachments', 'body_loaded'] as $column) {
            if (!array_key_exists($column, $changes) || !$this->columnExists($pdo, 'ph_mail_message', $column)) {
                continue;
            }
            $sets[] = "`{$column}` = ?";
            $params[] = $changes[$column];
        }
        if (!$sets) {
            return 0;
        }
        $params = array_merge($params, $ids);
        $stmt = $pdo->prepare('UPDATE ph_mail_message SET ' . implode(', ', $sets) . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /** @return array<int, array<string, mixed>> */
    public function mailRules(string $tenantKey): array
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || !$this->tableExists($pdo, 'ph_mail_rule')) {
            return [];
        }

        $rows = $pdo->query('SELECT * FROM ph_mail_rule ORDER BY priority, id')->fetchAll();
        if ($this->tableExists($pdo, 'ph_mail_rule_account')) {
            $map = [];
            foreach ($pdo->query('SELECT rule_id, account_id FROM ph_mail_rule_account ORDER BY rule_id, account_id')->fetchAll() as $row) {
                $rid = (int) $row['rule_id'];
                $map[$rid] ??= [];
                $map[$rid][] = (int) $row['account_id'];
            }
            foreach ($rows as &$row) {
                $row['account_ids'] = $map[(int) ($row['id'] ?? 0)] ?? [];
            }
            unset($row);
        }

        return $rows;
    }

    /** @param array<string, mixed> $data */
    public function saveMailRule(string $tenantKey, array $data): int
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || !$this->tableExists($pdo, 'ph_mail_rule')) {
            return 0;
        }

        $id = (int) ($data['id'] ?? 0);
        $accountIds = array_values(array_unique(array_filter(array_map('intval', (array) ($data['account_ids'] ?? [])))));
        $fields = [
            'name' => trim((string) ($data['name'] ?? '')),
            'account_id' => $accountIds[0] ?? 0,
            'apply_all' => (int) (($data['apply_all'] ?? 0) ? 1 : 0),
            'priority' => (int) ($data['priority'] ?? 0),
            'enabled' => (int) (($data['enabled'] ?? 1) ? 1 : 0),
            'match_from' => trim((string) ($data['match_from'] ?? '')),
            'match_subject' => trim((string) ($data['match_subject'] ?? '')),
            'match_to' => trim((string) ($data['match_to'] ?? '')),
            'platforms' => trim((string) ($data['platforms'] ?? '')),
            'target_folder_id' => (int) ($data['target_folder_id'] ?? 0),
            'target_folder_name' => trim((string) ($data['target_folder_name'] ?? '')),
            'auto_create_folder' => (int) (($data['auto_create_folder'] ?? 1) ? 1 : 0),
            'mark_read' => (int) (($data['mark_read'] ?? 0) ? 1 : 0),
            'mark_important' => (int) (($data['mark_important'] ?? 0) ? 1 : 0),
            'stop_on_match' => (int) (($data['stop_on_match'] ?? 1) ? 1 : 0),
        ];
        $fields = array_filter(
            $fields,
            fn (mixed $value, string $column): bool => $this->columnExists($pdo, 'ph_mail_rule', $column),
            ARRAY_FILTER_USE_BOTH
        );
        if (!$fields) {
            return $id;
        }

        if ($id > 0) {
            $sets = [];
            $params = [];
            foreach ($fields as $column => $value) {
                $sets[] = "`{$column}` = ?";
                $params[] = $value;
            }
            if ($this->columnExists($pdo, 'ph_mail_rule', 'updated_at')) {
                $sets[] = 'updated_at = NOW()';
            }
            $params[] = $id;
            $pdo->prepare('UPDATE ph_mail_rule SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
        } else {
            $columns = array_keys($fields);
            $pdo->prepare('INSERT INTO ph_mail_rule (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')')->execute(array_values($fields));
            $id = (int) $pdo->lastInsertId();
        }

        if ($id > 0 && $this->tableExists($pdo, 'ph_mail_rule_account')) {
            $pdo->prepare('DELETE FROM ph_mail_rule_account WHERE rule_id = ?')->execute([$id]);
            if (!(int) ($fields['apply_all'] ?? 0) && $accountIds) {
                $insert = $pdo->prepare('INSERT IGNORE INTO ph_mail_rule_account (rule_id, account_id) VALUES (?, ?)');
                foreach ($accountIds as $accountId) {
                    $insert->execute([$id, $accountId]);
                }
            }
        }

        return $id;
    }

    public function deleteMailRule(string $tenantKey, int $ruleId): void
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || $ruleId <= 0 || !$this->tableExists($pdo, 'ph_mail_rule')) {
            return;
        }
        if ($this->tableExists($pdo, 'ph_mail_rule_account')) {
            $pdo->prepare('DELETE FROM ph_mail_rule_account WHERE rule_id = ?')->execute([$ruleId]);
        }
        $pdo->prepare('DELETE FROM ph_mail_rule WHERE id = ?')->execute([$ruleId]);
    }

    /** @param array<string, mixed> $data */
    public function addMailReply(string $tenantKey, array $data): void
    {
        $pdo = $this->tenantPdo($tenantKey);
        if (!$pdo || !$this->tableExists($pdo, 'ph_mail_reply')) {
            return;
        }

        $pdo->prepare('INSERT INTO ph_mail_reply (message_id, account_id, to_addr, cc_addr, bcc_addr, subject, body, operator, success, error_msg, appended, has_attach) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            (int) ($data['message_id'] ?? 0),
            (int) ($data['account_id'] ?? 0),
            trim((string) ($data['to_addr'] ?? '')),
            trim((string) ($data['cc_addr'] ?? '')),
            trim((string) ($data['bcc_addr'] ?? '')),
            trim((string) ($data['subject'] ?? '')),
            (string) ($data['body'] ?? ''),
            trim((string) ($data['operator'] ?? '')),
            (int) (($data['success'] ?? 0) ? 1 : 0),
            trim((string) ($data['error_msg'] ?? '')),
            (int) (($data['appended'] ?? 0) ? 1 : 0),
            (int) (($data['has_attach'] ?? 0) ? 1 : 0),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function mailHydrateMysqlMessage(array $row): array
    {
        $decoded = json_decode((string) ($row['attachments'] ?? '[]'), true);
        $row['attachments'] = is_array($decoded) ? $decoded : [];
        $sent = trim((string) ($row['sent_folder'] ?? 'Sent'));
        $row['is_sent'] = $sent !== '' && in_array($sent, [
            trim((string) ($row['folder_path'] ?? '')),
            trim((string) ($row['folder_name'] ?? '')),
        ], true);

        return $row;
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

    private function master(): \PDO
    {
        if ($this->master instanceof \PDO) {
            return $this->master;
        }

        $this->master = $this->connect($this->config->mysqlDsn());
        return $this->master;
    }

    private function tenantPdo(string $tenantKey): ?\PDO
    {
        if (isset($this->tenantConnections[$tenantKey])) {
            return $this->tenantConnections[$tenantKey];
        }

        $dsn = $this->config->tenantDsn($tenantKey);
        if ($dsn === '') {
            return null;
        }

        $this->tenantConnections[$tenantKey] = $this->connect($dsn);
        return $this->tenantConnections[$tenantKey];
    }

    private function connect(string $dsn): \PDO
    {
        $pdo = new \PDO($dsn, $this->config->mysqlUser(), $this->config->mysqlPassword(), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('SET NAMES utf8mb4');

        return $pdo;
    }

    private function tenantId(string $tenantKey): ?int
    {
        $stmt = $this->master()->prepare('SELECT id FROM tenants WHERE subdomain = ? LIMIT 1');
        $stmt->execute([$tenantKey]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function ensureBillingAccount(int $tenantId): void
    {
        if ($tenantId <= 0 || !$this->tableExists($this->master(), 'tenant_billing_accounts')) {
            return;
        }

        $columns = ['tenant_id'];
        $values = ['?'];
        $params = [$tenantId];
        if ($this->columnExists($this->master(), 'tenant_billing_accounts', 'balance_points')) {
            $columns[] = 'balance_points';
            $values[] = '0';
        } elseif ($this->columnExists($this->master(), 'tenant_billing_accounts', 'balance_cents')) {
            $columns[] = 'balance_cents';
            $values[] = '0';
        }

        $this->master()
            ->prepare('INSERT IGNORE INTO tenant_billing_accounts (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')')
            ->execute($params);
    }

    private function createStoreBillingSubscription(string $tenantKey, int $storeId, string $storeName): void
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null || $storeId <= 0 || !$this->tableExists($this->master(), 'tenant_billing_subscriptions')) {
            return;
        }

        $startDate = new \DateTimeImmutable('today');
        $nextCharge = $this->nextMonthlyDate($startDate);
        $this->master()->prepare(
            'INSERT INTO tenant_billing_subscriptions (tenant_id, store_id, store_name, amount_points, cycle, billing_day, next_charge_at, status, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE store_name = VALUES(store_name), amount_points = VALUES(amount_points), status = VALUES(status), updated_at = NOW()'
        )->execute([
            $tenantId,
            $storeId,
            trim($storeName) !== '' ? trim($storeName) : ('店铺 #' . $storeId),
            self::STORE_MONTHLY_FEE,
            'monthly',
            (int) $startDate->format('j'),
            $nextCharge->format('Y-m-d'),
            'active',
            '新增店铺自动创建月费订阅',
        ]);
    }

    private function markMysqlSubscriptionCharged(int $subscriptionId, string $lastChargeAt, string $nextChargeAt): void
    {
        if ($subscriptionId <= 0 || !$this->tableExists($this->master(), 'tenant_billing_subscriptions')) {
            return;
        }

        $this->master()->prepare(
            'UPDATE tenant_billing_subscriptions SET last_charge_at = ?, next_charge_at = ?, updated_at = NOW() WHERE id = ?'
        )->execute([$lastChargeAt, $nextChargeAt, $subscriptionId]);
    }

    private function nextMonthlyDate(\DateTimeImmutable $from, ?int $billingDay = null): \DateTimeImmutable
    {
        $billingDay ??= (int) $from->format('j');
        $base = $from->modify('first day of next month');
        $lastDay = (int) $base->format('t');
        $day = min(max(1, $billingDay), $lastDay);

        return $base->setDate((int) $base->format('Y'), (int) $base->format('m'), $day);
    }

    private function writeTenantPointEntry(
        string $tenantKey,
        int $amount,
        string $type,
        string $note,
        string $operator,
        bool $requireEnough,
        bool $allowDebt,
        bool $autoSuspend,
        ?int &$balanceAfterOut = null
    ): bool
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null || !$this->tableExists($this->master(), 'tenant_billing_accounts')) {
            return false;
        }

        $pdo = $this->master();
        $this->ensureBillingAccount($tenantId);
        $usesPoints = $this->columnExists($pdo, 'tenant_billing_accounts', 'balance_points');
        $accountColumn = $usesPoints ? 'balance_points' : 'balance_cents';
        $amountForDb = $usesPoints ? $amount : $amount * 100;

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT {$accountColumn} AS balance FROM tenant_billing_accounts WHERE tenant_id = ? FOR UPDATE");
            $stmt->execute([$tenantId]);
            $currentRaw = (int) ($stmt->fetchColumn() ?: 0);
            $currentPoints = $usesPoints ? $currentRaw : intdiv($currentRaw, 100);
            $nextPoints = $currentPoints + $amount;
            if ($amount < 0 && ($requireEnough || !$allowDebt) && $nextPoints < 0) {
                $pdo->rollBack();
                return false;
            }

            $nextRaw = $currentRaw + $amountForDb;
            $pdo->prepare("UPDATE tenant_billing_accounts SET {$accountColumn} = ? WHERE tenant_id = ?")->execute([$nextRaw, $tenantId]);
            if ($autoSuspend && $nextPoints <= self::DEBT_SUSPEND_THRESHOLD) {
                $pdo->prepare("UPDATE tenants SET status = 'suspended' WHERE id = ?")->execute([$tenantId]);
                $note = trim($note) . '；余额达到 ' . self::DEBT_SUSPEND_THRESHOLD . 'pt 自动停用租户';
            }

            if ($this->tableExists($pdo, 'tenant_billing_ledger')) {
                $entryType = in_array($type, ['recharge', 'adjustment', 'charge'], true) ? $type : 'adjustment';
                if ($this->columnExists($pdo, 'tenant_billing_ledger', 'amount_points')) {
                    $columns = ['tenant_id', 'entry_type', 'amount_points', 'balance_after_points', 'note'];
                    $params = [$tenantId, $entryType, $amount, $nextPoints, $note];
                } else {
                    $columns = ['tenant_id', 'entry_type', 'amount_cents', 'balance_after_cents', 'note'];
                    $params = [$tenantId, $entryType, $amount * 100, $usesPoints ? $nextRaw * 100 : $nextRaw, $note];
                }
                if ($this->columnExists($pdo, 'tenant_billing_ledger', 'operator')) {
                    $columns[] = 'operator';
                    $params[] = $operator;
                }
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                $pdo->prepare('INSERT INTO tenant_billing_ledger (' . implode(', ', $columns) . ") VALUES ({$placeholders})")->execute($params);
            }

            $pdo->commit();
            $balanceAfterOut = $nextPoints;
            return true;
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    /** @param array<string, mixed> $company */
    private function updateTenantProfile(string $tenantKey, array $company): void
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null) {
            return;
        }

        $columns = [];
        $params = [];
        $columnMap = [
            'company_name' => 'company_name',
            'short_name' => 'company_short_name',
            'contact' => 'contact_name',
            'phone' => 'contact_phone',
            'address' => 'address',
            'note' => 'remark',
        ];

        foreach ($columnMap as $key => $column) {
            if (!$this->columnExists($this->master(), 'tenants', $column)) {
                continue;
            }
            $columns[] = "{$column} = ?";
            $params[] = trim((string) ($company[$key] ?? ''));
        }

        if (!$columns) {
            return;
        }

        $params[] = $tenantId;
        $stmt = $this->master()->prepare('UPDATE tenants SET ' . implode(', ', $columns) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return $stmt->fetchColumn() !== false;
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function hashPassword(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algorithm);
    }

    /** @return array<string, mixed> */
    private function normalizeStoreApiConfig(mixed $value): array
    {
        $config = $this->jsonArray($value);
        if (!$config && is_string($value)) {
            foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $raw] = explode('=', $line, 2);
                $key = trim($key);
                if ($key !== '') {
                    $config[$key] = trim($raw);
                }
            }
        }

        foreach (['serviceSecret' => 'Secret', 'service_secret' => 'Secret', 'licenseKey' => 'Key', 'license_key' => 'Key'] as $from => $to) {
            if (($config[$to] ?? '') === '' && ($config[$from] ?? '') !== '') {
                $config[$to] = $config[$from];
            }
        }

        return array_filter($config, static fn (mixed $item): bool => $item !== null && $item !== '');
    }

    private function percentValue(mixed $value): float
    {
        $number = $this->moneyValue($value, 70.0);
        return max(0.0, min(100.0, $number));
    }

    private function shortText(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }

    /** @return array<string, mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) ($value ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<int, string> $keys */
    private function firstExtra(array $extra, array $keys, mixed $default = ''): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $extra) && $extra[$key] !== null && $extra[$key] !== '') {
                return $extra[$key];
            }
        }

        return $default;
    }

    private function moneyValue(mixed $value, float $default = 0.0): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^\d.\-]+/', '', str_replace(',', '', (string) ($value ?? '')));
        return is_numeric($normalized) ? (float) $normalized : $default;
    }

    /** @param array<int, string> $keys */
    private function firstMoney(array $extra, array $keys, float $default = 0.0): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $extra) || $extra[$key] === null || $extra[$key] === '') {
                continue;
            }

            return $this->moneyValue($extra[$key], $default);
        }

        return $default;
    }

    private function sqlDateTime(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || strtotime($raw) === false) {
            return null;
        }

        return date('Y-m-d H:i:s', strtotime($raw));
    }

    private function jsonValue(array $value): ?string
    {
        return $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    private function findPlatformOrderId(\PDO $pdo, string $platform, int $storeId, string $platformOrderId): int
    {
        if ($storeId > 0) {
            $stmt = $pdo->prepare('SELECT id FROM orders WHERE platform = ? AND store_id = ? AND platform_order_id = ? LIMIT 1');
            $stmt->execute([$platform, $storeId, $platformOrderId]);
        } else {
            $stmt = $pdo->prepare('SELECT id FROM orders WHERE platform = ? AND platform_order_id = ? LIMIT 1');
            $stmt->execute([$platform, $platformOrderId]);
        }

        return (int) ($stmt->fetchColumn() ?: 0);
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

    private function resolveImportStoreId(string $tenantKey, string $platform, int $selectedStoreId): int
    {
        if ($selectedStoreId > 0) {
            return $selectedStoreId;
        }

        foreach ($this->stores($tenantKey) as $store) {
            if ((string) ($store['platform'] ?? '') === $platform && ($store['status'] ?? 'visible') !== 'hidden') {
                return (int) ($store['id'] ?? 0);
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $base @param array<string, mixed> $incoming @return array<string, mixed> */
    private function mergeImportOrder(array $base, array $incoming): array
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
                $base['extra'] = array_replace(is_array($base['extra'] ?? null) ? $base['extra'] : [], $value);
                $base['platform_extra'] = array_replace(is_array($base['platform_extra'] ?? null) ? $base['platform_extra'] : [], $value);
                continue;
            }
            $base[$field] = $value;
        }

        return $base;
    }

    /** @param array<string, mixed> $order */
    private function normalizePlatformOrderPayload(array $order): array
    {
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $extra = is_array($order['platform_extra'] ?? null) ? $order['platform_extra'] : [];

        return [
            'platform' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($order['platform'] ?? '')) ?: '',
            'platform_order_id' => trim((string) ($order['platform_order_id'] ?? '')),
            'order_detail_id' => trim((string) ($order['order_detail_id'] ?? '')),
            'store_id' => max(0, (int) ($order['store_id'] ?? 0)),
            'order_date' => $this->sqlDateTime($order['order_date'] ?? null),
            'order_status' => trim((string) ($order['status'] ?? $order['order_status'] ?? '')),
            'customer_name' => trim((string) ($customer['name'] ?? '')),
            'customer_kana' => trim((string) ($customer['kana'] ?? '')),
            'customer_zip' => trim((string) ($customer['zip'] ?? '')),
            'customer_address' => trim((string) ($customer['address'] ?? '')),
            'customer_phone' => trim((string) ($customer['phone'] ?? '')),
            'customer_mail' => trim((string) ($customer['mail'] ?? '')),
            'pay_method' => trim((string) ($order['pay_method'] ?? '')),
            'ship_method' => trim((string) ($order['ship_method'] ?? '')),
            'total_item_price' => $this->moneyValue($order['total_item_price'] ?? 0),
            'postage_price' => $this->moneyValue($order['postage_price'] ?? 0),
            'pay_charge' => $this->moneyValue($order['pay_charge'] ?? 0),
            'total_price' => $this->moneyValue($order['total'] ?? $order['total_price'] ?? 0),
            'review_invited' => !empty($order['review_invited']) ? 1 : 0,
            'reviewed' => !empty($order['reviewed']) ? 1 : 0,
            'platform_extra' => $extra,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function insertPlatformOrder(\PDO $pdo, array $payload): int
    {
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO orders
(platform, platform_order_id, order_detail_id, store_id, order_date, order_status, customer_name, customer_kana, customer_zip, customer_address, customer_phone, customer_mail, pay_method, ship_method, total_item_price, postage_price, pay_charge, total_price, review_invited, reviewed, platform_extra)
VALUES
(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        $stmt->execute([
            $payload['platform'],
            $payload['platform_order_id'],
            $payload['order_detail_id'] !== '' ? $payload['order_detail_id'] : null,
            $payload['store_id'] > 0 ? $payload['store_id'] : null,
            $payload['order_date'],
            $payload['order_status'],
            $payload['customer_name'],
            $payload['customer_kana'],
            $payload['customer_zip'],
            $payload['customer_address'],
            $payload['customer_phone'],
            $payload['customer_mail'],
            $payload['pay_method'],
            $payload['ship_method'],
            $payload['total_item_price'],
            $payload['postage_price'],
            $payload['pay_charge'],
            $payload['total_price'],
            $payload['review_invited'],
            $payload['reviewed'],
            $this->jsonValue($payload['platform_extra']),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string, mixed> $payload */
    private function updatePlatformOrder(\PDO $pdo, int $orderId, array $payload): void
    {
        $stmt = $pdo->prepare(<<<'SQL'
UPDATE orders
SET order_detail_id = ?, order_date = ?, order_status = ?, customer_name = ?, customer_kana = ?, customer_zip = ?,
    customer_address = ?, customer_phone = ?, customer_mail = ?, pay_method = ?, ship_method = ?,
    total_item_price = ?, postage_price = ?, pay_charge = ?, total_price = ?, review_invited = ?, reviewed = ?,
    platform_extra = ?
WHERE id = ?
SQL);
        $stmt->execute([
            $payload['order_detail_id'] !== '' ? $payload['order_detail_id'] : null,
            $payload['order_date'],
            $payload['order_status'],
            $payload['customer_name'],
            $payload['customer_kana'],
            $payload['customer_zip'],
            $payload['customer_address'],
            $payload['customer_phone'],
            $payload['customer_mail'],
            $payload['pay_method'],
            $payload['ship_method'],
            $payload['total_item_price'],
            $payload['postage_price'],
            $payload['pay_charge'],
            $payload['total_price'],
            $payload['review_invited'],
            $payload['reviewed'],
            $this->jsonValue($payload['platform_extra']),
            $orderId,
        ]);
    }

    /** @param array<string, mixed> $item */
    private function normalizePlatformItemPayload(int $orderId, array $item, int $index): array
    {
        $extra = is_array($item['platform_extra'] ?? null) ? $item['platform_extra'] : [];
        $quantity = max(0, (int) ($item['quantity'] ?? 0));
        $unitPrice = $this->moneyValue($item['unit_price'] ?? 0);
        $postage = $this->moneyValue($item['postage_price'] ?? 0);
        $payCharge = $this->moneyValue($item['pay_charge'] ?? 0);
        $lineTotal = $this->moneyValue($item['line_total'] ?? 0);
        if ($lineTotal <= 0 && $unitPrice > 0) {
            $lineTotal = ($unitPrice * max(1, $quantity)) + $postage + $payCharge;
        }

        return [
            'order_id' => $orderId,
            'order_detail_id' => trim((string) ($item['order_detail_id'] ?? '')),
            'line_id' => trim((string) (($item['line_id'] ?? '') !== '' ? $item['line_id'] : (string) ($index + 1))),
            'source_type' => in_array(($item['source_type'] ?? 'pending'), ['cn_purchase', 'jp_stock', 'pending'], true) ? (string) $item['source_type'] : 'pending',
            'purchase_status' => trim((string) ($item['purchase_status'] ?? '未处理的订单')),
            'item_code' => trim((string) ($item['item_code'] ?? '')),
            'lot_number' => trim((string) ($item['lot_number'] ?? '')),
            'item_management_id' => trim((string) ($item['item_management_id'] ?? '')),
            'jp_warehouse_id' => trim((string) ($item['jp_warehouse_id'] ?? '')),
            'product_title' => trim((string) ($item['title'] ?? $item['product_title'] ?? '')),
            'item_option' => trim((string) ($item['option'] ?? $item['item_option'] ?? '')),
            'chinese_option' => trim((string) ($item['chinese_option'] ?? '')),
            'quantity' => $quantity,
            'weight' => $this->moneyValue($item['weight'] ?? 0),
            'material' => trim((string) ($item['material'] ?? '')),
            'unit_price' => $unitPrice,
            'postage_price' => $postage,
            'pay_charge' => $payCharge,
            'line_total' => $lineTotal,
            'amount' => $this->moneyValue($item['amount'] ?? 0),
            'item_comment' => trim((string) ($item['comment'] ?? $item['item_comment'] ?? '')),
            'caigou_user' => trim((string) ($item['buyer'] ?? $item['caigou_user'] ?? '')),
            'main_image' => trim((string) ($item['image'] ?? $item['main_image'] ?? '')),
            'sku_image' => trim((string) ($item['sku_image'] ?? '')),
            'platform_extra' => $extra,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function findPlatformOrderItemId(\PDO $pdo, int $orderId, array $payload): int
    {
        if ($payload['order_detail_id'] !== '') {
            $stmt = $pdo->prepare('SELECT id FROM order_items WHERE order_id = ? AND order_detail_id = ? LIMIT 1');
            $stmt->execute([$orderId, $payload['order_detail_id']]);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        if ($payload['line_id'] !== '') {
            $stmt = $pdo->prepare('SELECT id FROM order_items WHERE order_id = ? AND line_id = ? LIMIT 1');
            $stmt->execute([$orderId, $payload['line_id']]);
            $id = (int) ($stmt->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        $stmt = $pdo->prepare('SELECT id FROM order_items WHERE order_id = ? AND item_code = ? AND item_option = ? LIMIT 1');
        $stmt->execute([$orderId, $payload['item_code'], $payload['item_option']]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /** @param array<string, mixed> $payload */
    private function insertPlatformOrderItem(\PDO $pdo, array $payload): int
    {
        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO order_items
(order_id, order_detail_id, line_id, source_type, purchase_status, item_code, lot_number, item_management_id, jp_warehouse_id, product_title, item_option, chinese_option, quantity, weight, material, unit_price, postage_price, pay_charge, line_total, amount, item_comment, caigou_user, main_image, sku_image, platform_extra)
VALUES
(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);
        $stmt->execute([
            $payload['order_id'],
            $payload['order_detail_id'],
            $payload['line_id'],
            $payload['source_type'],
            $payload['purchase_status'],
            $payload['item_code'],
            $payload['lot_number'],
            $payload['item_management_id'],
            $payload['jp_warehouse_id'] !== '' ? $payload['jp_warehouse_id'] : null,
            $payload['product_title'],
            $payload['item_option'],
            $payload['chinese_option'],
            $payload['quantity'],
            $payload['weight'],
            $payload['material'],
            $payload['unit_price'],
            $payload['postage_price'],
            $payload['pay_charge'],
            $payload['line_total'],
            $payload['amount'],
            $payload['item_comment'],
            $payload['caigou_user'] !== '' ? $payload['caigou_user'] : null,
            $payload['main_image'],
            $payload['sku_image'],
            $this->jsonValue($payload['platform_extra']),
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** @param array<string, mixed> $payload */
    private function updatePlatformOrderItem(\PDO $pdo, int $itemId, array $payload): void
    {
        $stmt = $pdo->prepare(<<<'SQL'
UPDATE order_items
SET order_detail_id = ?, line_id = ?, item_code = ?, lot_number = ?, item_management_id = ?, product_title = ?,
    item_option = ?, chinese_option = ?, quantity = ?, weight = ?, material = ?, unit_price = ?, postage_price = ?,
    pay_charge = ?, line_total = ?, item_comment = ?, main_image = ?, sku_image = ?, platform_extra = ?
WHERE id = ?
SQL);
        $stmt->execute([
            $payload['order_detail_id'],
            $payload['line_id'],
            $payload['item_code'],
            $payload['lot_number'],
            $payload['item_management_id'],
            $payload['product_title'],
            $payload['item_option'],
            $payload['chinese_option'],
            $payload['quantity'],
            $payload['weight'],
            $payload['material'],
            $payload['unit_price'],
            $payload['postage_price'],
            $payload['pay_charge'],
            $payload['line_total'],
            $payload['item_comment'],
            $payload['main_image'],
            $payload['sku_image'],
            $this->jsonValue($payload['platform_extra']),
            $itemId,
        ]);
    }

    private function mergeOrderQuantityDetail(\PDO $pdo, int $orderId, string $quantityDetail): void
    {
        $stmt = $pdo->prepare('SELECT platform_extra FROM orders WHERE id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $extra = $this->jsonArray($stmt->fetchColumn() ?: null);
        $extra['QuantityDetail'] = $quantityDetail;
        $pdo->prepare('UPDATE orders SET platform_extra = ? WHERE id = ?')->execute([$this->jsonValue($extra), $orderId]);
    }

    /** @param array<string, mixed> $identity */
    private function findImportItemId(\PDO $pdo, array $identity): int
    {
        $ids = $this->findImportItemIds($pdo, $identity);
        return $ids[0] ?? 0;
    }

    /** @param array<string, mixed> $identity @return array<int, int> */
    private function findImportItemIds(\PDO $pdo, array $identity): array
    {
        $platform = trim((string) ($identity['platform'] ?? ''));
        $orderId = trim((string) ($identity['platform_order_id'] ?? ''));
        $orderDetailId = trim((string) ($identity['order_detail_id'] ?? ''));
        $lineId = trim((string) ($identity['line_id'] ?? ''));
        $itemCode = trim((string) ($identity['item_code'] ?? ''));

        if ($orderId === '') {
            return [];
        }

        $conditions = ['o.platform_order_id = ?'];
        $params = [$orderId];
        if ($platform !== '') {
            $conditions[] = 'o.platform = ?';
            $params[] = $platform;
        }
        if ($orderDetailId !== '') {
            $conditions[] = 'i.order_detail_id = ?';
            $params[] = $orderDetailId;
        }
        if ($lineId !== '') {
            $conditions[] = 'i.line_id = ?';
            $params[] = $lineId;
        }
        if ($itemCode !== '') {
            $conditions[] = '(i.item_code = ? OR i.lot_number = ? OR i.item_management_id = ?)';
            array_push($params, $itemCode, $itemCode, $itemCode);
        }

        $stmt = $pdo->prepare(
            'SELECT i.id FROM order_items i INNER JOIN orders o ON o.id = i.order_id WHERE ' . implode(' AND ', $conditions) . ' ORDER BY i.id'
        );
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<int, int> */
    private function findImportItemIdsByIntlNumber(\PDO $pdo, string $number): array
    {
        $number = trim($number);
        if ($number === '') {
            return [];
        }

        $stmt = $pdo->prepare('SELECT order_item_id FROM intl_shipments WHERE intl_number = ? OR intl_number LIKE ? OR intl_number LIKE ? OR intl_number LIKE ?');
        $stmt->execute([$number, $number . ',%', '%,' . $number, '%,' . $number . ',%']);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function mergeMysqlTrackingNumbers(\PDO $pdo, int $itemId, string $incoming): string
    {
        $incoming = trim($incoming);
        if ($incoming === '') {
            return '';
        }

        $stmt = $pdo->prepare('SELECT intl_number FROM intl_shipments WHERE order_item_id = ? LIMIT 1');
        $stmt->execute([$itemId]);
        $current = (string) ($stmt->fetchColumn() ?: '');
        $numbers = array_values(array_filter(array_map('trim', preg_split('/[,，\s]+/u', $current) ?: [])));
        if (!in_array($incoming, $numbers, true)) {
            $numbers[] = $incoming;
        }

        return implode(',', $numbers);
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

    /** @return array<string, mixed> */
    private function mapOrder(\PDO $pdo, array $row): array
    {
        $items = $this->itemsForOrder($pdo, (int) $row['id']);
        $extra = $this->jsonArray($row['platform_extra'] ?? null);
        $customerName = (string) (($row['customer_name'] ?? '') ?: $this->firstExtra($extra, ['ShipName', 'senderName']));
        $customerKana = (string) (($row['customer_kana'] ?? '') ?: $this->firstExtra($extra, ['senderKana']));
        $customerZip = (string) (($row['customer_zip'] ?? '') ?: $this->firstExtra($extra, ['ShipZipCode', 'senderZipCode', 'shipping_postal_code']));
        $customerAddress = (string) (($row['customer_address'] ?? '') ?: $this->firstExtra($extra, ['senderAddress', 'ShipAddress1', 'shipping_address_1']));
        $customerPhone = (string) (($row['customer_phone'] ?? '') ?: $this->firstExtra($extra, ['ShipPhoneNumber', 'senderPhoneNumber1']));
        $customerMail = (string) (($row['customer_mail'] ?? '') ?: $this->firstExtra($extra, ['BillMailAddress', 'mailAddress']));

        return [
            'id' => (int) $row['id'],
            'platform' => (string) $row['platform'],
            'platform_order_id' => (string) $row['platform_order_id'],
            'order_detail_id' => (string) ($row['order_detail_id'] ?? ''),
            'order_date' => (string) ($row['order_date'] ?? $row['imported_at'] ?? ''),
            'imported_at' => (string) ($row['imported_at'] ?? ''),
            'status' => (string) ($row['order_status'] ?? ''),
            'store' => (string) ($row['store_name'] ?? ''),
            'customer' => [
                'name' => $customerName,
                'kana' => $customerKana,
                'phone' => $customerPhone,
                'zip' => $customerZip,
                'address' => $customerAddress,
                'mail' => $customerMail,
            ],
            'pay_method' => (string) (($row['pay_method'] ?? '') ?: $this->firstExtra($extra, ['PayMethodName', 'settlementName'])),
            'ship_method' => (string) (($row['ship_method'] ?? '') ?: $this->firstExtra($extra, ['deliveryName'])),
            'total_item_price' => $this->moneyValue($row['total_item_price'] ?? $this->firstExtra($extra, ['totalItemPrice', 'QuantityDetail'])),
            'postage_price' => $this->moneyValue($row['postage_price'] ?? $this->firstExtra($extra, ['postagePrice', 'ShipCharge'])),
            'pay_charge' => $this->moneyValue($row['pay_charge'] ?? $this->firstExtra($extra, ['PayCharge'])),
            'total' => (float) ($row['total_price'] ?? 0),
            'review_invited' => !empty($row['review_invited']),
            'reviewed' => !empty($row['reviewed']),
            'items' => $items,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function itemsForOrder(\PDO $pdo, int $orderId): array
    {
        $stmt = $pdo->prepare(<<<'SQL'
SELECT i.*, p.tabaono, p.caigou_link, p.buhuo_link, p.caigou_user AS purchase_user, p.caigou_time,
       p.caigou_ordernums, p.cn_amount, p.com_amount, p.cn_ship_number,
       j.out_status, j.assignee, j.location, j.out_no, j.out_cost, j.out_time,
       d.ship_company, d.ship_number, d.ship_quantity, d.jpship_status, d.jpship_completed_at, d.logistic_trace,
       x.intl_number, x.intl_status, x.intl_fee, x.intl_qty, x.intl_weight, x.tranship_comment, x.comment AS intl_comment
FROM order_items i
LEFT JOIN purchases p ON p.order_item_id = i.id
LEFT JOIN jp_shipments j ON j.order_item_id = i.id
LEFT JOIN domestic_shipments d ON d.order_item_id = i.id
LEFT JOIN intl_shipments x ON x.order_item_id = i.id
WHERE i.order_id = ?
ORDER BY i.id
SQL);
        $stmt->execute([$orderId]);

        return array_map(function (array $row) use ($pdo, $orderId): array {
            $extra = $this->jsonArray($row['platform_extra'] ?? null);
            $quantity = (int) ($row['quantity'] ?? 0);
            $unitPrice = $this->moneyValue($row['unit_price'] ?? null);
            if ($unitPrice <= 0) {
                $unitPrice = $this->firstMoney($extra, ['UnitPrice', 'itemPrice']);
            }
            $postagePrice = $this->moneyValue($row['postage_price'] ?? null);
            if ($postagePrice <= 0) {
                $postagePrice = $this->firstMoney($extra, ['postagePrice', 'ShipCharge']);
            }
            $payCharge = $this->moneyValue($row['pay_charge'] ?? null);
            if ($payCharge <= 0) {
                $payCharge = $this->firstMoney($extra, ['PayCharge']);
            }
            $lineTotal = $this->moneyValue($row['line_total'] ?? null);
            if ($lineTotal <= 0) {
                $lineTotal = $this->firstMoney($extra, ['totalItemPrice', 'TotalPrice']);
            }
            if ($lineTotal <= 0 && $unitPrice > 0) {
                $lineTotal = ($unitPrice * max(1, $quantity)) + $postagePrice + $payCharge;
            }
            $purchaseAmount = $this->moneyValue($row['amount'] ?? null);

            return [
                'id' => (int) $row['id'],
                'order_detail_id' => (string) (($row['order_detail_id'] ?? '') ?: $this->firstExtra($extra, ['orderDetailId'])),
                'line_id' => (string) (($row['line_id'] ?? '') ?: $this->firstExtra($extra, ['LineId'])),
                'item_code' => (string) (($row['item_code'] ?? '') ?: $this->firstExtra($extra, ['ItemId', 'itemCode', 'itemManagementId', 'lotnumber'])),
                'lot_number' => (string) (($row['lot_number'] ?? '') ?: $this->firstExtra($extra, ['lotnumber'])),
                'item_management_id' => (string) (($row['item_management_id'] ?? '') ?: $this->firstExtra($extra, ['ItemManagerId', 'itemManagementId'])),
                'jp_warehouse_id' => (string) ($row['jp_warehouse_id'] ?? ''),
                'title' => (string) (($row['product_title'] ?? '') ?: $this->firstExtra($extra, ['product_title', 'ItemTitle', 'itemName'])),
                'option' => (string) (($row['item_option'] ?? '') ?: $this->firstExtra($extra, ['SubCodeOption', 'itemOption', 'selectedChoice'])),
                'chinese_option' => (string) (($row['chinese_option'] ?? '') ?: $this->firstExtra($extra, ['chinese_option'])),
                'quantity' => $quantity,
                'source_type' => (string) $row['source_type'],
                'purchase_status' => (string) $row['purchase_status'],
                'buyer' => (string) (($row['purchase_user'] ?? '') ?: ($row['caigou_user'] ?? '')),
                'purchase_time' => (string) ($row['caigou_time'] ?? ''),
                'purchase_link' => (string) ($row['caigou_link'] ?? ''),
                'buhuo_link' => (string) ($row['buhuo_link'] ?? ''),
                'amount' => $purchaseAmount,
                'purchase_amount' => $purchaseAmount,
                'cn_amount' => $this->moneyValue($row['cn_amount'] ?? null),
                'com_amount' => $this->moneyValue($row['com_amount'] ?? null),
                'caigou_ordernums' => (string) ($row['caigou_ordernums'] ?? ''),
                'unit_price' => $unitPrice,
                'postage_price' => $postagePrice,
                'pay_charge' => $payCharge,
                'line_total' => $lineTotal,
                'material' => (string) ($row['material'] ?? ''),
                'weight' => $this->moneyValue($row['weight'] ?? null),
                'comment' => (string) (($row['item_comment'] ?? '') ?: $this->firstExtra($extra, ['comment'])),
                'tabaono' => (string) ($row['tabaono'] ?? ''),
                'ship_company' => (string) ($row['ship_company'] ?? ''),
                'ship_number' => (string) (($row['cn_ship_number'] ?? '') ?: ($row['ship_number'] ?? '')),
                'ship_quantity' => (int) ($row['ship_quantity'] ?? 0),
                'logistics' => (string) ($row['jpship_status'] ?? ''),
                'logistic_trace' => (string) ($row['logistic_trace'] ?? ''),
                'jpship_completed_at' => (string) ($row['jpship_completed_at'] ?? ''),
                'assignee' => $row['assignee'] ?? null,
                'out_status' => $row['out_status'] ?? null,
                'out_time' => (string) ($row['out_time'] ?? ''),
                'location' => (string) ($row['location'] ?? ''),
                'out_no' => (string) ($row['out_no'] ?? ''),
                'out_cost' => $this->moneyValue($row['out_cost'] ?? null),
                'intl_number' => (string) ($row['intl_number'] ?? ''),
                'intl_status' => (string) ($row['intl_status'] ?? ''),
                'intl_fee' => $this->moneyValue($row['intl_fee'] ?? null),
                'intl_qty' => (int) ($row['intl_qty'] ?? 0),
                'intl_weight' => $this->moneyValue($row['intl_weight'] ?? null),
                'tranship_comment' => (string) ($row['tranship_comment'] ?? ''),
                'intl_comment' => (string) ($row['intl_comment'] ?? ''),
                'image' => (string) (($row['main_image'] ?? '') ?: $this->firstExtra($extra, ['zhutu'], '/assets/no-image.svg')),
                'logs' => $this->logsForItem($pdo, $orderId, (int) $row['id']),
            ];
        }, $stmt->fetchAll());
    }

    /** @return array<int, array<string, mixed>> */
    private function logsForItem(\PDO $pdo, int $orderId, int $itemId): array
    {
        $stmt = $pdo->prepare(
            'SELECT operator, action_type, field_name, old_value, new_value, ip, created_at FROM order_logs WHERE order_id = ? AND (order_item_id = ? OR order_item_id IS NULL) ORDER BY created_at DESC LIMIT 20'
        );
        $stmt->execute([$orderId, $itemId]);

        return array_map(fn (array $row): array => [
            'time' => date('m-d H:i', strtotime((string) $row['created_at'])),
            'user' => (string) $row['operator'],
            'action' => (string) $row['action_type'],
            'field' => (string) $row['field_name'],
            'old' => (string) ($row['old_value'] ?? '-'),
            'new' => (string) ($row['new_value'] ?? '-'),
            'ip' => (string) $row['ip'],
        ], $stmt->fetchAll());
    }

    private function ensureChildRow(\PDO $pdo, string $table, int $itemId): void
    {
        $count = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE order_item_id = ?");
        $count->execute([$itemId]);
        if ((int) $count->fetchColumn() > 0) {
            return;
        }

        $insert = $pdo->prepare("INSERT INTO {$table} (order_item_id) VALUES (?)");
        $insert->execute([$itemId]);
    }

    /**
     * @param array<int, int> $itemIds
     * @param array<int, int> $orderIds
     * @return array<int, int>
     */
    private function resolveItemIds(\PDO $pdo, array $itemIds, array $orderIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!$itemIds && !$orderIds) {
            return [];
        }

        $clauses = [];
        $params = [];
        if ($itemIds) {
            $clauses[] = 'id IN (' . implode(',', array_fill(0, count($itemIds), '?')) . ')';
            array_push($params, ...$itemIds);
        }
        if ($orderIds) {
            $clauses[] = 'order_id IN (' . implode(',', array_fill(0, count($orderIds), '?')) . ')';
            array_push($params, ...$orderIds);
        }

        $stmt = $pdo->prepare('SELECT id FROM order_items WHERE ' . implode(' OR ', $clauses));
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed>|null */
    private function itemSnapshot(\PDO $pdo, int $itemId): ?array
    {
        $stmt = $pdo->prepare(<<<'SQL'
SELECT i.id, i.order_id, i.source_type, i.purchase_status, i.jp_warehouse_id, i.amount,
       i.material, i.weight, i.chinese_option, i.item_comment AS comment,
       p.tabaono, p.caigou_link AS purchase_link, p.buhuo_link, p.caigou_user AS buyer,
       p.caigou_time AS purchase_time, p.caigou_ordernums, p.cn_amount, p.com_amount, p.cn_ship_number,
       j.out_status, j.assignee,
       d.ship_company, COALESCE(NULLIF(p.cn_ship_number, ''), d.ship_number) AS ship_number,
       d.ship_quantity, d.jpship_status AS logistics, d.logistic_trace,
       x.intl_number, x.intl_status, x.intl_fee, x.intl_qty, x.intl_weight, x.tranship_comment, x.comment AS intl_comment
FROM order_items i
LEFT JOIN purchases p ON p.order_item_id = i.id
LEFT JOIN jp_shipments j ON j.order_item_id = i.id
LEFT JOIN domestic_shipments d ON d.order_item_id = i.id
LEFT JOIN intl_shipments x ON x.order_item_id = i.id
WHERE i.id = ?
LIMIT 1
SQL);
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @param array<string, mixed> $changes */
    private function updateOrderItemData(\PDO $pdo, int $itemId, array $changes, string $action, string $operator = '系统管理员'): void
    {
        $snapshot = $this->itemSnapshot($pdo, $itemId);
        if (!$snapshot) {
            return;
        }

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

        $pdo->beginTransaction();
        try {
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

                $oldValue = $snapshot[$field] ?? ($field === 'ship_number' ? ($snapshot['cn_ship_number'] ?? '') : '');
                if ((string) $oldValue === (string) $newValue) {
                    continue;
                }

                $this->writeItemField($pdo, $itemId, $field, $newValue);
                $this->insertItemLog($pdo, (int) $snapshot['order_id'], $itemId, $action, $field, (string) $oldValue, (string) $newValue, $operator);
                $snapshot[$field] = $newValue;

                if ($field === 'source_type') {
                    $nextStatus = match ((string) $newValue) {
                        'cn_purchase' => '国内采购-准备',
                        'jp_stock' => '待分配',
                        default => '待处理',
                    };
                    $this->writeItemField($pdo, $itemId, 'purchase_status', $nextStatus);
                }
            }

            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }

    private function writeItemField(\PDO $pdo, int $itemId, string $field, mixed $value): void
    {
        match ($field) {
            'source_type', 'purchase_status', 'amount', 'jp_warehouse_id' => $this->updateOrderItemColumn($pdo, $itemId, $field, $value),
            'buyer' => $this->updatePurchaseColumn($pdo, $itemId, 'caigou_user', $value),
            'purchase_time' => $this->updatePurchaseColumn($pdo, $itemId, 'caigou_time', $value !== '' ? $value : null),
            'purchase_link' => $this->updatePurchaseColumn($pdo, $itemId, 'caigou_link', $value),
            'buhuo_link' => $this->updatePurchaseColumn($pdo, $itemId, 'buhuo_link', $value),
            'tabaono' => $this->updatePurchaseColumn($pdo, $itemId, 'tabaono', $value),
            'caigou_ordernums' => $this->updatePurchaseColumn($pdo, $itemId, 'caigou_ordernums', $value),
            'cn_amount' => $this->updatePurchaseColumn($pdo, $itemId, 'cn_amount', $value),
            'com_amount' => $this->updatePurchaseColumn($pdo, $itemId, 'com_amount', $value),
            'ship_number' => $this->updateShipNumber($pdo, $itemId, $value),
            'ship_company' => $this->updateDomesticColumn($pdo, $itemId, 'ship_company', $value),
            'ship_quantity' => $this->updateDomesticColumn($pdo, $itemId, 'ship_quantity', $value),
            'logistics' => $this->updateDomesticColumn($pdo, $itemId, 'jpship_status', $value),
            'logistic_trace' => $this->updateDomesticColumn($pdo, $itemId, 'logistic_trace', $value),
            'material' => $this->updateOrderItemColumn($pdo, $itemId, 'material', $value),
            'weight' => $this->updateOrderItemColumn($pdo, $itemId, 'weight', $value),
            'chinese_option' => $this->updateOrderItemColumn($pdo, $itemId, 'chinese_option', $value),
            'comment' => $this->updateOrderItemColumn($pdo, $itemId, 'item_comment', $value),
            'assignee' => $this->updateJpColumn($pdo, $itemId, 'assignee', $value),
            'out_status' => $this->updateJpColumn($pdo, $itemId, 'out_status', $value),
            'intl_number' => $this->updateIntlColumn($pdo, $itemId, 'intl_number', $value),
            'intl_status' => $this->updateIntlColumn($pdo, $itemId, 'intl_status', $value),
            'intl_fee' => $this->updateIntlColumn($pdo, $itemId, 'intl_fee', $value),
            'intl_qty' => $this->updateIntlColumn($pdo, $itemId, 'intl_qty', $value),
            'intl_weight' => $this->updateIntlColumn($pdo, $itemId, 'intl_weight', $value),
            'tranship_comment' => $this->updateIntlColumn($pdo, $itemId, 'tranship_comment', $value),
            'intl_comment' => $this->updateIntlColumn($pdo, $itemId, 'comment', $value),
            default => null,
        };
    }

    private function updateOrderItemColumn(\PDO $pdo, int $itemId, string $column, mixed $value): void
    {
        $stmt = $pdo->prepare("UPDATE order_items SET {$column} = ? WHERE id = ?");
        $stmt->execute([$value, $itemId]);
    }

    private function updatePurchaseColumn(\PDO $pdo, int $itemId, string $column, mixed $value): void
    {
        $this->ensureChildRow($pdo, 'purchases', $itemId);
        $stmt = $pdo->prepare("UPDATE purchases SET {$column} = ? WHERE order_item_id = ?");
        $stmt->execute([$value, $itemId]);
    }

    private function updateDomesticColumn(\PDO $pdo, int $itemId, string $column, mixed $value): void
    {
        $this->ensureChildRow($pdo, 'domestic_shipments', $itemId);
        $stmt = $pdo->prepare("UPDATE domestic_shipments SET {$column} = ? WHERE order_item_id = ? LIMIT 1");
        $stmt->execute([$value, $itemId]);
    }

    private function updateShipNumber(\PDO $pdo, int $itemId, mixed $value): void
    {
        $this->updatePurchaseColumn($pdo, $itemId, 'cn_ship_number', $value);
        $this->updateDomesticColumn($pdo, $itemId, 'ship_number', $value);
    }

    private function updateJpColumn(\PDO $pdo, int $itemId, string $column, mixed $value): void
    {
        $this->ensureChildRow($pdo, 'jp_shipments', $itemId);
        $stmt = $pdo->prepare("UPDATE jp_shipments SET {$column} = ? WHERE order_item_id = ?");
        $stmt->execute([$value, $itemId]);
    }

    private function updateIntlColumn(\PDO $pdo, int $itemId, string $column, mixed $value): void
    {
        $this->ensureChildRow($pdo, 'intl_shipments', $itemId);
        $stmt = $pdo->prepare("UPDATE intl_shipments SET {$column} = ? WHERE order_item_id = ?");
        $stmt->execute([$value, $itemId]);
    }

    private function insertItemLog(\PDO $pdo, int $orderId, int $itemId, string $action, string $field, string $oldValue, string $newValue, string $operator = '系统管理员'): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO order_logs (order_id, order_item_id, operator, action_type, field_name, old_value, new_value, ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $orderId,
            $itemId,
            $operator,
            $action,
            $field,
            $oldValue,
            $newValue,
            $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        ]);
    }

    private function platformColor(string $code): string
    {
        return [
            'y' => '#ef4444',
            'r' => '#2563eb',
            'w' => '#14b8a6',
            'm' => '#06b6d4',
            'q' => '#8b5cf6',
            'yp' => '#64748b',
        ][$code] ?? '#64748b';
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
            ],
            'logistics' => [
                'domestic_receive_places' => '',
                'carrier_mapping' => '',
                'tracking_prefix_mapping' => '',
            ],
            'api_1688' => [
                'enabled' => false,
                'config_file' => 'storage/tenants/' . $this->tenantStorageKey((string) ($tenant['key'] ?? '')) . '/config/1688/apikeys.conf',
                'config_content' => '',
            ],
            'updated_at' => '',
        ];
    }

    private function tenantStorageKey(string $tenantKey): string
    {
        $tenantKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantKey) ?? '';
        return $tenantKey !== '' ? $tenantKey : 'erp';
    }

    /**
     * @param mixed $overrides
     * @return array<int, string>
     */
    private function permissionsForRole(string $role, mixed $overrides = []): array
    {
        $defaults = Permission::roleDefaults();

        $permissions = $defaults[$role] ?? $defaults['客服'];
        $extra = array_values(array_filter(array_map('trim', (array) $overrides)));
        return array_values(array_unique(array_merge($permissions, $extra)));
    }
}
