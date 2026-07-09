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
use Xizhen\Services\ProductImageDownloadService;
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

final class OrderExportController extends TenantBaseController
{

    public function exportOrders(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $type = preg_replace('/[^a-z_]/', '', (string) ($_POST['type'] ?? 'purchase')) ?: 'purchase';
        $this->ensurePlatformFeatureAccess($tenantKey, (string) ($_POST['platform'] ?? ''));
        $this->ensureImportExportAccess($tenantKey, $type);
        $this->sendSpreadsheetExportIfNeeded($tenantKey, $type, $_POST);
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
        if (!$targetItemIds) {
            $updated = 0;
            $message = match ($type) {
                '1688' => '当前筛选范围没有符合条件的 1688 物流记录。',
                'jp' => '当前筛选范围没有符合条件的日本物流记录。',
                default => '当前筛选范围没有符合条件的物流记录。',
            };
            $logStatus = '无可更新记录';
        } elseif ($type === '1688') {
            $syncResult = $this->alibaba1688LogisticsService->syncItems($tenantKey, $targetItemIds, [], $this->currentUserName($tenantKey));
            $updated = (int) $syncResult['updated'];
            $message = (string) $syncResult['message'];
            $logStatus = $syncResult['ok'] ? '同步完成' : '同步异常';
        } elseif ($type === 'jp') {
            $syncResult = $this->japanLogisticsService->syncItems($tenantKey, $targetItemIds, [], $this->currentUserName($tenantKey));
            $updated = (int) $syncResult['updated'];
            $message = (string) $syncResult['message'];
            $logStatus = $syncResult['ok'] ? '同步完成' : '同步异常';
        } elseif ($type === 'express') {
            $syncResult = $this->expressLogisticsService->syncItems(
                $tenantKey,
                $targetItemIds,
                [],
                $this->currentUserName($tenantKey),
                $this->auth->currentTenantUser($tenantKey)
            );
            $updated = (int) $syncResult['updated'];
            $message = (string) $syncResult['message'];
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

        $settings = $this->store->tenantSettings($tenantKey);
        $ordersSettings = is_array($settings['orders'] ?? null) ? $settings['orders'] : [];
        $days = $this->boundedInt($ordersSettings['platform_sync_default_days'] ?? 7, 1, 30);
        $options = [];
        if ($platform === 'w') {
            $folder = trim((string) ($_POST['order_status'] ?? $_POST['wowma_folder'] ?? ''));
            $folders = $this->wowmaSyncFoldersFromSettings($settings);
            if ($folder === '' || !in_array($folder, $folders, true)) {
                $this->forbid('请选择有效的 Wowma 文件夹名称后再同步。');
            }
            $options['order_status'] = $folder;
        }
        $operator = $this->currentUserName($tenantKey);
        $result = $service->sync($tenantKey, $storeId, $days, $operator, $options);

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
        $separator = str_contains($returnUrl, '?') ? '&' : '?';
        redirect_to($returnUrl . $separator . 'sync_message=1&message=' . rawurlencode($result['message']));
    }

    public function downloadProductImages(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'orders.platform');
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '订单编辑']);

        $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['platform'] ?? '')) ?: '';
        $this->ensurePlatformFeatureAccess($tenantKey, $platform);

        $settings = $this->store->tenantSettings($tenantKey);
        $ordersSettings = is_array($settings['orders'] ?? null) ? $settings['orders'] : [];
        $dayLimit = $this->boundedInt($ordersSettings['platform_sync_default_days'] ?? 7, 1, 30);
        $countLimit = $this->boundedInt($_POST['count_limit'] ?? 30, 1, 100);
        $result = (new ProductImageDownloadService($this->store))->run($tenantKey, [
            'day_limit' => $dayLimit,
            'count_limit' => $countLimit,
        ]);

        $this->store->addImportExportLog($tenantKey, [
            'type' => 'import',
            'name' => '订单图片下载',
            'status' => $result['ok'] ? '下载完成' : '下载异常',
            'file_name' => 'Product Image Download',
            'rows' => (int) ($result['updated'] ?? 0),
            'message' => $result['message'],
            'preview' => [[
                '扫描' => (string) ($result['scanned'] ?? 0),
                '下载' => (string) ($result['updated'] ?? 0),
                '跳过' => (string) ($result['skipped'] ?? 0),
                '失败' => (string) ($result['failed'] ?? 0),
            ]],
            'created_by' => $this->currentUserName($tenantKey),
        ]);

        $defaultReturn = tenant_url('/orders?view=platform' . ($platform !== '' ? '&platform=' . rawurlencode($platform) : ''), $tenantKey);
        $returnUrl = $this->safeReturn((string) ($_POST['return'] ?? $defaultReturn), $defaultReturn);
        $separator = str_contains($returnUrl, '?') ? '&' : '?';
        redirect_to($returnUrl . $separator . 'message=' . rawurlencode('订单图片下载完成：' . $result['message']));
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
}
