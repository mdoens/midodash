<?php

declare(strict_types=1);

namespace App\Service\Mcp;

use App\Service\CalculationService;
use App\Service\EurostatService;
use App\Service\FredApiService;
use App\Service\GoldPriceService;

class McpIndicatorService
{
    /** @var array<string, string> */
    private const ALIASES = [
        'vix' => 'VIXCLS',
        'ecb_rate' => 'ECBDFR',
        'ecb' => 'ECBDFR',
        'ecb_deposit' => 'ECBDFR',
        'ecb_refi' => 'ECBMRRFR',
        'fed_rate' => 'FEDFUNDS',
        'fed' => 'FEDFUNDS',
        'treasury_10y' => 'DGS10',
        'treasury_2y' => 'DGS2',
        'treasury_30y' => 'DGS30',
        'yield_curve' => 'T10Y2Y',
        'hy_spread' => 'BAMLH0A0HYM2',
        'ig_spread' => 'BAMLC0A4CBBB',
        'oil' => 'DCOILWTICO',
        'wti' => 'DCOILWTICO',
        'brent' => 'DCOILBRENTEU',
        'copper' => 'PCOPPUSDM',
        'eur_usd' => 'DEXUSEU',
        'eurusd' => 'DEXUSEU',
        'dxy' => 'DTWEXBGS',
        'dollar' => 'DTWEXBGS',
        'us_unemployment' => 'UNRATE',
        'unemployment' => 'UNRATE',
        'claims' => 'ICSA',
        'initial_claims' => 'ICSA',
        'sentiment' => 'UMCSENT',
        'consumer_sentiment' => 'UMCSENT',
        'm2' => 'M2SL',
        'fed_assets' => 'WALCL',
        'breakeven_5y' => 'T5YIE',
        'breakeven_10y' => 'T10YIE',
        'tips_10y' => 'DFII10',
        'real_rate_10y' => 'DFII10',
        'ted_spread' => 'TEDRATE',
        'stress_index' => 'STLFSI4',
    ];

    /** @var array<string, string> */
    private const CALCULATED = [
        'gold' => 'GOLDPRICE_ORG',
        'silver' => 'GOLDPRICE_ORG',
        'xau' => 'GOLDPRICE_ORG',
        'xag' => 'GOLDPRICE_ORG',
        'eu_inflation' => 'EUROSTAT_HICP',
        'inflation' => 'EUROSTAT_HICP',
        'hicp' => 'EUROSTAT_HICP',
        'eurozone_inflation' => 'EUROSTAT_HICP',
        'cape' => 'CALCULATED_CAPE',
        'erp' => 'CALCULATED_ERP',
        'gold_silver_ratio' => 'CALCULATED_GOLD_SILVER',
        'copper_gold_ratio' => 'CALCULATED_COPPER_GOLD',
        'real_ecb_rate' => 'CALCULATED_REAL_ECB',
        'real_fed_rate' => 'CALCULATED_REAL_FED',
        'recession_prob' => 'CALCULATED_RECESSION',
    ];

