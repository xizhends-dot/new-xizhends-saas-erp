<?php

declare(strict_types=1);

namespace Xizhen\Services;

interface CronTaskInterface
{
    public function key(): string;

    public function name(): string;

    public function description(): string;

    public function schedule(): string;

    public function oldSource(): string;

    /**
     * @param array<string, mixed> $options
     * @return array{
     *     ok: bool,
     *     message: string,
     *     scanned: int,
     *     updated: int,
     *     skipped: int,
     *     failed: int,
     *     tenants: array<int, string>
     * }
     */
    public function run(?string $tenantKey, array $options = []): array;
}
