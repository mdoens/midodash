<?php

declare(strict_types=1);

namespace App\Service;

class TriggerService
{
    public function __construct(
        private readonly FredApiService $fredApi,
        private readonly EurostatService $eurostat,
        private readonly CalculationService $calculations,
        private readonly MarketDataService $marketData,
    ) {}

    /**
     * @return array{triggers: array, warnings: array, active_count: int}
     */
    public function evaluateAll(): array
    {
        $triggers = [
            'T1_crash' => $this->evaluateT1(),
            'T3_stagflation' => $this->evaluateT3(),
            'T5_credit' => $this->evaluateT5(),
            'T9_recession' => $this->evaluateT9(),
        ];

        $warnings = [
            'W1_cape' => $this->evaluateW1Cape(),
            'W2_erp' => $this->evaluateW2Erp(),
            'W3_eurusd' => $this->evaluateW3EurUsd(),
        ];

        $activeCount = count(array_filter($triggers, fn(array $t): bool => $t['active']));

        return [
            'triggers' => $triggers,
            'warnings' => $warnings,
            'active_count' => $activeCount,
            'status' => $activeCount > 0 ? 'ACTION_REQUIRED' : 'GREEN',
        ];
    }

    /**
     * @return array{active: bool, name: string, value: float|null, threshold: float}
     */
    private function evaluateT1(): array
    {
        // Primary: Yahoo Finance real-time VIX, fallback: FRED daily
        $value = $this->marketData->getVixRealtime();
        if ($value === null) {
            $vix = $this->fredApi->getLatestValue('VIXCLS');
            $value = $vix['value'] ?? 0.0;
        }

        return ['active' => $value > 30, 'name' => 'Market Crash', 'value' => $value, 'threshold' => 30.0];
    }

    /**
     * @return array{active: bool, name: string, inflation: float|null, threshold: float}
     */
    private function evaluateT3(): array
    {
        $euInflation = $this->eurostat->getLatestInflation();
        $inflationValue = $euInflation['value'] ?? 0.0;

        return [
            'active' => $inflationValue > 4.0,
            'name' => 'Stagflation Risk',
            'inflation' => $inflationValue,
            'threshold' => 4.0,
        ];
    }

    /**
     * @return array{active: bool, name: string, hy_value: float|null, ig_value: float|null}
     */
    private function evaluateT5(): array
    {
        $hySpread = $this->fredApi->getLatestValue('BAMLH0A0HYM2');
        $igSpread = $this->fredApi->getLatestValue('BAMLC0A4CBBB');

        $hyValue = $hySpread['value'] ?? 0.0;
        $igValue = $igSpread['value'] ?? 0.0;

        return [
            'active' => $hyValue > 6.0 || $igValue > 2.5,
            'name' => 'Credit Stress',
            'hy_value' => $hyValue,
            'ig_value' => $igValue,
        ];
    }

    /**
     * @return array{active: bool, name: string, probability: int, threshold: int, status: string, factors: array<int, array{name: string, status: string, contribution: int}>}
     */
    private function evaluateT9(): array
    {
        $recession = $this->calculations->calculateRecessionProbability();

        return [
            'active' => $recession['probability'] >= 30,
            'name' => 'Recession Warning',
            'probability' => $recession['probability'],
            'threshold' => 30,
            'status' => $recession['status'],
            'factors' => $recession['factors'],
        ];
    }

    /**
     * @return array{active: bool, name: string, value: float}
     */
    private function evaluateW1Cape(): array
    {
        $cape = $this->calculations->getCapeAssessment();

        return ['active' => $cape['value'] > 35, 'name' => 'CAPE Warning', 'value' => $cape['value']];
    }

    /**
     * @return array{active: bool, name: string, value: float}
     */
    private function evaluateW2Erp(): array
    {
        $erp = $this->calculations->calculateEquityRiskPremium();

        return ['active' => $erp['value'] < 1.0, 'name' => 'ERP Warning', 'value' => $erp['value']];
    }

    /**
     * @return array{active: bool, name: string, value: float|null, deviation: float}
     */
    private function evaluateW3EurUsd(): array
    {
        $eurUsd = $this->fredApi->getLatestValue('DEXUSEU');
        $current = $eurUsd['value'] ?? 0.0;
        $deviation = (($current / 1.15) - 1) * 100;

        return [
            'active' => abs($deviation) > 10,
            'name' => 'EUR/USD Deviation',
            'value' => $current,
            'deviation' => round($deviation, 1),
        ];
    }
}
