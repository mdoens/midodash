<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\IbClient;
use App\Service\TransactionImportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:transactions:import',
    description: 'Import transactions from IB Flex XML statements',
)]
class TransactionImportCommand extends Command
{
    public function __construct(
        private readonly IbClient $ibClient,
        private readonly TransactionImportService $importService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Importing IB transactions...</info>');

        // Read the cached IB Flex XML (same file used by IbClient)
        $cacheFile = $this->ibClient->getCacheFile();

        if (!file_exists($cacheFile)) {
            $output->writeln('<comment>No IB statement cache file found. Run app:dashboard:warmup first.</comment>');

            return Command::SUCCESS;
        }

        $xml = file_get_contents($cacheFile);
        if ($xml === false) {
            $output->writeln('<error>Could not read IB cache file.</error>');

            return Command::FAILURE;
        }

        $result = $this->importService->importFromIbXml($xml);
        $output->writeln(sprintf(
            '<info>IB: %d imported, %d skipped (already exists)</info>',
            $result['imported'],
            $result['skipped'],
        ));

        return Command::SUCCESS;
    }
}
