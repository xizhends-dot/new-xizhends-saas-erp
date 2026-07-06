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
            'canUploadImage' => $this->service->tenantFeatureEnabled($tenantKey, 'media.library') && $this->service->tenantFeatureEnabled($tenantKey, 'media.upload') && $this->auth->tenantCan($tenantKey, '图片上传'),
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

    public function priceQuote(): void
    {
        $tenantKey = current_tenant_key();
        $this->auth->requireAnyTenantPermission($tenantKey, ['订单查看', '订单编辑']);
        $itemId = (int) ($_GET['item_id'] ?? 0);
        $match = $this->findAccessibleItem($tenantKey, $itemId);
        if ($match === null) {
            $this->json(['ok' => false, 'message' => '订单明细不存在或无权访问。'], 404);
        }

        $quote = $this->priceCalculatorService->quoteOrderItem(
            $tenantKey,
            $match['order'],
            $match['item'],
            [
                'sale_price' => $_GET['sale_price'] ?? null,
                'salePrice' => $_GET['salePrice'] ?? null,
                'shipping' => $_GET['shipping'] ?? null,
                'deduction' => $_GET['deduction'] ?? null,
                'cost' => $_GET['cost'] ?? null,
            ]
        );
        $this->json(['ok' => true, 'message' => '核价已计算。', 'quote' => $quote]);
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

    /**
     * @return array{order: array<string, mixed>, item: array<string, mixed>}|null
     */
    private function findAccessibleItem(string $tenantKey, int $itemId): ?array
    {
        if ($itemId <= 0) {
            return null;
        }

        foreach ($this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey)) as $order) {
            foreach ((array) ($order['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ((int) ($item['id'] ?? 0) === $itemId) {
                    return ['order' => $order, 'item' => $item];
                }
            }
        }

        return null;
    }
}
