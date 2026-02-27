<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Entity\PortfolioSnapshot;
use App\Repository\PortfolioSnapshotRepository;
use App\Service\SaxoClient;

class McpPerformanceService
{
    public function __construct(
        private readonly PortfolioSnapshotRepository $snapshotRepo,
        private readonly SaxoClient $saxoClient,
    ) {
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getPerformanceHistory(string $format, string $period, bool $includeBenchmark): string|array
    {
        $days = $this->periodToDays($period);
        $snapshots = $this->snapshotRepo->findLastDays($days);

        if ($snapshots === []) {
            return ['error' => true, 'message' => 'No portfolio snapshots available. Data collection starts after the first daily snapshot.'];
        }

        $history = [];
        foreach ($snapshots as $snap) {
            $history[] = [
                'date' => $snap->getDate()->format('Y-m-d'),
                'total_value' => (float) $snap->getTotalValue(),
                'total_invested' => (float) $snap->getTotalInvested(),
                'total_cash' => (float) $snap->getTotalCash(),
                'total_pl' => (float) $snap->getTotalPl(),
                'equity_pct' => (float) $snap->getEquityPct(),
                'fi_pct' => (float) $snap->getFiPct(),
                'alt_pct' => (float) $snap->getAltPct(),
                'regime' => $snap->getRegime(),
            ];
        }

        $firstValue = $history[0]['total_value'];
        $lastValue = $history[count($history) - 1]['total_value'];
        $absoluteReturn = $lastValue - $firstValue;
        $pctReturn = $firstValue > 0 ? (($lastValue / $firstValue) - 1) * 100 : 0;

        $twr = $this->calculateTWR($snapshots);

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
                'from' => $history[0]['date'],
                'to' => $history[count($history) - 1]['date'],
            ],
            'summary' => [
                'start_value' => round($firstValue, 2),
                'end_value' => round($lastValue, 2),
                'absolute_return' => round($absoluteReturn, 2),
                'pct_return' => round($pctReturn, 2),
                'twr' => $twr !== null ? round($twr * 100, 2) : null,
                'saxo_twr' => $saxoMetrics !== null ? round((float) $saxoMetrics['twr'] * 100, 2) : null,
                'peak_value' => round(max(array_column($history, 'total_value')), 2),
                'trough_value' => round(min(array_column($history, 'total_value')), 2),
            ],
            'history' => $history,
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatPerformanceMarkdown($data);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getAttribution(string $format, string $period, string $groupBy): string|array
    {
        $days = $this->periodToDays($period);
        $snapshots = $this->snapshotRepo->findLastDays($days);

        if (count($snapshots) < 2) {
            return ['error' => true, 'message' => "Insufficient data for attribution: need at least 2 snapshots, found " . count($snapshots)];
        }

        $firstSnap = $snapshots[0];
        $lastSnap = $snapshots[count($snapshots) - 1];

        $startPositions = [];
        foreach ($firstSnap->getPositions() as $pos) {
            $startPositions[$pos->getName()] = [
                'value' => (float) $pos->getValue(),
                'pct' => (float) $pos->getCurrentPct(),
                'asset_class' => $pos->getAssetClass(),
                'platform' => $pos->getPlatform(),
            ];
        }

        $endPositions = [];
        foreach ($lastSnap->getPositions() as $pos) {
            $endPositions[$pos->getName()] = [
                'value' => (float) $pos->getValue(),
                'pct' => (float) $pos->getCurrentPct(),
                'asset_class' => $pos->getAssetClass(),
                'platform' => $pos->getPlatform(),
            ];
        }

        $attribution = [];
        $allNames = array_unique(array_merge(array_keys($startPositions), array_keys($endPositions)));

        foreach ($allNames as $name) {
            $startValue = $startPositions[$name]['value'] ?? 0;
            $endValue = $endPositions[$name]['value'] ?? 0;
            $startWeight = ($startPositions[$name]['pct'] ?? 0) / 100;
            $posReturn = $startValue > 0 ? ($endValue - $startValue) / $startValue : 0;
            $contribution = $startWeight * $posReturn;

            $attribution[$name] = [
                'name' => $name,
                'asset_class' => $endPositions[$name]['asset_class'] ?? $startPositions[$name]['asset_class'] ?? 'unknown',
                'platform' => $endPositions[$name]['platform'] ?? $startPositions[$name]['platform'] ?? 'unknown',
                'start_value' => round($startValue, 2),
                'end_value' => round($endValue, 2),
                'return_pct' => round($posReturn * 100, 2),
                'weight' => round($startWeight * 100, 2),
                'contribution' => round($contribution * 100, 3),
            ];
        }

        $grouped = $this->groupAttribution($attribution, $groupBy);

        usort($grouped, fn(array $a, array $b): int => (int) (($b['contribution'] * 1000) - ($a['contribution'] * 1000)));

        $totalContribution = array_sum(array_column($grouped, 'contribution'));

        $startTotal = (float) $firstSnap->getTotalValue();
        $endTotal = (float) $lastSnap->getTotalValue();

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'period' => $period,
            'group_by' => $groupBy,
            'date_range' => [
                'from' => $firstSnap->getDate()->format('Y-m-d'),
                'to' => $lastSnap->getDate()->format('Y-m-d'),
            ],
            'portfolio_return' => $startTotal > 0 ? round((($endTotal / $startTotal) - 1) * 100, 2) : 0,
            'total_contribution' => round($totalContribution, 3),
            'attribution' => $grouped,
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatAttributionMarkdown($data);
    }

    /**
     * @param list<PortfolioSnapshot> $snapshots
     */
    private function calculateTWR(array $snapshots): ?float
    {
        if (count($snapshots) < 2) {
            return null;
        }

        $compoundReturn = 1.0;
        for ($i = 1, $count = count($snapshots); $i < $count; $i++) {
            $prevValue = (float) $snapshots[$i - 1]->getTotalValue();
            $currValue = (float) $snapshots[$i]->getTotalValue();

            if ($prevValue > 0) {
                $dailyReturn = $currValue / $prevValue;
                $compoundReturn *= $dailyReturn;
            }
        }

        return $compoundReturn - 1;
    }

    /**
     * @param array<string, array<string, mixed>> $attribution
     * @return list<array<string, mixed>>
     */
    private function groupAttribution(array $attribution, string $groupBy): array
    {
        if ($groupBy === 'position') {
            return array_values($attribution);
        }

        $groups = [];
        foreach ($attribution as $item) {
            $key = match ($groupBy) {
                'asset_class' => $item['asset_class'],
                'platform' => $item['platform'],
                'geography' => $this->inferGeography($item['name']),
                default => $item['name'],
            };

            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'name' => $key,
                    'start_value' => 0.0,
                    'end_value' => 0.0,
                    'contribution' => 0.0,
                    'positions' => [],
                ];
            }

            $groups[$key]['start_value'] += $item['start_value'];
            $groups[$key]['end_value'] += $item['end_value'];
            $groups[$key]['contribution'] += $item['contribution'];
            $groups[$key]['positions'][] = $item['name'];
        }

