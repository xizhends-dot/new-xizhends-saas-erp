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

final class DashboardController extends TenantBaseController
{

    public function dashboard(): void
    {
        $tenantKey = current_tenant_key();
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $tenant = $this->store->tenant($tenantKey);
        $this->view->render('tenant/dashboard', [
            'title' => '首页仪表盘',
            'tenantKey' => $tenantKey,
            'tenant' => $tenant,
            'menu' => $this->service->platformMenu($tenantKey),
            'tenantFeatures' => $this->service->tenantFeatureMap($tenantKey),
            'stats' => $this->service->dashboard($tenantKey, $currentUser),
            'priceDefaults' => $this->priceCalculatorService->defaults($tenantKey),
            'announcements' => $this->store->announcements(),
            'tenantNotices' => $this->tenantNoticeService->dashboardNotices($tenantKey, $currentUser),
            'groups' => $this->service->featureGroups($tenantKey),
            'active' => 'dashboard',
            'currentUser' => $currentUser,
        ]);
    }

    public function features(): void
    {
        $tenantKey = current_tenant_key();
        $this->renderTenant('tenant/features', $tenantKey, [
            'title' => '功能工作台',
            'active' => 'features',
            'groups' => $this->service->featureGroups($tenantKey),
        ]);
    }

    public function search(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'orders.search');
        $this->auth->requireTenantPermission($tenantKey, '订单查看');
        $keyword = trim((string) ($_GET['q'] ?? ''));
        $this->renderTenant('tenant/search', $tenantKey, [
            'title' => '全局搜索',
            'active' => 'search',
            'keyword' => $keyword,
            'results' => $this->service->globalSearchResults($tenantKey, $keyword, $this->auth->currentTenantUser($tenantKey)),
        ]);
    }
}
