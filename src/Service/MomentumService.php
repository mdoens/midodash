<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class MomentumService
{
    public const TICKERS = [
        'IWDA.AS' => ['name' => 'MSCI World', 'role' => 'Aandelen large cap', 'equity' => true],
        'SXRG.AS' => ['name' => 'Small Cap', 'role' => 'Aandelen small cap', 'equity' => true],
        'IEMA.AS' => ['name' => 'Emerging Markets', 'role' => 'Groei / dollar zwak', 'equity' => false],
        'IBCI.AS' => ['name' => 'Inflation Bonds', 'role' => 'Obligaties inflatie', 'equity' => false],
        'SGLD.L'  => ['name' => 'Physical Gold', 'role' => 'Crisis diversificator', 'equity' => false],
        'XEON.DE' => ['name' => '€STR Cash', 'role' => 'Cash equivalent', 'equity' => false],
    ];

    public function __construct(private HttpClientInterface $httpClient)
    {
    }

    public function getSignal(): array
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

        $positief = array_filter($scores, fn($s) => $s['score'] > 0);

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
                if ($seenEquity) continue;
                $seenEquity = true;
            }
            $top2[] = $ticker;
            if (count($top2) >= 2) break;
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
                'score' => $score,
                'last_price' => $lastPrice,
            ];
        }

        uasort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        return $scores;
    }

    public function checkRegime(): array
    {
        $prices = $this->fetchDailyPrices('IWDA.AS', 300);
        $prices = array_filter($prices, fn($p) => $p !== null && $p > 0);

        if (count($prices) < 200) {
            return ['bull' => true, 'price' => 0, 'ma200' => 0, 'error' => 'Onvoldoende data'];
        }

        $last = end($prices);
        $ma200 = array_sum(array_slice($prices, -200)) / 200;

        return [
            'bull' => $last > $ma200,
            'price' => round($last, 2),
            'ma200' => round($ma200, 2),
        ];
    }

    private function fetchMonthlyPrices(string $ticker): array
    {
        $end = time();
        $start = $end - (26 * 31 * 86400);

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1mo',
            urlencode($ticker), $start, $end
        );

        return $this->fetchYahoo($url);
    }

    private function fetchDailyPrices(string $ticker, int $days): array
    {
        $end = time();
        $start = $end - (int)($days * 1.5 * 86400);

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1d',
            urlencode($ticker), $start, $end
        );

        $data = $this->fetchYahooRaw($url);
        $result = $data['chart']['result'][0] ?? null;
        if (!$result) return [];

        return $result['indicators']['adjclose'][0]['adjclose']
            ?? $result['indicators']['quote'][0]['close']
            ?? [];
    }

    private function fetchYahoo(string $url): array
    {
        $data = $this->fetchYahooRaw($url);
        $result = $data['chart']['result'][0] ?? null;
        if (!$result) return [];

        $timestamps = $result['timestamp'] ?? [];
        $closes = $result['indicators']['adjclose'][0]['adjclose']
            ?? $result['indicators']['quote'][0]['close']
            ?? [];

        $prices = [];
        foreach ($timestamps as $i => $ts) {
            if (isset($closes[$i]) && $closes[$i] !== null) {
                $prices[date('Y-m', $ts)] = (float)$closes[$i];
            }
        }
        return $prices;
    }

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

    private function monthlyReturns(array $prices): array
    {
        $values = array_values($prices);
        $returns = [];
        for ($i = 1; $i < count($values); $i++) {
            if ($values[$i - 1] > 0) {
                $returns[] = ($values[$i] - $values[$i - 1]) / $values[$i - 1];
            }
        }
        return $returns;
    }

    private function momentumScore(array $returns): float
    {
        if (count($returns) < 7) return -999;

        $window = count($returns) >= 13
            ? array_slice($returns, -13, 12)
            : array_slice($returns, 0, -1);

        if (count($window) < 6) return -999;

        $mean = array_sum($window) / count($window);
        if ($mean <= 0) return -999;

        $variance = 0;
        foreach ($window as $r) {
            $variance += ($r - $mean) ** 2;
        }
        $std = sqrt($variance / count($window));
        if ($std <= 0) return -999;

        return round($mean / $std, 3);
    }
}
