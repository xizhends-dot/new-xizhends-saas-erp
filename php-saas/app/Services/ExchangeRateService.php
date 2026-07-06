<?php

declare(strict_types=1);

namespace Xizhen\Services;

final class ExchangeRateService
{
    private const FAWAZ_JPY_URL = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/jpy.json';
    private const FAWAZ_JPY_FALLBACK_URL = 'https://latest.currency-api.pages.dev/v1/currencies/jpy.json';
    private const DEFAULT_JPY_CNY = 0.048;

    public function __construct(private readonly ?string $fawazJpyUrl = null)
    {
    }

    /** @return array{rate: float, time: string, success: bool, source: string, precision: int, error?: string} */
    public function jpyToCny(): array
    {
        $rate = $this->fetchFawazJpyToCny();
        if ($rate !== null) {
            return [
                'rate' => $rate,
                'time' => date('Y-m-d H:i:s'),
                'success' => true,
                'source' => 'FawazCurrencyAPI',
                'precision' => 8,
            ];
        }

        return [
            'rate' => self::DEFAULT_JPY_CNY,
            'time' => date('Y-m-d H:i:s'),
            'success' => false,
            'source' => 'FawazCurrencyAPI',
            'precision' => 3,
            'error' => '实时汇率获取失败，显示默认汇率',
        ];
    }

    private function fetchFawazJpyToCny(): ?float
    {
        $urls = $this->fawazJpyUrl !== null
            ? [$this->fawazJpyUrl]
            : [self::FAWAZ_JPY_URL, self::FAWAZ_JPY_FALLBACK_URL];
        foreach ($urls as $url) {
            try {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 3,
                        'ignore_errors' => true,
                        'header' => "Accept: application/json\r\n",
                    ],
                ]);
                $response = @file_get_contents($url, false, $context);
                if ($response === false) {
                    continue;
                }

                $data = json_decode($response, true);
                $rate = $data['jpy']['cny'] ?? null;
                if (!is_numeric($rate) || (float) $rate <= 0) {
                    continue;
                }

                return (float) $rate;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
