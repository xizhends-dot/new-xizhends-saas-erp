<?php

declare(strict_types=1);

require __DIR__ . '/../app/Core/StoreInterface.php';
require __DIR__ . '/../app/Services/RakutenUrlHelper.php';
require __DIR__ . '/../app/Services/ProductImageDownloadService.php';

use Xizhen\Services\ProductImageDownloadService;
use Xizhen\Services\RakutenUrlHelper;

$service = (new ReflectionClass(ProductImageDownloadService::class))->newInstanceWithoutConstructor();

assert_same(
    'https://item-shopping.c.yimg.jp/i/l/shop-1_abc123',
    $service->yahooShopImageUrl(['legacy_dpid' => 'shop-1'], 'ABC123'),
    'Yahoo Shopping image URL'
);

assert_same(
    'https://item.rakuten.co.jp/shop-2/sku-9/',
    RakutenUrlHelper::rakutenItemUrl(['legacy_dpid' => 'shop-2'], 'SKU-9'),
    'Rakuten item URL'
);

$rakutenHtml = '<button aria-label="image-button-0"><span><img src="https://tshop.r10s.jp/shop/cabinet/main/sku.jpg?fitin=720:720"></span></button>';
assert_same(
    'https://image.rakuten.co.jp/shop/cabinet/main/sku.jpg',
    $service->rakutenImageUrlFromHtml($rakutenHtml),
    'Rakuten new image HTML'
);

$rakutenFallback = '<html><head><meta property="og:image" content="https://image.rakuten.co.jp/shop/cabinet/main/fallback.jpg"></head></html>';
assert_same(
    'https://image.rakuten.co.jp/shop/cabinet/main/fallback.jpg',
    $service->rakutenImageUrlFromHtml($rakutenFallback),
    'Rakuten og:image fallback'
);

$directUrls = invoke_private($service, 'rakutenDirectImageUrls', [['legacy_dpid' => 'shop'], 'SKU-1']);
assert_same(
    [
        'https://image.rakuten.co.jp/shop/cabinet/main/sku-1.jpg',
        'https://image.rakuten.co.jp/shop/cabinet/main1/sku-1.jpg',
        'https://image.rakuten.co.jp/shop/cabinet/main2/sku-1.jpg',
        'https://image.rakuten.co.jp/shop/cabinet/main3/sku-1.jpg',
        'https://image.rakuten.co.jp/shop/cabinet/main4/sku-1.jpg',
    ],
    $directUrls,
    'Rakuten direct image retry URLs'
);

assert_same(
    true,
    invoke_private($service, 'needsLocalImage', [['image' => 'https://image.rakuten.co.jp/shop/cabinet/main/sku.jpg', 'main_image' => '']]),
    'remote display image still needs local download'
);
assert_same(
    false,
    invoke_private($service, 'needsLocalImage', [['image' => 'storage/tenants/erp/images/orders/1/2/main.jpg', 'main_image' => 'storage/tenants/erp/images/orders/1/2/main.jpg']]),
    'local image path does not need download'
);

$wowmaHtml = '<meta property="og:image" content="https://img.wowma.jp/item/123.jpg" />';
assert_same(
    'https://img.wowma.jp/item/123.jpg',
    $service->wowmaImageUrlFromHtml($wowmaHtml),
    'Wowma og:image'
);

$auctionHtml = "<META property='og:image' content='https://auctions.c.yimg.jp/images.auctions.yahoo.co.jp/image/dr000/auc.jpg' />";
assert_same(
    'https://auctions.c.yimg.jp/images.auctions.yahoo.co.jp/image/dr000/auc.jpg',
    $service->yahooAuctionImageUrlFromHtml($auctionHtml),
    'Yahoo auction og:image'
);

echo "Product image download test passed.\n";

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "{$label}: expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

/** @param array<int, mixed> $args */
function invoke_private(object $object, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invokeArgs($object, $args);
}
