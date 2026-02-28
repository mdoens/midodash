<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CalculationService;
use App\Service\CrisisService;
use App\Service\DashboardCacheService;
use App\Service\DataBufferService;
use App\Service\DxyService;
use App\Service\EurostatService;
use App\Service\FredApiService;
use App\Service\GoldPriceService;
use App\Service\IbClient;
use App\Service\MomentumService;
use App\Service\PortfolioService;
use App\Service\TransactionImportService;
use App\Service\PortfolioSnapshotService;
use App\Service\ReturnsService;
use App\Service\SaxoClient;
use App\Service\TriggerService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

class DashboardController extends AbstractController
{
    #[Route('/health/returns', name: 'health_returns')]
    public function debugHealth(
        ReturnsService $returnsService,
        DashboardCacheService $dashboardCache,
        PortfolioSnapshotService $snapshotService,
        ChartBuilderInterface $chartBuilder,
        LoggerInterface $logger,
    ): JsonResponse {
        $checks = [];

        try {
            $returns = $returnsService->getPortfolioReturns(['total_portfolio' => 0, 'positions' => []]);
            $checks['returns_service'] = 'OK: deposits=' . $returns['total_deposits'];
        } catch (\Throwable $e) {
            $checks['returns_service'] = 'ERROR: ' . $e->getMessage();
        }

        $cached = null;
        try {
            $cached = $dashboardCache->load();
            $checks['cache'] = $cached !== null ? 'OK: cached' : 'OK: no cache';
        } catch (\Throwable $e) {
            $checks['cache'] = 'ERROR: ' . $e->getMessage();
        }

        try {
            $history = $snapshotService->getHistory(365);
            $checks['history'] = 'OK: ' . count($history) . ' snapshots';
            if (count($history) > 1) {
                $this->buildHistoryChart($chartBuilder, $history);
                $checks['history_chart'] = 'OK';
            }
        } catch (\Throwable $e) {
            $checks['history'] = 'ERROR: ' . $e->getMessage();
        }

        // Try full render pipeline
        if ($cached !== null) {
            try {
                $cached['pie_chart'] = $this->buildAssetClassPieChart($chartBuilder, $cached['allocation']);
                $checks['pie_chart'] = 'OK';
            } catch (\Throwable $e) {
                $checks['pie_chart'] = 'ERROR: ' . $e->getMessage();
            }

            try {
                $cached['performance_chart'] = $this->buildPerformanceChart($chartBuilder, $cached['allocation']['positions']);
                $checks['perf_chart'] = 'OK';
            } catch (\Throwable $e) {
                $checks['perf_chart'] = 'ERROR: ' . $e->getMessage();
            }
        }

        // Try full template render
        if ($cached !== null) {
            try {
                $cached['radar_chart'] = $this->buildFactorRadarChart($chartBuilder, $cached['factors'] ?? []);
                $checks['radar_chart'] = 'OK';
            } catch (\Throwable $e) {
                $checks['radar_chart'] = 'ERROR: ' . $e->getMessage();
            }

            try {
                $cached['saxo_authenticated'] = false;
                if (!isset($cached['saxo_performance'])) {
                    $cached['saxo_performance'] = null;
                }
                if (!isset($cached['saxo_currency_exposure'])) {
                    $cached['saxo_currency_exposure'] = [];
                }
                $cached['returns'] = $returnsService->getPortfolioReturns($cached['allocation']);
                $cached['position_returns'] = $returnsService->getPositionReturns($cached['allocation']);
                $cached['monthly_overview'] = $returnsService->getMonthlyOverview();
                $history = $snapshotService->getHistory(365);
                $cached['history'] = $history;
                if (count($history) > 1) {
                    $cached['history_chart'] = $this->buildHistoryChart($chartBuilder, $history);
                    $cached['allocation_chart'] = $this->buildAllocationHistoryChart($chartBuilder, $history);
                }
                $response = $this->render('dashboard/index.html.twig', $cached);
                $checks['full_render'] = 'OK: ' . $response->getStatusCode() . ' (' . strlen((string) $response->getContent()) . ' bytes)';
            } catch (\Throwable $e) {
                $checks['full_render'] = 'ERROR: ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine();
            }
        }

        return $this->json($checks);
    }

    #[Route('/health/ib', name: 'health_ib')]
    public function debugIb(IbClient $ibClient, DataBufferService $dataBuffer): JsonResponse
    {
        $cacheFile = $ibClient->getCacheFile();
        $cacheTimestamp = $ibClient->getCacheTimestamp();
        $positionsBuffer = $dataBuffer->retrieve('ib', 'positions');
        $cashBuffer = $dataBuffer->retrieve('ib', 'cash_report');

        $posBufferedAt = $positionsBuffer !== null
            ? $positionsBuffer['fetched_at']->format('Y-m-d H:i:s') : null;
        $cashBufferedAt = $cashBuffer !== null
            ? $cashBuffer['fetched_at']->format('Y-m-d H:i:s') : null;

        return new JsonResponse([
            'cache_file_exists' => file_exists($cacheFile),
            'cache_file_size' => file_exists($cacheFile) ? filesize($cacheFile) : 0,
            'cache_file_age_seconds' => $cacheTimestamp !== null ? time() - $cacheTimestamp->getTimestamp() : null,
            'cache_file_timestamp' => $cacheTimestamp?->format('Y-m-d H:i:s'),
            'positions_buffered' => $positionsBuffer !== null,
            'positions_buffered_at' => $posBufferedAt,
            'cash_buffered' => $cashBuffer !== null,
            'cash_buffered_at' => $cashBufferedAt,
        ]);
    }

