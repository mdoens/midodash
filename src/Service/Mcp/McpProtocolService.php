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
                // --- MCP v2.0 tools ---
                [
                    'name' => 'mido_portfolio_snapshot',
                    'description' => 'Complete portfolio overview: live positions with prices, weights, P/L, drift vs target, asset class breakdown, platform split, dry powder. Uses live Saxo/IB data with DataBuffer fallback.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
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
                    'name' => 'mido_cash_overview',
                    'description' => 'Cash balances per platform, open orders, dry powder breakdown (cash + XEON + IBGS), deployable capital by timeline.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
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
                    'name' => 'mido_currency_exposure',
                    'description' => 'FX exposure breakdown: currency weights, EUR vs non-EUR split, comparison with geographic targets.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
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
                    'name' => 'mido_performance_history',
                    'description' => 'Portfolio value over time with TWR calculation, peak/trough, regime history. Uses daily portfolio snapshots.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'period' => [
                                'type' => 'string',
                                'enum' => ['1m', '3m', '6m', '1y', 'ytd', 'all'],
                                'default' => '1y',
                                'description' => 'Lookback period',
                            ],
                            'format' => [
                                'type' => 'string',
                                'enum' => ['markdown', 'json'],
                                'default' => 'markdown',
                                'description' => 'Output format',
                            ],
                            'include_benchmark' => [
                                'type' => 'boolean',
                                'default' => false,
                                'description' => 'Include IWDA benchmark comparison (when available)',
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'mido_attribution',
                    'description' => 'Return attribution: contribution per position, asset class, platform, or geography. Shows which positions drove portfolio returns.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'period' => [
                                'type' => 'string',
                                'enum' => ['1m', '3m', '6m', '1y', 'ytd', 'all'],
                                'default' => '3m',
                                'description' => 'Attribution period',
                            ],
                            'group_by' => [
                                'type' => 'string',
                                'enum' => ['position', 'asset_class', 'platform', 'geography'],
                                'default' => 'position',
                                'description' => 'Grouping dimension',
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
                    'name' => 'mido_risk_metrics',
                    'description' => 'Portfolio risk analytics: volatility, max drawdown, Sharpe ratio, Sortino ratio, VaR/CVaR (95%). Cross-checked with Saxo performance metrics when available.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'period' => [
                                'type' => 'string',
                                'enum' => ['1m', '3m', '6m', '1y', 'ytd', 'all'],
                                'default' => '1y',
                                'description' => 'Calculation period',
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
                    'name' => 'mido_stress_test',
                    'description' => 'Portfolio stress testing with preset scenarios (crash -20%/-40%, rate hike +200bps, EUR/USD parity, stagflation) or custom shocks. Shows impact per position and whether crisis protocol would activate.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'scenario' => [
                                'type' => 'string',
                                'enum' => ['crash_20', 'crash_40', 'rate_hike', 'eur_usd_parity', 'stagflation', 'custom'],
                                'default' => 'crash_20',
                                'description' => 'Stress scenario to apply',
                            ],
                            'custom_shocks' => [
                                'type' => 'string',
                                'description' => 'JSON object with custom shocks per asset class or position, e.g. equity:-30, fixed_income:5. Only used when scenario=custom.',
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
                    'name' => 'mido_cost_analysis',
                    'description' => 'Transaction costs per platform, TER per position, weighted average TER, total cost ratio. Shows the true all-in cost of the portfolio.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'period' => [
                                'type' => 'string',
                                'enum' => ['1m', '3m', '6m', '1y', 'all'],
                                'default' => '1y',
                                'description' => 'Period for transaction cost analysis',
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
                    'name' => 'mido_fundamentals',
                    'description' => 'Fund/ETF fundamentals from Yahoo Finance: P/E, dividend yield, expense ratio, AUM, beta, 52-week range. Works for any ticker symbol.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'ticker' => [
                                'type' => 'string',
                                'description' => 'Yahoo Finance ticker symbol (e.g. AVWC.DE, IWDA.AS, XEON.DE)',
                            ],
                            'format' => [
                                'type' => 'string',
                                'enum' => ['markdown', 'json'],
                                'default' => 'markdown',
                                'description' => 'Output format',
                            ],
                        ],
                        'required' => ['ticker'],
                    ],
                ],
                [
                    'name' => 'mido_fund_lookthrough',
                    'description' => 'ETF/fund look-through: top holdings, sector breakdown, geography per position. Uses Yahoo Finance for IBKR ETFs and static config for Northern Trust funds.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'position' => [
                                'type' => 'string',
                                'description' => 'Specific position name (e.g. AVWC, NTWC). Omit for all positions.',
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
                    'name' => 'mido_rebalance_advisor',
                    'description' => 'Concrete buy/sell orders to reach target allocation. Shows delta per position, platform, FBI warnings for Saxo funds. Optionally include extra cash to deploy.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'cash_to_deploy' => [
                                'type' => 'number',
                                'description' => 'Extra cash to deploy (EUR). Added to portfolio value for target calculation.',
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
                    'name' => 'mido_scenario_planner',
                    'description' => 'Long-term portfolio projection with deterministic path and Monte Carlo simulation (1000 runs). Shows nominal/real end values, percentiles, and milestone targets.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'years' => [
                                'type' => 'integer',
                                'default' => 10,
                                'minimum' => 1,
                                'maximum' => 50,
                                'description' => 'Investment horizon in years',
                            ],
                            'expected_return_pct' => [
                                'type' => 'number',
                                'default' => 7.0,
                                'description' => 'Expected annual return percentage',
                            ],
                            'monthly_contribution' => [
                                'type' => 'number',
                                'default' => 0,
                                'description' => 'Monthly contribution in EUR',
                            ],
                            'inflation_pct' => [
                                'type' => 'number',
                                'default' => 2.0,
                                'description' => 'Annual inflation rate percentage',
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
            ],
        ];
    }
}
