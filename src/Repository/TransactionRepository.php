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
