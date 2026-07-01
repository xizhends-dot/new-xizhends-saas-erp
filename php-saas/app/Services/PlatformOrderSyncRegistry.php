<?php

declare(strict_types=1);

namespace Xizhen\Services;

use Xizhen\Core\StoreInterface;

final class PlatformOrderSyncRegistry
{
    /** @var array<string, PlatformOrderSyncInterface> */
    private array $services = [];

    public function __construct(StoreInterface $store)
    {
        foreach ($this->serviceClasses() as $class) {
            if (class_exists($class)) {
                $this->register(new $class($store));
            }
        }
    }

    public function get(string $platform): ?PlatformOrderSyncInterface
    {
        return $this->services[$platform] ?? null;
    }

    /** @return array<string, string> */
    public function names(): array
    {
        $names = [];
        foreach ($this->services as $code => $service) {
            $names[$code] = $service->platformName();
        }

        return $names;
    }

    private function register(PlatformOrderSyncInterface $service): void
    {
        $this->services[$service->platformCode()] = $service;
    }

    /** @return array<int, class-string> */
    private function serviceClasses(): array
    {
        return [
            RakutenOrderService::class,
            WowmaOrderSyncService::class,
            YahooShopOrderSyncService::class,
            MercariOrderSyncService::class,
            Qoo10OrderSyncService::class,
            YahooAuctionOrderSyncService::class,
        ];
    }
}
