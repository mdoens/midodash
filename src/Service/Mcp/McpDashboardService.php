<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\CalculationService;
use App\Service\DxyService;
use App\Service\EurostatService;
use App\Service\FredApiService;
use App\Service\GoldPriceService;
use App\Service\MarketDataService;
use App\Service\TriggerService;

class McpDashboardService
{
    public function __construct(
        private readonly FredApiService $fredApi,
        private readonly EurostatService $eurostat,
        private readonly CalculationService $calculations,
        private readonly TriggerService $triggers,
        private readonly GoldPriceService $goldPrice,
        private readonly DxyService $dxyService,
        private readonly MarketDataService $marketData,
    ) {}

    public function generate(string $format = 'markdown', bool $includeWarnings = true): string|array
    {
        $data = $this->collectData();

        if ($format === 'json') {
            return $data;
        }

        return $this->formatMarkdown($data, $includeWarnings);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectData(): array
    {
        // Primary: Yahoo Finance real-time VIX, fallback: FRED daily
        $yahooVix = $this->marketData->getVixRealtime();
        $vix = $this->fredApi->getLatestValue('VIXCLS');
        if ($yahooVix !== null) {
            $vix = ['value' => $yahooVix, 'date' => date('Y-m-d')];
        }
        $hySpread = $this->fredApi->getLatestValue('BAMLH0A0HYM2');
        $igSpread = $this->fredApi->getLatestValue('BAMLC0A4CBBB');
        $yieldCurve = $this->fredApi->getLatestValue('T10Y2Y');
        $tedSpread = $this->fredApi->getLatestValue('TEDRATE');

        $ecbDeposit = $this->fredApi->getLatestValue('ECBDFR');
        $ecbRefi = $this->fredApi->getLatestValue('ECBMRRFR');
        $fedFunds = $this->fredApi->getLatestValue('FEDFUNDS');

        $treasury2y = $this->fredApi->getLatestValue('DGS2');
        $treasury10y = $this->fredApi->getLatestValue('DGS10');
        $treasury30y = $this->fredApi->getLatestValue('DGS30');

        $euInflation = $this->eurostat->getLatestInflation();
        $breakeven5y = $this->fredApi->getLatestValue('T5YIE');
        $breakeven10y = $this->fredApi->getLatestValue('T10YIE');

        $preciousMetals = $this->goldPrice->getPrices();
        $oil = $this->fredApi->getLatestValue('DCOILWTICO');
        $copper = $this->fredApi->getLatestValue('PCOPPUSDM');

        $eurUsd = $this->fredApi->getLatestValue('DEXUSEU');
        $dxy = $this->dxyService->getDxy();

        $cape = $this->calculations->getCapeAssessment();
        $erp = $this->calculations->calculateEquityRiskPremium();
        $realEcbRate = $this->calculations->calculateRealEcbRate();
        $recessionProb = $this->calculations->calculateRecessionProbability();

        $triggerStatus = $this->triggers->evaluateAll();

        return [
            'strategy_version' => 'v8.0',
            'portfolio_value' => 1_800_000,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i') . ' CET',
            'market_stress' => [
                'hy_spread' => $hySpread['value'] ?? null,
                'ig_spread' => $igSpread['value'] ?? null,
                'vix' => $vix['value'] ?? null,
                'yield_curve_10y2y' => $yieldCurve['value'] ?? null,
                'ted_spread' => $tedSpread['value'] ?? null,
            ],
            'central_banks' => [
                'ecb_deposit' => $ecbDeposit['value'] ?? null,
                'ecb_refi' => $ecbRefi['value'] ?? null,
                'fed_funds' => $fedFunds['value'] ?? null,
                'treasury_2y' => $treasury2y['value'] ?? null,
                'treasury_10y' => $treasury10y['value'] ?? null,
                'treasury_30y' => $treasury30y['value'] ?? null,
                'real_ecb_rate' => $realEcbRate['value'] ?? null,
            ],
            'inflation' => [
                'eurozone_hicp' => $euInflation['value'] ?? null,
                'breakeven_5y' => $breakeven5y['value'] ?? null,
                'breakeven_10y' => $breakeven10y['value'] ?? null,
            ],
            'commodities' => [
                'gold' => $preciousMetals['gold'],
                'silver' => $preciousMetals['silver'],
                'gold_silver_ratio' => $preciousMetals['gold_silver_ratio'],
                'oil_wti' => $oil['value'] ?? null,
                'copper' => $copper['value'] ?? null,
            ],
            'currencies' => [
                'eur_usd' => $eurUsd['value'] ?? null,
                'dxy' => $dxy['value'] ?? null,
            ],
            'valuation' => [
                'cape' => $cape,
                'erp' => $erp,
            ],
            'recession' => $recessionProb,
            'triggers' => $triggerStatus,
        ];
    }

    private function formatMarkdown(array $data, bool $includeWarnings): string
    {
        $lines = [];

        $lines[] = '======================================================================';
        $lines[] = '       MIDO HOLDING B.V. - FAMILY OFFICE MACRO DASHBOARD';
        $lines[] = '======================================================================';
        $lines[] = "       Date: {$data['timestamp']}";
        $lines[] = "       Strategy: {$data['strategy_version']} | Portfolio: EUR " . number_format($data['portfolio_value'], 0, ',', '.');
        $lines[] = '       Server: mido.barcelona2.doens.nl';
        $lines[] = '======================================================================';
        $lines[] = '';

        $lines[] = 'MARKET STRESS & CREDIT';
        $lines[] = '----------------------------------------------------------------------';
        $lines[] = $this->formatLine('HY Credit Spread', $data['market_stress']['hy_spread'], '%', 'avg: 4.0%', $this->getStressStatus($data['market_stress']['hy_spread'], 5.0, 6.0));
        $lines[] = $this->formatLine('IG Credit Spread', $data['market_stress']['ig_spread'], '%', 'avg: 1.5%', $this->getStressStatus($data['market_stress']['ig_spread'], 2.0, 2.5));
        $lines[] = $this->formatLine('VIX', $data['market_stress']['vix'], '', 'avg: 20', $this->getStressStatus($data['market_stress']['vix'], 25.0, 30.0));
        $yc = $data['market_stress']['yield_curve_10y2y'];
        $lines[] = $this->formatLine('Yield Curve 10Y-2Y', $yc, '%', '', $yc !== null && $yc < 0 ? 'INVERTED' : 'Normal');
        $lines[] = $this->formatLine('TED Spread', $data['market_stress']['ted_spread'], '%', '', $this->getStressStatus($data['market_stress']['ted_spread'], 0.3, 0.5));
        $lines[] = '';

        $lines[] = 'CENTRAL BANKS';
        $lines[] = '----------------------------------------------------------------------';
        $lines[] = $this->formatLine('ECB Deposit Rate', $data['central_banks']['ecb_deposit'], '%', 'start: 3.00%');
        $lines[] = $this->formatLine('ECB Refi Rate', $data['central_banks']['ecb_refi'], '%');
        $lines[] = $this->formatLine('Fed Funds Rate', $data['central_banks']['fed_funds'], '%');
        $lines[] = '';
        $lines[] = $this->formatLine('US 2Y Treasury', $data['central_banks']['treasury_2y'], '%');
        $lines[] = $this->formatLine('US 10Y Treasury', $data['central_banks']['treasury_10y'], '%');
        $lines[] = $this->formatLine('US 30Y Treasury', $data['central_banks']['treasury_30y'], '%');
        $lines[] = '';
        $lines[] = $this->formatLine('Real ECB Rate', $data['central_banks']['real_ecb_rate'], '%');
        $lines[] = '';

        $lines[] = 'INFLATION';
        $lines[] = '----------------------------------------------------------------------';
        $hicp = $data['inflation']['eurozone_hicp'];
        $lines[] = $this->formatLine('Eurozone HICP', $hicp, '%', 'target: 2.0%', $hicp !== null && $hicp > 2.5 ? 'Above target' : 'Near target');
        $lines[] = $this->formatLine('Breakeven 5Y', $data['inflation']['breakeven_5y'], '%');
        $lines[] = $this->formatLine('Breakeven 10Y', $data['inflation']['breakeven_10y'], '%');
        $lines[] = '';

        $lines[] = 'COMMODITIES';
        $lines[] = '----------------------------------------------------------------------';
        $lines[] = $this->formatLine('Gold', $data['commodities']['gold'], '', '', '', '$');
        $lines[] = $this->formatLine('Silver', $data['commodities']['silver'], '', '', '', '$');
        $gsr = $data['commodities']['gold_silver_ratio'];
        $lines[] = $this->formatLine('Gold/Silver Ratio', $gsr, '', 'avg: 60', $gsr !== null && $gsr > 80 ? 'Elevated' : 'Normal');
        $lines[] = $this->formatLine('WTI Crude Oil', $data['commodities']['oil_wti'], '', '', '', '$');
        $lines[] = $this->formatLine('Copper', $data['commodities']['copper'], '/lb', '', '', '$');
        $lines[] = '';

        $lines[] = 'CURRENCIES';
        $lines[] = '----------------------------------------------------------------------';
        $lines[] = $this->formatLine('EUR/USD', $data['currencies']['eur_usd'], '', 'start: 1.15');
        $lines[] = $this->formatLine('DXY (USD Index)', $data['currencies']['dxy']);
        $lines[] = '';

        $lines[] = 'VALUATION';
        $lines[] = '----------------------------------------------------------------------';
        $cape = $data['valuation']['cape'];
        $erp = $data['valuation']['erp'];
        $lines[] = $this->formatLine('CAPE Ratio', $cape['value'], '', 'avg: 17', $this->getCapeStatus($cape['value']));
        $lines[] = $this->formatLine('Equity Risk Premium', $erp['value'], '%', '', $this->getErpStatus($erp['value']));
        $lines[] = '';

        $lines[] = 'MIDO TRIGGERS (v8.0)';
        $lines[] = '----------------------------------------------------------------------';
        $triggers = $data['triggers']['triggers'];
        $lines[] = $this->formatTriggerLine('T1 Crash (VIX>30)', $triggers['T1_crash']['active']);
        $lines[] = $this->formatTriggerLine('T3 Stagflation', $triggers['T3_stagflation']['active']);
        $lines[] = $this->formatTriggerLine('T5 Credit Stress', $triggers['T5_credit']['active']);
        $lines[] = $this->formatTriggerLine('T9 Recession', $triggers['T9_recession']['active']);
        $lines[] = '';
        $lines[] = "   Active triggers:    {$data['triggers']['active_count']} of 4";
        $lines[] = '';

        $recession = $data['recession'];
        $lines[] = 'RECESSION PROBABILITY';
        $lines[] = '----------------------------------------------------------------------';
        $lines[] = $this->formatLine('Score', (float) $recession['probability'], '%', '', $this->getRecessionStatus((float) $recession['probability']));
        $lines[] = '';

        if ($includeWarnings) {
            $warnings = $this->collectWarnings($data);
            if ($warnings !== []) {
                $lines[] = 'ATTENTION POINTS';
                $lines[] = '----------------------------------------------------------------------';
                foreach ($warnings as $warning) {
                    $lines[] = "   - {$warning}";
                }
                $lines[] = '';
            }
        }

        $lines[] = 'RECOMMENDATION';
        $lines[] = '----------------------------------------------------------------------';
        $status = $data['triggers']['status'];
        if ($status === 'GREEN') {
            $lines[] = '   PROCEED AS PLANNED';
        } else {
            $lines[] = '   ACTION REQUIRED - Review active triggers';
        }
        $lines[] = '';

        $lines[] = '======================================================================';

        return implode("\n", $lines);
    }

    private function formatLine(string $label, ?float $value, string $unit = '', string $context = '', string $status = '', string $prefix = ''): string
    {
        $label = str_pad($label . ':', 22);
        $valueStr = $value !== null ? $prefix . number_format($value, 2) . $unit : 'N/A';
        $valueStr = str_pad($valueStr, 12);
        $contextStr = $context !== '' ? str_pad("({$context})", 18) : str_pad('', 18);

        return "   {$label}{$valueStr}{$contextStr}{$status}";
    }

    private function formatTriggerLine(string $name, bool $active): string
    {
        $name = str_pad($name . ':', 26);
        $status = $active ? 'ACTIVE' : 'INACTIVE';

        return "   {$name}{$status}";
    }

    private function getStressStatus(?float $value, float $warning, float $critical): string
    {
        if ($value === null) {
            return '';
        }
        if ($value >= $critical) {
            return 'High';
        }
        if ($value >= $warning) {
            return 'Elevated';
        }

        return 'Low';
    }

    private function getCapeStatus(?float $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value > 35) {
            return 'Very high';
        }
        if ($value > 25) {
            return 'High';
        }

        return 'Normal';
    }

