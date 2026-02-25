<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MomentumService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MomentumServiceTest extends TestCase
{
    private MomentumService $service;

    protected function setUp(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $this->service = new MomentumService(
            $httpClient,
            new NullLogger(),
            sys_get_temp_dir(),
        );
    }

    public function testMomentumScoreWithPositiveReturns(): void
    {
        $returns = [0.02, 0.03, 0.01, 0.04, 0.02, 0.03, 0.01, 0.02, 0.03, 0.02, 0.01, 0.03, 0.02];
        $score = $this->service->momentumScore($returns);

        $this->assertGreaterThan(0, $score);
    }

    public function testMomentumScoreWithNegativeReturns(): void
    {
        $returns = [-0.02, -0.03, -0.01, -0.04, -0.02, -0.03, -0.01, -0.02, -0.03, -0.02, -0.01, -0.03, -0.02];
        $score = $this->service->momentumScore($returns);

        $this->assertSame(-999.0, $score);
    }

    public function testMomentumScoreWithInsufficientData(): void
    {
        $returns = [0.01, 0.02, 0.03];
        $score = $this->service->momentumScore($returns);

        $this->assertSame(-999.0, $score);
    }

    public function testMomentumScoreExactly7Returns(): void
    {
        $returns = [0.02, 0.03, 0.01, 0.04, 0.02, 0.03, 0.01];
        $score = $this->service->momentumScore($returns);

        $this->assertIsFloat($score);
    }

    public function testMonthlyReturnsCalculation(): void
    {
        $prices = [
            '2025-01' => 100.0,
            '2025-02' => 110.0,
            '2025-03' => 105.0,
        ];

        $returns = $this->service->monthlyReturns($prices);

        $this->assertCount(2, $returns);
        $this->assertEqualsWithDelta(0.1, $returns[0], 0.0001);
        $this->assertEqualsWithDelta(-0.04545, $returns[1], 0.001);
    }

    public function testMonthlyReturnsSkipsZeroPrices(): void
    {
        $prices = [
            '2025-01' => 0.0,
            '2025-02' => 100.0,
            '2025-03' => 110.0,
        ];

        $returns = $this->service->monthlyReturns($prices);

        $this->assertCount(1, $returns);
        $this->assertEqualsWithDelta(0.1, $returns[0], 0.0001);
    }

    public function testXeonIsMarkedAsCash(): void
    {
        $tickers = MomentumService::TICKERS;

        $this->assertTrue($tickers['XEON.DE']['cash']);
        $this->assertFalse($tickers['XEON.DE']['equity']);
    }

    public function testXeonExcludedFromPositiveFilter(): void
    {
        $scores = [
            'XEON.DE' => ['score' => 0.5, 'cash' => true, 'equity' => false],
            'IWDA.AS' => ['score' => 0.3, 'cash' => false, 'equity' => true],
        ];

        $positief = array_filter($scores, fn(array $s): bool => $s['score'] > 0 && !$s['cash']);

        $this->assertArrayNotHasKey('XEON.DE', $positief);
        $this->assertArrayHasKey('IWDA.AS', $positief);
    }

    public function testEquityConstraintMaxOneEquity(): void
    {
        $positief = [
            'IWDA.AS' => ['score' => 0.8, 'equity' => true, 'cash' => false],
            'AVWC.DE' => ['score' => 0.6, 'equity' => true, 'cash' => false],
            'SGLD.L'  => ['score' => 0.4, 'equity' => false, 'cash' => false],
        ];

        $top2 = [];
        $seenEquity = false;

        foreach ($positief as $ticker => $info) {
            if ($info['equity']) {
                if ($seenEquity) {
                    continue;
                }
                $seenEquity = true;
            }
            $top2[] = $ticker;
            if (count($top2) >= 2) {
                break;
            }
        }

        $this->assertCount(2, $top2);
        $this->assertSame('IWDA.AS', $top2[0]);
        $this->assertSame('SGLD.L', $top2[1]);
    }

    public function testTop2SelectionWithSinglePositive(): void
    {
        $positief = [
            'SGLD.L' => ['score' => 0.3, 'equity' => false, 'cash' => false],
        ];

        $top2 = [];
        $seenEquity = false;

        foreach ($positief as $ticker => $info) {
            if ($info['equity']) {
                if ($seenEquity) {
                    continue;
                }
                $seenEquity = true;
            }
            $top2[] = $ticker;
            if (count($top2) >= 2) {
                break;
            }
        }

        $this->assertCount(1, $top2);
    }
}
