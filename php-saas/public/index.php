<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/app/Core/helpers.php';

start_xizhen_session();

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

$routes = require BASE_PATH . '/app/Http/routes.php';
$routes($router, $admin, $tenant);

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'
);
