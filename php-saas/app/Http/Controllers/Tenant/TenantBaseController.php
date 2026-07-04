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

abstract class TenantBaseController
{
    protected AppService $service;
    protected CsvImportService $csvImportService;
    protected LegacySettingsService $legacySettings;
    protected MailService $mailService;
    protected PlatformOrderSyncRegistry $platformOrderSyncRegistry;
    protected ShippingWorkflowService $shippingWorkflowService;
    protected Alibaba1688LogisticsService $alibaba1688LogisticsService;
    protected ExpressLogisticsService $expressLogisticsService;
    protected JapanLogisticsService $japanLogisticsService;
    protected ShippingAnomalyService $shippingAnomalyService;
    protected WaybillCheckService $waybillCheckService;
    protected PerformanceStatsService $performanceStatsService;
    protected PurchaseStatsService $purchaseStatsService;
    protected PriceCalculatorService $priceCalculatorService;
    protected PlatformExportService $platformExportService;
    protected ExportTemplateService $exportTemplateService;
    protected FinanceImportMatcherService $financeImportMatcherService;
    protected ShippingImportModeService $shippingImportModeService;
    protected JapanWarehouseImportService $japanWarehouseImportService;
    protected CustomerExportService $customerExportService;
    protected FinanceExportRequirementService $financeExportRequirementService;
    protected TenantUserSecurityService $tenantUserSecurityService;
    protected TenantNoticeService $tenantNoticeService;
    protected UserPermissionOverrideService $userPermissionOverrideService;
    protected CustomerServiceDeductionService $customerServiceDeductionService;
    protected OrderAjaxService $orderAjaxService;
    protected LegacyEdgeToolService $legacyEdgeToolService;
    protected SpreadsheetExportService $spreadsheetExportService;
    protected YahooShopOAuthService $yahooShopOAuthService;
    protected PurchaseStatusService $purchaseStatusService;

    public function __construct(protected readonly StoreInterface $store, protected readonly View $view, protected readonly AuthService $auth)
    {
        $this->service = new AppService($store);
        $this->csvImportService = new CsvImportService();
        $this->legacySettings = new LegacySettingsService(BASE_PATH . '/../old/setting.ini');
        $this->mailService = new MailService($store);
        $this->platformOrderSyncRegistry = new PlatformOrderSyncRegistry($store);
        $this->shippingWorkflowService = new ShippingWorkflowService();
        $this->alibaba1688LogisticsService = new Alibaba1688LogisticsService($store);
        $this->expressLogisticsService = new ExpressLogisticsService($store);
        $this->japanLogisticsService = new JapanLogisticsService($store);
        $this->shippingAnomalyService = new ShippingAnomalyService($store);
        $this->waybillCheckService = new WaybillCheckService($store);
        $this->performanceStatsService = new PerformanceStatsService($store);
        $this->purchaseStatsService = new PurchaseStatsService($store);
        $this->priceCalculatorService = new PriceCalculatorService($store);
        $this->platformExportService = new PlatformExportService();
        $this->exportTemplateService = new ExportTemplateService($store);
        $this->financeImportMatcherService = new FinanceImportMatcherService();
        $this->shippingImportModeService = new ShippingImportModeService();
        $this->japanWarehouseImportService = new JapanWarehouseImportService();
        $this->customerExportService = new CustomerExportService();
        $this->financeExportRequirementService = new FinanceExportRequirementService();
        $this->tenantUserSecurityService = new TenantUserSecurityService($store);
        $this->tenantNoticeService = new TenantNoticeService($store);
        $this->userPermissionOverrideService = new UserPermissionOverrideService($store);
        $this->customerServiceDeductionService = new CustomerServiceDeductionService($store);
        $this->orderAjaxService = new OrderAjaxService($store, $this->service, $view);
        $this->legacyEdgeToolService = new LegacyEdgeToolService($store);
        $this->spreadsheetExportService = new SpreadsheetExportService(BASE_PATH);
        $this->yahooShopOAuthService = new YahooShopOAuthService($store);
        $this->purchaseStatusService = new PurchaseStatusService($store);
    }

