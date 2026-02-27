<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CalculationService;
use App\Service\CrisisService;
use App\Service\DashboardCacheService;
use App\Service\DataBufferService;
use App\Service\DxyService;
use App\Service\EurostatService;
use App\Service\FredApiService;
use App\Service\GoldPriceService;
use App\Service\IbClient;
use App\Service\MomentumService;
use App\Service\PortfolioService;
use App\Service\PortfolioSnapshotService;
use App\Service\ReturnsService;
use App\Service\SaxoClient;
use App\Service\TriggerService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class DashboardController extends AbstractController
{
    #[Route('/health/returns', name: 'health_returns')]
    public function debugHealth(
        ReturnsService $returnsService,
        LoggerInterface $logger,
    ): JsonResponse {
        $checks = [];

        try {
            $returns = $returnsService->getPortfolioReturns(['total_portfolio' => 0, 'positions' => []]);
            $checks['returns'] = 'OK: deposits=' . $returns['total_deposits'];
        } catch (\Throwable $e) {
            $checks['returns'] = 'ERROR: ' . $e->getMessage();
        }

        try {
            $monthly = $returnsService->getMonthlyOverview();
            $checks['monthly'] = 'OK: ' . count($monthly) . ' months';
        } catch (\Throwable $e) {
            $checks['monthly'] = 'ERROR: ' . $e->getMessage();
        }

        return $this->json($checks);
    }

    #[Route('/', name: 'dashboard')]
    public function index(
        IbClient $ibClient,
        SaxoClient $saxoClient,
        MomentumService $momentumService,
        PortfolioService $portfolioService,
        FredApiService $fredApi,
        CrisisService $crisisService,
        CalculationService $calculations,
        EurostatService $eurostat,
        GoldPriceService $goldPrice,
        DxyService $dxyService,
        TriggerService $triggerService,
        DashboardCacheService $dashboardCache,
        DataBufferService $dataBuffer,
        PortfolioSnapshotService $snapshotService,
        ReturnsService $returnsService,
        ChartBuilderInterface $chartBuilder,
        LoggerInterface $logger,
    ): Response {
        // Try loading from dashboard cache first (instant page load)
        $cached = $dashboardCache->load();

        if ($cached !== null) {
            // Build charts from cached data (Chart objects are not serializable)
            $cached['pie_chart'] = $this->buildAssetClassPieChart($chartBuilder, $cached['allocation']);
            $cached['radar_chart'] = $this->buildFactorRadarChart($chartBuilder, $portfolioService->getFactorData());
            $cached['performance_chart'] = $this->buildPerformanceChart($chartBuilder, $cached['allocation']['positions']);

            // Always check live Saxo auth status
            $cached['saxo_authenticated'] = $saxoClient->isAuthenticated();

            // Portfolio history for Historie tab
            $history = $snapshotService->getHistory(365);
            $cached['history'] = $history;
            if (count($history) > 1) {
                $cached['history_chart'] = $this->buildHistoryChart($chartBuilder, $history);
                $cached['allocation_chart'] = $this->buildAllocationHistoryChart($chartBuilder, $history);
            }

            // Returns data from transactions (graceful fallback if table empty/missing)
            try {
                $cached['returns'] = $returnsService->getPortfolioReturns($cached['allocation']);
                $cached['position_returns'] = $returnsService->getPositionReturns($cached['allocation']);
                $cached['monthly_overview'] = $returnsService->getMonthlyOverview();
            } catch (\Throwable $e) {
                $logger->error('Returns data failed', ['error' => $e->getMessage()]);
                $cached['returns'] = [];
                $cached['position_returns'] = [];
                $cached['monthly_overview'] = [];
            }

            return $this->render('dashboard/index.html.twig', $cached);
        }

        // No cache — compute everything
        $data = $this->computeDashboardData(
            $ibClient,
            $saxoClient,
            $momentumService,
            $portfolioService,
            $fredApi,
            $crisisService,
            $calculations,
            $eurostat,
            $goldPrice,
            $dxyService,
            $triggerService,
            $dataBuffer,
            $logger,
        );

        // Save daily portfolio snapshot (once per day)
        $snapshotService->saveSnapshot(
            $data['allocation'],
            $data['regime'],
            !($data['saxo_from_buffer'] ?? false),
            !($data['ib_from_buffer'] ?? false),
        );

        // Save to cache (without Chart objects)
        $dashboardCache->save($data);

        // Build charts
        $data['pie_chart'] = $this->buildAssetClassPieChart($chartBuilder, $data['allocation']);
        $data['radar_chart'] = $this->buildFactorRadarChart($chartBuilder, $portfolioService->getFactorData());
        $data['performance_chart'] = $this->buildPerformanceChart($chartBuilder, $data['allocation']['positions']);

        // Portfolio history for Historie tab
        $history = $snapshotService->getHistory(365);
        $data['history'] = $history;
        if (count($history) > 1) {
            $data['history_chart'] = $this->buildHistoryChart($chartBuilder, $history);
            $data['allocation_chart'] = $this->buildAllocationHistoryChart($chartBuilder, $history);
        }

        // Returns data from transactions (graceful fallback if table empty/missing)
        try {
            $data['returns'] = $returnsService->getPortfolioReturns($data['allocation']);
            $data['position_returns'] = $returnsService->getPositionReturns($data['allocation']);
            $data['monthly_overview'] = $returnsService->getMonthlyOverview();
        } catch (\Throwable $e) {
            $logger->error('Returns data failed', ['error' => $e->getMessage()]);
            $data['returns'] = [];
            $data['position_returns'] = [];
            $data['monthly_overview'] = [];
        }

        return $this->render('dashboard/index.html.twig', $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function computeDashboardData(
        IbClient $ibClient,
        SaxoClient $saxoClient,
        MomentumService $momentumService,
        PortfolioService $portfolioService,
        FredApiService $fredApi,
        CrisisService $crisisService,
        CalculationService $calculations,
        EurostatService $eurostat,
        GoldPriceService $goldPrice,
        DxyService $dxyService,
        TriggerService $triggerService,
        DataBufferService $dataBuffer,
        LoggerInterface $logger,
    ): array {
        // ── IB data ──
        $ibError = false;
        $ibFromBuffer = false;
        $ibBufferedAt = null;
        try {
            $ibPositions = $ibClient->getPositions();
            $ibCash = $ibClient->getCashReport();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: IB data failed', ['error' => $e->getMessage()]);
            $ibPositions = [];
            $ibCash = [];
            $ibError = true;
        }

        // IB fallback to buffer
        if ($ibPositions === [] && $ibError) {
            $buffered = $dataBuffer->retrieve('ib', 'positions');
            if ($buffered !== null) {
                $ibPositions = $buffered['data'];
                $ibFromBuffer = true;
                $ibBufferedAt = $buffered['fetched_at'];
                $logger->info('IB using buffered positions', ['buffered_at' => $ibBufferedAt->format('Y-m-d H:i')]);
            }

            $cashBuffered = $dataBuffer->retrieve('ib', 'cash_report');
            if ($cashBuffered !== null && $ibCash === []) {
                $ibCash = $cashBuffered['data'];
            }
        }

        $ibCashBalance = (float) ($ibCash['ending_cash'] ?? 0);

        // ── Saxo data ──
        $saxoError = false;
        $saxoFromBuffer = false;
        $saxoBufferedAt = null;
        $saxoPositions = null;
        $saxoCashBalance = 0.0;
        $saxoAuthenticated = false;
        try {
            $saxoAuthenticated = $saxoClient->isAuthenticated();
            if ($saxoAuthenticated) {
                $saxoPositions = $saxoClient->getPositions();
                $saxoBalance = $saxoClient->getAccountBalance();
                $saxoCashBalance = (float) ($saxoBalance['CashBalance'] ?? 0);

                // Log Saxo symbols for debugging mapping (stderr for Coolify visibility)
                if ($saxoPositions !== null) {
                    $symbolDetails = [];
                    foreach ($saxoPositions as $sp) {
                        $symbolDetails[] = sprintf(
                            '%s (%s) = €%s',
                            $sp['symbol'] ?? '?',
                            $sp['description'] ?? '?',
                            number_format((float) ($sp['exposure'] ?? 0), 0, ',', '.'),
                        );
                    }
                    $msg = 'Saxo positions: ' . implode(' | ', $symbolDetails);
                    $logger->info($msg);
                    file_put_contents('php://stderr', $msg . "\n");
                } else {
                    $saxoAuthenticated = false;
                    $logger->warning('Saxo positions returned null despite being authenticated');
                }
            }
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Saxo data failed', ['error' => $e->getMessage()]);
            $saxoError = true;
        }

        // Saxo fallback to buffer when positions are null (token expired, API down, etc.)
        if ($saxoPositions === null) {
            $buffered = $dataBuffer->retrieve('saxo', 'positions');
            if ($buffered !== null) {
                $saxoPositions = $buffered['data'];
                $saxoFromBuffer = true;
                $saxoBufferedAt = $buffered['fetched_at'];
                $logger->info('Saxo using buffered positions', ['buffered_at' => $saxoBufferedAt->format('Y-m-d H:i')]);
            }

            $balanceBuffered = $dataBuffer->retrieve('saxo', 'balance');
            if ($balanceBuffered !== null && $saxoCashBalance === 0.0) {
                $saxoCashBalance = (float) ($balanceBuffered['data']['CashBalance'] ?? 0);
            }
        }

        // Log raw IB symbols for debugging
        $ibSymbols = array_map(fn(array $p): string => sprintf(
            '%s=€%s',
            $p['symbol'] ?? '?',
            number_format((float) ($p['value'] ?? 0), 0, ',', '.'),
        ), $ibPositions);
        $logger->info('IB positions loaded', ['count' => count($ibPositions), 'symbols' => $ibSymbols]);

        // ── Portfolio allocation (v8.0) ──
        $allocation = $portfolioService->calculateAllocations(
            $ibPositions,
            $saxoPositions,
            $ibCashBalance,
            $saxoCashBalance,
        );

        // Log matched positions
        $matched = [];
        foreach ($allocation['positions'] as $name => $p) {
            $matched[] = sprintf('%s: €%s (%s)', $name, number_format($p['value'], 0, ',', '.'), $p['status']);
        }
        $logger->info('Portfolio positions', ['positions' => $matched]);

        // ── Momentum signal ──
        $momentumError = false;
        $signal = ['regime' => ['bull' => true, 'price' => 0, 'ma200' => 0], 'scores' => [], 'allocation' => [], 'reason' => ''];
        try {
            $signal = $momentumService->getSignal();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Momentum data failed', ['error' => $e->getMessage()]);
            $momentumError = true;
        }

        // ── Macro indicators ──
        $macroError = false;
        $macro = [];
        try {
            $macro = $this->collectMacroData($fredApi, $eurostat, $goldPrice, $dxyService, $calculations);
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Macro data failed', ['error' => $e->getMessage()]);
            $macroError = true;
        }

        // ── Crisis signals ──
        $crisis = ['crisis_triggered' => false, 'active_signals' => 0, 'signals' => [], 'drawdown' => []];
        try {
            $crisis = $crisisService->checkAllSignals();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Crisis data failed', ['error' => $e->getMessage()]);
        }

        // ── Triggers ──
        $triggers = ['triggers' => [], 'warnings' => [], 'active_count' => 0, 'status' => 'GREEN'];
        try {
            $triggers = $triggerService->evaluateAll();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Trigger evaluation failed', ['error' => $e->getMessage()]);
        }

        // ── Regime label ──
        $vixValue = $macro['vix'] ?? null;
        $regimeLabel = 'BULL';
        if (!($signal['regime']['bull'] ?? true)) {
            $regimeLabel = ($vixValue !== null && $vixValue > 30) ? 'BEAR BEVESTIGD' : 'BEAR';
        }

        // ── Sort positions by drift ──
        $positionsByDrift = $allocation['positions'];
        uasort($positionsByDrift, fn(array $a, array $b): int => (int) (abs($b['drift'] ?? 0) * 100) <=> (int) (abs($a['drift'] ?? 0) * 100));

        return [
            'allocation' => $allocation,
            'positions_by_drift' => $positionsByDrift,
            'macro' => $macro,
            'regime' => $regimeLabel,
            'crisis' => $crisis,
            'triggers' => $triggers,
            'signal' => $signal,
            'factors' => $portfolioService->getFactorData(),
            'factor_mapping' => $portfolioService->getFactorMapping(),
            'deployment_protocol' => $portfolioService->getDeploymentProtocol(),
            'five_questions' => $portfolioService->getFiveQuestions(),
            'saxo_authenticated' => $saxoAuthenticated,
            'ib_error' => $ibError,
            'saxo_error' => $saxoError,
            'momentum_error' => $momentumError,
            'macro_error' => $macroError,
            'saxo_from_buffer' => $saxoFromBuffer,
            'saxo_buffered_at' => $saxoBufferedAt,
            'ib_from_buffer' => $ibFromBuffer,
            'ib_buffered_at' => $ibBufferedAt,
        ];
    }

    /**
     * @return array<string, float|string|int|null>
     */
    private function collectMacroData(
        FredApiService $fredApi,
        EurostatService $eurostat,
        GoldPriceService $goldPrice,
        DxyService $dxyService,
        CalculationService $calculations,
    ): array {
        $vix = $fredApi->getLatestValue('VIXCLS');
        $hySpread = $fredApi->getLatestValue('BAMLH0A0HYM2');
        $ecbRate = $fredApi->getLatestValue('ECBDFR');
        $treasury10y = $fredApi->getLatestValue('DGS10');
        $yieldCurve = $fredApi->getLatestValue('T10Y2Y');
        $eurUsd = $fredApi->getLatestValue('DEXUSEU');

        $euInflation = $eurostat->getLatestInflation();
        $goldPrices = $goldPrice->getPrices();
        $dxy = $dxyService->getDxy();
        $cape = $calculations->getCapeAssessment();
        $erp = $calculations->calculateEquityRiskPremium();
        $realEcb = $calculations->calculateRealEcbRate();
        $recession = $calculations->calculateRecessionProbability();

        return [
            'vix' => $vix['value'] ?? null,
            'hy_spread' => $hySpread['value'] ?? null,
            'hy_spread_bps' => ($hySpread['value'] ?? null) !== null ? (int) round($hySpread['value'] * 100) : null,
            'ecb_rate' => $ecbRate['value'] ?? null,
            'dgs10' => $treasury10y['value'] ?? null,
            'yield_curve' => $yieldCurve['value'] ?? null,
            'eu_inflation' => $euInflation['value'] ?? null,
            'gold' => $goldPrices['gold'] ?? null,
            'eurusd' => $eurUsd['value'] ?? null,
            'dxy' => $dxy['value'] ?? null,
            'cape' => $cape['value'],
            'cape_status' => $cape['status'],
            'erp' => $erp['value'],
            'erp_status' => $erp['status'],
            'real_ecb_rate' => $realEcb['value'] ?? null,
            'recession_prob' => $recession['probability'],
            'recession_status' => $recession['status'],
        ];
    }

    /**
     * @param array<string, mixed> $allocation
     */
    private function buildAssetClassPieChart(ChartBuilderInterface $chartBuilder, array $allocation): Chart
    {
        $chart = $chartBuilder->createChart(Chart::TYPE_DOUGHNUT);

        $labels = [];
        $data = [];
        $colors = [];

        $colorMap = [
            'equity' => '#22c55e',
            'fixed_income' => '#3b82f6',
            'alternatives' => '#f59e0b',
        ];

        foreach ($allocation['asset_classes'] as $key => $ac) {
            $labels[] = $ac['label'];
            $data[] = $ac['current_pct'];
            $colors[] = $colorMap[$key] ?? '#6b7280';
        }

        $labels[] = 'Cash';
        $data[] = $allocation['cash_pct'];
        $colors[] = '#475569';

        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $data,
                'backgroundColor' => $colors,
                'borderWidth' => 0,
                'spacing' => 2,
            ]],
        ]);

        $chart->setOptions([
            'cutout' => '65%',
            'responsive' => true,
            'maintainAspectRatio' => true,
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'backgroundColor' => '#0f172a',
                    'titleColor' => '#e2e8f0',
                    'bodyColor' => '#94a3b8',
                    'borderColor' => 'rgba(148,163,184,0.2)',
                    'borderWidth' => 1,
                    'callbacks' => ['label' => '@@function(ctx) { return ctx.label + ": " + ctx.parsed.toFixed(1) + "%"; }@@'],
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * @param array<int, array{factor: string, score: float}> $factorData
     */
    private function buildFactorRadarChart(ChartBuilderInterface $chartBuilder, array $factorData): Chart
    {
        $chart = $chartBuilder->createChart(Chart::TYPE_RADAR);

        $chart->setData([
            'labels' => array_column($factorData, 'factor'),
            'datasets' => [[
                'label' => 'v8.0',
                'data' => array_column($factorData, 'score'),
                'borderColor' => '#3b82f6',
                'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                'borderWidth' => 2,
                'pointBackgroundColor' => '#3b82f6',
                'pointRadius' => 4,
            ]],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'r' => [
                    'beginAtZero' => true,
                    'max' => 0.6,
                    'ticks' => ['display' => false],
                    'grid' => ['color' => 'rgba(148,163,184,0.12)'],
                    'angleLines' => ['color' => 'rgba(148,163,184,0.12)'],
                    'pointLabels' => ['color' => '#94a3b8', 'font' => ['size' => 12]],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'backgroundColor' => '#0f172a',
                    'titleColor' => '#e2e8f0',
                    'bodyColor' => '#94a3b8',
                ],
            ],
        ]);

        return $chart;
    }

    #[Route('/api/portfolio-history', name: 'api_portfolio_history')]
    public function portfolioHistory(PortfolioSnapshotService $snapshotService): JsonResponse
    {
        return $this->json($snapshotService->getHistory(365));
    }

    /**
     * @param array<string, array<string, mixed>> $positions
     */
    private function buildPerformanceChart(ChartBuilderInterface $chartBuilder, array $positions): Chart
    {
        $chart = $chartBuilder->createChart(Chart::TYPE_BAR);

        $labels = [];
        $data = [];
        $colors = [];

        foreach ($positions as $name => $pos) {
            if ((float) ($pos['value'] ?? 0) <= 0) {
                continue;
            }
            $labels[] = $name;
            $plPct = round((float) ($pos['pl_pct'] ?? 0), 1);
            $data[] = $plPct;
            $colors[] = $plPct >= 0 ? '#22c55e' : '#ef4444';
        }

        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $data,
                'backgroundColor' => $colors,
                'borderRadius' => 4,
                'barPercentage' => 0.7,
            ]],
        ]);

        $chart->setOptions([
            'indexAxis' => 'y',
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'x' => [
                    'grid' => ['color' => 'rgba(148,163,184,0.08)', 'drawBorder' => false],
                    'ticks' => ['color' => '#64748b', 'font' => ['size' => 10], 'callback' => '@@function(v) { return v + "%"; }@@'],
                ],
                'y' => [
                    'grid' => ['display' => false],
                    'ticks' => ['color' => '#94a3b8', 'font' => ['size' => 11]],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'backgroundColor' => '#0f172a',
                    'callbacks' => ['label' => '@@function(ctx) { return ctx.parsed.x.toFixed(1) + "%"; }@@'],
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    private function buildHistoryChart(ChartBuilderInterface $chartBuilder, array $history): Chart
    {
        $chart = $chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'labels' => array_column($history, 'date'),
            'datasets' => [
                [
                    'label' => 'Portfolio Waarde',
                    'data' => array_column($history, 'total_value'),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => ['color' => 'rgba(148,163,184,0.08)'],
                    'ticks' => ['color' => '#64748b', 'maxTicksLimit' => 12],
                ],
                'y' => [
                    'grid' => ['color' => 'rgba(148,163,184,0.08)'],
                    'ticks' => ['color' => '#64748b'],
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    private function buildAllocationHistoryChart(ChartBuilderInterface $chartBuilder, array $history): Chart
    {
        $chart = $chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'labels' => array_column($history, 'date'),
            'datasets' => [
                [
                    'label' => 'Equity',
                    'data' => array_column($history, 'equity_pct'),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Fixed Income',
                    'data' => array_column($history, 'fi_pct'),
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Alternatives',
                    'data' => array_column($history, 'alt_pct'),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Cash',
                    'data' => array_column($history, 'cash_pct'),
                    'borderColor' => '#94a3b8',
                    'backgroundColor' => 'rgba(148, 163, 184, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'labels' => ['color' => '#94a3b8', 'boxWidth' => 12, 'padding' => 12],
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => ['color' => 'rgba(148,163,184,0.08)'],
                    'ticks' => ['color' => '#64748b', 'maxTicksLimit' => 12],
                ],
                'y' => [
                    'grid' => ['color' => 'rgba(148,163,184,0.08)'],
                    'ticks' => ['color' => '#64748b'],
                    'min' => 0,
                    'max' => 100,
                ],
            ],
        ]);

        return $chart;
    }
}
