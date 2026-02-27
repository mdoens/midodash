<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Repository\TransactionRepository;
use App\Service\PortfolioService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class McpPlanningService
{
    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(
        private readonly PortfolioService $portfolioService,
        private readonly TransactionRepository $transactionRepo,
        private readonly McpPortfolioService $portfolioMcp,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
        $file = $this->projectDir . '/config/mido_v65.yaml';
        $yaml = file_exists($file) ? Yaml::parseFile($file) : [];
        $this->config = $yaml['mido'] ?? [];
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getCostAnalysis(string $format, string $period): string|array
    {
        $days = $this->periodToDays($period);
        $from = new \DateTimeImmutable("-{$days} days");
        $to = new \DateTimeImmutable();

        $buyTx = $this->transactionRepo->findFiltered(null, 'buy', $from, $to, 1000);
        $sellTx = $this->transactionRepo->findFiltered(null, 'sell', $from, $to, 1000);

        $platformCosts = ['ib' => 0.0, 'saxo' => 0.0];
        $totalTraded = ['ib' => 0.0, 'saxo' => 0.0];

        foreach (array_merge($buyTx, $sellTx) as $tx) {
            $platform = $tx->getPlatform();
            $commission = abs((float) $tx->getCommission());
            $amount = abs((float) ($tx->getAmountEur() ?? $tx->getAmount()));

            if (isset($platformCosts[$platform])) {
                $platformCosts[$platform] += $commission;
                $totalTraded[$platform] += $amount;
            }
        }

        $targets = $this->config['targets'] ?? [];
        $allocation = $this->portfolioMcp->fetchLiveAllocation();
        $totalPortfolio = $allocation['total_portfolio'];

        $positionTer = [];
        $weightedTer = 0.0;
        foreach ($allocation['positions'] as $name => $pos) {
            $ter = (float) ($targets[$name]['ter'] ?? 0);
            $weight = (float) $pos['current_pct'] / 100;
            $annualCost = $pos['value'] * ($ter / 100);
            $positionTer[] = [
                'position' => $name,
                'value' => round($pos['value'], 2),
                'weight' => round($weight * 100, 2),
                'ter' => $ter,
                'annual_cost' => round($annualCost, 2),
            ];
            $weightedTer += $weight * $ter;
        }

        usort($positionTer, fn(array $a, array $b): int => (int) (($b['annual_cost'] * 100) - ($a['annual_cost'] * 100)));

        $totalTransactionCosts = $platformCosts['ib'] + $platformCosts['saxo'];
        $totalAnnualTer = $totalPortfolio * ($weightedTer / 100);

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'period' => $period,
            'transaction_costs' => [
                'ib' => [
                    'commission' => round($platformCosts['ib'], 2),
                    'traded_volume' => round($totalTraded['ib'], 2),
                    'cost_ratio' => $totalTraded['ib'] > 0 ? round(($platformCosts['ib'] / $totalTraded['ib']) * 100, 4) : 0,
                ],
                'saxo' => [
                    'commission' => round($platformCosts['saxo'], 2),
                    'traded_volume' => round($totalTraded['saxo'], 2),
                    'cost_ratio' => $totalTraded['saxo'] > 0 ? round(($platformCosts['saxo'] / $totalTraded['saxo']) * 100, 4) : 0,
                ],
                'total' => round($totalTransactionCosts, 2),
            ],
            'fund_costs' => [
                'weighted_ter' => round($weightedTer, 3),
                'annual_cost' => round($totalAnnualTer, 2),
                'positions' => $positionTer,
            ],
            'total_cost_ratio' => [
                'ter_annual' => round($weightedTer, 3),
                'transaction_annualized' => $totalPortfolio > 0 ? round(($totalTransactionCosts / $totalPortfolio) * 100 * (365 / max(1, $days)), 4) : 0,
                'total_annual_pct' => round($weightedTer + ($totalPortfolio > 0 ? ($totalTransactionCosts / $totalPortfolio) * 100 * (365 / max(1, $days)) : 0), 3),
            ],
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatCostMarkdown($data);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getFundLookthrough(string $format, ?string $position): string|array
    {
        $holdings = $this->config['holdings'] ?? [];
        $targets = $this->config['targets'] ?? [];

        $results = [];
        $positionsToLookup = $position !== null ? [$position] : array_keys($targets);

        foreach ($positionsToLookup as $posName) {
            if (isset($holdings[$posName])) {
                $results[$posName] = [
                    'source' => 'config',
                    'data' => $holdings[$posName],
                ];
                continue;
            }

            $target = $targets[$posName] ?? null;
            if ($target === null) {
                continue;
            }

            $yahooData = $this->fetchYahooHoldings($target['ticker'] ?? '', $posName);
            if ($yahooData !== null) {
                $results[$posName] = [
                    'source' => 'yahoo_finance',
                    'data' => $yahooData,
                ];
            }
        }

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'positions' => $results,
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatLookthroughMarkdown($data);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getFundamentals(string $format, string $ticker): string|array
    {
        $cacheKey = 'mcp_fundamentals_' . str_replace(['.', ':', '/'], '_', $ticker);

        /** @var array<string, mixed> $fundamentals */
        $fundamentals = $this->cache->get($cacheKey, function (ItemInterface $item) use ($ticker): array {
            $item->expiresAfter(3600);

            return $this->fetchYahooFundamentals($ticker);
        });

        if (isset($fundamentals['error'])) {
            return $fundamentals;
        }

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'ticker' => $ticker,
            'fundamentals' => $fundamentals,
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatFundamentalsMarkdown($data);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getRebalanceAdvice(string $format, ?float $cashToDeploy): string|array
    {
        $allocation = $this->portfolioMcp->fetchLiveAllocation();
        $totalPortfolio = $allocation['total_portfolio'];
        $targets = $this->portfolioService->getTargets();
        $momentumConfig = $this->config['momentum'] ?? [];

        if ($cashToDeploy !== null) {
            $totalPortfolio += $cashToDeploy;
        }

        $orders = [];
        foreach ($allocation['positions'] as $name => $pos) {
            if ($pos['target'] === 0) {
                if ($pos['value'] > 0) {
                    $orders[] = [
                        'position' => $name,
                        'action' => 'VERKOPEN',
                        'reason' => 'Geen target in v8.0',
                        'current_value' => round($pos['value'], 2),
                        'target_value' => 0,
                        'delta' => round(-$pos['value'], 2),
                        'platform' => $pos['platform'],
                        'fbi' => false,
                    ];
                }
                continue;
            }

            $targetValue = $totalPortfolio * ($pos['target'] / 100);
            $delta = $targetValue - $pos['value'];
            $driftAbs = abs($pos['drift']);

            if ($driftAbs < 1.0) {
                continue;
            }

            $isFbi = $this->isFbiPosition($name, $momentumConfig);

            if ($delta > 0 && $isFbi) {
                $orders[] = [
                    'position' => $name,
                    'action' => 'FBI — NIET BIJKOPEN',
                    'reason' => "FBI positie: alleen houden/verkopen op Saxo. Drift: {$pos['drift']}%",
                    'current_value' => round($pos['value'], 2),
                    'target_value' => round($targetValue, 2),
                    'delta' => round($delta, 2),
                    'platform' => $pos['platform'],
                    'fbi' => true,
                ];
                continue;
            }

            $action = $delta > 0 ? 'KOPEN' : 'VERKOPEN';
            $reason = sprintf(
                'Drift: %+.1f%% (relatief: %+.0f%%)',
                $pos['drift'],
                $pos['drift_relative'],
            );

            $orders[] = [
                'position' => $name,
                'action' => $action,
                'reason' => $reason,
                'current_value' => round($pos['value'], 2),
                'target_value' => round($targetValue, 2),
                'delta' => round($delta, 2),
                'platform' => $pos['platform'],
                'fbi' => $isFbi,
            ];
        }

        usort($orders, fn(array $a, array $b): int => (int) ((abs($b['delta']) * 100) - (abs($a['delta']) * 100)));

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'total_portfolio' => round($totalPortfolio, 2),
            'extra_cash' => $cashToDeploy,
            'orders' => $orders,
            'total_buy' => round(array_sum(array_map(
                fn(array $o): float => $o['delta'] > 0 && $o['action'] !== 'FBI — NIET BIJKOPEN' ? $o['delta'] : 0,
                $orders,
            )), 2),
            'total_sell' => round(abs(array_sum(array_map(
                fn(array $o): float => $o['delta'] < 0 ? $o['delta'] : 0,
                $orders,
            ))), 2),
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatRebalanceMarkdown($data);
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getScenarioPlanner(
        string $format,
        int $years,
        float $expectedReturnPct,
        float $monthlyContribution,
        float $inflationPct,
    ): string|array {
        $allocation = $this->portfolioMcp->fetchLiveAllocation();
        $startValue = $allocation['total_portfolio'];

        $monthlyReturn = $expectedReturnPct / 100 / 12;
        $monthlyInflation = $inflationPct / 100 / 12;
        $months = $years * 12;

        $nominal = $startValue;
        $deterministicPath = [];
        for ($m = 0; $m <= $months; $m++) {
            $deterministicPath[] = [
                'month' => $m,
                'nominal' => round($nominal, 2),
                'real' => round($nominal / ((1 + $monthlyInflation) ** $m), 2),
            ];
            $nominal = $nominal * (1 + $monthlyReturn) + $monthlyContribution;
        }

        $runs = 1000;
        $volatility = 0.15;
        $monthlyVol = $volatility / sqrt(12);
        $finalValues = [];

        for ($r = 0; $r < $runs; $r++) {
            $value = $startValue;
            for ($m = 0; $m < $months; $m++) {
                $randomReturn = $monthlyReturn + $monthlyVol * $this->boxMullerRandom();
                $value = $value * (1 + $randomReturn) + $monthlyContribution;
                if ($value < 0) {
                    $value = 0;
                }
            }
            $finalValues[] = $value;
        }

        sort($finalValues);
        $percentiles = [
            'p10' => round($finalValues[(int) floor($runs * 0.10)], 2),
            'p25' => round($finalValues[(int) floor($runs * 0.25)], 2),
            'p50' => round($finalValues[(int) floor($runs * 0.50)], 2),
            'p75' => round($finalValues[(int) floor($runs * 0.75)], 2),
            'p90' => round($finalValues[(int) floor($runs * 0.90)], 2),
        ];

        $inflationFactor = (1 + $monthlyInflation) ** $months;
        $realPercentiles = [];
        foreach ($percentiles as $key => $val) {
            $realPercentiles[$key] = round($val / $inflationFactor, 2);
        }

        $totalContributions = $monthlyContribution * $months;

        $data = [
            'timestamp' => (new \DateTime())->format('c'),
            'parameters' => [
                'start_value' => round($startValue, 2),
                'years' => $years,
                'expected_return_pct' => $expectedReturnPct,
                'monthly_contribution' => $monthlyContribution,
                'inflation_pct' => $inflationPct,
                'assumed_volatility' => round($volatility * 100, 1),
                'monte_carlo_runs' => $runs,
            ],
            'deterministic' => [
                'final_nominal' => $deterministicPath[count($deterministicPath) - 1]['nominal'],
                'final_real' => $deterministicPath[count($deterministicPath) - 1]['real'],
                'total_contributions' => round($totalContributions, 2),
                'total_growth' => round($deterministicPath[count($deterministicPath) - 1]['nominal'] - $startValue - $totalContributions, 2),
            ],
            'monte_carlo' => [
                'nominal' => $percentiles,
                'real' => $realPercentiles,
            ],
            'milestones' => $this->calculateMilestones($deterministicPath),
        ];

        if ($format === 'json') {
            return $data;
        }

        return $this->formatScenarioMarkdown($data);
    }

    private function boxMullerRandom(): float
    {
        $u1 = max(1e-10, (float) mt_rand() / mt_getrandmax());
        $u2 = (float) mt_rand() / mt_getrandmax();

        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    /**
     * @param list<array{month: int, nominal: float, real: float}> $path
     * @return list<array{target: string, month: int|null, year: float|null}>
     */
    private function calculateMilestones(array $path): array
    {
        $targets = [2_000_000, 2_500_000, 3_000_000, 5_000_000];
        $milestones = [];

        foreach ($targets as $target) {
            $month = null;
            foreach ($path as $point) {
                if ($point['nominal'] >= $target) {
                    $month = $point['month'];
                    break;
                }
            }

            $milestones[] = [
                'target' => '€' . number_format($target, 0, ',', '.'),
                'month' => $month,
                'year' => $month !== null ? round($month / 12, 1) : null,
            ];
        }

        return $milestones;
    }

    /**
     * @param array<string, mixed> $momentumConfig
     */
    private function isFbiPosition(string $name, array $momentumConfig): bool
    {
        $positions = $momentumConfig['positions'] ?? [];
        foreach ($positions as $posConfig) {
            if (isset($posConfig['name']) && str_contains((string) $posConfig['name'], $name) && ($posConfig['fbi'] ?? false)) {
                return true;
            }
        }

        return in_array($name, ['NTWC', 'NTEM'], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchYahooHoldings(string $ticker, string $positionName): ?array
    {
        if ($ticker === '' || str_contains($ticker, '.MFU')) {
            return null;
        }

        $cacheKey = 'mcp_holdings_' . str_replace(['.', ':', '/'], '_', $ticker);

        try {
            /** @var array<string, mixed>|null $result */
            $result = $this->cache->get($cacheKey, function (ItemInterface $item) use ($ticker): ?array {
                $item->expiresAfter(3600);

                $url = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$ticker}";
                $response = $this->httpClient->request('GET', $url, [
                    'query' => ['modules' => 'topHoldings,assetProfile'],
                    'headers' => ['User-Agent' => 'Mozilla/5.0'],
                    'timeout' => 15,
                ]);

                $data = $response->toArray(false);
                $result = $data['quoteSummary']['result'][0] ?? null;

                if ($result === null) {
                    return null;
                }

                $topHoldings = $result['topHoldings'] ?? [];
                $holdings = [];
                foreach ($topHoldings['holdings'] ?? [] as $h) {
                    $holdings[] = [
                        'symbol' => $h['symbol'] ?? '?',
                        'name' => $h['holdingName'] ?? '',
                        'pct' => round((float) ($h['holdingPercent']['raw'] ?? 0) * 100, 2),
                    ];
                }

                $sectors = [];
                foreach ($topHoldings['sectorWeightings'] ?? [] as $sw) {
                    foreach ($sw as $sector => $weight) {
                        $sectors[] = [
                            'sector' => $sector,
                            'pct' => round((float) ($weight['raw'] ?? 0) * 100, 2),
                        ];
                    }
                }

                return [
                    'top_holdings' => $holdings,
                    'sector_weightings' => $sectors,
                    'equity_share' => round((float) ($topHoldings['equityHoldings']['priceToEarnings']['raw'] ?? 0), 2),
                    'bond_share' => round((float) ($topHoldings['bondHoldings']['totalCount'] ?? 0), 0),
                ];
            });

            return $result;
        } catch (\Throwable $e) {
            $this->logger->debug('Yahoo holdings fetch failed', ['ticker' => $ticker, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchYahooFundamentals(string $ticker): array
    {
        try {
            $url = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$ticker}";
            $response = $this->httpClient->request('GET', $url, [
                'query' => ['modules' => 'summaryDetail,defaultKeyStatistics,assetProfile,price'],
                'headers' => ['User-Agent' => 'Mozilla/5.0'],
                'timeout' => 15,
            ]);

            $data = $response->toArray(false);
            $result = $data['quoteSummary']['result'][0] ?? null;

            if ($result === null) {
                return ['error' => true, 'message' => "No data found for ticker: {$ticker}"];
            }

            $summary = $result['summaryDetail'] ?? [];
            $stats = $result['defaultKeyStatistics'] ?? [];
            $price = $result['price'] ?? [];

            return [
                'name' => $price['shortName'] ?? $price['longName'] ?? $ticker,
                'currency' => $price['currency'] ?? 'EUR',
                'price' => (float) ($price['regularMarketPrice']['raw'] ?? 0),
                'market_cap' => $this->extractRaw($price, 'marketCap'),
                'pe_ratio' => $this->extractRaw($summary, 'trailingPE'),
                'forward_pe' => $this->extractRaw($summary, 'forwardPE'),
                'dividend_yield' => $this->extractRaw($summary, 'dividendYield') !== null
                    ? round($this->extractRaw($summary, 'dividendYield') * 100, 2) : null,
                'expense_ratio' => $this->extractRaw($stats, 'annualReportExpenseRatio') !== null
                    ? round($this->extractRaw($stats, 'annualReportExpenseRatio') * 100, 3) : null,
                'total_assets' => $this->extractRaw($stats, 'totalAssets'),
                'beta' => $this->extractRaw($stats, 'beta'),
                'ytd_return' => $this->extractRaw($stats, 'ytdReturn') !== null
                    ? round($this->extractRaw($stats, 'ytdReturn') * 100, 2) : null,
                '52w_high' => $this->extractRaw($summary, 'fiftyTwoWeekHigh'),
                '52w_low' => $this->extractRaw($summary, 'fiftyTwoWeekLow'),
                'avg_volume' => $this->extractRaw($summary, 'averageVolume'),
            ];
        } catch (\Throwable $e) {
            return ['error' => true, 'message' => "Failed to fetch fundamentals: {$e->getMessage()}"];
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractRaw(array $data, string $key): ?float
    {
        if (!isset($data[$key]['raw'])) {
            return null;
        }

        return (float) $data[$key]['raw'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatCostMarkdown(array $data): string
    {
        $md = "# MIDO Cost Analysis\n\n";
        $md .= "_Period: {$data['period']}_\n\n";

        $tc = $data['transaction_costs'];
        $md .= "## Transaction Costs\n\n";
        $md .= "| Platform | Commission | Volume | Cost Ratio |\n";
        $md .= "|----------|------------|--------|------------|\n";
        $md .= sprintf("| IBKR | €%.2f | €%s | %.4f%% |\n", $tc['ib']['commission'], number_format($tc['ib']['traded_volume'], 0, ',', '.'), $tc['ib']['cost_ratio']);
        $md .= sprintf("| Saxo | €%.2f | €%s | %.4f%% |\n", $tc['saxo']['commission'], number_format($tc['saxo']['traded_volume'], 0, ',', '.'), $tc['saxo']['cost_ratio']);
        $md .= sprintf("| **Total** | **€%.2f** | | |\n", $tc['total']);

        $fc = $data['fund_costs'];
        $md .= "\n## Fund Costs (TER)\n\n";
        $md .= sprintf("**Weighted average TER:** %.3f%%\n", $fc['weighted_ter']);
        $md .= sprintf("**Annual cost:** €%s\n\n", number_format($fc['annual_cost'], 0, ',', '.'));

        $md .= "| Position | Value | Weight | TER | Annual Cost |\n";
        $md .= "|----------|-------|--------|-----|-------------|\n";
        foreach ($fc['positions'] as $pos) {
            $md .= sprintf(
                "| %s | €%s | %.1f%% | %.2f%% | €%s |\n",
                $pos['position'],
                number_format($pos['value'], 0, ',', '.'),
                $pos['weight'],
                $pos['ter'],
                number_format($pos['annual_cost'], 0, ',', '.'),
            );
        }

        $tcr = $data['total_cost_ratio'];
        $md .= "\n## Total Cost Ratio\n\n";
        $md .= sprintf("| TER (annual) | %.3f%% |\n", $tcr['ter_annual']);
        $md .= sprintf("| Transaction costs (annualized) | %.4f%% |\n", $tcr['transaction_annualized']);
        $md .= sprintf("| **Total annual cost** | **%.3f%%** |\n", $tcr['total_annual_pct']);

        return $md;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatLookthroughMarkdown(array $data): string
    {
        $md = "# MIDO Fund Look-Through\n\n";
        $md .= "_Generated: {$data['timestamp']}_\n\n";

        foreach ($data['positions'] as $name => $posData) {
            $md .= "## {$name}\n";
            $md .= "_Source: {$posData['source']}_\n\n";

            $d = $posData['data'];

            if (isset($d['top_holdings']) && $d['top_holdings'] !== []) {
                $md .= "### Top Holdings\n\n";
                $md .= "| Holding | Weight |\n";
                $md .= "|---------|--------|\n";
                foreach ($d['top_holdings'] as $h) {
                    $label = $h['name'] !== '' ? $h['name'] : $h['symbol'];
                    $md .= sprintf("| %s | %.2f%% |\n", $label, $h['pct']);
                }
                $md .= "\n";
            }

            if (isset($d['sector_weightings']) && $d['sector_weightings'] !== []) {
                $md .= "### Sector Breakdown\n\n";
                $md .= "| Sector | Weight |\n";
                $md .= "|--------|--------|\n";
                foreach ($d['sector_weightings'] as $s) {
                    $md .= sprintf("| %s | %.2f%% |\n", ucfirst($s['sector']), $s['pct']);
                }
                $md .= "\n";
            }

            if (isset($d['geography']) && $d['geography'] !== []) {
                $md .= "### Geographic Breakdown\n\n";
                $md .= "| Region | Weight |\n";
                $md .= "|--------|--------|\n";
                foreach ($d['geography'] as $region => $pct) {
                    $md .= sprintf("| %s | %.1f%% |\n", $region, $pct);
                }
                $md .= "\n";
            }
        }

        return $md;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatFundamentalsMarkdown(array $data): string
    {
        $f = $data['fundamentals'];
        $md = "# Fundamentals: {$data['ticker']}\n\n";
        $md .= "_Generated: {$data['timestamp']}_\n\n";

        $md .= "| Metric | Value |\n";
        $md .= "|--------|-------|\n";
        $md .= sprintf("| Name | %s |\n", $f['name'] ?? 'N/A');
        $md .= sprintf("| Price | %s %.2f |\n", $f['currency'] ?? '', $f['price'] ?? 0);

        if ($f['market_cap'] !== null) {
            $md .= sprintf("| Market Cap | %s |\n", $this->formatLargeNumber($f['market_cap']));
        }
        if ($f['pe_ratio'] !== null) {
            $md .= sprintf("| P/E Ratio | %.2f |\n", $f['pe_ratio']);
        }
        if ($f['forward_pe'] !== null) {
            $md .= sprintf("| Forward P/E | %.2f |\n", $f['forward_pe']);
        }
        if ($f['dividend_yield'] !== null) {
            $md .= sprintf("| Dividend Yield | %.2f%% |\n", $f['dividend_yield']);
        }
        if ($f['expense_ratio'] !== null) {
            $md .= sprintf("| Expense Ratio (TER) | %.3f%% |\n", $f['expense_ratio']);
        }
        if ($f['total_assets'] !== null) {
            $md .= sprintf("| AUM / Total Assets | %s |\n", $this->formatLargeNumber($f['total_assets']));
        }
        if ($f['beta'] !== null) {
            $md .= sprintf("| Beta | %.2f |\n", $f['beta']);
        }
        if ($f['ytd_return'] !== null) {
            $md .= sprintf("| YTD Return | %.2f%% |\n", $f['ytd_return']);
        }
        if ($f['52w_high'] !== null) {
            $md .= sprintf("| 52W High | %.2f |\n", $f['52w_high']);
        }
        if ($f['52w_low'] !== null) {
            $md .= sprintf("| 52W Low | %.2f |\n", $f['52w_low']);
        }

        return $md;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatRebalanceMarkdown(array $data): string
    {
        $md = "# MIDO Rebalance Advisor\n\n";
        $md .= sprintf("_Portfolio: €%s_\n", number_format($data['total_portfolio'], 0, ',', '.'));
        if ($data['extra_cash'] !== null) {
            $md .= sprintf("_Extra cash to deploy: €%s_\n", number_format($data['extra_cash'], 0, ',', '.'));
        }
        $md .= "\n";

        if ($data['orders'] === []) {
            $md .= "**Geen rebalancing nodig — alle posities binnen bandbreedte.**\n";

            return $md;
        }

        $md .= "## Orders\n\n";
        $md .= "| Position | Actie | Platform | Huidig | Target | Delta | Reden |\n";
        $md .= "|----------|-------|----------|--------|--------|-------|-------|\n";

        foreach ($data['orders'] as $order) {
            $fbiIcon = $order['fbi'] ? ' ⚠️FBI' : '';
            $md .= sprintf(
                "| %s%s | %s | %s | €%s | €%s | €%s | %s |\n",
                $order['position'],
                $fbiIcon,
                $order['action'],
                $order['platform'],
                number_format($order['current_value'], 0, ',', '.'),
                number_format($order['target_value'], 0, ',', '.'),
                number_format($order['delta'], 0, ',', '.'),
                $order['reason'],
            );
        }

        $md .= sprintf("\n**Totaal kopen:** €%s\n", number_format($data['total_buy'], 0, ',', '.'));
        $md .= sprintf("**Totaal verkopen:** €%s\n", number_format($data['total_sell'], 0, ',', '.'));

        return $md;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatScenarioMarkdown(array $data): string
    {
        $p = $data['parameters'];
        $md = "# MIDO Scenario Planner\n\n";
        $md .= "## Parameters\n\n";
        $md .= sprintf("| Start Value | €%s |\n", number_format($p['start_value'], 0, ',', '.'));
        $md .= sprintf("| Horizon | %d jaar |\n", $p['years']);
        $md .= sprintf("| Expected Return | %.1f%% |\n", $p['expected_return_pct']);
        $md .= sprintf("| Monthly Contribution | €%s |\n", number_format($p['monthly_contribution'], 0, ',', '.'));
        $md .= sprintf("| Inflation | %.1f%% |\n", $p['inflation_pct']);
        $md .= sprintf("| Assumed Volatility | %.1f%% |\n", $p['assumed_volatility']);

        $det = $data['deterministic'];
        $md .= "\n## Deterministic Projection\n\n";
        $md .= sprintf("| Final Value (nominal) | €%s |\n", number_format($det['final_nominal'], 0, ',', '.'));
        $md .= sprintf("| Final Value (real) | €%s |\n", number_format($det['final_real'], 0, ',', '.'));
        $md .= sprintf("| Total Contributions | €%s |\n", number_format($det['total_contributions'], 0, ',', '.'));
        $md .= sprintf("| Investment Growth | €%s |\n", number_format($det['total_growth'], 0, ',', '.'));

        $mc = $data['monte_carlo'];
        $md .= "\n## Monte Carlo Simulation ({$p['monte_carlo_runs']} runs)\n\n";
        $md .= "| Percentile | Nominal | Real |\n";
        $md .= "|------------|---------|------|\n";
        foreach (['p10', 'p25', 'p50', 'p75', 'p90'] as $pct) {
            $md .= sprintf(
                "| %s | €%s | €%s |\n",
                strtoupper($pct),
                number_format($mc['nominal'][$pct], 0, ',', '.'),
                number_format($mc['real'][$pct], 0, ',', '.'),
            );
        }

        if ($data['milestones'] !== []) {
            $md .= "\n## Milestones (deterministisch)\n\n";
            $md .= "| Target | Bereikt na |\n";
            $md .= "|--------|------------|\n";
            foreach ($data['milestones'] as $m) {
                $when = $m['year'] !== null ? sprintf('%.1f jaar', $m['year']) : 'Niet binnen horizon';
                $md .= sprintf("| %s | %s |\n", $m['target'], $when);
            }
        }

        return $md;
    }

    private function formatLargeNumber(?float $number): string
    {
        if ($number === null) {
            return 'N/A';
        }

        if (abs($number) >= 1_000_000_000) {
            return sprintf('€%.1fB', $number / 1_000_000_000);
        }
        if (abs($number) >= 1_000_000) {
            return sprintf('€%.1fM', $number / 1_000_000);
        }

        return sprintf('€%s', number_format($number, 0, ',', '.'));
    }

    private function periodToDays(string $period): int
    {
        return match ($period) {
            '1m' => 30,
            '3m' => 90,
            '6m' => 180,
            '1y' => 365,
            'ytd' => max(1, (int) (new \DateTime())->diff(new \DateTime('first day of January'))->days),
            'all' => 3650,
            default => 365,
        };
    }
}
