<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/helpers.php';

$_SESSION = [];

$first = csrf_token();
$second = csrf_token();
assert_same($first, $second, 'csrf token is stable within one session');
assert_same(64, strlen($first), 'csrf token is 32 random bytes encoded as hex');

$_SESSION['_csrf'] = '"><script>alert(1)</script>';
$field = csrf_field();
assert_same(
    '<input type="hidden" name="_token" value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">',
    $field,
    'csrf field escapes token'
);

$_SESSION['_csrf'] = 'known-token';
assert_same(true, csrf_token_matches('known-token'), 'correct csrf token passes');
assert_same(false, csrf_token_matches('wrong-token'), 'wrong csrf token fails');
assert_same(false, csrf_token_matches(''), 'empty csrf token fails');
assert_same(false, csrf_token_matches(null), 'null csrf token fails');

unset($_SESSION['_csrf']);
assert_same(false, csrf_token_matches('known-token'), 'missing session csrf token fails');

echo "CSRF test passed.\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}
