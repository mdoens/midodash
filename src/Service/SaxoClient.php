<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SaxoClient
{
    private const CACHE_TTL = 900; // 15 minuten

    private readonly string $appKey;
    private readonly string $appSecret;
    private readonly string $redirectUri;
    private readonly string $authEndpoint;
    private readonly string $tokenEndpoint;
    private readonly string $apiBase;
    private readonly string $tokenFile;
    private readonly string $cacheFile;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->appKey = $_ENV['SAXO_APP_KEY'];
        $this->appSecret = $_ENV['SAXO_APP_SECRET'];
        $this->redirectUri = $_ENV['SAXO_REDIRECT_URI'];
        $this->authEndpoint = $_ENV['SAXO_AUTH_ENDPOINT'];
        $this->tokenEndpoint = $_ENV['SAXO_TOKEN_ENDPOINT'];
        $this->apiBase = $_ENV['SAXO_API_BASE'];
        $this->tokenFile = dirname(__DIR__, 2) . '/var/saxo_tokens.json';
        $this->cacheFile = dirname(__DIR__, 2) . '/var/saxo_cache.json';
    }

    public function getAuthUrl(string $state): string
    {
        return $this->authEndpoint . '?' . http_build_query([
            'client_id' => $this->appKey,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
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
        $response = $this->httpClient->request('POST', $this->tokenEndpoint, [
            'body' => [
                'grant_type' => 'authorization_code',
                'client_id' => $this->appKey,
                'client_secret' => $this->appSecret,
                'code' => $code,
                'redirect_uri' => $this->redirectUri,
            ],
        ]);

        $tokens = $response->toArray(false);

        if (!isset($tokens['access_token'])) {
            throw new \RuntimeException('Token exchange failed: ' . json_encode($tokens));
        }

        $this->saveTokens($tokens);
        $this->clearCache();

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
            $response = $this->httpClient->request('POST', $this->tokenEndpoint, [
                'body' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->appKey,
                    'client_secret' => $this->appSecret,
                    'refresh_token' => $tokens['refresh_token'],
                ],
            ]);

            $newTokens = $response->toArray(false);

            if (!isset($newTokens['access_token'])) {
                return null;
            }

            $this->saveTokens($newTokens);

            return $newTokens;
        } catch (\Throwable) {
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

        $response = $this->httpClient->request('GET', $this->apiBase . '/port/v1/positions/me', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
            'query' => ['FieldGroups' => 'DisplayAndFormat,PositionBase,PositionView'],
        ]);

        if ($response->getStatusCode() === 401) {
            $refreshed = $this->refreshToken();
            if ($refreshed === null) {
                return null;
            }

            $response = $this->httpClient->request('GET', $this->apiBase . '/port/v1/positions/me', [
                'headers' => ['Authorization' => 'Bearer ' . $refreshed['access_token']],
                'query' => ['FieldGroups' => 'DisplayAndFormat,PositionBase,PositionView'],
            ]);

            if ($response->getStatusCode() === 401) {
                return null;
            }
        }

        $positions = $this->parsePositions($response->toArray(false));
        $this->updateCache('positions', $positions);

        return $positions;
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
            $response = $this->httpClient->request('GET', $this->apiBase . '/port/v1/balances/me', [
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);

            $balance = $response->toArray(false);
            $this->updateCache('balance', $balance);

            return $balance;
        } catch (\Throwable) {
            return null;
        }
    }

    public function isAuthenticated(): bool
    {
        // Als er cache is, zijn we authenticated (data is recent)
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

    /**
     * @return int|null Seconden tot refresh token verloopt, null als onbekend
     */
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

        // Check of refresh token nog geldig is
        if (isset($tokens['created_at'], $tokens['refresh_token_expires_in'])) {
            $refreshExpiresAt = (int) $tokens['created_at'] + (int) $tokens['refresh_token_expires_in'];
            if (time() > $refreshExpiresAt) {
                return null; // Refresh token verlopen, opnieuw inloggen
            }
        }

        // Access token bijna verlopen? Proactief refreshen
        if (isset($tokens['created_at'], $tokens['expires_in'])) {
            $expiresAt = (int) $tokens['created_at'] + (int) $tokens['expires_in'];
            if (time() > $expiresAt - 120) { // 2 minuten marge
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
    private function parsePositions(array $data): array
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
        $dir = dirname($this->tokenFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->tokenFile, json_encode($tokens, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTokens(): ?array
    {
        if (!file_exists($this->tokenFile)) {
            return null;
        }

        $content = file_get_contents($this->tokenFile);
        if ($content === false) {
            return null;
        }

        return json_decode($content, true);
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
