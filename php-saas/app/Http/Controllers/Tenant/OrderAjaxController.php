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

final class OrderAjaxController extends TenantBaseController
{

    public function ajaxOrderRow(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'orders.platform');
        $this->auth->requireTenantPermission($tenantKey, '订单查看');
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $canEditFeature = $this->service->tenantFeatureEnabled($tenantKey, 'orders.edit');
        $result = $this->orderAjaxService->orderRow($tenantKey, (int) ($_GET['id'] ?? 0), $currentUser, [
            'orderView' => in_array(($_GET['view'] ?? 'platform'), ['platform', 'purchase', 'jp'], true) ? (string) $_GET['view'] : 'platform',
            'seq' => (int) ($_GET['seq'] ?? 1),
            'batchFormId' => (string) ($_GET['batch_form_id'] ?? 'batch-platform'),
            'returnUrl' => (string) ($_GET['return'] ?? tenant_url('/orders', $tenantKey)),
            'canEditOrders' => $canEditFeature && $this->auth->tenantCan($tenantKey, '订单编辑'),
            'canEditPurchase' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.purchase') && Permission::hasAny($currentUser, ['订单编辑', '采购状态']),
            'canEditJp' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.jp') && Permission::hasAny($currentUser, ['订单编辑', '日本仓发货']),
            'canChangeSource' => $canEditFeature && $this->auth->tenantCan($tenantKey, '货源改判'),
            'canBatchOperate' => $this->auth->tenantCan($tenantKey, '批量操作'),
            'canBatchPurchase' => Permission::hasAny($currentUser, ['批量操作', '采购状态', '订单编辑']),
            'canBatchJp' => Permission::hasAny($currentUser, ['批量操作', '日本仓发货', '订单编辑']),
        ]);
        $this->json($result, (int) $result['status']);
    }

    public function ajaxOrderDetail(): void
    {
        $tenantKey = current_tenant_key();
        $this->auth->requireTenantPermission($tenantKey, '订单查看');
        $result = $this->orderAjaxService->orderDetail($tenantKey, (int) ($_GET['id'] ?? 0), $this->auth->currentTenantUser($tenantKey));
        $this->json($result, (int) $result['status']);
    }

    public function ajaxLogisticsReload(): void
    {
        $tenantKey = current_tenant_key();
        $this->auth->requireAnyTenantPermission($tenantKey, ['物流查看', '1688物流', '日本物流日志']);
        $result = $this->orderAjaxService->logisticsReload($tenantKey, (int) ($_GET['id'] ?? 0), $this->auth->currentTenantUser($tenantKey));
        $this->json($result, (int) $result['status']);
    }

    public function ajaxToggleReview(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'orders.edit');
        $this->auth->requireAnyTenantPermission($tenantKey, ['订单编辑', '货源改判']);
        $result = $this->orderAjaxService->toggleReview(
            $tenantKey,
            (int) ($_POST['order_id'] ?? 0),
            (string) ($_POST['field'] ?? 'review_invited'),
            $this->auth->currentTenantUser($tenantKey),
            $this->currentUserName($tenantKey)
        );
        $this->json($result, (int) $result['status']);
    }
}
