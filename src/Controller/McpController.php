<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\CrisisService;
use App\Service\MarketDataService;
use App\Service\Mcp\McpDashboardService;
use App\Service\Mcp\McpIndicatorService;
use App\Service\Mcp\McpMomentumService;
use App\Service\Mcp\McpProtocolService;
use App\Service\TriggerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class McpController extends AbstractController
{
    private const PROTOCOL_VERSION = '2025-03-26';
    private const SESSION_TTL = 3600;

    /** @var list<string|null> */
    private const ALLOWED_ORIGINS = [
        'https://claude.ai',
        'https://www.claude.ai',
        'https://console.anthropic.com',
        null,
    ];

    // v8.0 crisis thresholds
    private const CRISIS_DRAWDOWN_THRESHOLD = -20;
    private const CRISIS_VIX_THRESHOLD = 30;
    private const CRISIS_VIX_SUSTAINED_DAYS = 3;
    private const CRISIS_CREDIT_THRESHOLD = 500;

    /** @var list<string> */
    private readonly array $apiTokens;

    public function __construct(
        private readonly McpProtocolService $mcpProtocol,
        private readonly McpDashboardService $dashboard,
        private readonly McpIndicatorService $indicators,
        private readonly McpMomentumService $momentum,
        private readonly TriggerService $triggers,
        private readonly CrisisService $crisis,
        private readonly MarketDataService $marketData,
        private readonly CacheInterface $cache,
        string $mcpApiTokens,
    ) {
        $this->apiTokens = array_filter(array_map('trim', explode(',', $mcpApiTokens)));
    }

    #[Route('/mcp/info', name: 'mcp_info', methods: ['GET'])]
    public function info(Request $request): Response
    {
        $authError = $this->validateBearerToken($request);
        if ($authError !== null) {
            return $authError;
        }

        return $this->corsResponse($this->json([
            'name' => 'MIDO Macro Economic MCP Server',
            'version' => '2.0.0',
            'strategy' => 'v8.0',
            'description' => 'Family Office Macro Dashboard for MIDO Holding B.V.',
            'protocol' => 'MCP Streamable HTTP',
            'protocolVersion' => self::PROTOCOL_VERSION,
            'endpoints' => [
                'GET /mcp/info' => 'This info page',
                'POST /mcp' => 'MCP JSON-RPC requests',
                'GET /mcp' => 'MCP SSE stream for server notifications',
                'DELETE /mcp' => 'Terminate MCP session',
            ],
            'tools' => [
                'mido_macro_dashboard',
                'mido_indicator',
                'mido_triggers',
                'mido_crisis_dashboard',
                'mido_drawdown_calculator',
                'mido_momentum_rebalancing',
            ],
        ]));
    }

    #[Route('/mcp', name: 'mcp', methods: ['GET', 'POST', 'DELETE', 'OPTIONS'])]
    public function mcp(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->corsResponse(new Response('', 204));
        }

        $authError = $this->validateBearerToken($request);
        if ($authError !== null) {
            return $authError;
        }

        $originValidation = $this->validateOrigin($request);
        if ($originValidation !== null) {
            return $originValidation;
        }

        if ($request->isMethod('GET')) {
            return $this->handleMcpGet($request);
        }

        if ($request->isMethod('POST')) {
            return $this->handleMcpPost($request);
        }

        if ($request->isMethod('DELETE')) {
            return $this->handleMcpDelete($request);
        }

        return $this->corsResponse($this->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32600, 'message' => 'Method not allowed. Use GET, POST, or DELETE.'],
        ], 405));
    }

    private function validateBearerToken(Request $request): ?Response
    {
        // No tokens configured = auth disabled (backwards compatible)
        if ($this->apiTokens === []) {
            return null;
        }

        $authHeader = $request->headers->get('Authorization', '');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->corsResponse($this->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Authorization required. Use: Authorization: Bearer <token>'],
            ], 401));
        }

        $token = substr($authHeader, 7);
        if (!in_array($token, $this->apiTokens, true)) {
            return $this->corsResponse($this->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Invalid bearer token'],
            ], 403));
        }

        return null;
    }

    private function validateOrigin(Request $request): ?Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null && in_array(null, self::ALLOWED_ORIGINS, true)) {
            return null;
        }

        if ($origin !== null && in_array($origin, self::ALLOWED_ORIGINS, true)) {
            return null;
        }

        return $this->corsResponse($this->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => ['code' => -32600, 'message' => 'Origin not allowed'],
        ], 403));
    }

    private function handleMcpPost(Request $request): Response
    {
        $accept = $request->headers->get('Accept', '');
        $acceptsJson = str_contains($accept, 'application/json') || str_contains($accept, '*/*') || $accept === '';
        if (!$acceptsJson) {
            return $this->corsResponse($this->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Accept header must include application/json'],
            ], 406));
        }

        $sessionValidation = $this->validateSession($request);
        if ($sessionValidation !== null) {
            return $sessionValidation;
        }

        $content = json_decode($request->getContent(), true);

        if ($content === null) {
            return $this->corsResponse($this->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32700, 'message' => 'Parse error: invalid JSON'],
            ], 400));
        }

        if (isset($content[0]) && is_array($content[0])) {
            return $this->handleBatchRequest($content);
        }

        return $this->handleSingleRequest($content);
    }

    /**
     * @param list<mixed> $batch
     */
    private function handleBatchRequest(array $batch): Response
    {
        $responses = [];
        $allNotifications = true;
        $hasInitialize = false;
        $sessionId = null;

        foreach ($batch as $item) {
            if (!is_array($item)) {
                $responses[] = [
                    'jsonrpc' => '2.0',
                    'id' => null,
                    'error' => ['code' => -32600, 'message' => 'Invalid Request'],
                ];
                $allNotifications = false;
                continue;
            }

            $result = $this->processSingleMessage($item);

            if (($item['method'] ?? '') === 'initialize' && isset($result['result'])) {
                $hasInitialize = true;
                $sessionId = $this->createSession();
            }

            if (!isset($item['id'])) {
                continue;
            }

            $allNotifications = false;
            $responses[] = $result;
        }

        if ($allNotifications) {
            return $this->corsResponse(new Response('', 202));
        }

        $response = $this->json($responses);

        if ($hasInitialize && $sessionId !== null) {
            $response->headers->set('Mcp-Session-Id', $sessionId);
        }

        return $this->corsResponse($response);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function handleSingleRequest(array $content): Response
    {
        $id = $content['id'] ?? null;
        $method = $content['method'] ?? '';

        $result = $this->processSingleMessage($content);

        if ($id === null) {
            return $this->corsResponse(new Response('', 202));
        }

        $response = $this->json($result);

        if ($method === 'initialize' && isset($result['result'])) {
            $sessionId = $this->createSession();
            $response->headers->set('Mcp-Session-Id', $sessionId);
        }

        return $this->corsResponse($response);
    }

    /**
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function processSingleMessage(array $content): array
    {
        $id = $content['id'] ?? null;
        $method = $content['method'] ?? '';
        $params = $content['params'] ?? [];

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize(),
                'initialized', 'notifications/initialized' => null,
                'notifications/cancelled' => null,
                'tools/list' => $this->mcpProtocol->getToolsList(),
                'tools/call' => $this->handleToolCall($params),
                'ping' => new \stdClass(),
                default => throw new \InvalidArgumentException("Unknown method: {$method}"),
            };

            if ($result === null) {
                return ['_notification' => true];
            }

            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => $e->getMessage()],
            ];
        } catch (\Exception $e) {
            return [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32603, 'message' => 'Internal error: ' . $e->getMessage()],
            ];
        }
    }

    private function createSession(): string
    {
        $sessionId = bin2hex(random_bytes(16));

        $this->cache->get('mcp_session_' . $sessionId, function (ItemInterface $item): array {
            $item->expiresAfter(self::SESSION_TTL);

            return ['created' => time(), 'lastActivity' => time()];
        });

        return $sessionId;
    }

    private function validateSession(Request $request): ?Response
    {
        $content = json_decode($request->getContent(), true);

        if (is_array($content)) {
            if (isset($content['method']) && $content['method'] === 'initialize') {
                return null;
            }
            if (isset($content[0])) {
                foreach ($content as $item) {
                    if (is_array($item) && ($item['method'] ?? '') === 'initialize') {
                        return null;
                    }
                }
            }
        }

        $sessionId = $request->headers->get('Mcp-Session-Id');

        if ($sessionId === null) {
            return null;
        }

        $cacheKey = 'mcp_session_' . $sessionId;
        $notFound = new \stdClass();
        $session = $this->cache->get($cacheKey, static fn (): \stdClass => $notFound);

        if ($session === $notFound || !is_array($session)) {
            $this->cache->delete($cacheKey);

            return $this->corsResponse($this->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Session not found or expired. Please reinitialize.'],
            ], 404));
        }

        $this->cache->delete($cacheKey);
        $this->cache->get($cacheKey, function (ItemInterface $item) use ($session): array {
            $item->expiresAfter(self::SESSION_TTL);

            return ['created' => $session['created'], 'lastActivity' => time()];
        });

        return null;
    }

    private function handleMcpDelete(Request $request): Response
    {
        $sessionId = $request->headers->get('Mcp-Session-Id');

        if ($sessionId === null) {
            return $this->corsResponse($this->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Mcp-Session-Id header required'],
            ], 400));
        }

        $this->cache->delete('mcp_session_' . $sessionId);

        return $this->corsResponse(new Response('', 204));
    }

    private function handleMcpGet(Request $request): Response
    {
        $accept = $request->headers->get('Accept', '');
        if (!str_contains($accept, 'text/event-stream') && !str_contains($accept, '*/*') && $accept !== '') {
            return $this->corsResponse($this->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Accept header must include text/event-stream'],
            ], 406));
        }

        $response = new StreamedResponse(function (): void {
            while (ob_get_level()) {
                ob_end_clean();
            }

            $startTime = time();
            $maxDuration = 300;

            while ((time() - $startTime) < $maxDuration) {
                echo ": keep-alive\n\n";

                if (!@ob_flush()) {
                    @flush();
                }

                if (connection_aborted()) {
                    break;
                }

                sleep(15);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $this->corsResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'MIDO Macro Economic MCP Server',
                'version' => '2.0.0',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function handleToolCall(array $params): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        $content = match ($toolName) {
            'mido_macro_dashboard' => $this->dashboard->generate(
                $arguments['format'] ?? 'markdown',
                (bool) ($arguments['include_warnings'] ?? true),
            ),
            'mido_indicator' => $this->indicators->get(
                $arguments['indicator'] ?? '',
                (int) ($arguments['observations'] ?? 1),
            ),
            'mido_triggers' => $this->handleTriggers((bool) ($arguments['verbose'] ?? false)),
            'mido_crisis_dashboard' => $this->handleCrisisDashboard(
                $arguments['format'] ?? 'markdown',
                (bool) ($arguments['include_history'] ?? false),
            ),
            'mido_drawdown_calculator' => $this->marketData->getDrawdown(
                $arguments['index'] ?? 'IWDA.AS',
                (int) ($arguments['lookback_days'] ?? 252),
            ),
            'mido_momentum_rebalancing' => $this->momentum->generateReport(
                $arguments['format'] ?? 'markdown',
                isset($arguments['portfolio_value']) ? (float) $arguments['portfolio_value'] : null,
            ),
            default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
        };

        if (is_string($content)) {
            return [
                'content' => [['type' => 'text', 'text' => $content]],
            ];
        }

        return [
            'content' => [['type' => 'text', 'text' => json_encode($content, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE)]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function handleTriggers(bool $verbose): array
    {
        $result = $this->triggers->evaluateAll();

        if ($verbose) {
            $result['strategy_version'] = 'v8.0';
            $result['thresholds'] = [
                'T1_vix' => self::CRISIS_VIX_THRESHOLD,
                'T3_inflation' => 4.0,
                'T5_hy_spread' => 6.0,
                'T5_ig_spread' => 2.5,
            ];
        }

        return $result;
    }

    /**
     * @return string|array<string, mixed>
     */
    private function handleCrisisDashboard(string $format, bool $includeHistory): string|array
    {
        $signalCheck = $this->crisis->checkAllSignals();
        $drawdown = $signalCheck['drawdown'];

        $activeSignals = $signalCheck['active_signals'];
        $drawdownPct = $drawdown['drawdown_pct'] ?? 0.0;

        $deploymentLevels = $this->evaluateDeploymentLevels($activeSignals, $drawdownPct);

        $data = [
            'strategy_version' => 'v8.0',
            'timestamp' => (new \DateTime())->format('c'),
            'crisis_status' => [
                'triggered' => $signalCheck['crisis_triggered'],
                'status' => $signalCheck['crisis_triggered'] ? 'CRISIS ACTIEF' : 'NORMAAL',
                'active_signals' => $activeSignals,
                'required_signals' => 2,
            ],
            'signals' => $signalCheck['signals'],
            'market_status' => [
                'iwda_price' => $drawdown['current_price'],
                'iwda_52w_high' => $drawdown['high_52w'],
                'drawdown_pct' => $drawdown['drawdown_pct'],
                'phase' => $drawdown['phase'],
            ],
            'deployment' => $deploymentLevels,
            'thresholds_v8' => [
                'drawdown' => self::CRISIS_DRAWDOWN_THRESHOLD,
                'vix' => self::CRISIS_VIX_THRESHOLD,
                'vix_sustained_days' => self::CRISIS_VIX_SUSTAINED_DAYS,
                'credit_bps' => self::CRISIS_CREDIT_THRESHOLD,
            ],
        ];

        if ($includeHistory) {
            $data['historical_context'] = $this->crisis->getHistoricalCrises();
        }

        if ($format === 'json') {
            return $data;
        }

        return $this->formatCrisisMarkdown($data);
    }

    /**
     * @return list<array{level: int, condition: string, amount: string, source: string, active: bool}>
     */
    private function evaluateDeploymentLevels(int $activeSignals, float $drawdownPct): array
    {
        return [
            [
                'level' => 1,
                'condition' => '1/3 signalen + DD>15%',
                'amount' => '€25.000',
                'source' => 'XEON',
                'active' => $activeSignals >= 1 && $drawdownPct <= -15,
            ],
            [
                'level' => 2,
                'condition' => '2/3 signalen',
                'amount' => '€45.000',
                'source' => 'XEON + IBGS',
                'active' => $activeSignals >= 2,
            ],
            [
                'level' => 3,
                'condition' => '30d: 2/3 nog actief',
                'amount' => '€45.000',
                'source' => 'IBGS',
                'active' => false,
            ],
            [
                'level' => 4,
                'condition' => '30d: 3/3 actief (systeemcrisis)',
                'amount' => 'Restant IBGS',
                'source' => 'IBGS volledig',
                'active' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatCrisisMarkdown(array $data): string
    {
        $status = $data['crisis_status'];
        $signals = $data['signals'];
        $market = $data['market_status'];
        $deployment = $data['deployment'];

        $md = "# MIDO Crisis Dashboard (v8.0)\n\n";
        $md .= "_Generated: {$data['timestamp']}_\n\n";

        $statusLabel = $status['triggered'] ? '🚨 CRISIS ACTIEF' : '✅ NORMAAL';
        $md .= "## Crisis Status: {$statusLabel}\n\n";
        $md .= "**Active Signals:** {$status['active_signals']}/{$status['required_signals']} (2-of-3 rule)\n\n";

        $md .= "## Signals (v8.0 calibration)\n\n";
        $md .= "| Signal | Status | Value | Threshold |\n";
        $md .= "|--------|--------|-------|----------|\n";

        foreach ($signals as $signal) {
            $statusIcon = $signal['active'] ? '🔴 ACTIVE' : '🟢 OK';
            $value = $signal['value'] !== null ? (string) $signal['value'] : 'N/A';
            $threshold = (string) $signal['threshold'];
            $description = $signal['description'] ?? '';
            $md .= "| {$description} | {$statusIcon} | {$value} | {$threshold} |\n";
        }
        $md .= "\n";

        $md .= "## IWDA Market Status\n\n";
        $md .= "| Metric | Value |\n";
        $md .= "|--------|-------|\n";
        $md .= "| Current Price | €{$market['iwda_price']} |\n";
        $md .= "| 52-Week High | €{$market['iwda_52w_high']} |\n";
        $md .= "| Drawdown | {$market['drawdown_pct']}% |\n";
        $md .= "| Phase | {$market['phase']} |\n\n";

        $md .= "## Deployment Status (v8.0)\n\n";
        $md .= "| Niveau | Conditie | Bedrag | Bron | Status |\n";
        $md .= "|--------|----------|--------|------|--------|\n";
        foreach ($deployment as $level) {
            $statusStr = $level['active'] ? '🔴 ACTIEF' : '⚪ INACTIEF';
            $md .= "| {$level['level']} | {$level['condition']} | {$level['amount']} | {$level['source']} | {$statusStr} |\n";
        }
        $md .= "\n";

        if (isset($data['historical_context']) && $data['historical_context'] !== []) {
            $md .= "## Historical Context\n\n";
            $md .= "| Crisis | Year | Max Drawdown | Recovery |\n";
            $md .= "|--------|------|--------------|----------|\n";
            foreach ($data['historical_context'] as $crisis) {
                $recovery = isset($crisis['recovery_months']) ? "{$crisis['recovery_months']} months" : 'N/A';
                $md .= "| {$crisis['name']} | {$crisis['year']} | {$crisis['max_drawdown']}% | {$recovery} |\n";
            }
        }

        return $md;
    }

    private function corsResponse(Response $response): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, Mcp-Session-Id, Last-Event-ID');
        $response->headers->set('Access-Control-Expose-Headers', 'Mcp-Session-Id');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
