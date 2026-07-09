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
use Xizhen\Services\OrderPageConfigRegistry;
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

final class OrderController extends TenantBaseController
{

    public function orders(): void
    {
        $tenantKey = current_tenant_key();
        $this->auth->requireTenantPermission($tenantKey, '订单查看');
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $view = $_GET['view'] ?? 'platform';
        $view = in_array($view, ['platform', 'purchase', 'jp'], true) ? $view : 'platform';
        $this->requireTenantFeature($tenantKey, match ($view) {
            'purchase' => 'orders.purchase',
            'jp' => 'orders.jp',
            default => 'orders.platform',
        });
        if ($view === 'purchase') {
            $this->auth->requireTenantPermission($tenantKey, '采购订单');
        }
        if ($view === 'jp') {
            $this->auth->requireTenantPermission($tenantKey, '日本仓发货');
        }
        $platform = trim((string) ($_GET['platform'] ?? ''));
        $this->ensurePlatformFeatureAccess($tenantKey, $platform !== '' ? $platform : null);
        $source = $_GET['source'] ?? 'all';
        $orderPageConfigRegistry = new OrderPageConfigRegistry($this->store, $tenantKey);
        $query = $orderPageConfigRegistry->normalizeFilterInput($platform, $_GET);
        $keyword = $this->keywordFrom($query);
        $filters = $this->orderFiltersFrom($query, $keyword);
        $filters['date_scope'] = $this->orderDateScope($view);
        $filters['default_pending'] = '1';
        $canEditFeature = $this->service->tenantFeatureEnabled($tenantKey, 'orders.edit');
        $canPlatformImportExport = $this->service->tenantFeatureEnabled($tenantKey, 'import_export.center')
            && $this->service->tenantFeatureEnabled($tenantKey, 'import_export.platform')
            && Permission::has($currentUser, '导入导出');
        $canPurchaseImportExport = $this->service->tenantFeatureEnabled($tenantKey, 'import_export.center')
            && $this->service->tenantFeatureEnabled($tenantKey, 'import_export.purchase')
            && Permission::hasAny($currentUser, ['导入导出', '采购导入导出']);
        $canFinanceExport = $this->service->tenantFeatureEnabled($tenantKey, 'import_export.center')
            && $this->service->tenantFeatureEnabled($tenantKey, 'import_export.finance')
            && Permission::has($currentUser, '导入导出');

        $orders = $this->service->filterOrdersForView(
            $this->service->ordersForUser($tenantKey, $currentUser),
            $view,
            $platform !== '' ? $platform : null,
            (string) $source,
            $keyword !== '' ? $keyword : null,
            $filters
        );

        $tenant = $this->store->tenant($tenantKey);
        $statusOptions = $this->service->purchaseStatuses($tenantKey);
        $jpStockStatusOptions = $this->purchaseStatusService->jpStockStatusesFor($tenantKey);
        $settings = $this->store->tenantSettings($tenantKey);
        $logisticsSettings = is_array($settings['logistics'] ?? null) ? $settings['logistics'] : [];
        $receiptCityOptions = $this->receiptCityOptionsFromSettings((string) ($logisticsSettings['domestic_receive_places'] ?? ''));
        $stores = $this->service->storesForTenant($tenantKey);
        if ($platform !== '') {
            $stores = array_values(array_filter($stores, static fn (array $store): bool => (string) ($store['platform'] ?? '') === $platform));
        }
        $this->view->render('tenant/orders', [
            'title' => $this->viewTitle($view),
            'tenantKey' => $tenantKey,
            'tenant' => $tenant,
            'menu' => $this->service->platformMenu($tenantKey),
            'tenantFeatures' => $this->service->tenantFeatureMap($tenantKey),
            'active' => $view,
            'orders' => $orders,
            'orderView' => $view,
            'platform' => $platform !== '' ? $platform : null,
            'source' => $source,
            'keyword' => $keyword,
            'filters' => $filters,
            'platformNames' => $this->service->tenantPlatformNames($tenantKey),
            'platformSyncServices' => $this->platformOrderSyncRegistry->names(),
            'wowmaSyncFolders' => $this->wowmaSyncFoldersFromSettings($settings),
            'stores' => $stores,
            'statusOptions' => $statusOptions,
            'jpStockStatusOptions' => $jpStockStatusOptions,
            'receiptCityOptions' => $receiptCityOptions,
            'filterFields' => $orderPageConfigRegistry->filterFieldsFor($platform),
            'exportTools' => $orderPageConfigRegistry->exportToolsFor($platform, $currentUser ?? []),
            'canEditOrders' => $canEditFeature && $this->auth->tenantCan($tenantKey, '订单编辑'),
            'canEditSource' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.platform') && Permission::hasAny($currentUser, ['订单编辑', '货源改判']),
            'canEditPurchase' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.purchase') && Permission::hasAny($currentUser, ['订单编辑', '采购状态']),
            'canEditJp' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.jp') && Permission::hasAny($currentUser, ['订单编辑', '日本仓发货']),
            'canChangeSource' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.platform') && $this->auth->tenantCan($tenantKey, '货源改判'),
            'canBatchOperate' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.platform') && $this->auth->tenantCan($tenantKey, '批量操作'),
            'canBatchPurchase' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.purchase') && Permission::hasAny($currentUser, ['批量操作', '采购状态', '订单编辑']),
            'canBatchJp' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.jp') && Permission::hasAny($currentUser, ['批量操作', '日本仓发货', '订单编辑']),
            'canUploadImage' => $this->service->tenantFeatureEnabled($tenantKey, 'media.library') && $this->service->tenantFeatureEnabled($tenantKey, 'media.upload') && $this->auth->tenantCan($tenantKey, '图片上传'),
            'canDeleteImage' => $this->service->tenantFeatureEnabled($tenantKey, 'media.library') && $this->service->tenantFeatureEnabled($tenantKey, 'media.delete') && $this->auth->tenantCan($tenantKey, '图片删除'),
            'canImportExport' => $canPlatformImportExport || $canPurchaseImportExport || $canFinanceExport,
            'canFullImportExport' => $canPlatformImportExport || $canFinanceExport,
            'canPlatformImportExport' => $canPlatformImportExport,
            'canPurchaseImportExport' => $canPurchaseImportExport,
            'canFinanceExport' => $canFinanceExport,
            'can1688Logistics' => $this->service->tenantFeatureEnabled($tenantKey, 'logistics.1688') && Permission::has($currentUser, '1688物流'),
            'canExpressLogistics' => $this->service->tenantFeatureEnabled($tenantKey, 'logistics.express') && Permission::hasAny($currentUser, ['1688物流', '物流查看']),
            'canJpLogistics' => $this->service->tenantFeatureEnabled($tenantKey, 'logistics.jp') && Permission::hasAny($currentUser, ['日本物流日志', '物流查看']),
            'tenantNotices' => $this->tenantNoticeService->orderPageNotices($tenantKey, $currentUser),
            'currentUser' => $currentUser,
            'deleteChallenge' => $this->batchDeleteChallenge($tenantKey),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function receiptCityOptionsFromSettings(string $places): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', preg_split('/[\r\n,，、]+/u', $places) ?: []),
            static fn (string $place): bool => $place !== ''
        )));
    }

    public function changeSource(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'orders.platform');
        $this->requireTenantFeature($tenantKey, 'orders.edit');
        $this->auth->requireTenantPermission($tenantKey, '货源改判');
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $source = (string) ($_POST['source'] ?? 'pending');
        $return = (string) ($_POST['return'] ?? "/orders?tenant={$tenantKey}");

        if ($itemId > 0) {
            $this->ensureItemAccess($tenantKey, $itemId);
            $this->store->changeItemSource($tenantKey, $itemId, $source);
        }

        redirect_to($this->urlWithNotice($return, 'message', '货源地已保存。'));
    }

    public function batchOrders(): void
    {
        $tenantKey = current_tenant_key();
        $action = (string) ($_POST['batch_action'] ?? '');
        $view = in_array(($_POST['view'] ?? ''), ['platform', 'purchase', 'jp'], true) ? (string) $_POST['view'] : '';
        $this->requireTenantFeature($tenantKey, 'orders.edit');
        $this->requireTenantFeature($tenantKey, match ($action) {
            'delete_orders', 'set_source' => 'orders.platform',
            'assign_jp', 'mark_out' => 'orders.jp',
            'set_purchase_status' => $view === 'jp' ? 'orders.jp' : 'orders.purchase',
            default => 'orders.purchase',
        });
        $this->requireBatchActionPermission($tenantKey, $action, $view);
        $return = (string) ($_POST['return'] ?? "/orders?tenant={$tenantKey}");
        $itemIds = $this->intList($_POST['item_ids'] ?? []);
        $orderIds = $this->intList($_POST['order_ids'] ?? []);
        if (!$this->auth->tenantCan($tenantKey, '批量操作')) {
            $orderIds = [];
        }
        $this->ensureBatchAccess($tenantKey, $itemIds, $orderIds);

        if ($action === 'delete_orders') {
            $this->assertBatchDeleteChallenge($tenantKey);
            if (!$orderIds && $itemIds) {
                $orderIds = $this->orderIdsForItems($tenantKey, $itemIds);
            }
            $count = count($orderIds);
            $this->store->deleteOrders($tenantKey, $orderIds);
            redirect_to($this->urlWithNotice($return, 'message', "已删除 {$count} 单。"));
        }

        if ($action === 'set_source') {
            $source = (string) ($_POST['source'] ?? '');
            $affected = 0;
            if (in_array($source, ['cn_purchase', 'jp_stock', 'pending'], true)) {
                $targetItemIds = $this->itemIdsForOrders($tenantKey, $itemIds, $orderIds);
                foreach ($targetItemIds as $itemId) {
                    $this->store->changeItemSource($tenantKey, $itemId, $source);
                }
                $affected = count($targetItemIds);
            }
            redirect_to($this->urlWithNotice($return, 'message', "货源地已保存，处理 {$affected} 条明细。"));
        }

        $changes = match ($action) {
            'set_purchase_status' => ['purchase_status' => (string) ($_POST['purchase_status'] ?? '')],
            'assign_buyer' => ['buyer' => (string) ($_POST['buyer'] ?? '')],
            'assign_jp' => [
                'assignee' => (string) ($_POST['assignee'] ?? ''),
                'out_status' => '已分配',
            ],
            'mark_out' => ['out_status' => '已出库'],
            default => [],
        };

        $changes = array_filter($changes, fn (mixed $value): bool => trim((string) $value) !== '');
        if ($changes) {
            $this->store->batchUpdateItems(
                $tenantKey,
                $itemIds,
                $orderIds,
                $changes,
                $this->currentUserName($tenantKey),
                $this->batchActionLogName($action)
            );
        }

        $message = $changes ? $this->batchActionLogName($action) . '已保存。' : '没有可保存的变更。';
        redirect_to($this->urlWithNotice($return, $changes ? 'message' : 'error', $message));
    }

    public function orderDetail(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'orders.platform');
        $this->auth->requireTenantPermission($tenantKey, '订单查看');
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $orderId = (int) ($_GET['id'] ?? 0);
        $order = $this->store->order($tenantKey, $orderId);
        if (!$order) {
            http_response_code(404);
            echo '订单不存在';
            return;
        }
        $this->ensurePlatformFeatureAccess($tenantKey, (string) ($order['platform'] ?? ''));
        if (!Permission::canAccessStore($currentUser, (string) ($order['store'] ?? ''))) {
            $this->forbid('当前账号没有该店铺订单的查看权限。');
        }
        $canEditFeature = $this->service->tenantFeatureEnabled($tenantKey, 'orders.edit');

        $this->renderTenant('tenant/order_detail', $tenantKey, [
            'title' => '订单详情',
            'active' => 'platform',
            'order' => $order,
            'attachments' => $this->store->orderAttachments($tenantKey, $orderId),
            'platformNames' => $this->service->tenantPlatformNames($tenantKey),
            'statusOptions' => $this->service->purchaseStatuses($tenantKey),
            'jpStockStatusOptions' => $this->purchaseStatusService->jpStockStatusesFor($tenantKey),
            'returnUrl' => (string) ($_GET['return'] ?? "/orders?tenant={$tenantKey}"),
            'canEditOrders' => $canEditFeature && $this->auth->tenantCan($tenantKey, '订单编辑'),
            'canEditPurchase' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.purchase') && Permission::hasAny($currentUser, ['订单编辑', '采购状态']),
            'canEditJp' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.jp') && Permission::hasAny($currentUser, ['订单编辑', '日本仓发货']),
            'canChangeSource' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.platform') && $this->auth->tenantCan($tenantKey, '货源改判'),
            'canUploadImage' => $this->service->tenantFeatureEnabled($tenantKey, 'media.library') && $this->service->tenantFeatureEnabled($tenantKey, 'media.upload') && $this->auth->tenantCan($tenantKey, '图片上传'),
            'canDeleteImage' => $this->service->tenantFeatureEnabled($tenantKey, 'media.library') && $this->service->tenantFeatureEnabled($tenantKey, 'media.delete') && $this->auth->tenantCan($tenantKey, '图片删除'),
        ]);
    }

    public function saveOrderItem(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'orders.edit');
        $this->auth->requireAnyTenantPermission($tenantKey, ['订单编辑', '采购状态', '日本仓发货', '货源改判']);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $return = (string) ($_POST['return'] ?? "/orders/detail?tenant={$tenantKey}&id={$orderId}");
        $this->ensureItemAccess($tenantKey, $itemId);
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $order = $this->accessibleOrderForItem($tenantKey, $itemId, $currentUser);
        $rules = new OrderItemSaveRuleService();
        $currentItem = $order ? $rules->findItem($order, $itemId) : null;
        if (!$order || !$currentItem) {
            $this->forbid('当前账号没有该子商品的操作权限。');
        }

        $data = $this->allowedOrderItemPostData($tenantKey);
        $data = $rules->withAutoBuyer($data, $currentItem, $currentUser);
        if (!$data) {
            $this->forbid('当前租户未开通可保存的订单字段，请联系 SaaS 超级管理员。');
        }
        $syncPlan = $rules->sameItemSyncPlan($order, $itemId, $data);

        $this->store->updateOrderItem($tenantKey, $itemId, $data, $this->currentUserName($tenantKey), '保存明细');
        if ($syncPlan['item_ids'] && $syncPlan['changes']) {
            $this->store->batchUpdateItems(
                $tenantKey,
                $syncPlan['item_ids'],
                [],
                $syncPlan['changes'],
                $this->currentUserName($tenantKey),
                '同品项同步修改'
            );
        }
        redirect_to($this->urlWithNotice($return, 'message', '订单明细已保存。'));
    }

    public function refresh1688Order(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'orders.edit');
        $this->requireTenantFeature($tenantKey, 'logistics.1688');
        $this->auth->requireAnyTenantPermission($tenantKey, ['订单编辑', '采购状态', '1688物流']);

        $itemId = (int) ($_POST['item_id'] ?? 0);
        $tabaono = (string) ($_POST['tabaono'] ?? '');
        $this->ensureItemAccess($tenantKey, $itemId);

        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $result = $this->alibaba1688LogisticsService->syncItem(
            $tenantKey,
            $itemId,
            $tabaono,
            $this->currentUserName($tenantKey),
            $currentUser
        );
        $this->json($result, $result['ok'] ? 200 : 422);
    }

    public function addOrderAttachment(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'media.library');
        $this->requireTenantFeature($tenantKey, 'media.upload');
        $this->auth->requireTenantPermission($tenantKey, '图片上传');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $this->ensureOrderAccess($tenantKey, $orderId);
        $this->store->addOrderAttachment($tenantKey, $orderId, [
            'order_item_id' => $_POST['order_item_id'] ?? 0,
            'type' => $_POST['type'] ?? '附件',
            'title' => $_POST['title'] ?? '',
            'path' => $_POST['path'] ?? '',
            'source' => $_POST['source'] ?? '手工登记',
            'uploaded_by' => $_POST['uploaded_by'] ?? '租户管理员',
            'size' => $_POST['size'] ?? '',
        ]);

        redirect_to('/orders/detail?tenant=' . rawurlencode($tenantKey) . '&id=' . $orderId . '&message=' . rawurlencode('附件已登记。'));
    }

    public function deleteOrderAttachment(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'media.library');
        $this->requireTenantFeature($tenantKey, 'media.delete');
        $this->auth->requireTenantPermission($tenantKey, '图片删除');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $this->ensureOrderAccess($tenantKey, $orderId);
        $this->store->deleteOrderAttachment($tenantKey, $orderId, (int) ($_POST['attachment_id'] ?? 0));
        redirect_to('/orders/detail?tenant=' . rawurlencode($tenantKey) . '&id=' . $orderId . '&message=' . rawurlencode('附件已删除。'));
    }

    public function uploadOrderImage(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'media.library');
        $this->requireTenantFeature($tenantKey, 'media.upload');
        $this->auth->requireTenantPermission($tenantKey, '图片上传');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $this->ensureOrderAccess($tenantKey, $orderId);
        if ($itemId > 0) {
            $this->ensureItemAccess($tenantKey, $itemId);
        }
        $kind = (string) ($_POST['kind'] ?? 'attachment');
        $kind = in_array($kind, ['main', 'sku', 'attachment'], true) ? $kind : 'attachment';
        $fallback = '/orders/detail?tenant=' . rawurlencode($tenantKey) . '&id=' . $orderId;
        $return = $this->safeReturn((string) ($_POST['return'] ?? $fallback), $fallback);

        $saved = $this->saveUploadedImage($tenantKey, $orderId, $itemId, $kind);
        if ($saved !== null) {
            if (in_array($kind, ['main', 'sku'], true) && $itemId > 0) {
                $this->store->updateOrderItemImage($tenantKey, $itemId, $kind, $saved['path']);
            } else {
                $this->store->addOrderAttachment($tenantKey, $orderId, [
                    'order_item_id' => $itemId,
                    'type' => (string) ($_POST['type'] ?? '订单图片'),
                    'title' => (string) ($_POST['title'] ?? $saved['name']),
                    'path' => $saved['path'],
                    'source' => (string) ($_POST['source'] ?? ($saved['source'] ?? '图片上传')),
                    'uploaded_by' => (string) ($_POST['uploaded_by'] ?? '租户管理员'),
                    'size' => $saved['size'],
                ]);
            }
        }

        if ($this->wantsJsonResponse()) {
            if ($saved === null) {
                $this->json(['ok' => false, 'message' => '图片上传失败，请确认图片格式和大小。'], 422);
            }
            $this->json([
                'ok' => true,
                'message' => '图片已保存。',
                'path' => $saved['path'],
                'kind' => $kind,
                'item_id' => $itemId,
            ]);
        }

        redirect_to($this->urlWithNotice($return, $saved === null ? 'error' : 'message', $saved === null ? '图片上传失败，请确认图片格式和大小。' : '图片已保存。'));
    }

    public function deleteOrderImage(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'media.library');
        $this->requireTenantFeature($tenantKey, 'media.delete');
        $this->auth->requireTenantPermission($tenantKey, '图片删除');
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $kind = (string) ($_POST['kind'] ?? '');
        $kind = in_array($kind, ['main', 'sku'], true) ? $kind : '';
        $fallback = '/orders/detail?tenant=' . rawurlencode($tenantKey) . '&id=' . $orderId;
        $return = $this->safeReturn((string) ($_POST['return'] ?? $fallback), $fallback);
        $this->ensureOrderAccess($tenantKey, $orderId);
        $this->ensureItemAccess($tenantKey, $itemId);
        $itemOrder = $this->accessibleOrderForItem($tenantKey, $itemId, $this->auth->currentTenantUser($tenantKey));
        if ($kind === '' || (int) ($itemOrder['id'] ?? 0) !== $orderId) {
            $this->forbid('当前账号没有该子商品图片的操作权限。');
        }

        $oldPath = $this->store->deleteOrderItemImage($tenantKey, $itemId, $kind);
        $this->deleteLocalOrderImageFile($tenantKey, $oldPath);

        if ($this->wantsJsonResponse()) {
            $this->json([
                'ok' => true,
                'message' => '图片已削除。',
                'kind' => $kind,
                'item_id' => $itemId,
            ]);
        }

        redirect_to($this->urlWithNotice($return, 'message', '图片已删除。'));
    }

    public function serveOrderImage(): void
    {
        $tenantKey = $this->tenantKeyFromRoute();
        $orderId = (int) ($_GET['order_id'] ?? 0);
        $itemId = (int) ($_GET['item_id'] ?? 0);
        $this->auth->requireTenant($tenantKey);
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $this->closeSessionForImageResponse();
        $order = $this->store->order($tenantKey, $orderId);
        if (!$this->canServeOrderItemImage($order, $itemId, $currentUser)) {
            http_response_code(404);
            return;
        }

        $this->sendTenantImage($tenantKey, "images/orders/{$orderId}/{$itemId}", (string) ($_GET['filename'] ?? ''));
    }

    public function serveItemImage(): void
    {
        $tenantKey = current_tenant_key();
        $orderId = (int) ($_GET['order_id'] ?? 0);
        $itemId = (int) ($_GET['item_id'] ?? 0);
        $this->auth->requireTenant($tenantKey);
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $this->closeSessionForImageResponse();
        $order = $this->store->order($tenantKey, $orderId);
        if (!$order || !Permission::canAccessStore($currentUser, (string) ($order['store'] ?? ''))) {
            $this->sendNoImage();
        }

        foreach (($order['items'] ?? []) as $item) {
            if ((int) ($item['id'] ?? 0) === $itemId) {
                $this->sendItemImageSource($tenantKey, $orderId, $itemId, $order, is_array($item) ? $item : []);
            }
        }

        $this->sendNoImage();
    }

    public function serveUploadedImage(): void
    {
        $tenantKey = $this->tenantKeyFromRoute();
        $orderId = (int) ($_GET['order_id'] ?? 0);
        $this->auth->requireTenant($tenantKey);
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $this->closeSessionForImageResponse();
        $order = $this->store->order($tenantKey, $orderId);
        if (!$order || !Permission::canAccessStore($currentUser, (string) ($order['store'] ?? ''))) {
            http_response_code(404);
            return;
        }

        $this->sendTenantImage($tenantKey, "images/uploads/{$orderId}", (string) ($_GET['filename'] ?? ''));
    }

    private function batchActionLogName(string $action): string
    {
        return match ($action) {
            'set_purchase_status' => '批量修改采购状态',
            'assign_buyer' => '批量分配采购人',
            'set_source' => '批量改货源地',
            'assign_jp' => '批量分配日本仓',
            'mark_out' => '批量标记出库',
            default => '批量更新',
        };
    }

    private function ensureOrderAccess(string $tenantKey, int $orderId): void
    {
        if ($orderId <= 0) {
            $this->forbid('订单不存在或参数不完整。');
        }

        $order = $this->store->order($tenantKey, $orderId);
        if ($order && !$this->service->platformEnabled($tenantKey, (string) ($order['platform'] ?? ''))) {
            $this->forbid('当前租户未开通该订单平台，请联系 SaaS 超级管理员。');
        }
        if (!$order || !Permission::canAccessStore($this->auth->currentTenantUser($tenantKey), (string) ($order['store'] ?? ''))) {
            $this->forbid('当前账号没有该店铺订单的操作权限。');
        }
    }

    private function ensureItemAccess(string $tenantKey, int $itemId): void
    {
        if ($itemId <= 0) {
            $this->forbid('子商品不存在或参数不完整。');
        }

        foreach ($this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey)) as $order) {
            foreach ($order['items'] ?? [] as $item) {
                if ((int) ($item['id'] ?? 0) === $itemId) {
                    return;
                }
            }
        }

        $this->forbid('当前账号没有该子商品的操作权限。');
    }

    private function accessibleOrderForItem(string $tenantKey, int $itemId, ?array $user): ?array
    {
        if ($itemId <= 0) {
            return null;
        }

        foreach ($this->service->ordersForUser($tenantKey, $user) as $order) {
            foreach ($order['items'] ?? [] as $item) {
                if ((int) ($item['id'] ?? 0) === $itemId) {
                    return $order;
                }
            }
        }

        return null;
    }

    private function requireBatchActionPermission(string $tenantKey, string $action, string $view = ''): void
    {
        match ($action) {
            'delete_orders' => $this->auth->requireTenantPermission($tenantKey, '批量操作'),
            'set_source' => $this->auth->requireTenantPermission($tenantKey, '货源改判'),
            'set_purchase_status' => $view === 'jp'
                ? $this->auth->requireAnyTenantPermission($tenantKey, ['批量操作', '日本仓发货', '订单编辑'])
                : $this->auth->requireAnyTenantPermission($tenantKey, ['批量操作', '采购状态', '订单编辑']),
            'assign_buyer' => $this->auth->requireAnyTenantPermission($tenantKey, ['批量操作', '采购状态', '订单编辑']),
            'assign_jp', 'mark_out' => $this->auth->requireAnyTenantPermission($tenantKey, ['批量操作', '日本仓发货', '订单编辑']),
            default => $this->auth->requireTenantPermission($tenantKey, '批量操作'),
        };
    }

    private function allowedOrderItemPostData(string $tenantKey): array
    {
        $data = [];
        $postField = static function (string $field, array &$target): void {
            if (array_key_exists($field, $_POST)) {
                $target[$field] = (string) $_POST[$field];
            }
        };
        if (
            $this->service->tenantFeatureEnabled($tenantKey, 'orders.platform')
            && ($this->auth->tenantCan($tenantKey, '货源改判') || $this->auth->tenantCan($tenantKey, '订单编辑'))
        ) {
            $postField('source_type', $data);
        }
        if (
            $this->service->tenantFeatureEnabled($tenantKey, 'orders.purchase')
            && ($this->auth->tenantCan($tenantKey, '采购状态') || $this->auth->tenantCan($tenantKey, '订单编辑'))
        ) {
            foreach ([
                'purchase_status',
                'purchase_time',
                'purchase_link',
                'buhuo_link',
                'amount',
                'cn_amount',
                'com_amount',
                'tabaono',
                'caigou_ordernums',
                'ship_company',
                'ship_number',
                'ship_quantity',
                'receipt_city',
                'logistics',
                'logistic_trace',
                'material',
                'weight',
                'chinese_option',
                'comment',
                'tranship_comment',
            ] as $field) {
                $postField($field, $data);
            }
        }
        if (
            $this->service->tenantFeatureEnabled($tenantKey, 'orders.jp')
            && ($this->auth->tenantCan($tenantKey, '日本仓发货') || $this->auth->tenantCan($tenantKey, '订单编辑'))
        ) {
            foreach (['assignee', 'out_status', 'jp_warehouse_id', 'intl_number', 'intl_fee', 'intl_qty', 'intl_weight', 'intl_comment'] as $field) {
                $postField($field, $data);
            }
        }

        return $data;
    }

    private function viewTitle(string $view): string
    {
        return match ($view) {
            'purchase' => '采购订单',
            'jp' => '日本仓发货',
            default => '平台订单',
        };
    }

    private function orderIdsForItems(string $tenantKey, array $itemIds): array
    {
        $ids = [];
        foreach ($this->store->orders($tenantKey) as $order) {
            foreach ($order['items'] ?? [] as $item) {
                if (in_array((int) ($item['id'] ?? 0), $itemIds, true)) {
                    $ids[] = (int) ($order['id'] ?? 0);
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function itemIdsForOrders(string $tenantKey, array $itemIds, array $orderIds): array
    {
        $ids = $itemIds;
        if (!$orderIds) {
            return array_values(array_unique(array_filter($ids)));
        }

        foreach ($this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey)) as $order) {
            if (!in_array((int) ($order['id'] ?? 0), $orderIds, true)) {
                continue;
            }
            foreach ($order['items'] ?? [] as $item) {
                $ids[] = (int) ($item['id'] ?? 0);
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function tenantKeyFromRoute(): string
    {
        $tenantKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['tenant_key'] ?? '')) ?: '';
        return $tenantKey !== '' ? $tenantKey : current_tenant_key();
    }

    /** @return array{question: string, answer: int} */
    private function batchDeleteChallenge(string $tenantKey): array
    {
        $left = random_int(3, 19);
        $right = random_int(2, 17);
        $challenge = ['question' => "{$left} + {$right}", 'answer' => $left + $right];
        $_SESSION['batch_delete_challenge'][$tenantKey] = $challenge;

        return $challenge;
    }

    private function assertBatchDeleteChallenge(string $tenantKey): void
    {
        $expected = $_SESSION['batch_delete_challenge'][$tenantKey]['answer'] ?? null;
        unset($_SESSION['batch_delete_challenge'][$tenantKey]);
        $provided = trim((string) ($_POST['delete_challenge_answer'] ?? ''));
        if (!is_int($expected) || $provided === '' || (int) $provided !== $expected) {
            $this->forbid('批量删除验证失败，请返回订单页重新输入验证答案。');
        }
    }

    /** @param array<string, mixed> $item */
    private function sendItemImageSource(string $tenantKey, int $orderId, int $itemId, array $order, array $item): never
    {
        foreach ($this->itemImageCandidates($tenantKey, $order, $item) as $source) {
            if ($this->isLocalOrderImagePath($tenantKey, $orderId, $itemId, $source)) {
                $this->sendTenantImage($tenantKey, "images/orders/{$orderId}/{$itemId}", basename($source));
            }
        }
        foreach ($this->itemImageCandidates($tenantKey, $order, $item) as $source) {
            if ($this->isRemoteImageUrl($source)) {
                header('Location: ' . $source, true, 302);
                header('Cache-Control: private, max-age=300');
                exit;
            }
        }

        $this->sendNoImage();
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item @return array<int, string> */
    private function itemImageCandidates(string $tenantKey, array $order, array $item): array
    {
        $extra = is_array($item['platform_extra'] ?? null) ? $item['platform_extra'] : [];
        $candidates = [];
        foreach (['main_image', 'image', 'sku_image'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '' && $value !== '/assets/no-image.svg') {
                $candidates[] = $value;
            }
        }
        foreach (['zhutu', 'skuimg'] as $field) {
            $value = trim((string) ($extra[$field] ?? ''));
            if ($value !== '' && $value !== '/assets/no-image.svg') {
                $candidates[] = $value;
            }
        }
        return array_values(array_unique($candidates));
    }

    private function isRemoteImageUrl(string $source): bool
    {
        $source = trim($source);
        if (!preg_match('#^https?://#i', $source)) {
            return false;
        }
        $path = (string) (parse_url($source, PHP_URL_PATH) ?: '');
        return preg_match('/\.(?:jpe?g|png|gif|webp)(?:$|\?)/i', $path) === 1;
    }

    private function isLocalOrderImagePath(string $tenantKey, int $orderId, int $itemId, string $source): bool
    {
        $source = ltrim(trim($source), '/');
        return str_starts_with($source, "storage/tenants/{$tenantKey}/images/orders/{$orderId}/{$itemId}/");
    }

    /** @param array<string, mixed>|null $order @param array<string, mixed>|null $user */
    private function canServeOrderItemImage(?array $order, int $itemId, ?array $user): bool
    {
        if (!$order || $itemId <= 0 || !Permission::canAccessStore($user, (string) ($order['store'] ?? ''))) {
            return false;
        }

        foreach (($order['items'] ?? []) as $item) {
            if ((int) ($item['id'] ?? 0) === $itemId) {
                return true;
            }
        }

        return false;
    }

    private function closeSessionForImageResponse(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    private function sendTenantImage(string $tenantKey, string $relativeFolder, string $filename): never
    {
        $filename = basename($filename);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        if ($filename === '' || !isset($mimeTypes[$extension])) {
            http_response_code(404);
            exit;
        }

        $baseDir = realpath(BASE_PATH . '/storage/tenants/' . $tenantKey);
        $path = realpath(BASE_PATH . '/storage/tenants/' . $tenantKey . '/' . $relativeFolder . '/' . $filename);
        if ($baseDir === false || $path === false || !str_starts_with(str_replace('\\', '/', $path), str_replace('\\', '/', $baseDir) . '/')) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $mimeTypes[$extension]);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=86400');
        readfile($path);
        exit;
    }

    private function sendNoImage(): never
    {
        $path = BASE_PATH . '/public/assets/no-image.svg';
        if (is_file($path)) {
            header('Content-Type: image/svg+xml');
            header('Content-Length: ' . filesize($path));
            header('Cache-Control: private, max-age=300');
            readfile($path);
            exit;
        }

        http_response_code(404);
        exit;
    }

    private function deleteLocalOrderImageFile(string $tenantKey, ?string $path): void
    {
        $path = trim((string) $path);
        $prefix = "storage/tenants/{$tenantKey}/images/orders/";
        if ($path === '' || !str_starts_with($path, $prefix)) {
            return;
        }

        $absolute = realpath(BASE_PATH . '/' . $path);
        $base = realpath(BASE_PATH . "/storage/tenants/{$tenantKey}/images/orders");
        if ($absolute === false || $base === false || !str_starts_with(str_replace('\\', '/', $absolute), str_replace('\\', '/', $base) . '/')) {
            return;
        }

        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function wantsJsonResponse(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    private function saveUploadedImage(string $tenantKey, int $orderId, int $itemId, string $kind): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $bytes = null;
        $originalName = 'paste-image.png';
        $source = '粘贴上传';

        if (isset($_FILES['image']) && is_array($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmpName = (string) ($_FILES['image']['tmp_name'] ?? '');
            if ($tmpName !== '' && is_uploaded_file($tmpName)) {
                $bytes = file_get_contents($tmpName);
                $originalName = (string) ($_FILES['image']['name'] ?? 'upload-image');
                $source = '文件上传';
            }
        }

        if ($bytes === null && trim((string) ($_POST['base64_image'] ?? '')) !== '') {
            $raw = trim((string) $_POST['base64_image']);
            if (str_contains($raw, ',')) {
                [, $raw] = explode(',', $raw, 2);
            }
            $decoded = base64_decode($raw, true);
            if ($decoded !== false) {
                $bytes = $decoded;
            }
        }

        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > 5 * 1024 * 1024) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if (!is_array($info)) {
            return null;
        }

        $mime = (string) ($info['mime'] ?? '');
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => '',
        };
        if ($extension === '') {
            return null;
        }

        $folder = $kind === 'attachment'
            ? BASE_PATH . "/storage/tenants/{$tenantKey}/images/uploads/{$orderId}"
            : BASE_PATH . "/storage/tenants/{$tenantKey}/images/orders/{$orderId}/{$itemId}";
        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '-', pathinfo($originalName, PATHINFO_FILENAME)) ?: $kind;
        $fileName = $kind . '-' . date('YmdHis') . '-' . substr(sha1($bytes), 0, 8) . '-' . $safeName . '.' . $extension;
        $absolute = $folder . '/' . $fileName;
        file_put_contents($absolute, $bytes);
        $relative = str_replace('\\', '/', $absolute);
        $base = str_replace('\\', '/', BASE_PATH . '/');
        if (str_starts_with($relative, $base)) {
            $relative = substr($relative, strlen($base));
        }

        return [
            'path' => $relative,
            'name' => $originalName,
            'source' => $source,
            'size' => $this->sizeLabel(strlen($bytes)),
        ];
    }

    private function sizeLabel(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . 'MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . 'KB';
        }
        return $bytes . 'B';
    }
}