    public function __construct(
        private readonly FredApiService $fredApi,
        private readonly EurostatService $eurostat,
        private readonly CalculationService $calculations,
        private readonly GoldPriceService $goldPrice,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(string $indicator, int $observations = 1): array
    {
        $indicator = strtolower(trim($indicator));
        $indicator = str_replace(' ', '_', $indicator);

        if (isset(self::CALCULATED[$indicator])) {
            return $this->getCalculated($indicator);
        }

        $seriesId = self::ALIASES[$indicator] ?? strtoupper($indicator);

        $data = $this->fredApi->getSeriesObservations($seriesId, $observations);

        if ($data === null) {
            return [
                'error' => true,
                'message' => "No data found for indicator: {$indicator} (series: {$seriesId})",
            ];
        }

        return [
            'indicator' => $indicator,
            'series_id' => $seriesId,
            'observations' => $data,
            'latest' => $data[0] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getCalculated(string $indicator): array
    {
        return match ($indicator) {
            'gold', 'xau' => $this->getGoldPrice(),
            'silver', 'xag' => $this->getSilverPrice(),
            'cape' => [
                'indicator' => 'cape',
                'type' => 'calculated',
                'data' => $this->calculations->getCapeAssessment(),
            ],
            'erp' => [
                'indicator' => 'erp',
                'type' => 'calculated',
                'data' => $this->calculations->calculateEquityRiskPremium(),
            ],
            'gold_silver_ratio' => $this->getGoldSilverRatio(),
            'copper_gold_ratio' => $this->getCopperGoldRatio(),
            'real_ecb_rate' => [
                'indicator' => 'real_ecb_rate',
                'type' => 'calculated',
                'data' => $this->calculations->calculateRealEcbRate(),
            ],
            'real_fed_rate' => $this->getRealFedRate(),
            'recession_prob' => [
                'indicator' => 'recession_prob',
                'type' => 'calculated',
                'data' => $this->calculations->calculateRecessionProbability(),
            ],
            'eu_inflation', 'inflation', 'hicp', 'eurozone_inflation' => $this->getEuInflation($indicator),
            default => ['error' => true, 'message' => "Unknown calculated indicator: {$indicator}"],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function getEuInflation(string $indicator): array
    {
        $data = $this->eurostat->getEurozoneInflation(1);

        if ($data === null) {
            return ['error' => true, 'message' => 'Failed to fetch EU inflation data'];
        }

        return [
            'indicator' => $indicator,
            'source' => 'Eurostat HICP',
            'observations' => $data,
            'latest' => $data[0] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getGoldPrice(): array
    {
        $prices = $this->goldPrice->getPrices();

        if ($prices['gold'] === null) {
            return ['error' => true, 'message' => 'Failed to fetch gold price'];
        }

        return [
            'indicator' => 'gold',
            'source' => 'goldprice.org',
            'value' => $prices['gold'],
            'unit' => 'USD/oz',
            'change_pct' => $prices['gold_change_pct'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getSilverPrice(): array
    {
        $prices = $this->goldPrice->getPrices();

        if ($prices['silver'] === null) {
            return ['error' => true, 'message' => 'Failed to fetch silver price'];
        }

        return [
            'indicator' => 'silver',
            'source' => 'goldprice.org',
            'value' => $prices['silver'],
            'unit' => 'USD/oz',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getGoldSilverRatio(): array
    {
        $prices = $this->goldPrice->getPrices();

        return [
            'indicator' => 'gold_silver_ratio',
            'type' => 'calculated',
            'data' => [
                'value' => $prices['gold_silver_ratio'],
                'gold' => $prices['gold'],
                'silver' => $prices['silver'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getCopperGoldRatio(): array
    {
        $copper = $this->fredApi->getLatestValue('PCOPPUSDM');
        $prices = $this->goldPrice->getPrices();

        $copperValue = $copper['value'] ?? null;
        $goldValue = $prices['gold'];

        $ratio = ($copperValue !== null && $goldValue !== null && $goldValue > 0)
            ? round($copperValue / $goldValue * 1000, 4)
            : null;

        return [
            'indicator' => 'copper_gold_ratio',
            'type' => 'calculated',
            'data' => [
                'value' => $ratio,
                'copper' => $copperValue,
                'gold' => $goldValue,
                'note' => 'Copper ($/lb) / Gold ($/oz) * 1000',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getRealFedRate(): array
    {
        $fedFunds = $this->fredApi->getLatestValue('FEDFUNDS');
        $euInflation = $this->eurostat->getLatestInflation();

        $fedValue = $fedFunds['value'] ?? null;
        $inflationValue = $euInflation['value'] ?? null;

        $realRate = ($fedValue !== null && $inflationValue !== null)
            ? round($fedValue - $inflationValue, 2)
            : null;

        return [
            'indicator' => 'real_fed_rate',
            'type' => 'calculated',
            'data' => [
                'value' => $realRate,
                'nominal_rate' => $fedValue,
                'inflation' => $inflationValue,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAvailableIndicators(): array
    {
        return [
            'aliases' => array_keys(self::ALIASES),
            'calculated' => array_keys(self::CALCULATED),
            'note' => 'You can also use any FRED series ID directly (e.g., VIXCLS, DGS10)',
        ];
    }
}
