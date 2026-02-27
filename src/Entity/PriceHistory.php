<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PriceHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PriceHistoryRepository::class)]
#[ORM\Table(name: 'price_history')]
#[ORM\UniqueConstraint(columns: ['ticker', 'price_date'])]
#[ORM\Index(columns: ['ticker', 'price_date'])]
class PriceHistory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $ticker;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private \DateTimeInterface $priceDate;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4)]
    private string $close;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $adjClose = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $open = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $high = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4, nullable: true)]
    private ?string $low = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?string $volume = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicker(): string
    {
        return $this->ticker;
    }

    public function setTicker(string $ticker): self
    {
        $this->ticker = $ticker;

        return $this;
    }

    public function getPriceDate(): \DateTimeInterface
    {
        return $this->priceDate;
    }

    public function setPriceDate(\DateTimeInterface $priceDate): self
    {
        $this->priceDate = $priceDate;

        return $this;
    }

    public function getClose(): string
    {
        return $this->close;
    }

    public function setClose(string $close): self
    {
        $this->close = $close;

        return $this;
    }

    public function getAdjClose(): ?string
    {
        return $this->adjClose;
    }

    public function setAdjClose(?string $adjClose): self
    {
        $this->adjClose = $adjClose;

        return $this;
    }

    public function getOpen(): ?string
    {
        return $this->open;
    }

    public function setOpen(?string $open): self
    {
        $this->open = $open;

        return $this;
    }

    public function getHigh(): ?string
    {
        return $this->high;
    }

    public function setHigh(?string $high): self
    {
        $this->high = $high;

        return $this;
    }

    public function getLow(): ?string
    {
        return $this->low;
    }

    public function setLow(?string $low): self
    {
        $this->low = $low;

        return $this;
    }

    public function getVolume(): ?string
    {
        return $this->volume;
    }

    public function setVolume(?string $volume): self
    {
        $this->volume = $volume;

        return $this;
    }
}
