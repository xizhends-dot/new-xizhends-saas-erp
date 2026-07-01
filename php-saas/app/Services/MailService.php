<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class MailService
{
    private const DEFAULT_LIMIT = 200;
    private const BODY_MAX_BYTES = 2000000;

    public function __construct(private readonly StoreInterface $store)
    {
    }

    /** @param array<string, mixed> $filters */
    public function pageData(string $tenantKey, array $filters, int $page, int $selectedId = 0, bool $loadMessages = true): array
    {
        $accounts = $this->store->mailAccounts($tenantKey);
        $folders = $this->store->mailFolders($tenantKey);
        $counts = $this->store->mailFolderCounts($tenantKey);
        $messages = $loadMessages
            ? $this->store->mailMessages($tenantKey, $filters, $page, 30)
            : ['rows' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
        $selected = $loadMessages
            ? ($selectedId > 0 ? $this->store->mailMessage($tenantKey, $selectedId) : ($messages['rows'][0] ?? null))
            : null;
        $body = null;
        if ($selected) {
            $body = $this->loadBody($tenantKey, (int) ($selected['id'] ?? 0), $selectedId > 0);
            $selected = $this->store->mailMessage($tenantKey, (int) ($selected['id'] ?? 0)) ?? $selected;
        }

        return [
            'accounts' => $accounts,
            'folders' => $folders,
            'folderTree' => $this->folderTree($accounts, $folders),
            'counts' => $counts,
            'messages' => $messages,
            'selected' => $selected,
            'body' => $body,
            'rules' => $this->store->mailRules($tenantKey),
            'imapAvailable' => $this->imapAvailable(),
            'message' => trim((string) ($_GET['message'] ?? '')),
            'error' => trim((string) ($_GET['error'] ?? '')),
        ];
    }

    /** @param array<string, mixed> $post */
    public function saveAccount(string $tenantKey, array $post): int
    {
        $id = (int) ($post['id'] ?? 0);
        $existing = $id > 0 ? ($this->store->mailAccount($tenantKey, $id) ?? []) : [];
        $imapPass = trim((string) ($post['imap_pass'] ?? ''));
        $smtpPass = trim((string) ($post['smtp_pass'] ?? ''));

        return $this->store->saveMailAccount($tenantKey, [
            'id' => $id,
            'shop_dpqz' => $post['shop_dpqz'] ?? '',
            'shop_name' => $post['shop_name'] ?? '',
            'platform' => $post['platform'] ?? '',
            'imap_host' => $post['imap_host'] ?? '',
            'imap_port' => $post['imap_port'] ?? 993,
            'imap_ssl' => isset($post['imap_ssl']) ? 1 : 0,
            'imap_user' => $post['imap_user'] ?? '',
            'imap_pass' => $imapPass !== '' ? $this->pwdEncode($imapPass) : ($existing['imap_pass'] ?? ''),
            'smtp_host' => $post['smtp_host'] ?? '',
            'smtp_port' => $post['smtp_port'] ?? 465,
            'smtp_secure' => $post['smtp_secure'] ?? 'ssl',
            'smtp_user' => $post['smtp_user'] ?? '',
            'smtp_pass' => $smtpPass !== '' ? $this->pwdEncode($smtpPass) : ($existing['smtp_pass'] ?? ''),
            'sent_folder' => $post['sent_folder'] ?? 'Sent',
            'enabled' => isset($post['enabled']) ? 1 : 0,
            'sort' => $post['sort'] ?? ($existing['sort'] ?? 0),
        ]);
    }

    public function deleteAccount(string $tenantKey, int $accountId): void
    {
        $this->store->deleteMailAccount($tenantKey, $accountId);
    }

    /** @return array{ok: bool, message: string, count: int} */
    public function probeFolders(string $tenantKey, int $accountId): array
    {
        $account = $this->store->mailAccount($tenantKey, $accountId);
        if (!$account) {
            return ['ok' => false, 'message' => '邮箱账号不存在', 'count' => 0];
        }
        if (!$this->imapAvailable()) {
            return ['ok' => false, 'message' => '服务器未启用 PHP imap 扩展', 'count' => 0];
        }
        if ($this->hostIsInternal((string) ($account['imap_host'] ?? ''))) {
            return ['ok' => false, 'message' => 'IMAP 主机解析到内网或不可解析，已拦截', 'count' => 0];
        }

        $folders = $this->listFolders($account);
        if ($folders === null) {
            return ['ok' => false, 'message' => '读取文件夹失败：' . $this->imapLastError(), 'count' => 0];
        }
        $this->store->upsertMailFolders($tenantKey, $accountId, $folders);

        return ['ok' => true, 'message' => '已读取邮箱文件夹 ' . count($folders) . ' 个', 'count' => count($folders)];
    }

    /** @param array<string, mixed> $post */
    public function saveFolder(string $tenantKey, array $post): void
    {
        $this->store->updateMailFolder($tenantKey, (int) ($post['folder_id'] ?? 0), [
            'display_name' => $post['display_name'] ?? '',
            'role' => $post['role'] ?? 'custom',
            'sync_enabled' => isset($post['sync_enabled']) ? 1 : 0,
            'sort' => $post['sort'] ?? 0,
        ]);
    }

    /** @return array{ok: bool, message: string, new: int, errors: array<int, string>} */
    public function sync(string $tenantKey, int $accountId = 0, int $folderId = 0, int $limit = self::DEFAULT_LIMIT): array
    {
        if (!$this->imapAvailable()) {
            return ['ok' => false, 'message' => '服务器未启用 PHP imap 扩展，无法同步', 'new' => 0, 'errors' => []];
        }
        $limit = max(1, min(500, $limit));
        $accounts = $accountId > 0
            ? array_values(array_filter([$this->store->mailAccount($tenantKey, $accountId)]))
            : $this->store->mailAccounts($tenantKey);
        $totalNew = 0;
        $errors = [];
        $folderCount = 0;

        foreach ($accounts as $account) {
            if (!$account || (int) ($account['enabled'] ?? 0) !== 1) {
                continue;
            }
            if ($this->hostIsInternal((string) ($account['imap_host'] ?? ''))) {
                $errors[] = $this->accountLabel($account) . '：IMAP 主机解析到内网或不可解析';
                continue;
            }
            $folders = $folderId > 0
                ? array_values(array_filter([$this->store->mailFolder($tenantKey, $folderId)]))
                : $this->store->mailFolders($tenantKey, (int) ($account['id'] ?? 0), true);
            foreach ($folders as $folder) {
                if (!$folder || (int) ($folder['account_id'] ?? 0) !== (int) ($account['id'] ?? 0)) {
                    continue;
                }
                $folderCount++;
                $result = $this->syncFolder($tenantKey, $account, $folder, $limit);
                $totalNew += $result['new'];
                array_push($errors, ...$result['errors']);
                if ($result['inserted_ids']) {
                    $rule = $this->applyRulesToMessages($tenantKey, $account, $result['inserted_ids']);
                    array_push($errors, ...$rule['errors']);
                }
            }
            $this->store->updateMailAccountLastSync($tenantKey, (int) ($account['id'] ?? 0));
        }

        $message = "同步完成：{$folderCount} 个文件夹，新增 {$totalNew} 封邮件";
        if ($errors) {
            $message .= '，有 ' . count($errors) . ' 个错误';
        }

        return ['ok' => !$errors, 'message' => $message, 'new' => $totalNew, 'errors' => $errors];
    }

    /** @return array{ok: bool, body_text: string, body_html: string, attachments: array<int, mixed>, cc_addr: string, error: string} */
    public function loadBody(string $tenantKey, int $messageId, bool $markRead = true): array
    {
        $message = $this->store->mailMessage($tenantKey, $messageId);
        if (!$message) {
            return ['ok' => false, 'body_text' => '', 'body_html' => '', 'attachments' => [], 'cc_addr' => '', 'error' => '邮件不存在'];
        }

        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
        if ((int) ($message['body_loaded'] ?? 0) === 1) {
            if ($markRead && (int) ($message['is_read'] ?? 0) === 0) {
                $this->store->updateMailMessages($tenantKey, [$messageId], ['is_read' => 1]);
            }
            return [
                'ok' => true,
                'body_text' => (string) ($message['body_text'] ?? ''),
                'body_html' => (string) ($message['body_html'] ?? ''),
                'attachments' => $attachments,
                'cc_addr' => (string) ($message['cc_addr'] ?? ''),
                'error' => '',
            ];
        }

        if (!$this->imapAvailable()) {
            return [
                'ok' => false,
                'body_text' => (string) ($message['body_text'] ?? ''),
                'body_html' => (string) ($message['body_html'] ?? ''),
                'attachments' => $attachments,
                'cc_addr' => (string) ($message['cc_addr'] ?? ''),
                'error' => '服务器未启用 PHP imap 扩展，无法加载正文',
            ];
        }

        $account = $this->store->mailAccount($tenantKey, (int) ($message['account_id'] ?? 0));
        $folder = $this->store->mailFolder($tenantKey, (int) ($message['folder_id'] ?? 0));
        if (!$account || !$folder) {
            return ['ok' => false, 'body_text' => '', 'body_html' => '', 'attachments' => [], 'cc_addr' => '', 'error' => '邮箱账号或文件夹不存在'];
        }

        $imap = $this->imapConnect($account, (string) ($folder['imap_path'] ?? 'INBOX'));
        if (!$imap) {
            return ['ok' => false, 'body_text' => '', 'body_html' => '', 'attachments' => [], 'cc_addr' => '', 'error' => '连接邮箱失败：' . $this->imapLastError()];
        }
        $body = $this->fetchOneBody($imap, (int) ($message['uid'] ?? 0));
        if ($markRead) {
            @imap_setflag_full($imap, (string) (int) ($message['uid'] ?? 0), '\\Seen', ST_UID);
        }
        @imap_close($imap);

        $this->store->saveMailMessageBody($tenantKey, $messageId, [
            'body_text' => $body['text'],
            'body_html' => $body['html'],
            'cc_addr' => $body['cc'],
            'has_attachment' => $body['has_attachment'] ? 1 : 0,
            'attachments' => $body['attachments'],
        ]);
        $this->store->updateMailMessages($tenantKey, [$messageId], ['is_read' => 1]);

        return [
            'ok' => true,
            'body_text' => $body['text'],
            'body_html' => $body['html'],
            'attachments' => $body['attachments'],
            'cc_addr' => $body['cc'],
            'error' => '',
        ];
    }

    /** @param array<int, int> $messageIds */
    public function mark(string $tenantKey, array $messageIds, string $action): int
    {
        $changes = match ($action) {
            'read' => ['is_read' => 1],
            'unread' => ['is_read' => 0],
            'important' => ['is_important' => 1],
            'unimportant' => ['is_important' => 0],
            'delete' => ['is_deleted' => 1],
            default => [],
        };

        return $changes ? $this->store->updateMailMessages($tenantKey, $messageIds, $changes) : 0;
    }

    /** @param array<int, int> $messageIds */
    public function move(string $tenantKey, array $messageIds, int $targetFolderId): array
    {
        $target = $this->store->mailFolder($tenantKey, $targetFolderId);
        if (!$target) {
            return ['ok' => false, 'message' => '目标文件夹不存在'];
        }
        $moved = 0;
        $errors = [];
        $byAccountFolder = [];
        foreach ($messageIds as $messageId) {
            $message = $this->store->mailMessage($tenantKey, (int) $messageId);
            if (!$message || (int) ($message['folder_id'] ?? 0) === $targetFolderId) {
                continue;
            }
            if ((int) ($message['account_id'] ?? 0) !== (int) ($target['account_id'] ?? 0)) {
                $errors[] = '邮件#' . (int) $messageId . ' 与目标文件夹不属于同一个邮箱账号';
                continue;
            }
            $key = (int) ($message['account_id'] ?? 0) . ':' . (int) ($message['folder_id'] ?? 0);
            $byAccountFolder[$key]['messages'][] = $message;
        }

        foreach ($byAccountFolder as $group) {
            $first = $group['messages'][0] ?? [];
            $account = $this->store->mailAccount($tenantKey, (int) ($first['account_id'] ?? 0));
            $source = $this->store->mailFolder($tenantKey, (int) ($first['folder_id'] ?? 0));
            if (!$account || !$source) {
                continue;
            }
            $ids = array_map(fn (array $m): int => (int) $m['id'], $group['messages']);
            $uids = array_map(fn (array $m): int => (int) $m['uid'], $group['messages']);
            $canMoveRemote = $this->imapAvailable() && !$this->hostIsInternal((string) ($account['imap_host'] ?? ''));
            if ($canMoveRemote) {
                $remote = $this->imapMove($account, (string) ($source['imap_path'] ?? ''), $uids, (string) ($target['imap_path'] ?? ''));
                if (!$remote['ok']) {
                    $errors[] = $remote['error'];
                    continue;
                }
                $this->store->updateMailMessages($tenantKey, $ids, ['is_deleted' => 1]);
            } else {
                $this->store->updateMailMessages($tenantKey, $ids, ['folder_id' => $targetFolderId]);
            }
            $moved += count($ids);
        }

        return ['ok' => !$errors, 'message' => $errors ? implode('；', $errors) : "已移动 {$moved} 封邮件", 'moved' => $moved];
    }

    /** @param array<string, mixed> $post */
    public function saveRule(string $tenantKey, array $post): int
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', (array) ($post['account_ids'] ?? [])))));
        return $this->store->saveMailRule($tenantKey, [
            'id' => $post['id'] ?? 0,
            'name' => $post['name'] ?? '',
            'apply_all' => isset($post['apply_all']) ? 1 : 0,
            'account_ids' => $accountIds,
            'platforms' => implode(',', array_values(array_filter(array_map('trim', (array) ($post['platforms'] ?? []))))),
            'priority' => $post['priority'] ?? 0,
            'enabled' => isset($post['enabled']) ? 1 : 0,
            'match_from' => $post['match_from'] ?? '',
            'match_subject' => $post['match_subject'] ?? '',
            'match_to' => $post['match_to'] ?? '',
            'target_folder_name' => $post['target_folder_name'] ?? '',
            'auto_create_folder' => isset($post['auto_create_folder']) ? 1 : 0,
            'mark_read' => isset($post['mark_read']) ? 1 : 0,
            'mark_important' => isset($post['mark_important']) ? 1 : 0,
            'stop_on_match' => isset($post['stop_on_match']) ? 1 : 0,
        ]);
    }

    public function deleteRule(string $tenantKey, int $ruleId): void
    {
        $this->store->deleteMailRule($tenantKey, $ruleId);
    }

    /** @return array{matched: int, moved: int, errors: array<int, string>} */
    public function applyRules(string $tenantKey, int $accountId = 0, int $folderId = 0): array
    {
        $result = ['matched' => 0, 'moved' => 0, 'errors' => []];
        $filters = ['account_id' => $accountId, 'folder_id' => $folderId];
        $page = 1;
        do {
            $batch = $this->store->mailMessages($tenantKey, $filters, $page, 100);
            foreach ($batch['rows'] as $message) {
                $account = $this->store->mailAccount($tenantKey, (int) ($message['account_id'] ?? 0));
                if (!$account) {
                    continue;
                }
                $r = $this->applyRulesToMessages($tenantKey, $account, [(int) ($message['id'] ?? 0)]);
                $result['matched'] += $r['matched'];
                $result['moved'] += $r['moved'];
                array_push($result['errors'], ...$r['errors']);
            }
            $page++;
        } while ($page <= (int) ($batch['total_pages'] ?? 1));

        return $result;
    }

    /** @param array<string, mixed> $post */
    public function reply(string $tenantKey, array $post, string $operator): array
    {
        $messageId = (int) ($post['message_id'] ?? 0);
        $message = $this->store->mailMessage($tenantKey, $messageId);
        if (!$message) {
            return ['ok' => false, 'message' => '邮件不存在'];
        }
        $account = $this->store->mailAccount($tenantKey, (int) ($message['account_id'] ?? 0));
        if (!$account) {
            return ['ok' => false, 'message' => '邮箱账号不存在'];
        }

        $to = trim((string) ($post['to_addr'] ?? ($message['from_addr'] ?? '')));
        $subject = trim((string) ($post['subject'] ?? ''));
        if ($subject === '') {
            $subject = str_starts_with((string) ($message['subject'] ?? ''), 'Re:') ? (string) ($message['subject'] ?? '') : 'Re: ' . (string) ($message['subject'] ?? '');
        }
        $body = trim((string) ($post['body'] ?? ''));
        $cc = $this->splitAddresses((string) ($post['cc_addr'] ?? ''));
        $bcc = $this->splitAddresses((string) ($post['bcc_addr'] ?? ''));

        $send = $this->smtpSend($account, $to, $subject, $body, (string) ($message['message_id'] ?? ''), (string) ($message['message_id'] ?? ''), $cc, $bcc);
        $appended = false;
        if ($send['ok']) {
            $appended = $this->appendSent($account, $send['raw']);
            $this->store->updateMailMessages($tenantKey, [$messageId], ['replied' => 1, 'is_read' => 1]);
        }

        $this->store->addMailReply($tenantKey, [
            'message_id' => $messageId,
            'account_id' => (int) ($message['account_id'] ?? 0),
            'to_addr' => $to,
            'cc_addr' => implode(',', $cc),
            'bcc_addr' => implode(',', $bcc),
            'subject' => $subject,
            'body' => $body,
            'operator' => $operator,
            'success' => $send['ok'] ? 1 : 0,
            'error_msg' => $send['ok'] ? '' : $send['error'],
            'appended' => $appended ? 1 : 0,
        ]);

        if (!$send['ok']) {
            return ['ok' => false, 'message' => '发送失败：' . $send['error']];
        }

        return ['ok' => true, 'message' => $appended ? '回复已发送，并已写回 Sent' : '回复已发送，但写回 Sent 失败'];
    }

    /** @param array<int, array<string, mixed>> $accounts @param array<int, array<string, mixed>> $folders */
    private function folderTree(array $accounts, array $folders): array
    {
        $tree = [];
        foreach ($accounts as $account) {
            $id = (int) ($account['id'] ?? 0);
            $tree[$id] = ['account' => $account, 'folders' => []];
        }
        foreach ($folders as $folder) {
            $aid = (int) ($folder['account_id'] ?? 0);
            if (isset($tree[$aid])) {
                $tree[$aid]['folders'][] = $folder;
            }
        }
        return $tree;
    }

    /** @return array{new: int, inserted_ids: array<int, int>, errors: array<int, string>} */
    private function syncFolder(string $tenantKey, array $account, array $folder, int $limit): array
    {
        $imap = $this->imapConnect($account, (string) ($folder['imap_path'] ?? 'INBOX'));
        if (!$imap) {
            return ['new' => 0, 'inserted_ids' => [], 'errors' => [$this->accountLabel($account) . ' / ' . ($folder['imap_path'] ?? '') . ' 连接失败：' . $this->imapLastError()]];
        }
        $messages = $this->fetchNewMessages($imap, (int) ($folder['last_uid'] ?? 0), $limit);
        @imap_close($imap);

        $insert = $this->store->insertMailMessages($tenantKey, (int) ($account['id'] ?? 0), (int) ($folder['id'] ?? 0), $messages);
        $counts = $this->store->mailFolderCounts($tenantKey);
        $messageCount = (int) (($counts['total_map'] ?? [])[(int) ($folder['id'] ?? 0)] ?? 0);
        $lastUid = max((int) ($folder['last_uid'] ?? 0), (int) ($insert['max_uid'] ?? 0));
        $this->store->updateMailFolderAfterSync($tenantKey, (int) ($folder['id'] ?? 0), $lastUid, $messageCount);

        return ['new' => (int) $insert['inserted'], 'inserted_ids' => $insert['inserted_ids'], 'errors' => []];
    }

    /** @param array<int, int> $messageIds @return array{matched: int, moved: int, errors: array<int, string>} */
    private function applyRulesToMessages(string $tenantKey, array $account, array $messageIds): array
    {
        $rules = array_values(array_filter(
            $this->store->mailRules($tenantKey),
            fn (array $rule): bool => $this->ruleAppliesToAccount($rule, $account)
        ));
        if (!$rules) {
            return ['matched' => 0, 'moved' => 0, 'errors' => []];
        }

        $matched = 0;
        $moved = 0;
        $errors = [];
        foreach ($messageIds as $messageId) {
            $message = $this->store->mailMessage($tenantKey, (int) $messageId);
            if (!$message || (int) ($message['is_deleted'] ?? 0) === 1) {
                continue;
            }
            foreach ($rules as $rule) {
                if (!$this->ruleMatches($rule, $message)) {
                    continue;
                }
                $matched++;
                $changes = [];
                if ((int) ($rule['mark_read'] ?? 0) === 1) {
                    $changes['is_read'] = 1;
                }
                if ((int) ($rule['mark_important'] ?? 0) === 1) {
                    $changes['is_important'] = 1;
                }
                if ($changes) {
                    $this->store->updateMailMessages($tenantKey, [(int) $messageId], $changes);
                }
                $targetName = trim((string) ($rule['target_folder_name'] ?? ''));
                if ($targetName !== '') {
                    $targetId = $this->resolveTargetFolder($tenantKey, $account, $targetName);
                    if ($targetId <= 0) {
                        $errors[] = '规则「' . (string) ($rule['name'] ?? '') . '」无法解析目标文件夹：' . $targetName;
                    } else {
                        $move = $this->move($tenantKey, [(int) $messageId], $targetId);
                        if (!($move['ok'] ?? false)) {
                            $errors[] = (string) ($move['message'] ?? '');
                        } else {
                            $moved += (int) ($move['moved'] ?? 0);
                        }
                    }
                    break;
                }
                if ((int) ($rule['stop_on_match'] ?? 1) === 1) {
                    break;
                }
            }
        }

        return ['matched' => $matched, 'moved' => $moved, 'errors' => array_values(array_filter($errors))];
    }

    private function resolveTargetFolder(string $tenantKey, array $account, string $targetName): int
    {
        foreach ($this->store->mailFolders($tenantKey, (int) ($account['id'] ?? 0), true) as $folder) {
            if (in_array($targetName, [(string) ($folder['display_name'] ?? ''), (string) ($folder['imap_path'] ?? '')], true)) {
                return (int) ($folder['id'] ?? 0);
            }
        }

        return 0;
    }

    private function ruleAppliesToAccount(array $rule, array $account): bool
    {
        if ((int) ($rule['enabled'] ?? 0) !== 1) {
            return false;
        }
        if ((int) ($rule['apply_all'] ?? 0) === 1) {
            return true;
        }
        if (in_array((int) ($account['id'] ?? 0), array_map('intval', (array) ($rule['account_ids'] ?? [])), true)) {
            return true;
        }
        $platforms = array_filter(array_map('trim', explode(',', (string) ($rule['platforms'] ?? ''))));
        return $platforms && in_array((string) ($account['platform'] ?? ''), $platforms, true);
    }

    private function ruleMatches(array $rule, array $message): bool
    {
        $has = false;
        foreach ([
            'match_from' => ((string) ($message['from_addr'] ?? '') . ' ' . (string) ($message['from_name'] ?? '')),
            'match_subject' => (string) ($message['subject'] ?? ''),
            'match_to' => (string) ($message['to_addr'] ?? ''),
        ] as $key => $haystack) {
            $needle = trim((string) ($rule[$key] ?? ''));
            if ($needle === '') {
                continue;
            }
            $has = true;
            $matched = function_exists('mb_stripos')
                ? mb_stripos($haystack, $needle, 0, 'UTF-8') !== false
                : stripos($haystack, $needle) !== false;
            if (!$matched) {
                return false;
            }
        }

        return $has;
    }

    private function imapAvailable(): bool
    {
        return function_exists('imap_open');
    }

    private function hostIsInternal(string $host): bool
    {
        $host = trim($host, '[] ');
        if ($host === '') {
            return true;
        }
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $low = strtolower($ip);
            return $low === '::1' || $low === '::' || str_starts_with($low, 'fc') || str_starts_with($low, 'fd') || str_starts_with($low, 'fe8');
        }
        $long = ip2long($ip);
        if ($long === false) {
            return true;
        }
        foreach ([['0.0.0.0', '255.0.0.0'], ['127.0.0.0', '255.0.0.0'], ['10.0.0.0', '255.0.0.0'], ['172.16.0.0', '255.240.0.0'], ['192.168.0.0', '255.255.0.0'], ['169.254.0.0', '255.255.0.0']] as $block) {
            if (($long & ip2long($block[1])) === (ip2long($block[0]) & ip2long($block[1]))) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int, string>|null */
    private function listFolders(array $account): ?array
    {
        $server = $this->imapServer($account);
        $imap = @imap_open($server, (string) ($account['imap_user'] ?? ''), $this->pwdDecode((string) ($account['imap_pass'] ?? '')), OP_HALFOPEN, 1);
        if (!$imap) {
            return null;
        }
        $list = @imap_list($imap, $server, '*');
        @imap_close($imap);
        if (!is_array($list)) {
            return null;
        }
        $folders = [];
        foreach ($list as $box) {
            $folders[] = $this->folderToUtf8(str_replace($server, '', (string) $box));
        }
        sort($folders);
        return $folders;
    }

    private function imapConnect(array $account, string $folder = 'INBOX'): mixed
    {
        if (!$this->imapAvailable()) {
            return false;
        }
        if (function_exists('imap_timeout')) {
            @imap_timeout(IMAP_OPENTIMEOUT, 20);
            @imap_timeout(IMAP_READTIMEOUT, 30);
            @imap_timeout(IMAP_WRITETIMEOUT, 30);
        }
        return @imap_open($this->imapServer($account) . $this->folderToMutf7($folder), (string) ($account['imap_user'] ?? ''), $this->pwdDecode((string) ($account['imap_pass'] ?? '')), 0, 1);
    }

    private function imapServer(array $account): string
    {
        $host = trim((string) ($account['imap_host'] ?? ''));
        $port = max(1, (int) ($account['imap_port'] ?? ((int) ($account['imap_ssl'] ?? 1) === 1 ? 993 : 143)));
        $flags = '/imap' . ((int) ($account['imap_ssl'] ?? 1) === 1 ? '/ssl' : '/notls') . '/novalidate-cert';
        return '{' . $host . ':' . $port . $flags . '}';
    }

    private function imapLastError(): string
    {
        $errors = [];
        if (function_exists('imap_errors')) {
            $got = imap_errors();
            if (is_array($got)) {
                $errors = array_merge($errors, $got);
            }
        }
        if (function_exists('imap_alerts')) {
            $alerts = imap_alerts();
            if (is_array($alerts)) {
                $errors = array_merge($errors, $alerts);
            }
        }
        return $errors ? implode('; ', array_unique($errors)) : '未知错误';
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchNewMessages(mixed $imap, int $lastUid, int $limit): array
    {
        if (!$imap) {
            return [];
        }
        if ($lastUid <= 0) {
            $check = @imap_check($imap);
            $total = $check && isset($check->Nmsgs) ? (int) $check->Nmsgs : 0;
            if ($total <= 0) {
                return [];
            }
            $start = max(1, $total - $limit + 1);
            $overviews = @imap_fetch_overview($imap, $start . ':' . $total, 0);
        } else {
            $overviews = @imap_fetch_overview($imap, ($lastUid + 1) . ':*', FT_UID);
        }
        $valid = [];
        if (is_array($overviews)) {
            foreach ($overviews as $ov) {
                if (isset($ov->uid) && (int) $ov->uid > $lastUid) {
                    $valid[] = $ov;
                }
            }
        }
        usort($valid, fn (object $a, object $b): int => (int) $a->uid <=> (int) $b->uid);
        if (count($valid) > $limit) {
            $valid = $lastUid <= 0 ? array_slice($valid, -$limit) : array_slice($valid, 0, $limit);
        }
        return array_map(fn (object $ov): array => $this->parseOverview($ov), $valid);
    }

    private function parseOverview(object $ov): array
    {
        $fromRaw = (string) ($ov->from ?? '');
        $fromAddr = '';
        $fromName = '';
        $parsed = @imap_rfc822_parse_adrlist($fromRaw, '');
        if (is_array($parsed) && isset($parsed[0])) {
            $a = $parsed[0];
            if (isset($a->mailbox, $a->host)) {
                $fromAddr = $a->mailbox . '@' . $a->host;
            }
            if (isset($a->personal) && $a->personal !== '') {
                $fromName = $this->decodeMime((string) $a->personal);
            }
        }
        $date = '';
        if (isset($ov->date) && strtotime((string) $ov->date)) {
            $date = date('Y-m-d H:i:s', (int) strtotime((string) $ov->date));
        }
        return [
            'uid' => (int) ($ov->uid ?? 0),
            'message_id' => trim((string) ($ov->message_id ?? ''), '<>'),
            'from_addr' => $fromAddr !== '' ? $fromAddr : $this->decodeMime($fromRaw),
            'from_name' => $fromName,
            'to_addr' => $this->decodeMime((string) ($ov->to ?? '')),
            'subject' => $this->decodeMime((string) ($ov->subject ?? '')),
            'body_text' => '',
            'body_html' => '',
            'mail_date' => $date,
            'seen' => !empty($ov->seen) ? 1 : 0,
            'has_attachment' => 0,
            'attachments' => [],
        ];
    }

    /** @return array{text: string, html: string, has_attachment: bool, attachments: array<int, mixed>, cc: string} */
    private function fetchOneBody(mixed $imap, int $uid): array
    {
        $result = ['text' => '', 'html' => '', 'has_attachment' => false, 'attachments' => [], 'cc' => ''];
        if (!$imap || $uid <= 0) {
            return $result;
        }
        $structure = @imap_fetchstructure($imap, $uid, FT_UID);
        if ($structure) {
            $this->walkStructure($imap, $uid, $structure, '', $result);
        }
        if ($result['text'] === '' && $result['html'] === '') {
            $raw = @imap_body($imap, $uid, FT_UID);
            if ($raw !== false) {
                $result['text'] = $this->decodeRawBody($imap, $uid, $raw);
            }
        }
        $result['cc'] = $this->fetchCc($imap, $uid);
        $result['text'] = substr($result['text'], 0, self::BODY_MAX_BYTES);
        $result['html'] = substr($result['html'], 0, self::BODY_MAX_BYTES);

        return $result;
    }

    private function walkStructure(mixed $imap, int $uid, object $part, string $prefix, array &$result): void
    {
        if (isset($part->parts) && is_array($part->parts) && $part->parts) {
            foreach ($part->parts as $idx => $child) {
                $num = $prefix === '' ? (string) ($idx + 1) : $prefix . '.' . ($idx + 1);
                $this->walkStructure($imap, $uid, $child, $num, $result);
            }
            return;
        }
        $partNum = $prefix === '' ? '1' : $prefix;
        $att = $this->attachmentMeta($part, $partNum);
        if ($att !== null) {
            $result['has_attachment'] = true;
            $result['attachments'][] = $att;
            return;
        }
        if ((int) ($part->type ?? -1) !== 0) {
            return;
        }
        $raw = @imap_fetchbody($imap, $uid, $partNum, FT_UID);
        if ($raw === false || $raw === '') {
            return;
        }
        $decoded = $this->decodePartBody($raw, (int) ($part->encoding ?? 0));
        $decoded = $this->partToUtf8($decoded, $part->parameters ?? []);
        if (strtoupper((string) ($part->subtype ?? '')) === 'HTML') {
            $result['html'] .= $decoded;
        } else {
            $result['text'] .= $decoded;
        }
    }

    private function attachmentMeta(object $part, string $partNum): ?array
    {
        $type = (int) ($part->type ?? 0);
        $subtype = strtolower((string) ($part->subtype ?? 'bin'));
        $encoding = (int) ($part->encoding ?? 0);
        $filename = '';
        $isAttachment = false;
        $isInline = false;
        if (!empty($part->ifdisposition) && isset($part->disposition)) {
            $disp = strtolower((string) $part->disposition);
            $isAttachment = $disp === 'attachment';
            $isInline = $disp === 'inline';
        }
        foreach ([$part->dparameters ?? [], $part->parameters ?? []] as $params) {
            if (!is_array($params)) {
                continue;
            }
            foreach ($params as $param) {
                $attr = strtolower((string) ($param->attribute ?? ''));
                if (in_array($attr, ['filename', 'name'], true)) {
                    $filename = (string) ($param->value ?? '');
                    $isAttachment = true;
                }
            }
        }
        $cid = '';
        if (!empty($part->ifid) && isset($part->id)) {
            $isInline = true;
            $cid = trim(trim((string) $part->id), '<>');
        }
        if (!$isAttachment && $type !== 0 && $type !== 1) {
            $isAttachment = true;
        }
        if (!$isAttachment) {
            return null;
        }
        return [
            'name' => $filename !== '' ? $this->decodeMime($filename) : '附件.' . $subtype,
            'part' => $partNum,
            'encoding' => $encoding,
            'size' => (int) ($part->bytes ?? 0),
            'type' => $type,
            'subtype' => $subtype,
            'inline' => $isInline ? 1 : 0,
            'cid' => $cid,
        ];
    }

    private function fetchCc(mixed $imap, int $uid): string
    {
        $header = @imap_fetchheader($imap, $uid, FT_UID);
        if ($header === false || $header === '') {
            return '';
        }
        $parsed = @imap_rfc822_parse_headers($header);
        if (!$parsed || !isset($parsed->cc) || !is_array($parsed->cc)) {
            return '';
        }
        $out = [];
        foreach ($parsed->cc as $addr) {
            if (!isset($addr->mailbox, $addr->host)) {
                continue;
            }
            $email = $addr->mailbox . '@' . $addr->host;
            $name = isset($addr->personal) ? $this->decodeMime((string) $addr->personal) : '';
            $out[] = $name !== '' ? "{$name} <{$email}>" : $email;
        }
        return implode(', ', $out);
    }

    private function decodeRawBody(mixed $imap, int $uid, string $raw): string
    {
        $header = @imap_fetchheader($imap, $uid, FT_UID);
        $cte = '';
        $charset = '';
        if (is_string($header)) {
            if (preg_match('/Content-Transfer-Encoding:\s*([^\r\n]+)/i', $header, $m)) {
                $cte = strtolower(trim($m[1]));
            }
            if (preg_match('/charset\s*=\s*"?([^"\r\n;]+)"?/i', $header, $m)) {
                $charset = strtoupper(trim($m[1]));
            }
        }
        if (str_contains($cte, 'base64')) {
            $decoded = base64_decode($raw, false);
            if ($decoded !== false) {
                $raw = $decoded;
            }
        } elseif (str_contains($cte, 'quoted-printable')) {
            $raw = quoted_printable_decode($raw);
        }
        return $this->textToUtf8($raw, $charset);
    }

    private function decodePartBody(string $data, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($data),
            4 => quoted_printable_decode($data),
            default => $data,
        };
    }

    private function partToUtf8(string $content, mixed $params): string
    {
        $charset = '';
        if (is_array($params)) {
            foreach ($params as $param) {
                if (strtolower((string) ($param->attribute ?? '')) === 'charset') {
                    $charset = strtoupper((string) ($param->value ?? ''));
                    break;
                }
            }
        }
        return $this->textToUtf8($content, $charset);
    }

    private function textToUtf8(string $content, string $charset = ''): string
    {
        if ($content === '' || !function_exists('mb_convert_encoding')) {
            return $content;
        }
        $charset = strtoupper(trim($charset));
        if (str_contains($content, "\x1B$") || str_contains($content, "\x1B(")) {
            $converted = @mb_convert_encoding($content, 'UTF-8', 'ISO-2022-JP');
            if ($converted !== false && $converted !== '') {
                return $converted;
            }
        }
        if ($charset === '' || in_array($charset, ['UTF-8', 'UTF8', 'US-ASCII', 'ASCII'], true)) {
            if ($charset === '') {
                $detected = @mb_detect_encoding($content, ['ASCII', 'UTF-8', 'ISO-2022-JP', 'SJIS', 'EUC-JP'], true);
                if ($detected && !in_array($detected, ['ASCII', 'UTF-8'], true)) {
                    $converted = @mb_convert_encoding($content, 'UTF-8', $detected);
                    if ($converted !== false && $converted !== '') {
                        return $converted;
                    }
                }
            }
            return $content;
        }
        $converted = @mb_convert_encoding($content, 'UTF-8', $charset);
        return $converted !== false && $converted !== '' ? $converted : $content;
    }

    private function decodeMime(string $str): string
    {
        if ($str === '' || !function_exists('imap_mime_header_decode')) {
            return $str;
        }
        $parts = @imap_mime_header_decode($str);
        if (!is_array($parts)) {
            return $str;
        }
        $out = '';
        foreach ($parts as $part) {
            $charset = strtoupper((string) ($part->charset ?? ''));
            $text = (string) ($part->text ?? '');
            if ($charset === '' || $charset === 'DEFAULT' || $charset === 'US-ASCII') {
                $out .= $text;
            } else {
                $converted = function_exists('mb_convert_encoding') ? @mb_convert_encoding($text, 'UTF-8', $charset) : false;
                $out .= ($converted !== false && $converted !== '') ? $converted : $text;
            }
        }
        return $out;
    }

    /** @param array<int, int> $uids @return array{ok: bool, error: string} */
    private function imapMove(array $account, string $sourcePath, array $uids, string $targetPath): array
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if (!$uids || $sourcePath === $targetPath) {
            return ['ok' => true, 'error' => ''];
        }
        $imap = $this->imapConnect($account, $sourcePath);
        if (!$imap) {
            return ['ok' => false, 'error' => '连接源文件夹失败：' . $this->imapLastError()];
        }
        $ok = @imap_mail_move($imap, implode(',', $uids), $this->folderToMutf7($targetPath), CP_UID);
        if ($ok) {
            @imap_expunge($imap);
        }
        @imap_close($imap);
        return $ok ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => '移动失败：' . $this->imapLastError()];
    }

    private function smtpSend(array $account, string $to, string $subject, string $body, string $inReplyTo = '', string $references = '', array $cc = [], array $bcc = []): array
    {
        if ($to === '' || $body === '') {
            return ['ok' => false, 'error' => '收件人和正文不能为空', 'raw' => ''];
        }
        $host = trim((string) ($account['smtp_host'] ?? ''));
        if ($host === '' || $this->hostIsInternal($host)) {
            return ['ok' => false, 'error' => 'SMTP 主机为空、不可解析或解析到内网', 'raw' => ''];
        }
        $secure = (string) ($account['smtp_secure'] ?? 'ssl');
        $port = max(1, (int) ($account['smtp_port'] ?? ($secure === 'ssl' ? 465 : 587)));
        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, 30, STREAM_CLIENT_CONNECT);
        if (!$fp) {
            return ['ok' => false, 'error' => "连接 SMTP 失败：{$errstr}", 'raw' => ''];
        }
        stream_set_timeout($fp, 30);
        $raw = $this->buildMessage($account, $to, $subject, $body, $inReplyTo, $references, $cc);
        $read = fn (): array => $this->smtpRead($fp);
        $put = fn (string $cmd): int|false => fwrite($fp, $cmd . "\r\n");
        $r = $read();
        if ($r['code'] >= 400) {
            fclose($fp);
            return ['ok' => false, 'error' => $r['text'], 'raw' => $raw];
        }
        $put('EHLO localhost');
        $r = $read();
        if ($r['code'] >= 400) {
            $put('HELO localhost');
            $r = $read();
        }
        if ($secure === 'tls') {
            $put('STARTTLS');
            $r = $read();
            if ($r['code'] !== 220 || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($fp);
                return ['ok' => false, 'error' => 'STARTTLS 失败：' . $r['text'], 'raw' => $raw];
            }
            $put('EHLO localhost');
            $read();
        }
        $authUser = trim((string) (($account['smtp_user'] ?? '') ?: ($account['imap_user'] ?? '')));
        $authPass = $this->pwdDecode((string) (($account['smtp_pass'] ?? '') ?: ($account['imap_pass'] ?? '')));
        if ($authUser !== '') {
            $put('AUTH LOGIN');
            $read();
            $put(base64_encode($authUser));
            $read();
            $put(base64_encode($authPass));
            $r = $read();
            if ($r['code'] >= 400) {
                fclose($fp);
                return ['ok' => false, 'error' => 'SMTP 认证失败：' . $r['text'], 'raw' => $raw];
            }
        }
        $from = trim((string) (($account['smtp_user'] ?? '') ?: ($account['imap_user'] ?? '')));
        $put('MAIL FROM:<' . $from . '>');
        $r = $read();
        if ($r['code'] >= 400) {
            fclose($fp);
            return ['ok' => false, 'error' => 'MAIL FROM 失败：' . $r['text'], 'raw' => $raw];
        }
        foreach (array_merge([$to], $cc, $bcc) as $rcpt) {
            $rcpt = trim((string) $rcpt);
            if ($rcpt === '') {
                continue;
            }
            $put('RCPT TO:<' . $rcpt . '>');
            $r = $read();
            if ($r['code'] >= 400) {
                fclose($fp);
                return ['ok' => false, 'error' => "RCPT {$rcpt} 失败：" . $r['text'], 'raw' => $raw];
            }
        }
        $put('DATA');
        $r = $read();
        if ($r['code'] >= 400) {
            fclose($fp);
            return ['ok' => false, 'error' => 'DATA 失败：' . $r['text'], 'raw' => $raw];
        }
        fwrite($fp, preg_replace('/^\./m', '..', $raw) . "\r\n.\r\n");
        $r = $read();
        $put('QUIT');
        fclose($fp);

        return $r['code'] >= 200 && $r['code'] < 400
            ? ['ok' => true, 'error' => '', 'raw' => $raw]
            : ['ok' => false, 'error' => $r['text'], 'raw' => $raw];
    }

    private function smtpRead(mixed $fp): array
    {
        $data = '';
        $code = 0;
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) >= 4) {
                $code = (int) substr($line, 0, 3);
                if ($line[3] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }
        return ['code' => $code, 'text' => trim($data)];
    }

    /** @param array<int, string> $cc */
    private function buildMessage(array $account, string $to, string $subject, string $body, string $inReplyTo, string $references, array $cc): string
    {
        $from = trim((string) (($account['smtp_user'] ?? '') ?: ($account['imap_user'] ?? '')));
        $name = trim((string) (($account['shop_name'] ?? '') ?: ($account['shop_dpqz'] ?? '')));
        $host = str_contains($from, '@') ? substr(strrchr($from, '@') ?: 'localhost', 1) : 'localhost';
        $messageId = '<' . uniqid('kefu', true) . '@' . $host . '>';
        $headers = [
            'From: ' . ($name !== '' ? $this->mimeB($name) . ' <' . $from . '>' : '<' . $from . '>'),
            'To: <' . $to . '>',
        ];
        if ($cc) {
            $headers[] = 'Cc: ' . implode(', ', array_map(fn (string $addr): string => '<' . $addr . '>', $cc));
        }
        $headers[] = 'Subject: ' . $this->mimeB($subject);
        $headers[] = 'Date: ' . (new \DateTime('now', new \DateTimeZone('Asia/Tokyo')))->format('r');
        $headers[] = 'Message-ID: ' . $messageId;
        $headers[] = 'Reply-To: <' . $from . '>';
        if ($inReplyTo !== '') {
            $headers[] = 'In-Reply-To: <' . trim($inReplyTo, '<>') . '>';
            $headers[] = 'References: <' . trim($references !== '' ? $references : $inReplyTo, '<>') . '>';
        }
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = str_replace("\n", "\r\n", $body);

        return implode("\r\n", $headers) . "\r\n\r\n" . quoted_printable_encode($body) . "\r\n";
    }

    private function appendSent(array $account, string $raw): bool
    {
        if (!$this->imapAvailable() || $raw === '') {
            return false;
        }
        $folder = trim((string) ($account['sent_folder'] ?? 'Sent')) ?: 'Sent';
        $imap = $this->imapConnect($account, 'INBOX');
        if (!$imap) {
            return false;
        }
        $ok = @imap_append($imap, $this->imapServer($account) . $this->folderToMutf7($folder), $raw, '\\Seen');
        @imap_close($imap);
        return (bool) $ok;
    }

    private function mimeB(string $str): string
    {
        if ($str === '') {
            return '';
        }
        if (function_exists('mb_check_encoding') && mb_check_encoding($str, 'ASCII')) {
            return $str;
        }
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }

    private function folderToMutf7(string $folder): string
    {
        if ($folder === '' || (function_exists('mb_check_encoding') && mb_check_encoding($folder, 'ASCII'))) {
            return $folder;
        }
        $converted = function_exists('mb_convert_encoding') ? @mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8') : false;
        if ($converted !== false && $converted !== '') {
            return $converted;
        }
        return function_exists('imap_utf7_encode') ? imap_utf7_encode($folder) : $folder;
    }

    private function folderToUtf8(string $folder): string
    {
        if ($folder === '') {
            return '';
        }
        $converted = function_exists('mb_convert_encoding') ? @mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP') : false;
        if ($converted !== false && $converted !== '') {
            return $converted;
        }
        return function_exists('imap_utf7_decode') ? imap_utf7_decode($folder) : $folder;
    }

    private function pwdEncode(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $key = getenv('KEFU_MAIL_PWD_SALT') ?: 'kefu_mail';
        $out = '';
        for ($i = 0, $n = strlen($plain), $k = strlen($key); $i < $n; $i++) {
            $out .= chr(ord($plain[$i]) ^ ord($key[$i % $k]));
        }
        return 'enc:' . base64_encode($out);
    }

    private function pwdDecode(string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, 'enc:')) {
            return $stored;
        }
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false) {
            return '';
        }
        $key = getenv('KEFU_MAIL_PWD_SALT') ?: 'kefu_mail';
        $out = '';
        for ($i = 0, $n = strlen($raw), $k = strlen($key); $i < $n; $i++) {
            $out .= chr(ord($raw[$i]) ^ ord($key[$i % $k]));
        }
        return $out;
    }

    /** @return array<int, string> */
    private function splitAddresses(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $raw) ?: []), fn (string $value): bool => $value !== ''));
    }

    private function accountLabel(array $account): string
    {
        $shop = trim((string) (($account['shop_name'] ?? '') ?: ($account['shop_dpqz'] ?? '')));
        return $shop !== '' ? $shop : (string) ($account['imap_user'] ?? ('账号#' . (int) ($account['id'] ?? 0)));
    }
}
