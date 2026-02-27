<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GoldPriceService
{
    private const SWISSQUOTE_GOLD_URL = 'https://forex-data-feed.swissquote.com/public-quotes/bboquotes/instrument/XAU/USD';
    private const SWISSQUOTE_SILVER_URL = 'https://forex-data-feed.swissquote.com/public-quotes/bboquotes/instrument/XAG/USD';
    private const GOLDPRICE_URL = 'https://data-asg.goldprice.org/dbXRates/USD';

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

            $result = $this->fetchSwissquote();

            if ($result['gold'] !== null) {
                return $result;
            }

            return $this->fetchGoldpriceOrg() ?? $result;
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
                /** @var array<int, array{spreadProfilePrices?: array<int, array{bid?: float}>}> $data */
                $data = $goldResponse->toArray(false);
                $bid = $data[0]['spreadProfilePrices'][0]['bid'] ?? null;
                $gold = $bid !== null ? (float) $bid : null;
            }

            $silverResponse = $this->httpClient->request('GET', self::SWISSQUOTE_SILVER_URL, ['timeout' => 10]);
            if ($silverResponse->getStatusCode() === 200) {
                /** @var array<int, array{spreadProfilePrices?: array<int, array{bid?: float}>}> $data */
                $data = $silverResponse->toArray(false);
                $bid = $data[0]['spreadProfilePrices'][0]['bid'] ?? null;
                $silver = $bid !== null ? (float) $bid : null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Swissquote precious metals fetch failed', ['error' => $e->getMessage()]);
        }

        $ratio = ($gold !== null && $silver !== null && $silver > 0) ? round($gold / $silver, 2) : null;

        return ['gold' => $gold, 'silver' => $silver, 'gold_silver_ratio' => $ratio, 'gold_change_pct' => null];
    }

    /**
     * @return array{gold: float|null, silver: float|null, gold_silver_ratio: float|null, gold_change_pct: float|null}|null
     */
    private function fetchGoldpriceOrg(): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::GOLDPRICE_URL, ['timeout' => 5]);

            if ($response->getStatusCode() === 200) {
                /** @var array{items?: array<int, array{xauPrice?: float, xagPrice?: float, pcXau?: float}>} $data */
                $data = $response->toArray(false);
                $row = $data['items'][0] ?? null;

                if ($row !== null) {
                    $gold = isset($row['xauPrice']) ? (float) $row['xauPrice'] : null;
                    $silver = isset($row['xagPrice']) ? (float) $row['xagPrice'] : null;

                    if ($gold !== null && $gold > 0) {
                        $ratio = ($silver !== null && $silver > 0) ? round($gold / $silver, 2) : null;

                        return [
                            'gold' => $gold,
                            'silver' => $silver,
                            'gold_silver_ratio' => $ratio,
                            'gold_change_pct' => isset($row['pcXau']) ? (float) $row['pcXau'] : null,
                        ];
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('goldprice.org fetch failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
