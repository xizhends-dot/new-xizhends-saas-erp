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
use Xizhen\Services\StoreApiFieldRegistry;
use Xizhen\Services\TenantNoticeService;
use Xizhen\Services\TenantUserSecurityService;
use Xizhen\Services\UserPermissionOverrideService;
use Xizhen\Services\WaybillCheckService;
use Xizhen\Services\YahooShopOAuthService;
use RuntimeException;

final class StoreController extends TenantBaseController
{

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
            'platformSyncServices' => $this->platformOrderSyncRegistry->names(),
            'wowmaSyncFolders' => $this->wowmaSyncFoldersFromSettings($this->store->tenantSettings($tenantKey)),
            'storeApiFields' => $this->storeApiFieldRegistry()->all(),
            'stores' => $this->service->storesForTenant($tenantKey),
            'billingAccount' => $this->store->tenantBillingAccount($tenantKey),
            'currentUser' => $this->auth->currentTenantUser($tenantKey),
            'message' => (string) ($_GET['message'] ?? ''),
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
                'api_config' => $this->apiConfigFromPost($platform),
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
            'storeApiFields' => $this->storeApiFieldRegistry()->all(),
            'storeApiValues' => $this->storeApiFieldRegistry()->fromJson((string) ($store['platform'] ?? ''), (string) ($store['api_config'] ?? '')),
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
            'api_config' => $this->apiConfigFromPost($platform),
            'profit_deduction' => $_POST['profit_deduction'] ?? 70,
            'hidden_reason' => $_POST['hidden_reason'] ?? '',
        ]);

        redirect_to('/stores?tenant=' . rawurlencode($tenantKey));
    }

    public function importOrdersForm(): void
    {
        $tenantKey = current_tenant_key();
        $store = $this->storeForOrderOperation($tenantKey, (int) ($_GET['id'] ?? 0));
        $platformNames = $this->service->tenantPlatformNames($tenantKey);

        $this->renderTenant('tenant/store_import', $tenantKey, [
            'title' => '店铺订单导入',
            'active' => 'stores',
            'store' => $store,
            'platformName' => $platformNames[$store['platform'] ?? ''] ?? ($store['platform'] ?? ''),
            'message' => (string) ($_GET['message'] ?? ''),
        ]);
    }

    public function importOrders(): void
    {
        $tenantKey = current_tenant_key();
        $store = $this->storeForOrderOperation($tenantKey, (int) ($_POST['id'] ?? $_POST['store_id'] ?? 0));
        $storeId = (int) ($store['id'] ?? 0);
        $storeName = (string) (($store['name'] ?? '') ?: ($store['short'] ?? ''));
        $platform = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($store['platform'] ?? '')) ?: '';
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
                $parsed = $this->csvImportService->parseFile($tmpName, 'platform_orders_import', [
                    'platform' => $platform,
                    'store_id' => $storeId,
                    'platform_names' => $this->service->platformNames(),
                    'stores' => [$store],
                    'user' => $this->auth->currentTenantUser($tenantKey),
                    'restrict_to_store_id' => true,
                ]);
                $rowCount = (int) $parsed['row_count'];
                $preview = $parsed['preview'];
                $parseErrors = $parsed['errors'];
                $storeMismatchCount = (int) ($parsed['store_mismatch_count'] ?? 0);
                $records = $parsed['records'];

                if ($rowCount <= 0) {
                    $status = '空文件';
                    $message = '导入文件没有数据行。';
                } elseif (!$records && $storeMismatchCount > 0) {
                    $status = '无可导入记录';
                    $message = "店铺订单导入：成功 0，更新 0，跳过 {$storeMismatchCount}。 {$storeMismatchCount} 行店铺列与所选店铺不符，已跳过。";
                } elseif (!$records) {
                    $status = '解析失败';
                    $message = '已读取导入文件，但没有可写入的数据。' . ($parseErrors ? ' ' . implode('；', array_slice($parseErrors, 0, 3)) : '');
                } else {
                    $writeReport = $this->store->importPlatformOrders($tenantKey, $records, $operator);
                    $skipped = (int) $writeReport['skipped'] + $storeMismatchCount;
                    $failed = (int) $writeReport['failed'] + max(0, count($parseErrors) - $storeMismatchCount);
                    $status = ($failed > 0 || $skipped > 0) ? '部分导入' : '已导入';
                    $message = sprintf(
                        '店铺订单导入：成功 %d，更新 %d，跳过 %d。',
                        (int) $writeReport['inserted'],
                        (int) $writeReport['updated'],
                        $skipped
                    );
                    if ($storeMismatchCount > 0) {
                        $message .= " {$storeMismatchCount} 行店铺列与所选店铺不符，已跳过。";
                    }
                    if ($failed > 0) {
                        $message .= " 失败 {$failed}。";
                    }
                }
            } else {
                $message = '文件超过 10MB 或上传临时文件不可读。';
            }
        }

        $this->store->addImportExportLog($tenantKey, [
            'type' => 'import',
            'name' => 'store_orders_import：' . $storeName,
            'status' => $status,
            'file_name' => $fileName,
            'rows' => $rowCount,
            'message' => $message,
            'preview' => $preview,
            'created_by' => $operator,
        ]);

        redirect_to('/stores?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode($message));
    }

    public function authorizeYahooShop(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.stores');
        $this->auth->requireTenantPermission($tenantKey, '店铺新增');
        $storeId = (int) ($_GET['id'] ?? 0);

        try {
            $url = $this->yahooShopOAuthService->authorizationUrl($tenantKey, $storeId, $this->absoluteUrl('/oauth/yahoo/callback'));
            redirect_to($url);
        } catch (RuntimeException $exception) {
            redirect_to('/stores/edit?tenant=' . rawurlencode($tenantKey) . '&id=' . $storeId . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function yahooOAuthCallback(): void
    {
        try {
            $result = $this->yahooShopOAuthService->handleCallback(
                (string) ($_GET['code'] ?? ''),
                (string) ($_GET['state'] ?? ''),
                $this->absoluteUrl('/oauth/yahoo/callback')
            );
            redirect_to('/stores/edit?tenant=' . rawurlencode($result['tenant_key']) . '&id=' . (int) $result['store_id'] . '&message=' . rawurlencode($result['message']));
        } catch (RuntimeException $exception) {
            $tenantKey = current_tenant_key();
            redirect_to('/stores?tenant=' . rawurlencode($tenantKey) . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    private function absoluteUrl(string $path): string
    {
        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        $scheme = $forwardedProto !== ''
            ? explode(',', $forwardedProto)[0]
            : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
        $scheme = in_array($scheme, ['http', 'https'], true) ? $scheme : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');

        return $scheme . '://' . $host . $path;
    }

    private function storeApiFieldRegistry(): StoreApiFieldRegistry
    {
        return new StoreApiFieldRegistry();
    }

    private function apiConfigFromPost(string $platform): string
    {
        $registry = $this->storeApiFieldRegistry();
        $fields = is_array($_POST['api_fields'] ?? null) ? $_POST['api_fields'] : [];
        $fieldValues = $this->apiFieldValues($registry, $platform, $fields);
        $originalFields = json_decode((string) ($_POST['api_fields_original'] ?? '[]'), true);
        $originalFieldValues = $this->apiFieldValues($registry, $platform, is_array($originalFields) ? $originalFields : []);
        $fieldJson = $registry->toJson($platform, $fields);
        $raw = trim((string) ($_POST['api_config_raw'] ?? ''));
        $original = trim((string) ($_POST['api_config_original'] ?? ''));
        $originalPlatform = strtolower(trim((string) ($_POST['api_config_platform_original'] ?? '')));
        $platformChanged = $originalPlatform !== '' && $originalPlatform !== strtolower(trim($platform));

        if ($raw !== '' && ($original === '' || $raw !== $original || ($fieldJson === '' && !$platformChanged))) {
            return $raw;
        }

        if (!$platformChanged && $original !== '' && $raw === $original && $fieldValues === $originalFieldValues) {
            return $original;
        }

        if ($fieldJson !== '' || $fieldValues !== $originalFieldValues || $raw === '' || $platformChanged) {
            return $fieldJson;
        }

        return $raw;
    }

    /** @param array<string, mixed> $input */
    private function apiFieldValues(StoreApiFieldRegistry $registry, string $platform, array $input): array
    {
        $values = [];
        foreach ($registry->fieldsFor($platform) as $field) {
            $value = trim((string) ($input[$field['key']] ?? ''));
            if ($value !== '') {
                $values[$field['key']] = $value;
            }
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function storeForOrderOperation(string $tenantKey, int $storeId): array
    {
        $this->requireTenantFeature($tenantKey, 'orders.platform');
        $this->auth->requireAnyTenantPermission($tenantKey, ['导入导出', '订单编辑']);
        $store = $this->store->store($tenantKey, $storeId);
        if (!$store) {
            http_response_code(404);
            echo '店铺不存在';
            exit;
        }

        if (!Permission::canAccessStore($this->auth->currentTenantUser($tenantKey), (string) (($store['name'] ?? '') ?: ($store['short'] ?? '')))) {
            $this->forbid('当前账号没有该店铺的订单导入权限。');
        }

        return $store;
    }
}