        foreach ($groups as &$group) {
            $group['start_value'] = round($group['start_value'], 2);
            $group['end_value'] = round($group['end_value'], 2);
            $group['return_pct'] = $group['start_value'] > 0
                ? round((($group['end_value'] / $group['start_value']) - 1) * 100, 2)
                : 0;
            $group['contribution'] = round($group['contribution'], 3);
        }
        unset($group);

        return array_values($groups);
    }

    private function inferGeography(string $positionName): string
    {
        return match ($positionName) {
            'NTWC', 'AVWC' => 'Developed World',
            'NTEM' => 'Emerging Markets',
            'AVWS' => 'Global Small Cap',
            'XEON', 'IBGS' => 'Europe (Fixed Income)',
            'EGLN' => 'Global (Gold)',
            'Crypto' => 'Global (Crypto)',
            default => 'Other',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatPerformanceMarkdown(array $data): string
    {
        $s = $data['summary'];
        $md = "# MIDO Performance History\n\n";
        $md .= "_Period: {$data['period']} ({$data['data_points']} data points, {$data['date_range']['from']} — {$data['date_range']['to']})_\n\n";

        $md .= "## Summary\n\n";
        $md .= "| Metric | Value |\n";
        $md .= "|--------|-------|\n";
        $md .= sprintf("| Start Value | €%s |\n", number_format($s['start_value'], 0, ',', '.'));
        $md .= sprintf("| End Value | €%s |\n", number_format($s['end_value'], 0, ',', '.'));
        $md .= sprintf("| Absolute Return | €%s |\n", number_format($s['absolute_return'], 0, ',', '.'));
        $md .= sprintf("| Return | %.2f%% |\n", $s['pct_return']);

        if ($s['twr'] !== null) {
            $md .= sprintf("| TWR (portfolio) | %.2f%% |\n", $s['twr']);
        }
        if ($s['saxo_twr'] !== null) {
            $md .= sprintf("| TWR (Saxo all-time) | %.2f%% |\n", $s['saxo_twr']);
        }

        $md .= sprintf("| Peak | €%s |\n", number_format($s['peak_value'], 0, ',', '.'));
        $md .= sprintf("| Trough | €%s |\n", number_format($s['trough_value'], 0, ',', '.'));

        $md .= "\n## Monthly Values (last 12)\n\n";
        $md .= "| Date | Value | P/L | Regime |\n";
        $md .= "|------|-------|-----|--------|\n";

        $displayHistory = $data['history'];
        $step = max(1, (int) floor(count($displayHistory) / 12));
        for ($i = 0; $i < count($displayHistory); $i += $step) {
            $h = $displayHistory[$i];
            $md .= sprintf(
                "| %s | €%s | €%s | %s |\n",
                $h['date'],
                number_format($h['total_value'], 0, ',', '.'),
                number_format($h['total_pl'], 0, ',', '.'),
                $h['regime'],
            );
        }

        $last = $displayHistory[count($displayHistory) - 1];
        if ($i - $step !== count($displayHistory) - 1) {
            $md .= sprintf(
                "| %s | €%s | €%s | %s |\n",
                $last['date'],
                number_format($last['total_value'], 0, ',', '.'),
                number_format($last['total_pl'], 0, ',', '.'),
                $last['regime'],
            );
        }

        return $md;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatAttributionMarkdown(array $data): string
    {
        $md = "# MIDO Return Attribution\n\n";
        $md .= "_Period: {$data['period']} ({$data['date_range']['from']} — {$data['date_range']['to']})_\n";
        $md .= "_Grouped by: {$data['group_by']}_\n\n";
        $md .= sprintf("**Portfolio Return:** %.2f%%\n\n", $data['portfolio_return']);

        $md .= "## Attribution\n\n";
        $md .= "| Group | Start | End | Return | Contribution |\n";
        $md .= "|-------|-------|-----|--------|--------------|\n";

        foreach ($data['attribution'] as $attr) {
            $md .= sprintf(
                "| %s | €%s | €%s | %+.2f%% | %+.3f%% |\n",
                $attr['name'],
                number_format($attr['start_value'], 0, ',', '.'),
                number_format($attr['end_value'], 0, ',', '.'),
                $attr['return_pct'],
                $attr['contribution'],
            );
        }

        $md .= sprintf("\n**Sum of contributions:** %.3f%%\n", $data['total_contribution']);

        return $md;
    }

    private function periodToDays(string $period): int
    {
        return match ($period) {
            '1m' => 30,
            '3m' => 90,
            '6m' => 180,
            '1y' => 365,
            'ytd' => max(1, (int) (new \DateTime())->diff(new \DateTime('first day of January'))->days),
            'all' => 3650,
            default => 365,
        };
    }
}