    #[Route('/health/saxo', name: 'health_saxo')]
    public function debugSaxo(SaxoClient $saxoClient, DataBufferService $dataBuffer): JsonResponse
    {
        $tokenFile = $this->getParameter('kernel.project_dir') . '/var/saxo_tokens.json';
        $balance = $dataBuffer->retrieve('saxo', 'balance');

        // Read raw token data for debugging
        $tokenDebug = [];
        if (file_exists($tokenFile)) {
            $raw = json_decode((string) file_get_contents($tokenFile), true);
            if (is_array($raw)) {
                $tokenDebug = [
                    'created_at' => isset($raw['created_at']) ? date('Y-m-d H:i:s', (int) $raw['created_at']) : null,
                    'expires_in' => $raw['expires_in'] ?? null,
                    'refresh_token_expires_in' => $raw['refresh_token_expires_in'] ?? null,
                    'refresh_token_created_at' => isset($raw['refresh_token_created_at']) ? date('Y-m-d H:i:s', (int) $raw['refresh_token_created_at']) : null,
                    'has_refresh_token' => isset($raw['refresh_token']),
                    'access_token_prefix' => isset($raw['access_token']) ? substr($raw['access_token'], 0, 10) . '...' : null,
                ];
            }
        }

        return new JsonResponse([
            'token_file_exists' => file_exists($tokenFile),
            'token_file_size' => file_exists($tokenFile) ? filesize($tokenFile) : 0,
            'token_file_age_seconds' => file_exists($tokenFile) ? time() - (int) filemtime($tokenFile) : null,
            'is_authenticated' => $saxoClient->isAuthenticated(),
            'token_expiry' => $saxoClient->getTokenExpiry(),
            'token_expiry_human' => $saxoClient->getTokenExpiry() !== null ? date('Y-m-d H:i:s', $saxoClient->getTokenExpiry()) : null,
            'refresh_ttl_seconds' => $saxoClient->getRefreshTokenTtl(),
            'token_debug' => $tokenDebug,
            'balance_buffered' => $balance !== null,
        ]);
    }

