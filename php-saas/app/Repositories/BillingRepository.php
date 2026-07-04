<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

final class BillingRepository extends BaseRepository
{


    /** @return array<string, mixed> */
    public function tenantBillingAccount(string $tenantKey): array
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null || !$this->tableExists($this->db->master(), 'tenant_billing_accounts')) {
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
        $balanceColumn = $this->columnExists($this->db->master(), 'tenant_billing_accounts', 'balance_points')
            ? 'balance_points'
            : 'FLOOR(balance_cents / 100)';
        $stmt = $this->db->master()->prepare("SELECT {$balanceColumn} AS balance, updated_at FROM tenant_billing_accounts WHERE tenant_id = ? LIMIT 1");
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
        if ($tenantId === null || !$this->tableExists($this->db->master(), 'tenant_billing_ledger')) {
            return [];
        }

        $amountColumn = $this->columnExists($this->db->master(), 'tenant_billing_ledger', 'amount_points')
            ? 'l.amount_points'
            : 'FLOOR(l.amount_cents / 100)';
        $balanceColumn = $this->columnExists($this->db->master(), 'tenant_billing_ledger', 'balance_after_points')
            ? 'l.balance_after_points'
            : 'FLOOR(l.balance_after_cents / 100)';
        $hasOperator = $this->columnExists($this->db->master(), 'tenant_billing_ledger', 'operator');
        $operatorSelect = $hasOperator ? 'l.operator' : "'' AS operator";
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->master()->prepare(
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
        if ($tenantId === null || !$this->tableExists($this->db->master(), 'tenant_billing_subscriptions')) {
            return [];
        }

        $stmt = $this->db->master()->prepare(
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

        if (!$this->tableExists($this->db->master(), 'tenant_billing_subscriptions')) {
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



    public function ensureBillingAccount(int $tenantId): void
    {
        if ($tenantId <= 0 || !$this->tableExists($this->db->master(), 'tenant_billing_accounts')) {
            return;
        }

        $columns = ['tenant_id'];
        $values = ['?'];
        $params = [$tenantId];
        if ($this->columnExists($this->db->master(), 'tenant_billing_accounts', 'balance_points')) {
            $columns[] = 'balance_points';
            $values[] = '0';
        } elseif ($this->columnExists($this->db->master(), 'tenant_billing_accounts', 'balance_cents')) {
            $columns[] = 'balance_cents';
            $values[] = '0';
        }

        $this->db->master()
            ->prepare('INSERT IGNORE INTO tenant_billing_accounts (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')')
            ->execute($params);
    }



    public function createStoreBillingSubscription(string $tenantKey, int $storeId, string $storeName): void
    {
        $tenantId = $this->tenantId($tenantKey);
        if ($tenantId === null || $storeId <= 0 || !$this->tableExists($this->db->master(), 'tenant_billing_subscriptions')) {
            return;
        }

        $startDate = new \DateTimeImmutable('today');
        $nextCharge = $this->nextMonthlyDate($startDate);
        $this->db->master()->prepare(
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
        if ($subscriptionId <= 0 || !$this->tableExists($this->db->master(), 'tenant_billing_subscriptions')) {
            return;
        }

        $this->db->master()->prepare(
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
        if ($tenantId === null || !$this->tableExists($this->db->master(), 'tenant_billing_accounts')) {
            return false;
        }

        $pdo = $this->db->master();
        $this->ensureBillingAccount($tenantId);
        $usesPoints = $this->columnExists($pdo, 'tenant_billing_accounts', 'balance_points');
        $accountColumn = $usesPoints ? 'balance_points' : 'balance_cents';
        $amountForDb = $usesPoints ? $amount : $amount * 100;

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare("SELECT {$accountColumn} AS balance FROM tenant_billing_accounts WHERE tenant_id = ? FOR UPDATE");
            $stmt->execute([$tenantId]);
            $currentRaw = (int) ($stmt->fetchColumn() ?: 0);
            $currentPoints = $usesPoints ? $currentRaw : intdiv($currentRaw, 100);
            $nextPoints = $currentPoints + $amount;
            if ($amount < 0 && ($requireEnough || !$allowDebt) && $nextPoints < 0) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
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

            if ($ownsTransaction) {
                $pdo->commit();
            }
            $balanceAfterOut = $nextPoints;
            return true;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
