<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Xizhen\Services\StoreApiFieldRegistry;

$registry = new StoreApiFieldRegistry();
$failures = [];
$check = static function (string $name, mixed $actual, mixed $expected) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = sprintf('%s: expected %s, got %s', $name, var_export($expected, true), var_export($actual, true));
    }
};

$check('乐天字段数', count($registry->fieldsFor('r')), 2);
$check('雅虎购物字段数', count($registry->fieldsFor('y')), 2);
$check('雅虎拍卖字段数', count($registry->fieldsFor('yp')), 2);
$check('Wowma字段数', count($registry->fieldsFor('w')), 1);
$check('Mercari字段数', count($registry->fieldsFor('m')), 0);
$check('Qoo10字段数', count($registry->fieldsFor('q')), 0);

$rakutenJson = $registry->toJson('r', ['serviceSecret' => 's1', 'licenseKey' => 'k1']);
$rakutenDecoded = json_decode($rakutenJson, true);
$check('乐天Secret映射', $rakutenDecoded['Secret'] ?? null, 's1');
$check('乐天Key映射', $rakutenDecoded['Key'] ?? null, 'k1');
$check('乐天往返serviceSecret', $registry->fromJson('r', $rakutenJson)['serviceSecret'] ?? null, 's1');
$check('乐天往返licenseKey', $registry->fromJson('r', $rakutenJson)['licenseKey'] ?? null, 'k1');

$yahooJson = $registry->toJson('y', ['AppID' => 'app-1', 'Secret' => 'secret-1']);
$yahooDecoded = json_decode($yahooJson, true);
$check('雅虎AppID映射', $yahooDecoded['AppID'] ?? null, 'app-1');
$check('雅虎Secret映射', $yahooDecoded['Secret'] ?? null, 'secret-1');
$check('雅虎往返AppID', $registry->fromJson('yp', $yahooJson)['AppID'] ?? null, 'app-1');
$check('雅虎往返Secret', $registry->fromJson('yp', $yahooJson)['Secret'] ?? null, 'secret-1');

$wowmaJson = $registry->toJson('w', ['token' => 'token-1']);
$wowmaDecoded = json_decode($wowmaJson, true);
$check('Wowma Token映射', $wowmaDecoded['Token'] ?? null, 'token-1');
$check('Wowma往返token', $registry->fromJson('w', $wowmaJson)['token'] ?? null, 'token-1');

$legacyRakuten = $registry->fromJson('r', '{"Secret":"old-s","Key":"old-k"}');
$check('乐天旧格式Secret读取', $legacyRakuten['serviceSecret'] ?? null, 'old-s');
$check('乐天旧格式Key读取', $legacyRakuten['licenseKey'] ?? null, 'old-k');

$legacyWowma = $registry->fromJson('w', 'legacy-token');
$check('Wowma旧裸串读取', $legacyWowma['token'] ?? null, 'legacy-token');

$check('无API字段平台输出空JSON', $registry->toJson('m', ['x' => 'y']), '');

if ($failures !== []) {
    echo "StoreApiFieldRegistry test FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}

echo "StoreApiFieldRegistry test OK\n";
