<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PortfolioSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortfolioSnapshot>
 */
class PortfolioSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortfolioSnapshot::class);
    }

    public function findByDate(\DateTimeInterface $date): ?PortfolioSnapshot
    {
        /** @var PortfolioSnapshot|null */
        return $this->createQueryBuilder('s')
            ->where('s.date = :date')
            ->setParameter('date', $date->format('Y-m-d'))
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<PortfolioSnapshot>
     */
    public function findByDateRange(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        /** @var list<PortfolioSnapshot> */
        return $this->createQueryBuilder('s')
            ->where('s.date >= :from')
            ->andWhere('s.date <= :to')
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('s.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<PortfolioSnapshot>
     */
    public function findLastDays(int $days): array
    {
        $from = new \DateTime("-{$days} days");

        /** @var list<PortfolioSnapshot> */
        return $this->createQueryBuilder('s')
            ->where('s.date >= :from')
            ->setParameter('from', $from->format('Y-m-d'))
            ->orderBy('s.date', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatest(): ?PortfolioSnapshot
    {
        /** @var PortfolioSnapshot|null */
        return $this->createQueryBuilder('s')
            ->orderBy('s.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
