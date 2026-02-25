<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\SaxoClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:saxo:refresh',
    description: 'Refresh Saxo access token om sessie actief te houden',
)]
class SaxoRefreshCommand extends Command
{
    public function __construct(
        private readonly SaxoClient $saxoClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->saxoClient->isAuthenticated()) {
            $output->writeln('<comment>Niet ingelogd bij Saxo — skip</comment>');

            return Command::SUCCESS;
        }

        $result = $this->saxoClient->refreshToken();

        if ($result !== null) {
            $ttl = $this->saxoClient->getRefreshTokenTtl();
            $output->writeln(sprintf(
                '<info>Token vernieuwd. Refresh token geldig nog %d minuten.</info>',
                $ttl !== null ? (int) ($ttl / 60) : 0,
            ));

            return Command::SUCCESS;
        }

        $output->writeln('<error>Token refresh mislukt. Opnieuw inloggen nodig.</error>');

        return Command::FAILURE;
    }
}