    protected function sendCsvDataset(string $tenantKey, array $dataset): never
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
            fputcsv($out, $this->csvSafeRow($dataset['headers']), ',', '"', '\\');
            foreach ($dataset['rows'] as $row) {
                fputcsv($out, $this->csvSafeRow($row), ',', '"', '\\');
            }
            fclose($out);
        }
        exit;
    }

    protected function sendXlsxFile(string $tenantKey, array $file): never
    {
        $path = (string) ($file['path'] ?? '');
        if ($path === '' || !is_file($path)) {
            $this->forbid('Excel 文件生成失败，请检查导出服务配置。');
        }

        $this->store->addImportExportLog($tenantKey, [
            'type' => 'export',
            'name' => (string) ($file['name'] ?? 'Excel 导出'),
            'status' => '已导出',
            'file_name' => (string) ($file['filename'] ?? basename($path)),
            'rows' => (int) ($file['rows'] ?? 0),
            'message' => 'XLSX 已生成下载。',
            'created_by' => $this->currentUserName($tenantKey),
        ]);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', (string) ($file['filename'] ?? 'export.xlsx')) . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: max-age=0');
        readfile($path);
        @unlink($path);
        exit;
    }

    protected function withOrderAttachments(string $tenantKey, array $orders): array
    {
        foreach ($orders as &$order) {
            $order['_attachments'] = $this->store->orderAttachments($tenantKey, (int) ($order['id'] ?? 0));
        }
        unset($order);

        return $orders;
    }

    protected function sendSpreadsheetExportIfNeeded(string $tenantKey, string $type, array $source): void
    {
        if (!in_array($type, ['purchase', 'finance', 'customers'], true)) {
            return;
        }

        $criteria = $this->exportCriteriaFrom($source);
        if ($type === 'purchase') {
            $criteria['view'] = 'purchase';
        }
        $orders = $this->service->ordersForExport($tenantKey, $this->auth->currentTenantUser($tenantKey), $criteria);
        if ($type === 'purchase') {
            $this->sendXlsxFile($tenantKey, $this->buildPurchaseWorkbook(
                $tenantKey,
                $orders,
                preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($source['platform'] ?? '')) ?: ''
            ));
        }

        if ($type === 'finance') {
            $this->sendXlsxFile($tenantKey, $this->buildFinanceWorkbook(
                $tenantKey,
                $this->withOrderAttachments($tenantKey, $orders),
                $this->financeExportVariantFrom($source)
            ));
        }

        $dataset = $this->customerExportService->exportDataset($tenantKey, $orders, $source);
        $this->sendXlsxFile($tenantKey, $this->buildCustomerWorkbook($tenantKey, $dataset));
    }

    protected function buildPurchaseWorkbook(string $tenantKey, array $orders, string $platform = ''): array
    {
        try {
            return $this->spreadsheetExportService->purchaseWorkbook(
                $tenantKey,
                $orders,
                $this->currentUserName($tenantKey),
                $platform,
                $this->service->purchaseStatuses($tenantKey)
            );
        } catch (RuntimeException $exception) {
            $this->forbid($exception->getMessage());
        }
    }

    protected function buildFinanceWorkbook(string $tenantKey, array $orders, string $variant = ''): array
    {
        try {
            return $this->spreadsheetExportService->financeWorkbook($tenantKey, $orders, $this->currentUserName($tenantKey), $variant);
        } catch (RuntimeException $exception) {
            $this->forbid($exception->getMessage());
        }
    }

    protected function financeExportVariantFrom(array $source): string
    {
        $variant = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($source['variant'] ?? '')) ?: '';
        if ($variant !== '') {
            return $variant;
        }

        return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($source['platform'] ?? '')) ?: '';
    }

    protected function buildCustomerWorkbook(string $tenantKey, array $dataset): array
    {
        try {
            return $this->spreadsheetExportService->customerWorkbook($tenantKey, $dataset, $this->currentUserName($tenantKey));
        } catch (RuntimeException $exception) {
            $this->forbid($exception->getMessage());
        }
    }

    protected function csvSafeRow(array $row): array
    {
        return array_map($this->csvSafeCell(...), $row);
    }

    protected function csvSafeCell(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return preg_match('/^\s*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
    }

    protected function renderTenant(string $template, string $tenantKey, array $data): void
    {
        $this->view->render($template, array_merge([
            'tenantKey' => $tenantKey,
            'tenant' => $this->store->tenant($tenantKey),
            'menu' => $this->service->platformMenu($tenantKey),
            'tenantFeatures' => $this->service->tenantFeatureMap($tenantKey),
            'currentUser' => $this->auth->currentTenantUser($tenantKey),
        ], $data));
    }

    protected function currentUserName(string $tenantKey): string
    {
        $user = $this->auth->currentTenantUser($tenantKey);
        return (string) (($user['name'] ?? '') ?: ($user['username'] ?? '系统'));
    }

    protected function ensureImportExportAccess(string $tenantKey, string $operation): void
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

    protected function requireTenantFeature(string $tenantKey, string $featureKey): void
    {
        if ($this->service->tenantFeatureEnabled($tenantKey, $featureKey)) {
            return;
        }

        $this->forbid('当前租户未开通该功能，请联系 SaaS 超级管理员。');
    }

    protected function ensurePlatformFeatureAccess(string $tenantKey, ?string $platform): void
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

    protected function featureForImportExportOperation(string $operation): string
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

    protected function orderFiltersFrom(array $source, string $keyword = ''): array
    {
        $first = static function (array $keys) use ($source): string {
            foreach ($keys as $key) {
                $value = trim((string) ($source[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            return '';
        };

        return [
            'order_no' => $first(['order_no', 'orderId', 'ziid']),
            'tabaono' => $first(['tabaono']),
            'status' => $first(['status', 'beizhu']),
            'store' => $first(['store', 'shop_select']),
            'item_id' => $first(['item_id', 'ItemId']),
            'lot_number' => $first(['lot_number', 'lotnumber']),
            'lot_number_empty' => $first(['lot_number_empty']),
            'item_management_id' => $first(['item_management_id', 'itemManagementId']),
            'order_detail_id' => $first(['order_detail_id']),
            'mail' => $first(['mail', 'mails']),
            'customer_name' => $first(['customer_name', 'sendname']),
            'kana' => $first(['kana', 'senderKana', 'pianjiaming']),
            'phone' => $first(['phone', 'sendphone']),
            'pay_method' => $first(['pay_method', 'settlementName']),
            'ship_method' => $first(['ship_method', 'deliveryName', 'PayStatus', 'yunshu']),
            'buyer' => $first(['buyer']),
            'product_name' => $first(['product_name', 'product_title']),
            'purchase_link' => $first(['purchase_link', 'caigoulink']),
            'comment' => $first(['comment']),
            'purchase_comment' => $first(['purchase_comment', 'cg_comment']),
            'cn_ship_no' => $first(['cn_ship_no', 'shipno']),
            'intl_ship_no' => $first(['intl_ship_no', 'shipnumber']),
            'intl_ship_empty' => $first(['intl_ship_empty', 'kong']),
            'carrier' => $first(['carrier']),
            'location' => $first(['location']),
            'receipt_city' => $first(['receipt_city']),
            'material' => $first(['material']),
            'date_from' => $first(['date_from']),
            'date_to' => $first(['date_to']),
            'import_date_from' => $first(['import_date_from', 'cdate']),
            'import_date_to' => $first(['import_date_to', 'cdate2']),
            'order_date_from' => $first(['order_date_from', 'orderDate', 'OrderTime']),
            'order_date_to' => $first(['order_date_to', 'orderDate2', 'OrderTime2']),
            'purchase_date_from' => $first(['purchase_date_from']),
            'purchase_date_to' => $first(['purchase_date_to']),
            'purchase_date' => $first(['purchase_date', 'caigoutime']),
            'review_invited' => $first(['review_invited', 'invite_review']),
            'reviewed' => $first(['reviewed']),
            'late_ship' => $first(['late_ship', 'chaoshifahuo']),
            'in_delivery' => $first(['in_delivery', 'haitatsuchuu']),
            'delivered' => $first(['delivered', 'haitatsukanryo']),
            'page_size' => (string) $this->boundedInt($source['page_size'] ?? $source['npage'] ?? 200, 20, 1000),
            'keyword' => $keyword,
        ];
    }

    protected function orderDateScope(string $view): string
    {
        return match ($view) {
            'purchase' => 'purchase',
            'jp' => 'order',
            default => 'imported',
        };
    }

    protected function exportCriteriaFrom(array $source): array
    {
        $keyword = $this->keywordFrom($source);
        $view = (string) ($source['view'] ?? 'platform');
        $view = in_array($view, ['platform', 'purchase', 'jp'], true) ? $view : 'platform';
        $filters = $this->orderFiltersFrom($source, $keyword);
        $filters['date_scope'] = $this->orderDateScope($view);
        $filters['default_pending'] = '1';

        return [
            'view' => $view,
            'platform' => (string) ($source['platform'] ?? ''),
            'source' => (string) ($source['source'] ?? 'all'),
            'keyword' => $keyword,
            'filters' => $filters,
            'item_ids' => $this->intList($source['item_ids'] ?? []),
            'order_ids' => $this->intList($source['order_ids'] ?? []),
        ];
    }

    protected function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected function keywordFrom(array $source): string
    {
        foreach (['q', 'order_no', 'tabaono'] as $key) {
            $value = trim((string) ($source[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    protected function boundedInt(mixed $value, int $min, int $max): int
    {
        $number = is_numeric($value) ? (int) $value : $min;
        return max($min, min($max, $number));
    }

    protected function forbid(string $message): never
    {
        http_response_code(403);
        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>无权限</title><link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page"><main class="login-card"><h1>无权限访问</h1><p>' . e($message) . '</p><p><a class="btn primary" href="javascript:history.back()">返回上一页</a></p></main></body></html>';
        exit;
    }

    protected function safeReturn(string $return, string $fallback): string
    {
        return str_starts_with($return, '/') && !str_starts_with($return, '//') ? $return : $fallback;
    }

    protected function intList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        return array_values(array_unique(array_filter(array_map('intval', $values))));
    }

    protected function ensureBatchAccess(string $tenantKey, array $itemIds, array $orderIds): void
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
}
