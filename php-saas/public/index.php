<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/Core/helpers.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'Xizhen\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = BASE_PATH . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

use Xizhen\Controllers\AdminController;
use Xizhen\Controllers\TenantController;
use Xizhen\Core\Config;
use Xizhen\Core\Router;
use Xizhen\Core\StoreFactory;
use Xizhen\Core\View;
use Xizhen\Services\AuthService;

$config = Config::load(BASE_PATH);
$store = StoreFactory::make($config);
$view = new View(BASE_PATH . '/app/Views');
$auth = new AuthService($store);

$router = new Router();
$admin = new AdminController($store, $view, $config, $auth);
$tenant = new TenantController($store, $view, $auth);

$router->get('/admin/login', [$admin, 'loginForm']);
$router->post('/admin/login', [$admin, 'login']);
$router->post('/admin/logout', [$admin, 'logout']);
$router->get('/admin', [$admin, 'overview']);
$router->get('/admin/tenants', [$admin, 'tenants']);
$router->get('/admin/billing', [$admin, 'billing']);
$router->post('/admin/billing/adjust', [$admin, 'adjustBilling']);
$router->post('/admin/billing/process', [$admin, 'processBilling']);
$router->get('/admin/platforms', [$admin, 'platforms']);
$router->post('/admin/platforms/toggle', [$admin, 'togglePlatform']);
$router->post('/admin/features/toggle', [$admin, 'toggleFeature']);
$router->get('/admin/announcements', [$admin, 'announcements']);
$router->get('/admin/settings', [$admin, 'settings']);
$router->post('/admin/settings/save', [$admin, 'saveSettings']);
$router->get('/admin/system', [$admin, 'systemStatus']);

$router->get('/login', [$tenant, 'loginForm']);
$router->post('/login', [$tenant, 'login']);
$router->post('/logout', [$tenant, 'logout']);
$router->get('/', [$tenant, 'dashboard']);
$router->get('/orders', [$tenant, 'orders']);
$router->get('/orders/detail', [$tenant, 'orderDetail']);
$router->post('/orders/source', [$tenant, 'changeSource']);
$router->post('/orders/batch', [$tenant, 'batchOrders']);
$router->post('/orders/export', [$tenant, 'exportOrders']);
$router->post('/orders/send-jp', [$tenant, 'sendJapan']);
$router->post('/orders/xizhen-delivery/export', [$tenant, 'exportXizhenDelivery']);
$router->post('/orders/logistics/update', [$tenant, 'updateLogistics']);
$router->post('/orders/platform/sync', [$tenant, 'syncPlatformOrders']);
$router->post('/orders/rakuten/sync', [$tenant, 'syncRakutenOrders']);
$router->post('/orders/item/save', [$tenant, 'saveOrderItem']);
$router->post('/orders/attachments/add', [$tenant, 'addOrderAttachment']);
$router->post('/orders/attachments/delete', [$tenant, 'deleteOrderAttachment']);
$router->post('/orders/images/upload', [$tenant, 'uploadOrderImage']);
$router->get('/features', [$tenant, 'features']);
$router->get('/search', [$tenant, 'search']);
$router->get('/analytics/profit', [$tenant, 'profit']);
$router->get('/stats/purchase', [$tenant, 'purchaseStats']);
$router->get('/logistics/1688', [$tenant, 'logistics1688']);
$router->get('/logistics/jp', [$tenant, 'logisticsJp']);
$router->get('/mail', [$tenant, 'mail']);
$router->get('/mail/settings', [$tenant, 'mailSettings']);
$router->get('/mail/rules', [$tenant, 'mailRules']);
$router->post('/mail/accounts/save', [$tenant, 'saveMailAccount']);
$router->post('/mail/accounts/delete', [$tenant, 'deleteMailAccount']);
$router->post('/mail/folders/probe', [$tenant, 'probeMailFolders']);
$router->post('/mail/folders/save', [$tenant, 'saveMailFolder']);
$router->post('/mail/sync', [$tenant, 'syncMail']);
$router->post('/mail/action', [$tenant, 'mailAction']);
$router->post('/mail/move', [$tenant, 'moveMail']);
$router->post('/mail/rules/save', [$tenant, 'saveMailRule']);
$router->post('/mail/rules/delete', [$tenant, 'deleteMailRule']);
$router->post('/mail/rules/apply', [$tenant, 'applyMailRules']);
$router->post('/mail/reply', [$tenant, 'replyMail']);
$router->get('/import-export', [$tenant, 'importExport']);
$router->get('/import-export/export', [$tenant, 'exportCsv']);
$router->post('/import-export/import', [$tenant, 'importCsv']);
$router->get('/media', [$tenant, 'media']);
$router->get('/jobs', [$tenant, 'jobs']);
$router->get('/logs', [$tenant, 'logs']);
$router->get('/settings', [$tenant, 'settings']);
$router->post('/settings/save', [$tenant, 'saveSettings']);
$router->get('/stores', [$tenant, 'stores']);
$router->post('/stores/add', [$tenant, 'addStore']);
$router->get('/stores/edit', [$tenant, 'editStore']);
$router->post('/stores/update', [$tenant, 'updateStore']);
$router->get('/users', [$tenant, 'users']);
$router->post('/users/add', [$tenant, 'addUser']);
$router->get('/users/edit', [$tenant, 'editUser']);
$router->post('/users/update', [$tenant, 'updateUser']);
$router->get('/assignments', [$tenant, 'assignments']);
$router->post('/assignments/save', [$tenant, 'saveAssignment']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
