<?php

declare(strict_types=1);

namespace Xizhen\Http\Controllers\Admin;

use Xizhen\Core\Config;
use Xizhen\Core\StoreInterface;
use Xizhen\Core\TenantFeature;
use Xizhen\Core\View;
use Xizhen\Services\AppService;
use Xizhen\Services\AuthService;
use Xizhen\Services\LegacySettingsService;
use Xizhen\Services\TenantProvisioningService;

final class AdminController
{
    private AppService $service;
    private LegacySettingsService $legacySettings;

    public function __construct(
        private readonly StoreInterface $store,
        private readonly View $view,
        private readonly Config $config,
        private readonly AuthService $auth
    )
    {
        $this->service = new AppService($store);
        $this->legacySettings = new LegacySettingsService(BASE_PATH . '/../old/setting.ini');
    }

    public function loginForm(): void
    {
        if ($this->auth->currentAdmin() !== null) {
            redirect_to('/admin');
        }

        $this->view->render('auth/admin_login', [
            'title' => '超管登录',
            'error' => $_GET['error'] ?? '',
            'returnUrl' => $this->safeReturn((string) ($_GET['return'] ?? '/admin'), '/admin'),
        ], 'layouts/auth');
    }

    public function login(): void
    {
        $returnUrl = $this->safeReturn((string) ($_POST['return'] ?? '/admin'), '/admin');
        $result = $this->auth->loginAdmin(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );

        if ($result['ok']) {
            redirect_to($returnUrl);
        }

        redirect_to('/admin/login?return=' . rawurlencode($returnUrl) . '&error=' . rawurlencode($result['message']));
    }

    public function logout(): void
    {
        $this->auth->logout('admin');
        redirect_to('/admin/login');
    }

    public function overview(): void
    {
        $tenants = $this->service->tenantsWithPlatformLabels();
        $activeTenants = array_filter($tenants, fn (array $tenant): bool => ($tenant['status'] ?? '') === 'active');
        $platformAuthCount = 0;
        foreach ($tenants as $tenant) {
            foreach ($tenant['platforms'] ?? [] as $platform) {
                if ($platform['enabled'] ?? false) {
                    $platformAuthCount++;
                }
            }
        }

        $this->view->render('admin/overview', [
            'title' => '超管概览',
            'active' => 'overview',
            'tenants' => array_slice($tenants, 0, 4),
            'stats' => [
                'tenant_count' => count($tenants),
                'active_tenant_count' => count($activeTenants),
                'staff_count' => array_sum(array_map(fn (array $tenant): int => (int) ($tenant['staff_count'] ?? 0), $tenants)),
                'platform_auth_count' => $platformAuthCount,
            ],
            'diagnostics' => $this->config->diagnostics(),
            'currentAdmin' => $this->auth->currentAdmin(),
        ], 'layouts/admin');
    }

    public function tenants(): void
    {
        $this->view->render('admin/tenants', [
            'title' => '租户管理',
            'active' => 'tenants',
            'tenants' => $this->service->tenantsWithPlatformLabels(),
            'message' => (string) ($_GET['message'] ?? ''),
            'currentAdmin' => $this->auth->currentAdmin(),
        ], 'layouts/admin');
    }

    public function tenantCreateForm(): void
    {
        $this->renderTenantCreate([
            'plan' => 'basic',
            'db_host' => $this->defaultDbHost(),
            'initial_points' => 0,
        ]);
    }

    public function tenantCreate(): void
    {
        $admin = $this->auth->currentAdmin();
        $operator = (string) (($admin['display_name'] ?? '') ?: ($admin['username'] ?? 'superadmin'));
        $values = $_POST;
        $values['operator'] = $operator;
        $result = $this->store->createTenant($values);

        if ($result['ok']) {
            redirect_to('/admin/tenants?message=' . rawurlencode($result['message']));
        }

        $this->renderTenantCreate($values, (string) ($result['message'] ?? '开通失败'));
    }

