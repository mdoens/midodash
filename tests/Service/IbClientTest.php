<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DataBufferService;
use App\Service\IbClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class IbClientTest extends TestCase
{
    private IbClient $client;

    protected function setUp(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $dataBuffer = $this->createMock(DataBufferService::class);
        $this->client = new IbClient(
            $httpClient,
            new NullLogger(),
            $dataBuffer,
            'test_token',
            'test_query',
            sys_get_temp_dir(),
        );
    }

    public function testParsePositionsValidXml(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FlexQueryResponse>
    <FlexStatements>
        <FlexStatement>
            <OpenPositions>
                <OpenPosition symbol="IWDA" currency="EUR" assetCategory="STK" position="100" positionValue="8500" costBasisPrice="75.00"/>
                <OpenPosition symbol="SGLD" currency="GBP" assetCategory="STK" position="50" positionValue="3000" costBasisPrice="55.00"/>
            </OpenPositions>
        </FlexStatement>
    </FlexStatements>
</FlexQueryResponse>
XML;

        $positions = $this->client->parsePositions($xml);

        $this->assertCount(2, $positions);

        $this->assertSame('IWDA', $positions[0]['symbol']);
        $this->assertSame('EUR', $positions[0]['currency']);
        $this->assertSame(100.0, $positions[0]['amount']);
        $this->assertSame(8500.0, $positions[0]['value']);
        $this->assertSame(7500.0, $positions[0]['cost']);
        $this->assertSame(75.0, $positions[0]['cost_price']);
        $this->assertSame(85.0, $positions[0]['current_price']);
        $this->assertEqualsWithDelta(13.3, $positions[0]['pnl_pct'], 0.1);
        $this->assertSame(1000.0, $positions[0]['pnl']);

        $this->assertSame('SGLD', $positions[1]['symbol']);
    }

    public function testParsePositionsInvalidXml(): void
    {
        $positions = $this->client->parsePositions('not xml at all');

        $this->assertSame([], $positions);
    }

    public function testParsePositionsZeroQuantity(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FlexQueryResponse>
    <FlexStatements>
        <FlexStatement>
            <OpenPositions>
                <OpenPosition symbol="TEST" currency="USD" assetCategory="STK" position="0" positionValue="0" costBasisPrice="10.00"/>
            </OpenPositions>
        </FlexStatement>
    </FlexStatements>
</FlexQueryResponse>
XML;

        $positions = $this->client->parsePositions($xml);

        $this->assertCount(1, $positions);
        $this->assertSame(0, $positions[0]['current_price']);
        $this->assertSame(0.0, $positions[0]['cost']);
    }

    public function testGetCashReportFromCacheFile(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<FlexQueryResponse>
    <FlexStatements>
        <FlexStatement>
            <CashReport>
                <CashReportCurrency deposits="50000" commissions="-123.45" endingCash="12345.67"/>
            </CashReport>
        </FlexStatement>
    </FlexStatements>
</FlexQueryResponse>
XML;

        $cacheFile = $this->client->getCacheFile();
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($cacheFile, $xml);

        $report = $this->client->getCashReport();

        $this->assertEqualsWithDelta(50000.0, $report['deposits'], 0.01);
        $this->assertEqualsWithDelta(-123.45, $report['commissions'], 0.01);
        $this->assertEqualsWithDelta(12345.67, $report['ending_cash'], 0.01);

        @unlink($cacheFile);
    }

    public function testGetCashReportNoCacheFile(): void
    {
        $cacheFile = $this->client->getCacheFile();
        @unlink($cacheFile);

        $report = $this->client->getCashReport();

        $this->assertSame([], $report);
    }
}
