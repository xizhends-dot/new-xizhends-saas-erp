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

final class LogisticsController extends TenantBaseController
{

    public function logistics1688(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'logistics.1688');
        $this->auth->requireAnyTenantPermission($tenantKey, ['1688物流', '1688物流日志']);
        $this->renderTenant('tenant/logistics', $tenantKey, [
            'title' => '1688 物流',
            'active' => 'logistics_1688',
            'type' => '1688',
            'rows' => $this->service->logisticsRows($tenantKey, '1688', $this->auth->currentTenantUser($tenantKey)),
        ]);
    }

    public function logisticsJp(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'logistics.jp');
        $this->auth->requireAnyTenantPermission($tenantKey, ['日本物流日志', '物流查看']);
        $this->renderTenant('tenant/logistics', $tenantKey, [
            'title' => '日本物流',
            'active' => 'logistics_jp',
            'type' => 'jp',
            'rows' => $this->service->logisticsRows($tenantKey, 'jp', $this->auth->currentTenantUser($tenantKey)),
        ]);
    }

    public function logisticsExpress(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'logistics.express');
        $this->auth->requireAnyTenantPermission($tenantKey, ['1688物流', '物流查看']);
        $this->renderTenant('tenant/logistics', $tenantKey, [
            'title' => 'TB/PDD 物流',
            'active' => 'logistics_express',
            'type' => 'express',
            'rows' => $this->service->logisticsRows($tenantKey, 'express', $this->auth->currentTenantUser($tenantKey)),
        ]);
    }

    public function waybillCheck(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'logistics.jp');
        $this->auth->requireAnyTenantPermission($tenantKey, ['日本物流日志', '物流查看']);
        $this->renderTenant('tenant/waybill_check', $tenantKey, [
            'title' => '运单核对',
            'active' => 'waybill_check',
            'report' => $this->waybillCheckService->report($tenantKey, $this->auth->currentTenantUser($tenantKey), $_GET),
            'carrierLabels' => $this->waybillCheckService->japaneseCarrierLabels(),
            'platformNames' => $this->service->tenantPlatformNames($tenantKey),
            'stores' => $this->service->storesForTenant($tenantKey),
        ]);
    }

    public function jpydCheck(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'logistics.jp');
        $this->auth->requireAnyTenantPermission($tenantKey, ['日本物流日志', '物流查看']);
        $result = $this->waybillCheckService->japaneseTrackingUrl(
            $tenantKey,
            (string) ($_GET['number'] ?? $_GET['no'] ?? $_GET['shipno'] ?? ''),
            (string) ($_GET['carrier'] ?? '')
        );
        if ($result['ok'] && $result['url'] !== '') {
            redirect_to($result['url']);
        }

        $return = tenant_url('/logistics/waybill-check?message=' . rawurlencode($result['message'] ?: '未匹配到日本快递跳转链接。'), $tenantKey);
        redirect_to($return);
    }
}
