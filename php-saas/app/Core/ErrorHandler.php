<?php

declare(strict_types=1);

namespace Xizhen\Core;

use Throwable;

final class ErrorHandler
{
    private static string $logDir = '';

    public static function register(?string $logDir = null): void
    {
        self::$logDir = $logDir ?? BASE_PATH . '/storage/logs';

        set_exception_handler([self::class, 'handleException']);
        set_error_handler([self::class, 'handleError']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleException(Throwable $exception): void
    {
        self::logThrowable($exception);
        self::render500($exception);
    }

    public static function handleError(int $severity, string $message, string $file, int $line): bool
    {
        self::writeLog(sprintf(
            "[%s] %s %s\nPHP error %d: %s in %s:%d\n\n",
            date('Y-m-d H:i:s'),
            self::requestUri(),
            self::userSummary(),
            $severity,
            $message,
            $file,
            $line
        ));

        return false;
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if (!is_array($error) || !in_array((int) ($error['type'] ?? 0), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        $message = (string) ($error['message'] ?? 'Fatal error');
        $file = (string) ($error['file'] ?? 'unknown');
        $line = (int) ($error['line'] ?? 0);
        self::writeLog(sprintf(
            "[%s] %s %s\nFatal error: %s in %s:%d\n\n",
            date('Y-m-d H:i:s'),
            self::requestUri(),
            self::userSummary(),
            $message,
            $file,
            $line
        ));

        if (!headers_sent()) {
            self::render500(null);
        }
    }

    public static function render404(): void
    {
        http_response_code(404);
        echo self::htmlPage('页面不存在', '页面不存在', '请检查访问地址是否正确。');
    }

    private static function render500(?Throwable $exception): void
    {
        http_response_code(500);

        if (self::expectsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'message' => '服务器错误'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        $detail = '';
        if ((string) getenv('APP_DEBUG') === '1' && $exception !== null) {
            $detail = $exception::class . ': ' . $exception->getMessage();
        }

        echo self::htmlPage('服务器错误', '系统开小差了，请稍后重试。', $detail);
    }

    private static function logThrowable(Throwable $exception): void
    {
        self::writeLog(sprintf(
            "[%s] %s %s\n%s: %s\n%s\n\n",
            date('Y-m-d H:i:s'),
            self::requestUri(),
            self::userSummary(),
            $exception::class,
            $exception->getMessage(),
            $exception->getTraceAsString()
        ));
    }

    private static function writeLog(string $message): void
    {
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0777, true);
        }

        file_put_contents(self::$logDir . '/app-' . date('Y-m-d') . '.log', $message, FILE_APPEND | LOCK_EX);
    }

    private static function htmlPage(string $title, string $heading, string $message): string
    {
        $body = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><title>' . \e($title) . '</title><link rel="stylesheet" href="/assets/app.css"></head><body class="auth-page"><main class="login-card"><h1>' . \e($heading) . '</h1>';
        if ($message !== '') {
            $body .= '<p>' . \e($message) . '</p>';
        }
        $body .= '<p><a class="btn primary" href="javascript:history.back()">返回上一页</a></p></main></body></html>';

        return $body;
    }

    private static function expectsJson(): bool
    {
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
    }

    private static function requestUri(): string
    {
        return (string) ($_SERVER['REQUEST_URI'] ?? '/');
    }

    private static function userSummary(): string
    {
        $auth = $_SESSION['xizhen_auth'] ?? [];
        if (!is_array($auth)) {
            return 'user=guest';
        }

        $admin = $auth['admin']['username'] ?? null;
        if (is_string($admin) && $admin !== '') {
            return 'admin=' . $admin;
        }

        $tenants = $auth['tenants'] ?? [];
        if (is_array($tenants)) {
            foreach ($tenants as $tenantKey => $user) {
                if (is_array($user)) {
                    return 'tenant=' . (string) $tenantKey . ' user=' . (string) ($user['username'] ?? '');
                }
            }
        }

        return 'user=guest';
    }
}
