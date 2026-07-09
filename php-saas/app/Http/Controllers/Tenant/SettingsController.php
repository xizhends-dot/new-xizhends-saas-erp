<?php

declare(strict_types=1);

namespace Xizhen\Http\Controllers\Tenant;

use Xizhen\Core\Permission;
use Xizhen\Core\StoreInterface;
use Xizhen\Core\View;
use Xizhen\Services\Alibaba1688LogisticsService;
use Xizhen\Services\AppService;
use Xizhen\Services\AuthService;
use Xizhen\Services\CsvImportService;
use Xizhen\Services\CustomerExportService;
use Xizhen\Services\CustomerServiceDeductionService;
use Xizhen\Services\ExpressLogisticsService;
use Xizhen\Services\FinanceExportRequirementService;
use Xizhen\Services\FinanceImportMatcherService;
use Xizhen\Services\ExportFieldRegistry;
use Xizhen\Services\ExportTemplateService;
use Xizhen\Services\JapanWarehouseImportService;
use Xizhen\Services\JapanLogisticsService;
use Xizhen\Services\LegacySettingsService;
use Xizhen\Services\LegacyEdgeToolService;
use Xizhen\Services\MailService;
use Xizhen\Services\OrderAjaxService;
use Xizhen\Services\OrderItemSaveRuleService;
use Xizhen\Services\PerformanceStatsService;
use Xizhen\Services\PlatformExportService;
use Xizhen\Services\PlatformOrderSyncRegistry;
use Xizhen\Services\PriceCalculatorService;
use Xizhen\Services\PurchaseStatsService;
use Xizhen\Services\PurchaseStatusService;
use Xizhen\Services\ShippingAnomalyService;
use Xizhen\Services\ShippingImportModeService;
use Xizhen\Services\ShippingWorkflowService;
use Xizhen\Services\SpreadsheetExportService;
use Xizhen\Services\TenantNoticeService;
use Xizhen\Services\TenantUserSecurityService;
use Xizhen\Services\UserPermissionOverrideService;
use Xizhen\Services\WaybillCheckService;
use Xizhen\Services\YahooShopOAuthService;
use RuntimeException;

final class SettingsController extends TenantBaseController
{

    public function billing(): void
    {
        $tenantKey = current_tenant_key();
        $this->auth->requireTenantCompanyAdmin($tenantKey);
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
    }

    public function tenantNotices(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.notices');
        $this->auth->requireAnyTenantPermission($tenantKey, ['公告管理', '通知查看']);
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $canManageNotices = Permission::has($currentUser, '公告管理');
        $draft = $canManageNotices ? ($this->store->tenantNotice($tenantKey, (int) ($_GET['id'] ?? 0)) ?? []) : [];
        foreach (['published_at', 'expired_at'] as $field) {
            $time = strtotime((string) ($draft[$field] ?? ''));
            $draft[$field . '_input'] = $time === false ? '' : date('Y-m-d\TH:i', $time);
        }
        $this->renderTenant('tenant/tenant_notices', $tenantKey, [
            'title' => '通知公告',
            'active' => 'tenant_notices',
            'notices' => $canManageNotices
                ? $this->store->tenantNotices($tenantKey)
                : $this->tenantNoticeService->tenantNotices($tenantKey, $currentUser, 50),
            'draft' => $draft,
            'canManageNotices' => $canManageNotices,
            'targetRoles' => $canManageNotices ? $this->tenantNoticeService->targetRoles() : [],
            'users' => $canManageNotices ? $this->store->users($tenantKey) : [],
            'message' => (string) ($_GET['message'] ?? ''),
            'errors' => ($_GET['error'] ?? '') !== '' ? ['form' => (string) $_GET['error']] : [],
        ]);
    }