    #[Route('/health/audit', name: 'health_audit')]
    public function auditReturns(
        SaxoClient $saxoClient,
        ReturnsService $returnsService,
        DashboardCacheService $dashboardCache,
        DataBufferService $dataBuffer,
        \App\Repository\TransactionRepository $transactionRepository,
        \Doctrine\DBAL\Connection $conn,
        LoggerInterface $logger,
    ): JsonResponse {
        $audit = [];

        // 1. Transaction DB totals
        $table = $conn->quoteIdentifier('transaction');

        $audit['db_summary'] = $conn->executeQuery(
            "SELECT type, platform, COUNT(*) as cnt, SUM(COALESCE(amount_eur, amount)) as total FROM {$table} GROUP BY type, platform ORDER BY type, platform"
        )->fetchAllAssociative();

        // 2. Saxo performance API (live)
        $audit['saxo_authenticated'] = $saxoClient->isAuthenticated();
        try {
            $perf = $saxoClient->getPerformanceMetrics();
            $audit['saxo_performance_live'] = $perf;
        } catch (\Throwable $e) {
            $audit['saxo_performance_live'] = 'ERROR: ' . $e->getMessage();
        }

        // 3. Saxo performance from DataBuffer
        $buffered = $dataBuffer->retrieve('saxo', 'performance');
        $audit['saxo_performance_buffer'] = $buffered !== null ? $buffered['data'] : null;

        // 4. Specific deposit sums
        $audit['deposits'] = [
            'ib_deposits' => $transactionRepository->sumByType('deposit', 'ib'),
            'saxo_deposits_db' => $transactionRepository->sumByType('deposit', 'saxo'),
            'ib_withdrawals' => $transactionRepository->sumByType('withdrawal', 'ib'),
            'saxo_withdrawals_db' => $transactionRepository->sumByType('withdrawal', 'saxo'),
            'all_dividends' => $transactionRepository->sumByType('dividend'),
            'ib_dividends' => $transactionRepository->sumByType('dividend', 'ib'),
            'saxo_dividends' => $transactionRepository->sumByType('dividend', 'saxo'),
            'all_interest' => $transactionRepository->sumByType('interest'),
        ];

        // 5. Cached allocation + returns
        $cached = $dashboardCache->load();
        if ($cached !== null) {
            $audit['portfolio_total'] = $cached['allocation']['total_portfolio'] ?? null;
            $audit['ib_cash'] = $cached['allocation']['ib_cash'] ?? null;
            $audit['saxo_cash'] = $cached['allocation']['saxo_cash'] ?? null;

            try {
                $returns = $returnsService->getPortfolioReturns($cached['allocation']);
                $audit['returns'] = $returns;
            } catch (\Throwable $e) {
                $audit['returns'] = 'ERROR: ' . $e->getMessage();
            }
        } else {
            $audit['cache'] = 'no cache available';
        }

        // 6. Saxo transactions detail
        $audit['saxo_txs'] = $conn->executeQuery(
            "SELECT type, position_name, amount, amount_eur, traded_at FROM {$table} WHERE platform = 'saxo' ORDER BY type, traded_at DESC LIMIT 30"
        )->fetchAllAssociative();

        // 7. Raw Saxo cash transactions from API (for debugging import mapping)
        try {
            $rawCashTxs = $saxoClient->getCashTransactions();
            if ($rawCashTxs !== null) {
                $audit['saxo_raw_cash_sample'] = array_slice($rawCashTxs, 0, 30);
                $audit['saxo_raw_cash_count'] = count($rawCashTxs);

                // Summarize by TransactionType
                $typeSummary = [];
                foreach ($rawCashTxs as $rtx) {
                    $txType = (string) ($rtx['TransactionType'] ?? 'unknown');
                    $event = (string) ($rtx['Event'] ?? '');
                    $key = $txType . '|' . $event;
                    if (!isset($typeSummary[$key])) {
                        $typeSummary[$key] = ['count' => 0, 'total' => 0.0];
                    }
                    $typeSummary[$key]['count']++;
                    $typeSummary[$key]['total'] += (float) ($rtx['BookedAmount'] ?? $rtx['Amount'] ?? 0);
                }
                $audit['saxo_raw_type_summary'] = $typeSummary;
            }
        } catch (\Throwable $e) {
            $audit['saxo_raw_cash'] = 'ERROR: ' . $e->getMessage();
        }

        // 8. Raw IB cash transaction types (check for dividends)
        $audit['ib_tx_types'] = $conn->executeQuery(
            "SELECT type, COUNT(*) as cnt, SUM(COALESCE(amount_eur, amount)) as total FROM {$table} WHERE platform = 'ib' GROUP BY type ORDER BY type"
        )->fetchAllAssociative();

        // 9. IB Flex XML: check if CashTransaction nodes exist
        $ibCacheFile = $this->getParameter('kernel.project_dir') . '/var/ib_statement.xml';
        if (file_exists($ibCacheFile)) {
            $xml = file_get_contents($ibCacheFile);
            if ($xml !== false) {
                libxml_use_internal_errors(true);
                $doc = simplexml_load_string($xml);
                if ($doc !== false) {
                    $trades = $doc->xpath('//Trade') ?: [];
                    $cashTxs = $doc->xpath('//CashTransaction') ?: [];
                    $audit['ib_xml'] = [
                        'file_size' => strlen($xml),
                        'trade_nodes' => count($trades),
                        'cash_transaction_nodes' => count($cashTxs),
                    ];
                    // Summarize CashTransaction types
                    if (count($cashTxs) > 0) {
                        $typeSummary = [];
                        foreach ($cashTxs as $ct) {
                            $attrs = [];
                            foreach ($ct->attributes() as $k => $v) {
                                $attrs[(string) $k] = (string) $v;
                            }
                            $type = $attrs['type'] ?? 'unknown';
                            if (!isset($typeSummary[$type])) {
                                $typeSummary[$type] = ['count' => 0, 'total' => 0.0];
                            }
                            $typeSummary[$type]['count']++;
                            $typeSummary[$type]['total'] += (float) ($attrs['amount'] ?? 0);
                        }
                        $audit['ib_cash_tx_type_summary'] = $typeSummary;
                    }
                }
            }
        } else {
            $audit['ib_xml'] = 'No IB cache file';
        }

        return $this->json($audit);
    }

    #[Route('/health/reimport-saxo', name: 'health_reimport_saxo')]
    public function reimportSaxo(
        SaxoClient $saxoClient,
        TransactionImportService $importService,
        DashboardCacheService $dashboardCache,
    ): JsonResponse {
        $result = [];

        // Delete existing Saxo transactions
        $deletedCash = $importService->deleteSaxoCashTransactions();
        $deletedTrades = $importService->deletePlatformTransactions('saxo');
        $result['deleted_cash'] = $deletedCash;
        $result['deleted_trades'] = $deletedTrades;

        if (!$saxoClient->ensureValidToken()) {
            $result['error'] = 'Saxo not authenticated';

            return $this->json($result);
        }

        // Re-import trades
        $trades = $saxoClient->getHistoricalTrades();
        if ($trades !== null) {
            $r = $importService->importFromSaxoOrders($trades);
            $result['trades'] = sprintf('%d imported, %d skipped', $r['imported'], $r['skipped']);
        } else {
            $result['trades'] = 'null (API failed)';
        }

        // Re-import cash transactions
        $cashTxs = $saxoClient->getCashTransactions();
        if ($cashTxs !== null) {
            $r = $importService->importFromSaxoCashTransactions($cashTxs);
            $result['cash'] = sprintf('%d imported, %d skipped', $r['imported'], $r['skipped']);
        } else {
            $result['cash'] = 'null (API failed)';
        }

        $remapped = $importService->remapPositionNames();
        $result['remapped'] = $remapped;

        $dashboardCache->invalidate();
        $result['cache'] = 'invalidated';

        return $this->json($result);
    }

