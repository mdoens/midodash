<?php

declare(strict_types=1);

namespace App\Service\Mcp;

class McpProtocolService
{
    /**
     * @return array{tools: list<array{name: string, description: string, inputSchema: array<string, mixed>}>}
     */
    public function getToolsList(): array
    {
        return [
            'tools' => [
                [
                    'name' => 'mido_macro_dashboard',
                    'description' => 'Complete macro-economic analysis with all indicators, triggers and recommendations for MIDO Holding portfolio monitoring (strategy v8.0).',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'format' => [
                                'type' => 'string',
                                'enum' => ['markdown', 'json'],
                                'default' => 'markdown',
                                'description' => 'Output format',
                            ],
                            'include_warnings' => [
                                'type' => 'boolean',
                                'default' => true,
                                'description' => 'Include attention points',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'mido_indicator',
                    'description' => 'Fetch a specific macro-economic indicator (e.g., VIX, ECB rate, gold, inflation).',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'indicator' => [
                                'type' => 'string',
                                'description' => 'Indicator name (vix, ecb_rate, gold, eu_inflation, cape, erp, etc.) or FRED series ID',
                            ],
                            'observations' => [
                                'type' => 'integer',
                                'default' => 1,
                                'minimum' => 1,
                                'maximum' => 100,
                                'description' => 'Number of historical data points',
                            ],
                        ],
                        'required' => ['indicator'],
                    ],
                ],
                [
                    'name' => 'mido_triggers',
                    'description' => 'Evaluate MIDO v8.0 strategy triggers (T1 Crash VIX>30, T3 Stagflation, T5 Credit Stress, T9 Recession) and warnings.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'verbose' => [
                                'type' => 'boolean',
                                'default' => false,
                                'description' => 'Include detailed explanation per trigger',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'mido_crisis_dashboard',
                    'description' => 'Real-time crisis monitoring with multi-signal detection (2/3 rule, v8.0 calibration: IWDA DD>20%, VIX>30 for 3d, HY>500bps). Includes 4-level deployment protocol.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'include_history' => [
                                'type' => 'boolean',
                                'default' => false,
                                'description' => 'Include historical crisis comparisons',
                            ],
                            'format' => [
                                'type' => 'string',
                                'enum' => ['markdown', 'json'],
                                'default' => 'markdown',
                                'description' => 'Output format',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'mido_drawdown_calculator',
                    'description' => 'Calculate MSCI World/IWDA drawdown vs 52-week high. Returns current price, 52w high, drawdown percentage, and phase classification.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'index' => [
                                'type' => 'string',
                                'default' => 'IWDA.AS',
                                'description' => 'Index/ETF ticker symbol (default: IWDA.AS for iShares MSCI World)',
                            ],
                            'lookback_days' => [
                                'type' => 'integer',
                                'default' => 252,
                                'minimum' => 30,
                                'maximum' => 504,
                                'description' => 'Days for 52-week high calculation (default: 252 trading days)',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'mido_momentum_rebalancing',
                    'description' => 'Monthly momentum-enhanced rebalancing check (v8.0). Calculates volatility-adjusted momentum scores, determines market regime (BULL/BEAR/BEAR_BEVESTIGD), and generates position-by-position advice with bandwidth adjustments and FBI constraints.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'format' => [
                                'type' => 'string',
                                'enum' => ['markdown', 'json'],
                                'default' => 'markdown',
                                'description' => 'Output format',
                            ],
                            'portfolio_value' => [
                                'type' => 'number',
                                'description' => 'Override portfolio value (default: 1800000 EUR)',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
