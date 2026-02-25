<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\MomentumService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:momentum:warmup',
    description: 'Warm up momentum signal cache',
)]
class MomentumWarmupCommand extends Command
{
    public function __construct(
        private readonly MomentumService $momentumService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Computing momentum signal...</info>');

        try {
            $signal = $this->momentumService->getSignal();
            $output->writeln(sprintf(
                '<info>Signal computed: %s (regime: %s)</info>',
                $signal['reason'] ?? 'unknown',
                ($signal['regime']['bull'] ?? false) ? 'bull' : 'bear',
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }
    }
}
