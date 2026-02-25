<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CalculationService
{
    private const CAPE_FALLBACK = 41.0;
    private const CAPE_HISTORICAL_AVG = 17.0;
    private const MULTPL_URL = 'https://www.multpl.com/shiller-pe/table/by-month';

    public function __construct(
        private readonly FredApiService $fredApi,
        private readonly EurostatService $eurostat,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{value: float, historical_avg: float, status: string}
     */
    public function getCapeAssessment(): array
    {
        $cape = $this->fetchCape();

        return [
            'value' => $cape,
            'historical_avg' => self::CAPE_HISTORICAL_AVG,
            'deviation_pct' => round((($cape / self::CAPE_HISTORICAL_AVG) - 1) * 100, 1),
            'status' => match (true) {
                $cape > 35 => 'VERY_HIGH',
                $cape > 25 => 'HIGH',
                $cape > 20 => 'ABOVE_AVG',
                default => 'NORMAL',
            },
        ];
    }

    /**
     * @return array{value: float, earnings_yield: float, treasury_10y: float|null, cape: float, status: string}
     */
    public function calculateEquityRiskPremium(): array
    {
        $treasury10y = $this->fredApi->getLatestValue('DGS10');
        $cape = $this->fetchCape();
        $earningsYield = (1 / $cape) * 100;
        $erp = $earningsYield - ($treasury10y['value'] ?? 0);

        return [
            'value' => round($erp, 2),
            'earnings_yield' => round($earningsYield, 2),
            'treasury_10y' => $treasury10y['value'] ?? null,
            'cape' => $cape,
            'status' => match (true) {
                $erp < 0 => 'NEGATIVE',
                $erp < 1 => 'LOW',
                $erp < 3 => 'BELOW_AVG',
                default => 'NORMAL',
            },
        ];
    }

    /**
     * @return array{value: float|null, nominal_rate: float|null, inflation: float|null}
     */
    public function calculateRealEcbRate(): array
    {
        $ecbRate = $this->fredApi->getLatestValue('ECBDFR');
        $euInflation = $this->eurostat->getLatestInflation();

        if ($ecbRate === null || $euInflation === null) {
            return ['value' => null, 'nominal_rate' => null, 'inflation' => null];
        }

        return [
            'value' => round($ecbRate['value'] - $euInflation['value'], 2),
            'nominal_rate' => $ecbRate['value'],
            'inflation' => $euInflation['value'],
        ];
    }

    /**
     * @return array{probability: int, status: string, factors: array<int, array{name: string, status: string, contribution: int}>}
     */
    public function calculateRecessionProbability(): array
    {
        $score = 0;
        $factors = [];

        $spread10y2y = $this->fredApi->getLatestValue('T10Y2Y');
        if ($spread10y2y !== null && $spread10y2y['value'] < 0) {
            $score += 25;
            $factors[] = ['name' => 'Yield Curve 10Y-2Y', 'status' => 'INVERTED', 'contribution' => 25];
        } elseif ($spread10y2y !== null && $spread10y2y['value'] < 0.5) {
            $score += 10;
            $factors[] = ['name' => 'Yield Curve 10Y-2Y', 'status' => 'FLAT', 'contribution' => 10];
        }

        $hySpread = $this->fredApi->getLatestValue('BAMLH0A0HYM2');
        if ($hySpread !== null && $hySpread['value'] > 6) {
            $score += 15;
            $factors[] = ['name' => 'HY Credit Spread', 'status' => 'STRESSED', 'contribution' => 15];
        } elseif ($hySpread !== null && $hySpread['value'] > 5) {
            $score += 8;
            $factors[] = ['name' => 'HY Credit Spread', 'status' => 'ELEVATED', 'contribution' => 8];
        }

        $vix = $this->fredApi->getLatestValue('VIXCLS');
        if ($vix !== null && $vix['value'] > 30) {
            $score += 10;
            $factors[] = ['name' => 'VIX', 'status' => 'HIGH', 'contribution' => 10];
        }

        $claims = $this->fredApi->getLatestValue('ICSA');
        if ($claims !== null && $claims['value'] > 300) {
            $score += 15;
            $factors[] = ['name' => 'Initial Claims', 'status' => 'HIGH', 'contribution' => 15];
        } elseif ($claims !== null && $claims['value'] > 250) {
            $score += 8;
            $factors[] = ['name' => 'Initial Claims', 'status' => 'ELEVATED', 'contribution' => 8];
        }

        $sentiment = $this->fredApi->getLatestValue('UMCSENT');
        if ($sentiment !== null && $sentiment['value'] < 60) {
            $score += 10;
            $factors[] = ['name' => 'Consumer Sentiment', 'status' => 'LOW', 'contribution' => 10];
        }

        return [
            'probability' => min($score, 100),
            'status' => match (true) {
                $score >= 50 => 'HIGH',
                $score >= 30 => 'ELEVATED',
                $score >= 15 => 'MODERATE',
                default => 'LOW',
            },
            'factors' => $factors,
        ];
    }

    private function fetchCape(): float
    {
        return $this->cache->get('cape_ratio', function (ItemInterface $item): float {
            $item->expiresAfter(21600);

            try {
                $response = $this->httpClient->request('GET', self::MULTPL_URL, [
                    'timeout' => 10,
                    'headers' => ['User-Agent' => 'MIDO-Dashboard/1.0'],
                ]);

                if ($response->getStatusCode() === 200) {
                    $html = $response->getContent();
                    if (preg_match('/<td[^>]*class="right"[^>]*>([\d.]+)<\/td>/', $html, $matches)) {
                        return (float) $matches[1];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('CAPE fetch failed, using fallback', ['error' => $e->getMessage()]);
            }

            return self::CAPE_FALLBACK;
        });
    }
}
