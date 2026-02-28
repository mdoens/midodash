<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\DataBufferService;
use App\Service\IbClient;
use App\Service\PortfolioService;
use App\Service\ReturnsService;
use App\Service\SaxoClient;
use Psr\Log\LoggerInterface;

class McpPortfolioService
{
    public function __construct(
        private readonly PortfolioService $portfolioService,
        private readonly ReturnsService $returnsService,
        private readonly SaxoClient $saxoClient,
        private readonly IbClient $ibClient,
        private readonly DataBufferService $dataBuffer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getPortfolioSnapshot(string $format): string|array
    {
        $allocation = $this->fetchLiveAllocation();
        $positionReturns = $this->returnsService->getPositionReturns($allocation);
        $portfolioReturns = $this->returnsService->getPortfolioReturns($allocation);

        $returnsMap = [];
        foreach ($positionReturns as $pr) {
            $returnsMap[$pr['name']] = $pr;
        }

        $positions = [];
        foreach ($allocation['positions'] as $name => $pos) {
            $returns = $returnsMap[$name] ?? null;
            $positions[] = [
                'name' => $name,
                'ticker' => $pos['ticker'],
                'platform' => $pos['platform'],
                'asset_class' => $pos['asset_class'],
                'units' => round($pos['units'], 4),
                'value' => round($pos['value'], 2),
                'target_pct' => $pos['target'],
                'current_pct' => round($pos['current_pct'], 2),
                'drift' => round($pos['drift'], 2),
                'drift_relative' => round($pos['drift_relative'] ?? 0, 1),
                'pl' => round($pos['pl'], 2),
                'pl_pct' => round($pos['pl_pct'], 2),
                'dividends' => round($returns['dividends'] ?? 0, 2),
                'total_return' => round($returns['total_return'] ?? $pos['pl'], 2),
                'status' => $pos['status'],
                'extra' => $pos['extra'] ?? false,
            ];
        }

        usort($positions, fn(array $a, array $b): int => (int) (($b['value'] * 100) - ($a['value'] * 100)));

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'strategy_version' => 'v8.0',
            'data_freshness' => [
                'saxo' => $this->isSaxoLive($allocation) ? 'live' : 'buffered',
                'ib' => $this->ibClient->getCacheTimestamp() !== null ? 'live' : 'buffered',
            ],
            'summary' => [
                'total_portfolio' => round($allocation['total_portfolio'], 2),
                'total_invested' => round($allocation['total_invested'], 2),
                'total_cash' => round($allocation['total_cash'], 2),
                'cash_pct' => $allocation['cash_pct'],
                'total_pl' => round($allocation['total_pl'], 2),
                'total_return' => round($portfolioReturns['total_return'], 2),
                'total_return_pct' => round($portfolioReturns['total_return_pct'], 2),
                'dry_powder' => round($allocation['dry_powder'], 2),
            ],
            'positions' => $positions,
            'asset_classes' => $allocation['asset_classes'],
            'platform_split' => [
                'ibkr' => round($allocation['platform_split']['ibkr'], 2),
                'saxo' => round($allocation['platform_split']['saxo'], 2),
            ],
            'rebal_needed' => array_keys($allocation['rebal_needed']),
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatSnapshotMarkdown($data);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getCashOverview(string $format): string|array
    {
        $saxoBalance = null;
        $saxoOrders = null;

        try {
            $saxoBalance = $this->saxoClient->getAccountBalance();
            $saxoOrders = $this->saxoClient->getOpenOrders();
        } catch (\Throwable $e) {
            $this->logger->debug('Saxo data unavailable for cash overview', ['error' => $e->getMessage()]);
        }

        $ibCash = $this->ibClient->getCashReport();

        $saxoCashAvailable = (float) ($saxoBalance['CashAvailableForTrading'] ?? $saxoBalance['TotalValue'] ?? 0) -
            (float) ($saxoBalance['NonMarginPositionsValue'] ?? 0);
        if ($saxoCashAvailable <= 0 && $saxoBalance !== null) {
            $saxoCashAvailable = (float) ($saxoBalance['CashBalance'] ?? 0);
        }

        $ibCashAmount = (float) ($ibCash['ending_cash'] ?? 0);
        $totalCash = $saxoCashAvailable + $ibCashAmount;

        $openOrdersValue = 0.0;
        $ordersList = [];
        if ($saxoOrders !== null) {
            foreach ($saxoOrders as $order) {
                $openOrdersValue += (float) $order['order_value'];
                $ordersList[] = [
                    'symbol' => $order['symbol'],
                    'description' => $order['description'],
                    'direction' => $order['buy_sell'],
                    'value' => round((float) $order['order_value'], 2),
                    'type' => $order['order_type'],
                    'status' => $order['status'],
                ];
            }
        }

        $allocation = $this->fetchLiveAllocation();
        $xeonValue = $allocation['positions']['XEON']['value'] ?? 0;
        $ibgsValue = $allocation['positions']['IBGS']['value'] ?? 0;
        $dryPowder = $totalCash + $xeonValue + $ibgsValue;

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'data_freshness' => [
                'saxo' => $saxoBalance !== null ? 'live' : 'buffered',
                'ib' => $ibCash !== [] ? 'live' : 'buffered',
            ],
            'cash_balances' => [
                'saxo' => round($saxoCashAvailable, 2),
                'ibkr' => round($ibCashAmount, 2),
                'total' => round($totalCash, 2),
            ],
            'open_orders' => [
                'count' => count($ordersList),
                'total_value' => round($openOrdersValue, 2),
                'orders' => $ordersList,
            ],
            'dry_powder' => [
                'cash' => round($totalCash, 2),
                'xeon' => round((float) $xeonValue, 2),
                'ibgs' => round((float) $ibgsValue, 2),
                'total' => round($dryPowder, 2),
            ],
            'deployable' => [
                'immediate' => round($totalCash, 2),
                'within_1d' => round($totalCash + $xeonValue, 2),
                'within_3d' => round($dryPowder, 2),
            ],
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatCashMarkdown($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchLiveAllocation(): array
    {
        $ibPositions = $this->ibClient->getPositions();
        $saxoPositions = null;
        $saxoCash = 0.0;
        $openOrders = [];

        try {
            $saxoPositions = $this->saxoClient->getPositions();
            $balance = $this->saxoClient->getAccountBalance();
            $saxoCash = (float) ($balance['CashBalance'] ?? 0);

            // Include open order value in Saxo cash — Saxo deducts from CashBalance
            // when order is placed, but position doesn't exist yet
            $openOrders = $this->saxoClient->getOpenOrders() ?? [];
            foreach ($openOrders as $order) {
                $saxoCash += (float) $order['order_value'];
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Saxo data fetch failed', ['error' => $e->getMessage()]);
        }

        // Fallback to DataBuffer when Saxo returns null (auth expired, API down)
        if ($saxoPositions === null) {
            $buffered = $this->dataBuffer->retrieve('saxo', 'positions');
            if ($buffered !== null) {
                $saxoPositions = $buffered['data'];
                $this->logger->info('Saxo using buffered positions for MCP');
            }

            if ($saxoCash === 0.0) {
                $balanceBuffered = $this->dataBuffer->retrieve('saxo', 'balance');
                if ($balanceBuffered !== null) {
                    $saxoCash = (float) ($balanceBuffered['data']['CashBalance'] ?? 0);
                }
            }
        }

        $ibCashReport = $this->ibClient->getCashReport();
        $ibCash = (float) ($ibCashReport['ending_cash'] ?? 0);

        return $this->portfolioService->calculateAllocations($ibPositions, $saxoPositions, $ibCash, $saxoCash, $openOrders);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatSnapshotMarkdown(array $data): string
    {
        $s = $data['summary'];
        $md = "# MIDO Portfolio Snapshot (v8.0)\n\n";
        $md .= "_Generated: {$data['timestamp']}_\n";
        $md .= "_Data: Saxo={$data['data_freshness']['saxo']}, IB={$data['data_freshness']['ib']}_\n\n";

        $md .= "## Summary\n\n";
        $md .= "| Metric | Value |\n";
        $md .= "|--------|-------|\n";
        $md .= sprintf("| Total Portfolio | €%s |\n", number_format($s['total_portfolio'], 0, ',', '.'));
        $md .= sprintf("| Total Invested | €%s |\n", number_format($s['total_invested'], 0, ',', '.'));
        $md .= sprintf("| Total Cash | €%s (%.1f%%) |\n", number_format($s['total_cash'], 0, ',', '.'), $s['cash_pct']);
        $md .= sprintf("| Total P/L | €%s |\n", number_format($s['total_pl'], 0, ',', '.'));
        $md .= sprintf("| Total Return | €%s (%.1f%%) |\n", number_format($s['total_return'], 0, ',', '.'), $s['total_return_pct']);
        $md .= sprintf("| Dry Powder | €%s |\n", number_format($s['dry_powder'], 0, ',', '.'));

        $md .= "\n## Positions\n\n";
        $md .= "| Position | Platform | Value | Weight | Target | Drift | P/L | Status |\n";
        $md .= "|----------|----------|-------|--------|--------|-------|-----|--------|\n";

        foreach ($data['positions'] as $pos) {
            $statusIcon = match ($pos['status']) {
                'OK' => '🟢',
                'MONITOR' => '🟡',
                'REBAL' => '🔴',
                'ONTBREEKT' => '⚫',
                default => '⚪',
            };
            $extra = $pos['extra'] ? ' ⚠️' : '';
            $md .= sprintf(
                "| %s%s | %s | €%s | %.1f%% | %d%% | %+.1f%% | €%s (%.1f%%) | %s %s |\n",
                $pos['name'],
                $extra,
                $pos['platform'],
                number_format($pos['value'], 0, ',', '.'),
                $pos['current_pct'],
                $pos['target_pct'],
                $pos['drift'],
                number_format($pos['pl'], 0, ',', '.'),
                $pos['pl_pct'],
                $statusIcon,
                $pos['status'],
            );
        }

        $md .= "\n## Asset Classes\n\n";
        $md .= "| Class | Current | Target | Band | Status |\n";
        $md .= "|-------|---------|--------|------|--------|\n";
        foreach ($data['asset_classes'] as $key => $ac) {
            $bandStatus = $ac['in_band'] ? '🟢 In band' : '🔴 Out of band';
            $md .= sprintf(
                "| %s | %.1f%% | %d%% | %.0f-%.0f%% | %s |\n",
                $ac['label'],
                $ac['current_pct'],
                $ac['target'],
                $ac['band_low'],
                $ac['band_high'],
                $bandStatus,
            );
        }

        $md .= "\n## Platform Split\n\n";
        $md .= sprintf("| IBKR | €%s |\n", number_format($data['platform_split']['ibkr'], 0, ',', '.'));
        $md .= sprintf("| Saxo | €%s |\n", number_format($data['platform_split']['saxo'], 0, ',', '.'));

        if ($data['rebal_needed'] !== []) {
            $md .= "\n## ⚠️ Rebalancing Needed\n\n";
            $md .= implode(', ', $data['rebal_needed']) . "\n";
        }

        return $md;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatCashMarkdown(array $data): string
    {
        $md = "# MIDO Cash Overview\n\n";
        $md .= "_Generated: {$data['timestamp']}_\n";
        $md .= "_Data: Saxo={$data['data_freshness']['saxo']}, IB={$data['data_freshness']['ib']}_\n\n";

        $md .= "## Cash Balances\n\n";
        $md .= "| Platform | Cash |\n";
        $md .= "|----------|------|\n";
        $md .= sprintf("| Saxo | €%s |\n", number_format($data['cash_balances']['saxo'], 2, ',', '.'));
        $md .= sprintf("| IBKR | €%s |\n", number_format($data['cash_balances']['ibkr'], 2, ',', '.'));
        $md .= sprintf("| **Total** | **€%s** |\n", number_format($data['cash_balances']['total'], 2, ',', '.'));

        if ($data['open_orders']['count'] > 0) {
            $md .= "\n## Open Orders\n\n";
            $md .= "| Symbol | Direction | Value | Type | Status |\n";
            $md .= "|--------|-----------|-------|------|--------|\n";
            foreach ($data['open_orders']['orders'] as $order) {
                $md .= sprintf(
                    "| %s | %s | €%s | %s | %s |\n",
                    $order['symbol'],
                    $order['direction'],
                    number_format($order['value'], 2, ',', '.'),
                    $order['type'],
                    $order['status'],
                );
            }
            $md .= sprintf("\n**Total open order value:** €%s\n", number_format($data['open_orders']['total_value'], 2, ',', '.'));
        }

        $dp = $data['dry_powder'];
        $md .= "\n## Dry Powder Breakdown\n\n";
        $md .= "| Source | Value |\n";
        $md .= "|--------|-------|\n";
        $md .= sprintf("| Cash | €%s |\n", number_format($dp['cash'], 2, ',', '.'));
        $md .= sprintf("| XEON (money market) | €%s |\n", number_format($dp['xeon'], 2, ',', '.'));
        $md .= sprintf("| IBGS (short bonds) | €%s |\n", number_format($dp['ibgs'], 2, ',', '.'));
        $md .= sprintf("| **Total Dry Powder** | **€%s** |\n", number_format($dp['total'], 2, ',', '.'));

        $deploy = $data['deployable'];
        $md .= "\n## Deployable Capital\n\n";
        $md .= "| Timeline | Amount |\n";
        $md .= "|----------|--------|\n";
        $md .= sprintf("| Immediate (cash) | €%s |\n", number_format($deploy['immediate'], 2, ',', '.'));
        $md .= sprintf("| Within 1 day (+XEON) | €%s |\n", number_format($deploy['within_1d'], 2, ',', '.'));
        $md .= sprintf("| Within 3 days (+IBGS) | €%s |\n", number_format($deploy['within_3d'], 2, ',', '.'));

        return $md;
    }

    /**
     * Check if Saxo data is live by verifying Saxo positions have values.
     *
     * @param array<string, mixed> $allocation
     */
    private function isSaxoLive(array $allocation): bool
    {
        foreach ($allocation['positions'] as $pos) {
            if (($pos['platform'] ?? '') === 'Saxo' && ($pos['value'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
