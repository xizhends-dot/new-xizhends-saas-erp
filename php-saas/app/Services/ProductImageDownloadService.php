<?php

declare(strict_types=1);

namespace Xizhen\Services;

use RuntimeException;
use Xizhen\Core\StoreInterface;

final class ProductImageDownloadService
{
    private const PLATFORMS = ['y', 'r', 'w', 'yp'];
    private const NO_IMAGE = '/assets/no-image.svg';
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif'];

    public function __construct(
        private readonly StoreInterface $store,
        private readonly ?string $basePath = null
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, scanned: int, updated: int, skipped: int, failed: int}
     */
    public function run(string $tenantKey, array $options = []): array
    {
        $dayLimit = max(1, (int) ($options['day_limit'] ?? $options['day-limit'] ?? 3));
        $countLimit = max(1, (int) ($options['count_limit'] ?? $options['count-limit'] ?? 20));
        $candidates = array_slice($this->candidateItems($tenantKey, $dayLimit), 0, $countLimit);
        $summary = ['ok' => true, 'message' => '', 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($candidates as $candidate) {
            $summary['scanned']++;
            try {
                $bytes = $this->downloadForCandidate($tenantKey, $candidate);
                if ($bytes === '') {
                    $summary['skipped']++;
                    continue;
                }

                $relativePath = $this->saveImage(
                    $tenantKey,
                    (int) ($candidate['order']['id'] ?? 0),
                    (int) ($candidate['item']['id'] ?? 0),
                    $bytes
                );
                $this->store->updateOrderItemImage($tenantKey, (int) ($candidate['item']['id'] ?? 0), 'main', $relativePath);
                $summary['updated']++;
            } catch (\Throwable) {
                $summary['failed']++;
            }
        }

        $summary['ok'] = $summary['failed'] === 0;
        $summary['message'] = sprintf(
            '扫描 %d 条，下载 %d 张，跳过 %d 条，失败 %d 条。',
            $summary['scanned'],
            $summary['updated'],
            $summary['skipped'],
            $summary['failed']
        );

        return $summary;
    }

    /** @param array<string, mixed> $store */
    public function yahooShopImageUrl(array $store, string $itemCode): string
    {
        $dpid = RakutenUrlHelper::rakutenDpid($store);
        $itemId = strtolower(trim($itemCode));
        if ($dpid === '' || $itemId === '') {
            return '';
        }

        return "https://item-shopping.c.yimg.jp/i/l/{$dpid}_{$itemId}";
    }

    public function rakutenImageUrlFromHtml(string $html): string
    {
        if (preg_match('/aria-label="image-button-0"[^>]*>.*?<img[^>]+src="(?P<IMGURL>[^"]+)"/s', $html, $matches) === 1) {
            $url = (string) $matches['IMGURL'];
            $url = strtok($url, '?') ?: $url;
            return str_replace('tshop.r10s.jp', 'image.rakuten.co.jp', $url);
        }

        if (preg_match('/<meta[\s]*?property="og:image"[\s]*?content="(?P<IMGURL>.*?)"[\s]*?>/s', $html, $matches) === 1) {
            return (string) $matches['IMGURL'];
        }

        return '';
    }

    public function wowmaImageUrlFromHtml(string $html): string
    {
        if (preg_match('/<meta[\s]+property="og:image"[\s]+content="(?P<SRC>.*?)"[\s]*\/?>/i', $html, $matches) === 1) {
            return (string) $matches['SRC'];
        }

        return '';
    }

    public function yahooAuctionImageUrlFromHtml(string $html): string
    {
        if (preg_match('/<meta[\s]+property=["\']og:image["\'][\s]+content=["\'](?P<SRC>[^"\']*?)["\']\s*\/?>/i', $html, $matches) === 1) {
            return (string) $matches['SRC'];
        }

        return '';
    }

    /**
     * @return array<int, array{order: array<string, mixed>, item: array<string, mixed>}>
     */
    private function candidateItems(string $tenantKey, int $dayLimit): array
    {
        $cutoff = time() - $dayLimit * 86400;
        $candidates = [];
        foreach ($this->store->orders($tenantKey) as $order) {
            $platform = strtolower((string) ($order['platform'] ?? ''));
            if (!in_array($platform, self::PLATFORMS, true)) {
                continue;
            }

            $date = trim((string) ($order['order_date'] ?? ''));
            if ($date === '') {
                $date = trim((string) ($order['imported_at'] ?? ''));
            }
            $timestamp = strtotime($date);
            if ($timestamp === false || $timestamp < $cutoff) {
                continue;
            }

            foreach (($order['items'] ?? []) as $item) {
                if (!is_array($item) || (string) ($item['image'] ?? '') !== self::NO_IMAGE) {
                    continue;
                }
                $candidates[] = ['order' => $order, 'item' => $item];
            }
        }

        return $candidates;
    }

    /** @param array{order: array<string, mixed>, item: array<string, mixed>} $candidate */
    private function downloadForCandidate(string $tenantKey, array $candidate): string
    {
        $platform = strtolower((string) ($candidate['order']['platform'] ?? ''));
        $order = $candidate['order'];
        $item = $candidate['item'];

        return match ($platform) {
            'y' => $this->downloadYahooShop($tenantKey, $order, $item),
            'r' => $this->downloadRakuten($tenantKey, $order, $item),
            'w' => $this->downloadWowma($item),
            'yp' => $this->downloadYahooAuction($item),
            default => '',
        };
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function downloadYahooShop(string $tenantKey, array $order, array $item): string
    {
        $store = $this->store->store($tenantKey, (int) ($order['store_id'] ?? 0)) ?? [];
        $url = $this->yahooShopImageUrl($store, (string) ($item['item_code'] ?? ''));
        if ($url === '') {
            return '';
        }

        $response = $this->httpGet($url);
        if (!$this->isImageResponse($response)) {
            return '';
        }
        if ($this->isReferencePlaceholder($response['body'], 'no-pic-y.gif')) {
            return '';
        }

        return $response['body'];
    }

    /** @param array<string, mixed> $order @param array<string, mixed> $item */
    private function downloadRakuten(string $tenantKey, array $order, array $item): string
    {
        $store = $this->store->store($tenantKey, (int) ($order['store_id'] ?? 0)) ?? [];
        $itemId = strtolower(trim((string) ($item['item_code'] ?? '')));
        $url = RakutenUrlHelper::rakutenItemUrl($store, $itemId);
        if ($url === '') {
            return '';
        }

        $html = '';
        $proxy = $this->proxy();
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = $this->httpGet($url, ['proxy' => $proxy]);
            $html = $response['body'];
            if ($html !== '') {
                break;
            }
            usleep($attempt * 200000);
        }

        $imageUrl = $this->rakutenImageUrlFromHtml($html);
        if ($imageUrl === '') {
            return '';
        }

        $response = $this->httpGet($imageUrl, ['proxy' => $proxy, 'referer' => $url]);
        if (!$this->isImageResponse($response)) {
            return '';
        }
        if ($this->isReferencePlaceholder($response['body'], 'no-pic-r.gif')) {
            return '';
        }

        return $response['body'];
    }

    /** @param array<string, mixed> $item */
    private function downloadWowma(array $item): string
    {
        $lotNumber = trim((string) ($item['lot_number'] ?? ''));
        if ($lotNumber === '') {
            return '';
        }
        $pageUrl = "https://wowma.jp/item/{$lotNumber}";
        $proxy = $this->proxy();
        $html = $this->httpGet($pageUrl, ['proxy' => $proxy])['body'];
        $imageUrl = $this->wowmaImageUrlFromHtml($html);
        if ($imageUrl === '') {
            return '';
        }

        $response = $this->httpGet($imageUrl, ['proxy' => $proxy, 'referer' => $pageUrl]);
        return $this->isImageResponse($response) ? $response['body'] : '';
    }

    /** @param array<string, mixed> $item */
    private function downloadYahooAuction(array $item): string
    {
        $lotNumber = trim((string) ($item['lot_number'] ?? ''));
        if ($lotNumber === '') {
            return '';
        }
        $pageUrl = "https://auctions.yahoo.co.jp/jp/auction/{$lotNumber}";
        $proxy = $this->proxy();
        $html = $this->httpGet($pageUrl, ['proxy' => $proxy])['body'];
        $imageUrl = $this->yahooAuctionImageUrlFromHtml($html);
        if ($imageUrl === '') {
            return '';
        }

        $response = $this->httpGet($imageUrl, ['proxy' => $proxy, 'referer' => 'https://auctions.yahoo.co.jp/']);
        return $this->isImageResponse($response) ? $response['body'] : '';
    }

    private function proxy(): string
    {
        $proxy = is_array($this->store->globalSettings()['proxy'] ?? null) ? $this->store->globalSettings()['proxy'] : [];
        if (empty($proxy['enabled'])) {
            return '';
        }

        return trim((string) ($proxy['rotation_proxy'] ?? ''));
    }

    /**
     * @param array<string, string> $options
     * @return array{body: string, content_type: string}
     */
    private function httpGet(string $url, array $options = []): array
    {
        if ($url === '' || !function_exists('curl_init')) {
            return ['body' => '', 'content_type' => ''];
        }

        $ch = curl_init($url);
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: ja,en-US;q=0.8,en;q=0.6',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => 'deflate, gzip',
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $this->randomUserAgent(),
        ]);
        if (trim((string) ($options['proxy'] ?? '')) !== '') {
            curl_setopt($ch, CURLOPT_PROXY, trim((string) $options['proxy']));
        }
        if (trim((string) ($options['referer'] ?? '')) !== '') {
            curl_setopt($ch, CURLOPT_REFERER, trim((string) $options['referer']));
        }

