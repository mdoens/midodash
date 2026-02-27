# MIDO Macro Economic MCP Server — SKILL.md

## Server Info

- **Name:** MIDO Macro Economic MCP Server
- **Version:** 2.0.0
- **Strategy:** v8.0
- **Protocol:** MCP Streamable HTTP (2025-03-26)
- **Endpoint:** `https://mido.barcelona2.doens.nl/mcp`
- **Info:** `GET /mcp/info`

## Tools

### 1. `mido_macro_dashboard`

Full macro-economic dashboard with VIX, yield curves, credit spreads, gold, EUR/USD, inflation, CAPE, ERP, and recession probability.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `format` | string | `markdown` | Output format: `markdown` or `json` |
| `include_warnings` | bool | `true` | Include CAPE/ERP/currency warning flags |

**Use when:** You need a complete market overview for investment decisions.

---

### 2. `mido_indicator`

Fetch a single macro indicator (VIX, HY spread, ECB rate, yield curve, etc.) with historical observations.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `indicator` | string | *(required)* | FRED series ID (e.g. `VIXCLS`, `BAMLH0A0HYM2`, `DGS10`, `T10Y2Y`, `ECBDFR`, `DEXUSEU`) |
| `observations` | int | `1` | Number of most recent observations |

**Use when:** You need a specific data point, not the full dashboard.

---

### 3. `mido_triggers`

Evaluate all v8.0 investment triggers (T1-T9) and warnings against current market data.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `verbose` | bool | `false` | Include threshold values and strategy version |

**Triggers evaluated:**
- T1: VIX + drawdown
- T3: Inflation + GDP growth
- T5: HY + IG credit spreads
- T9: Yield curve inversion duration

**Use when:** You want to check if any strategy triggers are active.

---

### 4. `mido_crisis_dashboard`

Full crisis protocol dashboard with signal status (2-of-3 rule), IWDA drawdown, and deployment levels.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `format` | string | `markdown` | Output format: `markdown` or `json` |
| `include_history` | bool | `false` | Include historical crisis comparison |

**v8.0 Thresholds:**
- IWDA drawdown: -20%
- VIX sustained: 30 for 3 consecutive days
- HY spread: 500 bps

**Deployment levels:**
1. €25.000 from XEON (1/3 signals + DD>15%)
2. €45.000 from XEON+IBGS (2/3 signals)
3. €45.000 from IBGS (30d sustained)
4. Remainder IBGS (3/3 system crisis)

**Use when:** Market stress is elevated and you need to check crisis protocol activation.

---

### 5. `mido_drawdown_calculator`

Calculate current drawdown from 52-week high for any index/ETF.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `index` | string | `IWDA.AS` | Yahoo Finance ticker |
| `lookback_days` | int | `252` | Trading days to look back for high |

**Use when:** You need the exact drawdown percentage for a specific instrument.

---

### 6. `mido_momentum_rebalancing`

**v8.0 Drift x Momentum decision matrix.** Combines live portfolio drift (from IB + Saxo) with momentum scores to generate concrete rebalancing advice.

**Parameters:**
| Name | Type | Default | Description |
|------|------|---------|-------------|
| `format` | string | `markdown` | Output format: `markdown` or `json` |
| `portfolio_value` | float | *live* | Override portfolio value (default: live from brokers) |

**Decision rules (spec 4.2c-e):**
| Rule | Drift | Momentum | Action |
|------|-------|----------|--------|
| HARDCAP | >= 7% | *any* | REBALANCE_VOLLEDIG |
| LARGE_DRIFT_NEG | 5-7% | negative | REBALANCE_VOLLEDIG |
| LARGE_DRIFT_POS | 5-7% | positive | REBALANCE_GEDEELTELIJK (50%) |
| MODERATE_DRIFT_NEG | band-5% | negative | REBALANCE_VOLLEDIG |
| MODERATE_DRIFT_POS | band-5% | positive | WACHT |
| WITHIN_BAND | < band | *any* | GEEN_ACTIE |

**Output fields per position:**
- `drift_pct`: Current drift from target (%)
- `rule_applied`: Which decision rule fired
- `bandwidth_active`: Active bandwidth regime
- `bandwidth_exceeded`: Boolean
- `sell_amount` / `buy_amount`: Concrete EUR amounts
- `destination`: Where proceeds go
- `momentum_score`: Volatility-adjusted 12M momentum (skip most recent month)

**FBI constraint:** NT World and NT EM (FBI positions) are NEVER recommended for purchase — only hold or sell.

**Use when:** Monthly rebalancing review or when drift alerts fire.

---

## Regime Detection

The momentum tool detects market regime automatically:

| Regime | Condition | Bandwidth |
|--------|-----------|-----------|
| BULL | IWDA > SMA200 + VIX < 25 | normaal (±3%) |
| BEAR | IWDA < SMA200 or VIX > 25 | verruimd (±5%) |
| BEAR_BEVESTIGD | IWDA < SMA200 + VIX > 30 | maximaal (±7%) |

## Authentication

All MCP endpoints are **public** (no authentication required). CORS headers allow access from claude.ai and desktop apps.
