<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DataBufferRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DataBufferRepository::class)]
#[ORM\Table(name: 'data_buffer')]
#[ORM\UniqueConstraint(columns: ['source', 'data_type'])]
class DataBuffer
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $source;

    #[ORM\Column(length: 30)]
    private string $dataType;

    #[ORM\Column(type: Types::TEXT)]
    private string $data;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $fetchedAt;

    public function __construct()
    {
        $this->fetchedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;

        return $this;
    }

    public function getDataType(): string
    {
        return $this->dataType;
    }

    public function setDataType(string $dataType): self
    {
        $this->dataType = $dataType;

        return $this;
    }

    public function getData(): string
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDecodedData(): array
    {
        $decoded = json_decode($this->data, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    public function setData(string $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public function setEncodedData(array $data): self
    {
        $encoded = json_encode($data);
        $this->data = $encoded !== false ? $encoded : '{}';

        return $this;
    }

    public function getFetchedAt(): \DateTimeInterface
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(\DateTimeInterface $fetchedAt): self
    {
        $this->fetchedAt = $fetchedAt;

        return $this;
    }
}
