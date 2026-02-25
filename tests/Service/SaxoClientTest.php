<?php

declare(strict_types=1);

namespace App\Tests\Service;

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
        $this->client = new SaxoClient(
            $httpClient,
            new NullLogger(),
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
}
