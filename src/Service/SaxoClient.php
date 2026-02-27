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

        $tokens = $response->toArray(false);

        if (!isset($tokens['access_token'])) {
            $this->logger->error('Saxo token exchange failed', ['response' => $tokens]);
            throw new \RuntimeException('Token exchange failed: ' . json_encode($tokens));
        }

        $this->saveTokens($tokens);
        $this->clearCache();
        $this->logger->info('Saxo token exchange successful');

        return $tokens;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function refreshToken(): ?array
    {
        $tokens = $this->loadTokens();
        if ($tokens === null || !isset($tokens['refresh_token'])) {
            return null;
        }

        try {
            $response = $this->httpClient->request('POST', $this->saxoTokenEndpoint, [
                'body' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->saxoAppKey,
                    'client_secret' => $this->saxoAppSecret,
                    'refresh_token' => $tokens['refresh_token'],
                ],
            ]);

            $newTokens = $response->toArray(false);

            if (!isset($newTokens['access_token'])) {
                $this->logger->warning('Saxo token refresh returned no access_token');

                return null;
            }

            $this->saveTokens($newTokens);
            $this->logger->info('Saxo token refreshed successfully');

            return $newTokens;
        } catch (\Throwable $e) {
            $this->logger->error('Saxo token refresh failed', ['error' => $e->getMessage()]);

            return null;
        }
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
        if ($tokens === null || !isset($tokens['created_at'], $tokens['refresh_token_expires_in'])) {
            return null;
        }

        $expiresAt = (int) $tokens['created_at'] + (int) $tokens['refresh_token_expires_in'];

        return max(0, $expiresAt - time());
    }

    private function getValidToken(): ?string
    {
        $tokens = $this->loadTokens();
        if ($tokens === null || !isset($tokens['access_token'])) {
            return null;
        }

        if (isset($tokens['created_at'], $tokens['refresh_token_expires_in'])) {
            $refreshExpiresAt = (int) $tokens['created_at'] + (int) $tokens['refresh_token_expires_in'];
            if (time() > $refreshExpiresAt) {
                return null;
            }
        }

        if (isset($tokens['created_at'], $tokens['expires_in'])) {
            $expiresAt = (int) $tokens['created_at'] + (int) $tokens['expires_in'];
            if (time() > $expiresAt - 120) {
                $refreshed = $this->refreshToken();

                return $refreshed['access_token'] ?? null;
            }
        }

        return $tokens['access_token'];
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
        file_put_contents($this->tokenFile, json_encode($tokens, JSON_PRETTY_PRINT));

        $this->logger->info('Saxo tokens saved to database and file');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTokens(): ?array
    {
        // Try file first (fast)
        if (file_exists($this->tokenFile)) {
            $content = file_get_contents($this->tokenFile);
            if ($content !== false) {
                $tokens = json_decode($content, true);
                if (is_array($tokens) && isset($tokens['access_token'])) {
                    return $tokens;
                }
            }
        }

        // Fallback: load from database (survives deploys)
        $buffered = $this->dataBuffer->retrieve('saxo', 'tokens');
        if ($buffered !== null && isset($buffered['data']['access_token'])) {
            $tokens = $buffered['data'];
            $this->logger->info('Saxo tokens restored from database');

            // Re-create the file for fast subsequent reads
            $dir = dirname($this->tokenFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($this->tokenFile, json_encode($tokens, JSON_PRETTY_PRINT));

            return $tokens;
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
