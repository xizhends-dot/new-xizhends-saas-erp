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

final class ImportExportController extends TenantBaseController
{

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

    public function orderTools(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireTenantPermission($tenantKey, '公司设置');
        $registry = new OrderPageConfigRegistry($this->store, $tenantKey);

        $this->renderTenant('tenant/order_export_tools', $tenantKey, [
            'title' => '订单页导出按钮管理',
            'active' => 'import_export',
            'builtinTools' => $registry->builtinToolsForConfig(),
            'templates' => $this->exportTemplateService->templatesForTenant($tenantKey),
            'displayConfig' => $registry->displayConfig(),
            'message' => (string) ($_GET['message'] ?? ''),
        ]);
    }

    public function saveOrderTools(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireTenantPermission($tenantKey, '公司设置');
        $registry = new OrderPageConfigRegistry($this->store, $tenantKey);
        $templates = $this->exportTemplateService->templatesForTenant($tenantKey);
        $allowedKeys = array_map(static fn (array $tool): string => (string) ($tool['key'] ?? ''), $registry->builtinToolsForConfig());
        foreach ($templates as $template) {
            $templateId = trim((string) ($template['id'] ?? ''));
            if ($templateId !== '') {
                $allowedKeys[] = OrderPageConfigRegistry::templateToolKey($templateId);
            }
        }

        $raw = is_array($_POST['tools'] ?? null) ? $_POST['tools'] : [];
        $config = OrderPageConfigRegistry::normalizeDisplayConfig($raw, $allowedKeys);
        $this->store->saveTenantSettings($tenantKey, ['order_export_tools' => $config]);

        header('Location: /import-export/order-tools?tenant=' . urlencode($tenantKey) . '&message=' . urlencode('订单页导出按钮设置已保存。'));
        exit;
    }

    public function exportCsv(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $type = preg_replace('/[^a-z_]/', '', (string) ($_GET['type'] ?? 'purchase')) ?: 'purchase';
        $this->ensurePlatformFeatureAccess($tenantKey, (string) ($_GET['platform'] ?? ''));
        $this->ensureImportExportAccess($tenantKey, $type);
        $this->sendSpreadsheetExportIfNeeded($tenantKey, $type, $_GET);

        $dataset = $this->service->exportDataset($tenantKey, $type, $this->auth->currentTenantUser($tenantKey), $this->exportCriteriaFrom($_GET));
        $this->sendCsvDataset($tenantKey, $dataset);
    }

    public function importExportNonExcel(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);

