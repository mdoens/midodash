<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\DataBufferService;
use App\Service\SaxoClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SaxoClientTest extends TestCase
{
    private SaxoClient $client;

    protected function setUp(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $dataBuffer = $this->createMock(DataBufferService::class);
        $this->client = new SaxoClient(
            $httpClient,
            new NullLogger(),
            $dataBuffer,
            'test_key',
            'test_secret',
            'https://example.com/callback',
            'https://example.com/auth',
            'https://example.com/token',
            'https://example.com/api',
            sys_get_temp_dir(),
        );
    }

    public function testParsePositionsValidData(): void
    {
        $data = [
            'Data' => [
                [
                    'DisplayAndFormat' => [
                        'Symbol' => 'AAPL:xnas',
                        'Description' => 'Apple Inc.',
                        'Currency' => 'USD',
                    ],
                    'PositionBase' => [
                        'AssetType' => 'Stock',
                        'Amount' => 10,
                        'OpenPrice' => 150.0,
                    ],
                    'PositionView' => [
                        'CurrentPrice' => 175.0,
                        'ProfitLossOnTrade' => 250.0,
                        'ProfitLossOnTradeInBaseCurrency' => 230.0,
                        'ExposureInBaseCurrency' => 1750.0,
                    ],
                ],
                [
                    'DisplayAndFormat' => [
                        'Symbol' => 'MSFT:xnas',
                        'Description' => 'Microsoft Corp.',
                        'Currency' => 'USD',
                    ],
                    'PositionBase' => [
                        'AssetType' => 'Stock',
                        'Amount' => 5,
                        'OpenPrice' => 300.0,
                    ],
                    'PositionView' => [
                        'CurrentPrice' => 320.0,
                        'ProfitLossOnTrade' => 100.0,
                        'ProfitLossOnTradeInBaseCurrency' => 92.0,
                        'ExposureInBaseCurrency' => 1600.0,
                    ],
                ],
            ],
        ];

        $positions = $this->client->parsePositions($data);

        $this->assertCount(2, $positions);

        $this->assertSame('AAPL:xnas', $positions[0]['symbol']);
        $this->assertSame('Apple Inc.', $positions[0]['description']);
        $this->assertSame('USD', $positions[0]['currency']);
        $this->assertSame('Stock', $positions[0]['type']);
        $this->assertSame(10, $positions[0]['amount']);
        $this->assertSame(150.0, $positions[0]['open_price']);
        $this->assertSame(175.0, $positions[0]['current_price']);
        $this->assertSame(250.0, $positions[0]['pnl']);
        $this->assertSame(230.0, $positions[0]['pnl_base']);
        $this->assertSame(1750.0, $positions[0]['exposure']);

        $this->assertSame('MSFT:xnas', $positions[1]['symbol']);
    }

    public function testParsePositionsEmptyData(): void
    {
        $positions = $this->client->parsePositions([]);

        $this->assertSame([], $positions);
    }

    public function testParsePositionsNoDataKey(): void
    {
        $positions = $this->client->parsePositions(['something' => 'else']);

        $this->assertSame([], $positions);
    }

    public function testParsePositionsMissingFields(): void
    {
        $data = [
            'Data' => [
                [
                    'DisplayAndFormat' => [],
                    'PositionBase' => [],
                    'PositionView' => [],
                ],
            ],
        ];

        $positions = $this->client->parsePositions($data);

        $this->assertCount(1, $positions);
        $this->assertSame('?', $positions[0]['symbol']);
        $this->assertSame('', $positions[0]['description']);
        $this->assertSame('EUR', $positions[0]['currency']);
        $this->assertSame(0, $positions[0]['amount']);
    }

    public function testGetAuthUrl(): void
    {
        $url = $this->client->getAuthUrl('test_state_123');

        $this->assertStringContainsString('https://example.com/auth', $url);
        $this->assertStringContainsString('client_id=test_key', $url);
        $this->assertStringContainsString('state=test_state_123', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
    }

    public function testParseClosedPositionsValidData(): void
    {
        $data = [
            'Data' => [
                [
                    'ClosedPosition' => [
                        'OpenPrice' => 45.50,
                        'ClosePrice' => 48.20,
                        'ClosedProfitLoss' => 270.0,
                        'Amount' => 100,
                        'AssetType' => 'Stock',
                        'ExecutionTimeOpen' => '2025-01-15T09:00:00Z',
                        'ExecutionTimeClose' => '2025-06-20T14:30:00Z',
                    ],
                    'DisplayAndFormat' => [
                        'Symbol' => 'ZPRX:xams',
                        'Description' => 'SPDR MSCI Europe Small Cap',
                    ],
                ],
                [
                    'ClosedPosition' => [
                        'OpenPrice' => 100.0,
                        'ClosePrice' => 95.0,
                        'ClosedProfitLoss' => -500.0,
                        'Amount' => 100,
                        'AssetType' => 'EtfIndex',
                        'ExecutionTimeOpen' => '2025-03-01T10:00:00Z',
                        'ExecutionTimeClose' => '2025-07-15T11:00:00Z',
                    ],
                    'DisplayAndFormat' => [
                        'Symbol' => 'AVEM:xams',
                        'Description' => 'Avantis EM Equity',
                    ],
                ],
            ],
        ];

        $positions = $this->client->parseClosedPositions($data);

        $this->assertCount(2, $positions);

        $this->assertSame('ZPRX:xams', $positions[0]['symbol']);
        $this->assertSame('SPDR MSCI Europe Small Cap', $positions[0]['description']);
        $this->assertSame(45.50, $positions[0]['open_price']);
        $this->assertSame(48.20, $positions[0]['close_price']);
        $this->assertSame(270.0, $positions[0]['profit_loss']);
        $this->assertSame(100.0, $positions[0]['amount']);
        $this->assertSame('Stock', $positions[0]['asset_type']);
        $this->assertSame('2025-01-15T09:00:00Z', $positions[0]['open_time']);
        $this->assertSame('2025-06-20T14:30:00Z', $positions[0]['close_time']);

        $this->assertSame('AVEM:xams', $positions[1]['symbol']);
        $this->assertSame(-500.0, $positions[1]['profit_loss']);
    }

    public function testParseClosedPositionsEmptyData(): void
    {
        $positions = $this->client->parseClosedPositions([]);

        $this->assertSame([], $positions);
    }

    public function testParseClosedPositionsMissingFields(): void
    {
        $data = [
            'Data' => [
                [
                    'ClosedPosition' => [],
                    'DisplayAndFormat' => [],
                ],
            ],
        ];

        $positions = $this->client->parseClosedPositions($data);

        $this->assertCount(1, $positions);
        $this->assertSame('?', $positions[0]['symbol']);
        $this->assertSame(0.0, $positions[0]['open_price']);
        $this->assertSame(0.0, $positions[0]['profit_loss']);
    }

    public function testParsePerformanceMetricsValidData(): void
    {
        $data = [
            'TimeWeightedPerformance' => [
                'TimeWeightedReturn' => 0.1245,
                'TimeWeightedReturnAnnualized' => 0.089,
                'SharpeRatio' => 1.35,
                'SortinoRatio' => 1.82,
            ],
            'BalancePerformance' => [
                'MaxDrawDown' => -0.085,
                'TotalReturnFraction' => 0.125,
            ],
            'AccountSummary' => [
                'TotalDeposited' => 500000.0,
                'TotalWithdrawn' => 10000.0,
                'TotalProfitLoss' => 62500.0,
            ],
        ];

        $metrics = $this->client->parsePerformanceMetrics($data);

        $this->assertSame(0.1245, $metrics['twr']);
        $this->assertSame(0.089, $metrics['twr_annualized']);
        $this->assertSame(1.35, $metrics['sharpe_ratio']);
        $this->assertSame(1.82, $metrics['sortino_ratio']);
        $this->assertSame(-0.085, $metrics['max_drawdown']);
        $this->assertSame(0.125, $metrics['total_return_fraction']);
        $this->assertSame(500000.0, $metrics['total_deposited']);
        $this->assertSame(10000.0, $metrics['total_withdrawn']);
        $this->assertSame(62500.0, $metrics['total_profit_loss']);
    }

    public function testParsePerformanceMetricsEmptyData(): void
    {
        $metrics = $this->client->parsePerformanceMetrics([]);

        $this->assertSame(0.0, $metrics['twr']);
        $this->assertSame(0.0, $metrics['sharpe_ratio']);
        $this->assertSame(0.0, $metrics['max_drawdown']);
        $this->assertSame(0.0, $metrics['total_deposited']);
    }

    public function testParseCurrencyExposureValidData(): void
    {
        $data = [
            'Data' => [
                [
                    'Currency' => 'EUR',
                    'Amount' => 250000.0,
                    'AmountInCalculationEntityCurrency' => 250000.0,
                ],
                [
                    'Currency' => 'USD',
                    'Amount' => 50000.0,
                    'AmountInCalculationEntityCurrency' => 46500.0,
                ],
            ],
        ];

        $exposure = $this->client->parseCurrencyExposure($data);

        $this->assertCount(2, $exposure);
        $this->assertSame('EUR', $exposure[0]['currency']);
        $this->assertSame(250000.0, $exposure[0]['amount']);
        $this->assertSame(250000.0, $exposure[0]['amount_base']);
        $this->assertSame('USD', $exposure[1]['currency']);
        $this->assertSame(50000.0, $exposure[1]['amount']);
        $this->assertSame(46500.0, $exposure[1]['amount_base']);
    }

    public function testParseCurrencyExposureEmptyData(): void
    {
        $exposure = $this->client->parseCurrencyExposure([]);

        $this->assertSame([], $exposure);
    }
}
