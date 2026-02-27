<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PositionSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PositionSnapshot>
 */
class PositionSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PositionSnapshot::class);
    }

    /**
     * @return list<PositionSnapshot>
     */
    public function findHistoryByName(string $name, int $days = 365): array
    {
        $from = new \DateTime("-{$days} days");

        /** @var list<PositionSnapshot> */
        return $this->createQueryBuilder('p')
            ->join('p.portfolioSnapshot', 's')
            ->where('p.name = :name')
            ->andWhere('s.date >= :from')
            ->setParameter('name', $name)
            ->setParameter('from', $from->format('Y-m-d'))
            ->orderBy('s.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