        $body = curl_exec($ch);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        return [
            'body' => is_string($body) ? $body : '',
            'content_type' => strtolower(strtok($contentType, ';') ?: $contentType),
        ];
    }

    /** @param array{body: string, content_type: string} $response */
    private function isImageResponse(array $response): bool
    {
        return $response['body'] !== '' && in_array(strtolower($response['content_type']), self::IMAGE_TYPES, true);
    }

    private function isReferencePlaceholder(string $bytes, string $fileName): bool
    {
        $path = $this->basePath() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'reference' . DIRECTORY_SEPARATOR . $fileName;
        if (!is_file($path)) {
            return false;
        }

        return md5($bytes) === md5_file($path);
    }

    private function saveImage(string $tenantKey, int $orderId, int $itemId, string $bytes): string
    {
        if ($orderId <= 0 || $itemId <= 0 || $bytes === '') {
            throw new RuntimeException('Invalid image save request.');
        }

        $folder = $this->basePath() . "/storage/tenants/{$tenantKey}/images/orders/{$orderId}/{$itemId}";
        if (!is_dir($folder) && !mkdir($folder, 0777, true) && !is_dir($folder)) {
            throw new RuntimeException("Unable to create image directory: {$folder}");
        }

        $fileName = 'main-' . date('YmdHis') . '-' . substr(sha1($bytes), 0, 8) . '-download.jpg';
        $absolute = $folder . '/' . $fileName;
        if (file_put_contents($absolute, $bytes) === false) {
            throw new RuntimeException("Unable to write image: {$absolute}");
        }

        $relative = str_replace('\\', '/', $absolute);
        $base = str_replace('\\', '/', $this->basePath() . '/');
        if (str_starts_with($relative, $base)) {
            $relative = substr($relative, strlen($base));
        }

        return $relative;
    }

    private function basePath(): string
    {
        if ($this->basePath !== null && $this->basePath !== '') {
            return rtrim($this->basePath, '/\\');
        }

        return defined('BASE_PATH') ? (string) constant('BASE_PATH') : dirname(__DIR__, 2);
    }

    private function randomUserAgent(): string
    {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_6) AppleWebKit/605.1.15 Version/17.5 Safari/605.1.15',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36',
        ];

        return $agents[array_rand($agents)];
    }
}
