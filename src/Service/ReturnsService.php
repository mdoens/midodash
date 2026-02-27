<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\TransactionRepository;

class ReturnsService
{
    public function __construct(
        private readonly TransactionRepository $transactionRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $allocation
     *
     * @return array{total_deposits: float, total_withdrawals: float, net_deposits: float, current_value: float, total_return: float, total_return_pct: float, total_dividends: float, total_interest: float, total_commission: float}
     */
    public function getPortfolioReturns(array $allocation): array
    {
        $totalDeposits = $this->transactionRepository->sumByType('deposit');
        $totalWithdrawals = abs($this->transactionRepository->sumByType('withdrawal'));
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

        /** @var array<string, array<string, mixed>> $positions */
        $positions = $allocation['positions'] ?? [];

        $result = [];
        $allNames = array_unique(array_merge(array_keys($buys), array_keys($sells), array_keys($dividends), array_keys($positions)));
        sort($allNames);

        foreach ($allNames as $name) {
            $totalBought = $buys[$name] ?? 0.0;
            $totalSold = $sells[$name] ?? 0.0;
            $currentValue = (float) ($positions[$name]['value'] ?? 0);
            $livePl = (float) ($positions[$name]['pl'] ?? 0);

            // For positions without transaction data (e.g. Saxo), derive cost basis from live P/L
            $hasTransactions = $totalBought > 0 || $totalSold > 0;

            if ($hasTransactions) {
                $realizedPl = $totalSold - $totalBought;
                $unrealizedPl = $currentValue > 0 ? $currentValue - $totalBought + $totalSold : 0.0;
                $costBasis = $totalBought - $totalSold;
            } else {
                // No transactions: use live allocation data (value - pl = cost basis)
                $realizedPl = 0.0;
                $costBasis = $currentValue > 0 ? $currentValue - $livePl : 0.0;
                $unrealizedPl = $livePl;
                $totalBought = $costBasis > 0 ? $costBasis : 0.0;
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
     * @return list<array{month: string, deposits: float, buys: float, sells: float, dividends: float, commissions: float, interest: float}>
     */
    public function getMonthlyOverview(): array
    {
        return $this->transactionRepository->getMonthlyOverview();
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
