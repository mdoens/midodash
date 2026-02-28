<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SaxoClient
{
    private const CACHE_TTL = 900;

    private readonly string $tokenFile;
    private readonly string $cacheFile;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly DataBufferService $dataBuffer,
        private readonly string $saxoAppKey,
        private readonly string $saxoAppSecret,
        private readonly string $saxoRedirectUri,
        private readonly string $saxoAuthEndpoint,
        private readonly string $saxoTokenEndpoint,
        private readonly string $saxoApiBase,
        private readonly string $projectDir,
    ) {
        $this->tokenFile = $this->projectDir . '/var/saxo_tokens.json';
        $this->cacheFile = $this->projectDir . '/var/saxo_cache.json';
    }

    public function getAuthUrl(string $state): string
    {
        return $this->saxoAuthEndpoint . '?' . http_build_query([
            'client_id' => $this->saxoAppKey,
            'response_type' => 'code',
            'redirect_uri' => $this->saxoRedirectUri,
            'state' => $state,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->httpClient->request('POST', $this->saxoTokenEndpoint, [
            'body' => [
                'grant_type' => 'authorization_code',
                'client_id' => $this->saxoAppKey,
                'client_secret' => $this->saxoAppSecret,
                'code' => $code,
                'redirect_uri' => $this->saxoRedirectUri,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            throw new \RuntimeException('Saxo token endpoint returned HTTP ' . $statusCode);
        }

        $tokens = $response->toArray(false);

        if (!isset($tokens['access_token'])) {
            $this->logger->error('Saxo token exchange failed', ['response' => $tokens]);
            throw new \RuntimeException('Token exchange failed: ' . json_encode($tokens));
        }

        // Track refresh token creation time separately (critical for expiry calculation)
        $tokens['refresh_token_created_at'] = time();
        $this->saveTokens($tokens);
        $this->clearCache();
        $this->logger->info('Saxo token exchange successful', [
            'expires_in' => $tokens['expires_in'] ?? 'unknown',
            'refresh_token_expires_in' => $tokens['refresh_token_expires_in'] ?? 'unknown',
            'has_refresh_token' => isset($tokens['refresh_token']),
        ]);

        return $tokens;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function refreshToken(): ?array
    {
        $oldTokens = $this->loadTokens();
        if ($oldTokens === null || !isset($oldTokens['refresh_token'])) {
            return null;
        }

        // Retry up to 2 times on transient failures
        $maxAttempts = 2;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $this->httpClient->request('POST', $this->saxoTokenEndpoint, [
                    'body' => [
                        'grant_type' => 'refresh_token',
                        'client_id' => $this->saxoAppKey,
                        'client_secret' => $this->saxoAppSecret,
                        'refresh_token' => $oldTokens['refresh_token'],
                    ],
                ]);

                $statusCode = $response->getStatusCode();

                // 4xx = client error (invalid/revoked token) — no point retrying
                if ($statusCode >= 400 && $statusCode < 500) {
                    $this->logger->warning('Saxo token refresh returned client error', [
                        'status' => $statusCode,
                        'attempt' => $attempt,
                    ]);

                    return null;
                }

                // 5xx = server error — retry
                if ($statusCode >= 500) {
                    $this->logger->error('Saxo token endpoint returned server error', [
                        'status' => $statusCode,
                        'attempt' => $attempt,
                    ]);

                    if ($attempt < $maxAttempts) {
                        usleep(500_000); // 500ms before retry
                        continue;
                    }

                    return null;
                }

                $newTokens = $response->toArray(false);

                if (!isset($newTokens['access_token'])) {
                    $this->logger->warning('Saxo token refresh returned no access_token', ['response' => $newTokens]);

                    return null;
                }

                // Merge: preserve fields from old tokens that Saxo didn't return in the refresh response
                // This prevents losing refresh_token or refresh_token_expires_in
                $mergedTokens = array_merge($oldTokens, $newTokens);
                unset($mergedTokens['created_at']); // Will be set fresh by saveTokens()

                // Track refresh token origin separately:
                // If Saxo returned a NEW refresh_token, update its creation time.
                // If not, preserve the original creation time so expiry calculation stays accurate.
                if (isset($newTokens['refresh_token'])) {
                    $mergedTokens['refresh_token_created_at'] = time();
                    $this->logger->info('Saxo: new refresh_token received in refresh response');
                } else {
                    // Preserve original refresh_token_created_at
                    $mergedTokens['refresh_token_created_at'] = $oldTokens['refresh_token_created_at']
                        ?? $oldTokens['created_at']
                        ?? time();
                    $this->logger->info('Saxo: no new refresh_token in response, preserving original expiry');
                }

                $this->saveTokens($mergedTokens);
                $this->logger->info('Saxo token refreshed successfully', [
                    'attempt' => $attempt,
                    'new_access_expires_in' => $newTokens['expires_in'] ?? '?',
                    'has_new_refresh' => isset($newTokens['refresh_token']),
                    'refresh_ttl' => isset($mergedTokens['refresh_token_expires_in'])
                        ? (int) $mergedTokens['refresh_token_created_at'] + (int) $mergedTokens['refresh_token_expires_in'] - time()
                        : '?',
                ]);

                return $mergedTokens;
            } catch (\Throwable $e) {
                $this->logger->error('Saxo token refresh failed', [
                    'error' => $e->getMessage(),
                    'attempt' => $attempt,
                ]);

                if ($attempt < $maxAttempts) {
                    usleep(500_000);
                    continue;
                }

                return null;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function getPositions(): ?array
    {
        $cached = $this->loadCache();
        if ($cached !== null && isset($cached['positions'])) {
            return $cached['positions'];
        }

        $token = $this->getValidToken();
        if ($token === null) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $this->saxoApiBase . '/port/v1/positions/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => ['FieldGroups' => 'DisplayAndFormat,PositionBase,PositionView'],
            ]);

            if ($response->getStatusCode() === 401) {
                $refreshed = $this->refreshToken();
                if ($refreshed === null) {
                    $this->logger->warning('Saxo positions: auth failed after refresh');

                    return null;
                }

                $response = $this->httpClient->request('GET', $this->saxoApiBase . '/port/v1/positions/me', [
                    'headers' => ['Authorization' => 'Bearer ' . $refreshed['access_token']],
                    'query' => ['FieldGroups' => 'DisplayAndFormat,PositionBase,PositionView'],
                ]);

                if ($response->getStatusCode() === 401) {
                    $this->logger->error('Saxo positions: still 401 after token refresh');

                    return null;
                }
            }

            $positions = $this->parsePositions($response->toArray(false));
            $this->updateCache('positions', $positions);
            $this->dataBuffer->store('saxo', 'positions', $positions);
            $this->logger->info('Saxo positions fetched', ['count' => count($positions)]);

            return $positions;
        } catch (\Throwable $e) {
            $this->logger->error('Saxo positions fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAccountBalance(): ?array
    {
        $cached = $this->loadCache();
        if ($cached !== null && isset($cached['balance'])) {
            return $cached['balance'];
        }

        $token = $this->getValidToken();
        if ($token === null) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $this->saxoApiBase . '/port/v1/balances/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => ['FieldGroups' => 'CalculateCashForTrading'],
            ]);

            $balance = $response->toArray(false);
            $this->updateCache('balance', $balance);
            $this->dataBuffer->store('saxo', 'balance', $balance);

            return $balance;
        } catch (\Throwable $e) {
            $this->logger->error('Saxo balance fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get the ClientKey for the authenticated user.
     */
    public function getClientKey(): ?string
    {
        $token = $this->getValidToken();
        if ($token === null) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $this->saxoApiBase . '/port/v1/clients/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);

            $data = $response->toArray(false);

            return $data['ClientKey'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('Saxo ClientKey fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fetch historical trades from Saxo (past 12 months).
     * Uses /cs/v1/reports/trades/{ClientKey} (not /me).
     *
     * @return list<array<string, mixed>>|null
     */
    public function getHistoricalTrades(): ?array
    {
        $token = $this->getValidToken();
        if ($token === null) {
            return null;
        }

        $clientKey = $this->getClientKey();
        if ($clientKey === null) {
            $this->logger->warning('Saxo trades: could not resolve ClientKey');

            return null;
        }

        try {
            $fromDate = (new \DateTimeImmutable('-12 months'))->format('Y-m-d');
            $toDate = (new \DateTimeImmutable())->format('Y-m-d');

            $url = $this->saxoApiBase . '/cs/v1/reports/trades/' . urlencode($clientKey);

            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => [
                    'FromDate' => $fromDate,
                    'ToDate' => $toDate,
                ],
            ]);

            if ($response->getStatusCode() === 401) {
                $refreshed = $this->refreshToken();
                if ($refreshed === null) {
                    return null;
                }

                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Authorization' => 'Bearer ' . $refreshed['access_token']],
                    'query' => [
                        'FromDate' => $fromDate,
                        'ToDate' => $toDate,
                    ],
                ]);
            }

            $data = $response->toArray(false);
            $this->logger->info('Saxo historical trades fetched', [
                'status' => $response->getStatusCode(),
                'count' => count($data['Data'] ?? []),
                'keys' => array_keys($data),
                'clientKey' => substr($clientKey, 0, 8) . '...',
            ]);

            return $data['Data'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->error('Saxo historical trades fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fetch cash transactions (dividends, interest, fees, deposits) from Saxo.
     * Uses /hist/v1/transactions endpoint.
     *
     * @return list<array<string, mixed>>|null
     */
    public function getCashTransactions(): ?array
    {
        $token = $this->getValidToken();
        if ($token === null) {
            return null;
        }

        $clientKey = $this->getClientKey();
        if ($clientKey === null) {
            return null;
        }

        try {
            $fromDate = (new \DateTimeImmutable('-24 months'))->format('Y-m-d');
            $toDate = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');

            $url = $this->saxoApiBase . '/hist/v1/transactions';

            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => [
                    'ClientKey' => $clientKey,
                    'FromDate' => $fromDate,
                    'ToDate' => $toDate,
                    '$top' => 1000,
                ],
            ]);

            if ($response->getStatusCode() === 401) {
                $refreshed = $this->refreshToken();
                if ($refreshed === null) {
                    return null;
                }

                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Authorization' => 'Bearer ' . $refreshed['access_token']],
                    'query' => [
                        'ClientKey' => $clientKey,
                        'FromDate' => $fromDate,
                        'ToDate' => $toDate,
                        '$top' => 1000,
                    ],
                ]);

                if ($response->getStatusCode() === 401) {
                    $this->logger->error('Saxo cash transactions: still 401 after token refresh');

                    return null;
                }
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $this->logger->warning('Saxo cash transactions returned HTTP ' . $statusCode);

                return null;
            }

            $data = $response->toArray(false);
            $this->logger->info('Saxo cash transactions fetched', [
                'count' => count($data['Data'] ?? []),
                'keys' => array_keys($data),
            ]);

            return $data['Data'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->error('Saxo cash transactions fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fetch open/working orders from Saxo.
     *
     * @return list<array{order_id: string, symbol: string, description: string, buy_sell: string, amount: float, cash_amount: float, order_type: string, price: float, order_value: float, duration: string, status: string}>|null
     */
    public function getOpenOrders(): ?array
    {
        $cached = $this->loadCache();
        if ($cached !== null && isset($cached['open_orders'])) {
            return $cached['open_orders'];
        }

        $token = $this->getValidToken();
        if ($token === null) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $this->saxoApiBase . '/port/v1/orders/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => ['FieldGroups' => 'DisplayAndFormat'],
            ]);

            if ($response->getStatusCode() === 401) {
                $refreshed = $this->refreshToken();
                if ($refreshed === null) {
                    $this->logger->warning('Saxo open orders: auth failed after refresh');

                    return null;
                }

                $response = $this->httpClient->request('GET', $this->saxoApiBase . '/port/v1/orders/me', [
                    'headers' => ['Authorization' => 'Bearer ' . $refreshed['access_token']],
                    'query' => ['FieldGroups' => 'DisplayAndFormat'],
                ]);

                if ($response->getStatusCode() === 401) {
                    $this->logger->error('Saxo open orders: still 401 after token refresh');

                    return null;
                }
            }

            $data = $response->toArray(false);
            $orders = [];

            foreach ($data['Data'] ?? [] as $order) {
                $display = $order['DisplayAndFormat'] ?? [];
                $buySell = (string) ($order['BuySell'] ?? 'Unknown');
                $status = (string) ($order['Status'] ?? 'Unknown');

                // Only include working/pending orders
                if (!in_array($status, ['Working', 'NotTriggered', 'Placed'], true)) {
                    continue;
                }

                $duration = $order['Duration'] ?? [];
                $durationStr = is_array($duration) ? ($duration['DurationType'] ?? 'Unknown') : (string) $duration;

                $amount = (float) ($order['Amount'] ?? 0);
                $cashAmount = (float) ($order['CashAmount'] ?? 0);
                $price = (float) ($order['Price'] ?? 0);

                // Order value: CashAmount for cash-based orders (mutual funds), or Amount * Price for limit orders
                $orderValue = $cashAmount > 0 ? $cashAmount : ($amount > 0 && $price > 0 ? $amount * $price : 0.0);

                $orders[] = [
                    'order_id' => (string) ($order['OrderId'] ?? ''),
                    'symbol' => (string) ($display['Symbol'] ?? ($order['Uic'] ?? '?')),
                    'description' => (string) ($display['Description'] ?? ''),
                    'buy_sell' => $buySell,
                    'amount' => $amount,
                    'cash_amount' => $cashAmount,
                    'order_type' => (string) ($order['OrderType'] ?? 'Unknown'),
                    'price' => $price,
                    'order_value' => $orderValue,
                    'duration' => $durationStr,
                    'status' => $status,
                ];
            }

            $this->updateCache('open_orders', $orders);
            $this->logger->info('Saxo open orders fetched', ['count' => count($orders)]);

            return $orders;
        } catch (\Throwable $e) {
            $this->logger->error('Saxo open orders fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Fetch closed positions from Saxo.
     *
     * @return list<array{symbol: string, description: string, open_price: float, close_price: float, profit_loss: float, amount: float, asset_type: string, open_time: string, close_time: string}>|null
     */
    public function getClosedPositions(): ?array
    {
        $cached = $this->loadCache();
        if ($cached !== null && isset($cached['closed_positions'])) {
            return $cached['closed_positions'];
        }

        $token = $this->getValidToken();
        if ($token === null) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $this->saxoApiBase . '/port/v1/closedpositions/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => ['FieldGroups' => 'ClosedPosition,DisplayAndFormat'],
            ]);

            if ($response->getStatusCode() === 401) {
                $refreshed = $this->refreshToken();
                if ($refreshed === null) {
                    return null;
                }

                $response = $this->httpClient->request('GET', $this->saxoApiBase . '/port/v1/closedpositions/me', [
                    'headers' => ['Authorization' => 'Bearer ' . $refreshed['access_token']],
                    'query' => ['FieldGroups' => 'ClosedPosition,DisplayAndFormat'],
                ]);

                if ($response->getStatusCode() === 401) {
                    $this->logger->error('Saxo closed positions: still 401 after token refresh');

                    return null;
                }
            }

            $data = $response->toArray(false);
            $positions = $this->parseClosedPositions($data);
            $this->updateCache('closed_positions', $positions);
            $this->dataBuffer->store('saxo', 'closed_positions', $positions);
            $this->logger->info('Saxo closed positions fetched', ['count' => count($positions)]);

            return $positions;
        } catch (\Throwable $e) {
            $this->logger->error('Saxo closed positions fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{symbol: string, description: string, open_price: float, close_price: float, profit_loss: float, amount: float, asset_type: string, open_time: string, close_time: string}>
     */
    public function parseClosedPositions(array $data): array
    {
        $positions = [];
        foreach ($data['Data'] ?? [] as $pos) {
            $closed = $pos['ClosedPosition'] ?? [];
            $display = $pos['DisplayAndFormat'] ?? [];

            $positions[] = [
                'symbol' => (string) ($display['Symbol'] ?? $closed['Symbol'] ?? '?'),
                'description' => (string) ($display['Description'] ?? $closed['Description'] ?? ''),
                'open_price' => (float) ($closed['OpenPrice'] ?? 0),
                'close_price' => (float) ($closed['ClosePrice'] ?? 0),
                'profit_loss' => (float) ($closed['ClosedProfitLoss'] ?? 0),
                'amount' => (float) ($closed['Amount'] ?? 0),
                'asset_type' => (string) ($closed['AssetType'] ?? ''),
                'open_time' => (string) ($closed['ExecutionTimeOpen'] ?? ''),
                'close_time' => (string) ($closed['ExecutionTimeClose'] ?? ''),
            ];
        }

        return $positions;
    }

    /**
     * Fetch performance metrics (TWR, Sharpe, Sortino, MaxDrawDown) from Saxo.
     *
     * @return array<string, mixed>|null
     */
    public function getPerformanceMetrics(): ?array
    {
        $cached = $this->loadCache();
        if ($cached !== null && isset($cached['performance'])) {
            return $cached['performance'];
        }

        $token = $this->getValidToken();
        if ($token === null) {
            return null;
        }

        $clientKey = $this->getClientKey();
        if ($clientKey === null) {
            return null;
        }

        try {
            $url = $this->saxoApiBase . '/hist/v3/perf/' . urlencode($clientKey);

            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => [
                    'FieldGroups' => 'AccountSummary,TimeWeightedPerformance,BalancePerformance',
                    'StandardPeriod' => 'AllTime',
                ],
            ]);

            if ($response->getStatusCode() === 401) {
                $refreshed = $this->refreshToken();
                if ($refreshed === null) {
                    return null;
                }

                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Authorization' => 'Bearer ' . $refreshed['access_token']],
                    'query' => [
                        'FieldGroups' => 'AccountSummary,TimeWeightedPerformance,BalancePerformance',
                        'StandardPeriod' => 'AllTime',
                    ],
                ]);
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $this->logger->warning('Saxo performance metrics returned HTTP ' . $statusCode);

                return null;
            }

            $data = $response->toArray(false);
            $metrics = $this->parsePerformanceMetrics($data);
            $this->updateCache('performance', $metrics);
            $this->dataBuffer->store('saxo', 'performance', $metrics);
            $this->logger->info('Saxo performance metrics fetched');

            return $metrics;
        } catch (\Throwable $e) {
            $this->logger->error('Saxo performance metrics fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function parsePerformanceMetrics(array $data): array
    {
        $twr = $data['TimeWeightedPerformance'] ?? [];
        $balance = $data['BalancePerformance'] ?? [];
        $summary = $data['AccountSummary'] ?? [];

        return [
            'twr' => (float) ($twr['TimeWeightedReturn'] ?? 0),
            'twr_annualized' => (float) ($twr['TimeWeightedReturnAnnualized'] ?? 0),
            'sharpe_ratio' => (float) ($twr['SharpeRatio'] ?? 0),
            'sortino_ratio' => (float) ($twr['SortinoRatio'] ?? 0),
            'max_drawdown' => (float) ($balance['MaxDrawDown'] ?? $twr['MaxDrawDown'] ?? 0),
            'total_return_fraction' => (float) ($balance['TotalReturnFraction'] ?? 0),
            'total_deposited' => (float) ($summary['TotalDeposited'] ?? 0),
            'total_withdrawn' => (float) ($summary['TotalWithdrawn'] ?? 0),
            'total_profit_loss' => (float) ($summary['TotalProfitLoss'] ?? 0),
        ];
    }

    /**
     * Fetch currency exposure from Saxo.
     *
     * @return list<array{currency: string, amount: float, amount_base: float}>|null
     */
    public function getCurrencyExposure(): ?array
    {
        $cached = $this->loadCache();
        if ($cached !== null && isset($cached['currency_exposure'])) {
            return $cached['currency_exposure'];
        }

        $token = $this->getValidToken();
        if ($token === null) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', $this->saxoApiBase . '/port/v1/exposure/currency/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);

            if ($response->getStatusCode() === 401) {
                $refreshed = $this->refreshToken();
                if ($refreshed === null) {
                    return null;
                }

                $response = $this->httpClient->request('GET', $this->saxoApiBase . '/port/v1/exposure/currency/me', [
                    'headers' => ['Authorization' => 'Bearer ' . $refreshed['access_token']],
                ]);

                if ($response->getStatusCode() === 401) {
                    $this->logger->error('Saxo currency exposure: still 401 after token refresh');

                    return null;
                }
            }

            $data = $response->toArray(false);
            $exposure = $this->parseCurrencyExposure($data);
            $this->updateCache('currency_exposure', $exposure);
            $this->dataBuffer->store('saxo', 'currency_exposure', $exposure);
            $this->logger->info('Saxo currency exposure fetched', ['count' => count($exposure)]);

            return $exposure;
        } catch (\Throwable $e) {
            $this->logger->error('Saxo currency exposure fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array{currency: string, amount: float, amount_base: float}>
     */
    public function parseCurrencyExposure(array $data): array
    {
        $exposure = [];
        foreach ($data['Data'] ?? [] as $item) {
            $exposure[] = [
                'currency' => (string) ($item['Currency'] ?? '?'),
                'amount' => (float) ($item['Amount'] ?? 0),
                'amount_base' => (float) ($item['AmountInCalculationEntityCurrency'] ?? $item['Amount'] ?? 0),
            ];
        }

        return $exposure;
    }

    /**
     * Fetch historical account values from Saxo.
     *
     * @return array<string, mixed>|null
     */
    public function getAccountValues(?string $fromDate = null): ?array
    {
        $token = $this->getValidToken();
        if ($token === null) {
            return null;
        }

        $clientKey = $this->getClientKey();
        if ($clientKey === null) {
            return null;
        }

        try {
            $url = $this->saxoApiBase . '/hist/v3/accountvalues/' . urlencode($clientKey);
            $query = [];
            if ($fromDate !== null) {
                $query['FromDate'] = $fromDate;
            }

            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer ' . $token],
                'query' => $query,
            ]);

            if ($response->getStatusCode() === 401) {
                $refreshed = $this->refreshToken();
                if ($refreshed === null) {
                    return null;
                }

                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Authorization' => 'Bearer ' . $refreshed['access_token']],
                    'query' => $query,
                ]);
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $this->logger->warning('Saxo account values returned HTTP ' . $statusCode);

                return null;
            }

            $data = $response->toArray(false);
            $this->dataBuffer->store('saxo', 'account_values', $data);
            $this->logger->info('Saxo account values fetched');

            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('Saxo account values fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Actively ensure we have a valid token (refresh if needed).
     * Use this instead of isAuthenticated() when you need to make API calls.
     */
    public function ensureValidToken(): bool
    {
        return $this->getValidToken() !== null;
    }

    public function isAuthenticated(): bool
    {
        $cached = $this->loadCache();
        if ($cached !== null) {
            return true;
        }

        return $this->getValidToken() !== null;
    }

    public function getTokenExpiry(): ?int
    {
        $tokens = $this->loadTokens();
        if ($tokens === null || !isset($tokens['created_at'], $tokens['expires_in'])) {
            return null;
        }

        return (int) $tokens['created_at'] + (int) $tokens['expires_in'];
    }

    public function getRefreshTokenTtl(): ?int
    {
        $tokens = $this->loadTokens();
        if ($tokens === null || !isset($tokens['refresh_token_expires_in'])) {
            return null;
        }

        $refreshCreatedAt = (int) ($tokens['refresh_token_created_at'] ?? $tokens['created_at'] ?? 0);
        if ($refreshCreatedAt === 0) {
            return null;
        }

        $expiresAt = $refreshCreatedAt + (int) $tokens['refresh_token_expires_in'];

        return max(0, $expiresAt - time());
    }

    private function getValidToken(): ?string
    {
        $tokens = $this->loadTokens();
        if ($tokens === null || !isset($tokens['access_token'])) {
            return null;
        }

        if (isset($tokens['created_at'], $tokens['expires_in'])) {
            $createdAt = (int) $tokens['created_at'];
            $expiresIn = (int) $tokens['expires_in'];
            $expiresAt = $createdAt + $expiresIn;
            $now = time();

            // Access token fully expired — must refresh
            if ($now > $expiresAt) {
                return $this->tryRefreshOrNull($tokens);
            }

            // Access token still valid
            $lifetime = $expiresIn;
            $elapsed = $now - $createdAt;
            $halfLife = $lifetime / 2;

            // Proactive refresh: when past 50% of lifetime, refresh in background
            // This prevents the last-minute rush that causes failures
            if ($elapsed > $halfLife) {
                $refreshed = $this->tryRefresh($tokens);
                if ($refreshed !== null) {
                    return $refreshed['access_token'];
                }

                // Refresh failed but access token is still valid — use it
                return $tokens['access_token'];
            }

            // Token is fresh (< 50% lifetime) — use as-is
            return $tokens['access_token'];
        }

        // No timing info — use token as-is (will get 401 if expired, handled by callers)
        return $tokens['access_token'];
    }

    /**
     * Try to refresh, return null if refresh token is also expired (needs re-auth).
     *
     * @param array<string, mixed> $tokens
     */
    private function tryRefreshOrNull(array $tokens): ?string
    {
        $refreshCreatedAt = (int) ($tokens['refresh_token_created_at'] ?? $tokens['created_at'] ?? 0);
        $refreshExpiresIn = (int) ($tokens['refresh_token_expires_in'] ?? 0);

        if ($refreshCreatedAt > 0 && $refreshExpiresIn > 0) {
            $refreshExpiresAt = $refreshCreatedAt + $refreshExpiresIn;
            if (time() > $refreshExpiresAt) {
                $this->logger->warning('Saxo: refresh token expired, re-auth required', [
                    'refresh_created_at' => date('H:i:s', $refreshCreatedAt),
                    'refresh_expires_in' => $refreshExpiresIn,
                    'expired_ago' => time() - $refreshExpiresAt,
                ]);

                return null;
            }
        }

        $refreshed = $this->refreshToken();

        return $refreshed['access_token'] ?? null;
    }

    /**
     * Try to refresh. Returns new tokens on success, null on failure.
     * Does NOT return null for expired refresh tokens — caller decides what to do.
     *
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>|null
     */
    private function tryRefresh(array $tokens): ?array
    {
        // Don't attempt refresh if refresh token is expired
        $refreshCreatedAt = (int) ($tokens['refresh_token_created_at'] ?? $tokens['created_at'] ?? 0);
        if ($refreshCreatedAt > 0 && isset($tokens['refresh_token_expires_in'])) {
            $refreshExpiresAt = $refreshCreatedAt + (int) $tokens['refresh_token_expires_in'];
            if (time() > $refreshExpiresAt) {
                $this->logger->info('Saxo: refresh token expired, proactive refresh skipped');

                return null;
            }
        }

        return $this->refreshToken();
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, array<string, mixed>>
     */
    public function parsePositions(array $data): array
    {
        $positions = [];
        foreach ($data['Data'] ?? [] as $pos) {
            $display = $pos['DisplayAndFormat'] ?? [];
            $base = $pos['PositionBase'] ?? [];
            $view = $pos['PositionView'] ?? [];

            $positions[] = [
                'symbol' => $display['Symbol'] ?? '?',
                'description' => $display['Description'] ?? '',
                'currency' => $display['Currency'] ?? 'EUR',
                'type' => $base['AssetType'] ?? '',
                'amount' => $base['Amount'] ?? 0,
                'open_price' => $base['OpenPrice'] ?? 0,
                'current_price' => $view['CurrentPrice'] ?? 0,
                'pnl' => $view['ProfitLossOnTrade'] ?? 0,
                'pnl_base' => $view['ProfitLossOnTradeInBaseCurrency'] ?? 0,
                'exposure' => $view['ExposureInBaseCurrency'] ?? 0,
            ];
        }

        return $positions;
    }

    /**
     * @param array<string, mixed> $tokens
     */
    private function saveTokens(array $tokens): void
    {
        $tokens['created_at'] = time();

        // Primary: save to database (survives deploys)
        $this->dataBuffer->store('saxo', 'tokens', $tokens);

        // Secondary: save to file (fast reads, may not survive deploys)
        $dir = dirname($this->tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $written = file_put_contents($this->tokenFile, json_encode($tokens, JSON_PRETTY_PRINT));

        if ($written === false) {
            $this->logger->error('Saxo token file write FAILED', ['path' => $this->tokenFile]);
        } else {
            $this->logger->info('Saxo tokens saved', [
                'file' => $written . ' bytes',
                'expires_in' => $tokens['expires_in'] ?? 'unknown',
                'has_refresh' => isset($tokens['refresh_token']),
            ]);
        }

        // Verify: read back from both sources to confirm persistence
        $fileCheck = file_exists($this->tokenFile) && file_get_contents($this->tokenFile) !== false;
        $dbCheck = $this->dataBuffer->retrieve('saxo', 'tokens') !== null;
        $this->logger->info('Saxo token save verification', ['file_ok' => $fileCheck, 'db_ok' => $dbCheck]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTokens(): ?array
    {
        $fileTokens = null;
        $dbTokens = null;

        // Try file first (fast)
        if (file_exists($this->tokenFile)) {
            $content = file_get_contents($this->tokenFile);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if (is_array($decoded) && isset($decoded['access_token'])) {
                    $fileTokens = $decoded;
                }
            }
        }

        // Also check database (may have newer tokens if file write failed due to permissions)
        $buffered = $this->dataBuffer->retrieve('saxo', 'tokens');
        if ($buffered !== null && isset($buffered['data']['access_token'])) {
            $dbTokens = $buffered['data'];
        }

        // Use whichever source has the most recent tokens (by created_at)
        if ($fileTokens !== null && $dbTokens !== null) {
            $fileCreatedAt = (int) ($fileTokens['created_at'] ?? 0);
            $dbCreatedAt = (int) ($dbTokens['created_at'] ?? 0);

            if ($dbCreatedAt > $fileCreatedAt) {
                $this->logger->info('Saxo: DB tokens are newer than file, using DB', [
                    'file_created' => date('H:i:s', $fileCreatedAt),
                    'db_created' => date('H:i:s', $dbCreatedAt),
                ]);

                // Try to sync file with DB tokens
                @file_put_contents($this->tokenFile, json_encode($dbTokens, JSON_PRETTY_PRINT));

                return $dbTokens;
            }

            return $fileTokens;
        }

        if ($fileTokens !== null) {
            return $fileTokens;
        }

        if ($dbTokens !== null) {
            $this->logger->info('Saxo tokens restored from database');

            // Re-create the file for fast subsequent reads
            @file_put_contents($this->tokenFile, json_encode($dbTokens, JSON_PRETTY_PRINT));

            return $dbTokens;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCache(): ?array
    {
        if (!file_exists($this->cacheFile)) {
            return null;
        }

        if ((time() - filemtime($this->cacheFile)) > self::CACHE_TTL) {
            return null;
        }

        $content = file_get_contents($this->cacheFile);
        if ($content === false) {
            return null;
        }

        return json_decode($content, true);
    }

    private function updateCache(string $key, mixed $value): void
    {
        $cache = [];
        if (file_exists($this->cacheFile)) {
            $content = file_get_contents($this->cacheFile);
            if ($content !== false) {
                $cache = json_decode($content, true) ?? [];
            }
        }

        $cache[$key] = $value;

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->cacheFile, json_encode($cache));
    }

    private function clearCache(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }
}
