<?php

declare(strict_types=1);

namespace Xizhen\Services;

use RuntimeException;
use Xizhen\Core\StoreInterface;

final class ProductImageDownloadService
{
    private const PLATFORMS = ['y', 'r', 'w', 'yp'];
    private const LOG_PLATFORM_ORDER = ['r', 'm', 'w', 'y', 'yp'];
    private const NO_IMAGE = '/assets/no-image.svg';
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    private const RAKUTEN_DIRECT_IMAGE_FOLDERS = ['', '1', '2', '3', '4'];

    public function __construct(
        private readonly StoreInterface $store,
        private readonly ?string $basePath = null
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array{ok: bool, message: string, scanned: int, updated: int, skipped: int, failed: int, logs: array<int, string>}
     */
    public function run(string $tenantKey, array $options = []): array
    {
        $dayLimit = max(1, (int) ($options['day_limit'] ?? $options['day-limit'] ?? 3));
        $countLimit = max(1, (int) ($options['count_limit'] ?? $options['count-limit'] ?? 20));
        $candidates = array_slice($this->candidateItems($tenantKey, $dayLimit), 0, $countLimit);
        $summary = ['ok' => true, 'message' => '', 'scanned' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'logs' => []];
        $groupedCandidates = $this->groupCandidatesByPlatform($candidates);
        $logger = is_callable($options['logger'] ?? null) ? $options['logger'] : null;

        foreach (self::LOG_PLATFORM_ORDER as $platform) {
            $this->appendLog($summary, $this->platformStartLine($platform), $logger);
            foreach ($groupedCandidates[$platform] ?? [] as $candidate) {
                $this->processCandidate($tenantKey, $candidate, $summary, $logger);
            }
            $this->appendLog($summary, $this->platformEndLine($platform), $logger);
            $this->appendLog($summary, '', $logger);
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

    /**
     * @param array<int, array{order: array<string, mixed>, item: array<string, mixed>}> $candidates
     * @return array<string, array<int, array{order: array<string, mixed>, item: array<string, mixed>}>>
     */
    private function groupCandidatesByPlatform(array $candidates): array
    {
        $grouped = [];
        foreach ($candidates as $candidate) {
            $platform = strtolower((string) ($candidate['order']['platform'] ?? ''));
            $grouped[$platform][] = $candidate;
        }

        return $grouped;
    }

    /**
     * @param array{order: array<string, mixed>, item: array<string, mixed>} $candidate
     * @param array{ok: bool, message: string, scanned: int, updated: int, skipped: int, failed: int, logs: array<int, string>} $summary
     */
    private function processCandidate(string $tenantKey, array $candidate, array &$summary, ?callable $logger): void
    {
        $summary['scanned']++;
        $this->appendLog($summary, $this->candidateLine($candidate), $logger);

        try {
                $image = $this->downloadForCandidate($tenantKey, $candidate);
                if ($image['body'] === '') {
                    $summary['skipped']++;
                    $this->appendLog($summary, '图片下载跳过: 未找到有效图片', $logger);
                    return;
            }

                $relativePath = $this->saveImage(
                    $tenantKey,
                    (int) ($candidate['order']['id'] ?? 0),
                    (int) ($candidate['item']['id'] ?? 0),
                    $image['body'],
                    $image['content_type']
                );
            $this->store->updateOrderItemImage($tenantKey, (int) ($candidate['item']['id'] ?? 0), 'main', $relativePath);
            $summary['updated']++;
            $this->appendLog($summary, "图片下载成功: {$relativePath}", $logger);
        } catch (\Throwable $exception) {
            $summary['failed']++;
            $this->appendLog($summary, '图片下载出错: ' . $exception->getMessage(), $logger);
        }
    }

    /**
     * @param array{ok: bool, message: string, scanned: int, updated: int, skipped: int, failed: int, logs: array<int, string>} $summary
     */
    private function appendLog(array &$summary, string $line, ?callable $logger): void
    {
        $summary['logs'][] = $line;
        if ($logger !== null) {
            $logger($line);
        }
    }

    private function platformStartLine(string $platform): string
    {
        $label = strtoupper($platform);
        $suffix = $platform === 'w' ? '(爬虫模式)' : '';

        return "---------------- 开始{$label}主图下载任务{$suffix} ----------------";
    }

    private function platformEndLine(string $platform): string
    {
        $label = strtoupper($platform);
        $suffix = $platform === 'w' ? '(爬虫模式)' : '';

        return "---------------- {$label}主图下载任务结束{$suffix} ----------------";
    }

    /** @param array{order: array<string, mixed>, item: array<string, mixed>} $candidate */
    private function candidateLine(array $candidate): string
    {
        $order = $candidate['order'];
        $item = $candidate['item'];
        $parts = [
            'ID=' . (string) ($order['id'] ?? ''),
            'OrderId=' . (string) ($order['platform_order_id'] ?? ''),
        ];
        $lotNumber = trim((string) ($item['lot_number'] ?? ''));
        if ($lotNumber !== '') {
            $parts[] = 'lotnumber=' . $lotNumber;
        }
        $itemCode = trim((string) ($item['item_code'] ?? ''));
        if ($itemCode !== '') {
            $parts[] = 'item_code=' . $itemCode;
        }

        return '订单:' . implode(', ', $parts);
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
                if (!is_array($item) || !$this->needsLocalImage($item)) {
                    continue;
                }
                $candidates[] = ['order' => $order, 'item' => $item];
            }
        }

        return $candidates;
    }

    /**
     * @param array{order: array<string, mixed>, item: array<string, mixed>} $candidate
     * @return array{body: string, content_type: string}
     */
    private function downloadForCandidate(string $tenantKey, array $candidate): array
    {
        $platform = strtolower((string) ($candidate['order']['platform'] ?? ''));
        $order = $candidate['order'];
        $item = $candidate['item'];

        return match ($platform) {
            'y' => $this->downloadYahooShop($tenantKey, $order, $item),
            'r' => $this->downloadRakuten($tenantKey, $order, $item),
            'w' => $this->downloadWowma($item),
            'yp' => $this->downloadYahooAuction($item),
            default => $this->emptyImage(),
        };
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @return array{body: string, content_type: string}
     */
    private function downloadYahooShop(string $tenantKey, array $order, array $item): array
    {
        $store = $this->store->store($tenantKey, (int) ($order['store_id'] ?? 0)) ?? [];
        $url = $this->yahooShopImageUrl($store, (string) ($item['item_code'] ?? ''));
        if ($url === '') {
            return $this->emptyImage();
        }

        $response = $this->httpGet($url);
        if (!$this->isImageResponse($response)) {
            return $this->emptyImage();
        }
        if ($this->isReferencePlaceholder($response['body'], 'no-pic-y.gif')) {
            return $this->emptyImage();
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $item
     * @return array{body: string, content_type: string}
     */
    private function downloadRakuten(string $tenantKey, array $order, array $item): array
    {
        $store = $this->store->store($tenantKey, (int) ($order['store_id'] ?? 0)) ?? [];
        $itemId = strtolower(trim((string) ($item['item_code'] ?? '')));
        $url = RakutenUrlHelper::rakutenItemUrl($store, $itemId);
        $proxy = $this->proxy();
        $extra = is_array($item['platform_extra'] ?? null) ? $item['platform_extra'] : [];
        $imageUrl = trim((string) ($extra['zhutu'] ?? ''));
        if ($imageUrl !== '') {
            $response = $this->httpGet($imageUrl, ['proxy' => $proxy, 'referer' => $url]);
            if ($this->isImageResponse($response) && !$this->isReferencePlaceholder($response['body'], 'no-pic-r.gif')) {
                return $response;
            }
        }

        foreach ($this->rakutenDirectImageUrls($store, $itemId) as $directUrl) {
            $response = $this->httpGet($directUrl, ['proxy' => $proxy, 'referer' => $url]);
            if ($this->isImageResponse($response) && !$this->isReferencePlaceholder($response['body'], 'no-pic-r.gif')) {
                return $response;
            }
        }

        if ($url === '') {
            return $this->emptyImage();
        }

        $html = '';
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
            return $this->emptyImage();
        }

        $response = $this->httpGet($imageUrl, ['proxy' => $proxy, 'referer' => $url]);
        if (!$this->isImageResponse($response)) {
            return $this->emptyImage();
        }
        if ($this->isReferencePlaceholder($response['body'], 'no-pic-r.gif')) {
            return $this->emptyImage();
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{body: string, content_type: string}
     */
    private function downloadWowma(array $item): array
    {
        $lotNumber = trim((string) ($item['lot_number'] ?? ''));
        if ($lotNumber === '') {
            return $this->emptyImage();
        }
        $pageUrl = "https://wowma.jp/item/{$lotNumber}";
        $proxy = $this->proxy();
        $html = $this->httpGet($pageUrl, ['proxy' => $proxy])['body'];
        $imageUrl = $this->wowmaImageUrlFromHtml($html);
        if ($imageUrl === '') {
            return $this->emptyImage();
        }

        $response = $this->httpGet($imageUrl, ['proxy' => $proxy, 'referer' => $pageUrl]);
        return $this->isImageResponse($response) ? $response : $this->emptyImage();
    }

    /**
     * @param array<string, mixed> $item
     * @return array{body: string, content_type: string}
     */
    private function downloadYahooAuction(array $item): array
    {
        $lotNumber = trim((string) ($item['lot_number'] ?? ''));
        if ($lotNumber === '') {
            return $this->emptyImage();
        }
        $pageUrl = "https://auctions.yahoo.co.jp/jp/auction/{$lotNumber}";
        $proxy = $this->proxy();
        $html = $this->httpGet($pageUrl, ['proxy' => $proxy])['body'];
        $imageUrl = $this->yahooAuctionImageUrlFromHtml($html);
        if ($imageUrl === '') {
            return $this->emptyImage();
        }

        $response = $this->httpGet($imageUrl, ['proxy' => $proxy, 'referer' => 'https://auctions.yahoo.co.jp/']);
        return $this->isImageResponse($response) ? $response : $this->emptyImage();
    }

    /** @return array{body: string, content_type: string} */
    private function emptyImage(): array
    {
        return ['body' => '', 'content_type' => ''];
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

    /** @param array<string, mixed> $item */
    private function needsLocalImage(array $item): bool
    {
        $mainImage = trim((string) ($item['main_image'] ?? ''));
        if ($mainImage === '' || $mainImage === self::NO_IMAGE || $this->isRemoteUrl($mainImage)) {
            return true;
        }

        $displayImage = trim((string) ($item['image'] ?? ''));

        return $displayImage === '' || $displayImage === self::NO_IMAGE || $this->isRemoteUrl($displayImage);
    }

    private function isRemoteUrl(string $value): bool
    {
        return preg_match('/^https?:\/\//i', $value) === 1;
    }

    /**
     * @param array<string, mixed> $store
     * @return array<int, string>
     */
    private function rakutenDirectImageUrls(array $store, string $itemId): array
    {
        $dpid = RakutenUrlHelper::rakutenDpid($store);
        $itemId = strtolower(trim($itemId));
        if ($dpid === '' || $itemId === '') {
            return [];
        }

        return array_map(
            static fn (string $suffix): string => "https://image.rakuten.co.jp/{$dpid}/cabinet/main{$suffix}/{$itemId}.jpg",
            self::RAKUTEN_DIRECT_IMAGE_FOLDERS
        );
    }

    private function saveImage(string $tenantKey, int $orderId, int $itemId, string $bytes, string $contentType): string
    {
        if ($orderId <= 0 || $itemId <= 0 || $bytes === '') {
            throw new RuntimeException('Invalid image save request.');
        }

        $folder = $this->basePath() . "/storage/tenants/{$tenantKey}/images/orders/{$orderId}/{$itemId}";
        if (!is_dir($folder) && !mkdir($folder, 0777, true) && !is_dir($folder)) {
            throw new RuntimeException("Unable to create image directory: {$folder}");
        }

        $fileName = 'main-' . date('YmdHis') . '-' . substr(sha1($bytes), 0, 8) . '-download.' . $this->imageExtension($contentType);
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

    private function imageExtension(string $contentType): string
    {
        return match (strtolower($contentType)) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
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
