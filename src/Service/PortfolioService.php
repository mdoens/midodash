<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

class PortfolioService
{
    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(
        private readonly string $projectDir,
    ) {
        $file = $this->projectDir . '/config/mido_v65.yaml';
        $yaml = file_exists($file) ? Yaml::parseFile($file) : [];
        $this->config = $yaml['mido'] ?? [];
    }

    /**
     * @return array<string, array{target: int, ticker: string, platform: string, asset_class: string}>
     */
    public function getTargets(): array
    {
        return $this->config['targets'] ?? [];
    }

    /**
     * @return array<string, array{target: int, label: string, band_low: float, band_high: float}>
     */
    public function getAssetClassTargets(): array
    {
        return $this->config['asset_classes'] ?? [];
    }

    /**
     * @return array<string, int>
     */
    public function getGeoTargets(): array
    {
        return $this->config['geo_targets'] ?? [];
    }

    /**
     * @return array<int, array{factor: string, score: float}>
     */
    public function getFactorData(): array
    {
        return $this->config['factors'] ?? [];
    }

    /**
     * @return array<int, array{position: string, factors: array<string>, description: string}>
     */
    public function getFactorMapping(): array
    {
        return $this->config['factor_mapping'] ?? [];
    }

    /**
     * @return array<int, array{level: int, condition: string, amount: int, source: string}>
     */
    public function getDeploymentProtocol(): array
    {
        return $this->config['deployment'] ?? [];
    }

    /**
     * @return array<string>
     */
    public function getFiveQuestions(): array
    {
        return $this->config['five_questions'] ?? [];
    }

