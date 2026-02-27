<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * @return list<Transaction>
     */
    public function findFiltered(
        ?string $platform = null,
        ?string $type = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        int $limit = 100,
    ): array {
        $qb = $this->createQueryBuilder('t');

        if ($platform !== null) {
            $qb->andWhere('t.platform = :platform')->setParameter('platform', $platform);
        }

        if ($type !== null) {
            $qb->andWhere('t.type = :type')->setParameter('type', $type);
        }

        if ($from !== null) {
            $qb->andWhere('t.tradedAt >= :from')->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('t.tradedAt <= :to')->setParameter('to', $to);
        }

        /** @var list<Transaction> */
        return $qb->orderBy('t.tradedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function sumByType(string $type): float
    {
        /** @var string|null $sum */
        $sum = $this->createQueryBuilder('t')
            ->select('SUM(COALESCE(t.amountEur, t.amount))')
            ->where('t.type = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($sum ?? 0);
    }

    /**
     * @return array<string, float>
     */
    public function sumBuysBySymbol(): array
    {
        return $this->sumByTypeGrouped('buy');
    }

    /**
     * @return array<string, float>
     */
    public function sumSellsBySymbol(): array
    {
        return $this->sumByTypeGrouped('sell');
    }

    /**
     * @return array<string, float>
     */
    public function sumDividendsBySymbol(): array
    {
        return $this->sumByTypeGrouped('dividend');
    }

    /**
     * @return array<string, float>
     */
    private function sumByTypeGrouped(string $type): array
    {
        /** @var list<array{positionName: string, total: string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.positionName, SUM(ABS(COALESCE(t.amountEur, t.amount))) AS total')
            ->where('t.type = :type')
            ->setParameter('type', $type)
            ->groupBy('t.positionName')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['positionName']] = (float) $row['total'];
        }

        return $result;
    }

    /**
     * @return list<array{month: string, deposits: float, buys: float, sells: float, dividends: float, commissions: float, interest: float}>
     */
    public function getMonthlyOverview(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // Use SUBSTR for SQLite compatibility (works on MySQL too)
        /** @var list<array<string, string>> $rows */
        $rows = $conn->executeQuery(
            <<<'SQL'
            SELECT
                SUBSTR(traded_at, 1, 7) AS month,
                SUM(CASE WHEN type = 'deposit' THEN COALESCE(amount_eur, amount) ELSE 0 END) AS deposits,
                SUM(CASE WHEN type = 'buy' THEN ABS(COALESCE(amount_eur, amount)) ELSE 0 END) AS buys,
                SUM(CASE WHEN type = 'sell' THEN ABS(COALESCE(amount_eur, amount)) ELSE 0 END) AS sells,
                SUM(CASE WHEN type = 'dividend' THEN COALESCE(amount_eur, amount) ELSE 0 END) AS dividends,
                SUM(ABS(commission)) AS commissions,
                SUM(CASE WHEN type = 'interest' THEN COALESCE(amount_eur, amount) ELSE 0 END) AS interest
            FROM "transaction"
            GROUP BY SUBSTR(traded_at, 1, 7)
            ORDER BY month DESC
            SQL
        )->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'month' => $row['month'],
                'deposits' => (float) $row['deposits'],
                'buys' => (float) $row['buys'],
                'sells' => (float) $row['sells'],
                'dividends' => (float) $row['dividends'],
                'commissions' => (float) $row['commissions'],
                'interest' => (float) $row['interest'],
            ];
        }

        return $result;
    }

    public function existsByPlatformAndExternalId(string $platform, string $externalId): bool
    {
        /** @var int $count */
        $count = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.platform = :platform')
            ->andWhere('t.externalId = :externalId')
            ->setParameter('platform', $platform)
            ->setParameter('externalId', $externalId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
