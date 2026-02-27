<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PriceHistory;
use App\Repository\PriceHistoryRepository;
use App\Service\MomentumService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:prices:sync',
    description: 'Sync daily prices from Yahoo Finance to database',
)]
class PriceSyncCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PriceHistoryRepository $priceRepo,
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Number of days to fetch', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int) $input->getOption('days');
        $output->writeln(sprintf('<info>Syncing prices for %d days...</info>', $days));

        $tickers = array_keys(MomentumService::TICKERS);
        $totalInserted = 0;
        $totalSkipped = 0;

        foreach ($tickers as $ticker) {
            $output->write(sprintf('  %s ... ', $ticker));

            try {
                $result = $this->fetchDailyOHLCV($ticker, $days);
                $inserted = 0;
                $skipped = 0;

                foreach ($result as $row) {
                    $existing = $this->priceRepo->findOneBy([
                        'ticker' => $ticker,
                        'priceDate' => $row['date'],
                    ]);

                    if ($existing !== null) {
                        $skipped++;
                        continue;
                    }

                    $price = new PriceHistory();
                    $price->setTicker($ticker);
                    $price->setPriceDate($row['date']);
                    $price->setOpen((string) $row['open']);
                    $price->setHigh((string) $row['high']);
                    $price->setLow((string) $row['low']);
                    $price->setClose((string) $row['close']);
                    $price->setAdjClose((string) $row['adj_close']);
                    $price->setVolume((string) $row['volume']);

                    $this->em->persist($price);
                    $inserted++;
                }

                $this->em->flush();

                $totalInserted += $inserted;
                $totalSkipped += $skipped;
                $output->writeln(sprintf('<info>+%d new, %d existing</info>', $inserted, $skipped));
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>FAILED: %s</error>', $e->getMessage()));
                $this->logger->error('Price sync failed', ['ticker' => $ticker, 'error' => $e->getMessage()]);
            }
        }

        $output->writeln(sprintf(
            '<info>Done. Inserted: %d, Skipped: %d</info>',
            $totalInserted,
            $totalSkipped,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{date: \DateTime, open: float, high: float, low: float, close: float, adj_close: float, volume: int}>
     */
    private function fetchDailyOHLCV(string $ticker, int $days): array
    {
        $end = time();
        $start = $end - (int) ($days * 1.5 * 86400); // Extra margin for weekends/holidays

        $url = sprintf(
            'https://query1.finance.yahoo.com/v8/finance/chart/%s?period1=%d&period2=%d&interval=1d',
            urlencode($ticker),
            $start,
            $end,
        );

        $response = $this->httpClient->request('GET', $url, [
            'headers' => ['User-Agent' => 'Mozilla/5.0'],
            'timeout' => 15,
        ]);

        $data = $response->toArray(false);
        $result = $data['chart']['result'][0] ?? null;

        if ($result === null) {
            return [];
        }

        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];
        $adjClose = $result['indicators']['adjclose'][0]['adjclose'] ?? [];

        $rows = [];
        foreach ($timestamps as $i => $ts) {
            $close = $quote['close'][$i] ?? null;
            if ($close === null) {
                continue;
            }

            $rows[] = [
                'date' => new \DateTime(date('Y-m-d', (int) $ts)),
                'open' => (float) ($quote['open'][$i] ?? 0),
                'high' => (float) ($quote['high'][$i] ?? 0),
                'low' => (float) ($quote['low'][$i] ?? 0),
                'close' => (float) $close,
                'adj_close' => (float) ($adjClose[$i] ?? $close),
                'volume' => (int) ($quote['volume'][$i] ?? 0),
            ];
        }

        return $rows;
    }
}
