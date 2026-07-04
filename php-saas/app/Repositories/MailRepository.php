<?php

declare(strict_types=1);

namespace Xizhen\Repositories;

final class MailRepository extends BaseRepository
{


    /** @return array<int, array<string, mixed>> */
    public function mailAccounts(string $tenantKey): array
    {
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
            return [];
        }

        return $pdo->query(
            'SELECT a.*, (SELECT COUNT(*) FROM ph_mail_folder f WHERE f.account_id = a.id AND f.sync_enabled = 1) AS synced_folders FROM ph_mail_account a ORDER BY a.sort, a.id'
        )->fetchAll();
    }



    /** @return array<string, mixed>|null */
    public function mailAccount(string $tenantKey, int $accountId): ?array
    {
        if ($accountId <= 0) {
            return null;
        }
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        if ($accountId <= 0) {
            return;
        }
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        if ($folderId <= 0) {
            return null;
        }
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        $pdo = $this->mailPdo($tenantKey);
        $account = $this->mailAccount($tenantKey, $accountId);
        if (!$pdo || !$account) {
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
        if ($folderId <= 0) {
            return;
        }
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        if ($messageId <= 0) {
            return null;
        }
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        $pdo = $this->mailPdo($tenantKey);
        $account = $this->mailAccount($tenantKey, $accountId);
        if (!$pdo || !$account) {
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
        if ($folderId <= 0) {
            return;
        }
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        if ($accountId <= 0) {
            return;
        }
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
        if (!$ids || !$changes) {
            return 0;
        }
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        if ($ruleId <= 0) {
            return;
        }
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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
        $pdo = $this->mailPdo($tenantKey);
        if (!$pdo) {
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



    private function mailPdo(string $tenantKey): ?\PDO
    {
        $pdo = $this->db->tenantPdo($tenantKey);
        if (!$pdo) {
            return null;
        }

        $missing = [];
        foreach (self::MAIL_TABLES as $table) {
            if (!$this->tableExists($pdo, $table)) {
                $missing[] = $table;
            }
        }
        if ($missing) {
            throw new \RuntimeException('邮件中心 MySQL 表未建：' . implode(', ', $missing) . '。请先执行 migrations/tenant/0006_create_mail_tables.sql。');
        }

        return $pdo;
    }
}
