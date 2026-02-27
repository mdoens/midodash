<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PortfolioSnapshot;
use App\Entity\PositionSnapshot;
use App\Repository\PortfolioSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class PortfolioSnapshotService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PortfolioSnapshotRepository $repository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Save a daily portfolio snapshot (one per day, upsert).
     *
     * @param array<string, mixed> $allocation From PortfolioService::calculateAllocations()
     */
    public function saveSnapshot(
        array $allocation,
        string $regime,
        bool $saxoAvailable,
        bool $ibAvailable,
    ): void {
        try {
            $today = new \DateTime('today');
            $existing = $this->repository->findByDate($today);

            if ($existing !== null) {
                $this->logger->debug('Portfolio snapshot already exists for today, updating');
                $this->em->remove($existing);
                $this->em->flush();
            }

            $snapshot = new PortfolioSnapshot();
            $snapshot->setDate($today);
            $snapshot->setSnapshottedAt(new \DateTime());
            $snapshot->setTotalValue((string) ($allocation['total_value'] ?? 0));
            $snapshot->setTotalInvested((string) ($allocation['total_invested'] ?? 0));

            $ibCash = (float) ($allocation['ib_cash'] ?? 0);
            $saxoCash = (float) ($allocation['saxo_cash'] ?? 0);
            $snapshot->setTotalCash((string) ($ibCash + $saxoCash));
            $snapshot->setIbCash((string) $ibCash);
            $snapshot->setSaxoCash((string) $saxoCash);
            $snapshot->setTotalPl((string) ($allocation['total_pl'] ?? 0));

            $assetClasses = $allocation['asset_classes'] ?? [];
            $snapshot->setEquityPct((string) ($assetClasses['equity']['current_pct'] ?? 0));
            $snapshot->setFiPct((string) ($assetClasses['fixed_income']['current_pct'] ?? 0));
            $snapshot->setAltPct((string) ($assetClasses['alternatives']['current_pct'] ?? 0));
            $snapshot->setCashPct((string) ($allocation['cash_pct'] ?? 0));

            $snapshot->setRegime($regime);
            $snapshot->setSaxoAvailable($saxoAvailable);
            $snapshot->setIbAvailable($ibAvailable);

            $positions = $allocation['positions'] ?? [];
            foreach ($positions as $name => $pos) {
                $posSnapshot = new PositionSnapshot();
                $posSnapshot->setName($name);
                $posSnapshot->setTicker($pos['ticker'] ?? '');
                $posSnapshot->setPlatform($pos['platform'] ?? '');
                $posSnapshot->setAssetClass($pos['asset_class'] ?? '');
                $posSnapshot->setUnits((string) ($pos['units'] ?? 0));
                $posSnapshot->setValue((string) ($pos['value'] ?? 0));
                $posSnapshot->setCost((string) ($pos['cost'] ?? 0));
                $posSnapshot->setPl((string) ($pos['pl'] ?? 0));
                $posSnapshot->setPlPct((string) ($pos['pl_pct'] ?? 0));
                $posSnapshot->setCurrentPct((string) ($pos['current_pct'] ?? 0));
                $posSnapshot->setTargetPct((string) ($pos['target_pct'] ?? 0));
                $posSnapshot->setDrift((string) ($pos['drift'] ?? 0));
                $posSnapshot->setStatus($pos['status'] ?? 'ONTBREEKT');

                $snapshot->addPosition($posSnapshot);
            }

            $this->em->persist($snapshot);
            $this->em->flush();

            $this->logger->info('Portfolio snapshot saved', [
                'date' => $today->format('Y-m-d'),
                'total_value' => $allocation['total_value'] ?? 0,
                'positions' => count($positions),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Portfolio snapshot save failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getHistory(int $days = 365): array
    {
        $snapshots = $this->repository->findLastDays($days);
        $result = [];

        foreach ($snapshots as $snapshot) {
            $result[] = [
                'date' => $snapshot->getDate()->format('Y-m-d'),
                'total_value' => (float) $snapshot->getTotalValue(),
                'total_invested' => (float) $snapshot->getTotalInvested(),
                'total_cash' => (float) $snapshot->getTotalCash(),
                'total_pl' => (float) $snapshot->getTotalPl(),
                'equity_pct' => (float) $snapshot->getEquityPct(),
                'fi_pct' => (float) $snapshot->getFiPct(),
                'alt_pct' => (float) $snapshot->getAltPct(),
                'cash_pct' => (float) $snapshot->getCashPct(),
                'regime' => $snapshot->getRegime(),
                'saxo_available' => $snapshot->isSaxoAvailable(),
                'ib_available' => $snapshot->isIbAvailable(),
            ];
        }

        return $result;
    }
}
