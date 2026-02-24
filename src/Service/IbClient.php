<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class IbClient
{
    private readonly string $token;
    private readonly string $queryId;
    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->token = $_ENV['IB_TOKEN'];
        $this->queryId = $_ENV['IB_QUERY_ID'];
        $this->baseUrl = 'https://gdcdyn.interactivebrokers.com/Universal/servlet/FlexStatementService';
    }

    public function fetchStatement(): ?string
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . '.SendRequest', [
            'query' => ['t' => $this->token, 'q' => $this->queryId, 'v' => 3],
        ]);

        $xml = simplexml_load_string($response->getContent());
        if ($xml === false || (string) $xml->Status !== 'Success') {
            return null;
        }

        $refCode = (string) $xml->ReferenceCode;

        for ($i = 0; $i < 10; $i++) {
            sleep(5);
            $result = $this->httpClient->request('GET', $this->baseUrl . '.GetStatement', [
                'query' => ['q' => $refCode, 't' => $this->token, 'v' => 3],
            ]);

            $content = $result->getContent();
            $check = @simplexml_load_string($content);

            if ($check !== false && isset($check->Status) && (string) $check->Status !== 'Success') {
                continue;
            }

            return $content;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPositions(): array
    {
        $cacheFile = dirname(__DIR__, 2) . '/var/ib_statement.xml';
        $content = null;

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $content = file_get_contents($cacheFile);
            if ($content === false) {
                $content = null;
            }
        } else {
            $content = $this->fetchStatement();
            if ($content !== null) {
                $dir = dirname($cacheFile);
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                file_put_contents($cacheFile, $content);
            }
        }

        if ($content === null) {
            return [];
        }

        return $this->parsePositions($content);
    }

    /**
     * @return array<string, float>
     */
    public function getCashReport(): array
    {
        $cacheFile = dirname(__DIR__, 2) . '/var/ib_statement.xml';
        if (!file_exists($cacheFile)) {
            return [];
        }

        $rawContent = file_get_contents($cacheFile);
        if ($rawContent === false) {
            return [];
        }

        $xml = simplexml_load_string($rawContent);
        if ($xml === false) {
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parsePositions(string $xmlContent): array
    {
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
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
