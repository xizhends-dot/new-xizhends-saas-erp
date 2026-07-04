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
        $keyword = $this->keywordFrom($_GET);
        $filters = $this->orderFiltersFrom($_GET, $keyword);
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
        $orderPageConfigRegistry = new OrderPageConfigRegistry();

        $orders = $this->service->filterOrdersForView(
            $this->service->ordersForUser($tenantKey, $currentUser),
            $view,
            $platform !== '' ? $platform : null,
            (string) $source,
            $keyword !== '' ? $keyword : null,
            $filters
        );

        $tenant = $this->store->tenant($tenantKey);
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
            'stores' => $this->service->storesForTenant($tenantKey),
            'statusOptions' => $this->service->purchaseStatuses($tenantKey),
            'filterFields' => $orderPageConfigRegistry->filterFieldsFor($platform),
            'exportTools' => $orderPageConfigRegistry->exportToolsFor($platform, $currentUser ?? []),
            'priceDefaults' => $this->priceCalculatorService->defaults($tenantKey),
            'canEditOrders' => $canEditFeature && $this->auth->tenantCan($tenantKey, '订单编辑'),
            'canEditSource' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.platform') && Permission::hasAny($currentUser, ['订单编辑', '货源改判']),
            'canEditPurchase' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.purchase') && Permission::hasAny($currentUser, ['订单编辑', '采购状态']),
            'canEditJp' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.jp') && Permission::hasAny($currentUser, ['订单编辑', '日本仓发货']),
            'canChangeSource' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.platform') && $this->auth->tenantCan($tenantKey, '货源改判'),
            'canBatchOperate' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.platform') && $this->auth->tenantCan($tenantKey, '批量操作'),
            'canBatchPurchase' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.purchase') && Permission::hasAny($currentUser, ['批量操作', '采购状态', '订单编辑']),
            'canBatchJp' => $canEditFeature && $this->service->tenantFeatureEnabled($tenantKey, 'orders.jp') && Permission::hasAny($currentUser, ['批量操作', '日本仓发货', '订单编辑']),
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
        ]);
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

        redirect_to($return);
    }

    public function batchOrders(): void
    {
        $tenantKey = current_tenant_key();
        $action = (string) ($_POST['batch_action'] ?? '');
        $this->requireTenantFeature($tenantKey, 'orders.edit');
        $this->requireTenantFeature($tenantKey, match ($action) {
            'delete_orders' => 'orders.platform',
            'assign_jp', 'mark_out' => 'orders.jp',
            default => 'orders.purchase',
        });
        $this->requireBatchActionPermission($tenantKey, $action);
        $return = (string) ($_POST['return'] ?? "/orders?tenant={$tenantKey}");
        $itemIds = $this->intList($_POST['item_ids'] ?? []);
        $orderIds = $this->intList($_POST['order_ids'] ?? []);
        if (!$this->auth->tenantCan($tenantKey, '批量操作')) {
            $orderIds = [];
        }
        $this->ensureBatchAccess($tenantKey, $itemIds, $orderIds);

        if ($action === 'delete_orders') {
            if (!$orderIds && $itemIds) {
                $orderIds = $this->orderIdsForItems($tenantKey, $itemIds);
            }
            $this->store->deleteOrders($tenantKey, $orderIds);
            redirect_to($return);
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

        redirect_to($return);
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
        redirect_to($return);
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

        redirect_to('/orders/detail?tenant=' . rawurlencode($tenantKey) . '&id=' . $orderId);
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
        redirect_to('/orders/detail?tenant=' . rawurlencode($tenantKey) . '&id=' . $orderId);
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

        redirect_to('/orders/detail?tenant=' . rawurlencode($tenantKey) . '&id=' . $orderId);
    }

    private function batchActionLogName(string $action): string
    {
        return match ($action) {
            'set_purchase_status' => '批量修改采购状态',
            'assign_buyer' => '批量分配采购人',
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

    private function requireBatchActionPermission(string $tenantKey, string $action): void
    {
        match ($action) {
            'delete_orders' => $this->auth->requireTenantPermission($tenantKey, '批量操作'),
            'set_purchase_status', 'assign_buyer' => $this->auth->requireAnyTenantPermission($tenantKey, ['批量操作', '采购状态', '订单编辑']),
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
                'buyer',
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
