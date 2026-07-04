<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Core/helpers.php';

use Xizhen\Core\Container;
use Xizhen\Core\Router;
use Xizhen\Core\Middleware\MiddlewareInterface;

final class RouterPipelineRecorder
{
    /** @var array<int, string> */
    public static array $events = [];

    public static function record(string $event): null
    {
        self::$events[] = $event;
        return null;
    }
}

final class RouterPipelineGlobalMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): mixed
    {
        RouterPipelineRecorder::record('global-before');
        $result = $next();
        RouterPipelineRecorder::record('global-after');
        return $result;
    }
}

final class RouterPipelineGroupMiddleware implements MiddlewareInterface
{
    public function handle(callable $next): mixed
    {
        RouterPipelineRecorder::record('group-before');
        $result = $next();
        RouterPipelineRecorder::record('group-after');
        return $result;
    }
}

final class RouterPipelineController
{
    public function show(): void
    {
        RouterPipelineRecorder::record('show:' . ($_GET['id'] ?? ''));
    }
}

$_GET = [];
RouterPipelineRecorder::$events = [];
$container = new Container();
$container->bind(RouterPipelineGlobalMiddleware::class, static fn (): RouterPipelineGlobalMiddleware => new RouterPipelineGlobalMiddleware());
$container->bind(RouterPipelineGroupMiddleware::class, static fn (): RouterPipelineGroupMiddleware => new RouterPipelineGroupMiddleware());
$container->bind(RouterPipelineController::class, static fn (): RouterPipelineController => new RouterPipelineController());

$router = new Router($container);
$router->middleware([RouterPipelineGlobalMiddleware::class]);
$router->get('/items/static', static fn (): null => RouterPipelineRecorder::record('exact'));
$router->get('/items/{id}', [RouterPipelineController::class, 'show']);
$router->group([RouterPipelineGroupMiddleware::class], static function (Router $router): void {
    $router->get('/grouped', static fn (): null => RouterPipelineRecorder::record('grouped-handler'));
});
$router->get('/plain', static fn (): null => RouterPipelineRecorder::record('plain-handler'));

ob_start();
$router->dispatch('GET', '/items/static');
ob_end_clean();
assert_same(['global-before', 'exact', 'global-after'], RouterPipelineRecorder::$events, 'exact match has priority');

RouterPipelineRecorder::$events = [];
$_GET = [];
ob_start();
$router->dispatch('GET', '/items/42');
ob_end_clean();
assert_same('42', $_GET['id'] ?? null, 'parameter route injects GET value');
assert_same(['global-before', 'show:42', 'global-after'], RouterPipelineRecorder::$events, 'parameter route calls controller');

RouterPipelineRecorder::$events = [];
ob_start();
$router->dispatch('GET', '/grouped');
ob_end_clean();
assert_same(['global-before', 'group-before', 'grouped-handler', 'group-after', 'global-after'], RouterPipelineRecorder::$events, 'middleware order is global then group then handler');

RouterPipelineRecorder::$events = [];
ob_start();
$router->dispatch('GET', '/plain');
ob_end_clean();
assert_same(['global-before', 'plain-handler', 'global-after'], RouterPipelineRecorder::$events, 'group middleware is isolated');

RouterPipelineRecorder::$events = [];
ob_start();
$router->dispatch('GET', '/missing');
$body = ob_get_clean();
assert_same(404, http_response_code(), 'missing route returns 404');
assert_same(true, str_contains($body, '页面不存在'), 'missing route renders friendly 404');

echo "Router pipeline test passed.\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
