<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class IbClient
{
    private readonly string $cacheFile;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $ibToken,
        private readonly string $ibQueryId,
        private readonly string $projectDir,
    ) {
        $this->cacheFile = $this->projectDir . '/var/ib_statement.xml';
    }

    public function fetchStatement(): ?string
    {
        $baseUrl = 'https://gdcdyn.interactivebrokers.com/Universal/servlet/FlexStatementService';

        try {
            $response = $this->httpClient->request('GET', $baseUrl . '.SendRequest', [
                'query' => ['t' => $this->ibToken, 'q' => $this->ibQueryId, 'v' => 3],
            ]);

            $xml = simplexml_load_string($response->getContent());
            if ($xml === false || (string) $xml->Status !== 'Success') {
                $this->logger->warning('IB Flex SendRequest failed', [
                    'status' => $xml !== false ? (string) $xml->Status : 'parse_error',
                ]);

                return null;
            }

            $refCode = (string) $xml->ReferenceCode;
            $this->logger->info('IB Flex statement requested', ['ref' => $refCode]);

            for ($i = 0; $i < 10; $i++) {
                sleep(5);
                $result = $this->httpClient->request('GET', $baseUrl . '.GetStatement', [
                    'query' => ['q' => $refCode, 't' => $this->ibToken, 'v' => 3],
                ]);

                $content = $result->getContent();
                $check = @simplexml_load_string($content);

                if ($check !== false && isset($check->Status) && (string) $check->Status !== 'Success') {
                    continue;
                }

                $this->logger->info('IB Flex statement received', ['attempt' => $i + 1]);

                return $content;
            }

            $this->logger->error('IB Flex statement timeout after 10 attempts');
        } catch (\Throwable $e) {
            $this->logger->error('IB Flex API error', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPositions(): array
    {
        $content = null;

        if (file_exists($this->cacheFile) && (time() - filemtime($this->cacheFile)) < 3600) {
            $content = file_get_contents($this->cacheFile);
            if ($content === false) {
                $content = null;
            }
        } else {
            $content = $this->fetchStatement();
            if ($content !== null) {
                $dir = dirname($this->cacheFile);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($this->cacheFile, $content);
            }
        }

        if ($content === null) {
            $this->logger->warning('IB positions unavailable: no cached or fresh data');

            return [];
        }

        return $this->parsePositions($content);
    }

    /**
     * @return array<string, float>
     */
    public function getCashReport(): array
    {
        if (!file_exists($this->cacheFile)) {
            return [];
        }

        $rawContent = file_get_contents($this->cacheFile);
        if ($rawContent === false) {
            return [];
        }

        $xml = simplexml_load_string($rawContent);
        if ($xml === false) {
            $this->logger->warning('IB cash report: failed to parse XML');

            return [];
        }

        $statement = $xml->FlexStatements->FlexStatement;
        $cash = $statement->CashReport->CashReportCurrency[0] ?? null;

        if ($cash === null) {
            return [];
        }

        return [
            'deposits' => (float) $cash['deposits'],
            'commissions' => (float) $cash['commissions'],
            'ending_cash' => (float) $cash['endingCash'],
        ];
    }

    public function getCacheFile(): string
    {
        return $this->cacheFile;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parsePositions(string $xmlContent): array
    {
        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false) {
            $this->logger->warning('IB parsePositions: invalid XML');

            return [];
        }

        $statement = $xml->FlexStatements->FlexStatement;

        $positions = [];
        foreach ($statement->OpenPositions->OpenPosition as $pos) {
            $symbol = (string) $pos['symbol'];
            $qty = (float) $pos['position'];
            $value = (float) $pos['positionValue'];
            $costPrice = (float) $pos['costBasisPrice'];
            $cost = $costPrice * $qty;
            $pnlPct = $cost > 0 ? (($value - $cost) / $cost) * 100 : 0;

            $positions[] = [
                'symbol' => $symbol,
                'description' => $symbol,
                'currency' => (string) $pos['currency'],
                'type' => (string) $pos['assetCategory'],
                'amount' => $qty,
                'value' => $value,
                'cost' => $cost,
                'cost_price' => $costPrice,
                'current_price' => $qty > 0 ? $value / $qty : 0,
                'pnl' => $value - $cost,
                'pnl_pct' => round($pnlPct, 1),
            ];
        }

        return $positions;
    }
}
