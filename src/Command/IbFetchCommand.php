<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\IbClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:ib:fetch',
    description: 'Fetch IB Flex statement and save to cache file',
)]
class IbFetchCommand extends Command
{
    public function __construct(
        private readonly IbClient $ibClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Fetching IB Flex statement...</info>');

        $content = $this->ibClient->fetchStatement();

        if ($content === null) {
            $output->writeln('<error>Failed to fetch IB statement.</error>');

            return Command::FAILURE;
        }

        $cacheFile = $this->ibClient->getCacheFile();
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($cacheFile, $content);

        $output->writeln(sprintf('<info>Statement saved to %s (%d bytes)</info>', $cacheFile, strlen($content)));

        return Command::SUCCESS;
    }
}
