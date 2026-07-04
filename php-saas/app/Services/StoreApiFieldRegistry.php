<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class StoreApiFieldRegistry
{
    /** @return array<string, array<int, array{key:string,label:string,hint:string,jsonKey:string}>> */
    public function all(): array
    {
        return [
            'r' => [
                ['key' => 'serviceSecret', 'label' => 'serviceSecret(乐天接口密钥)', 'hint' => '乐天接口密钥。', 'jsonKey' => 'Secret'],
                ['key' => 'licenseKey', 'label' => 'licenseKey(乐天接口授权码)', 'hint' => '乐天接口授权码。', 'jsonKey' => 'Key'],
            ],
            'y' => [
                ['key' => 'AppID', 'label' => 'AppID(ClientID)', 'hint' => '雅虎接口 AppID / ClientID。', 'jsonKey' => 'AppID'],
                ['key' => 'Secret', 'label' => 'Secret(密钥)', 'hint' => '雅虎接口密钥。', 'jsonKey' => 'Secret'],
            ],
            'yp' => [
                ['key' => 'AppID', 'label' => 'AppID(ClientID)', 'hint' => '雅虎接口 AppID / ClientID。', 'jsonKey' => 'AppID'],
                ['key' => 'Secret', 'label' => 'Secret(密钥)', 'hint' => '雅虎接口密钥。', 'jsonKey' => 'Secret'],
            ],
            'w' => [
                ['key' => 'token', 'label' => 'token(店铺接口令牌码)', 'hint' => '店铺接口令牌码。', 'jsonKey' => 'Token'],
            ],
            'm' => [],
            'q' => [],
        ];
    }

    /** @return array<int, array{key:string,label:string,hint:string,jsonKey:string}> */
    public function fieldsFor(string $platform): array
    {
        $platform = $this->normalizePlatform($platform);
        return $this->all()[$platform] ?? [];
    }

    /** @param array<string, mixed> $input */
    public function toJson(string $platform, array $input): string
    {
        $payload = [];
        foreach ($this->fieldsFor($platform) as $field) {
            $value = $this->stringValue($input[$field['key']] ?? null);
            if ($value === '') {
                continue;
            }

            $payload[$field['jsonKey']] = $value;
        }

        return $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, string> */
    public function fromJson(string $platform, string $json): array
    {
        $platform = $this->normalizePlatform($platform);
        $raw = trim($json);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $platform === 'w' ? ['token' => $raw] : [];
        }

        $values = [];
        foreach ($this->fieldsFor($platform) as $field) {
            $value = $this->stringValue($decoded[$field['jsonKey']] ?? null);
            if ($value === '') {
                $value = $this->stringValue($decoded[$field['key']] ?? null);
            }
            foreach ($this->aliasesFor($field['key']) as $alias) {
                if ($value !== '') {
                    break;
                }
                $value = $this->stringValue($decoded[$alias] ?? null);
            }
            if ($value !== '') {
                $values[$field['key']] = $value;
            }
        }

        return $values;
    }

    /** @return array<string> */
    private function aliasesFor(string $key): array
    {
        return match ($key) {
            'serviceSecret' => ['service_secret', 'Secret'],
            'licenseKey' => ['license_key', 'Key'],
            'token' => ['Token'],
            default => [],
        };
    }

    private function normalizePlatform(string $platform): string
    {
        return strtolower(trim($platform));
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