    #[Route('/health/import', name: 'health_import')]
    public function triggerImport(
        IbClient $ibClient,
        SaxoClient $saxoClient,
        TransactionImportService $importService,
        DashboardCacheService $dashboardCache,
    ): JsonResponse {
        $result = [];

        try {
            // IB
            $cacheFile = $ibClient->getCacheFile();
            if (!file_exists($cacheFile)) {
                $result['ib'] = 'No cache file at ' . $cacheFile;
            } else {
                $xml = file_get_contents($cacheFile);
                if ($xml === false) {
                    $result['ib'] = 'Could not read cache file';
                } else {
                    $r = $importService->importFromIbXml($xml);
                    $result['ib'] = sprintf('%d imported, %d skipped', $r['imported'], $r['skipped']);
                }
            }
        } catch (\Throwable $e) {
            $result['ib'] = 'ERROR: ' . $e->getMessage();
        }

        try {
            // Saxo
            if (!$saxoClient->ensureValidToken()) {
                $result['saxo'] = 'Not authenticated (token refresh failed)';
            } else {
                // Import trades
                $trades = $saxoClient->getHistoricalTrades();
                if ($trades === null) {
                    $result['saxo_trades'] = 'Could not fetch trades (null)';
                } elseif ($trades === []) {
                    $result['saxo_trades'] = 'Empty trade list';
                } else {
                    $r = $importService->importFromSaxoOrders($trades);
                    $result['saxo_trades'] = sprintf('%d imported, %d skipped', $r['imported'], $r['skipped']);
                }

                // Import cash transactions (dividends, deposits, interest, etc.)
                $cashTxs = $saxoClient->getCashTransactions();
                if ($cashTxs === null) {
                    $result['saxo_cash'] = 'Could not fetch cash transactions (null)';
                } elseif ($cashTxs === []) {
                    $result['saxo_cash'] = 'No cash transactions found';
                } else {
                    $r = $importService->importFromSaxoCashTransactions($cashTxs);
                    $result['saxo_cash'] = sprintf('%d imported, %d skipped', $r['imported'], $r['skipped']);
                }
            }
        } catch (\Throwable $e) {
            $result['saxo'] = 'ERROR: ' . $e->getMessage();
        }

        try {
            $remapped = $importService->remapPositionNames();
            $result['remapped'] = $remapped;
        } catch (\Throwable $e) {
            $result['remap'] = 'ERROR: ' . $e->getMessage();
        }

        // Invalidate dashboard cache so returns are recalculated
        $dashboardCache->invalidate();
        $result['cache'] = 'invalidated';

        return $this->json($result);
    }

