<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MarketDataService
{
    private const YAHOO_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function getPrice(string $ticker): ?float
    {
        $quote = $this->fetchQuote($ticker);

        return $quote['price'] ?? null;
    }

    /**
     * @return array{ticker: string, price: float|null, previous_close: float|null, currency: string}
     */
    public function fetchQuote(string $ticker): array
    {
        $cacheKey = 'market_quote_' . str_replace('.', '_', $ticker);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($ticker): array {
            $item->expiresAfter(900);

            try {
                $response = $this->httpClient->request('GET', self::YAHOO_URL . $ticker, [
                    'query' => ['interval' => '1d', 'range' => '1d'],
                    'headers' => ['User-Agent' => 'Mozilla/5.0'],
                    'timeout' => 15,
                ]);

                $result = $response->toArray(false)['chart']['result'][0] ?? null;
                if ($result === null) {
                    return ['ticker' => $ticker, 'price' => null, 'previous_close' => null, 'currency' => 'EUR'];
                }

                $meta = $result['meta'] ?? [];

                return [
                    'ticker' => $ticker,
                    'price' => isset($meta['regularMarketPrice']) ? (float) $meta['regularMarketPrice'] : null,
                    'previous_close' => isset($meta['previousClose']) ? (float) $meta['previousClose'] : null,
                    'currency' => $meta['currency'] ?? 'EUR',
                ];
            } catch (\Throwable $e) {
                $this->logger->warning('Yahoo quote error', ['ticker' => $ticker, 'error' => $e->getMessage()]);

                return ['ticker' => $ticker, 'price' => null, 'previous_close' => null, 'currency' => 'EUR'];
            }
        });
    }

    /**
     * @return array{high: float|null, date: string|null}
     */
    public function get52WeekHigh(string $ticker, int $days = 252): array
    {
        $cacheKey = 'market_52w_' . str_replace('.', '_', $ticker) . "_{$days}";

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($ticker, $days): array {
            $item->expiresAfter(900);

            try {
                $end = time();
                $start = $end - (int) ($days * 1.5 * 86400);

                $response = $this->httpClient->request('GET', self::YAHOO_URL . $ticker, [
                    'query' => ['period1' => $start, 'period2' => $end, 'interval' => '1d'],
                    'headers' => ['User-Agent' => 'Mozilla/5.0'],
                    'timeout' => 15,
                ]);

                $result = $response->toArray(false)['chart']['result'][0] ?? null;
                if ($result === null) {
                    return ['high' => null, 'date' => null];
                }

                $timestamps = $result['timestamp'] ?? [];
                $highs = $result['indicators']['quote'][0]['high'] ?? [];

                $maxHigh = null;
                $maxDate = null;

                foreach ($highs as $i => $high) {
                    if ($high !== null && ($maxHigh === null || $high > $maxHigh)) {
                        $maxHigh = (float) $high;
                        $maxDate = isset($timestamps[$i]) ? date('Y-m-d', (int) $timestamps[$i]) : null;
                    }
                }

                return ['high' => $maxHigh, 'date' => $maxDate];
            } catch (\Throwable $e) {
                $this->logger->warning('Yahoo 52w high error', ['ticker' => $ticker, 'error' => $e->getMessage()]);

                return ['high' => null, 'date' => null];
            }
        });
    }

    /**
     * @return array{current_price: float|null, high_52w: float|null, drawdown_pct: float|null, phase: string}
     */
    public function getDrawdown(string $ticker = 'IWDA.AS', int $lookbackDays = 252): array
    {
        $quote = $this->fetchQuote($ticker);
        $high52w = $this->get52WeekHigh($ticker, $lookbackDays);

        $price = $quote['price'];
        $high = $high52w['high'];

        if ($price === null || $high === null || $high == 0) {
            return [
                'current_price' => $price,
                'high_52w' => $high,
                'high_52w_date' => $high52w['date'],
                'drawdown_pct' => null,
                'phase' => 'Unknown',
            ];
        }

        $drawdownPct = (($price - $high) / $high) * 100;

        return [
            'current_price' => round($price, 2),
            'high_52w' => round($high, 2),
            'high_52w_date' => $high52w['date'],
            'drawdown_pct' => round($drawdownPct, 2),
            'phase' => $this->classifyDrawdown($drawdownPct),
        ];
    }

    /**
     * Get real-time VIX from Yahoo Finance (^VIX ticker).
     * Returns intraday VIX value instead of FRED's previous-day close.
     */
    public function getVixRealtime(): ?float
    {
        $cacheKey = 'market_vix_realtime';

        return $this->cache->get($cacheKey, function (ItemInterface $item): ?float {
            $item->expiresAfter(3600); // 1 hour cache

            try {
                $response = $this->httpClient->request('GET', self::YAHOO_URL . '%5EVIX', [
                    'query' => ['interval' => '1d', 'range' => '1d'],
                    'headers' => ['User-Agent' => 'Mozilla/5.0'],
                    'timeout' => 15,
                ]);

                $result = $response->toArray(false)['chart']['result'][0] ?? null;
                if ($result === null) {
                    return null;
                }

                $price = $result['meta']['regularMarketPrice'] ?? null;

                return $price !== null ? (float) $price : null;
            } catch (\Throwable $e) {
                $this->logger->warning('Yahoo VIX fetch error', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    private function classifyDrawdown(float $pct): string
    {
        return match (true) {
            $pct >= -5 => 'Normal',
            $pct >= -10 => 'Minor pullback',
            $pct >= -20 => 'Correction',
            $pct >= -30 => 'Bear market',
            $pct >= -50 => 'Severe crash',
            default => 'System crisis',
        };
    }
}
