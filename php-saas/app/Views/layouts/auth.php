<!doctype html>
<?php $assetVersion = static fn (string $path): string => (string) @filemtime(BASE_PATH . '/public' . $path); ?>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '登录') ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=<?= e($assetVersion('/assets/app.css')) ?>">
</head>
<body class="auth-shell">
    <?= $content ?>
</body>
</html>