        $this->renderTenant('tenant/import_export_non_excel', $tenantKey, [
            'title' => '非 Excel 导入导出',
            'active' => 'import_export',
            'exportTemplates' => $this->exportTemplateService->templatesForTenant($tenantKey),
            'canManageTemplates' => $this->auth->tenantCan($tenantKey, '公司设置'),
            'message' => (string) ($_GET['message'] ?? ''),
            'importPreviews' => [],
            'stores' => $this->accessibleStoresForCurrentUser($tenantKey),
            'excelRequirements' => array_merge(
                $this->financeExportRequirementService->excelRequirements(),
                array_map(
                    static fn (string $item): array => ['item' => $item, 'reason' => '已通过 PhpSpreadsheet 生成样式 XLSX。', 'usage' => '客户资料导出时保留固定列宽、表头样式和文本格式。'],
                    $this->customerExportService->excelRequirements()
                )
            ),
        ]);
    }

    public function exportTemplateEdit(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireTenantPermission($tenantKey, '公司设置');
        $returnUrl = $this->safeReturn((string) ($_GET['return'] ?? ''), '/import-export/non-excel?tenant=' . rawurlencode($tenantKey));
        $id = trim((string) ($_GET['id'] ?? ''));
        $template = null;
        if ($id !== '') {
            $template = $this->exportTemplateService->find($tenantKey, $id);
            if ($template === null) {
                $this->forbid('导出模板不存在。');
            }
            if (str_starts_with((string) ($template['id'] ?? ''), 'builtin_')) {
                $template['id'] = '';
                $template['name'] = (string) $template['name'] . '（副本）';
            }
        }

        $this->renderTenant('tenant/export_template_edit', $tenantKey, [
            'title' => $template === null ? '新建导出模板' : '编辑导出模板',
            'active' => 'import_export',
            'template' => $template,
            'exportTemplate' => $template,
            'fieldGroups' => ExportFieldRegistry::groups(),
            'errors' => [],
            'returnUrl' => $returnUrl,
        ]);
    }

    public function saveExportTemplate(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireTenantPermission($tenantKey, '公司设置');
        $returnUrl = $this->safeReturn((string) ($_POST['return'] ?? ''), '/import-export/non-excel?tenant=' . rawurlencode($tenantKey));
        $columns = json_decode((string) ($_POST['columns_json'] ?? '[]'), true);
        $input = [
            'id' => trim((string) ($_POST['id'] ?? '')),
            'name' => trim((string) ($_POST['name'] ?? '')),
            'format' => (string) ($_POST['format'] ?? 'xlsx'),
            'platforms' => is_array($_POST['platforms'] ?? null) ? array_values($_POST['platforms']) : [],
            'columns' => is_array($columns) ? $columns : [],
        ];
        $result = $this->exportTemplateService->save($tenantKey, $input);
        if ($result['errors'] !== []) {
            $this->renderTenant('tenant/export_template_edit', $tenantKey, [
                'title' => '编辑导出模板',
                'active' => 'import_export',
                'template' => $input + ['id' => $input['id']],
                'exportTemplate' => $input + ['id' => $input['id']],
                'fieldGroups' => ExportFieldRegistry::groups(),
                'errors' => $result['errors'],
                'returnUrl' => $returnUrl,
            ]);
            return;
        }

        header('Location: ' . $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'message=' . urlencode('模板已保存。'));
        exit;
    }

    public function deleteExportTemplate(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireTenantPermission($tenantKey, '公司设置');
        $id = trim((string) ($_POST['id'] ?? ''));
        $returnUrl = $this->safeReturn((string) ($_POST['return'] ?? ''), '/import-export/non-excel?tenant=' . rawurlencode($tenantKey));
        $message = $this->exportTemplateService->delete($tenantKey, $id) ? '模板已删除。' : '模板不存在或为系统预置,无法删除。';
        header('Location: ' . $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'message=' . urlencode($message));
        exit;
    }

    public function previewExportTemplate(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireTenantPermission($tenantKey, '公司设置');
        header('Content-Type: application/json; charset=UTF-8');
        $columns = json_decode((string) ($_POST['columns_json'] ?? '[]'), true);
        $errors = $this->exportTemplateService->validateColumns(is_array($columns) ? $columns : []);
        if ($errors !== []) {
            echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $orders = array_slice(
            $this->service->ordersForExport($tenantKey, $this->auth->currentTenantUser($tenantKey), $this->exportCriteriaFrom($_POST)),
            0,
            3
        );
        $dataset = $this->platformExportService->render(
            ['id' => 'preview', 'name' => '预览', 'format' => 'csv', 'columns' => $columns],
            $orders
        );
        echo json_encode(['ok' => true, 'headers' => $dataset['headers'], 'rows' => $dataset['rows']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function exportPlatformSpecial(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->requireTenantFeature($tenantKey, 'import_export.platform_special');
        $this->auth->requireTenantPermission($tenantKey, '导入导出');
        $templateId = trim((string) ($_GET['template_id'] ?? ''));
        if ($templateId === '') {
            $legacy = preg_replace('/[^a-z0-9_]/', '', (string) ($_GET['variant'] ?? 'riya')) ?: 'riya';
            $templateId = $this->exportTemplateService->fromLegacyVariant($legacy) ?? 'builtin_riya';
        }
        $template = $this->exportTemplateService->find($tenantKey, $templateId);
        if ($template === null) {
            $this->forbid('导出模板不存在。');
        }

        $orders = $this->service->ordersForExport($tenantKey, $this->auth->currentTenantUser($tenantKey), $this->exportCriteriaFrom($_GET));
        $dataset = $this->platformExportService->render($template, $orders);
        if ($dataset['format'] === 'xlsx') {
            $this->sendXlsxFile($tenantKey, $this->spreadsheetExportService->shippingWorkbook($tenantKey, $dataset, $this->currentUserName($tenantKey)));
        }

        $this->sendCsvDataset($tenantKey, $dataset);
    }

    public function exportCustomers(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'customers.data');
        $this->auth->requireTenantPermission($tenantKey, '客户资料');
        $orders = $this->service->ordersForExport($tenantKey, $this->auth->currentTenantUser($tenantKey), $this->exportCriteriaFrom($_GET));
        $dataset = $this->customerExportService->exportDataset($tenantKey, $orders, $_GET);
        $this->sendXlsxFile($tenantKey, $this->buildCustomerWorkbook($tenantKey, $dataset));
    }

    public function exportFinancePlaceholder(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.finance');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '财务导出']);
        $orders = $this->service->ordersForExport($tenantKey, $this->auth->currentTenantUser($tenantKey), $this->exportCriteriaFrom($_GET));
        $orders = $this->withOrderAttachments($tenantKey, $orders);
        $this->sendXlsxFile($tenantKey, $this->buildFinanceWorkbook($tenantKey, $orders, $this->financeExportVariantFrom($_GET)));
    }

    public function exportBrushOrders(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.platform');
        $this->auth->requireTenantPermission($tenantKey, '导入导出');
        $orders = $this->service->ordersForExport($tenantKey, $this->auth->currentTenantUser($tenantKey), $this->exportCriteriaFrom($_GET));
        $this->sendCsvDataset($tenantKey, $this->legacyEdgeToolService->brushOrderDataset($tenantKey, $orders));
    }

    public function previewFinanceImport(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.finance_import');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '财务导入']);
        $preview = $this->financeImportMatcherService->planForOrders(
            $this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey)),
            $this->csvRowsFromUpload('csv_file')
        );
        $this->logImportPreview($tenantKey, '财务数据导入预览', $preview['summary']['rows'] ?? 0, $preview);
        $this->json(['ok' => true] + $preview);
    }

    public function importFinanceData(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.finance_import');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '财务导入']);
        $preview = $this->financeImportMatcherService->planForOrders(
            $this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey)),
            $this->csvRowsFromUpload('csv_file')
        );

        $updated = 0;
        foreach ($preview['updates'] as $update) {
            $changes = is_array($update['changes'] ?? null) ? $update['changes'] : [];
            $itemId = (int) ($update['item_id'] ?? 0);
            if ($itemId <= 0 || $changes === []) {
                continue;
            }
            $this->store->updateOrderItem($tenantKey, $itemId, $changes, $this->currentUserName($tenantKey), '财务数据导入');
            $updated++;
        }

        $this->store->addImportExportLog($tenantKey, [
            'type' => 'import',
            'name' => '财务数据导入',
            'status' => $updated > 0 ? '已导入' : '无可导入记录',
            'file_name' => (string) ($_FILES['csv_file']['name'] ?? ''),
            'rows' => $updated,
            'message' => "已更新 {$updated} 条财务匹配记录，未匹配/错误 " . count((array) ($preview['errors'] ?? [])) . ' 条。',
            'preview' => array_slice((array) ($preview['updates'] ?? []), 0, 5),
            'created_by' => $this->currentUserName($tenantKey),
        ]);
        $this->json(['ok' => true, 'updated' => $updated, 'errors' => $preview['errors'] ?? []]);
    }

    public function previewShippingImportModes(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.shipping_modes');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $preview = $this->shippingImportModeService->parseRows($this->csvRowsFromUpload('csv_file'));
        $this->logImportPreview($tenantKey, '国际运单导入模式预览', (int) ($preview['row_count'] ?? 0), $preview);
        $this->json(['ok' => true] + $preview);
    }

    public function importShippingModes(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.shipping_modes');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $preview = $this->shippingImportModeService->parseRows($this->csvRowsFromUpload('csv_file'));
        $records = $this->shippingRecordsForAccessibleOrders(
            $this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey)),
            (array) ($preview['records'] ?? [])
        );
        $report = $records
            ? $this->store->importShippingRows($tenantKey, $records, $this->currentUserName($tenantKey))
            : ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];

        $this->store->addImportExportLog($tenantKey, [
            'type' => 'import',
            'name' => '国际运单导入(追加/覆盖)',
            'status' => (int) ($report['failed'] ?? 0) > 0 ? '部分导入' : ((int) ($report['updated'] ?? 0) > 0 ? '已导入' : '无可导入记录'),
            'file_name' => (string) ($_FILES['csv_file']['name'] ?? ''),
            'rows' => (int) ($preview['row_count'] ?? 0),
            'message' => $this->importLogMessage(
                '国际运单导入：更新 ' . (int) ($report['updated'] ?? 0) . '，失败 ' . (int) ($report['failed'] ?? 0) . '。',
                (array) ($preview['errors'] ?? []),
                (array) ($report['messages'] ?? [])
            ),
            'preview' => array_slice($records, 0, 5),
            'created_by' => $this->currentUserName($tenantKey),
        ]);
        $this->json(['ok' => true, 'report' => $report, 'errors' => $preview['errors'] ?? []]);
    }

    public function previewJapanWarehouseImport(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.jp_warehouse_import');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $preview = $this->japanWarehouseImportService->planForOrders(
            $this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey)),
            $this->csvRowsFromUpload('csv_file')
        );
        $this->logImportPreview($tenantKey, '日本仓 YD 导入预览', (int) ($preview['parsed']['row_count'] ?? 0), $preview);
        $this->json(['ok' => true] + $preview);
    }

    public function importJapanWarehouse(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.jp_warehouse_import');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $preview = $this->japanWarehouseImportService->planForOrders(
            $this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey)),
            $this->csvRowsFromUpload('csv_file')
        );

        $updated = 0;
        foreach ($preview['updates'] as $update) {
            $changes = is_array($update['changes'] ?? null) ? $update['changes'] : [];
            $itemId = (int) ($update['item_id'] ?? 0);
            if ($itemId <= 0 || $changes === []) {
                continue;
            }
            $this->store->updateOrderItem($tenantKey, $itemId, $changes, $this->currentUserName($tenantKey), '日本仓YD导入');
            $updated++;
        }

        $this->store->addImportExportLog($tenantKey, [
            'type' => 'import',
            'name' => '日本仓 YD 导入',
            'status' => $updated > 0 ? '已导入' : '无可导入记录',
            'file_name' => (string) ($_FILES['csv_file']['name'] ?? ''),
            'rows' => (int) ($preview['parsed']['row_count'] ?? 0),
            'message' => "已更新 {$updated} 条日本仓 YD 记录，未匹配 " . count((array) ($preview['unmatched'] ?? [])) . ' 条。',
            'preview' => array_slice((array) ($preview['updates'] ?? []), 0, 5),
            'created_by' => $this->currentUserName($tenantKey),
        ]);
        $this->json(['ok' => true, 'updated' => $updated, 'unmatched' => $preview['unmatched'] ?? [], 'errors' => $preview['parsed']['errors'] ?? []]);
    }

    public function externalInsertPreview(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.platform');
        $this->auth->requireTenantPermission($tenantKey, '导入导出');
        $store = $this->accessibleStoreFromInput($tenantKey, $_POST['store_id'] ?? null);
        $preview = $this->legacyEdgeToolService->parseExternalInsertRows($this->csvRowsFromUpload('csv_file'));
        if ($store === null) {
            $preview['errors'][] = '请选择当前账号可访问的目标店铺。';
            $preview['records'] = [];
            $preview['preview'] = [];
        }
        $this->logImportPreview($tenantKey, '外部运单/订单插入预览', (int) ($preview['row_count'] ?? 0), $preview);
        $this->json(['ok' => true] + $preview);
    }

    public function externalInsertImport(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.platform');
        $this->auth->requireTenantPermission($tenantKey, '导入导出');
        $store = $this->accessibleStoreFromInput($tenantKey, $_POST['store_id'] ?? null);
        if ($store === null) {
            $this->json(['ok' => false, 'status' => 403, 'message' => '请选择当前账号可访问的目标店铺。', 'inserted' => 0, 'errors' => ['目标店铺无权访问。']], 403);
        }
        $preview = $this->legacyEdgeToolService->parseExternalInsertRows($this->csvRowsFromUpload('csv_file'));
        $inserted = 0;
        foreach ($preview['records'] as $record) {
            $record['store_id'] = (int) ($store['id'] ?? 0);
            $record['store'] = (string) (($store['name'] ?? '') ?: ($store['short'] ?? ''));
            if ($this->store->insertExternalOrder($tenantKey, $record, $this->currentUserName($tenantKey)) > 0) {
                $inserted++;
            }
        }
        $this->store->addImportExportLog($tenantKey, [
            'type' => 'import',
            'name' => '外部运单/订单插入',
            'status' => $inserted > 0 ? '已导入' : '无可导入记录',
            'file_name' => (string) ($_FILES['csv_file']['name'] ?? ''),
            'rows' => $inserted,
            'message' => "已导入 {$inserted} 条外部记录。",
            'preview' => $preview['preview'],
            'created_by' => $this->currentUserName($tenantKey),
        ]);
        $this->json(['ok' => true, 'inserted' => $inserted, 'errors' => $preview['errors']]);
    }

    public function importCsv(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'import_export.center');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '采购导入导出']);
        $job = preg_replace('/[^a-z_]/', '', (string) ($_POST['job'] ?? 'platform_orders_import')) ?: 'platform_orders_import';
        $this->ensureImportExportAccess($tenantKey, $job);
        $label = $this->importJobLabel($job);
        $message = '未收到可解析的 CSV/XLSX 文件。';
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
            if ($tmpName !== '' && is_uploaded_file($tmpName) && (int) ($_FILES['csv_file']['size'] ?? 0) <= 10 * 1024 * 1024) {
                $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_POST['platform'] ?? '')) ?: '';
                $storeId = max(0, (int) ($_POST['store_id'] ?? 0));
                if ($platform !== '') {
                    $this->ensurePlatformFeatureAccess($tenantKey, $platform);
                }

                $parsed = $this->csvImportService->parseFile($tmpName, $job, [
                    'platform' => $platform,
                    'store_id' => $storeId,
                    'platform_names' => $this->service->platformNames(),
                    'stores' => $this->accessibleStoresForCurrentUser($tenantKey),
                    'user' => $this->auth->currentTenantUser($tenantKey),
                ]);
                $rowCount = (int) $parsed['row_count'];
                $preview = $parsed['preview'];
                $parseErrors = $parsed['errors'];
                $records = $this->filterImportRecordsByPlatform($tenantKey, $parsed['records'], $parseErrors);
                $records = $this->filterImportRecordsByCurrentUserAccess($tenantKey, $job, $records, $parseErrors);

                if ($rowCount <= 0) {
                    $status = '空文件';
                    $message = '导入文件没有数据行。';
                } elseif (!$records) {
                    $status = '解析失败';
                    $message = '已读取导入文件，但没有可写入的数据。' . ($parseErrors ? ' ' . implode('；', array_slice($parseErrors, 0, 3)) : '');
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
                $message = '文件超过 10MB 或上传临时文件不可读。';
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

    private function importJobLabel(string $job): string
    {
        return match ($job) {
            'purchase_import' => '采购表导入',
            'shipping_import' => '国际运单导入',
            default => '平台订单 CSV 导入',
        };
    }

    private function importLogMessage(string $summary, array $parseErrors, array $writeMessages): string
    {
        $details = array_merge(array_slice($parseErrors, 0, 5), array_slice($writeMessages, 0, 10));
        if (!$details) {
            return $summary;
        }

        return $summary . ' 明细：' . implode('；', $details);
    }

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

    private function filterImportRecordsByCurrentUserAccess(string $tenantKey, string $job, array $records, array &$errors): array
    {
        if ($records === []) {
            return [];
        }

        if ($job === 'platform_orders_import') {
            return $this->filterPlatformImportRecordsByAccessibleStores($tenantKey, $records, $errors);
        }

        if (in_array($job, ['purchase_import', 'shipping_import'], true)) {
            return $this->filterImportRecordsByAccessibleOrders($tenantKey, $records, $errors);
        }

        return $records;
    }

    private function filterPlatformImportRecordsByAccessibleStores(string $tenantKey, array $records, array &$errors): array
    {
        $storesById = [];
        foreach ($this->accessibleStoresForCurrentUser($tenantKey) as $store) {
            $storeId = (int) ($store['id'] ?? 0);
            if ($storeId > 0) {
                $storesById[$storeId] = $store;
            }
        }

        $filtered = [];
        foreach ($records as $record) {
            $order = is_array($record['order'] ?? null) ? $record['order'] : [];
            $storeId = (int) ($order['store_id'] ?? 0);
            if ($storeId <= 0 || !isset($storesById[$storeId])) {
                $errors[] = '第 ' . (int) ($record['row'] ?? 0) . ' 行：目标店铺不在当前账号可访问范围内，已跳过。';
                continue;
            }

            $store = $storesById[$storeId];
            $record['order']['store_id'] = $storeId;
            $record['order']['store'] = (string) (($store['name'] ?? '') ?: ($store['short'] ?? ''));
            $filtered[] = $record;
        }

        return $filtered;
    }

    private function filterImportRecordsByAccessibleOrders(string $tenantKey, array $records, array &$errors): array
    {
        $orders = $this->service->ordersForUser($tenantKey, $this->auth->currentTenantUser($tenantKey));
        $keys = [];
        foreach ($orders as $order) {
            $platform = trim((string) ($order['platform'] ?? ''));
            $orderNo = trim((string) ($order['platform_order_id'] ?? ''));
            if ($orderNo === '') {
                continue;
            }
            $keys[$platform . "\n" . $orderNo] = true;
            $keys["\n" . $orderNo] = true;
        }

        $filtered = [];
        foreach ($records as $record) {
            $identity = is_array($record['identity'] ?? null) ? $record['identity'] : [];
            $platform = trim((string) ($identity['platform'] ?? ''));
            $orderNo = trim((string) ($identity['platform_order_id'] ?? ''));
            if ($orderNo === '' || (!isset($keys[$platform . "\n" . $orderNo]) && !isset($keys["\n" . $orderNo]))) {
                $errors[] = '第 ' . (int) ($record['row'] ?? 0) . ' 行：订单不在当前账号可访问范围内，已跳过。';
                continue;
            }
            $filtered[] = $record;
        }

        return $filtered;
    }

    private function accessibleStoresForCurrentUser(string $tenantKey): array
    {
        $user = $this->auth->currentTenantUser($tenantKey);

        return array_values(array_filter(
            $this->service->storesForTenant($tenantKey),
            static fn (array $store): bool => Permission::canAccessStore($user, (string) (($store['name'] ?? '') ?: ($store['short'] ?? '')))
        ));
    }

    private function accessibleStoreFromInput(string $tenantKey, mixed $storeId): ?array
    {
        $storeId = (int) $storeId;
        if ($storeId <= 0) {
            return null;
        }

        foreach ($this->accessibleStoresForCurrentUser($tenantKey) as $store) {
            if ((int) ($store['id'] ?? 0) === $storeId) {
                return $store;
            }
        }

        return null;
    }

    private function shippingRecordsForAccessibleOrders(array $orders, array $records): array
    {
        $orderMap = [];
        foreach ($orders as $order) {
            $orderNo = trim((string) ($order['platform_order_id'] ?? ''));
            if ($orderNo === '') {
                continue;
            }
            $orderMap[$orderNo] = $order;
        }

        $filtered = [];
        foreach ($records as $record) {
            $identity = is_array($record['identity'] ?? null) ? $record['identity'] : [];
            $orderNo = trim((string) ($identity['platform_order_id'] ?? ''));
            if ($orderNo === '' || !isset($orderMap[$orderNo])) {
                continue;
            }

            $record['identity'] = [
                'platform' => (string) ($orderMap[$orderNo]['platform'] ?? ''),
                'platform_order_id' => $orderNo,
            ];
            $filtered[] = $record;
        }

        return $filtered;
    }

    private function csvRowsFromUpload(string $field): array
    {
        if (!isset($_FILES[$field]) || !is_array($_FILES[$field]) || (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [];
        }

        $tmpName = (string) ($_FILES[$field]['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName) || (int) ($_FILES[$field]['size'] ?? 0) > 3 * 1024 * 1024) {
            return [];
        }

        $handle = fopen($tmpName, 'r');
        if ($handle === false) {
            return [];
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map(static fn (mixed $value): string => trim((string) $value), $row);
        }
        fclose($handle);

        return $rows;
    }

    private function logImportPreview(string $tenantKey, string $name, int $rows, array $preview): void
    {
        $this->store->addImportExportLog($tenantKey, [
            'type' => 'import',
            'name' => $name,
            'status' => '预览',
            'file_name' => (string) ($_FILES['csv_file']['name'] ?? ''),
            'rows' => $rows,
            'message' => '已生成预览，未写入数据。',
            'preview' => array_slice((array) ($preview['preview'] ?? $preview['updates'] ?? $preview['records'] ?? []), 0, 5),
            'created_by' => $this->currentUserName($tenantKey),
        ]);
    }

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
}
