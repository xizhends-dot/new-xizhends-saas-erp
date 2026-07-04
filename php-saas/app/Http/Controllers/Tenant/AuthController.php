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

final class AuthController extends TenantBaseController
{

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

    public function passwordEdit(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'account.password_edit');
        $this->auth->requireTenant($tenantKey);
        $this->renderTenant('tenant/password_edit', $tenantKey, [
            'title' => '修改密码',
            'active' => 'password_edit',
            'policy' => $this->tenantUserSecurityService->passwordPolicy(),
            'message' => (string) ($_GET['message'] ?? ''),
            'errors' => ($_GET['error'] ?? '') !== '' ? ['form' => (string) $_GET['error']] : [],
        ]);
    }

    public function passwordUpdate(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'account.password_edit');
        $this->auth->requireTenant($tenantKey);
        $currentUser = $this->auth->currentTenantUser($tenantKey);
        $result = $this->tenantUserSecurityService->changePassword(
            $tenantKey,
            (int) ($currentUser['id'] ?? 0),
            (string) ($_POST['old_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        $key = $result['ok'] ? 'message' : 'error';
        redirect_to('/password/edit?tenant=' . rawurlencode($tenantKey) . '&' . $key . '=' . rawurlencode($result['message']));
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
}
