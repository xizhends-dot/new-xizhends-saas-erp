<?php

declare(strict_types=1);

// 方法完整性测试：扫描全项目类内 $this->method() 直接调用，
// 断言目标方法都有定义（含父类/trait 继承）。
// 背景：MysqlStore 移植 JsonStore 公告逻辑时漏抄 nextId() 辅助方法，
// MySQL 模式下新建公告即 500——本测试防止此类"漏抄辅助方法"地雷再次出现。

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

$targets = array_merge(
    glob(BASE_PATH . '/app/Repositories/*.php') ?: [],
    glob(BASE_PATH . '/app/Core/*.php') ?: [],
    glob(BASE_PATH . '/app/Core/Middleware/*.php') ?: [],
    glob(BASE_PATH . '/app/Services/*.php') ?: [],
    glob(BASE_PATH . '/app/Services/Concerns/*.php') ?: [],
    glob(BASE_PATH . '/app/Http/Controllers/*/*.php') ?: []
);

$errors = [];
$scanned = 0;
foreach ($targets as $file) {
    $code = (string) file_get_contents($file);
    if (!preg_match('/namespace\s+([^;]+);/', $code, $ns)) {
        continue;
    }
    if (!preg_match('/(?:class|trait)\s+(\w+)/', $code, $cl)) {
        continue;
    }
    $fqcn = trim($ns[1]) . '\\' . $cl[1];
    if (!class_exists($fqcn) && !trait_exists($fqcn)) {
        continue;
    }
    $scanned++;
    preg_match_all('/\$this->(\w+)\s*\(/', $code, $calls);
    foreach (array_unique($calls[1]) as $method) {
        if (!method_exists($fqcn, $method)) {
            $errors[] = "{$fqcn}::{$method}() 被调用但未定义";
        }
    }
}

if ($scanned < 50) {
    fwrite(STDERR, "扫描类数量异常（{$scanned} < 50），检查 glob 路径。\n");
    exit(1);
}

if ($errors) {
    fwrite(STDERR, "发现未定义方法调用：\n" . implode("\n", $errors) . "\n");
    exit(1);
}

echo "Method integrity test passed ({$scanned} classes scanned).\n";
