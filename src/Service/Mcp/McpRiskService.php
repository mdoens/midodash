<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Repository\PortfolioSnapshotRepository;
use App\Service\CrisisService;
use App\Service\DataBufferService;
use App\Service\FredApiService;
use App\Service\PortfolioService;
use App\Service\SaxoClient;
use Psr\Log\LoggerInterface;

class McpRiskService
{
    public function __construct(
        private readonly PortfolioSnapshotRepository $snapshotRepo,
        private readonly FredApiService $fredApi,
        private readonly SaxoClient $saxoClient,
        private readonly PortfolioService $portfolioService,
        private readonly CrisisService $crisisService,
        private readonly DataBufferService $dataBuffer,
        private readonly McpPortfolioService $portfolioMcp,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getRiskMetrics(string $format, string $period): string|array
    {
        $days = $this->periodToDays($period);
        $snapshots = $this->snapshotRepo->findLastDays($days);

        if (count($snapshots) < 5) {
            return ['error' => true, 'message' => "Insufficient data: need at least 5 snapshots, found " . count($snapshots) . ". Available from: " . ($snapshots !== [] ? $snapshots[0]->getDate()->format('Y-m-d') : 'none')];
        }

        $values = array_map(fn($s): float => (float) $s->getTotalValue(), $snapshots);
        $returns = $this->calculateDailyReturns($values);

        $volatility = $this->standardDeviation($returns) * sqrt(252);
        $annualReturn = $this->annualizedReturn($values, count($snapshots));

        $riskFreeRate = 0.0;
        try {
            $tbill = $this->fredApi->getLatestValue('DTB3');
            $riskFreeRate = $tbill !== null ? (float) $tbill / 100 : 0.0;
        } catch (\Throwable) {
        }

        $sharpe = $volatility > 0 ? ($annualReturn - $riskFreeRate) / $volatility : 0.0;

        $downsideReturns = array_filter($returns, fn(float $r): bool => $r < 0);
        $downsideVol = count($downsideReturns) > 1
            ? $this->standardDeviation(array_values($downsideReturns)) * sqrt(252)
            : 0.0;
        $sortino = $downsideVol > 0 ? ($annualReturn - $riskFreeRate) / $downsideVol : 0.0;

        $maxDrawdown = $this->calculateMaxDrawdown($values);

        $var95 = $this->calculateVaR($returns, 0.05);
        $cvar95 = $this->calculateCVaR($returns, 0.05);

        $saxoMetrics = null;
        try {
            $saxoMetrics = $this->saxoClient->getPerformanceMetrics();
        } catch (\Throwable) {
        }

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'period' => $period,
            'data_points' => count($snapshots),
            'date_range' => [
                'from' => $snapshots[0]->getDate()->format('Y-m-d'),
                'to' => $snapshots[count($snapshots) - 1]->getDate()->format('Y-m-d'),
            ],
            'metrics' => [
                'annualized_return' => round($annualReturn * 100, 2),
                'annualized_volatility' => round($volatility * 100, 2),
                'sharpe_ratio' => round($sharpe, 3),
                'sortino_ratio' => round($sortino, 3),
                'max_drawdown_pct' => round($maxDrawdown * 100, 2),
                'var_95_daily' => round($var95 * 100, 3),
                'cvar_95_daily' => round($cvar95 * 100, 3),
                'risk_free_rate' => round($riskFreeRate * 100, 2),
            ],
            'saxo_cross_check' => $saxoMetrics !== null ? [
                'twr' => round((float) $saxoMetrics['twr'] * 100, 2),
                'sharpe' => round((float) $saxoMetrics['sharpe_ratio'], 3),
                'sortino' => round((float) $saxoMetrics['sortino_ratio'], 3),
                'max_drawdown' => round((float) $saxoMetrics['max_drawdown'] * 100, 2),
            ] : null,
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatRiskMarkdown($data);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getStressTest(string $format, string $scenario, ?string $customShocks): string|array
    {
        $allocation = $this->portfolioMcp->fetchLiveAllocation();
        $totalPortfolio = $allocation['total_portfolio'];

        $scenarios = $this->getPresetScenarios();
        if ($scenario === 'custom' && $customShocks !== null) {
            $decoded = json_decode($customShocks, true);
            if (is_array($decoded)) {
                $scenarios['custom'] = [
                    'name' => 'Custom Scenario',
                    'description' => 'User-defined shocks',
                    'shocks' => $decoded,
                ];
            }
        }

        if (!isset($scenarios[$scenario])) {
            return ['error' => true, 'message' => "Unknown scenario: {$scenario}. Available: " . implode(', ', array_keys($scenarios))];
        }

        $selectedScenario = $scenarios[$scenario];
        $shocks = $selectedScenario['shocks'];

        $results = [];
        $totalImpact = 0.0;

        foreach ($allocation['positions'] as $name => $pos) {
            $value = (float) $pos['value'];
            $assetClass = $pos['asset_class'];

            $shock = $shocks[$name] ?? $shocks[$assetClass] ?? 0.0;
            $impact = $value * ($shock / 100);
            $totalImpact += $impact;

            $results[] = [
                'position' => $name,
                'current_value' => round($value, 2),
                'shock_pct' => $shock,
                'impact' => round($impact, 2),
                'stressed_value' => round($value + $impact, 2),
            ];
        }

        $cashImpact = 0.0;
        if (isset($shocks['cash'])) {
            $cashImpact = $allocation['total_cash'] * ($shocks['cash'] / 100);
            $totalImpact += $cashImpact;
        }

        $stressedTotal = $totalPortfolio + $totalImpact;
        $drawdownPct = $totalPortfolio > 0 ? ($totalImpact / $totalPortfolio) * 100 : 0.0;

        $crisisSignals = $this->crisisService->checkAllSignals();
        $wouldTriggerCrisis = $drawdownPct <= -20;

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'scenario' => [
                'id' => $scenario,
                'name' => $selectedScenario['name'],
                'description' => $selectedScenario['description'],
            ],
            'current_portfolio' => round($totalPortfolio, 2),
            'stressed_portfolio' => round($stressedTotal, 2),
            'total_impact' => round($totalImpact, 2),
            'drawdown_pct' => round($drawdownPct, 2),
            'position_impacts' => $results,
            'crisis_protocol' => [
                'would_trigger' => $wouldTriggerCrisis,
                'current_signals_active' => $crisisSignals['active_signals'],
                'note' => $wouldTriggerCrisis ? 'Crisis protocol zou activeren bij dit scenario' : 'Binnen normale parameters',
            ],
            'available_scenarios' => array_keys($scenarios),
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatStressMarkdown($data);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getCurrencyExposure(string $format): string|array
    {
        $saxoExposure = null;
        try {
            $saxoExposure = $this->saxoClient->getCurrencyExposure();
        } catch (\Throwable $e) {
            $this->logger->debug('Saxo currency exposure unavailable', ['error' => $e->getMessage()]);
            $buffered = $this->dataBuffer->retrieve('saxo', 'currency_exposure');
            if ($buffered !== null) {
                $saxoExposure = $buffered['data'];
            }
        }

        $allocation = $this->portfolioMcp->fetchLiveAllocation();
        $totalPortfolio = $allocation['total_portfolio'];

        $currencyBreakdown = [];
        if ($saxoExposure !== null) {
            foreach ($saxoExposure as $item) {
                $currency = $item['currency'];
                if (!isset($currencyBreakdown[$currency])) {
                    $currencyBreakdown[$currency] = ['amount' => 0.0, 'source' => 'Saxo'];
                }
                $currencyBreakdown[$currency]['amount'] += (float) $item['amount_base'];
            }
        }

        foreach ($allocation['positions'] as $pos) {
            if ($pos['platform'] === 'IBKR' && $pos['value'] > 0) {
                $currency = $this->inferCurrency($pos['ticker'] ?? '', $pos['asset_class'] ?? '');
                if (!isset($currencyBreakdown[$currency])) {
                    $currencyBreakdown[$currency] = ['amount' => 0.0, 'source' => 'estimated'];
                }
                $currencyBreakdown[$currency]['amount'] += (float) $pos['value'];
            }
        }

        $exposure = [];
        foreach ($currencyBreakdown as $currency => $info) {
            $pct = $totalPortfolio > 0 ? ($info['amount'] / $totalPortfolio) * 100 : 0;
            $exposure[] = [
                'currency' => $currency,
                'amount' => round($info['amount'], 2),
                'pct' => round($pct, 2),
                'source' => $info['source'],
            ];
        }

        usort($exposure, fn(array $a, array $b): int => (int) (($b['amount'] * 100) - ($a['amount'] * 100)));

        $geoTargets = $this->portfolioService->getGeoTargets();

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'data_freshness' => $saxoExposure !== null ? 'live' : 'estimated',
            'total_portfolio' => round($totalPortfolio, 2),
            'exposure' => $exposure,
            'geo_targets' => $geoTargets,
            'eur_pct' => round(array_sum(array_map(
                fn(array $e): float => $e['currency'] === 'EUR' ? $e['pct'] : 0,
                $exposure,
            )), 2),
            'non_eur_pct' => round(array_sum(array_map(
                fn(array $e): float => $e['currency'] !== 'EUR' ? $e['pct'] : 0,
                $exposure,
            )), 2),
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatCurrencyMarkdown($data);
    }

    /**
     * @param list<float> $values
     * @return list<float>
     */
    private function calculateDailyReturns(array $values): array
    {
        $returns = [];
        for ($i = 1, $count = count($values); $i < $count; $i++) {
            if ($values[$i - 1] > 0) {
                $returns[] = ($values[$i] - $values[$i - 1]) / $values[$i - 1];
            }
        }

        return $returns;
    }

    /**
     * @param list<float> $values
     */
    private function standardDeviation(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = array_sum($values) / $n;
        $sumSquaredDiff = 0.0;
        foreach ($values as $v) {
            $sumSquaredDiff += ($v - $mean) ** 2;
        }

        return sqrt($sumSquaredDiff / ($n - 1));
    }

    /**
     * @param list<float> $values
     */
    private function annualizedReturn(array $values, int $days): float
    {
        if ($days < 2 || $values[0] <= 0) {
            return 0.0;
        }

        $totalReturn = ($values[count($values) - 1] / $values[0]) - 1;
        $years = $days / 365.25;

        return $years > 0 ? (1 + $totalReturn) ** (1 / $years) - 1 : $totalReturn;
    }

    /**
     * @param list<float> $values
     */
    private function calculateMaxDrawdown(array $values): float
    {
        $peak = $values[0] ?? 0;
        $maxDd = 0.0;

        foreach ($values as $val) {
            if ($val > $peak) {
                $peak = $val;
            }
            if ($peak > 0) {
                $dd = ($val - $peak) / $peak;
                if ($dd < $maxDd) {
                    $maxDd = $dd;
                }
            }
        }

        return $maxDd;
    }

    /**
     * @param list<float> $returns
     */
    private function calculateVaR(array $returns, float $confidence): float
    {
        if ($returns === []) {
            return 0.0;
        }

        $sorted = $returns;
        sort($sorted);
        $index = (int) floor(count($sorted) * $confidence);

        return $sorted[$index] ?? 0.0;
    }

    /**
     * @param list<float> $returns
     */
    private function calculateCVaR(array $returns, float $confidence): float
    {
        if ($returns === []) {
            return 0.0;
        }

        $sorted = $returns;
        sort($sorted);
        $cutoff = (int) floor(count($sorted) * $confidence);

        if ($cutoff === 0) {
            return $sorted[0];
        }

        $tail = array_slice($sorted, 0, $cutoff);

        return array_sum($tail) / count($tail);
    }

    private function inferCurrency(string $ticker, string $assetClass): string
    {
        if (str_ends_with($ticker, '.DE') || str_ends_with($ticker, '.AS') || $assetClass === 'fixed_income') {
            return 'EUR';
        }
        if (str_ends_with($ticker, '.L')) {
            return 'GBP';
        }

        return 'EUR';
    }

    /**
     * @return array<string, array{name: string, description: string, shocks: array<string, float>}>
     */
    private function getPresetScenarios(): array
    {
        return [
            'crash_20' => [
                'name' => 'Market Crash -20%',
                'description' => 'Equity markets drop 20%, bonds +5%, gold +10%',
                'shocks' => [
                    'equity' => -20.0,
                    'fixed_income' => 5.0,
                    'alternatives' => 10.0,
                    'cash' => 0.0,
                ],
            ],
            'crash_40' => [
                'name' => 'Severe Crash -40%',
                'description' => 'Equity markets drop 40%, bonds +10%, gold +20%',
                'shocks' => [
                    'equity' => -40.0,
                    'fixed_income' => 10.0,
                    'alternatives' => 20.0,
                    'cash' => 0.0,
                ],
            ],
            'rate_hike' => [
                'name' => 'Rate Hike +200bps',
                'description' => 'Interest rates rise 200bps: equities -10%, bonds -15%, gold -5%',
                'shocks' => [
                    'equity' => -10.0,
                    'fixed_income' => -15.0,
                    'alternatives' => -5.0,
                    'cash' => 0.0,
                ],
            ],
            'eur_usd_parity' => [
                'name' => 'EUR/USD Parity',
                'description' => 'EUR drops to parity with USD: USD-denominated assets gain ~15% in EUR terms',
                'shocks' => [
                    'NTWC' => 6.0,
                    'AVWC' => 8.0,
                    'NTEM' => 5.0,
                    'AVWS' => 8.0,
                    'XEON' => 0.0,
                    'IBGS' => -2.0,
                    'EGLN' => 15.0,
                    'Crypto' => 15.0,
                ],
            ],
            'stagflation' => [
                'name' => 'Stagflation',
                'description' => 'High inflation + stagnation: equities -25%, bonds -10%, gold +30%',
                'shocks' => [
                    'equity' => -25.0,
                    'fixed_income' => -10.0,
                    'alternatives' => 30.0,
                    'cash' => -3.0,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatRiskMarkdown(array $data): string
    {
        $m = $data['metrics'];
        $md = "# MIDO Risk Metrics\n\n";
        $md .= "_Period: {$data['period']} ({$data['data_points']} data points, {$data['date_range']['from']} — {$data['date_range']['to']})_\n\n";

        $md .= "## Portfolio Risk\n\n";
        $md .= "| Metric | Value |\n";
        $md .= "|--------|-------|\n";
        $md .= sprintf("| Annualized Return | %.2f%% |\n", $m['annualized_return']);
        $md .= sprintf("| Annualized Volatility | %.2f%% |\n", $m['annualized_volatility']);
        $md .= sprintf("| Sharpe Ratio | %.3f |\n", $m['sharpe_ratio']);
        $md .= sprintf("| Sortino Ratio | %.3f |\n", $m['sortino_ratio']);
        $md .= sprintf("| Max Drawdown | %.2f%% |\n", $m['max_drawdown_pct']);
        $md .= sprintf("| VaR (95%%, daily) | %.3f%% |\n", $m['var_95_daily']);
        $md .= sprintf("| CVaR (95%%, daily) | %.3f%% |\n", $m['cvar_95_daily']);
        $md .= sprintf("| Risk-Free Rate | %.2f%% |\n", $m['risk_free_rate']);

        if ($data['saxo_cross_check'] !== null) {
            $sx = $data['saxo_cross_check'];
            $md .= "\n## Saxo Cross-Check (all-time)\n\n";
            $md .= "| Metric | Saxo |\n";
            $md .= "|--------|------|\n";
            $md .= sprintf("| TWR | %.2f%% |\n", $sx['twr']);
            $md .= sprintf("| Sharpe | %.3f |\n", $sx['sharpe']);
            $md .= sprintf("| Sortino | %.3f |\n", $sx['sortino']);
            $md .= sprintf("| Max Drawdown | %.2f%% |\n", $sx['max_drawdown']);
        }

        return $md;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatStressMarkdown(array $data): string
    {
        $md = "# MIDO Stress Test\n\n";
        $md .= "_Scenario: {$data['scenario']['name']}_\n";
        $md .= "_{$data['scenario']['description']}_\n\n";

        $md .= "## Impact Summary\n\n";
        $md .= "| Metric | Value |\n";
        $md .= "|--------|-------|\n";
        $md .= sprintf("| Current Portfolio | €%s |\n", number_format($data['current_portfolio'], 0, ',', '.'));
        $md .= sprintf("| Stressed Portfolio | €%s |\n", number_format($data['stressed_portfolio'], 0, ',', '.'));
        $md .= sprintf("| Total Impact | €%s |\n", number_format($data['total_impact'], 0, ',', '.'));
        $md .= sprintf("| Drawdown | %.1f%% |\n", $data['drawdown_pct']);

        $crisis = $data['crisis_protocol'];
        $crisisIcon = $crisis['would_trigger'] ? '🚨' : '✅';
        $md .= sprintf("\n**Crisis Protocol:** %s %s\n", $crisisIcon, $crisis['note']);

        $md .= "\n## Position Impacts\n\n";
        $md .= "| Position | Current | Shock | Impact | Stressed |\n";
        $md .= "|----------|---------|-------|--------|----------|\n";

        foreach ($data['position_impacts'] as $pos) {
            $md .= sprintf(
                "| %s | €%s | %+.0f%% | €%s | €%s |\n",
                $pos['position'],
                number_format($pos['current_value'], 0, ',', '.'),
                $pos['shock_pct'],
                number_format($pos['impact'], 0, ',', '.'),
                number_format($pos['stressed_value'], 0, ',', '.'),
            );
        }

        $md .= "\n_Available scenarios: " . implode(', ', $data['available_scenarios']) . "_\n";

        return $md;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatCurrencyMarkdown(array $data): string
    {
        $md = "# MIDO Currency Exposure\n\n";
        $md .= "_Generated: {$data['timestamp']}_\n";
        $md .= "_Data: {$data['data_freshness']}_\n\n";

        $md .= "## FX Breakdown\n\n";
        $md .= "| Currency | Amount | Weight |\n";
        $md .= "|----------|--------|--------|\n";
        foreach ($data['exposure'] as $exp) {
            $md .= sprintf(
                "| %s | €%s | %.1f%% |\n",
                $exp['currency'],
                number_format($exp['amount'], 0, ',', '.'),
                $exp['pct'],
            );
        }

        $md .= sprintf("\n**EUR exposure:** %.1f%%\n", $data['eur_pct']);
        $md .= sprintf("**Non-EUR exposure:** %.1f%%\n", $data['non_eur_pct']);

        $md .= "\n## Geographic Targets (v8.0)\n\n";
        $md .= "| Region | Target |\n";
        $md .= "|--------|--------|\n";
        foreach ($data['geo_targets'] as $region => $target) {
            $md .= sprintf("| %s | %d%% |\n", $region, $target);
        }

        return $md;
    }

    private function periodToDays(string $period): int
    {
        return match ($period) {
            '1m' => 30,
            '3m' => 90,
            '6m' => 180,
            '1y' => 365,
            'ytd' => (int) (new \DateTime())->diff(new \DateTime('first day of January'))->days,
            'all' => 3650,
            default => 365,
        };
    }
}
