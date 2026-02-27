<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TransactionRepository;
use Psr\Log\LoggerInterface;

class ReturnsService
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
        private readonly SaxoClient $saxoClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $allocation
     *
     * @return array{total_deposits: float, total_withdrawals: float, net_deposits: float, current_value: float, total_return: float, total_return_pct: float, total_dividends: float, total_interest: float, total_commission: float, saxo_twr: float|null, saxo_sharpe: float|null, saxo_sortino: float|null, saxo_max_drawdown: float|null}
     */
    public function getPortfolioReturns(array $allocation): array
    {
        // IB deposits/withdrawals from transaction records (platform-filtered to avoid double-counting)
        $ibDeposits = $this->transactionRepository->sumByType('deposit', 'ib');
        $totalWithdrawals = abs($this->transactionRepository->sumByType('withdrawal', 'ib'));

        // Try to use Saxo performance metrics for accurate deposit data
        $saxoPerformance = null;
        try {
            $saxoPerformance = $this->saxoClient->getPerformanceMetrics();
        } catch (\Throwable $e) {
            $this->logger->debug('Saxo performance metrics not available', ['error' => $e->getMessage()]);
        }

        if ($saxoPerformance !== null && ($saxoPerformance['total_deposited'] ?? 0) > 0) {
            // Use exact Saxo deposit/withdrawal data from performance API
            $saxoDeposits = (float) $saxoPerformance['total_deposited'];
            $saxoWithdrawals = (float) ($saxoPerformance['total_withdrawn'] ?? 0);
            $totalWithdrawals += $saxoWithdrawals;
        } else {
            // Fallback: derive net deposits from allocation (cost basis = value - P/L)
            $saxoDeposits = 0.0;
            foreach ($allocation['positions'] ?? [] as $pos) {
                if (($pos['platform'] ?? '') === 'Saxo' && ($pos['value'] ?? 0) > 0) {
                    $saxoDeposits += (float) ($pos['value'] ?? 0) - (float) ($pos['pl'] ?? 0);
                }
            }
            $saxoDeposits += (float) ($allocation['saxo_cash'] ?? 0);
        }

        $totalDeposits = $ibDeposits + $saxoDeposits;
        $netDeposits = $totalDeposits - $totalWithdrawals;
        $currentValue = (float) ($allocation['total_portfolio'] ?? 0);
        $totalReturn = $currentValue - $netDeposits;
        $totalReturnPct = $netDeposits > 0 ? ($totalReturn / $netDeposits) * 100 : 0.0;
        $totalDividends = $this->transactionRepository->sumByType('dividend');
        $totalInterest = $this->transactionRepository->sumByType('interest');
        $totalCommission = abs($this->transactionRepository->sumByType('commission'));

        // Also sum commission from trade records
        $buyCommission = $this->sumTradeCommissions();
        $totalCommission += $buyCommission;

        return [
            'total_deposits' => $totalDeposits,
            'total_withdrawals' => $totalWithdrawals,
            'net_deposits' => $netDeposits,
            'current_value' => $currentValue,
            'total_return' => $totalReturn,
            'total_return_pct' => $totalReturnPct,
            'total_dividends' => $totalDividends,
            'total_interest' => $totalInterest,
            'total_commission' => $totalCommission,
            'saxo_twr' => $saxoPerformance !== null ? (float) $saxoPerformance['twr'] : null,
            'saxo_sharpe' => $saxoPerformance !== null ? (float) $saxoPerformance['sharpe_ratio'] : null,
            'saxo_sortino' => $saxoPerformance !== null ? (float) $saxoPerformance['sortino_ratio'] : null,
            'saxo_max_drawdown' => $saxoPerformance !== null ? (float) $saxoPerformance['max_drawdown'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $allocation
     *
     * @return list<array{name: string, total_bought: float, total_sold: float, realized_pl: float, current_value: float, unrealized_pl: float, total_return: float, dividends: float, source: string}>
     */
    public function getPositionReturns(array $allocation): array
    {
        $buys = $this->transactionRepository->sumBuysBySymbol();
        $sells = $this->transactionRepository->sumSellsBySymbol();
        $dividends = $this->transactionRepository->sumDividendsBySymbol();

        // Fetch Saxo closed positions for accurate realized P/L
        $closedPl = $this->getClosedPositionProfitLoss();

        /** @var array<string, array<string, mixed>> $positions */
        $positions = $allocation['positions'] ?? [];

        $result = [];
        $allNames = array_unique(array_merge(array_keys($buys), array_keys($sells), array_keys($dividends), array_keys($positions), array_keys($closedPl)));
        sort($allNames);

        foreach ($allNames as $name) {
            $totalBought = $buys[$name] ?? 0.0;
            $totalSold = $sells[$name] ?? 0.0;
            $currentValue = (float) ($positions[$name]['value'] ?? 0);
            $livePl = (float) ($positions[$name]['pl'] ?? 0);

            // For positions without transaction data (e.g. Saxo), derive cost basis from live P/L
            $hasTransactions = $totalBought > 0 || $totalSold > 0;

            if ($hasTransactions && $currentValue > 0 && $livePl !== 0.0) {
                // Has both transactions and live broker P/L — prefer broker P/L for open positions
                // because transaction history may be incomplete (e.g. cross-platform moves)
                $unrealizedPl = $livePl;
                $costBasis = $currentValue - $livePl;
                $realizedPl = 0.0;
            } elseif ($hasTransactions) {
                $netInvested = $totalBought - $totalSold;
                $unrealizedPl = $currentValue > 0 ? $currentValue - $netInvested : 0.0;
                // Realized P/L only for fully closed positions (no current value left)
                $realizedPl = $currentValue === 0.0 && $totalSold > 0 ? $totalSold - $totalBought : 0.0;
                $costBasis = $netInvested;
            } else {
                // No transactions: use live allocation data (value - pl = cost basis)
                $realizedPl = 0.0;
                $costBasis = $currentValue > 0 ? $currentValue - $livePl : 0.0;
                $unrealizedPl = $livePl;
                $totalBought = $costBasis > 0 ? $costBasis : 0.0;
            }

            // Add Saxo closed position P/L if available and no realized P/L from transactions
            if ($realizedPl === 0.0 && isset($closedPl[$name])) {
                $realizedPl = $closedPl[$name];
            }

            $totalReturn = $realizedPl + $unrealizedPl + ($dividends[$name] ?? 0.0);

            // Skip positions with no data at all
            if ($totalBought === 0.0 && $totalSold === 0.0 && $currentValue === 0.0 && ($dividends[$name] ?? 0.0) === 0.0) {
                continue;
            }

            $result[] = [
                'name' => $name,
                'total_bought' => $totalBought,
                'total_sold' => $totalSold,
                'realized_pl' => $realizedPl,
                'current_value' => $currentValue,
                'unrealized_pl' => $unrealizedPl,
                'total_return' => $totalReturn,
                'dividends' => $dividends[$name] ?? 0.0,
                'source' => $hasTransactions ? 'transactions' : 'live',
            ];
        }

        // Sort by current value descending
        usort($result, fn(array $a, array $b): int => (int) (($b['current_value'] * 100) - ($a['current_value'] * 100)));

        return $result;
    }

    /**
     * @return array{combined: list<array{month: string, deposits: float, buys: float, sells: float, dividends: float, commissions: float, interest: float}>, ib: list<array{month: string, deposits: float, buys: float, sells: float, dividends: float, commissions: float, interest: float}>, saxo: list<array{month: string, deposits: float, buys: float, sells: float, dividends: float, commissions: float, interest: float}>}
     */
    public function getMonthlyOverview(): array
    {
        return $this->transactionRepository->getMonthlyOverview();
    }

    /**
     * Get realized P/L from Saxo closed positions, keyed by position name.
     *
     * @return array<string, float>
     */
    private function getClosedPositionProfitLoss(): array
    {
        try {
            $closedPositions = $this->saxoClient->getClosedPositions();
        } catch (\Throwable $e) {
            $this->logger->debug('Saxo closed positions not available', ['error' => $e->getMessage()]);

            return [];
        }

        if ($closedPositions === null) {
            return [];
        }

        $result = [];
        foreach ($closedPositions as $pos) {
            $name = $pos['description'] !== '' ? $pos['description'] : $pos['symbol'];
            // Strip exchange suffix for matching (e.g. ":xams")
            $symbol = preg_replace('/:[\w]+$/', '', $pos['symbol']) ?? $pos['symbol'];

            // Use description as key (matches portfolio position names)
            $key = $name;

            if (!isset($result[$key])) {
                $result[$key] = 0.0;
            }
            $result[$key] += $pos['profit_loss'];
        }

        return $result;
    }

    private function sumTradeCommissions(): float
    {
        /** @var string|null $sum */
        $sum = $this->transactionRepository->createQueryBuilder('t')
            ->select('SUM(ABS(t.commission))')
            ->where('t.type = :buy OR t.type = :sell')
            ->setParameter('buy', 'buy')
            ->setParameter('sell', 'sell')
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($sum ?? 0);
    }
}
