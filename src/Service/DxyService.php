<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class DxyService
{
    private const EXCHANGE_RATE_URL = 'https://api.exchangerate-api.com/v4/latest/USD';
    private const DXY_CONSTANT = 50.14348112;

    /** @var array<string, float> */
    private const DXY_WEIGHTS = [
        'EUR' => -0.576,
        'JPY' => 0.136,
        'GBP' => -0.119,
        'CAD' => 0.091,
        'SEK' => 0.042,
        'CHF' => 0.036,
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly FredApiService $fredApi,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{value: float|null, source: string}
     */
    public function getDxy(): array
    {
        return $this->cache->get('dxy_index', function (ItemInterface $item): array {
            $item->expiresAfter(300);

            $value = $this->calculateDxy();
            if ($value !== null) {
                return ['value' => round($value, 2), 'source' => 'ICE formula'];
            }

            $fred = $this->fredApi->getLatestValue('DTWEXBGS');

            return ['value' => $fred['value'] ?? null, 'source' => 'FRED'];
        });
    }

    private function calculateDxy(): ?float
    {
        try {
            $response = $this->httpClient->request('GET', self::EXCHANGE_RATE_URL, ['timeout' => 10]);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $rates = $response->toArray(false)['rates'] ?? [];

            $eur = $rates['EUR'] ?? null;
            $jpy = $rates['JPY'] ?? null;
            $gbp = $rates['GBP'] ?? null;
            $cad = $rates['CAD'] ?? null;
            $sek = $rates['SEK'] ?? null;
            $chf = $rates['CHF'] ?? null;

            if ($eur === null || $jpy === null || $gbp === null || $cad === null || $sek === null || $chf === null) {
                return null;
            }

            return self::DXY_CONSTANT
                * pow(1 / $eur, self::DXY_WEIGHTS['EUR'])
                * pow($jpy, self::DXY_WEIGHTS['JPY'])
                * pow(1 / $gbp, self::DXY_WEIGHTS['GBP'])
                * pow($cad, self::DXY_WEIGHTS['CAD'])
                * pow($sek, self::DXY_WEIGHTS['SEK'])
                * pow($chf, self::DXY_WEIGHTS['CHF']);
        } catch (\Throwable $e) {
            $this->logger->warning('DXY calculation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
