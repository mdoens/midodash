<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TransactionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Table(name: '`transaction`')]
#[ORM\UniqueConstraint(columns: ['platform', 'external_id'])]
#[ORM\Index(columns: ['traded_at'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private string $platform;

    #[ORM\Column(length: 100)]
    private string $externalId;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $tradedAt;

    #[ORM\Column(length: 30)]
    private string $symbol;

    #[ORM\Column(length: 255)]
    private string $positionName;

    #[ORM\Column(length: 20)]
    private string $type;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4)]
    private string $quantity;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4)]
    private string $price;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $amount;

    #[ORM\Column(length: 5)]
    private string $currency;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 6, nullable: true)]
    private ?string $fxRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    private ?string $amountEur = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 4)]
    private string $commission;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function setPlatform(string $platform): self
    {
        $this->platform = $platform;

        return $this;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): self
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getTradedAt(): \DateTimeInterface
    {
        return $this->tradedAt;
    }

    public function setTradedAt(\DateTimeInterface $tradedAt): self
    {
        $this->tradedAt = $tradedAt;

        return $this;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): self
    {
        $this->symbol = $symbol;

        return $this;
    }

    public function getPositionName(): string
    {
        return $this->positionName;
    }

    public function setPositionName(string $positionName): self
    {
        $this->positionName = $positionName;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): self
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getFxRate(): ?string
    {
        return $this->fxRate;
    }

    public function setFxRate(?string $fxRate): self
    {
        $this->fxRate = $fxRate;

        return $this;
    }

    public function getAmountEur(): ?string
    {
        return $this->amountEur;
    }

    public function setAmountEur(?string $amountEur): self
    {
        $this->amountEur = $amountEur;

        return $this;
    }

    public function getCommission(): string
    {
        return $this->commission;
    }

    public function setCommission(string $commission): self
    {
        $this->commission = $commission;

        return $this;
    }
}