    public function saveTenantNotice(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.notices');
        $this->auth->requireTenantPermission($tenantKey, '公告管理');
        $noticeId = (int) ($_POST['id'] ?? 0);
        $payload = $this->tenantNoticeService->payloadFromInput($tenantKey, $_POST, $this->auth->currentTenantUser($tenantKey) ?? [], $noticeId > 0 ? $noticeId : null);
        if ((string) ($payload['title'] ?? '') === '' || (string) ($payload['body'] ?? '') === '') {
            redirect_to('/notices?tenant=' . rawurlencode($tenantKey) . '&error=' . rawurlencode('公告标题和内容不能为空。'));
        }
        $this->store->saveTenantNotice($tenantKey, $payload);
        redirect_to('/notices?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode('公告已保存。'));
    }

    public function deleteTenantNotice(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.notices');
        $this->auth->requireTenantPermission($tenantKey, '公告管理');
        $this->store->deleteTenantNotice($tenantKey, (int) ($_POST['id'] ?? 0));
        redirect_to('/notices?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode('公告已删除。'));
    }

    public function pinTenantNotice(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.notices');
        $this->auth->requireTenantPermission($tenantKey, '公告管理');
        $this->store->toggleTenantNoticePinned($tenantKey, (int) ($_POST['id'] ?? 0), !empty($_POST['is_pinned']));
        redirect_to('/notices?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode('公告置顶状态已更新。'));
    }

    public function media(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'media.library');
        $this->auth->requireAnyTenantPermission($tenantKey, ['图片管理', '图片上传', '图片删除']);
        $this->renderTenant('tenant/media', $tenantKey, [
            'title' => '租户图片库',
            'active' => 'media',
            'library' => $this->service->tenantMediaLibrary($tenantKey, $this->auth->currentTenantUser($tenantKey)),
        ]);
    }

    public function jobs(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.jobs');
        $this->renderTenant('tenant/jobs', $tenantKey, [
            'title' => '定时任务',
            'active' => 'jobs',
            'jobs' => $this->service->jobDefinitions(),
        ]);
    }

    public function logs(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.logs');
        $this->auth->requireTenantPermission($tenantKey, '订单日志');
        $this->renderTenant('tenant/logs', $tenantKey, [
            'title' => '操作日志',
            'active' => 'logs',
            'logs' => $this->service->auditLogs($tenantKey, $this->auth->currentTenantUser($tenantKey)),
        ]);
    }

    public function settings(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.settings');
        $this->auth->requireAnyTenantPermission($tenantKey, ['公司设置', '系统设置']);
        $settings = $this->store->tenantSettings($tenantKey);
        $tenantApi1688Path = $this->tenant1688ConfigRelativePath($tenantKey);
        $api1688 = is_array($settings['api_1688'] ?? null) ? $settings['api_1688'] : [];
        $settings['api_1688'] = array_replace($api1688, [
            'config_content' => '',
            'config_file' => $tenantApi1688Path,
            'has_config' => is_file($this->tenant1688ConfigAbsolutePath($tenantKey)),
        ]);

        $this->renderTenant('tenant/settings', $tenantKey, [
            'title' => '系统设置',
            'active' => 'settings',
            'settings' => $settings,
            'wowmaSyncFolders' => $this->wowmaSyncFoldersFromSettings($settings),
            'platformNames' => $this->service->tenantPlatformNames($tenantKey, true),
            'purchaseStatuses' => $this->purchaseStatusService->statusesFor($tenantKey),
            'jpStockPurchaseStatuses' => $this->purchaseStatusService->jpStockStatusesFor($tenantKey),
            'systemPurchaseStatuses' => PurchaseStatusService::systemStatuses(),
            'saved' => (string) ($_GET['saved'] ?? ''),
            'error' => (string) ($_GET['error'] ?? ''),
        ]);
    }

    public function saveSettings(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.settings');
        $this->auth->requireAnyTenantPermission($tenantKey, ['公司设置', '系统设置']);
        $api1688Content = trim((string) ($_POST['api_1688_config_content'] ?? ''));
        $api1688ConfigFile = $this->tenant1688ConfigRelativePath($tenantKey);
        if ($api1688Content !== '') {
            $api1688ConfigFile = $this->writeTenant1688Config($tenantKey, $api1688Content);
        }
        $this->store->saveTenantSettings($tenantKey, [
            'company' => [
                'company_name' => trim((string) ($_POST['company_name'] ?? '')),
                'short_name' => trim((string) ($_POST['short_name'] ?? '')),
                'contact' => trim((string) ($_POST['contact'] ?? '')),
                'phone' => trim((string) ($_POST['phone'] ?? '')),
                'address' => trim((string) ($_POST['address'] ?? '')),
                'note' => trim((string) ($_POST['note'] ?? '')),
            ],
            'orders' => [
                'default_page_size' => $this->boundedInt($_POST['default_page_size'] ?? 200, 20, 1000),
                'default_query_days' => $this->boundedInt($_POST['default_query_days'] ?? 30, 1, 365),
                'archive_days' => $this->boundedInt($_POST['archive_days'] ?? 180, 30, 3650),
                'price_warning_index' => $this->boundedFloat($_POST['price_warning_index'] ?? 0, 0, 999999),
                'platform_sync_default_days' => $this->boundedInt($_POST['platform_sync_default_days'] ?? 7, 1, 30),
                'wowma_sync_folders' => $this->wowmaSyncFoldersFromInput($_POST['wowma_sync_folders'] ?? ''),
            ],
            'profit' => [
                'exchange_rate' => $this->boundedFloat($_POST['exchange_rate'] ?? 0.046, 0.0001, 100),
                'exchange_rate_mode' => in_array(($_POST['exchange_rate_mode'] ?? 'fixed'), ['fixed', 'manual'], true) ? (string) $_POST['exchange_rate_mode'] : 'fixed',
                'fixed_exchange_rate' => $this->boundedFloat($_POST['fixed_exchange_rate'] ?? 0.046, 0.0001, 100),
                'default_intl_fee' => $this->boundedFloat($_POST['default_intl_fee'] ?? 820, 0, 999999),
                'platform_deductions' => $this->platformDeductionsFromPost(),
                'store_deduction_enabled' => isset($_POST['store_deduction_enabled']),
                'excluded_purchase_statuses' => $this->excludedPurchaseStatusesFromPost(),
            ],
            'logistics' => [
                'domestic_receive_places' => trim((string) ($_POST['domestic_receive_places'] ?? '')),
            ],
            'api_1688' => [
                'enabled' => isset($_POST['api_1688_enabled']),
                'config_content' => '',
                'config_file' => $api1688ConfigFile,
            ],
        ]);

        redirect_to('/settings?tenant=' . rawurlencode($tenantKey) . '&saved=1');
    }

    public function savePurchaseStatuses(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.settings');
        $this->auth->requireAnyTenantPermission($tenantKey, ['公司设置', '系统设置']);

        if (isset($_POST['reset_purchase_statuses'])) {
            $result = $this->purchaseStatusService->resetStatuses($tenantKey);
        } elseif (isset($_POST['reset_jp_stock_statuses'])) {
            $result = $this->purchaseStatusService->resetJpStockStatuses($tenantKey);
        } else {
            $decoded = json_decode((string) ($_POST['statuses_json'] ?? '[]'), true);
            $jpDecoded = json_decode((string) ($_POST['jp_stock_statuses_json'] ?? '[]'), true);
            if (!is_array($decoded) || !is_array($jpDecoded)) {
                $result = ['ok' => false, 'message' => '采购状态数据格式错误。'];
            } else {
                $result = $this->purchaseStatusService->saveAllStatuses($tenantKey, $decoded, $jpDecoded);
            }
        }

        $queryKey = ($result['ok'] ?? false) ? 'saved' : 'error';
        $message = ($result['ok'] ?? false) ? 'purchase_statuses' : (string) ($result['message'] ?? '采购状态保存失败。');
        redirect_to('/settings?tenant=' . rawurlencode($tenantKey) . '&' . $queryKey . '=' . rawurlencode($message) . '#purchase-statuses');
    }

    private function tenant1688ConfigRelativePath(string $tenantKey): string
    {
        $tenantKey = $this->tenantStorageKey($tenantKey);
        return "storage/tenants/{$tenantKey}/config/1688/apikeys.conf";
    }

    private function tenantStorageKey(string $tenantKey): string
    {
        $tenantKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $tenantKey) ?? '';
        return $tenantKey !== '' ? $tenantKey : 'erp';
    }

    private function tenant1688ConfigAbsolutePath(string $tenantKey): string
    {
        return BASE_PATH . '/' . $this->tenant1688ConfigRelativePath($tenantKey);
    }

    private function writeTenant1688Config(string $tenantKey, string $content): string
    {
        $path = $this->tenant1688ConfigAbsolutePath($tenantKey);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, $content === '' ? '' : $content . PHP_EOL, LOCK_EX);
        return $this->tenant1688ConfigRelativePath($tenantKey);
    }

    private function platformDeductionsFromPost(): array
    {
        $deductions = [];
        $input = is_array($_POST['platform_deductions'] ?? null) ? $_POST['platform_deductions'] : [];
        foreach ($this->service->platformNames() as $code => $_name) {
            $deductions[$code] = $this->boundedFloat($input[$code] ?? 70, 0, 100);
        }

        return $deductions;
    }

    /** @return array<int, string> */
    private function excludedPurchaseStatusesFromPost(): array
    {
        $statuses = [];
        $input = is_array($_POST['excluded_purchase_statuses'] ?? null) ? $_POST['excluded_purchase_statuses'] : [];
        foreach ($input as $status) {
            $status = trim((string) $status);
            if ($status !== '' && !isset($statuses[$status])) {
                $statuses[$status] = true;
            }
        }

        return array_keys($statuses);
    }

    private function boundedFloat(mixed $value, float $min, float $max): float
    {
        $number = is_numeric($value) ? (float) $value : $min;
        return round(max($min, min($max, $number)), 4);
    }

    private function readTenant1688Config(string $tenantKey): string
    {
        $path = $this->tenant1688ConfigAbsolutePath($tenantKey);
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        return trim((string) file_get_contents($path));
    }
}
