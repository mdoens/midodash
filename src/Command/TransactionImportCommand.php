<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\IbClient;
use App\Service\SaxoClient;
use App\Service\TransactionImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:transactions:import',
    description: 'Import transactions from IB Flex XML and Saxo API',
)]
class TransactionImportCommand extends Command
{
    public function __construct(
        private readonly IbClient $ibClient,
        private readonly SaxoClient $saxoClient,
        private readonly TransactionImportService $importService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ── IB transactions ──
        $output->writeln('<info>Importing IB transactions...</info>');

        $cacheFile = $this->ibClient->getCacheFile();

        if (!file_exists($cacheFile)) {
            $output->writeln('<comment>No IB statement cache file found. Run app:ib:fetch first.</comment>');
        } else {
            $xml = file_get_contents($cacheFile);
            if ($xml === false) {
                $output->writeln('<error>Could not read IB cache file.</error>');
            } else {
                $result = $this->importService->importFromIbXml($xml);
                $output->writeln(sprintf(
                    '<info>IB: %d imported, %d skipped (already exists)</info>',
                    $result['imported'],
                    $result['skipped'],
                ));
            }
        }

        // ── Saxo transactions ──
        $output->writeln('<info>Importing Saxo transactions...</info>');

        if (!$this->saxoClient->isAuthenticated()) {
            $output->writeln('<comment>Saxo not authenticated, skipping.</comment>');
        } else {
            $trades = $this->saxoClient->getHistoricalTrades();
            if ($trades === null) {
                $output->writeln('<comment>Could not fetch Saxo trades.</comment>');
            } else {
                $result = $this->importService->importFromSaxoOrders($trades);
                $output->writeln(sprintf(
                    '<info>Saxo: %d imported, %d skipped (already exists)</info>',
                    $result['imported'],
                    $result['skipped'],
                ));
            }
        }

        // ── Remap position names using symbol_map (fixes legacy data) ──
        $remapped = $this->importService->remapPositionNames();
        if ($remapped > 0) {
            $output->writeln(sprintf('<info>Remapped %d position names via symbol_map</info>', $remapped));
        }

        return Command::SUCCESS;
    }
}
