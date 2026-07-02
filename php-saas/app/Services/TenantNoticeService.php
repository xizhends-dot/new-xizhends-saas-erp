<?php

declare(strict_types=1);

namespace Xizhen\Services;

use DateTimeImmutable;
use Xizhen\Core\Permission;
use Xizhen\Core\StoreInterface;

final class TenantNoticeService
{
    /** @var array<int, string> */
    private const VALID_STATUSES = ['draft', 'published', 'archived'];

    public function __construct(private readonly StoreInterface $store)
    {
    }

    /**
     * @param array<string, mixed>|null $currentUser
     * @return array<int, array<string, mixed>>
     */
    public function tenantNotices(string $tenantKey, ?array $currentUser = null, int $limit = 20, ?string $surface = null): array
    {
        $settings = $this->store->tenantSettings($tenantKey);
        $rows = is_array($settings['notices']['items'] ?? null) ? $settings['notices']['items'] : [];

        return $this->listFromRows((array) $rows, $currentUser, $limit, $surface);
    }

    /**
     * @param array<string, mixed>|null $currentUser
     * @return array<int, array<string, mixed>>
     */
    public function dashboardNotices(string $tenantKey, ?array $currentUser = null, int $limit = 3): array
    {
        return $this->tenantNotices($tenantKey, $currentUser, $limit, 'dashboard');
    }

    /**
     * @param array<string, mixed>|null $currentUser
     * @return array<int, array<string, mixed>>
     */
    public function orderPageNotices(string $tenantKey, ?array $currentUser = null, int $limit = 3): array
    {
        return $this->tenantNotices($tenantKey, $currentUser, $limit, 'orders');
    }

    /**
     * @param array<int, mixed> $rows
     * @param array<string, mixed>|null $currentUser
     * @return array<int, array<string, mixed>>
     */
    public function listFromRows(array $rows, ?array $currentUser = null, int $limit = 20, ?string $surface = null): array
    {
        $now = new DateTimeImmutable();
        $notices = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $notice = $this->normalizeNotice($row);
            if (!$this->isVisible($notice, $currentUser, $surface, $now)) {
                continue;
            }
            $notices[] = $notice;
        }

        usort($notices, static function (array $left, array $right): int {
            if ((bool) $left['is_pinned'] !== (bool) $right['is_pinned']) {
                return (bool) $left['is_pinned'] ? -1 : 1;
            }

            return strcmp((string) $right['published_at'], (string) $left['published_at']);
        });

