<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoldPriceService
{
    private const GOLDPRICE_URL = 'https://data-asg.goldprice.org/dbXRates/USD';
    private const SWISSQUOTE_GOLD_URL = 'https://forex-data-feed.swissquote.com/public-quotes/bboquotes/instrument/XAU/USD';
    private const SWISSQUOTE_SILVER_URL = 'https://forex-data-feed.swissquote.com/public-quotes/bboquotes/instrument/XAG/USD';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{gold: float|null, silver: float|null, gold_silver_ratio: float|null, gold_change_pct: float|null}
     */
    public function getPrices(): array
    {
        return $this->cache->get('gold_prices', function (ItemInterface $item): array {
            $item->expiresAfter(300);

            try {
                $response = $this->httpClient->request('GET', self::GOLDPRICE_URL, ['timeout' => 5]);

                if ($response->getStatusCode() === 200) {
                    $data = $response->toArray(false);
                    $row = $data['items'][0] ?? null;

                    if ($row !== null) {
                        $gold = isset($row['xauPrice']) ? (float) $row['xauPrice'] : null;
                        $silver = isset($row['xagPrice']) ? (float) $row['xagPrice'] : null;
                        $ratio = ($gold !== null && $silver !== null && $silver > 0) ? round($gold / $silver, 2) : null;

                        return [
                            'gold' => $gold,
                            'silver' => $silver,
                            'gold_silver_ratio' => $ratio,
                            'gold_change_pct' => isset($row['pcXau']) ? (float) $row['pcXau'] : null,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('goldprice.org failed, trying Swissquote', ['error' => $e->getMessage()]);
            }

            return $this->fetchSwissquote();
        });
    }

    /**
     * @return array{gold: float|null, silver: float|null, gold_silver_ratio: float|null, gold_change_pct: float|null}
     */
    private function fetchSwissquote(): array
    {
        $gold = null;
        $silver = null;

        try {
            $goldResponse = $this->httpClient->request('GET', self::SWISSQUOTE_GOLD_URL, ['timeout' => 10]);
            if ($goldResponse->getStatusCode() === 200) {
                $data = $goldResponse->toArray(false);
                $gold = isset($data[0]['spreadProfilePrices'][0]['bid']) ? (float) $data[0]['spreadProfilePrices'][0]['bid'] : null;
            }

            $silverResponse = $this->httpClient->request('GET', self::SWISSQUOTE_SILVER_URL, ['timeout' => 10]);
            if ($silverResponse->getStatusCode() === 200) {
                $data = $silverResponse->toArray(false);
                $silver = isset($data[0]['spreadProfilePrices'][0]['bid']) ? (float) $data[0]['spreadProfilePrices'][0]['bid'] : null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Swissquote fallback failed', ['error' => $e->getMessage()]);
        }

        $ratio = ($gold !== null && $silver !== null && $silver > 0) ? round($gold / $silver, 2) : null;

        return ['gold' => $gold, 'silver' => $silver, 'gold_silver_ratio' => $ratio, 'gold_change_pct' => null];
    }
}
