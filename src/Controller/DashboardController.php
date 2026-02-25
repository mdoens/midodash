<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CalculationService;
use App\Service\CrisisService;
use App\Service\DxyService;
use App\Service\EurostatService;
use App\Service\FredApiService;
use App\Service\GoldPriceService;
use App\Service\IbClient;
use App\Service\MomentumService;
use App\Service\PortfolioService;
use App\Service\SaxoClient;
use App\Service\TriggerService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class DashboardController extends AbstractController
{
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
        ChartBuilderInterface $chartBuilder,
        LoggerInterface $logger,
    ): Response {
        // ── IB data ──
        $ibError = false;
        try {
            $ibPositions = $ibClient->getPositions();
            $ibCash = $ibClient->getCashReport();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: IB data failed', ['error' => $e->getMessage()]);
            $ibPositions = [];
            $ibCash = [];
            $ibError = true;
        }
        $ibCashBalance = (float) ($ibCash['ending_cash'] ?? 0);

        // ── Saxo data ──
        $saxoError = false;
        $saxoPositions = null;
        $saxoCashBalance = 0.0;
        $saxoAuthenticated = false;
        try {
            $saxoAuthenticated = $saxoClient->isAuthenticated();
            if ($saxoAuthenticated) {
                $saxoPositions = $saxoClient->getPositions();
                $saxoBalance = $saxoClient->getAccountBalance();
                $saxoCashBalance = (float) ($saxoBalance['CashBalance'] ?? 0);
                if ($saxoPositions === null) {
                    $saxoAuthenticated = false;
                }
            }
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Saxo data failed', ['error' => $e->getMessage()]);
            $saxoError = true;
        }

        // ── Portfolio allocation (v6.5) ──
        $allocation = $portfolioService->calculateAllocations(
            $ibPositions,
            $saxoPositions,
            $ibCashBalance,
            $saxoCashBalance,
        );

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
        $crisisError = false;
        $crisis = ['crisis_triggered' => false, 'active_signals' => 0, 'signals' => [], 'drawdown' => []];
        try {
            $crisis = $crisisService->checkAllSignals();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Crisis data failed', ['error' => $e->getMessage()]);
            $crisisError = true;
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

        // ── Charts ──
        $pieChart = $this->buildAssetClassPieChart($chartBuilder, $allocation);
        $radarChart = $this->buildFactorRadarChart($chartBuilder, $portfolioService->getFactorData());
        $performanceChart = $this->buildPerformanceChart($chartBuilder, $allocation['positions']);

        // ── Sort positions by drift ──
        $positionsByDrift = $allocation['positions'];
        uasort($positionsByDrift, fn(array $a, array $b): int => (int) (abs($b['drift'] ?? 0) * 100) <=> (int) (abs($a['drift'] ?? 0) * 100));

        return $this->render('dashboard/index.html.twig', [
            'allocation' => $allocation,
            'positions_by_drift' => array_slice($positionsByDrift, 0, 5, true),
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
            'pie_chart' => $pieChart,
            'radar_chart' => $radarChart,
            'performance_chart' => $performanceChart,
        ]);
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
     * @param array<string, array<string, mixed>> $assetClasses
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
                'label' => 'v6.5',
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
            'maintainAspectRatio' => true,
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
}
