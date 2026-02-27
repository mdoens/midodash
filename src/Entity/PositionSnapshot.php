<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PositionSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PositionSnapshotRepository::class)]
#[ORM\Table(name: 'position_snapshot')]
class PositionSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PortfolioSnapshot::class, inversedBy: 'positions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PortfolioSnapshot $portfolioSnapshot;

    #[ORM\Column(length: 50)]
    private string $name;

    #[ORM\Column(length: 30)]
    private string $ticker;

    #[ORM\Column(length: 10)]
    private string $platform;

    #[ORM\Column(length: 20)]
    private string $assetClass;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 4)]
    private string $units;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $value;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $cost;

    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2)]
    private string $pl;

    #[ORM\Column(type: Types::DECIMAL, precision: 8, scale: 2)]
    private string $plPct;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $currentPct;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $targetPct;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
    private string $drift;

    #[ORM\Column(length: 12)]
    private string $status;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPortfolioSnapshot(): PortfolioSnapshot
    {
        return $this->portfolioSnapshot;
    }

    public function setPortfolioSnapshot(PortfolioSnapshot $portfolioSnapshot): self
    {
        $this->portfolioSnapshot = $portfolioSnapshot;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
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

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function setPlatform(string $platform): self
    {
        $this->platform = $platform;

        return $this;
    }

    public function getAssetClass(): string
    {
        return $this->assetClass;
    }

    public function setAssetClass(string $assetClass): self
    {
        $this->assetClass = $assetClass;

        return $this;
    }

    public function getUnits(): string
    {
        return $this->units;
    }

    public function setUnits(string $units): self
    {
        $this->units = $units;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getCost(): string
    {
        return $this->cost;
    }

    public function setCost(string $cost): self
    {
        $this->cost = $cost;

        return $this;
    }

    public function getPl(): string
    {
        return $this->pl;
    }

    public function setPl(string $pl): self
    {
        $this->pl = $pl;

        return $this;
    }

    public function getPlPct(): string
    {
        return $this->plPct;
    }

    public function setPlPct(string $plPct): self
    {
        $this->plPct = $plPct;

        return $this;
    }

    public function getCurrentPct(): string
    {
        return $this->currentPct;
    }

    public function setCurrentPct(string $currentPct): self
    {
        $this->currentPct = $currentPct;

        return $this;
    }

    public function getTargetPct(): string
    {
        return $this->targetPct;
    }

    public function setTargetPct(string $targetPct): self
    {
        $this->targetPct = $targetPct;

        return $this;
    }

    public function getDrift(): string
    {
        return $this->drift;
    }

    public function setDrift(string $drift): self
    {
        $this->drift = $drift;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }
}
