<?php

declare(strict_types=1);

namespace Xizhen\Controllers;

use Xizhen\Core\Permission;
use Xizhen\Core\StoreInterface;
use Xizhen\Core\View;
use Xizhen\Services\Alibaba1688LogisticsService;
use Xizhen\Services\AppService;
use Xizhen\Services\AuthService;
use Xizhen\Services\CsvImportService;
use Xizhen\Services\LegacySettingsService;
use Xizhen\Services\MailService;
use Xizhen\Services\OrderItemSaveRuleService;
use Xizhen\Services\PlatformOrderSyncRegistry;
use Xizhen\Services\ShippingWorkflowService;

final class TenantController
{
    private AppService $service;
    private CsvImportService $csvImportService;
    private LegacySettingsService $legacySettings;
    private MailService $mailService;
    private PlatformOrderSyncRegistry $platformOrderSyncRegistry;
    private ShippingWorkflowService $shippingWorkflowService;
    private Alibaba1688LogisticsService $alibaba1688LogisticsService;

    public function __construct(private readonly StoreInterface $store, private readonly View $view, private readonly AuthService $auth)
    {
        $this->service = new AppService($store);
        $this->csvImportService = new CsvImportService();
        $this->legacySettings = new LegacySettingsService(BASE_PATH . '/../old/setting.ini');
        $this->mailService = new MailService($store);
        $this->platformOrderSyncRegistry = new PlatformOrderSyncRegistry($store);
        $this->shippingWorkflowService = new ShippingWorkflowService();
        $this->alibaba1688LogisticsService = new Alibaba1688LogisticsService($store);
    }

    public function loginForm(): void
    {
        $tenantKey = current_tenant_key();
        if ($this->auth->currentTenantUser($tenantKey) !== null) {
            redirect_to(tenant_url('/', $tenantKey));
        }

        $this->view->render('auth/tenant_login', [
            'title' => '租户登录',
            'tenantKey' => $tenantKey,
            'tenant' => $this->store->tenant($tenantKey),
            'tenants' => $this->store->tenants(),
            'tenantHostMode' => is_tenant_host(),
            'error' => $_GET['error'] ?? '',
            'returnUrl' => $this->safeReturn((string) ($_GET['return'] ?? tenant_url('/', $tenantKey)), tenant_url('/', $tenantKey)),
        ], 'layouts/auth');
    }

    public function login(): void
    {
        $tenantKey = current_tenant_key();
        $returnUrl = $this->safeReturn((string) ($_POST['return'] ?? tenant_url('/', $tenantKey)), tenant_url('/', $tenantKey));
        $result = $this->auth->loginTenant(
            $tenantKey,
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );

        if ($result['ok']) {
            $user = $result['user'] ?? [];
            $preference = (string) ($user['preference_module'] ?? '');
            if ($this->isTenantHome($returnUrl, $tenantKey) && $preference !== '') {
                $returnUrl = $this->preferenceUrl($tenantKey, $preference);
            }
            redirect_to($returnUrl);
        }

        redirect_to(tenant_url('/login?return=' . rawurlencode($returnUrl) . '&error=' . rawurlencode($result['message']), $tenantKey));
    }

    public function logout(): void
    {
        $tenantKey = current_tenant_key();
        $this->auth->logout('tenant', $tenantKey);
        redirect_to(tenant_url('/login', $tenantKey));
    }

    public function dashboard(): void
    {
        $tenantKey = current_tenant_key();
        $this->auth->requireTenant($tenantKey);
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $tenant = $this->store->tenant($tenantKey);
        $this->view->render('tenant/dashboard', [
            'title' => '首页仪表盘',
            'tenantKey' => $tenantKey,
            'tenant' => $tenant,
            'menu' => $this->service->platformMenu($tenantKey),
            'tenantFeatures' => $this->service->tenantFeatureMap($tenantKey),
            'stats' => $this->service->dashboard($tenantKey, $currentUser),
            'announcements' => $this->store->announcements(),
            'groups' => $this->service->featureGroups($tenantKey),
            'active' => 'dashboard',
            'currentUser' => $currentUser,
        ]);
    }

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
        $data = array_filter($data, fn (mixed $value): bool => trim((string) $value) !== '');
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
        $this->store->deleteOrderAttachment($tenantKey, (int) ($_POST['attachment_id'] ?? 0));
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

    public function users(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.users');
        $this->auth->requireTenantPermission($tenantKey, '员工管理');
        $this->view->render('tenant/users', [
            'title' => '员工管理',
            'tenantKey' => $tenantKey,
            'tenant' => $this->store->tenant($tenantKey),
            'menu' => $this->service->platformMenu($tenantKey),
            'tenantFeatures' => $this->service->tenantFeatureMap($tenantKey),
            'active' => 'users',
            'users' => $this->store->users($tenantKey),
            'stores' => $this->service->storesForTenant($tenantKey),
            'rolePermissions' => $this->service->rolePermissionMatrix(),
            'currentUser' => $this->auth->currentTenantUser($tenantKey),
        ]);
    }

    public function addUser(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.users');
        $this->auth->requireTenantPermission($tenantKey, '员工管理');
        $this->store->addUser($tenantKey, [
            'name' => $_POST['name'] ?? '',
            'username' => $_POST['username'] ?? '',
            'role' => $_POST['role'] ?? '客服',
            'password_reset' => $_POST['password_reset'] ?? '',
            'preference_module' => $_POST['preference_module'] ?? '',
            'api_1688_config' => $_POST['api_1688_config'] ?? '',
            'permissions' => $_POST['permissions'] ?? [],
            'stores' => $_POST['stores'] ?? [],
            'status' => $_POST['status'] ?? 'active',
        ]);

        redirect_to('/users?tenant=' . rawurlencode($tenantKey));
    }