    private function getErpStatus(?float $value): string
    {
        if ($value === null) {
            return '';
        }
        if ($value < 0) {
            return 'Negative';
        }
        if ($value < 1) {
            return 'Low';
        }

        return 'Normal';
    }

    private function getRecessionStatus(float $value): string
    {
        if ($value >= 50) {
            return 'High';
        }
        if ($value >= 30) {
            return 'Elevated';
        }
        if ($value >= 15) {
            return 'Moderate';
        }

        return 'Low';
    }

    /**
     * @return list<string>
     */
    private function collectWarnings(array $data): array
    {
        $warnings = [];

        if ($data['valuation']['cape']['value'] > 35) {
            $warnings[] = "CAPE elevated: {$data['valuation']['cape']['value']} (historical average: 17)";
        }

        if (($data['valuation']['erp']['value'] ?? 999) < 1) {
            $warnings[] = "Low ERP: {$data['valuation']['erp']['value']}%";
        }

        if ($data['commodities']['gold_silver_ratio'] !== null && $data['commodities']['gold_silver_ratio'] > 80) {
            $warnings[] = "Gold/Silver ratio high: {$data['commodities']['gold_silver_ratio']}";
        }

        if ($data['market_stress']['yield_curve_10y2y'] !== null && $data['market_stress']['yield_curve_10y2y'] < 0) {
            $warnings[] = "Yield curve inverted: {$data['market_stress']['yield_curve_10y2y']}%";
        }

        return $warnings;
    }
}
