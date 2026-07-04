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

final class MailController extends TenantBaseController
{

    public function mail(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $filters = $this->mailFiltersFrom($_GET);
        $mail = $this->mailPageData($tenantKey, $filters, (int) ($_GET['page'] ?? 1), (int) ($_GET['message_id'] ?? 0));
        $this->renderTenant('tenant/mail', $tenantKey, [
            'title' => '邮件汇总',
            'active' => 'mail',
            'mail' => $mail,
            'filters' => $filters,
        ]);
    }

    public function mailSettings(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $filters = $this->mailFiltersFrom($_GET);
        $mail = $this->mailPageData($tenantKey, $filters, 1, 0, false);
        $this->renderTenant('tenant/mail_settings', $tenantKey, [
            'title' => '邮箱设置',
            'active' => 'mail',
            'mail' => $mail,
            'filters' => $filters,
        ]);
    }

    public function mailRules(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $filters = $this->mailFiltersFrom($_GET);
        $mail = $this->mailPageData($tenantKey, $filters, 1, 0, false);
        $this->renderTenant('tenant/mail_rules', $tenantKey, [
            'title' => '过滤规则',
            'active' => 'mail',
            'mail' => $mail,
            'filters' => $filters,
        ]);
    }

    public function saveMailAccount(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        try {
            $id = $this->mailService->saveAccount($tenantKey, $_POST);
            redirect_to('/mail/settings?tenant=' . rawurlencode($tenantKey) . '&account_id=' . $id . '&message=' . rawurlencode('邮箱账号已保存'));
        } catch (RuntimeException $exception) {
            redirect_to('/mail/settings?tenant=' . rawurlencode($tenantKey) . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function deleteMailAccount(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        try {
            $this->mailService->deleteAccount($tenantKey, (int) ($_POST['account_id'] ?? 0));
            redirect_to('/mail/settings?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode('邮箱账号已删除'));
        } catch (RuntimeException $exception) {
            redirect_to('/mail/settings?tenant=' . rawurlencode($tenantKey) . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function probeMailFolders(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $accountId = (int) ($_POST['account_id'] ?? 0);
        try {
            $result = $this->mailService->probeFolders($tenantKey, $accountId);
        } catch (RuntimeException $exception) {
            $result = ['ok' => false, 'message' => $exception->getMessage()];
        }
        $key = $result['ok'] ? 'message' : 'error';
        redirect_to('/mail/settings?tenant=' . rawurlencode($tenantKey) . '&account_id=' . $accountId . '&' . $key . '=' . rawurlencode($result['message']));
    }

    public function saveMailFolder(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $return = (string) ($_POST['return'] ?? '/mail/settings?tenant=' . rawurlencode($tenantKey));
        try {
            $this->mailService->saveFolder($tenantKey, $_POST);
            redirect_to($return);
        } catch (RuntimeException $exception) {
            redirect_to($this->urlWithNotice($return, 'error', $exception->getMessage()));
        }
    }

    public function syncMail(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $return = trim((string) ($_POST['return'] ?? ''));
        if ($return === '') {
            $return = ((int) ($_POST['account_id'] ?? 0) > 0 || (int) ($_POST['folder_id'] ?? 0) > 0)
                ? '/mail/settings?tenant=' . rawurlencode($tenantKey)
                : '/mail?tenant=' . rawurlencode($tenantKey);
        }
        try {
            $result = $this->mailService->sync($tenantKey, (int) ($_POST['account_id'] ?? 0), (int) ($_POST['folder_id'] ?? 0), (int) ($_POST['limit'] ?? 200));
        } catch (RuntimeException $exception) {
            $result = ['ok' => false, 'message' => $exception->getMessage()];
        }
        $key = $result['ok'] ? 'message' : 'error';
        redirect_to($this->urlWithNotice($return, $key, (string) $result['message']));
    }

    public function mailAction(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $return = (string) ($_POST['return'] ?? '/mail?tenant=' . rawurlencode($tenantKey));
        try {
            $count = $this->mailService->mark($tenantKey, $this->intList($_POST['message_ids'] ?? []), (string) ($_POST['action'] ?? ''));
            redirect_to($this->urlWithNotice($return, 'message', "已处理 {$count} 封邮件"));
        } catch (RuntimeException $exception) {
            redirect_to($this->urlWithNotice($return, 'error', $exception->getMessage()));
        }
    }

    public function moveMail(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        try {
            $result = $this->mailService->move($tenantKey, $this->intList($_POST['message_ids'] ?? []), (int) ($_POST['target_folder_id'] ?? 0));
        } catch (RuntimeException $exception) {
            $result = ['ok' => false, 'message' => $exception->getMessage()];
        }
        $key = $result['ok'] ? 'message' : 'error';
        redirect_to($this->urlWithNotice((string) ($_POST['return'] ?? '/mail?tenant=' . rawurlencode($tenantKey)), $key, (string) $result['message']));
    }

    public function saveMailRule(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        try {
            $this->mailService->saveRule($tenantKey, $_POST);
            redirect_to('/mail/rules?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode('规则已保存'));
        } catch (RuntimeException $exception) {
            redirect_to('/mail/rules?tenant=' . rawurlencode($tenantKey) . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function deleteMailRule(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        try {
            $this->mailService->deleteRule($tenantKey, (int) ($_POST['rule_id'] ?? 0));
            redirect_to('/mail/rules?tenant=' . rawurlencode($tenantKey) . '&message=' . rawurlencode('规则已删除'));
        } catch (RuntimeException $exception) {
            redirect_to('/mail/rules?tenant=' . rawurlencode($tenantKey) . '&error=' . rawurlencode($exception->getMessage()));
        }
    }

    public function applyMailRules(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        $return = trim((string) ($_POST['return'] ?? ''));
        if ($return === '') {
            $return = '/mail/rules?tenant=' . rawurlencode($tenantKey);
        }
        try {
            $result = $this->mailService->applyRules($tenantKey, (int) ($_POST['account_id'] ?? 0), (int) ($_POST['folder_id'] ?? 0));
            redirect_to($this->urlWithNotice($return, 'message', "规则执行完成：命中 {$result['matched']} 封，移动 {$result['moved']} 封"));
        } catch (RuntimeException $exception) {
            redirect_to($this->urlWithNotice($return, 'error', $exception->getMessage()));
        }
    }

    public function replyMail(): void
    {
        $tenantKey = current_tenant_key();
        $this->requireTenantFeature($tenantKey, 'mail.center');
        $this->auth->requireTenantPermission($tenantKey, '邮件中心');
        try {
            $result = $this->mailService->reply($tenantKey, $_POST, $this->currentUserName($tenantKey));
        } catch (RuntimeException $exception) {
            $result = ['ok' => false, 'message' => $exception->getMessage()];
        }
        $key = $result['ok'] ? 'message' : 'error';
        $messageId = (int) ($_POST['message_id'] ?? 0);
        redirect_to('/mail?tenant=' . rawurlencode($tenantKey) . '&message_id=' . $messageId . '&' . $key . '=' . rawurlencode((string) $result['message']));
    }

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

    private function mailPageData(string $tenantKey, array $filters, int $page, int $selectedId = 0, bool $loadMessages = true): array
    {
        try {
            return $this->mailService->pageData($tenantKey, $filters, $page, $selectedId, $loadMessages);
        } catch (RuntimeException $exception) {
            return [
                'accounts' => [],
                'folders' => [],
                'folderTree' => [],
                'counts' => ['unread_map' => [], 'total_map' => [], 'total_unread' => 0, 'total_all' => 0],
                'messages' => ['rows' => [], 'total' => 0, 'page' => 1, 'page_size' => 30, 'total_pages' => 1],
                'selected' => null,
                'body' => null,
                'rules' => [],
                'imapAvailable' => function_exists('imap_open'),
                'message' => trim((string) ($_GET['message'] ?? '')),
                'error' => trim((string) ($_GET['error'] ?? $exception->getMessage())),
            ];
        }
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
}
