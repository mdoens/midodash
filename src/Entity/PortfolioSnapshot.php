<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PortfolioSnapshotRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PortfolioSnapshotRepository::class)]
#[ORM\Table(name: 'portfolio_snapshot')]
#[ORM\UniqueConstraint(columns: ['date'])]
class PortfolioSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private \DateTimeInterface $date;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $snapshottedAt;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $totalValue;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $totalInvested;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $totalCash;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $ibCash;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $saxoCash;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $totalPl;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $equityPct;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $fiPct;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $altPct;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $cashPct;

    #[ORM\Column(length: 20)]
    private string $regime;

    #[ORM\Column]
    private bool $saxoAvailable;

    #[ORM\Column]
    private bool $ibAvailable;

    /** @var Collection<int, PositionSnapshot> */
    #[ORM\OneToMany(targetEntity: PositionSnapshot::class, mappedBy: 'portfolioSnapshot', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $positions;

    public function __construct()
    {
        $this->positions = new ArrayCollection();
        $this->snapshottedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): \DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(\DateTimeInterface $date): self
    {
        $this->date = $date;

        return $this;
    }

    public function getSnapshottedAt(): \DateTimeInterface
    {
        return $this->snapshottedAt;
    }

    public function setSnapshottedAt(\DateTimeInterface $snapshottedAt): self
    {
        $this->snapshottedAt = $snapshottedAt;

        return $this;
    }

    public function getTotalValue(): string
    {
        return $this->totalValue;
    }

    public function setTotalValue(string $totalValue): self
    {
        $this->totalValue = $totalValue;

        return $this;
    }

    public function getTotalInvested(): string
    {
        return $this->totalInvested;
    }

    public function setTotalInvested(string $totalInvested): self
    {
        $this->totalInvested = $totalInvested;

        return $this;
    }

    public function getTotalCash(): string
    {
        return $this->totalCash;
    }

    public function setTotalCash(string $totalCash): self
    {
        $this->totalCash = $totalCash;

        return $this;
    }

    public function getIbCash(): string
    {
        return $this->ibCash;
    }

    public function setIbCash(string $ibCash): self
    {
        $this->ibCash = $ibCash;

        return $this;
    }

    public function getSaxoCash(): string
    {
        return $this->saxoCash;
    }

    public function setSaxoCash(string $saxoCash): self
    {
        $this->saxoCash = $saxoCash;

        return $this;
    }

    public function getTotalPl(): string
    {
        return $this->totalPl;
    }

    public function setTotalPl(string $totalPl): self
    {
        $this->totalPl = $totalPl;

        return $this;
    }

    public function getEquityPct(): string
    {
        return $this->equityPct;
    }

    public function setEquityPct(string $equityPct): self
    {
        $this->equityPct = $equityPct;

        return $this;
    }

    public function getFiPct(): string
    {
        return $this->fiPct;
    }

    public function setFiPct(string $fiPct): self
    {
        $this->fiPct = $fiPct;

        return $this;
    }

    public function getAltPct(): string
    {
        return $this->altPct;
    }

    public function setAltPct(string $altPct): self
    {
        $this->altPct = $altPct;

        return $this;
    }

    public function getCashPct(): string
    {
        return $this->cashPct;
    }

    public function setCashPct(string $cashPct): self
    {
        $this->cashPct = $cashPct;

        return $this;
    }

    public function getRegime(): string
    {
        return $this->regime;
    }

    public function setRegime(string $regime): self
    {
        $this->regime = $regime;

        return $this;
    }

    public function isSaxoAvailable(): bool
    {
        return $this->saxoAvailable;
    }

    public function setSaxoAvailable(bool $saxoAvailable): self
    {
        $this->saxoAvailable = $saxoAvailable;

        return $this;
    }

    public function isIbAvailable(): bool
    {
        return $this->ibAvailable;
    }

    public function setIbAvailable(bool $ibAvailable): self
    {
        $this->ibAvailable = $ibAvailable;

        return $this;
    }

    /** @return Collection<int, PositionSnapshot> */
    public function getPositions(): Collection
    {
        return $this->positions;
    }

    public function addPosition(PositionSnapshot $position): self
    {
        if (!$this->positions->contains($position)) {
            $this->positions->add($position);
            $position->setPortfolioSnapshot($this);
        }

        return $this;
    }
}