    /**
     * Match live positions from IB/Saxo to v6.5 targets and calculate allocations.
     *
     * @param array<int, array<string, mixed>> $ibPositions
     * @param array<int, array<string, mixed>>|null $saxoPositions
     * @param float $ibCash
     * @param float $saxoCash
     * @return array{
     *   positions: array<string, array<string, mixed>>,
     *   total_portfolio: float,
     *   total_invested: float,
     *   total_cash: float,
     *   ib_cash: float,
     *   saxo_cash: float,
     *   asset_classes: array<string, array<string, mixed>>,
     *   rebal_needed: array<string, array<string, mixed>>,
     *   dry_powder: float,
     *   platform_split: array{ibkr: float, saxo: float}
     * }
     */
    public function calculateAllocations(
        array $ibPositions,
        ?array $saxoPositions,
        float $ibCash,
        float $saxoCash,
    ): array {
        $targets = $this->getTargets();
        $symbolMap = $this->config['symbol_map'] ?? [];

        // Initialize positions from targets
        $positions = [];
        foreach ($targets as $name => $config) {
            $positions[$name] = [
                'name' => $name,
                'target' => (int) $config['target'],
                'ticker' => $config['ticker'],
                'platform' => $config['platform'],
                'asset_class' => $config['asset_class'],
                'units' => 0.0,
                'value' => 0.0,
                'pl' => 0.0,
                'pl_pct' => 0.0,
                'matched' => false,
            ];
        }

        // Match IB positions
        foreach ($ibPositions as $pos) {
            $symbol = $pos['symbol'] ?? '';
            $targetName = $symbolMap[$symbol] ?? $symbol;

            if (isset($positions[$targetName])) {
                // Aggregate (for crypto with multiple symbols)
                $positions[$targetName]['units'] += (float) ($pos['amount'] ?? 0);
                $positions[$targetName]['value'] += (float) ($pos['value'] ?? 0);
                $positions[$targetName]['pl'] += (float) ($pos['pnl'] ?? $pos['value'] - ($pos['cost'] ?? $pos['value']));
                $positions[$targetName]['matched'] = true;
            } else {
                // Unmatched IB position (not in v6.5 targets) — show as extra
                $value = (float) ($pos['value'] ?? 0);
                $pl = (float) ($pos['pnl'] ?? 0);
                if (!isset($positions[$targetName])) {
                    $positions[$targetName] = [
                        'name' => $targetName,
                        'target' => 0,
                        'ticker' => $symbol,
                        'platform' => 'IBKR',
                        'asset_class' => 'equity',
                        'units' => 0.0,
                        'value' => 0.0,
                        'pl' => 0.0,
                        'pl_pct' => 0.0,
                        'matched' => true,
                        'extra' => true,
                    ];
                }
                $positions[$targetName]['units'] += (float) ($pos['amount'] ?? 0);
                $positions[$targetName]['value'] += $value;
                $positions[$targetName]['pl'] += $pl;
            }
        }

        // Build ISIN reverse lookup for Saxo fallback matching
        $isinMap = [];
        foreach ($targets as $name => $config) {
            if (isset($config['isin'])) {
                $isinMap[$config['isin']] = $name;
            }
        }

        // Match Saxo positions (by symbol_map, then by ISIN, then by description)
        if ($saxoPositions !== null) {
            foreach ($saxoPositions as $pos) {
                $symbol = $pos['symbol'] ?? '';
                $description = $pos['description'] ?? '';
                $targetName = $symbolMap[$symbol] ?? null;

                // Fallback: match by description containing target name
                if ($targetName === null) {
                    foreach ($targets as $name => $config) {
                        if ($config['platform'] !== 'Saxo') {
                            continue;
                        }
                        // Match on partial name (e.g. "NT World Custom" matches "NT World")
                        if (stripos($description, $name) !== false) {
                            $targetName = $name;
                            break;
                        }
                    }
                }

                if ($targetName !== null && isset($positions[$targetName])) {
                    $value = (float) ($pos['exposure'] ?? ($pos['amount'] * ($pos['current_price'] ?? 0)));
                    $positions[$targetName]['units'] += (float) ($pos['amount'] ?? 0);
                    $positions[$targetName]['value'] += $value;
                    $positions[$targetName]['pl'] += (float) ($pos['pnl_base'] ?? $pos['pnl'] ?? 0);
                    $positions[$targetName]['matched'] = true;
                }
            }
        }

        // Calculate totals
        $totalInvested = array_sum(array_column($positions, 'value'));
        $totalCash = $ibCash + $saxoCash;
        $totalPortfolio = $totalInvested + $totalCash;

        // Calculate percentages and drifts
        foreach ($positions as $name => &$pos) {
            $pos['current_pct'] = $totalPortfolio > 0 ? ($pos['value'] / $totalPortfolio) * 100 : 0;
            $pos['drift'] = $pos['current_pct'] - $pos['target'];
            $pos['drift_relative'] = $pos['target'] > 0 ? ($pos['drift'] / $pos['target']) * 100 : 0;

            if ($pos['value'] > 0 && $pos['pl'] != 0) {
                $cost = $pos['value'] - $pos['pl'];
                $pos['pl_pct'] = $cost > 0 ? ($pos['pl'] / $cost) * 100 : 0;
            }

            // Status
            $missing = $pos['target'] > 0 && $pos['value'] == 0;
            if ($missing) {
                $pos['status'] = 'ONTBREEKT';
            } elseif (abs($pos['drift']) >= 5 || abs($pos['drift_relative']) >= 25) {
                $pos['status'] = 'REBAL';
            } elseif (abs($pos['drift']) >= 3) {
                $pos['status'] = 'MONITOR';
            } else {
                $pos['status'] = 'OK';
            }
        }
        unset($pos);

        // Asset class breakdown
        $assetClassTargets = $this->getAssetClassTargets();
        $assetClasses = [];
        foreach ($assetClassTargets as $key => $config) {
            $classPositions = array_filter($positions, fn(array $p): bool => $p['asset_class'] === $key);
            $totalValue = array_sum(array_column($classPositions, 'value'));
            $currentPct = $totalPortfolio > 0 ? ($totalValue / $totalPortfolio) * 100 : 0;
            $drift = $currentPct - $config['target'];
            $inBand = $currentPct >= $config['band_low'] && $currentPct <= $config['band_high'];

            $assetClasses[$key] = [
                'label' => $config['label'],
                'target' => $config['target'],
                'band_low' => $config['band_low'],
                'band_high' => $config['band_high'],
                'total_value' => $totalValue,
                'current_pct' => round($currentPct, 1),
                'drift' => round($drift, 1),
                'in_band' => $inBand,
            ];
        }

        // Rebalancing alerts (5/25 rule)
        $rebalNeeded = array_filter($positions, function (array $p): bool {
            if ($p['target'] === 0) {
                return false;
            }

            return abs($p['drift']) >= 5 || abs($p['drift_relative']) >= 25;
        });

        // Dry powder
        $dryPowder = 0.0;
        foreach (['XEON', 'IBGS'] as $dpName) {
            $dryPowder += $positions[$dpName]['value'] ?? 0;
        }
        $dryPowder += $totalCash;

        // Platform split
        $ibkrValue = 0.0;
        $saxoValue = 0.0;
        foreach ($positions as $pos) {
            if ($pos['platform'] === 'IBKR') {
                $ibkrValue += $pos['value'];
            } else {
                $saxoValue += $pos['value'];
            }
        }
        $ibkrValue += $ibCash;
        $saxoValue += $saxoCash;

        return [
            'positions' => $positions,
            'total_portfolio' => $totalPortfolio,
            'total_invested' => $totalInvested,
            'total_cash' => $totalCash,
            'ib_cash' => $ibCash,
            'saxo_cash' => $saxoCash,
            'cash_pct' => $totalPortfolio > 0 ? round(($totalCash / $totalPortfolio) * 100, 1) : 0,
            'asset_classes' => $assetClasses,
            'rebal_needed' => $rebalNeeded,
            'dry_powder' => $dryPowder,
            'platform_split' => ['ibkr' => $ibkrValue, 'saxo' => $saxoValue],
            'total_pl' => array_sum(array_column($positions, 'pl')),
        ];
    }
}
