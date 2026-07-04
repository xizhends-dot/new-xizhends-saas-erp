<?php

declare(strict_types=1);

namespace Xizhen\Core;

use Xizhen\Core\Middleware\MiddlewareInterface;
use RuntimeException;

final class Router
{
    /** @var array<string, array<int, array{path: string, pattern: string, parameters: array<int, string>, handler: mixed, middleware: array<int, string>}>> */
    private array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    /** @var array<int, string> */
    private array $globalMiddleware = [];

    /** @var array<int, string> */
    private array $groupMiddleware = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function middleware(array $middleware): void
    {
        $this->globalMiddleware = array_values($middleware);
    }

    public function get(string $path, mixed $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, mixed $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function group(array $middleware, callable $register): void
    {
        $previous = $this->groupMiddleware;
        $this->groupMiddleware = array_merge($this->groupMiddleware, array_values($middleware));
        $register($this);
        $this->groupMiddleware = $previous;
    }

    public function dispatch(string $method, string $path): void
    {
        $method = strtoupper($method);
        $path = $this->normalize($path);
        $route = $this->match($method, $path);

        if ($route === null) {
            ErrorHandler::render404();
            return;
        }

        $middleware = array_map(
            fn (string $class): MiddlewareInterface => $this->container->make($class),
            array_merge($this->globalMiddleware, $route['middleware'])
        );

        (new Pipeline($middleware))->run(fn (): mixed => $this->call($route['handler']));
    }

    private function add(string $method, string $path, mixed $handler): void
    {
        $path = $this->normalize($path);
        [$pattern, $parameters] = $this->compile($path);

        $this->routes[$method][] = [
            'path' => $path,
            'pattern' => $pattern,
            'parameters' => $parameters,
            'handler' => $handler,
            'middleware' => $this->groupMiddleware,
        ];
    }

    /** @return array{path: string, pattern: string, parameters: array<int, string>, handler: mixed, middleware: array<int, string>}|null */
    private function match(string $method, string $path): ?array
    {
        foreach ($this->routes[$method] ?? [] as $route) {
            if ($route['path'] === $path) {
                return $route;
            }
        }

        foreach ($this->routes[$method] ?? [] as $route) {
            if ($route['parameters'] === []) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }

            foreach ($route['parameters'] as $parameter) {
                $_GET[$parameter] = $matches[$parameter] ?? '';
            }

            return $route;
        }

        return null;
    }

    private function call(mixed $handler): mixed
    {
        if (is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null)) {
            $controller = $this->container->make($handler[0]);
            return $controller->{$handler[1]}();
        }

        if (is_callable($handler)) {
            return $handler();
        }

        throw new RuntimeException('Invalid route handler.');
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function compile(string $path): array
    {
        $parameters = [];
        $segments = explode('/', trim($path, '/'));
        $compiled = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $segment, $matches) === 1) {
                $parameters[] = $matches[1];
                $compiled[] = '(?P<' . $matches[1] . '>[^/]+)';
                continue;
            }

            $compiled[] = preg_quote($segment, '#');
        }

        $pattern = $path === '/' ? '/' : '/' . implode('/', $compiled);

        return ['#^' . $pattern . '$#', $parameters];
    }

    private function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '//' ? '/' : $path;
    }
}
