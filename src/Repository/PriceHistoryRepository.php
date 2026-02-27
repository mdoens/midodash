<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PriceHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceHistory>
 */
class PriceHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceHistory::class);
    }

    /**
     * @return list<PriceHistory>
     */
    public function findByTickerAndRange(string $ticker, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        /** @var list<PriceHistory> */
        return $this->createQueryBuilder('p')
            ->where('p.ticker = :ticker')
            ->andWhere('p.priceDate >= :from')
            ->andWhere('p.priceDate <= :to')
            ->setParameter('ticker', $ticker)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('p.priceDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestByTicker(string $ticker): ?PriceHistory
    {
        /** @var PriceHistory|null */
        return $this->createQueryBuilder('p')
            ->where('p.ticker = :ticker')
            ->setParameter('ticker', $ticker)
            ->orderBy('p.priceDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