    public function editUser(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.users');
        $this->auth->requireTenantPermission($tenantKey, '员工管理');
        $userId = (int) ($_GET['id'] ?? 0);
        $user = $this->store->user($tenantKey, $userId);
        if (!$user) {
            http_response_code(404);
            echo '员工不存在';
            return;
        }

        $this->renderTenant('tenant/user_edit', $tenantKey, [
            'title' => '编辑员工',
            'active' => 'users',
            'user' => $user,
            'stores' => $this->service->storesForTenant($tenantKey),
            'rolePermissions' => $this->service->rolePermissionMatrix(),
            'returnUrl' => (string) ($_GET['return'] ?? "/users?tenant={$tenantKey}"),
        ]);
    }

    public function updateUser(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.users');
        $this->auth->requireTenantPermission($tenantKey, '员工管理');
        $userId = (int) ($_POST['id'] ?? 0);
        $this->store->updateUser($tenantKey, $userId, [
            'name' => $_POST['name'] ?? '',
            'username' => $_POST['username'] ?? '',
            'role' => $_POST['role'] ?? '客服',
            'password_reset' => $_POST['password_reset'] ?? '',
            'preference_module' => $_POST['preference_module'] ?? '',
            'api_1688_config' => $_POST['api_1688_config'] ?? '',
            'permissions' => $_POST['permissions'] ?? [],
            'stores' => $_POST['stores'] ?? [],
            'status' => $_POST['status'] ?? 'active',
        ]);

        redirect_to('/users?tenant=' . rawurlencode($tenantKey));
    }

    public function assignments(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.assignments');
        $this->auth->requireTenantPermission($tenantKey, '店铺分配');
        $users = $this->store->users($tenantKey);
        $this->renderTenant('tenant/assignments', $tenantKey, [
            'title' => '店铺分配',
            'active' => 'users',
            'users' => $users,
            'buyers' => array_values(array_filter($users, fn (array $user): bool => in_array(($user['role'] ?? ''), ['采购', '品检'], true))),
            'supports' => array_values(array_filter($users, fn (array $user): bool => ($user['role'] ?? '') === '客服')),
            'assignments' => $this->store->assignments($tenantKey),
        ]);
    }

    public function saveAssignment(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.assignments');
        $this->auth->requireTenantPermission($tenantKey, '店铺分配');
        $mode = (string) ($_POST['mode'] ?? 'buyer');

        if ($mode === 'support') {
            $this->store->saveAssignmentBySupport(
                $tenantKey,
                (int) ($_POST['support_user_id'] ?? 0),
                $this->intList($_POST['buyer_user_ids'] ?? [])
            );
        } else {
            $this->store->saveAssignmentByBuyer(
                $tenantKey,
                (int) ($_POST['buyer_user_id'] ?? 0),
                $this->intList($_POST['support_user_ids'] ?? [])
            );
        }

        redirect_to('/assignments?tenant=' . rawurlencode($tenantKey));
    }

    public function features(): void
    {
        $tenantKey = current_tenant_key();
        $this->auth->requireTenant($tenantKey);
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

    public function profit(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'analytics.profit');
        $this->auth->requireTenantPermission($tenantKey, '利润分析');
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $this->renderTenant('tenant/profit', $tenantKey, [
            'title' => '利润分析',
            'active' => 'profit',
            'summary' => $this->service->profitSummary($tenantKey, $currentUser),
            'legacySettings' => $this->legacySettings->summary(),
        ]);
    }

