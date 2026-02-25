<?php

declare(strict_types=1);

namespace App\Command;

use App\Controller\DashboardController;
use App\Service\CalculationService;
use App\Service\CrisisService;
use App\Service\DashboardCacheService;
use App\Service\DxyService;
use App\Service\EurostatService;
use App\Service\FredApiService;
use App\Service\GoldPriceService;
use App\Service\IbClient;
use App\Service\MomentumService;
use App\Service\PortfolioService;
use App\Service\SaxoClient;
use App\Service\TriggerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:dashboard:warmup',
    description: 'Pre-compute and cache all dashboard data for instant page loads',
)]
class DashboardWarmupCommand extends Command
{
    public function __construct(
        private readonly DashboardController $dashboardController,
        private readonly DashboardCacheService $dashboardCache,
        private readonly IbClient $ibClient,
        private readonly SaxoClient $saxoClient,
        private readonly MomentumService $momentumService,
        private readonly PortfolioService $portfolioService,
        private readonly FredApiService $fredApi,
        private readonly CrisisService $crisisService,
        private readonly CalculationService $calculations,
        private readonly EurostatService $eurostat,
        private readonly GoldPriceService $goldPrice,
        private readonly DxyService $dxyService,
        private readonly TriggerService $triggerService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Warming up dashboard cache...</info>');
        $start = microtime(true);

        try {
            $data = $this->dashboardController->computeDashboardData(
                $this->ibClient,
                $this->saxoClient,
                $this->momentumService,
                $this->portfolioService,
                $this->fredApi,
                $this->crisisService,
                $this->calculations,
                $this->eurostat,
                $this->goldPrice,
                $this->dxyService,
                $this->triggerService,
                $this->logger,
            );

            $this->dashboardCache->save($data);

            $elapsed = round(microtime(true) - $start, 1);
            $output->writeln(sprintf(
                '<info>Dashboard cache warmed in %ss. Portfolio: €%s, Positions: %d</info>',
                $elapsed,
                number_format($data['allocation']['total_portfolio'] ?? 0, 0, ',', '.'),
                count($data['allocation']['positions'] ?? []),
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Warmup failed: %s</error>', $e->getMessage()));
            $this->logger->error('Dashboard warmup failed', ['error' => $e->getMessage()]);

            return Command::FAILURE;
        }
    }
}
