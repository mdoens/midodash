<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EurostatService
{
    private const BASE_URL = 'https://ec.europa.eu/eurostat/api/dissemination/statistics/1.0/data';
    private const ECB_BACKUP_URL = 'https://data-api.ecb.europa.eu/service/data';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{date: string, value: float}|null
     */
    public function getLatestInflation(): ?array
    {
        $data = $this->getEurozoneInflation(1);

        return $data[0] ?? null;
    }

    /**
     * @return array<int, array{date: string, value: float}>|null
     */
    public function getEurozoneInflation(int $periods = 3): ?array
    {
        $cacheKey = "eurostat_hicp_{$periods}";

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($periods): ?array {
            $item->expiresAfter(43200);

            try {
                $response = $this->httpClient->request('GET', self::BASE_URL . '/prc_hicp_manr', [
                    'query' => [
                        'format' => 'JSON',
                        'geo' => 'EA',
                        'coicop' => 'CP00',
                        'lastTimePeriod' => $periods,
                    ],
                    'timeout' => 30,
                ]);

                $data = $response->toArray(false);

                return $this->parseEurostatResponse($data);
            } catch (\Throwable $e) {
                $this->logger->warning('Eurostat API error, trying ECB backup', ['error' => $e->getMessage()]);

                return $this->getEcbInflationBackup($periods);
            }
        });
    }

    /**
     * @return array<int, array{date: string, value: float}>
     */
    private function parseEurostatResponse(array $data): array
    {
        $observations = [];

        if (!isset($data['dimension']['time']['category']['index'])) {
            return $observations;
        }

        $timeIndex = $data['dimension']['time']['category']['index'];
        $values = $data['value'] ?? [];

        arsort($timeIndex);

        foreach ($timeIndex as $period => $idx) {
            if (isset($values[(string) $idx])) {
                $observations[] = [
                    'date' => (string) $period,
                    'value' => (float) $values[(string) $idx],
                ];
            }
        }

        return $observations;
    }

    /**
     * @return array<int, array{date: string, value: float}>|null
     */
    private function getEcbInflationBackup(int $periods): ?array
    {
        try {
            $response = $this->httpClient->request('GET', self::ECB_BACKUP_URL . '/ICP/M.U2.N.000000.4.ANR', [
                'query' => ['format' => 'jsondata', 'lastNObservations' => $periods],
                'timeout' => 30,
            ]);

            $data = $response->toArray(false);
            $obs = $data['dataSets'][0]['series']['0:0:0:0:0:0']['observations'] ?? [];
            $timePeriods = $data['structure']['dimensions']['observation'][0]['values'] ?? [];

            $observations = [];
            foreach ($obs as $idx => $values) {
                $period = $timePeriods[$idx]['id'] ?? null;
                $value = $values[0] ?? null;
                if ($period !== null && $value !== null) {
                    $observations[] = ['date' => (string) $period, 'value' => (float) $value];
                }
            }

            usort($observations, fn(array $a, array $b): int => strcmp($b['date'], $a['date']));

            return $observations;
        } catch (\Throwable $e) {
            $this->logger->error('ECB backup API error', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
