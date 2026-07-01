<?php

declare(strict_types=1);

return [
    'data_driver' => getenv('DATA_DRIVER') ?: 'json',
    'json_path' => BASE_PATH . '/storage/data/app.json',
    'mysql' => [
        'dsn' => getenv('MYSQL_DSN') ?: '',
        'user' => getenv('MYSQL_USER') ?: '',
        'password' => getenv('MYSQL_PASSWORD') ?: '',
        'tenant_dsn' => [
            'erp' => getenv('MYSQL_TENANT_DSN_ERP') ?: '',
            'tokyo' => getenv('MYSQL_TENANT_DSN_TOKYO') ?: '',
            'demo' => getenv('MYSQL_TENANT_DSN_DEMO') ?: '',
        ],
    ],
];