    public function billing(): void
    {
        $selected = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['tenant'] ?? 'erp')) ?: 'erp';
        $this->view->render('admin/billing', [
            'title' => '租户费用管理',
            'active' => 'billing',
            'selected' => $selected,
            'tenants' => $this->service->tenantsWithPlatformLabels(),
            'account' => $this->store->tenantBillingAccount($selected),
            'ledger' => $this->store->tenantBillingLedger($selected, 80),
            'subscriptions' => $this->store->tenantBillingSubscriptions($selected),
            'message' => (string) ($_GET['message'] ?? ''),
            'currentAdmin' => $this->auth->currentAdmin(),
        ], 'layouts/admin');
    }

    public function adjustBilling(): void
    {
        $tenant = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['tenant'] ?? 'erp')) ?: 'erp';
        $action = (string) ($_POST['action'] ?? 'recharge');
        $amount = max(0, (int) ($_POST['amount'] ?? 0));
        $note = trim((string) ($_POST['note'] ?? ''));
        $admin = $this->auth->currentAdmin();
        $operator = (string) (($admin['display_name'] ?? '') ?: ($admin['username'] ?? 'superadmin'));

        if ($amount <= 0) {
            redirect_to('/admin/billing?tenant=' . rawurlencode($tenant) . '&message=' . rawurlencode('请输入大于 0 的积分数量'));
        }

        if ($action === 'deduct') {
            $ok = $this->store->chargeTenantPoints($tenant, $amount, $note !== '' ? $note : '超管手动扣减', $operator);
            $message = $ok ? "已扣减 {$amount}pt" : '余额不足，扣减失败';
        } else {
            $this->store->adjustTenantPoints($tenant, $amount, 'recharge', $note !== '' ? $note : '超管手动充值', $operator);
            $message = "已充值 {$amount}pt";
        }

        redirect_to('/admin/billing?tenant=' . rawurlencode($tenant) . '&message=' . rawurlencode($message));
    }

    public function processBilling(): void
    {
        $tenant = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['tenant'] ?? 'erp')) ?: 'erp';
        $admin = $this->auth->currentAdmin();
        $operator = (string) (($admin['display_name'] ?? '') ?: ($admin['username'] ?? 'superadmin'));
        $result = $this->store->processDueTenantBilling($tenant, $operator);

        redirect_to('/admin/billing?tenant=' . rawurlencode($tenant) . '&message=' . rawurlencode((string) ($result['message'] ?? '已处理到期扣费。')));
    }

    public function platforms(): void
    {
        $selected = (string) ($_GET['tenant'] ?? 'erp');
        $this->view->render('admin/platforms', [
            'title' => '租户授权',
            'active' => 'platforms',
            'selected' => $selected,
            'tenants' => $this->store->tenants(),
            'platforms' => $this->store->platforms(),
            'auth' => $this->authMap($selected),
            'featureGroups' => TenantFeature::groups(),
            'featureAuth' => TenantFeature::mapFromRows($this->store->tenantFeatures($selected)),
            'currentAdmin' => $this->auth->currentAdmin(),
        ], 'layouts/admin');
    }

    public function togglePlatform(): void
    {
        $tenant = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['tenant'] ?? 'erp')) ?: 'erp';
        $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['platform'] ?? '')) ?: '';
        $field = (string) ($_POST['field'] ?? '');

        if ($platform !== '' && in_array($field, ['enabled', 'locked'], true)) {
            $this->store->togglePlatform($tenant, $platform, $field);
        }

        redirect_to('/admin/platforms?tenant=' . rawurlencode($tenant));
    }

    public function toggleFeature(): void
    {
        $tenant = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['tenant'] ?? 'erp')) ?: 'erp';
        $feature = preg_replace('/[^a-zA-Z0-9_.-]/', '', (string) ($_POST['feature'] ?? '')) ?: '';

        if ($feature !== '') {
            $this->store->toggleTenantFeature($tenant, $feature);
        }

        redirect_to('/admin/platforms?tenant=' . rawurlencode($tenant) . '#tenant-features');
    }

    public function announcements(): void
    {
        $this->view->render('admin/announcements', [
            'title' => '系统公告',
            'active' => 'announcements',
            'announcements' => $this->store->announcements(),
            'currentAdmin' => $this->auth->currentAdmin(),
        ], 'layouts/admin');
    }

    public function settings(): void
    {
        $settings = $this->store->globalSettings();
        if (trim((string) ($settings['updated_at'] ?? '')) === '') {
            $settings = array_replace_recursive($settings, $this->legacySettings->globalSettingsDefaults());
        }

        $this->view->render('admin/settings', [
            'title' => '系统设置',
            'active' => 'settings',
            'settings' => $settings,
            'legacyGroups' => $this->legacySettings->globalGroupsForUi(),
            'message' => (string) ($_GET['message'] ?? ''),
            'saved' => (string) ($_GET['saved'] ?? ''),
            'currentAdmin' => $this->auth->currentAdmin(),
        ], 'layouts/admin');
    }

    public function saveSettings(): void
    {
        $mapping = is_array($_POST['logistics_mapping'] ?? null) ? $_POST['logistics_mapping'] : [];
        $showapi = is_array($_POST['showapi'] ?? null) ? $_POST['showapi'] : [];
        $proxy = is_array($_POST['proxy'] ?? null) ? $_POST['proxy'] : [];

        $this->store->saveGlobalSettings([
            'logistics_mapping' => [
                'yahoo' => trim((string) ($mapping['yahoo'] ?? '')),
                'rakuten' => trim((string) ($mapping['rakuten'] ?? '')),
                'wowma' => trim((string) ($mapping['wowma'] ?? '')),
                'jp_carrier' => trim((string) ($mapping['jp_carrier'] ?? '')),
                'tracking_query' => trim((string) ($mapping['tracking_query'] ?? '')),
            ],
            'showapi' => [
                'app_id' => '',
                'sign' => '',
                'baidu_enabled' => isset($showapi['baidu_enabled']),
                'enabled' => isset($showapi['enabled']),
            ],
            'proxy' => [
                'rotation_proxy' => '',
                'enabled' => isset($proxy['enabled']),
            ],
        ]);

        redirect_to('/admin/settings?saved=1&message=' . rawurlencode('系统设置已保存。'));
    }

    public function systemStatus(): void
    {
        $this->view->render('admin/system_status', [
            'title' => '系统状态',
            'active' => 'system',
            'diagnostics' => $this->config->diagnostics(),
            'currentAdmin' => $this->auth->currentAdmin(),
        ], 'layouts/admin');
    }

    /** @return array<string, array<string, mixed>> */
    private function authMap(string $tenantKey): array
    {
        $map = [];
        foreach ($this->store->tenantPlatforms($tenantKey) as $item) {
            $map[$item['code']] = $item;
        }
        return $map;
    }

    private function safeReturn(string $return, string $fallback): string
    {
        return str_starts_with($return, '/') && !str_starts_with($return, '//') ? $return : $fallback;
    }

    /** @param array<string, mixed> $values */
    private function renderTenantCreate(array $values, string $error = ''): void
    {
        $normalized = TenantProvisioningService::normalizeInput($values, $this->defaultDbHost());
        $this->view->render('admin/tenant_create', [
            'title' => '新增租户',
            'active' => 'tenants',
            'values' => $normalized,
            'error' => $error,
            'baseDomain' => getenv('TENANT_BASE_DOMAIN') ?: 'xizhends.com',
            'currentAdmin' => $this->auth->currentAdmin(),
        ], 'layouts/admin');
    }

    private function defaultDbHost(): string
    {
        if (preg_match('/(?:^|;)host=([^;]+)/', $this->config->mysqlDsn(), $matches)) {
            return $matches[1];
        }

        return '127.0.0.1';
    }
}
