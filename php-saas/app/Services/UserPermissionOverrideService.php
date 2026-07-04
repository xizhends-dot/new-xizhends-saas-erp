<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\Permission;
use Xizhen\Core\StoreInterface;

final class UserPermissionOverrideService
{
    public function __construct(private readonly StoreInterface $store)
    {
    }

    /**
     * @return array{ok: bool, message: string, user?: array<string, mixed>, matrix?: array<int, array<string, mixed>>, groups?: array<string, array<int, array<string, mixed>>>}
     */
    public function matrixForUser(string $tenantKey, int $userId): array
    {
        $user = $this->store->user($tenantKey, $userId);
        if (!$user) {
            return ['ok' => false, 'message' => '员工不存在。'];
        }

        $matrix = $this->buildMatrix($user);

        return [
            'ok' => true,
            'message' => '',
            'user' => $user,
            'matrix' => $matrix,
            'groups' => $this->groupMatrix($matrix),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function buildMatrix(array $user): array
    {
        $role = (string) ($user['role'] ?? '客服');
        $roleDefaults = Permission::roleDefaults()[Permission::normalizeRole($role)] ?? Permission::roleDefaults()['客服'];
        $storedPermissions = array_values(array_filter(array_map('trim', (array) ($user['permissions'] ?? []))));
        $storedLookup = array_flip($storedPermissions);
        $overrides = $this->normalizeStoredOverrides($user['permission_overrides'] ?? []);

        $matrix = [];
        foreach ($this->catalog() as $item) {
            $key = (string) $item['key'];
            $legacyKey = (string) ($item['legacy_key'] ?? '');
            $inherited = in_array($key, $roleDefaults, true);
            $stored = isset($storedLookup[$key]) || ($legacyKey !== '' && isset($storedLookup[$legacyKey]));
            $state = 'inherit';
            if (in_array($key, $overrides['deny'], true)) {
                $state = 'deny';
            } elseif (in_array($key, $overrides['allow'], true) || (!$inherited && $stored)) {
                $state = 'allow';
            }

            $effective = match ($state) {
                'allow' => true,
                'deny' => false,
                default => $inherited || $stored,
            };

            $matrix[] = array_merge($item, [
                'inherited' => $inherited,
                'stored' => $stored,
                'state' => $state,
                'effective' => $effective,
            ]);
        }

        return $matrix;
    }

    /**
     * @return array<int, array{key: string, label: string, group: string, description: string, legacy_key: string}>
     */
    public function catalog(): array
    {
        $defaults = [];
        foreach (Permission::roleDefaults() as $permissions) {
            foreach ($permissions as $permission) {
                $defaults[$permission] = $permission;
            }
        }

        $legacyByLabel = [];
        foreach (Permission::legacyMap() as $legacyKey => $label) {
            $defaults[$label] = $label;
            $legacyByLabel[$label] = $legacyKey;
        }

        foreach ([
            '性能分析' => 'performance_analysis',
            '公告管理' => 'tenant_notice_manage',
            '通知查看' => 'tenant_notice_view',
            '权限覆盖' => 'user_permission_override',
            '客服扣点' => 'customer_service_deduction',
        ] as $label => $legacyKey) {
            $defaults[$label] = $label;
            $legacyByLabel[$label] = $legacyKey;
        }

        $catalog = [];
        foreach (array_keys($defaults) as $permission) {
            $catalog[] = [
                'key' => $permission,
                'label' => $permission,
                'group' => $this->groupForPermission($permission),
                'description' => $this->descriptionForPermission($permission),
                'legacy_key' => $legacyByLabel[$permission] ?? '',
            ];
        }

        usort($catalog, static fn (array $left, array $right): int => strcmp(
            (string) $left['group'] . (string) $left['label'],
            (string) $right['group'] . (string) $right['label']
        ));

        return $catalog;
    }

    /**
     * @param array<string, mixed> $states
     * @return array{allow: array<int, string>, deny: array<int, string>}
     */
    public function normalizeSubmittedStates(array $states): array
    {
        $known = array_flip(array_map(static fn (array $item): string => (string) $item['key'], $this->catalog()));
        $allow = [];
        $deny = [];
        foreach ($states as $key => $state) {
            $key = trim((string) $key);
            if ($key === '' || !isset($known[$key])) {
                continue;
            }
            if ($state === 'allow') {
                $allow[] = $key;
            } elseif ($state === 'deny') {
                $deny[] = $key;
            }
        }

        return [
            'allow' => array_values(array_unique($allow)),
            'deny' => array_values(array_unique($deny)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $matrix
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupMatrix(array $matrix): array
    {
        $groups = [];
        foreach ($matrix as $item) {
            $groups[(string) $item['group']][] = $item;
        }

        return $groups;
    }

    /**
     * @param mixed $raw
     * @return array{allow: array<int, string>, deny: array<int, string>}
     */
    private function normalizeStoredOverrides(mixed $raw): array
    {
        if (!is_array($raw)) {
            return ['allow' => [], 'deny' => []];
        }

        return [
            'allow' => array_values(array_filter(array_map('trim', (array) ($raw['allow'] ?? [])))),
            'deny' => array_values(array_filter(array_map('trim', (array) ($raw['deny'] ?? [])))),
        ];
    }

    private function groupForPermission(string $permission): string
    {
        foreach ([
            '订单' => ['订单', '货源', '批量', '客户资料'],
            '履约物流' => ['采购', '日本仓', '物流', '1688', '异常运费'],
            '经营分析' => ['业绩', '利润', '商品', '统计', '性能'],
            '邮件图片' => ['邮件', '图片'],
            '管理' => ['员工', '店铺', '公司', '系统', '权限', '公告', '通知', '客服扣点'],
        ] as $group => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($permission, $needle)) {
                    return $group;
                }
            }
        }

        return '其他';
    }

    private function descriptionForPermission(string $permission): string
    {
        return match ($permission) {
            '公告管理' => '发布、编辑、置顶和归档租户内公告。',
            '通知查看' => '查看租户内面向员工发布的公告。',
            '权限覆盖' => '编辑单个员工的 allow/deny 权限覆盖。',
            '客服扣点' => '维护客服账号利润扣点。',
            default => '控制该功能入口或相关操作权限。',
        };
    }
}