        return array_slice($notices, 0, max(0, $limit));
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $operator
     * @return array{ok: bool, message: string, errors: array<string, string>, payload?: array<string, mixed>, needs_store_support?: bool}
     */
    public function prepareSave(string $tenantKey, array $input, array $operator, ?int $noticeId = null): array
    {
        $payload = $this->payloadFromInput($tenantKey, $input, $operator, $noticeId);
        $errors = $this->validatePayload($payload);
        if ($errors !== []) {
            return [
                'ok' => false,
                'message' => '请修正公告内容后再提交。',
                'errors' => $errors,
            ];
        }

        return [
            'ok' => false,
            'message' => '公告持久化接口尚未接入 Store，请主控统一添加后调用保存。',
            'errors' => [],
            'payload' => $payload,
            'needs_store_support' => true,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $operator
     * @return array<string, mixed>
     */
    public function payloadFromInput(string $tenantKey, array $input, array $operator, ?int $noticeId = null): array
    {
        $targetRoles = array_values(array_intersect(
            $this->targetRoles(),
            array_map($this->normalizeTargetRole(...), (array) ($input['target_roles'] ?? []))
        ));
        $targetUserIds = array_values(array_filter(
            array_map('intval', (array) ($input['target_user_ids'] ?? [])),
            static fn (int $id): bool => $id > 0
        ));
        $status = (string) ($input['status'] ?? 'published');
        if (!in_array($status, self::VALID_STATUSES, true)) {
            $status = 'published';
        }

        return [
            'id' => $noticeId,
            'tenant_key' => trim($tenantKey),
            'title' => $this->textPrefix(trim((string) ($input['title'] ?? '')), 120),
            'body' => trim((string) ($input['body'] ?? $input['content'] ?? '')),
            'status' => $status,
            'is_pinned' => !empty($input['is_pinned']),
            'show_on_dashboard' => !array_key_exists('show_on_dashboard', $input) || !empty($input['show_on_dashboard']),
            'show_on_orders' => !empty($input['show_on_orders']),
            'target_roles' => $targetRoles,
            'target_user_ids' => $targetUserIds,
            'published_at' => $this->dateInput($input['published_at'] ?? '') ?: date('Y-m-d H:i:s'),
            'expired_at' => $this->dateInput($input['expired_at'] ?? ''),
            'author_id' => (int) ($operator['id'] ?? 0),
            'author_name' => (string) (($operator['name'] ?? '') ?: ($operator['username'] ?? '')),
        ];
    }

    /** @return array<int, string> */
    public function targetRoles(): array
    {
        return ['公司管理员', '采购', '客服'];
    }

    /** @return array<int, string> */
    public function persistenceRequirements(): array
    {
        return [
            'StoreInterface::tenantNotices(string $tenantKey): array',
            'StoreInterface::tenantNotice(string $tenantKey, int $noticeId): ?array',
            'StoreInterface::saveTenantNotice(string $tenantKey, array $data): int',
            'StoreInterface::deleteTenantNotice(string $tenantKey, int $noticeId): void',
            'StoreInterface::toggleTenantNoticePinned(string $tenantKey, int $noticeId, bool $pinned): void',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeNotice(array $row): array
    {
        $status = (string) ($row['status'] ?? 'published');
        if ($status === 'active') {
            $status = 'published';
        }
        if (!in_array($status, self::VALID_STATUSES, true)) {
            $status = 'published';
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => trim((string) ($row['title'] ?? '')),
            'body' => (string) ($row['body'] ?? $row['content'] ?? ''),
            'status' => $status,
            'is_pinned' => (bool) ($row['is_pinned'] ?? (($row['status'] ?? '') === 'yes')),
            'show_on_dashboard' => !array_key_exists('show_on_dashboard', $row) || (bool) $row['show_on_dashboard'],
            'show_on_orders' => (bool) ($row['show_on_orders'] ?? false),
            'target_roles' => array_values(array_unique(array_filter(array_map(
                $this->normalizeTargetRole(...),
                (array) ($row['target_roles'] ?? [])
            )))),
            'target_user_ids' => array_values(array_filter(array_map('intval', (array) ($row['target_user_ids'] ?? [])))),
            'published_at' => (string) ($row['published_at'] ?? $row['created_at'] ?? $row['c_time'] ?? ''),
            'expired_at' => (string) ($row['expired_at'] ?? ''),
            'author_name' => (string) ($row['author_name'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? $row['c_time'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $notice
     * @param array<string, mixed>|null $currentUser
     */
    private function isVisible(array $notice, ?array $currentUser, ?string $surface, DateTimeImmutable $now): bool
    {
        if (($notice['status'] ?? '') !== 'published') {
            return false;
        }
        if ($surface === 'dashboard' && empty($notice['show_on_dashboard'])) {
            return false;
        }
        if ($surface === 'orders' && empty($notice['show_on_orders'])) {
            return false;
        }

        $publishedAt = $this->timestamp((string) ($notice['published_at'] ?? ''));
        if ($publishedAt !== null && $publishedAt > $now->getTimestamp()) {
            return false;
        }

        $expiredAt = $this->timestamp((string) ($notice['expired_at'] ?? ''));
        if ($expiredAt !== null && $expiredAt < $now->getTimestamp()) {
            return false;
        }

        $targetUserIds = (array) ($notice['target_user_ids'] ?? []);
        if ($targetUserIds !== [] && !in_array((int) ($currentUser['id'] ?? 0), $targetUserIds, true)) {
            return false;
        }

        $targetRoles = (array) ($notice['target_roles'] ?? []);
        if ($targetRoles !== [] && !in_array(Permission::normalizeRole($currentUser['role'] ?? ''), $targetRoles, true)) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function validatePayload(array $payload): array
    {
        $errors = [];
        if ((string) ($payload['tenant_key'] ?? '') === '') {
            $errors['tenant_key'] = '租户不能为空。';
        }
        if ((string) ($payload['title'] ?? '') === '') {
            $errors['title'] = '公告标题不能为空。';
        }
        if ((string) ($payload['body'] ?? '') === '') {
            $errors['body'] = '公告内容不能为空。';
        }
        if ($this->textLength((string) ($payload['body'] ?? '')) > 20000) {
            $errors['body'] = '公告内容过长。';
        }

        return $errors;
    }

    private function normalizeTargetRole(mixed $role): string
    {
        $role = trim((string) $role);
        if ($role === '品检') {
            return '采购';
        }

        return in_array($role, $this->targetRoles(), true) ? $role : '';
    }

    private function timestamp(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : $time;
    }

    private function dateInput(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $time = strtotime($value);

        return $time === false ? '' : date('Y-m-d H:i:s', $time);
    }

    private function textPrefix(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
    }

    private function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value);
        }

        return strlen($value);
    }
}
