<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

class CrisisService
{
    /** @var array<string, mixed> */
    private readonly array $historicalCrises;

    public function __construct(
        private readonly MarketDataService $marketData,
        private readonly FredApiService $fredApi,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
        $file = $this->projectDir . '/config/historical_crises.json';
        $content = file_exists($file) ? file_get_contents($file) : false;
        $this->historicalCrises = ($content !== false) ? (json_decode($content, true) ?? []) : [];
    }

    /**
     * @return array{
     *   crisis_triggered: bool,
     *   active_signals: int,
     *   signals: array{price: array, volatility: array, credit: array},
     *   drawdown: array
     * }
     */
    public function checkAllSignals(): array
    {
        $drawdown = $this->marketData->getDrawdown('IWDA.AS', 252);
        $priceSignal = $this->checkPriceSignal($drawdown);
        $volatilitySignal = $this->checkVolatilitySignal();
        $creditSignal = $this->checkCreditSignal();

        $signals = [$priceSignal, $volatilitySignal, $creditSignal];
        $activeCount = array_sum(array_map(fn(array $s): int => $s['active'] ? 1 : 0, $signals));

        return [
            'crisis_triggered' => $activeCount >= 2,
            'active_signals' => $activeCount,
            'signals' => [
                'price' => $priceSignal,
                'volatility' => $volatilitySignal,
                'credit' => $creditSignal,
            ],
            'drawdown' => $drawdown,
        ];
    }

    /**
     * @param array<string, mixed> $drawdown
     * @return array{active: bool, value: float|null, threshold: int, description: string}
     */
    private function checkPriceSignal(array $drawdown): array
    {
        $dd = $drawdown['drawdown_pct'] ?? 0.0;

        return [
            'active' => $dd <= -20,
            'value' => $dd,
            'threshold' => -20,
            'description' => sprintf('IWDA %.1f%% from 52w high', $dd),
        ];
    }

    /**
     * @return array{active: bool, value: float|null, threshold: int, sustained_days: int, description: string}
     */
    private function checkVolatilitySignal(): array
    {
        // Primary: Yahoo Finance real-time VIX (^VIX, 1h cache)
        $latestVix = $this->marketData->getVixRealtime();

        // FRED historical data for sustained days check (previous daily closes)
        $vixData = $this->fredApi->getSeriesObservations('VIXCLS', 10, 'desc');

        // Fallback: if Yahoo unavailable, use FRED's latest value
        if ($latestVix === null && $vixData !== null) {
            foreach ($vixData as $obs) {
                if (isset($obs['value'])) {
                    $latestVix = (float) $obs['value'];
                    break;
                }
            }
        }

        if ($latestVix === null) {
            return [
                'active' => false,
                'value' => null,
                'threshold' => 30,
                'sustained_days' => 0,
                'description' => 'VIX data unavailable',
            ];
        }

        // Count consecutive days > 30 from FRED historical data
        $consecutiveDays = 0;
        if ($vixData !== null) {
            $reversed = array_reverse($vixData);
            for ($i = count($reversed) - 1; $i >= 0; $i--) {
                $value = $reversed[$i]['value'] ?? null;
                if ($value !== null && $value > 30) {
                    $consecutiveDays++;
                } else {
                    break;
                }
            }
        }

        // If current real-time VIX is also > 30, count today too
        if ($latestVix > 30 && $consecutiveDays === 0) {
            $consecutiveDays = 1;
        }

        return [
            'active' => $consecutiveDays >= 3,
            'value' => $latestVix,
            'threshold' => 30,
            'sustained_days' => $consecutiveDays,
            'description' => sprintf('VIX %.1f (>30 for %d/3 days)', $latestVix, $consecutiveDays),
        ];
    }

    /**
     * @return array{active: bool, value: float|null, threshold: int, description: string}
     */
    private function checkCreditSignal(): array
    {
        $hySpread = $this->fredApi->getLatestValue('BAMLH0A0HYM2');

        if ($hySpread === null || $hySpread['value'] === null) {
            return [
                'active' => false,
                'value' => null,
                'threshold' => 500,
                'description' => 'HY spread data unavailable',
            ];
        }

        $spreadBps = (int) round($hySpread['value'] * 100);

        return [
            'active' => $spreadBps > 500,
            'value' => (float) $spreadBps,
            'threshold' => 500,
            'description' => sprintf('HY Spread %dbps', $spreadBps),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getHistoricalCrises(): array
    {
        return $this->historicalCrises;
    }
}
