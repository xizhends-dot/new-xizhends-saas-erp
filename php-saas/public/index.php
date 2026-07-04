<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/app/Core/helpers.php';

use Xizhen\Core\Config;
use Xizhen\Core\Container;
use Xizhen\Core\ErrorHandler;
use Xizhen\Core\Router;
use Xizhen\Core\StoreFactory;
use Xizhen\Core\StoreInterface;
use Xizhen\Core\View;
use Xizhen\Services\AuthService;

start_xizhen_session();
ErrorHandler::register();

$container = new Container();
$container->singleton(Config::class, static fn (): Config => Config::load(BASE_PATH));
$container->singleton(StoreInterface::class, static fn (Container $container): StoreInterface => StoreFactory::make($container->make(Config::class)));
$container->singleton(View::class, static fn (): View => new View(BASE_PATH . '/app/Views'));
$container->singleton(AuthService::class, static fn (Container $container): AuthService => new AuthService($container->make(StoreInterface::class)));

$router = new Router($container);

$routes = require BASE_PATH . '/app/Http/routes.php';
$routes($container, $router);

$router->dispatch(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'
);
