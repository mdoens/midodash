<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DataBuffer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataBuffer>
 */
class DataBufferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataBuffer::class);
    }

    public function findBySourceAndType(string $source, string $dataType): ?DataBuffer
    {
        /** @var DataBuffer|null */
        return $this->createQueryBuilder('b')
            ->where('b.source = :source')
            ->andWhere('b.dataType = :dataType')
            ->setParameter('source', $source)
            ->setParameter('dataType', $dataType)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