    #[Route('/', name: 'dashboard')]
    public function index(
        IbClient $ibClient,
        SaxoClient $saxoClient,
        MomentumService $momentumService,
        PortfolioService $portfolioService,
        FredApiService $fredApi,
        CrisisService $crisisService,
        CalculationService $calculations,
        EurostatService $eurostat,
        GoldPriceService $goldPrice,
        DxyService $dxyService,
        TriggerService $triggerService,
        DashboardCacheService $dashboardCache,
        DataBufferService $dataBuffer,
        PortfolioSnapshotService $snapshotService,
        ReturnsService $returnsService,
        ChartBuilderInterface $chartBuilder,
        LoggerInterface $logger,
    ): Response {
        // Try loading from dashboard cache first (instant page load)
        $cached = $dashboardCache->load();

        if ($cached !== null) {
            // Build charts from cached data (Chart objects are not serializable)
            $cached['pie_chart'] = $this->buildAssetClassPieChart($chartBuilder, $cached['allocation']);
            $cached['radar_chart'] = $this->buildFactorRadarChart($chartBuilder, $portfolioService->getFactorData());
            $cached['performance_chart'] = $this->buildPerformanceChart($chartBuilder, $cached['allocation']['positions']);

            // Always check live Saxo auth status + open orders + performance
            $cached['saxo_authenticated'] = $saxoClient->isAuthenticated();
            if ($cached['saxo_authenticated']) {
                $cached['saxo_from_buffer'] = false;
            }
            $cached['saxo_open_orders'] = $cached['saxo_authenticated'] ? ($saxoClient->getOpenOrders() ?? []) : [];
            if (!isset($cached['saxo_performance'])) {
                $cached['saxo_performance'] = null;
            }
            if (!isset($cached['saxo_currency_exposure'])) {
                $cached['saxo_currency_exposure'] = [];
            }

            // Portfolio history for Historie tab
            $history = $snapshotService->getHistory(365);
            $cached['history'] = $history;
            if (count($history) > 1) {
                $cached['history_chart'] = $this->buildHistoryChart($chartBuilder, $history);
                $cached['allocation_chart'] = $this->buildAllocationHistoryChart($chartBuilder, $history);
            }

            // Returns data from transactions (graceful fallback if table empty/missing)
            try {
                $cached['returns'] = $returnsService->getPortfolioReturns($cached['allocation']);
                $cached['position_returns'] = $returnsService->getPositionReturns($cached['allocation']);
                $cached['monthly_overview'] = $returnsService->getMonthlyOverview();
            } catch (\Throwable $e) {
                $logger->error('Returns data failed', ['error' => $e->getMessage()]);
                $cached['returns'] = [];
                $cached['position_returns'] = [];
                $cached['monthly_overview'] = [];
            }

            return $this->render('dashboard/index.html.twig', $cached);
        }

        // No cache — compute everything
        $data = $this->computeDashboardData(
            $ibClient,
            $saxoClient,
            $momentumService,
            $portfolioService,
            $fredApi,
            $crisisService,
            $calculations,
            $eurostat,
            $goldPrice,
            $dxyService,
            $triggerService,
            $dataBuffer,
            $logger,
        );

        // Save daily portfolio snapshot (once per day)
        $snapshotService->saveSnapshot(
            $data['allocation'],
            $data['regime'],
            !($data['saxo_from_buffer'] ?? false),
            !($data['ib_from_buffer'] ?? false),
        );

        // Save to cache (without Chart objects)
        $dashboardCache->save($data);

        // Build charts
        $data['pie_chart'] = $this->buildAssetClassPieChart($chartBuilder, $data['allocation']);
        $data['radar_chart'] = $this->buildFactorRadarChart($chartBuilder, $portfolioService->getFactorData());
        $data['performance_chart'] = $this->buildPerformanceChart($chartBuilder, $data['allocation']['positions']);

        // Portfolio history for Historie tab
        $history = $snapshotService->getHistory(365);
        $data['history'] = $history;
        if (count($history) > 1) {
            $data['history_chart'] = $this->buildHistoryChart($chartBuilder, $history);
            $data['allocation_chart'] = $this->buildAllocationHistoryChart($chartBuilder, $history);
        }

        // Returns data from transactions (graceful fallback if table empty/missing)
        try {
            $data['returns'] = $returnsService->getPortfolioReturns($data['allocation']);
            $data['position_returns'] = $returnsService->getPositionReturns($data['allocation']);
            $data['monthly_overview'] = $returnsService->getMonthlyOverview();
        } catch (\Throwable $e) {
            $logger->error('Returns data failed', ['error' => $e->getMessage()]);
            $data['returns'] = [];
            $data['position_returns'] = [];
            $data['monthly_overview'] = [];
        }

        return $this->render('dashboard/index.html.twig', $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function computeDashboardData(
        IbClient $ibClient,
        SaxoClient $saxoClient,
        MomentumService $momentumService,
        PortfolioService $portfolioService,
        FredApiService $fredApi,
        CrisisService $crisisService,
        CalculationService $calculations,
        EurostatService $eurostat,
        GoldPriceService $goldPrice,
        DxyService $dxyService,
        TriggerService $triggerService,
        DataBufferService $dataBuffer,
        LoggerInterface $logger,
    ): array {
        // ── IB data ──
        $ibError = false;
        $ibFromBuffer = false;
        $ibBufferedAt = null;
        try {
            $ibPositions = $ibClient->getPositions();
            $ibCash = $ibClient->getCashReport();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: IB data failed', ['error' => $e->getMessage()]);
            $ibPositions = [];
            $ibCash = [];
            $ibError = true;
        }

        // IB fallback to buffer
        if ($ibPositions === [] && $ibError) {
            $buffered = $dataBuffer->retrieve('ib', 'positions');
            if ($buffered !== null) {
                $ibPositions = $buffered['data'];
                $ibFromBuffer = true;
                $ibBufferedAt = $buffered['fetched_at'];
                $logger->info('IB using buffered positions', ['buffered_at' => $ibBufferedAt->format('Y-m-d H:i')]);
            }

            $cashBuffered = $dataBuffer->retrieve('ib', 'cash_report');
            if ($cashBuffered !== null && $ibCash === []) {
                $ibCash = $cashBuffered['data'];
            }
        }

        $ibCashBalance = (float) ($ibCash['ending_cash'] ?? 0);
        $ibDataTimestamp = $ibClient->getCacheTimestamp();

        // ── Saxo data ──
        $saxoError = false;
        $saxoFromBuffer = false;
        $saxoBufferedAt = null;
        $saxoPositions = null;
        $saxoCashBalance = 0.0;
        $saxoAuthenticated = false;
        $saxoOpenOrders = [];
        $saxoPerformance = null;
        $saxoCurrencyExposure = [];
        $saxoCashForTrading = 0.0;
        $saxoCostToClose = 0.0;
        try {
            $saxoAuthenticated = $saxoClient->isAuthenticated();
            if ($saxoAuthenticated) {
                $saxoPositions = $saxoClient->getPositions();
                $saxoBalance = $saxoClient->getAccountBalance();
                $saxoCashBalance = (float) ($saxoBalance['CashBalance'] ?? 0);
                $saxoCashForTrading = (float) ($saxoBalance['CashAvailableForTrading'] ?? 0);
                $saxoCostToClose = (float) ($saxoBalance['CostToClosePositions'] ?? 0);

                // Fetch open orders
                $saxoOpenOrders = $saxoClient->getOpenOrders() ?? [];

                // Fetch performance metrics + currency exposure (non-critical, graceful fallback)
                try {
                    $saxoPerformance = $saxoClient->getPerformanceMetrics();
                } catch (\Throwable $e) {
                    $logger->warning('Saxo performance metrics failed', ['error' => $e->getMessage()]);
                    $saxoPerformance = null;
                }

                try {
                    $saxoCurrencyExposure = $saxoClient->getCurrencyExposure() ?? [];
                } catch (\Throwable $e) {
                    $logger->warning('Saxo currency exposure failed', ['error' => $e->getMessage()]);
                    $saxoCurrencyExposure = [];
                }

                // Log Saxo symbols for debugging mapping (stderr for Coolify visibility)
                if ($saxoPositions !== null) {
                    $symbolDetails = [];
                    foreach ($saxoPositions as $sp) {
                        $symbolDetails[] = sprintf(
                            '%s (%s) = €%s',
                            $sp['symbol'] ?? '?',
                            $sp['description'] ?? '?',
                            number_format((float) ($sp['exposure'] ?? 0), 0, ',', '.'),
                        );
                    }
                    $msg = 'Saxo positions: ' . implode(' | ', $symbolDetails);
                    $logger->info($msg);
                    file_put_contents('php://stderr', $msg . "\n");
                } else {
                    // Don't set saxoAuthenticated=false — tokens may still be valid
                    // (API could be down, rate limited, or temporary network issue)
                    $logger->warning('Saxo positions returned null despite being authenticated');
                }
            }
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Saxo data failed', ['error' => $e->getMessage()]);
            $saxoError = true;
        }

        // Saxo fallback to buffer when positions are null (token expired, API down, etc.)
        if ($saxoPositions === null) {
            $buffered = $dataBuffer->retrieve('saxo', 'positions');
            if ($buffered !== null) {
                $saxoPositions = $buffered['data'];
                $saxoFromBuffer = true;
                $saxoBufferedAt = $buffered['fetched_at'];
                $logger->info('Saxo using buffered positions', ['buffered_at' => $saxoBufferedAt->format('Y-m-d H:i')]);
            }

            $balanceBuffered = $dataBuffer->retrieve('saxo', 'balance');
            if ($balanceBuffered !== null && $saxoCashBalance === 0.0) {
                $saxoCashBalance = (float) ($balanceBuffered['data']['CashBalance'] ?? 0);
            }
        }

        // Log raw IB symbols for debugging
        $ibSymbols = array_map(fn(array $p): string => sprintf(
            '%s=€%s',
            $p['symbol'] ?? '?',
            number_format((float) ($p['value'] ?? 0), 0, ',', '.'),
        ), $ibPositions);
        $logger->info('IB positions loaded', ['count' => count($ibPositions), 'symbols' => $ibSymbols]);

        // ── Portfolio allocation (v8.0) ──
        // Saxo CashBalance already includes money reserved for open orders
        // (only deducted upon fill), so pass it as-is — no adjustment needed.
        $allocation = $portfolioService->calculateAllocations(
            $ibPositions,
            $saxoPositions,
            $ibCashBalance,
            $saxoCashBalance,
            $saxoOpenOrders,
        );

        // Log matched positions
        $matched = [];
        foreach ($allocation['positions'] as $name => $p) {
            $matched[] = sprintf('%s: €%s (%s)', $name, number_format($p['value'], 0, ',', '.'), $p['status']);
        }
        $logger->info('Portfolio positions', ['positions' => $matched]);

        // ── Momentum signal ──
        $momentumError = false;
        $signal = ['regime' => ['bull' => true, 'price' => 0, 'ma200' => 0], 'scores' => [], 'allocation' => [], 'reason' => ''];
        try {
            $signal = $momentumService->getSignal();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Momentum data failed', ['error' => $e->getMessage()]);
            $momentumError = true;
        }

        // ── Macro indicators ──
        $macroError = false;
        $macro = [];
        try {
            $macro = $this->collectMacroData($fredApi, $eurostat, $goldPrice, $dxyService, $calculations);
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Macro data failed', ['error' => $e->getMessage()]);
            $macroError = true;
        }

        // ── Crisis signals ──
        $crisis = ['crisis_triggered' => false, 'active_signals' => 0, 'signals' => [], 'drawdown' => []];
        try {
            $crisis = $crisisService->checkAllSignals();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Crisis data failed', ['error' => $e->getMessage()]);
        }

        // ── Triggers ──
        $triggers = ['triggers' => [], 'warnings' => [], 'active_count' => 0, 'status' => 'GREEN'];
        try {
            $triggers = $triggerService->evaluateAll();
        } catch (\Throwable $e) {
            $logger->error('Dashboard: Trigger evaluation failed', ['error' => $e->getMessage()]);
        }

        // ── Regime label ──
        $vixValue = $macro['vix'] ?? null;
        $regimeLabel = 'BULL';
        if (!($signal['regime']['bull'] ?? true)) {
            $regimeLabel = ($vixValue !== null && $vixValue > 30) ? 'BEAR BEVESTIGD' : 'BEAR';
        }

        // ── Sort positions by drift ──
        $positionsByDrift = $allocation['positions'];
        uasort($positionsByDrift, fn(array $a, array $b): int => (int) (abs($b['drift'] ?? 0) * 100) <=> (int) (abs($a['drift'] ?? 0) * 100));

        return [
            'allocation' => $allocation,
            'positions_by_drift' => $positionsByDrift,
            'macro' => $macro,
            'regime' => $regimeLabel,
            'crisis' => $crisis,
            'triggers' => $triggers,
            'signal' => $signal,
            'factors' => $portfolioService->getFactorData(),
            'factor_mapping' => $portfolioService->getFactorMapping(),
            'deployment_protocol' => $portfolioService->getDeploymentProtocol(),
            'five_questions' => $portfolioService->getFiveQuestions(),
            'saxo_authenticated' => $saxoAuthenticated,
            'ib_error' => $ibError,
            'saxo_error' => $saxoError,
            'momentum_error' => $momentumError,
            'macro_error' => $macroError,
            'saxo_from_buffer' => $saxoFromBuffer,
            'saxo_buffered_at' => $saxoBufferedAt?->format('d M Y H:i'),
            'ib_from_buffer' => $ibFromBuffer,
            'ib_buffered_at' => $ibBufferedAt?->format('d M Y H:i'),
            'saxo_open_orders' => $saxoOpenOrders,
            'saxo_performance' => $saxoPerformance,
            'saxo_currency_exposure' => $saxoCurrencyExposure,
            'saxo_cash_for_trading' => $saxoCashForTrading,
            'saxo_cost_to_close' => $saxoCostToClose,
            'ib_data_timestamp' => $ibDataTimestamp?->format('d M H:i'),
            'saxo_data_timestamp' => $saxoAuthenticated ? 'live' : null,
        ];
    }

    /**
     * @return array<string, float|string|int|null>
     */
    private function collectMacroData(
        FredApiService $fredApi,
        EurostatService $eurostat,
        GoldPriceService $goldPrice,
        DxyService $dxyService,
        CalculationService $calculations,
    ): array {
        $vix = $fredApi->getLatestValue('VIXCLS');
        $hySpread = $fredApi->getLatestValue('BAMLH0A0HYM2');
        $ecbRate = $fredApi->getLatestValue('ECBDFR');
        $treasury10y = $fredApi->getLatestValue('DGS10');
        $yieldCurve = $fredApi->getLatestValue('T10Y2Y');
        $eurUsd = $fredApi->getLatestValue('DEXUSEU');

        $euInflation = $eurostat->getLatestInflation();
        $goldPrices = $goldPrice->getPrices();
        $dxy = $dxyService->getDxy();
        $cape = $calculations->getCapeAssessment();
        $erp = $calculations->calculateEquityRiskPremium();
        $realEcb = $calculations->calculateRealEcbRate();
        $recession = $calculations->calculateRecessionProbability();

        return [
            'vix' => $vix['value'] ?? null,
            'hy_spread' => $hySpread['value'] ?? null,
            'hy_spread_bps' => ($hySpread['value'] ?? null) !== null ? (int) round($hySpread['value'] * 100) : null,
            'ecb_rate' => $ecbRate['value'] ?? null,
            'dgs10' => $treasury10y['value'] ?? null,
            'yield_curve' => $yieldCurve['value'] ?? null,
            'eu_inflation' => $euInflation['value'] ?? null,
            'gold' => $goldPrices['gold'] ?? null,
            'eurusd' => $eurUsd['value'] ?? null,
            'dxy' => $dxy['value'] ?? null,
            'cape' => $cape['value'],
            'cape_status' => $cape['status'],
            'erp' => $erp['value'],
            'erp_status' => $erp['status'],
            'real_ecb_rate' => $realEcb['value'] ?? null,
            'recession_prob' => $recession['probability'],
            'recession_status' => $recession['status'],
        ];
    }

    /**
     * @param array<string, mixed> $allocation
     */
    private function buildAssetClassPieChart(ChartBuilderInterface $chartBuilder, array $allocation): Chart
    {
        $chart = $chartBuilder->createChart(Chart::TYPE_DOUGHNUT);

        $labels = [];
        $data = [];
        $colors = [];

        $colorMap = [
            'equity' => '#22c55e',
            'fixed_income' => '#3b82f6',
            'alternatives' => '#f59e0b',
        ];

        foreach ($allocation['asset_classes'] as $key => $ac) {
            $labels[] = $ac['label'];
            $data[] = $ac['current_pct'];
            $colors[] = $colorMap[$key] ?? '#6b7280';
        }

        $labels[] = 'Cash';
        $data[] = $allocation['cash_pct'];
        $colors[] = '#475569';

        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $data,
                'backgroundColor' => $colors,
                'borderWidth' => 0,
                'spacing' => 2,
            ]],
        ]);

        $chart->setOptions([
            'cutout' => '65%',
            'responsive' => true,
            'maintainAspectRatio' => true,
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'backgroundColor' => '#0f172a',
                    'titleColor' => '#e2e8f0',
                    'bodyColor' => '#94a3b8',
                    'borderColor' => 'rgba(148,163,184,0.2)',
                    'borderWidth' => 1,
                    'callbacks' => ['label' => '@@function(ctx) { return ctx.label + ": " + ctx.parsed.toFixed(1) + "%"; }@@'],
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * @param array<int, array{factor: string, score: float}> $factorData
     */
    private function buildFactorRadarChart(ChartBuilderInterface $chartBuilder, array $factorData): Chart
    {
        $chart = $chartBuilder->createChart(Chart::TYPE_RADAR);

        $chart->setData([
            'labels' => array_column($factorData, 'factor'),
            'datasets' => [[
                'label' => 'v8.0',
                'data' => array_column($factorData, 'score'),
                'borderColor' => '#3b82f6',
                'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                'borderWidth' => 2,
                'pointBackgroundColor' => '#3b82f6',
                'pointRadius' => 4,
            ]],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'r' => [
                    'beginAtZero' => true,
                    'max' => 0.6,
                    'ticks' => ['display' => false],
                    'grid' => ['color' => 'rgba(148,163,184,0.12)'],
                    'angleLines' => ['color' => 'rgba(148,163,184,0.12)'],
                    'pointLabels' => ['color' => '#94a3b8', 'font' => ['size' => 12]],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'backgroundColor' => '#0f172a',
                    'titleColor' => '#e2e8f0',
                    'bodyColor' => '#94a3b8',
                ],
            ],
        ]);

        return $chart;
    }

    #[Route('/api/portfolio-history', name: 'api_portfolio_history')]
    public function portfolioHistory(PortfolioSnapshotService $snapshotService): JsonResponse
    {
        return $this->json($snapshotService->getHistory(365));
    }

    /**
     * @param array<string, array<string, mixed>> $positions
     */
    private function buildPerformanceChart(ChartBuilderInterface $chartBuilder, array $positions): Chart
    {
        $chart = $chartBuilder->createChart(Chart::TYPE_BAR);

        $labels = [];
        $data = [];
        $colors = [];

        foreach ($positions as $name => $pos) {
            if ((float) ($pos['value'] ?? 0) <= 0) {
                continue;
            }
            $labels[] = $name;
            $plPct = round((float) ($pos['pl_pct'] ?? 0), 1);
            $data[] = $plPct;
            $colors[] = $plPct >= 0 ? '#22c55e' : '#ef4444';
        }

        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $data,
                'backgroundColor' => $colors,
                'borderRadius' => 4,
                'barPercentage' => 0.7,
            ]],
        ]);

        $chart->setOptions([
            'indexAxis' => 'y',
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'x' => [
                    'grid' => ['color' => 'rgba(148,163,184,0.08)', 'drawBorder' => false],
                    'ticks' => ['color' => '#64748b', 'font' => ['size' => 10], 'callback' => '@@function(v) { return v + "%"; }@@'],
                ],
                'y' => [
                    'grid' => ['display' => false],
                    'ticks' => ['color' => '#94a3b8', 'font' => ['size' => 11]],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'backgroundColor' => '#0f172a',
                    'callbacks' => ['label' => '@@function(ctx) { return ctx.parsed.x.toFixed(1) + "%"; }@@'],
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    private function buildHistoryChart(ChartBuilderInterface $chartBuilder, array $history): Chart
    {
        $chart = $chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'labels' => array_column($history, 'date'),
            'datasets' => [
                [
                    'label' => 'Portfolio Waarde',
                    'data' => array_column($history, 'total_value'),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => ['color' => 'rgba(148,163,184,0.08)'],
                    'ticks' => ['color' => '#64748b', 'maxTicksLimit' => 12],
                ],
                'y' => [
                    'grid' => ['color' => 'rgba(148,163,184,0.08)'],
                    'ticks' => ['color' => '#64748b'],
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * @param list<array<string, mixed>> $history
     */
    private function buildAllocationHistoryChart(ChartBuilderInterface $chartBuilder, array $history): Chart
    {
        $chart = $chartBuilder->createChart(Chart::TYPE_LINE);

        $chart->setData([
            'labels' => array_column($history, 'date'),
            'datasets' => [
                [
                    'label' => 'Equity',
                    'data' => array_column($history, 'equity_pct'),
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Fixed Income',
                    'data' => array_column($history, 'fi_pct'),
                    'borderColor' => '#22c55e',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Alternatives',
                    'data' => array_column($history, 'alt_pct'),
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Cash',
                    'data' => array_column($history, 'cash_pct'),
                    'borderColor' => '#94a3b8',
                    'backgroundColor' => 'rgba(148, 163, 184, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 0,
                    'borderWidth' => 2,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'labels' => ['color' => '#94a3b8', 'boxWidth' => 12, 'padding' => 12],
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'scales' => [
                'x' => [
                    'grid' => ['color' => 'rgba(148,163,184,0.08)'],
                    'ticks' => ['color' => '#64748b', 'maxTicksLimit' => 12],
                ],
                'y' => [
                    'grid' => ['color' => 'rgba(148,163,184,0.08)'],
                    'ticks' => ['color' => '#64748b'],
                    'min' => 0,
                    'max' => 100,
                ],
            ],
        ]);

        return $chart;
    }
}
