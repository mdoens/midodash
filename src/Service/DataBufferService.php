<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DataBuffer;
use App\Repository\DataBufferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class DataBufferService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DataBufferRepository $repository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Store API response data in the buffer (upsert).
     *
     * @param array<int|string, mixed> $data
     */
    public function store(string $source, string $dataType, array $data): void
    {
        try {
            $buffer = $this->repository->findBySourceAndType($source, $dataType);

            if ($buffer === null) {
                $buffer = new DataBuffer();
                $buffer->setSource($source);
                $buffer->setDataType($dataType);
                $this->em->persist($buffer);
            }

            $buffer->setEncodedData($data);
            $buffer->setFetchedAt(new \DateTime());
            $this->em->flush();

            $this->logger->debug('DataBuffer stored', ['source' => $source, 'type' => $dataType]);
        } catch (\Throwable $e) {
            $this->logger->warning('DataBuffer store failed', [
                'source' => $source,
                'type' => $dataType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retrieve buffered data. Returns null if no buffer exists.
     *
     * @return array{data: array<string, mixed>, fetched_at: \DateTimeInterface}|null
     */
    public function retrieve(string $source, string $dataType): ?array
    {
        try {
            $buffer = $this->repository->findBySourceAndType($source, $dataType);

            if ($buffer === null) {
                return null;
            }

            return [
                'data' => $buffer->getDecodedData(),
                'fetched_at' => $buffer->getFetchedAt(),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('DataBuffer retrieve failed', [
                'source' => $source,
                'type' => $dataType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the timestamp of the last buffered data for a source+type.
     */
    public function getBufferedAt(string $source, string $dataType): ?\DateTimeInterface
    {
        try {
            $buffer = $this->repository->findBySourceAndType($source, $dataType);

            return $buffer?->getFetchedAt();
        } catch (\Throwable) {
            return null;
        }
    }
}
