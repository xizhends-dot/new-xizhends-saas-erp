<?php

declare(strict_types=1);

namespace Xizhen\Core;

final class StoreFactory
{
    public static function make(Config $config): StoreInterface
    {
        if ($config->effectiveDriver() === 'mysql') {
            return new MysqlStore($config);
        }

        return new JsonStore($config->jsonPath());
    }
}
