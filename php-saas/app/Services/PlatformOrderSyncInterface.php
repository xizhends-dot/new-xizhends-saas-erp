<?php

declare(strict_types=1);

namespace Xizhen\Services;

interface PlatformOrderSyncInterface
{
    public function platformCode(): string;

    public function platformName(): string;

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, searched: int, inserted: int, updated: int, skipped: int, items_inserted: int, items_updated: int}
     */
    public function sync(string $tenantKey, int $storeId, int $days, string $operator, array $options = []): array;
}
