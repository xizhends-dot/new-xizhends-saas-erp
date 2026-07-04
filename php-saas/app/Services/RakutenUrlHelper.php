<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class RakutenUrlHelper
{
    /** @param array<string, mixed> $store */
    public static function rakutenItemUrl(array $store, string $itemId): string
    {
        $dpid = self::rakutenDpid($store);
        $itemId = trim($itemId);
        if ($dpid === '' || $itemId === '') {
            return '';
        }

        return 'https://item.rakuten.co.jp/' . rawurlencode($dpid) . '/' . rawurlencode(strtolower($itemId)) . '/';
    }

    /** @param array<string, mixed> $store */
    public static function rakutenMainImageUrl(array $store, string $itemId): string
    {
        $dpid = self::rakutenDpid($store);
        $itemId = trim($itemId);
        if ($dpid === '' || $itemId === '') {
            return '';
        }

        return 'https://image.rakuten.co.jp/' . rawurlencode($dpid) . '/cabinet/main/' . rawurlencode(strtolower($itemId)) . '.jpg';
    }

    /** @param array<string, mixed> $store */
    public static function rakutenDpid(array $store): string
    {
        $dpid = trim((string) ($store['legacy_dpid'] ?? ''));
        if ($dpid === '') {
            $config = json_decode((string) ($store['api_config'] ?? ''), true);
            if (is_array($config)) {
                foreach (['dpid', 'shopId', 'shop_id', 'shopCode', 'shop_code'] as $key) {
                    if (trim((string) ($config[$key] ?? '')) !== '') {
                        $dpid = trim((string) $config[$key]);
                        break;
                    }
                }
            }
        }

        return (string) (preg_replace('/[^a-zA-Z0-9_-]/', '', $dpid) ?? '');
    }
}

