<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class MomentumService
{
    /** @var array<string, array{name: string, role: string, equity: bool, cash: bool}> */
    public const TICKERS = [
        'IWDA.AS' => ['name' => 'MSCI World', 'role' => 'Aandelen large cap', 'equity' => true, 'cash' => false],
        'AVWC.DE' => ['name' => 'Avantis Global Equity', 'role' => 'Aandelen global value', 'equity' => true, 'cash' => false],
        'AVWS.DE' => ['name' => 'Avantis Small Cap Value', 'role' => 'Aandelen small cap value', 'equity' => true, 'cash' => false],
        'ZPRX.DE' => ['name' => 'Europe Small Cap Value', 'role' => 'Aandelen Europa small cap', 'equity' => true, 'cash' => false],
        'IEMA.AS' => ['name' => 'Emerging Markets', 'role' => 'Groei / dollar zwak', 'equity' => false, 'cash' => false],
        'AVEM.DE' => ['name' => 'Avantis Emerging Markets', 'role' => 'Groei / EM value', 'equity' => false, 'cash' => false],
        'IBCI.AS' => ['name' => 'Inflation Bonds', 'role' => 'Obligaties inflatie', 'equity' => false, 'cash' => false],
        'SGLD.L'  => ['name' => 'Physical Gold', 'role' => 'Crisis diversificator', 'equity' => false, 'cash' => false],
        'XEON.DE' => ['name' => '€STR Cash', 'role' => 'Cash equivalent', 'equity' => false, 'cash' => true],
    ];

    private const CACHE_TTL = 3600;

    private readonly string $cacheFile;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->cacheFile = dirname(__DIR__, 2) . '/var/momentum_cache.json';
    }

    /**
     * @return array<string, mixed>
     */
    public function getSignal(): array
    {
        $cached = $this->loadCache();
        if ($cached !== null) {
            return $cached;
        }

        $signal = $this->computeSignal();
        $this->saveCache($signal);

        return $signal;
    }

    /**
     * @return array<string, mixed>
     */
    private function computeSignal(): array
    {
        $regime = $this->checkRegime();
        $scores = $this->calculateScores();

        if (!$regime['bull']) {
            return [
                'regime' => $regime,
                'scores' => $scores,
                'allocation' => ['XEON.DE' => 1.0],
                'reason' => 'Bear regime — IWDA onder 200-daags gemiddelde → 100% cash',
            ];
        }

        $positief = array_filter($scores, fn(array $s): bool => $s['score'] > 0 && !$s['cash']);

        if (empty($positief)) {
            return [
                'regime' => $regime,
                'scores' => $scores,
                'allocation' => ['XEON.DE' => 1.0],
                'reason' => 'Alle momentum scores negatief → 100% cash',
            ];
        }

        // Top 2, nooit 2 aandelenETFs tegelijk
        $top2 = [];
        $seenEquity = false;

        foreach ($positief as $ticker => $info) {
            if ($info['equity']) {
                if ($seenEquity) {
                    continue;
                }
                $seenEquity = true;
            }
            $top2[] = $ticker;
            if (count($top2) >= 2) {
                break;
            }
        }

        if (count($top2) === 1) {
            return [
                'regime' => $regime,
                'scores' => $scores,
                'allocation' => [$top2[0] => 0.5, 'XEON.DE' => 0.5],
                'reason' => 'Slechts 1 ETF positief → 50/50 met cash',
            ];
        }

        $weight = 1.0 / count($top2);
        $allocation = [];
        foreach ($top2 as $t) {
            $allocation[$t] = $weight;
        }

        return [
            'regime' => $regime,
            'scores' => $scores,
            'allocation' => $allocation,
            'reason' => 'Top 2 momentum ETFs geselecteerd',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function calculateScores(): array
    {
        $scores = [];
        foreach (self::TICKERS as $ticker => $info) {
            $prices = $this->fetchMonthlyPrices($ticker);
            $returns = $this->monthlyReturns($prices);
            $score = $this->momentumScore($returns);

            $values = array_values($prices);
            $lastPrice = end($values) ?: 0;

            $scores[$ticker] = [
                'name' => $info['name'],
                'role' => $info['role'],
                'equity' => $info['equity'],
                'cash' => $info['cash'],
                'score' => $score,
                'last_price' => $lastPrice,
            ];
        }

        uasort($scores, fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scores;
    }

    /**
     * @return array<string, mixed>
     */
    public function checkRegime(): array
    {
        $prices = $this->fetchDailyPrices('IWDA.AS', 300);
        $prices = array_filter($prices, fn(mixed $p): bool => $p !== null && $p > 0);

        if (count($prices) < 200) {
            return ['bull' => true, 'price' => 0, 'ma200' => 0, 'error' => 'Onvoldoende data'];
        }

        $last = (float) end($prices);
        $ma200 = array_sum(array_slice($prices, -200)) / 200;

        return [
            'bull' => $last > $ma200,
            'price' => round($last, 2),
            'ma200' => round($ma200, 2),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function fetchMonthlyPrices(string $ticker): array
    {
        $end = time();
        $start = $end - (26 * 31 * 86400);

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1mo',
            urlencode($ticker),
            $start,
            $end
        );

        return $this->fetchYahoo($url);
    }

    /**
     * @return array<int, float|null>
     */
    private function fetchDailyPrices(string $ticker, int $days): array
    {
        $end = time();
        $start = $end - (int) ($days * 1.5 * 86400);

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1d',
            urlencode($ticker),
            $start,
            $end
        );

        $data = $this->fetchYahooRaw($url);
        $result = $data['chart']['result'][0] ?? null;
        if ($result === null) {
            return [];
        }

        return $result['indicators']['adjclose'][0]['adjclose']
            ?? $result['indicators']['quote'][0]['close']
            ?? [];
    }

    /**
     * @return array<string, float>
     */
    private function fetchYahoo(string $url): array
    {
        $data = $this->fetchYahooRaw($url);
        $result = $data['chart']['result'][0] ?? null;
        if ($result === null) {
            return [];
        }

        $timestamps = $result['timestamp'] ?? [];
        $closes = $result['indicators']['adjclose'][0]['adjclose']
            ?? $result['indicators']['quote'][0]['close']
            ?? [];

        $prices = [];
        foreach ($timestamps as $i => $ts) {
            if (isset($closes[$i]) && $closes[$i] !== null) {
                $prices[date('Y-m', (int) $ts)] = (float) $closes[$i];
            }
        }

        return $prices;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchYahooRaw(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => 'Mozilla/5.0'],
                'timeout' => 10,
            ]);

            return $response->toArray(false);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, float> $prices
     *
     * @return array<int, float>
     */
    private function monthlyReturns(array $prices): array
    {
        $values = array_values($prices);
        $returns = [];
        $count = count($values);
        for ($i = 1; $i < $count; $i++) {
            if ($values[$i - 1] > 0) {
                $returns[] = ($values[$i] - $values[$i - 1]) / $values[$i - 1];
            }
        }

        return $returns;
    }

    /**
     * @param array<int, float> $returns
     */
    private function momentumScore(array $returns): float
    {
        if (count($returns) < 7) {
            return -999.0;
        }

        $window = count($returns) >= 13
            ? array_slice($returns, -13, 12)
            : array_slice($returns, 0, -1);

        if (count($window) < 6) {
            return -999.0;
        }

        $mean = array_sum($window) / count($window);
        if ($mean <= 0) {
            return -999.0;
        }

        $variance = 0.0;
        foreach ($window as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $std = sqrt($variance / count($window));
        if ($std <= 0) {
            return -999.0;
        }

        return round($mean / $std, 3);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCache(): ?array
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        if ((time() - filemtime($this->cacheFile)) > self::CACHE_TTL) {
            return null;
        }

        $content = file_get_contents($this->cacheFile);
        if ($content === false) {
            return null;
        }

        return json_decode($content, true);
    }

    /**
     * @param array<string, mixed> $signal
     */
    private function saveCache(array $signal): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->cacheFile, json_encode($signal));
    }
}
