<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\FredApiService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class McpMomentumService
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(
        private readonly FredApiService $fredApi,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $configFile = $this->projectDir . '/config/mido_v65.yaml';
        if (file_exists($configFile)) {
            $yaml = Yaml::parseFile($configFile);
            $this->config = $yaml['mido']['momentum'] ?? $this->getDefaultConfig();
        } else {
            $this->config = $this->getDefaultConfig();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            'portfolio_value' => 1_800_000,
            'positions' => [
                'IWDA.AS' => ['name' => 'NT World (proxy)', 'target' => 0.40, 'type' => 'equity', 'platform' => 'Saxo', 'fbi' => true, 'proxy' => 'IWDA.AS als proxy (zelfde index)', 'actual' => 'Northern Trust World Custom ESG'],
                'AVWC.L' => ['name' => 'Avantis Global Equity', 'target' => 0.15, 'type' => 'equity', 'platform' => 'IBKR', 'fbi' => false],
                'IEMA.AS' => ['name' => 'NT EM (proxy)', 'target' => 0.10, 'type' => 'equity', 'platform' => 'Saxo', 'fbi' => true, 'proxy' => 'IEMA.AS als proxy (zelfde index)', 'actual' => 'Northern Trust EM Custom ESG'],
                'AVWS.L' => ['name' => 'Avantis Global SCV', 'target' => 0.10, 'type' => 'equity', 'platform' => 'IBKR', 'fbi' => false],
                'IBGS.AS' => ['name' => 'iShares EUR Govt 1-3yr', 'target' => 0.10, 'type' => 'fixed_income', 'platform' => 'IBKR', 'fbi' => false],
                'SGLD.L' => ['name' => 'Invesco Physical Gold', 'target' => 0.07, 'type' => 'alternatief', 'platform' => 'IBKR', 'fbi' => false],
                'XEON.DE' => ['name' => 'Xtrackers EUR Overnight', 'target' => 0.05, 'type' => 'cash', 'platform' => 'IBKR', 'fbi' => false],
            ],
            'bandwidths' => ['normaal' => 0.03, 'verruimd' => 0.05, 'maximaal' => 0.07],
            'regime' => ['vix_bear_threshold' => 30, 'vix_bull_threshold' => 25, 'sma_period' => 200],
            'crypto' => ['target' => 0.03, 'bandwidth' => 0.02],
        ];
    }

    public function generateReport(string $format = 'markdown', ?float $portfolioValue = null): string|array
    {
        $portfolioValue = $portfolioValue ?? (float) $this->config['portfolio_value'];
        $scores = $this->calculateMomentumScores();
        $regime = $this->checkRegime();
        $advice = $this->generateAdvice($scores, $regime, $portfolioValue);

        if ($format === 'json') {
            return [
                'strategy_version' => 'v8.0',
                'date' => date('Y-m-d'),
                'portfolio_value' => $portfolioValue,
                'regime' => $regime,
                'momentum_scores' => $scores,
                'advice' => $advice,
                'positions' => $this->config['positions'],
            ];
        }

        return $this->formatMarkdown($scores, $regime, $advice, $portfolioValue);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function calculateMomentumScores(): array
    {
        $scores = [];

        foreach ($this->config['positions'] as $ticker => $position) {
            $history = $this->fetchMonthlyHistory($ticker, 26);

            if (count($history) < 14) {
                $scores[$ticker] = [
                    'ticker' => $ticker,
                    'name' => $position['name'],
                    'score' => null,
                    'error' => 'Insufficient data (' . count($history) . ' months)',
                    'type' => $position['type'],
                ];
                continue;
            }

            $returns = [];
            $count = count($history);
            for ($i = 1; $i < $count; $i++) {
                if ($history[$i - 1]['close'] > 0) {
                    $returns[] = ($history[$i]['close'] - $history[$i - 1]['close']) / $history[$i - 1]['close'];
                }
            }

            if (count($returns) < 13) {
                $scores[$ticker] = [
                    'ticker' => $ticker,
                    'name' => $position['name'],
                    'score' => null,
                    'error' => 'Insufficient return data',
                    'type' => $position['type'],
                ];
                continue;
            }

            array_pop($returns);
            $momentumReturns = array_slice($returns, -12);

            $mean = array_sum($momentumReturns) / count($momentumReturns);
            $variance = 0.0;
            foreach ($momentumReturns as $r) {
                $variance += ($r - $mean) ** 2;
            }
            $stdev = sqrt($variance / count($momentumReturns));

            $score = $stdev > 0 ? $mean / $stdev : 0.0;

            $scoreEntry = [
                'ticker' => $ticker,
                'name' => $position['name'],
                'score' => round($score, 3),
                'mean_return' => round($mean * 100, 2),
                'volatility' => round($stdev * 100, 2),
                'type' => $position['type'],
                'months_used' => count($momentumReturns),
                'latest_price' => $history[count($history) - 1]['close'],
            ];

            if (!empty($position['proxy'])) {
                $scoreEntry['proxy'] = $position['proxy'];
                $scoreEntry['actual'] = $position['actual'] ?? null;
            }

            $scores[$ticker] = $scoreEntry;
        }

        return $scores;
    }

    /**
     * @return array<string, mixed>
     */
    public function checkRegime(): array
    {
        $regimeConfig = $this->config['regime'];
        $smaPeriod = (int) $regimeConfig['sma_period'];

        $dailyData = $this->fetchDailyHistory('IWDA.AS', (int) ($smaPeriod * 1.5));

        $currentPrice = null;
        $sma200 = null;
        $priceAboveSma = null;

        if (count($dailyData) >= $smaPeriod) {
            $currentPrice = $dailyData[count($dailyData) - 1]['close'];
            $smaSlice = array_slice($dailyData, -$smaPeriod);
            $sma200 = array_sum(array_column($smaSlice, 'close')) / count($smaSlice);
            $priceAboveSma = $currentPrice > $sma200;
        }

        $vixData = $this->fredApi->getLatestValue('VIXCLS');
        $vix = $vixData['value'] ?? null;

        $regime = 'ONBEKEND';
        if ($priceAboveSma !== null && $vix !== null) {
            if ($priceAboveSma && $vix < $regimeConfig['vix_bull_threshold']) {
                $regime = 'BULL';
            } elseif (!$priceAboveSma && $vix > $regimeConfig['vix_bear_threshold']) {
                $regime = 'BEAR_BEVESTIGD';
            } else {
                $regime = 'BEAR';
            }
        }

        return [
            'regime' => $regime,
            'iwda_price' => $currentPrice !== null ? round($currentPrice, 2) : null,
            'sma200' => $sma200 !== null ? round($sma200, 2) : null,
            'price_vs_sma' => ($priceAboveSma !== null && $sma200 > 0)
                ? round((($currentPrice - $sma200) / $sma200) * 100, 2)
                : null,
            'vix' => $vix,
            'vix_date' => $vixData['date'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateAdvice(array $scores, array $regime, float $portfolioValue): array
    {
        $regimeType = $regime['regime'];
        $bandwidths = $this->config['bandwidths'];

        $activeBandwidth = match ($regimeType) {
            'BULL' => (float) $bandwidths['normaal'],
            'BEAR' => (float) $bandwidths['verruimd'],
            'BEAR_BEVESTIGD' => (float) $bandwidths['maximaal'],
            default => (float) $bandwidths['normaal'],
        };

        $bandwidthLabel = match ($regimeType) {
            'BULL' => 'normaal',
            'BEAR' => 'verruimd',
            'BEAR_BEVESTIGD' => 'maximaal',
            default => 'normaal',
        };

        $advice = [];

        foreach ($this->config['positions'] as $ticker => $position) {
            $target = (float) $position['target'];
            $score = $scores[$ticker]['score'] ?? null;
            $type = $position['type'];
            $fbi = (bool) ($position['fbi'] ?? false);

            $adjustedTarget = $target;
            $action = 'HOUDEN';
            $reason = '';

            if ($score !== null) {
                if ($regimeType === 'BEAR_BEVESTIGD') {
                    if ($type === 'equity') {
                        $adjustedTarget = max($target - $activeBandwidth, 0.0);
                        $action = 'VERLAGEN';
                        $reason = 'Defensief protocol actief — equity verlagen';
                    } elseif (in_array($type, ['cash', 'fixed_income'], true)) {
                        $adjustedTarget = $target + ($activeBandwidth / 2);
                        $action = 'VERHOGEN';
                        $reason = 'Defensief protocol — veilige havens verhogen';
                    }
                } elseif ($regimeType === 'BEAR') {
                    if ($type === 'equity' && $score < 0) {
                        $adjustedTarget = max($target - ($activeBandwidth / 2), 0.0);
                        $action = 'VERLAGEN';
                        $reason = 'Negatief momentum in bear regime';
                    } elseif ($type === 'equity' && $score > 0) {
                        $action = 'HOUDEN';
                        $reason = 'Positief momentum — target handhaven';
                    }
                } else {
                    if ($type === 'equity' && $score > 0.5) {
                        $adjustedTarget = min($target + ($activeBandwidth / 2), 1.0);
                        $action = 'VERHOGEN';
                        $reason = 'Sterk positief momentum in bull markt';
                    } elseif ($type === 'equity' && $score < -0.3) {
                        $adjustedTarget = max($target - ($activeBandwidth / 2), 0.0);
                        $action = 'VERLAGEN';
                        $reason = 'Negatief momentum ondanks bull markt';
                    } else {
                        $reason = 'Momentum neutraal — target handhaven';
                    }
                }
            } else {
                $reason = 'Geen momentum score beschikbaar';
            }

            if ($fbi && $action === 'VERHOGEN') {
                $reason .= ' (Let op: FBI-beperking — niet aankopen, alleen houden/verkopen)';
                $adjustedTarget = min($adjustedTarget, $target);
                $action = $adjustedTarget < $target ? 'VERLAGEN' : 'HOUDEN';
            }

            $targetValue = (int) round($portfolioValue * $adjustedTarget);
            $lowerBound = (int) round($portfolioValue * max($adjustedTarget - $activeBandwidth, 0.0));
            $upperBound = (int) round($portfolioValue * min($adjustedTarget + $activeBandwidth, 1.0));

            $advice[$ticker] = [
                'ticker' => $ticker,
                'name' => $position['name'],
                'type' => $type,
                'platform' => $position['platform'],
                'fbi' => $fbi,
                'strategic_target' => round($target * 100, 1),
                'adjusted_target' => round($adjustedTarget * 100, 1),
                'target_value' => $targetValue,
                'lower_bound' => $lowerBound,
                'upper_bound' => $upperBound,
                'action' => $action,
                'reason' => $reason,
                'momentum_score' => $score,
            ];
        }

        $cryptoConfig = $this->config['crypto'];
        $cryptoTarget = (float) $cryptoConfig['target'];
        $cryptoBandwidth = (float) $cryptoConfig['bandwidth'];

        $advice['CRYPTO'] = [
            'ticker' => 'BTC/WETH/SOL',
            'name' => 'Crypto ETP',
            'type' => 'crypto',
            'platform' => 'IBKR',
            'fbi' => false,
            'strategic_target' => round($cryptoTarget * 100, 1),
            'adjusted_target' => round($cryptoTarget * 100, 1),
            'target_value' => (int) round($portfolioValue * $cryptoTarget),
            'lower_bound' => (int) round($portfolioValue * max($cryptoTarget - $cryptoBandwidth, 0.0)),
            'upper_bound' => (int) round($portfolioValue * ($cryptoTarget + $cryptoBandwidth)),
            'action' => 'HOUDEN',
            'reason' => 'Geen momentum scoring — strategisch target handhaven',
            'momentum_score' => null,
        ];

        return [
            'bandwidth_regime' => $bandwidthLabel,
            'bandwidth_pct' => round($activeBandwidth * 100, 1),
            'positions' => $advice,
        ];
    }

    private function formatMarkdown(array $scores, array $regime, array $advice, float $portfolioValue): string
    {
        $date = date('d-m-Y');
        $regimeEmoji = match ($regime['regime']) {
            'BULL' => '🟢',
            'BEAR' => '🟡',
            'BEAR_BEVESTIGD' => '🔴',
            default => '⚪',
        };

        $md = "# MIDO Momentum Rebalancing Report (v8.0)\n";
        $md .= "**Datum:** {$date}  \n";
        $md .= '**Portefeuille waarde:** €' . number_format($portfolioValue, 0, ',', '.') . "\n\n";

        $md .= "## Marktregime\n\n";
        $md .= "| Indicator | Waarde |\n";
        $md .= "|-----------|--------|\n";
        $md .= "| Regime | {$regimeEmoji} **{$regime['regime']}** |\n";
        if ($regime['iwda_price'] !== null) {
            $md .= "| IWDA.AS | €{$regime['iwda_price']} |\n";
            $md .= "| SMA200 | €{$regime['sma200']} ({$regime['price_vs_sma']}%) |\n";
        }
        if ($regime['vix'] !== null) {
            $md .= "| VIX | {$regime['vix']} ({$regime['vix_date']}) |\n";
        }
        $md .= "| Bandwidth | **{$advice['bandwidth_regime']}** ({$advice['bandwidth_pct']}%) |\n";
        $md .= "\n";

        $md .= "## Momentum Scores\n\n";
        $md .= "| Ticker | Naam | Score | Gem. Return | Volatiliteit | Type |\n";
        $md .= "|--------|------|------:|------------:|-------------:|------|\n";

        foreach ($scores as $s) {
            if ($s['score'] !== null) {
                $scoreStr = ($s['score'] >= 0 ? '+' : '') . number_format($s['score'], 3);
                $returnStr = ($s['mean_return'] >= 0 ? '+' : '') . number_format($s['mean_return'], 2) . '%';
                $volStr = number_format($s['volatility'], 2) . '%';
                $md .= "| {$s['ticker']} | {$s['name']} | {$scoreStr} | {$returnStr} | {$volStr} | {$s['type']} |\n";
            } else {
                $md .= "| {$s['ticker']} | {$s['name']} | — | — | — | {$s['type']} |\n";
            }
        }
        $md .= "\n";

        $md .= "## Herbalanceringsadvies\n\n";
        $md .= "| Ticker | Actie | Strat. % | Adj. % | Target EUR | Bandbreedte EUR | Reden |\n";
        $md .= "|--------|-------|--------:|---------:|---------:|--------------:|-------|\n";

        foreach ($advice['positions'] as $pos) {
            $actionEmoji = match ($pos['action']) {
                'VERHOGEN' => '🔼',
                'VERLAGEN' => '🔽',
                'HOUDEN' => '➡️',
                default => '',
            };
            $targetStr = '€' . number_format($pos['target_value'], 0, ',', '.');
            $bandStr = '€' . number_format($pos['lower_bound'], 0, ',', '.') . ' – €' . number_format($pos['upper_bound'], 0, ',', '.');
            $fbiStr = $pos['fbi'] ? ' 🏛️' : '';

            $md .= "| {$pos['ticker']}{$fbiStr} | {$actionEmoji} {$pos['action']} | {$pos['strategic_target']}% | {$pos['adjusted_target']}% | {$targetStr} | {$bandStr} | {$pos['reason']} |\n";
        }
        $md .= "\n";

        $proxies = [];
        foreach ($this->config['positions'] as $ticker => $position) {
            if (!empty($position['proxy'])) {
                $actual = $position['actual'] ?? $ticker;
                $proxies[] = "- **{$ticker}**: {$position['proxy']} — werkelijk fonds: {$actual}";
            }
        }

        if ($proxies !== []) {
            $md .= "## Data proxies\n\n";
            $md .= "Sommige fondsen zijn niet beschikbaar op Yahoo Finance. We gebruiken vergelijkbare tickers als proxy voor de momentum-berekening:\n\n";
            $md .= implode("\n", $proxies) . "\n\n";
        }

        $md .= "---\n";
        if (array_filter(array_column($this->config['positions'], 'fbi'))) {
            $md .= "🏛️ = FBI-beperking (niet aankopen, alleen houden/verkopen)  \n";
        }
        $md .= "Score = volatiliteits-gecorrigeerd momentum (12M, skip recentste maand)  \n";
        $md .= "Crypto (BTC/WETH/SOL) niet opgenomen in momentum scoring\n";

        return $md;
    }

    /**
     * @return list<array{close: float}>
     */
    private function fetchMonthlyHistory(string $ticker, int $months): array
    {
        $end = time();
        $start = $end - ($months * 31 * 86400);

        try {
            $url = sprintf(
                'https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1mo',
                urlencode($ticker),
                $start,
                $end,
            );

            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => 'Mozilla/5.0'],
                'timeout' => 15,
            ]);

            $result = $response->toArray(false)['chart']['result'][0] ?? null;
            if ($result === null) {
                return [];
            }

            $closes = $result['indicators']['adjclose'][0]['adjclose']
                ?? $result['indicators']['quote'][0]['close']
                ?? [];

            $history = [];
            foreach ($closes as $close) {
                if ($close !== null) {
                    $history[] = ['close' => (float) $close];
                }
            }

            return $history;
        } catch (\Throwable $e) {
            $this->logger->warning('MCP Momentum: Yahoo monthly fetch failed', ['ticker' => $ticker, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<array{close: float}>
     */
    private function fetchDailyHistory(string $ticker, int $days): array
    {
        $end = time();
        $start = $end - (int) ($days * 1.5 * 86400);

        try {
            $url = sprintf(
                'https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1d',
                urlencode($ticker),
                $start,
                $end,
            );

            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => 'Mozilla/5.0'],
                'timeout' => 15,
            ]);

            $result = $response->toArray(false)['chart']['result'][0] ?? null;
            if ($result === null) {
                return [];
            }

            $closes = $result['indicators']['adjclose'][0]['adjclose']
                ?? $result['indicators']['quote'][0]['close']
                ?? [];

            $history = [];
            foreach ($closes as $close) {
                if ($close !== null) {
                    $history[] = ['close' => (float) $close];
                }
            }

            return $history;
        } catch (\Throwable $e) {
            $this->logger->warning('MCP Momentum: Yahoo daily fetch failed', ['ticker' => $ticker, 'error' => $e->getMessage()]);

            return [];
        }
    }
}
