<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$css = file_get_contents($basePath . '/public/assets/app.css') ?: '';

$failures = [];
$assert = static function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};

$rules = [
    '.table th' => 400,
    '.otable thead th' => 400,
    '.order-page .otable thead th' => 400,
    '.log-table th' => 400,
];

foreach ($rules as $selector => $weight) {
    $pattern = '/' . preg_quote($selector, '/') . '\s*\{[^}]*font-weight:\s*' . $weight . '\s*;/s';
    $assert($selector . ' 表头不使用粗体', preg_match($pattern, $css) === 1);
}

if ($failures !== []) {
    fwrite(STDERR, "Table header weight test FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "Table header weight test OK\n";
