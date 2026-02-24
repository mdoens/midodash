<?php

/**
 * ETF Momentum Rotatiestrategie — MIDO Holding B.V.
 * Berekent momentum scores, regime filter, en allocatie-signaal.
 */

const TICKERS = [
    'IWDA.AS' => ['name' => 'MSCI World',         'role' => 'Aandelen large cap', 'equity' => true],
    'SXRG.AS' => ['name' => 'Small Cap',           'role' => 'Aandelen small cap', 'equity' => true],
    'IEMA.AS' => ['name' => 'Emerging Markets',    'role' => 'Groei / dollar zwak', 'equity' => false],
    'IBCI.AS' => ['name' => 'Inflation Bonds',     'role' => 'Obligaties inflatie', 'equity' => false],
    'SGLD.L'  => ['name' => 'Physical Gold',       'role' => 'Crisis diversificator','equity' => false],
    'XEON.DE' => ['name' => '€STR Cash',           'role' => 'Cash equivalent',     'equity' => false],
];

/**
 * Haal historische maandprijzen op via Yahoo Finance.
 */
function fetchPrices(string $ticker, int $months = 26): array
{
    $end = time();
    $start = $end - ($months * 31 * 86400);

    $url = sprintf(
        'https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1mo',
        urlencode($ticker), $start, $end
    );

    $ctx = stream_context_create(['http' => [
        'header' => "User-Agent: Mozilla/5.0\r\n",
        'timeout' => 10,
    ]]);

    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return [];

    $data = json_decode($json, true);
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

/**
 * Haal dagelijkse prijzen op voor 200-daags gemiddelde.
 */
function fetchDailyPrices(string $ticker, int $days = 250): array
{
    $end = time();
    $start = $end - ($days * 1.5 * 86400);

    $url = sprintf(
        'https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1d',
        urlencode($ticker), $start, $end
    );

    $ctx = stream_context_create(['http' => [
        'header' => "User-Agent: Mozilla/5.0\r\n",
        'timeout' => 10,
    ]]);

    $json = @file_get_contents($url, false, $ctx);
    if (!$json) return [];

    $data = json_decode($json, true);
    $result = $data['chart']['result'][0] ?? null;
    if (!$result) return [];

    return $result['indicators']['adjclose'][0]['adjclose']
        ?? $result['indicators']['quote'][0]['close']
        ?? [];
}

/**
 * Bereken maandrendementen uit prijzen.
 */
function monthlyReturns(array $prices): array
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

/**
 * Bereken volatiliteit-gecorrigeerde momentum score.
 * Gebruik maanden t-13 tot t-1 (skip laatste maand).
 */
function momentumScore(array $returns): float
{
    // We need at least 13 returns (12 for scoring + 1 to skip)
    if (count($returns) < 13) {
        // Use what we have, skip last month
        $window = array_slice($returns, 0, -1);
    } else {
        // Use t-13 to t-1 (skip last month)
        $window = array_slice($returns, -13, 12);
    }

    if (count($window) < 6) return -999;

    $mean = array_sum($window) / count($window);

    // Absolute filter: negatief gemiddeld rendement = uitsluiten
    if ($mean <= 0) return -999;

    $variance = 0;
    foreach ($window as $r) {
        $variance += ($r - $mean) ** 2;
    }
    $std = sqrt($variance / count($window));

    if ($std <= 0) return -999;

    return $mean / $std;
}

/**
 * Check regime filter: staat IWDA boven zijn 200-daags gemiddelde?
 */
function regimeIsBull(): array
{
    $prices = fetchDailyPrices('IWDA.AS', 250);
    $prices = array_filter($prices, fn($p) => $p !== null);

    if (count($prices) < 200) {
        return ['bull' => true, 'price' => 0, 'ma200' => 0, 'error' => 'Onvoldoende data'];
    }

    $last = end($prices);
    $ma200 = array_sum(array_slice($prices, -200)) / 200;

    return [
        'bull'  => $last > $ma200,
        'price' => round($last, 2),
        'ma200' => round($ma200, 2),
    ];
}

/**
 * Bereken alle momentum scores.
 */
function getAllScores(): array
{
    $scores = [];
    foreach (TICKERS as $ticker => $info) {
        $prices = fetchPrices($ticker);
        $returns = monthlyReturns($prices);
        $score = momentumScore($returns);

        $values = array_values($prices);
        $lastPrice = end($values);
        $firstPrice = reset($values);
        $totalReturn = ($firstPrice > 0) ? (($lastPrice - $firstPrice) / $firstPrice) * 100 : 0;

        $scores[$ticker] = [
            'name'         => $info['name'],
            'role'         => $info['role'],
            'equity'       => $info['equity'],
            'score'        => $score,
            'last_price'   => $lastPrice,
            'total_return' => round($totalReturn, 1),
        ];
    }

    // Sorteer op score (hoogste eerst)
    uasort($scores, fn($a, $b) => $b['score'] <=> $a['score']);

    return $scores;
}

/**
 * Bepaal de maandelijkse allocatie op basis van momentum + regime.
 */
function getAllocation(): array
{
    $regime = regimeIsBull();
    $scores = getAllScores();

    // Bear regime: 100% cash
    if (!$regime['bull']) {
        return [
            'regime'     => $regime,
            'scores'     => $scores,
            'allocation' => ['XEON.DE' => 1.0],
            'reason'     => 'Bear regime — IWDA onder 200-daags gemiddelde → 100% cash',
        ];
    }

    // Filter positieve scores
    $positief = array_filter($scores, fn($s) => $s['score'] > 0);

    if (empty($positief)) {
        return [
            'regime'     => $regime,
            'scores'     => $scores,
            'allocation' => ['XEON.DE' => 1.0],
            'reason'     => 'Alle momentum scores negatief → 100% cash',
        ];
    }

    // Selecteer top 2, maar nooit 2 aandelenETFs tegelijk
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

    // Als maar 1 positief: 50% in #1, 50% cash
    if (count($top2) === 1) {
        return [
            'regime'     => $regime,
            'scores'     => $scores,
            'allocation' => [$top2[0] => 0.5, 'XEON.DE' => 0.5],
            'reason'     => 'Slechts 1 ETF positief → 50/50 met cash',
        ];
    }

    $weight = 1.0 / count($top2);
    $allocation = [];
    foreach ($top2 as $t) {
        $allocation[$t] = $weight;
    }

    return [
        'regime'     => $regime,
        'scores'     => $scores,
        'allocation' => $allocation,
        'reason'     => 'Top 2 momentum ETFs geselecteerd',
    ];
}
