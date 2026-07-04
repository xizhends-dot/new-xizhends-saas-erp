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

final class StoreController extends TenantBaseController
{

    public function stores(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.stores');
        $this->auth->requireTenantPermission($tenantKey, '店铺新增');
        $this->view->render('tenant/stores', [
            'title' => '店铺管理',
            'tenantKey' => $tenantKey,
            'tenant' => $this->store->tenant($tenantKey),
            'menu' => $this->service->platformMenu($tenantKey),
            'tenantFeatures' => $this->service->tenantFeatureMap($tenantKey),
            'active' => 'stores',
            'platformNames' => $this->service->tenantPlatformNames($tenantKey),
            'stores' => $this->service->storesForTenant($tenantKey),
            'billingAccount' => $this->store->tenantBillingAccount($tenantKey),
            'currentUser' => $this->auth->currentTenantUser($tenantKey),
        ]);
    }

    public function addStore(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.stores');
        $this->auth->requireTenantPermission($tenantKey, '店铺新增');
        $platform = trim((string) ($_POST['platform'] ?? ''));
        if ($platform === '') {
            $this->forbid('请选择已开通的店铺平台。');
        }
        $this->ensurePlatformFeatureAccess($tenantKey, $platform);
        $short = trim((string) ($_POST['short'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($short === '' || $name === '') {
            $this->forbid('请填写店铺缩写和店铺全称后再新增。');
        }
        $fee = (int) ($this->store->tenantBillingAccount($tenantKey)['store_add_fee'] ?? 50);
        $operator = $this->currentUserName($tenantKey);
        if (!$this->store->chargeTenantPoints($tenantKey, $fee, "新增店铺：{$name}", $operator)) {
            $this->forbid("当前租户积分余额不足，新增店铺需要 {$fee}pt，请联系 SaaS 超级管理员充值。");
        }
        try {
            $created = $this->store->addStore($tenantKey, [
                'platform' => $platform,
                'legacy_dpid' => $_POST['legacy_dpid'] ?? '',
                'short' => $short,
                'name' => $name,
                'status' => $_POST['status'] ?? 'visible',
                'api_status' => $_POST['api_status'] ?? '未配置',
                'api_config' => $_POST['api_config'] ?? '',
                'profit_deduction' => $_POST['profit_deduction'] ?? 70,
                'hidden_reason' => $_POST['hidden_reason'] ?? '',
            ]);
        } catch (\Throwable $error) {
            $this->store->adjustTenantPoints($tenantKey, $fee, 'adjustment', "新增店铺异常退回：{$name}", $operator);
            throw $error;
        }
        if (!$created) {
            $this->store->adjustTenantPoints($tenantKey, $fee, 'adjustment', "新增店铺失败退回：{$name}", $operator);
            $this->forbid('店铺新增失败，已自动退回本次扣除的积分，请检查店铺资料后重试。');
        }

        redirect_to('/stores?tenant=' . rawurlencode($tenantKey));
    }

    public function editStore(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.stores');
        $this->auth->requireTenantPermission($tenantKey, '店铺新增');
        $storeId = (int) ($_GET['id'] ?? 0);
        $store = $this->store->store($tenantKey, $storeId);
        if (!$store) {
            http_response_code(404);
            echo '店铺不存在';
            return;
        }
        $this->ensurePlatformFeatureAccess($tenantKey, (string) ($store['platform'] ?? ''));

        $this->renderTenant('tenant/store_edit', $tenantKey, [
            'title' => '编辑店铺',
            'active' => 'stores',
            'store' => $store,
            'platformNames' => $this->service->tenantPlatformNames($tenantKey),
            'returnUrl' => (string) ($_GET['return'] ?? "/stores?tenant={$tenantKey}"),
        ]);
    }

    public function updateStore(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.stores');
        $this->auth->requireTenantPermission($tenantKey, '店铺新增');
        $storeId = (int) ($_POST['id'] ?? 0);
        $platform = trim((string) ($_POST['platform'] ?? ''));
        if ($platform === '') {
            $this->forbid('请选择已开通的店铺平台。');
        }
        $this->ensurePlatformFeatureAccess($tenantKey, $platform);
        $this->store->updateStore($tenantKey, $storeId, [
            'platform' => $platform,
            'legacy_dpid' => $_POST['legacy_dpid'] ?? '',
            'short' => $_POST['short'] ?? '',
            'name' => $_POST['name'] ?? '',
            'status' => $_POST['status'] ?? 'visible',
            'api_status' => $_POST['api_status'] ?? '未配置',
            'api_config' => $_POST['api_config'] ?? '',
            'profit_deduction' => $_POST['profit_deduction'] ?? 70,
            'hidden_reason' => $_POST['hidden_reason'] ?? '',
        ]);

        redirect_to('/stores?tenant=' . rawurlencode($tenantKey));
    }

    public function authorizeYahooShop(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.stores');
        $this->auth->requireTenantPermission($tenantKey, '店铺新增');
        $storeId = (int) ($_GET['id'] ?? 0);

        try {
            $url = $this->yahooShopOAuthService->authorizationUrl($tenantKey, $storeId, $this->absoluteUrl('/oauth/yahoo/callback'));
            redirect_to($url);
        } catch (RuntimeException $exception) {
            redirect_to('/stores/edit?tenant=' . rawurlencode($tenantKey) . '&id=' . $storeId . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function yahooOAuthCallback(): void
    {
        try {
            $result = $this->yahooShopOAuthService->handleCallback(
                (string) ($_GET['code'] ?? ''),
                (string) ($_GET['state'] ?? ''),
                $this->absoluteUrl('/oauth/yahoo/callback')
            );
            redirect_to('/stores/edit?tenant=' . rawurlencode($result['tenant_key']) . '&id=' . (int) $result['store_id'] . '&message=' . rawurlencode($result['message']));
        } catch (RuntimeException $exception) {
            $tenantKey = current_tenant_key();
            redirect_to('/stores?tenant=' . rawurlencode($tenantKey) . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    private function absoluteUrl(string $path): string
    {
        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $scheme = $forwardedProto !== ''
            ? explode(',', $forwardedProto)[0]
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $scheme = in_array($scheme, ['http', 'https'], true) ? $scheme : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');

        return $scheme . '://' . $host . $path;
    }
}
