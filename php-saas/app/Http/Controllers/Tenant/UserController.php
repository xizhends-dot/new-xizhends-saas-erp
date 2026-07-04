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

final class UserController extends TenantBaseController
{

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
            'permissions' => $_POST['permissions'] ?? [],
            'stores' => $_POST['stores'] ?? [],
            'status' => $_POST['status'] ?? 'active',
        ]);

        redirect_to('/users?tenant=' . rawurlencode($tenantKey));
    }

    public function userPermissions(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.user_permission_overrides');
        $this->auth->requireTenantPermission($tenantKey, '权限覆盖');
        $userId = (int) ($_GET['id'] ?? 0);
        $matrix = $userId > 0 ? $this->userPermissionOverrideService->matrixForUser($tenantKey, $userId) : ['ok' => false, 'message' => '请选择员工。'];
        $this->renderTenant('tenant/user_permissions', $tenantKey, [
            'title' => '细粒度权限',
            'active' => 'users',
            'users' => $this->store->users($tenantKey),
            'selectedUserId' => $userId,
            'user' => $matrix['user'] ?? [],
            'groups' => $matrix['groups'] ?? [],
            'message' => (string) ($_GET['message'] ?? ($matrix['message'] ?? '')),
        ]);
    }

    public function saveUserPermissions(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.user_permission_overrides');
        $this->auth->requireTenantPermission($tenantKey, '权限覆盖');
        $userId = (int) ($_POST['user_id'] ?? 0);
        $states = is_array($_POST['states'] ?? null) ? $_POST['states'] : [];
        $overrides = $this->userPermissionOverrideService->normalizeSubmittedStates($states);
        $this->store->updateUserPermissionOverrides($tenantKey, $userId, $overrides, $this->currentUserName($tenantKey));
        redirect_to('/users/permissions?tenant=' . rawurlencode($tenantKey) . '&id=' . $userId . '&message=' . rawurlencode('权限覆盖已保存。'));
    }

    public function customerServiceDeductions(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.customer_service_deductions');
        $this->auth->requireTenantPermission($tenantKey, '客服扣点');
        $this->renderTenant('tenant/customer_service_deductions', $tenantKey, [
            'title' => '客服扣点',
            'active' => 'users',
            'rows' => $this->customerServiceDeductionService->rows($tenantKey),
            'summary' => $this->customerServiceDeductionService->summary($tenantKey),
            'message' => (string) ($_GET['message'] ?? ''),
            'errors' => ($_GET['error'] ?? '') !== '' ? ['form' => (string) $_GET['error']] : [],
        ]);
    }

    public function saveCustomerServiceDeductions(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'management.customer_service_deductions');
        $this->auth->requireTenantPermission($tenantKey, '客服扣点');
        $deductions = is_array($_POST['deductions'] ?? null) ? $_POST['deductions'] : [];
        $result = $this->customerServiceDeductionService->saveToTenantSettings($tenantKey, $deductions, $this->auth->currentTenantUser($tenantKey) ?? []);
        $key = $result['ok'] ? 'message' : 'error';
        redirect_to('/users/customer-service-deductions?tenant=' . rawurlencode($tenantKey) . '&' . $key . '=' . rawurlencode($result['message']));
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
            'buyers' => array_values(array_filter($users, fn (array $user): bool => Permission::normalizeRole($user['role'] ?? '') === '采购')),
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
}