    public function purchaseStats(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'stats.purchase');
        $this->auth->requireTenantPermission($tenantKey, '采购统计');
        $this->renderTenant('tenant/purchase_stats', $tenantKey, [
            'title' => '采购统计',
            'active' => 'purchase_stats',
            'stats' => $this->service->purchaseStats($tenantKey, $this->auth->currentTenantUser($tenantKey)),
        ]);
    }

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

    public function mail(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $filters = $this->mailFiltersFrom($_GET);
        $this->renderTenant('tenant/mail', $tenantKey, [
            'title' => '邮件汇总',
            'active' => 'mail',
            'mail' => $this->mailService->pageData($tenantKey, $filters, (int) ($_GET['page'] ?? 1), (int) ($_GET['message_id'] ?? 0)),
            'filters' => $filters,
        ]);
    }

    public function mailSettings(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $filters = $this->mailFiltersFrom($_GET);
        $this->renderTenant('tenant/mail_settings', $tenantKey, [
            'title' => '邮箱设置',
            'active' => 'mail',
            'mail' => $this->mailService->pageData($tenantKey, $filters, 1, 0, false),
            'filters' => $filters,
        ]);
    }

    public function mailRules(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $filters = $this->mailFiltersFrom($_GET);
        $this->renderTenant('tenant/mail_rules', $tenantKey, [
            'title' => '过滤规则',
            'active' => 'mail',
            'mail' => $this->mailService->pageData($tenantKey, $filters, 1, 0, false),
            'filters' => $filters,
        ]);
    }

    public function saveMailAccount(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $id = $this->mailService->saveAccount($tenantKey, $_POST);
        redirect_to('/mail/settings?tenant=' . rawurlencode($tenantKey) . '&account_id=' . $id . '&message=' . rawurlencode('邮箱账号已保存'));
    }

    public function deleteMailAccount(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $this->mailService->deleteAccount($tenantKey, (int) ($_POST['account_id'] ?? 0));
        redirect_to('/mail/settings?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode('邮箱账号已删除'));
    }

    public function probeMailFolders(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $accountId = (int) ($_POST['account_id'] ?? 0);
        $result = $this->mailService->probeFolders($tenantKey, $accountId);
        $key = $result['ok'] ? 'message' : 'error';
        redirect_to('/mail/settings?tenant=' . rawurlencode($tenantKey) . '&account_id=' . $accountId . '&' . $key . '=' . rawurlencode($result['message']));
    }

    public function saveMailFolder(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $this->mailService->saveFolder($tenantKey, $_POST);
        redirect_to((string) ($_POST['return'] ?? '/mail/settings?tenant=' . rawurlencode($tenantKey)));
    }

    public function syncMail(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $result = $this->mailService->sync($tenantKey, (int) ($_POST['account_id'] ?? 0), (int) ($_POST['folder_id'] ?? 0), (int) ($_POST['limit'] ?? 200));
        $key = $result['ok'] ? 'message' : 'error';
        $return = trim((string) ($_POST['return'] ?? ''));
        if ($return === '') {
            $return = ((int) ($_POST['account_id'] ?? 0) > 0 || (int) ($_POST['folder_id'] ?? 0) > 0)
                ? '/mail/settings?tenant=' . rawurlencode($tenantKey)
                : '/mail?tenant=' . rawurlencode($tenantKey);
        }
        redirect_to($this->urlWithNotice($return, $key, (string) $result['message']));
    }

    public function mailAction(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $count = $this->mailService->mark($tenantKey, $this->intList($_POST['message_ids'] ?? []), (string) ($_POST['action'] ?? ''));
        redirect_to($this->urlWithNotice((string) ($_POST['return'] ?? '/mail?tenant=' . rawurlencode($tenantKey)), 'message', "已处理 {$count} 封邮件"));
    }

    public function moveMail(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $result = $this->mailService->move($tenantKey, $this->intList($_POST['message_ids'] ?? []), (int) ($_POST['target_folder_id'] ?? 0));
        $key = $result['ok'] ? 'message' : 'error';
        redirect_to($this->urlWithNotice((string) ($_POST['return'] ?? '/mail?tenant=' . rawurlencode($tenantKey)), $key, (string) $result['message']));
    }

    public function saveMailRule(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $this->mailService->saveRule($tenantKey, $_POST);
        redirect_to('/mail/rules?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode('规则已保存'));
    }

    public function deleteMailRule(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $this->mailService->deleteRule($tenantKey, (int) ($_POST['rule_id'] ?? 0));
        redirect_to('/mail/rules?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode('规则已删除'));
    }

    public function applyMailRules(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $result = $this->mailService->applyRules($tenantKey, (int) ($_POST['account_id'] ?? 0), (int) ($_POST['folder_id'] ?? 0));
        $return = trim((string) ($_POST['return'] ?? ''));
        if ($return === '') {
            $return = '/mail/rules?tenant=' . rawurlencode($tenantKey);
        }
        redirect_to($this->urlWithNotice($return, 'message', "规则执行完成：命中 {$result['matched']} 封，移动 {$result['moved']} 封"));
    }

    public function replyMail(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $result = $this->mailService->reply($tenantKey, $_POST, $this->currentUserName($tenantKey));
        $key = $result['ok'] ? 'message' : 'error';
        $messageId = (int) ($_POST['message_id'] ?? 0);
        redirect_to('/mail?tenant=' . rawurlencode($tenantKey) . '&message_id=' . $messageId . '&' . $key . '=' . rawurlencode((string) $result['message']));
    }

    public function importExport(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $jobs = $this->service->importExportJobsForTenant($tenantKey);
        if (!Permission::has($currentUser, '导入导出')) {
            $jobs = array_values(array_filter(
                $jobs,
                fn (array $job): bool => in_array((string) ($job['key'] ?? ''), ['purchase_export', 'purchase_import'], true)
            ));
        }

        $this->renderTenant('tenant/import_export', $tenantKey, [
            'title' => '导入导出',
            'active' => 'import_export',
            'jobs' => $jobs,
            'logs' => $this->store->importExportLogs($tenantKey),
            'platforms' => $this->service->tenantPlatformNames($tenantKey),
            'stores' => $this->service->storesForTenant($tenantKey),
            'importMessage' => (string) ($_GET['message'] ?? ''),
        ]);
    }

    public function exportCsv(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $type = preg_replace('/[^a-z_]/', '', (string) ($_GET['type'] ?? 'purchase')) ?: 'purchase';
        $this->ensurePlatformFeatureAccess($tenantKey, (string) ($_GET['platform'] ?? ''));
        $this->ensureImportExportAccess($tenantKey, $type);
        $dataset = $this->service->exportDataset($tenantKey, $type, $this->auth->currentTenantUser($tenantKey), $this->exportCriteriaFrom($_GET));
        $this->sendCsvDataset($tenantKey, $dataset);
    }

    public function exportOrders(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $type = preg_replace('/[^a-z_]/', '', (string) ($_POST['type'] ?? 'purchase')) ?: 'purchase';
        $this->ensurePlatformFeatureAccess($tenantKey, (string) ($_POST['platform'] ?? ''));
        $this->ensureImportExportAccess($tenantKey, $type);
        $dataset = $this->service->exportDataset($tenantKey, $type, $this->auth->currentTenantUser($tenantKey), $this->exportCriteriaFrom($_POST));
        $this->sendCsvDataset($tenantKey, $dataset);
    }

    public function updateLogistics(): void
    {
        $tenantKey = current_tenant_key();
        $type = preg_replace('/[^a-z0-9_]/', '', (string) ($_POST['type'] ?? '1688')) ?: '1688';
        $this->requireTenantFeature($tenantKey, $this->featureForLogisticsType($type));
        $this->requireLogisticsPermission($tenantKey, $type);
        $this->ensurePlatformFeatureAccess($tenantKey, (string) ($_POST['platform'] ?? ''));

        $criteria = $this->exportCriteriaFrom($_POST);
        $itemIds = $this->intList($_POST['item_ids'] ?? []);
        $orderIds = $this->auth->tenantCan($tenantKey, '批量操作') ? $this->intList($_POST['order_ids'] ?? []) : [];
        $this->ensureBatchAccess($tenantKey, $itemIds, $orderIds);
        $targetItemIds = $this->service->itemIdsForLogisticsUpdate(
            $tenantKey,
            $this->auth->currentTenantUser($tenantKey),
            $type,
            $criteria,
            $itemIds,
            $orderIds
        );
        if ($type === '1688') {
            $syncResult = $this->alibaba1688LogisticsService->syncItems($tenantKey, $targetItemIds, [], $this->currentUserName($tenantKey));
            $updated = (int) $syncResult['updated'];
            $message = $targetItemIds ? (string) $syncResult['message'] : '当前筛选范围没有符合条件的 1688 物流记录。';
            $logStatus = $syncResult['ok'] ? '同步完成' : '同步异常';
        } else {
            $status = $this->service->logisticsUpdateStatus($type);
            $updated = $this->store->updateItemsLogistics(
                $tenantKey,
                $targetItemIds,
                $status,
                $this->service->logisticsUpdateName($type),
                $this->currentUserName($tenantKey)
            );
            $message = $targetItemIds
                ? "已触发 {$updated}/" . count($targetItemIds) . ' 条物流同步，真实轨迹等待接口接入后回写。'
                : '当前筛选范围没有符合条件的物流记录。';
            $logStatus = $updated > 0 ? '已触发' : '无可更新记录';
        }
        $this->store->addImportExportLog($tenantKey, [
            'type' => 'logistics',
            'name' => $this->service->logisticsUpdateName($type),
            'status' => $logStatus,
            'file_name' => '',
            'rows' => $updated,
            'message' => $message,
            'created_by' => $this->currentUserName($tenantKey),
        ]);

        $returnUrl = $this->safeReturn((string) ($_POST['return'] ?? tenant_url('/orders?view=platform', $tenantKey)), tenant_url('/orders?view=platform', $tenantKey));
        redirect_to($returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'message=' . rawurlencode($message));
    }

    public function sendJapan(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'orders.edit');
        $this->requireTenantFeature($tenantKey, 'orders.purchase');
        $this->auth->requireAnyTenantPermission($tenantKey, ['批量操作', '采购状态', '订单编辑']);

        $itemIds = $this->intList($_POST['item_ids'] ?? []);
        $orderIds = $this->auth->tenantCan($tenantKey, '批量操作') ? $this->intList($_POST['order_ids'] ?? []) : [];
        $this->ensureBatchAccess($tenantKey, $itemIds, $orderIds);

        $plan = $this->shippingWorkflowService->planSendJapan(
            $tenantKey,
            $this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey)),
            $itemIds,
            $orderIds
        );
        $updated = 0;
        if ($plan['item_ids']) {
            $updated = $this->store->transitionItemPurchaseStatus(
                $tenantKey,
                $plan['item_ids'],
                ShippingWorkflowService::STATUS_READY_FOR_JP,
                ShippingWorkflowService::STATUS_SENT_TO_JP,
                $this->currentUserName($tenantKey),
                ShippingWorkflowService::ACTION_SEND_JP
            );
        }

        $message = "已发日本更新 {$updated}/" . (int) $plan['selected'] . ' 件。';
        if ((int) $plan['skipped'] > 0) {
            $message .= ' 跳过 ' . (int) $plan['skipped'] . ' 件非已到货明细。';
        }
        $statusChanged = count($plan['item_ids']) - $updated;
        if ($statusChanged > 0) {
            $message .= ' 另有 ' . $statusChanged . ' 件在写入前状态已变化。';
        }
        $this->store->addImportExportLog($tenantKey, [
            'type' => 'workflow',
            'name' => '批量已发日本',
            'status' => $updated > 0 ? '已更新' : '无可更新记录',
            'file_name' => '',
            'rows' => $updated,
            'message' => $message,
            'created_by' => $this->currentUserName($tenantKey),
        ]);

        $returnUrl = $this->safeReturn((string) ($_POST['return'] ?? tenant_url('/orders?view=purchase', $tenantKey)), tenant_url('/orders?view=purchase', $tenantKey));
        redirect_to($returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'message=' . rawurlencode($message));
    }

    public function exportXizhenDelivery(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->requireTenantFeature($tenantKey, 'import_export.platform');
        $this->auth->requireTenantPermission($tenantKey, '导入导出');
        $this->ensurePlatformFeatureAccess($tenantKey, (string) ($_POST['platform'] ?? ''));

        $criteria = $this->exportCriteriaFrom($_POST);
        $orders = $this->service->ordersForExport($tenantKey, $this->auth->currentTenantUser($tenantKey), $criteria);
        $platform = (string) ($_POST['platform'] ?? '');
        $platformNames = $this->service->tenantPlatformNames($tenantKey);
        $dataset = $this->shippingWorkflowService->xizhenDeliveryDataset(
            $tenantKey,
            $orders,
            $platform,
            $platformNames[$platform] ?? '',
            $this->intList($_POST['item_ids'] ?? []),
            $this->intList($_POST['order_ids'] ?? [])
        );
        $this->sendCsvDataset($tenantKey, $dataset);
    }

    public function syncRakutenOrders(): void
    {
        $_POST['platform'] = 'r';
        $this->syncPlatformOrders();
    }

    public function syncPlatformOrders(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'orders.platform');
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->requireTenantFeature($tenantKey, 'import_export.platform');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '订单编辑']);

        $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['platform'] ?? '')) ?: '';
        $service = $this->platformOrderSyncRegistry->get($platform);
        if (!$service) {
            $this->forbid('该平台暂未接入订单同步服务。');
        }
        $this->ensurePlatformFeatureAccess($tenantKey, $platform);

        $storeId = (int) ($_POST['store_id'] ?? 0);
        $store = $this->store->store($tenantKey, $storeId);
        if (!$store || ($store['platform'] ?? '') !== $platform) {
            $this->forbid('请选择' . $service->platformName() . '店铺后再同步。');
        }
        if (!Permission::canAccessStore($this->auth->currentTenantUser($tenantKey), (string) ($store['name'] ?? ''))) {
            $this->forbid('当前账号没有该店铺的订单同步权限。');
        }

        $days = $this->boundedInt($_POST['days'] ?? 7, 1, 30);
        $operator = $this->currentUserName($tenantKey);
        $result = $service->sync($tenantKey, $storeId, $days, $operator);

        $this->store->addImportExportLog($tenantKey, [
            'type' => 'import',
            'name' => $service->platformName() . ' API 同步',
            'status' => $result['ok'] ? '同步完成' : '同步异常',
            'file_name' => $service->platformName() . ' Order API',
            'rows' => (int) ($result['inserted'] ?? 0) + (int) ($result['updated'] ?? 0),
            'message' => $result['message'],
            'preview' => [[
                '检索订单' => (string) ($result['searched'] ?? 0),
                '新增订单' => (string) ($result['inserted'] ?? 0),
                '更新订单' => (string) ($result['updated'] ?? 0),
                '新增商品' => (string) ($result['items_inserted'] ?? 0),
                '更新商品' => (string) ($result['items_updated'] ?? 0),
                '跳过' => (string) ($result['skipped'] ?? 0),
            ]],
            'created_by' => $operator,
        ]);

        $defaultReturn = tenant_url('/orders?view=platform&platform=' . rawurlencode($platform), $tenantKey);
        $returnUrl = $this->safeReturn((string) ($_POST['return'] ?? $defaultReturn), $defaultReturn);
        redirect_to($returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'message=' . rawurlencode($result['message']));
    }

    /** @param array{name: string, filename: string, headers: array<int, string>, rows: array<int, array<int, mixed>>} $dataset */
    private function sendCsvDataset(string $tenantKey, array $dataset): never
    {
        $this->store->addImportExportLog($tenantKey, [
            'type' => 'export',
            'name' => $dataset['name'],
            'status' => '已导出',
            'file_name' => $dataset['filename'],
            'rows' => count($dataset['rows']),
            'message' => 'CSV 已生成下载。',
            'created_by' => $this->currentUserName($tenantKey),
        ]);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $dataset['filename'] . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        if ($out !== false) {
            fputcsv($out, $dataset['headers'], ',', '"', '\\');
            foreach ($dataset['rows'] as $row) {
                fputcsv($out, $row, ',', '"', '\\');
            }
            fclose($out);
        }
        exit;
    }

    public function importCsv(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $job = preg_replace('/[^a-z_]/', '', (string) ($_POST['job'] ?? 'platform_orders_import')) ?: 'platform_orders_import';
        $this->ensureImportExportAccess($tenantKey, $job);
        $label = $this->importJobLabel($job);
        $message = '未收到可解析的 CSV 文件。';
        $preview = [];
        $rowCount = 0;
        $fileName = '';
        $status = '解析失败';
        $operator = $this->currentUserName($tenantKey);
        $parseErrors = [];
        $writeReport = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];

        if (isset($_FILES['csv_file']) && is_array($_FILES['csv_file']) && (int) ($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $fileName = (string) ($_FILES['csv_file']['name'] ?? '');
            $tmpName = (string) ($_FILES['csv_file']['tmp_name'] ?? '');
            if ($tmpName !== '' && is_uploaded_file($tmpName) && (int) ($_FILES['csv_file']['size'] ?? 0) <= 3 * 1024 * 1024) {
                $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['platform'] ?? '')) ?: '';
                $storeId = max(0, (int) ($_POST['store_id'] ?? 0));
                if ($platform !== '') {
                    $this->ensurePlatformFeatureAccess($tenantKey, $platform);
                }

                $parsed = $this->csvImportService->parseFile($tmpName, $job, [
                    'platform' => $platform,
                    'store_id' => $storeId,
                    'platform_names' => $this->service->platformNames(),
                    'stores' => $this->service->storesForTenant($tenantKey),
                    'user' => $this->auth->currentTenantUser($tenantKey),
                ]);
                $rowCount = (int) $parsed['row_count'];
                $preview = $parsed['preview'];
                $parseErrors = $parsed['errors'];
                $records = $this->filterImportRecordsByPlatform($tenantKey, $parsed['records'], $parseErrors);

                if ($rowCount <= 0) {
                    $status = '空文件';
                    $message = 'CSV 文件没有数据行。';
                } elseif (!$records) {
                    $status = '解析失败';
                    $message = '已读取 CSV，但没有可写入的数据。' . ($parseErrors ? ' ' . implode('；', array_slice($parseErrors, 0, 3)) : '');
                } else {
                    $writeReport = match ($job) {
                        'purchase_import' => $this->store->importPurchaseRows($tenantKey, $records, $operator),
                        'shipping_import' => $this->store->importShippingRows($tenantKey, $records, $operator),
                        default => $this->store->importPlatformOrders($tenantKey, $records, $operator),
                    };
                    $failed = (int) $writeReport['failed'] + count($parseErrors);
                    $status = $failed > 0 ? '部分导入' : '已导入';
                    $message = sprintf(
                        '%s：读取 %d 行，新增 %d，更新 %d，跳过 %d，失败 %d。',
                        $label,
                        $rowCount,
                        (int) $writeReport['inserted'],
                        (int) $writeReport['updated'],
                        (int) $writeReport['skipped'],
                        $failed
                    );
                }
            } else {
                $message = '文件超过 3MB 或上传临时文件不可读。';
            }
        }

        $this->store->addImportExportLog($tenantKey, [
            'type' => 'import',
            'name' => $label,
            'status' => $status,
            'file_name' => $fileName,
            'rows' => $rowCount,
            'message' => $this->importLogMessage($message, $parseErrors, (array) ($writeReport['messages'] ?? [])),
            'preview' => $preview,
            'created_by' => $operator,
        ]);

        redirect_to('/import-export?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode($message));
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
        $this->auth->requireTenant($tenantKey);
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
        $tenantApi1688Content = $this->readTenant1688Config($tenantKey);
        $api1688 = is_array($settings['api_1688'] ?? null) ? $settings['api_1688'] : [];
        $settings['api_1688'] = array_replace($api1688, [
            'config_content' => $tenantApi1688Content,
            'config_file' => $tenantApi1688Path,
        ]);

        $this->renderTenant('tenant/settings', $tenantKey, [
            'title' => '系统设置',
            'active' => 'settings',
            'settings' => $settings,
            'platformNames' => $this->service->tenantPlatformNames($tenantKey, true),
            'saved' => (string) ($_GET['saved'] ?? ''),
        ]);
    }

    public function saveSettings(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.settings');
        $this->auth->requireAnyTenantPermission($tenantKey, ['公司设置', '系统设置']);
        $api1688Content = trim((string) ($_POST['api_1688_config_content'] ?? ''));
        $api1688ConfigFile = $this->writeTenant1688Config($tenantKey, $api1688Content);
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
            ],
            'profit' => [
                'exchange_rate' => $this->boundedFloat($_POST['exchange_rate'] ?? 0.046, 0.0001, 100),
                'exchange_rate_mode' => in_array(($_POST['exchange_rate_mode'] ?? 'fixed'), ['fixed', 'manual'], true) ? (string) $_POST['exchange_rate_mode'] : 'fixed',
                'fixed_exchange_rate' => $this->boundedFloat($_POST['fixed_exchange_rate'] ?? 0.046, 0.0001, 100),
                'default_intl_fee' => $this->boundedFloat($_POST['default_intl_fee'] ?? 820, 0, 999999),
                'platform_deductions' => $this->platformDeductionsFromPost(),
                'store_deduction_enabled' => isset($_POST['store_deduction_enabled']),
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

    private function readTenant1688Config(string $tenantKey): string
    {
        $path = $this->tenant1688ConfigAbsolutePath($tenantKey);
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        return trim((string) file_get_contents($path));
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

    /** @param array<string, mixed> $data */
    private function renderTenant(string $template, string $tenantKey, array $data): void
    {
        $this->view->render($template, array_merge([
            'tenantKey' => $tenantKey,
            'tenant' => $this->store->tenant($tenantKey),
            'menu' => $this->service->platformMenu($tenantKey),
            'tenantFeatures' => $this->service->tenantFeatureMap($tenantKey),
            'currentUser' => $this->auth->currentTenantUser($tenantKey),
        ], $data));
    }

    private function currentUserName(string $tenantKey): string
    {
        $user = $this->auth->currentTenantUser($tenantKey);
        return (string) (($user['name'] ?? '') ?: ($user['username'] ?? '系统'));
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

    /** @param array<string, mixed>|null $user @return array<string, mixed>|null */
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

    /**
     * @param array<int, int> $itemIds
     * @param array<int, int> $orderIds
     */
    private function ensureBatchAccess(string $tenantKey, array $itemIds, array $orderIds): void
    {
        $allowedItems = [];
        $allowedOrders = [];
        foreach ($this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey)) as $order) {
            $allowedOrders[] = (int) ($order['id'] ?? 0);
            foreach ($order['items'] ?? [] as $item) {
                $allowedItems[] = (int) ($item['id'] ?? 0);
            }
        }

        foreach ($orderIds as $orderId) {
            if (!in_array($orderId, $allowedOrders, true)) {
                $this->forbid('批量操作包含当前账号无权处理的订单。');
            }
        }
        foreach ($itemIds as $itemId) {
            if (!in_array($itemId, $allowedItems, true)) {
                $this->forbid('批量操作包含当前账号无权处理的子商品。');
            }
        }
    }

    private function ensureImportExportAccess(string $tenantKey, string $operation): void
    {
        $this->requireTenantFeature($tenantKey, $this->featureForImportExportOperation($operation));

        if ($this->auth->tenantCan($tenantKey, '导入导出')) {
            return;
        }

        if ($this->auth->tenantCan($tenantKey, '采购导入导出') && in_array($operation, ['purchase', 'purchase_import'], true)) {
            return;
        }

        $this->forbid('当前账号没有该导入导出任务的权限。');
    }

    private function requireTenantFeature(string $tenantKey, string $featureKey): void
    {
        if ($this->service->tenantFeatureEnabled($tenantKey, $featureKey)) {
            return;
        }

        $this->forbid('当前租户未开通该功能，请联系 SaaS 超级管理员。');
    }

    private function ensurePlatformFeatureAccess(string $tenantKey, ?string $platform): void
    {
        $platform = trim((string) $platform);
        if ($platform === '') {
            return;
        }

        if ($this->service->platformEnabled($tenantKey, $platform)) {
            return;
        }

        $this->forbid('当前租户未开通或已锁定该订单平台，请联系 SaaS 超级管理员。');
    }

    private function featureForImportExportOperation(string $operation): string
    {
        return match ($operation) {
            'platform', 'platform_orders_import', 'shipment', 'delivery_notice' => 'import_export.platform',
            'purchase', 'purchase_import' => 'import_export.purchase',
            'finance' => 'import_export.finance',
            'logistics', 'shipping_import' => 'import_export.logistics',
            'customers' => 'customers.data',
            default => 'import_export.center',
        };
    }

    private function featureForLogisticsType(string $type): string
    {
        return match ($type) {
            '1688' => 'logistics.1688',
            'jp' => 'logistics.jp',
            default => 'logistics.express',
        };
    }

    private function requireLogisticsPermission(string $tenantKey, string $type): void
    {
        if ($type === '1688') {
            $this->auth->requireTenantPermission($tenantKey, '1688物流');
            return;
        }

        if ($type === 'jp') {
            $this->auth->requireAnyTenantPermission($tenantKey, ['日本物流日志', '物流查看']);
            return;
        }

        $this->auth->requireAnyTenantPermission($tenantKey, ['1688物流', '物流查看']);
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

    /**
     * @param array<string, mixed> $source
     * @return array<string, string>
     */
    private function orderFiltersFrom(array $source, string $keyword = ''): array
    {
        return [
            'order_no' => trim((string) ($source['order_no'] ?? '')),
            'tabaono' => trim((string) ($source['tabaono'] ?? '')),
            'status' => (string) ($source['status'] ?? ''),
            'store' => (string) ($source['store'] ?? ''),
            'item_id' => trim((string) ($source['item_id'] ?? '')),
            'mail' => trim((string) ($source['mail'] ?? '')),
            'customer_name' => trim((string) ($source['customer_name'] ?? '')),
            'phone' => trim((string) ($source['phone'] ?? '')),
            'buyer' => (string) ($source['buyer'] ?? ''),
            'product_name' => trim((string) ($source['product_name'] ?? '')),
            'cn_ship_no' => trim((string) ($source['cn_ship_no'] ?? '')),
            'intl_ship_no' => trim((string) ($source['intl_ship_no'] ?? '')),
            'carrier' => trim((string) ($source['carrier'] ?? '')),
            'location' => trim((string) ($source['location'] ?? '')),
            'date_from' => trim((string) ($source['date_from'] ?? '')),
            'date_to' => trim((string) ($source['date_to'] ?? '')),
            'late_ship' => (string) ($source['late_ship'] ?? ''),
            'in_delivery' => (string) ($source['in_delivery'] ?? ''),
            'delivered' => (string) ($source['delivered'] ?? ''),
            'page_size' => (string) ($source['page_size'] ?? '200'),
            'keyword' => $keyword,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function exportCriteriaFrom(array $source): array
    {
        $keyword = $this->keywordFrom($source);

        return [
            'view' => (string) ($source['view'] ?? 'platform'),
            'platform' => (string) ($source['platform'] ?? ''),
            'source' => (string) ($source['source'] ?? 'all'),
            'keyword' => $keyword,
            'filters' => $this->orderFiltersFrom($source, $keyword),
            'item_ids' => $this->intList($source['item_ids'] ?? []),
            'order_ids' => $this->intList($source['order_ids'] ?? []),
        ];
    }

    /** @return array<string, string> */
    private function allowedOrderItemPostData(string $tenantKey): array
    {
        $data = [];
        if (
            $this->service->tenantFeatureEnabled($tenantKey, 'orders.platform')
            && ($this->auth->tenantCan($tenantKey, '货源改判') || $this->auth->tenantCan($tenantKey, '订单编辑'))
        ) {
            $data['source_type'] = (string) ($_POST['source_type'] ?? '');
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
                $data[$field] = (string) ($_POST[$field] ?? '');
            }
        }
        if (
            $this->service->tenantFeatureEnabled($tenantKey, 'orders.jp')
            && ($this->auth->tenantCan($tenantKey, '日本仓发货') || $this->auth->tenantCan($tenantKey, '订单编辑'))
        ) {
            foreach (['assignee', 'out_status', 'jp_warehouse_id', 'intl_number', 'intl_fee', 'intl_qty', 'intl_weight', 'intl_comment'] as $field) {
                $data[$field] = (string) ($_POST[$field] ?? '');
            }
        }

        return $data;
    }

    private function importJobLabel(string $job): string
    {
        return match ($job) {
            'purchase_import' => '采购表导入',
            'shipping_import' => '国际运单导入',
            default => '平台订单 CSV 导入',
        };
    }

    /** @param array<int, string> $parseErrors @param array<int, string> $writeMessages */
    private function importLogMessage(string $summary, array $parseErrors, array $writeMessages): string
    {
        $details = array_merge(array_slice($parseErrors, 0, 5), array_slice($writeMessages, 0, 10));
        if (!$details) {
            return $summary;
        }

        return $summary . ' 明细：' . implode('；', $details);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @param array<int, string> $errors
     * @return array<int, array<string, mixed>>
     */
    private function filterImportRecordsByPlatform(string $tenantKey, array $records, array &$errors): array
    {
        $allowed = array_flip($this->service->enabledPlatformCodes($tenantKey));
        $filtered = [];
        foreach ($records as $record) {
            $platform = (string) (($record['order']['platform'] ?? null) ?: ($record['identity']['platform'] ?? ''));
            if ($platform !== '' && !isset($allowed[$platform])) {
                $errors[] = '第 ' . (int) ($record['row'] ?? 0) . " 行：当前租户未开通平台 {$platform}，已跳过。";
                continue;
            }

            $filtered[] = $record;
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function mailFiltersFrom(array $source): array
    {
        return [
            'account_id' => (int) ($source['account_id'] ?? 0),
            'folder_id' => (int) ($source['folder_id'] ?? 0),
            'unread' => (int) ($source['unread'] ?? 0) === 1,
            'important' => (int) ($source['important'] ?? 0) === 1,
            'q' => trim((string) ($source['q'] ?? '')),
        ];
    }

    private function urlWithNotice(string $url, string $key, string $message): string
    {
        $key = $key === 'error' ? 'error' : 'message';
        $fragment = '';
        $hashPos = strpos($url, '#');
        if ($hashPos !== false) {
            $fragment = substr($url, $hashPos);
            $url = substr($url, 0, $hashPos);
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . rawurlencode($message) . $fragment;
    }

    /** @param array<string, mixed> $source */
    private function keywordFrom(array $source): string
    {
        foreach (['q', 'order_no', 'tabaono'] as $key) {
            $value = trim((string) ($source[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array{0: int, 1: array<int, array<string, string>>}
     */
    private function parseCsvPreview(string $file): array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return [0, []];
        }

        $headers = [];
        $preview = [];
        $rows = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $row = array_map(static fn (mixed $value): string => trim((string) $value), $row);
            if ($headers === []) {
                $headers = $row;
                if (isset($headers[0])) {
                    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
                }
                continue;
            }

            if (implode('', $row) === '') {
                continue;
            }
            $rows++;
            if (count($preview) < 5) {
                $preview[] = array_combine(
                    array_slice($headers, 0, count($row)),
                    array_slice($row, 0, count($headers))
                ) ?: [];
            }
        }
        fclose($handle);

        return [$rows, $preview];
    }

    /** @return array<string, float> */
    private function platformDeductionsFromPost(): array
    {
        $deductions = [];
        $input = is_array($_POST['platform_deductions'] ?? null) ? $_POST['platform_deductions'] : [];
        foreach ($this->service->platformNames() as $code => $_name) {
            $deductions[$code] = $this->boundedFloat($input[$code] ?? 70, 0, 100);
        }

        return $deductions;
    }

    private function boundedInt(mixed $value, int $min, int $max): int
    {
        $number = is_numeric($value) ? (int) $value : $min;
        return max($min, min($max, $number));
    }

    private function boundedFloat(mixed $value, float $min, float $max): float
    {
        $number = is_numeric($value) ? (float) $value : $min;
        return round(max($min, min($max, $number)), 4);
    }

    private function forbid(string $message): never
    {
        http_response_code(403);
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>无权限</title><link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page"><main class="login-card"><h1>无权限访问</h1><p>' . e($message) . '</p><p><a class="btn primary" href="javascript:history.back()">返回上一页</a></p></main></body></html>';
        exit;
    }

    private function preferenceUrl(string $tenantKey, string $preference): string
    {
        $targets = [
            'platform' => ['orders.platform', tenant_url('/orders?view=platform', $tenantKey)],
            'purchase' => ['orders.purchase', tenant_url('/orders?view=purchase', $tenantKey)],
            'jp' => ['orders.jp', tenant_url('/orders?view=jp', $tenantKey)],
            'mail' => ['mail.center', tenant_url('/mail', $tenantKey)],
            'profit' => ['analytics.profit', tenant_url('/analytics/profit', $tenantKey)],
        ];
        [$feature, $url] = $targets[$preference] ?? ['', tenant_url('/', $tenantKey)];
        if ($feature !== '' && !$this->service->tenantFeatureEnabled($tenantKey, $feature)) {
            return tenant_url('/', $tenantKey);
        }

        return $url;
    }

    private function isTenantHome(string $returnUrl, string $tenantKey): bool
    {
        return $returnUrl === '/' || $returnUrl === '/?tenant=' . $tenantKey;
    }

    private function safeReturn(string $return, string $fallback): string
    {
        return str_starts_with($return, '/') && !str_starts_with($return, '//') ? $return : $fallback;
    }

    private function viewTitle(string $view): string
    {
        return match ($view) {
            'purchase' => '采购订单',
            'jp' => '日本仓发货',
            default => '平台订单',
        };
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        return array_values(array_unique(array_filter(array_map('intval', $values))));
    }

    /**
     * @param array<int, int> $itemIds
     * @return array<int, int>
     */
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

    /** @return array<string, string>|null */
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
