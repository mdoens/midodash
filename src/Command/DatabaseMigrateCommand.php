<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:db:migrate',
    description: 'Create/update database schema from Doctrine entities',
)]
class DatabaseMigrateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $schemaTool = new SchemaTool($this->em);
            $metadata = $this->em->getMetadataFactory()->getAllMetadata();

            if ($metadata === []) {
                $io->warning('No Doctrine entities found.');

                return Command::SUCCESS;
            }

            $schemaTool->updateSchema($metadata);

            $entityNames = array_map(
                static fn(object $m): string => $m->getName(),
                $metadata,
            );

            $io->success(sprintf('Database schema updated for %d entities.', count($metadata)));
            $io->listing($entityNames);

            $this->logger->info('Database schema migrated', ['entities' => count($metadata)]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('Schema update failed: ' . $e->getMessage());
            $this->logger->error('Database migration failed', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }
    }
}
