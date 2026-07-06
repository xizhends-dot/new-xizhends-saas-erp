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

final class StatsController extends TenantBaseController
{

    public function profit(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'analytics.profit');
        $this->auth->requireTenantPermission($tenantKey, '利润分析');
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $this->renderTenant('tenant/profit', $tenantKey, [
            'title' => '利润核算分析',
            'active' => 'profit',
            'analysis' => $this->service->profitAnalysis($tenantKey, $currentUser, $_GET),
            'legacySettings' => $this->legacySettings->summary(),
        ]);
    }

    public function purchaseStats(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'stats.purchase');
        $this->auth->requireTenantPermission($tenantKey, '采购统计');
        $this->renderTenant('tenant/purchase_stats_extended', $tenantKey, [
            'title' => '采购业绩统计',
            'active' => 'purchase_stats',
            'stats' => $this->purchaseStatsService->purchaseStats($tenantKey, $this->auth->currentTenantUser($tenantKey), $_GET),
        ]);
    }

    public function purchaseStatusDaily(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'stats.purchase');
        $this->auth->requireTenantPermission($tenantKey, '采购统计');
        $this->renderTenant('tenant/purchase_status_daily', $tenantKey, [
            'title' => '采购状态每日统计',
            'active' => 'purchase_stats',
            'stats' => $this->purchaseStatsService->dailyStatus($tenantKey, $this->auth->currentTenantUser($tenantKey), $_GET),
        ]);
    }

    public function performanceDashboard(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'stats.performance');
        $this->auth->requireTenantPermission($tenantKey, '业绩统计');
        $this->renderTenant('tenant/performance_dashboard', $tenantKey, [
            'title' => '业绩面板',
            'active' => 'performance',
            'dashboard' => $this->performanceStatsService->dashboard($tenantKey, $this->auth->currentTenantUser($tenantKey), $_GET),
        ]);
    }

    public function performanceDailyData(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'stats.performance');
        $this->auth->requireTenantPermission($tenantKey, '业绩统计');
        $this->json($this->performanceStatsService->dailyBreakdown($tenantKey, $this->auth->currentTenantUser($tenantKey), $_GET));
    }

    public function performanceSummary(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'stats.performance');
        $this->auth->requireTenantPermission($tenantKey, '业绩统计');
        $this->renderTenant('tenant/performance_summary', $tenantKey, [
            'title' => '业绩汇总',
            'active' => 'performance',
            'summary' => $this->performanceStatsService->summary($tenantKey, $this->auth->currentTenantUser($tenantKey), $_GET),
        ]);
    }

    public function productAnalysis(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'stats.products');
        $this->auth->requireTenantPermission($tenantKey, '出单商品统计');
        $this->renderTenant('tenant/product_analysis', $tenantKey, [
            'title' => '出单商品分析',
            'active' => 'product_analysis',
            'analysis' => $this->performanceStatsService->productAnalysis($tenantKey, $this->auth->currentTenantUser($tenantKey), $_GET),
        ]);
    }

    public function productAnalysisData(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'stats.products');
        $this->auth->requireTenantPermission($tenantKey, '出单商品统计');
        $this->json($this->performanceStatsService->productAnalysis($tenantKey, $this->auth->currentTenantUser($tenantKey), $_GET));
    }

    public function priceCalculator(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'tools.price_calculator');
        $this->auth->requireTenantPermission($tenantKey, '核价计算器');
        $this->renderTenant('tenant/price_calculator', $tenantKey, [
            'title' => '核价计算器',
            'active' => 'price_calculator',
            'calculator' => [
                'defaults' => $this->priceCalculatorService->defaults($tenantKey),
                'rows' => [],
                'summary' => [],
            ],
        ]);
    }

    public function calculatePrice(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'tools.price_calculator');
        $this->auth->requireTenantPermission($tenantKey, '核价计算器');
        $rows = is_array($_POST['rows'] ?? null) ? $_POST['rows'] : [];
        $this->json($this->priceCalculatorService->calculateRows($tenantKey, $rows));
    }

    public function shippingAnomaly(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'stats.shipping_anomaly');
        $this->auth->requireTenantPermission($tenantKey, '异常运费');
        $currentUser = $this->auth->currentTenantUser($tenantKey);

        if ((string) ($_GET['export'] ?? '') === 'csv') {
            $this->sendCsvDataset($tenantKey, $this->shippingAnomalyService->csvRows($tenantKey, $currentUser, $_GET));
        }

        $this->renderTenant('tenant/shipping_anomaly', $tenantKey, [
            'title' => '异常运费检测',
            'active' => 'shipping_anomaly',
            'result' => $this->shippingAnomalyService->detect($tenantKey, $currentUser, $_GET),
        ]);
    }
}
