<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class IbClient
{
    private string $token;
    private string $queryId;
    private string $baseUrl = 'https://gdcdyn.interactivebrokers.com/Universal/servlet/FlexStatementService';

    public function __construct(private HttpClientInterface $httpClient)
    {
        $this->token = $_ENV['IB_TOKEN'];
        $this->queryId = $_ENV['IB_QUERY_ID'];
    }

    public function fetchStatement(): ?string
    {
        // Step 1: Send request
        $response = $this->httpClient->request('GET', $this->baseUrl . '.SendRequest', [
            'query' => ['t' => $this->token, 'q' => $this->queryId, 'v' => 3],
        ]);

        $xml = simplexml_load_string($response->getContent());
        if (!$xml || (string)$xml->Status !== 'Success') return null;

        $refCode = (string)$xml->ReferenceCode;

        // Step 2: Poll for result
        for ($i = 0; $i < 10; $i++) {
            sleep(5);
            $result = $this->httpClient->request('GET', $this->baseUrl . '.GetStatement', [
                'query' => ['q' => $refCode, 't' => $this->token, 'v' => 3],
            ]);

            $content = $result->getContent();
            $check = @simplexml_load_string($content);

            if ($check && isset($check->Status) && (string)$check->Status !== 'Success') {
                continue;
            }

            return $content;
        }

        return null;
    }

    public function getPositions(): array
    {
        // Try cached statement first (max 1 hour old)
        $cacheFile = dirname(__DIR__, 2) . '/var/ib_statement.xml';
        $content = null;

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
            $content = file_get_contents($cacheFile);
        } else {
            $content = $this->fetchStatement();
            if ($content) {
                $dir = dirname($cacheFile);
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                file_put_contents($cacheFile, $content);
            }
        }

        if (!$content) return [];

        return $this->parsePositions($content);
    }

    public function getCashReport(): array
    {
        $cacheFile = dirname(__DIR__, 2) . '/var/ib_statement.xml';
        if (!file_exists($cacheFile)) return [];

        $xml = simplexml_load_string(file_get_contents($cacheFile));
        $statement = $xml->FlexStatements->FlexStatement;
        $cash = $statement->CashReport->CashReportCurrency[0] ?? null;

        if (!$cash) return [];

        return [
            'deposits' => (float)$cash['deposits'],
            'commissions' => (float)$cash['commissions'],
            'ending_cash' => (float)$cash['endingCash'],
        ];
    }

    private function parsePositions(string $xmlContent): array
    {
        $xml = simplexml_load_string($xmlContent);
        $statement = $xml->FlexStatements->FlexStatement;

        $positions = [];
        foreach ($statement->OpenPositions->OpenPosition as $pos) {
            $symbol = (string)$pos['symbol'];
            $qty = (float)$pos['position'];
            $value = (float)$pos['positionValue'];
            $costPrice = (float)$pos['costBasisPrice'];
            $cost = $costPrice * $qty;
            $pnlPct = $cost > 0 ? (($value - $cost) / $cost) * 100 : 0;

            $positions[] = [
                'symbol' => $symbol,
                'description' => $symbol,
                'currency' => (string)$pos['currency'],
                'type' => (string)$pos['assetCategory'],
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
