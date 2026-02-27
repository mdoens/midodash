<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\FredApiService;
use App\Service\IbClient;
use App\Service\PortfolioService;
use App\Service\SaxoClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class McpMomentumService
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * Mapping from Yahoo Finance momentum ticker to v8.0 portfolio position name.
     *
     * @var array<string, string>
     */
    private const TICKER_TO_PORTFOLIO = [
        'IWDA.AS' => 'NT World',
        'AVWC.DE' => 'AVWC',
        'IEMA.AS' => 'NT EM',
        'AVWS.DE' => 'AVWS',
        'IBGS.AS' => 'IBGS',
        'SGLD.L'  => 'EGLN',
        'XEON.DE' => 'XEON',
    ];

    public function __construct(
        private readonly FredApiService $fredApi,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly IbClient $ibClient,
        private readonly SaxoClient $saxoClient,
        private readonly PortfolioService $portfolioService,
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
                'AVWC.DE' => ['name' => 'Avantis Global Equity', 'target' => 0.15, 'type' => 'equity', 'platform' => 'IBKR', 'fbi' => false],
                'IEMA.AS' => ['name' => 'NT EM (proxy)', 'target' => 0.10, 'type' => 'equity', 'platform' => 'Saxo', 'fbi' => true, 'proxy' => 'IEMA.AS als proxy (zelfde index)', 'actual' => 'Northern Trust EM Custom ESG'],
                'AVWS.DE' => ['name' => 'Avantis Global SCV', 'target' => 0.10, 'type' => 'equity', 'platform' => 'IBKR', 'fbi' => false],
                'IBGS.AS' => ['name' => 'iShares EUR Govt 1-3yr', 'target' => 0.10, 'type' => 'fixed_income', 'platform' => 'IBKR', 'fbi' => false],
                'SGLD.L' => ['name' => 'Invesco Physical Gold', 'target' => 0.07, 'type' => 'alternatief', 'platform' => 'IBKR', 'fbi' => false],
                'XEON.DE' => ['name' => 'Xtrackers EUR Overnight', 'target' => 0.05, 'type' => 'cash', 'platform' => 'IBKR', 'fbi' => false],
            ],
            'bandwidths' => ['normaal' => 0.03, 'verruimd' => 0.05, 'maximaal' => 0.07],
            'regime' => ['vix_bear_threshold' => 30, 'vix_bull_threshold' => 25, 'sma_period' => 200],
            'crypto' => ['target' => 0.03, 'bandwidth' => 0.02],
        ];
    }

    /**
     * @return string|array<string, mixed>
     */
    public function generateReport(string $format = 'markdown', ?float $portfolioValue = null): string|array
    {
        $scores = $this->calculateMomentumScores();
        $regime = $this->checkRegime();
        $liveDrift = $this->fetchLiveDrift();
        $portfolioValue = $liveDrift['total_portfolio'] > 0
            ? $liveDrift['total_portfolio']
            : ($portfolioValue ?? (float) $this->config['portfolio_value']);

        $advice = $this->generateAdvice($scores, $regime, $portfolioValue, $liveDrift);

        if ($format === 'json') {
            return [
                'strategy_version' => 'v8.0',
                'date' => date('Y-m-d'),
                'portfolio_value' => $portfolioValue,
                'live_data_available' => $liveDrift['live'],
                'regime' => $regime,
                'momentum_scores' => $scores,
                'advice' => $advice,
                'positions' => $this->config['positions'],
            ];
        }

        return $this->formatMarkdown($scores, $regime, $advice, $portfolioValue);
    }

    /**
     * Fetch live positions from IB + Saxo and calculate drift per position.
     *
     * @return array{live: bool, total_portfolio: float, drifts: array<string, array{current_pct: float, drift_pct: float, value: float}>}
     */
    private function fetchLiveDrift(): array
    {
        $result = ['live' => false, 'total_portfolio' => 0.0, 'drifts' => []];

        try {
            $ibPositions = $this->ibClient->getPositions();
            $ibCash = $this->ibClient->getCashReport();
            $ibCashBalance = (float) ($ibCash['ending_cash'] ?? 0);

            $saxoPositions = null;
            $saxoCashBalance = 0.0;
            if ($this->saxoClient->isAuthenticated()) {
                $saxoPositions = $this->saxoClient->getPositions();
                $saxoBalance = $this->saxoClient->getAccountBalance();
                $saxoCashBalance = (float) ($saxoBalance['CashBalance'] ?? 0);
            }

            $allocation = $this->portfolioService->calculateAllocations(
                $ibPositions,
                $saxoPositions,
                $ibCashBalance,
                $saxoCashBalance,
            );

            $result['live'] = true;
            $result['total_portfolio'] = $allocation['total_portfolio'];

            foreach ($allocation['positions'] as $name => $pos) {
                $result['drifts'][$name] = [
                    'current_pct' => round((float) ($pos['current_pct'] ?? 0), 2),
                    'drift_pct' => round((float) ($pos['drift'] ?? 0), 2),
                    'value' => (float) ($pos['value'] ?? 0),
                ];
            }

            $this->logger->info('MCP Momentum: live drift data loaded', [
                'total' => $allocation['total_portfolio'],
                'positions' => count($result['drifts']),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('MCP Momentum: live drift fetch failed, using targets only', [
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Apply the 6 drift x momentum decision rules from spec 4.2c-e.
     *
     * Rules (absolute drift in percentage points):
     * 1. HARDCAP:            |drift| >= 7%  -> REBALANCE_VOLLEDIG (regardless of momentum)
     * 2. LARGE_DRIFT_NEG:    5% <= |drift| < 7%, momentum < 0 -> REBALANCE_VOLLEDIG
     * 3. LARGE_DRIFT_POS:    5% <= |drift| < 7%, momentum >= 0 -> REBALANCE_GEDEELTELIJK (50% of excess)
     * 4. MODERATE_DRIFT_NEG: 3% <= |drift| < 5%, momentum < 0 -> REBALANCE_VOLLEDIG
     * 5. MODERATE_DRIFT_POS: 3% <= |drift| < 5%, momentum >= 0 -> WACHT (momentum favorable)
     * 6. WITHIN_BAND:        |drift| < 3%  -> GEEN_ACTIE
     *
     * In BEAR_BEVESTIGD regime, bandwidths shift: normaal->verruimd, verruimd->maximaal.
     *
     * @return array{rule: string, action: string, rebalance_fraction: float, reason: string}
     */
    private function applyDriftMomentumRule(
        float $absDrift,
        float $driftPct,
        ?float $momentumScore,
        float $activeBandwidth,
        bool $fbi,
        string $type,
    ): array {
        $hardcapPct = 7.0;
        $largeDriftPct = 5.0;
        $moderateDriftPct = $activeBandwidth * 100;
        $isPositiveMomentum = ($momentumScore ?? 0) >= 0;

        if ($absDrift >= $hardcapPct) {
            return [
                'rule' => 'HARDCAP',
                'action' => 'REBALANCE_VOLLEDIG',
                'rebalance_fraction' => 1.0,
                'reason' => sprintf('Drift %.1f%% overschrijdt hardcap ±%.0f%% — volledig herbalanceren', $driftPct, $hardcapPct),
            ];
        }

        if ($absDrift >= $largeDriftPct) {
            if (!$isPositiveMomentum) {
                return [
                    'rule' => 'LARGE_DRIFT_NEG',
                    'action' => 'REBALANCE_VOLLEDIG',
                    'rebalance_fraction' => 1.0,
                    'reason' => sprintf('Grote drift %.1f%% + negatief momentum (%.3f) — volledig herbalanceren', $driftPct, $momentumScore ?? 0),
                ];
            }

            return [
                'rule' => 'LARGE_DRIFT_POS',
                'action' => 'REBALANCE_GEDEELTELIJK',
                'rebalance_fraction' => 0.5,
                'reason' => sprintf('Grote drift %.1f%% maar positief momentum (%.3f) — 50%% van excess herbalanceren', $driftPct, $momentumScore ?? 0),
            ];
        }

        if ($absDrift >= $moderateDriftPct) {
            if (!$isPositiveMomentum) {
                return [
                    'rule' => 'MODERATE_DRIFT_NEG',
                    'action' => 'REBALANCE_VOLLEDIG',
                    'rebalance_fraction' => 1.0,
                    'reason' => sprintf('Drift %.1f%% buiten band ±%.1f%% + negatief momentum (%.3f) — volledig herbalanceren', $driftPct, $moderateDriftPct, $momentumScore ?? 0),
                ];
            }

            return [
                'rule' => 'MODERATE_DRIFT_POS',
                'action' => 'WACHT',
                'rebalance_fraction' => 0.0,
                'reason' => sprintf('Drift %.1f%% buiten band ±%.1f%% maar positief momentum (%.3f) — afwachten', $driftPct, $moderateDriftPct, $momentumScore ?? 0),
            ];
        }

        return [
            'rule' => 'WITHIN_BAND',
            'action' => 'GEEN_ACTIE',
            'rebalance_fraction' => 0.0,
            'reason' => sprintf('Drift %.1f%% binnen band ±%.1f%% — geen actie nodig', $driftPct, $moderateDriftPct),
        ];
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
     * Generate advice using drift x momentum decision matrix (spec 4.2c-e).
     *
     * @param array<string, array<string, mixed>> $scores
     * @param array<string, mixed> $regime
     * @param array{live: bool, total_portfolio: float, drifts: array<string, array{current_pct: float, drift_pct: float, value: float}>} $liveDrift
     * @return array<string, mixed>
     */
    public function generateAdvice(array $scores, array $regime, float $portfolioValue, array $liveDrift = []): array
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
            'BULL' => sprintf('normaal +/-%s%%', round($bandwidths['normaal'] * 100, 0)),
            'BEAR' => sprintf('verruimd +/-%s%%', round($bandwidths['verruimd'] * 100, 0)),
            'BEAR_BEVESTIGD' => sprintf('maximaal +/-%s%%', round($bandwidths['maximaal'] * 100, 0)),
            default => sprintf('normaal +/-%s%%', round($bandwidths['normaal'] * 100, 0)),
        };

        $hasLiveData = !empty($liveDrift['live']);
        $advice = [];

        foreach ($this->config['positions'] as $ticker => $position) {
            $target = (float) $position['target'];
            $score = $scores[$ticker]['score'] ?? null;
            $type = $position['type'];
            $fbi = (bool) ($position['fbi'] ?? false);

            $portfolioName = self::TICKER_TO_PORTFOLIO[$ticker] ?? null;
            $driftPct = 0.0;
            $currentPct = $target * 100;
            $currentValue = $portfolioValue * $target;

            if ($hasLiveData && $portfolioName !== null && isset($liveDrift['drifts'][$portfolioName])) {
                $driftData = $liveDrift['drifts'][$portfolioName];
                $driftPct = $driftData['drift_pct'];
                $currentPct = $driftData['current_pct'];
                $currentValue = $driftData['value'];
            }

            $absDrift = abs($driftPct);
            $targetValue = (int) round($portfolioValue * $target);
            $excessValue = $currentValue - $targetValue;

            $rule = $this->applyDriftMomentumRule($absDrift, $driftPct, $score, $activeBandwidth, $fbi, $type);

            $sellAmount = 0;
            $buyAmount = 0;
            $destination = null;

            if ($rule['rebalance_fraction'] > 0 && abs($excessValue) > 100) {
                $rebalAmount = $excessValue * $rule['rebalance_fraction'];
                if ($rebalAmount > 0) {
                    $sellAmount = (int) round($rebalAmount);
                    $destination = $this->determineDestination($type, $regimeType);
                } else {
                    $buyAmount = (int) round(abs($rebalAmount));
                }
            }

            // FBI constraint: never buy FBI positions
            if ($fbi && $buyAmount > 0) {
                $buyAmount = 0;
                $rule['action'] = 'HOUDEN';
                $rule['rule'] .= '+FBI';
                $rule['reason'] .= ' | FBI-beperking: niet aankopen, alleen houden/verkopen';
            }

            // BEAR_BEVESTIGD regime override for equity: shift to defensive
            if ($regimeType === 'BEAR_BEVESTIGD' && $type === 'equity' && $rule['action'] === 'GEEN_ACTIE') {
                $rule['action'] = 'MONITOR';
                $rule['reason'] .= ' | Bear bevestigd: equity defensief monitoren';
            }

            $advice[$ticker] = [
                'ticker' => $ticker,
                'name' => $position['name'],
                'portfolio_name' => $portfolioName,
                'type' => $type,
                'platform' => $position['platform'],
                'fbi' => $fbi,
                'strategic_target' => round($target * 100, 1),
                'current_pct' => round($currentPct, 1),
                'target_value' => $targetValue,
                'current_value' => (int) round($currentValue),
                'drift_pct' => round($driftPct, 1),
                'rule_applied' => $rule['rule'],
                'action' => $rule['action'],
                'reason' => $rule['reason'],
                'bandwidth_active' => $bandwidthLabel,
                'bandwidth_exceeded' => $absDrift >= ($activeBandwidth * 100),
                'sell_amount' => $sellAmount,
                'buy_amount' => $buyAmount,
                'destination' => $destination,
                'momentum_score' => $score,
                'live_data' => $hasLiveData,
            ];
        }

        // Crypto entry (no momentum scoring, no live drift matching)
        $cryptoConfig = $this->config['crypto'];
        $cryptoTarget = (float) $cryptoConfig['target'];
        $cryptoBandwidth = (float) $cryptoConfig['bandwidth'];

        $cryptoDrift = 0.0;
        $cryptoCurrentPct = $cryptoTarget * 100;
        $cryptoValue = $portfolioValue * $cryptoTarget;
        if ($hasLiveData && isset($liveDrift['drifts']['Crypto'])) {
            $cryptoDrift = $liveDrift['drifts']['Crypto']['drift_pct'];
            $cryptoCurrentPct = $liveDrift['drifts']['Crypto']['current_pct'];
            $cryptoValue = $liveDrift['drifts']['Crypto']['value'];
        }

        $advice['CRYPTO'] = [
            'ticker' => 'BTC/WETH/SOL',
            'name' => 'Crypto ETP',
            'portfolio_name' => 'Crypto',
            'type' => 'crypto',
            'platform' => 'IBKR',
            'fbi' => false,
            'strategic_target' => round($cryptoTarget * 100, 1),
            'current_pct' => round($cryptoCurrentPct, 1),
            'target_value' => (int) round($portfolioValue * $cryptoTarget),
            'current_value' => (int) round($cryptoValue),
            'drift_pct' => round($cryptoDrift, 1),
            'rule_applied' => 'N/A',
            'action' => 'HOUDEN',
            'reason' => 'Geen momentum scoring — strategisch target handhaven',
            'bandwidth_active' => $bandwidthLabel,
            'bandwidth_exceeded' => abs($cryptoDrift) >= ($cryptoBandwidth * 100),
            'sell_amount' => 0,
            'buy_amount' => 0,
            'destination' => null,
            'momentum_score' => null,
            'live_data' => $hasLiveData,
        ];

        return [
            'bandwidth_regime' => $bandwidthLabel,
            'bandwidth_pct' => round($activeBandwidth * 100, 1),
            'live_data_available' => $hasLiveData,
            'positions' => $advice,
        ];
    }

    /**
     * Determine where proceeds from selling should go.
     */
    private function determineDestination(string $sourceType, string $regime): string
    {
        if ($regime === 'BEAR_BEVESTIGD') {
            return 'XEON (cash buffer — defensief protocol)';
        }

        return match ($sourceType) {
            'equity' => 'Ondergewogen equity positie of XEON',
            'fixed_income' => 'Ondergewogen equity positie',
            'alternatief' => 'XEON of ondergewogen positie',
            'cash' => 'Ondergewogen equity/FI positie',
            default => 'XEON',
        };
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

        $liveLabel = ($advice['live_data_available'] ?? false) ? 'LIVE' : 'ESTIMATED';

        $md = "# MIDO Momentum Rebalancing Report (v8.0)\n";
        $md .= "**Datum:** {$date}  \n";
        $md .= '**Portefeuille waarde:** €' . number_format($portfolioValue, 0, ',', '.') . " ({$liveLabel})\n\n";

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
        $md .= "| Bandwidth | **{$advice['bandwidth_regime']}** |\n";
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

        $md .= "## Drift x Momentum Beslismatrix\n\n";
        $md .= "| Ticker | Drift | Regel | Actie | Verkoop | Aankoop | Bestemming |\n";
        $md .= "|--------|------:|-------|-------|--------:|--------:|-----------|\n";

        foreach ($advice['positions'] as $pos) {
            $driftStr = sprintf('%+.1f%%', $pos['drift_pct']);
            $sellStr = $pos['sell_amount'] > 0 ? '€' . number_format($pos['sell_amount'], 0, ',', '.') : '—';
            $buyStr = $pos['buy_amount'] > 0 ? '€' . number_format($pos['buy_amount'], 0, ',', '.') : '—';
            $destStr = $pos['destination'] ?? '—';
            $fbiStr = $pos['fbi'] ? ' 🏛️' : '';
            $actionEmoji = match (true) {
                str_contains($pos['action'], 'REBALANCE') => '🔄',
                $pos['action'] === 'WACHT' => '⏳',
                $pos['action'] === 'MONITOR' => '👁️',
                $pos['action'] === 'HOUDEN' => '➡️',
                default => '✅',
            };

            $md .= "| {$pos['ticker']}{$fbiStr} | {$driftStr} | {$pos['rule_applied']} | {$actionEmoji} {$pos['action']} | {$sellStr} | {$buyStr} | {$destStr} |\n";
        }
        $md .= "\n";

        $md .= "## Herbalanceringsadvies\n\n";
        $md .= "| Ticker | Strat. % | Huidig % | Target EUR | Huidig EUR | Reden |\n";
        $md .= "|--------|--------:|---------:|---------:|---------:|-------|\n";

        foreach ($advice['positions'] as $pos) {
            $targetStr = '€' . number_format($pos['target_value'], 0, ',', '.');
            $currentStr = '€' . number_format($pos['current_value'], 0, ',', '.');
            $fbiStr = $pos['fbi'] ? ' 🏛️' : '';

            $md .= "| {$pos['ticker']}{$fbiStr} | {$pos['strategic_target']}% | {$pos['current_pct']}% | {$targetStr} | {$currentStr} | {$pos['reason']} |\n";
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
        $md .= "**Beslisregels:** HARDCAP (|drift|>=7%), LARGE_DRIFT (5-7%), MODERATE_DRIFT (band-5%), WITHIN_BAND (<band)  \n";
        $md .= "**Momentum richting:** _NEG = negatief momentum -> agressiever herbalanceren, _POS = positief -> afwachten  \n";
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
