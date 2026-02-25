<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FredApiService
{
    private const BASE_URL = 'https://api.stlouisfed.org/fred';

    private const CACHE_TTL = [
        'default' => 3600,
        'daily' => 3600,
        'weekly' => 21600,
        'monthly' => 43200,
        'quarterly' => 86400,
    ];

    /** @var array<string, string> */
    private const SERIES_FREQUENCY = [
        'ECBDFR' => 'daily',
        'ECBMRRFR' => 'daily',
        'VIXCLS' => 'daily',
        'DGS2' => 'daily',
        'DGS10' => 'daily',
        'DGS30' => 'daily',
        'T10Y2Y' => 'daily',
        'T5YIE' => 'daily',
        'T10YIE' => 'daily',
        'BAMLH0A0HYM2' => 'daily',
        'BAMLC0A4CBBB' => 'daily',
        'TEDRATE' => 'daily',
        'DCOILWTICO' => 'daily',
        'DEXUSEU' => 'daily',
        'DTWEXBGS' => 'daily',
        'FEDFUNDS' => 'monthly',
        'UNRATE' => 'monthly',
        'PCOPPUSDM' => 'monthly',
        'UMCSENT' => 'monthly',
        'ICSA' => 'weekly',
        'STLFSI4' => 'weekly',
        'GDPC1' => 'quarterly',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $fredApiKey,
    ) {}

    /**
     * @return array<int, array{date: string, value: float|null}>|null
     */
    public function getSeriesObservations(string $seriesId, int $limit = 1, string $sortOrder = 'desc'): ?array
    {
        $cacheKey = "fred_{$seriesId}_{$limit}_{$sortOrder}";
        $cacheTtl = $this->getCacheTtl($seriesId);

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($seriesId, $limit, $sortOrder, $cacheTtl): ?array {
            $item->expiresAfter($cacheTtl);

            try {
                $response = $this->httpClient->request('GET', self::BASE_URL . '/series/observations', [
                    'query' => [
                        'series_id' => $seriesId,
                        'api_key' => $this->fredApiKey,
                        'file_type' => 'json',
                        'sort_order' => $sortOrder,
                        'limit' => $limit,
                    ],
                    'timeout' => 30,
                ]);

                $data = $response->toArray(false);

                if (!isset($data['observations']) || $data['observations'] === []) {
                    $this->logger->warning('No observations found for FRED series', ['series' => $seriesId]);

                    return null;
                }

                return array_map(fn(array $obs): array => [
                    'date' => $obs['date'],
                    'value' => $obs['value'] !== '.' ? (float) $obs['value'] : null,
                ], $data['observations']);
            } catch (\Throwable $e) {
                $this->logger->error('FRED API error', ['series' => $seriesId, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * @return array{date: string, value: float|null}|null
     */
    public function getLatestValue(string $seriesId): ?array
    {
        $observations = $this->getSeriesObservations($seriesId, 1);

        return $observations[0] ?? null;
    }

    private function getCacheTtl(string $seriesId): int
    {
        $frequency = self::SERIES_FREQUENCY[$seriesId] ?? 'default';

        return self::CACHE_TTL[$frequency] ?? self::CACHE_TTL['default'];
    }
}
