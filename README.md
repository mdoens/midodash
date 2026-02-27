# MidoDash

**Investment portfolio dashboard for MIDO Holding B.V.**

![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Symfony 8](https://img.shields.io/badge/Symfony-8-000000?logo=symfony&logoColor=white)
![Docker](https://img.shields.io/badge/Docker-php%3A8.4--apache-2496ED?logo=docker&logoColor=white)
![License](https://img.shields.io/badge/License-Proprietary-red)

MidoDash is a real-time portfolio monitoring and rebalancing dashboard that aggregates positions from Interactive Brokers and Saxo Bank, overlays macro-economic signals from FRED/Eurostat/Yahoo Finance, and implements a systematic crisis protocol with momentum-based rebalancing logic.

---

## Features

- **Dual-broker portfolio tracking** — Aggregates positions from Interactive Brokers (Flex API) and Saxo Bank (OpenAPI) into a unified allocation view
- **Real-time macro dashboard** — FRED, Eurostat, ECB, and Yahoo Finance data: VIX, yield curves, credit spreads, inflation, DXY, gold, oil
- **Crisis protocol** — 2-of-3 rule (drawdown, VIX, credit spread) with 4-level deployment strategy
- **Momentum rebalancing** — Volatility-adjusted momentum scoring with regime detection (SMA200 + VIX), drift × momentum decision matrix
- **Trigger system** — T1 (crash), T3 (stagflation), T5 (credit), T9 (recession) triggers with warning indicators (CAPE, ERP, EUR/USD)
- **5/25 rebalancing rule** — Automatic drift detection with threshold-based rebal alerts
- **MCP server** — Claude AI integration via Model Context Protocol (6 tools)
- **Automated background refresh** — Cron-based data warming every 15–60 minutes
- **Interactive charts** — Asset allocation (doughnut), factor analysis (radar), P/L performance (bar) via Chart.js

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────┐
│                      Browser / Claude AI                │
│                   (Twig + Stimulus/Turbo)                │
└────────────────┬──────────────────────┬─────────────────┘
                 │ HTTP                 │ MCP (JSON-RPC)
                 ▼                      ▼
┌────────────────────────┐  ┌─────────────────────────────┐
│   DashboardController  │  │       McpController          │
│   LoginController      │  │  (Streamable HTTP + SSE)     │
│   SaxoAuthController   │  └──────────┬──────────────────┘
│   HealthController     │             │
└────────┬───────────────┘             │
         │                             ▼
         │              ┌──────────────────────────────┐
         │              │       MCP Services            │
         │              │  McpDashboardService          │
         │              │  McpIndicatorService (50+)    │
         │              │  McpMomentumService            │
         │              │  McpProtocolService            │
         │              └──────────┬───────────────────┘
         │                         │
         ▼                         ▼
┌──────────────────────────────────────────────────────────┐
│                    Core Services                         │
│                                                          │
│  PortfolioService ──── config/mido_v65.yaml (targets)    │
│  CrisisService ─────── config/historical_crises.json     │
│  TriggerService                                          │
│  MomentumService                                         │
│  CalculationService                                      │
│  DashboardCacheService                                   │
└──────┬──────────┬──────────┬──────────┬─────────────────┘
       │          │          │          │
       ▼          ▼          ▼          ▼
┌──────────┐ ┌────────┐ ┌────────┐ ┌──────────────────────┐
│ IbClient │ │ Saxo   │ │ FRED   │ │ MarketDataService    │
│ (Flex)   │ │ Client │ │ API    │ │ EurostatService      │
│          │ │(OAuth2)│ │        │ │ GoldPriceService     │
│          │ │        │ │        │ │ DxyService           │
└──────────┘ └────────┘ └────────┘ └──────────────────────┘
       │          │          │          │
       ▼          ▼          ▼          ▼
   IB Flex    Saxo Bank    FRED     Yahoo Finance
   API        OpenAPI      API      Eurostat/ECB
```

### Cache Strategy

| Layer | File | TTL | Purpose |
|-------|------|-----|---------|
| Dashboard | `var/dashboard_cache.json` | 15 min | Full pre-computed dashboard |
| IB Statement | `var/ib_statement.xml` | 1 hour | Flex API XML response |
| Saxo Positions | `var/saxo_cache.json` | 15 min | Positions + balance |
| Saxo Tokens | `var/saxo_tokens.json` | — | OAuth2 access/refresh tokens |
| Momentum | `var/momentum_cache.json` | 1 hour | Momentum scores + regime |
| FRED Data | Symfony Cache | varies | 1h (daily) to 24h (quarterly) |
| Market Data | Symfony Cache | 15 min | Yahoo Finance prices |
| Gold/DXY | Symfony Cache | 5 min | Gold price, DXY index |

---

## Tech Stack

| Component | Technology |
|-----------|------------|
| Framework | Symfony 8 |
| Language | PHP 8.4+ (strict types) |
| Templates | Twig |
| Frontend | Stimulus 3.2, Turbo 7.3, Chart.js 3.9 |
| Asset Pipeline | Symfony Asset Mapper (no Node.js) |
| Testing | PHPUnit 13 |
| Static Analysis | PHPStan (level max) |
| Container | Docker (php:8.4-apache) |
| Deployment | Coolify |

### External APIs

| API | Service | Purpose |
|-----|---------|---------|
| Interactive Brokers | `IbClient` | Flex Statement — positions, cash, P/L |
| Saxo Bank | `SaxoClient` | OpenAPI — positions, balance (OAuth2) |
| FRED | `FredApiService` | 20+ macro series (VIX, yields, spreads, etc.) |
| Yahoo Finance | `MarketDataService` | Real-time prices, 52-week highs, drawdowns |
| Eurostat / ECB | `EurostatService` | Eurozone HICP inflation |
| Gold/Silver | `GoldPriceService` | Spot prices (goldprice.org + Swissquote) |
| Exchange Rates | `DxyService` | ICE DXY calculation via exchangerate-api.com |

---

## Getting Started

### Prerequisites

- PHP 8.4+
- Composer 2
- Docker (optional, for containerized deployment)

### Installation

```bash
git clone https://github.com/mdoens/midodash.git
cd midodash
composer install
```

### Environment Setup

Copy the placeholder `.env` to `.env.local` and fill in the secrets:

```bash
cp .env .env.local
```

Required secrets in `.env.local`:

| Variable | Description |
|----------|-------------|
| `IB_TOKEN` | Interactive Brokers Flex Web Service token |
| `IB_QUERY_ID` | IB Flex query ID |
| `FRED_API_KEY` | FRED API key (free at api.stlouisfed.org) |
| `SAXO_APP_KEY` | Saxo Bank application key |
| `SAXO_APP_SECRET` | Saxo Bank application secret |
| `DASHBOARD_PASSWORD_HASH` | Bcrypt hash for dashboard login |
| `APP_SECRET` | Symfony application secret |

### Local Development

```bash
php -S localhost:8080 -t public/
```

Visit `http://localhost:8080` to access the dashboard.

### Docker

```bash
docker build -t midodash .
docker run -p 8080:80 \
  --env-file .env.local \
  -v midodash-var:/var/www/html/var \
  midodash
```

The Docker volume `midodash-var` persists cached data and OAuth tokens across container restarts.

---

## Configuration

### Strategy Config (`config/mido_v65.yaml`)

The investment strategy is defined in a single YAML file. Key sections:

| Section | Description |
|---------|-------------|
| `mido.strategy` | Version, portfolio value, number of positions |
| `mido.targets` | Per-position target allocation, ticker, platform, asset class, ISIN |
| `mido.asset_classes` | Asset class targets with bands (e.g., Equity 75% ± 5%) |
| `mido.crisis` | Crisis thresholds: drawdown, VIX, HY spread, sustained days |
| `mido.deployment` | 4-level crisis deployment protocol with amounts |
| `mido.momentum` | Momentum universe, bandwidths, regime thresholds |
| `mido.symbol_map` | Maps broker symbols to target names (IB, Saxo, legacy) |
| `mido.factor_scores` | Factor exposure: Beta, Value, Momentum, Size, Quality |

### Environment Variables

Non-secret variables (safe in `.env`, committed to git):

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_ENV` | `dev` | Symfony environment |
| `APP_SHARE_DIR` | `var/share` | Shared file storage directory |
| `DEFAULT_URI` | `https://mido.barcelona2.doens.nl` | Application base URL |
| `SAXO_REDIRECT_URI` | `https://mido.barcelona2.doens.nl/saxo/callback` | Saxo OAuth2 callback URL |
| `SAXO_AUTH_ENDPOINT` | `https://live.logonvalidation.net/authorize` | Saxo authorization endpoint |
| `SAXO_TOKEN_ENDPOINT` | `https://live.logonvalidation.net/token` | Saxo token endpoint |
| `SAXO_API_BASE` | `https://gateway.saxobank.com/openapi` | Saxo API base URL |

---

## API Integrations

### Interactive Brokers (Flex Statement)

The `IbClient` service fetches portfolio data via IB's Flex Web Service:

1. Sends a `SendRequest` with the query ID and token
2. Polls `GetStatement` until the XML report is ready (max 10 attempts)
3. Parses `OpenPositions/OpenPosition` elements for holdings and `CashReportCurrency` for cash balances
4. Caches the XML response for 1 hour

### Saxo Bank (OAuth2)

The `SaxoClient` implements a full OAuth2 authorization code flow:

1. **`/saxo/login`** — Redirects to Saxo's authorization endpoint with state parameter
2. **`/saxo/callback`** — Exchanges the authorization code for access + refresh tokens
3. **Auto-refresh** — Tokens are refreshed automatically on 401 responses and via the `app:saxo:refresh` cron command
4. Tokens are persisted to `var/saxo_tokens.json` (Docker volume)

### FRED API

The `FredApiService` tracks 20+ macro-economic series with frequency-aware caching:

- **Daily** (1h cache): VIX, Treasury yields (2Y/10Y/30Y), yield curve spread, credit spreads, oil, EUR/USD, DXY, Fed Funds
- **Weekly** (6h cache): TED rate, initial claims, financial stress index
- **Monthly** (12h cache): unemployment, consumer sentiment, copper
- **Quarterly** (24h cache): real GDP

### Yahoo Finance

The `MarketDataService` fetches real-time quotes from Yahoo Finance's chart API for ETF prices, 52-week highs, and drawdown calculations.

### Eurostat / ECB

The `EurostatService` retrieves Eurozone HICP inflation with a fallback chain: Eurostat API → ECB Statistical Data Warehouse.

---

## MCP Server

MidoDash includes a built-in [Model Context Protocol](https://modelcontextprotocol.io/) server for Claude AI integration.

- **Endpoint**: `POST /mcp` (JSON-RPC 2.0) | `GET /mcp` (SSE) | `DELETE /mcp` (session termination)
- **Info**: `GET /mcp/info`
- **Protocol version**: `2025-03-26` (Streamable HTTP transport)
- **Authentication**: Public access (no API key required)

### Available Tools

| Tool | Description |
|------|-------------|
| `mido_macro_dashboard` | Full macro-economic dashboard with all indicators |
| `mido_indicator` | Single indicator lookup (50+ aliases for FRED series + calculated values) |
| `mido_triggers` | Evaluate all trigger conditions (T1, T3, T5, T9 + warnings) |
| `mido_crisis_dashboard` | Crisis protocol status with 2-of-3 signal evaluation |
| `mido_drawdown_calculator` | Calculate drawdown from 52-week high with phase classification |
| `mido_momentum_rebalancing` | Full drift × momentum rebalancing report with trade recommendations |

For detailed tool schemas and decision rules, see [SKILL.md](SKILL.md).

---

## Investment Logic

### Crisis Protocol

The crisis protocol uses a **2-of-3 rule** — at least 2 of 3 signals must be active:

| Signal | Threshold | Source |
|--------|-----------|--------|
| Price | IWDA drawdown ≤ -20% from 52-week high | Yahoo Finance |
| Volatility | VIX > 30 for 3+ consecutive days | FRED (VIXCLS) |
| Credit | HY spread > 500 bps | FRED (BAMLH0A0HYM2) |

When triggered, a 4-level deployment protocol activates:

| Level | Amount | Action |
|-------|--------|--------|
| 1 | €25,000 | Redeploy from XEON |
| 2 | €45,000 | Redeploy from XEON + IBGS |
| 3 | €45,000 | Redeploy from IBGS |
| 4 | Remainder | Full IBGS redeployment |

### Momentum Strategy

Volatility-adjusted momentum scoring for 7 ETFs:

1. Fetch 13 months of price history from Yahoo Finance
2. Calculate monthly returns (skip most recent month)
3. Score = mean(returns) / stdev(returns) — penalizes volatile momentum
4. **Regime detection**: IWDA.AS price vs 200-day SMA, combined with VIX level
   - **BULL**: Price > SMA200
   - **BEAR**: Price < SMA200
   - **BEAR_BEVESTIGD**: Price < SMA200 AND VIX > 30

Bandwidths adjust by regime: normal 3%, expanded 5%, maximum 7%.

### Drift × Momentum Decision Matrix

| Condition | Action |
|-----------|--------|
| HARDCAP | Drift exceeds asset class band → mandatory rebalance |
| LARGE_DRIFT | Drift > 5% and momentum confirms → rebalance |
| MODERATE_DRIFT | 3-5% drift, momentum aligned → consider rebalance |
| WITHIN_BAND | Drift < 3% → hold, no action |

### Trigger System

| Trigger | Condition | Severity |
|---------|-----------|----------|
| T1 (Crash) | VIX > 30 | High |
| T3 (Stagflation) | EU inflation > 4.0% | High |
| T5 (Credit) | HY spread > 6.0% OR IG spread > 2.5% | High |
| T9 (Recession) | Yield curve T10Y2Y inversion | High |
| W1 (CAPE) | Shiller CAPE > 35 | Warning |
| W2 (ERP) | Equity Risk Premium < 1.0% | Warning |
| W3 (EUR/USD) | Deviation from 1.15 > 10% | Warning |

### 5/25 Rebalancing Rule

Positions are monitored against their target allocation:

- **5% absolute drift** on any individual position → rebalance alert
- **25% relative drift** (drift / target) on any position → rebalance alert

---

## CLI Commands

| Command | Schedule | Description |
|---------|----------|-------------|
| `app:dashboard:warmup` | Every 15 min | Pre-compute and cache all dashboard data |
| `app:saxo:refresh` | Every 15 min | Refresh Saxo OAuth2 access token |
| `app:ib:fetch` | Every 30 min | Fetch IB Flex statement (slow API, 1h cache) |
| `app:momentum:warmup` | Every hour | Compute momentum scores and regime detection |

Run manually:

```bash
php bin/console app:dashboard:warmup
php bin/console app:saxo:refresh
php bin/console app:ib:fetch
php bin/console app:momentum:warmup
```

On Docker container startup, all 4 commands run sequentially before Apache starts.

---

## Deployment

### Coolify

MidoDash is deployed via [Coolify](https://coolify.io/) from the public GitHub repository.

- **Dashboard**: Coolify admin panel
- **Source**: Public GitHub (`mdoens/midodash`)
- **Build**: Dockerfile in project root

### deploy.sh

One-command deploy: commit, push, trigger Coolify build, and wait for completion.

```bash
# Set token (once per session, or stored in .env.local)
export COOLIFY_TOKEN=your_token_here

# Deploy
./deploy.sh "feat: my change description"
```

The script:
1. Stages all changes and commits with the provided message
2. Pushes to `origin main`
3. Triggers a Coolify deployment via API
4. Polls deployment status every 10 seconds (max 5 minutes)

### Docker Build

The Dockerfile builds a production-ready image:

1. Base: `php:8.4-apache` with `mod_rewrite`, `zip`, `opcache`
2. Installs Composer 2, runs `composer install --no-dev`
3. Compiles assets via `importmap:install` and `asset-map:compile`
4. Configures Apache with `DocumentRoot /var/www/html/public`
5. Sets up cron jobs for background data refresh
6. Entrypoint runs all warmup commands, then starts Apache

### Cron Schedule (Production)

| Schedule | Command | Purpose |
|----------|---------|---------|
| `*/15 * * * *` | `app:dashboard:warmup` | Keep dashboard cache warm |
| `*/15 * * * *` | `app:saxo:refresh` | Prevent Saxo token expiry |
| `*/30 * * * *` | `app:ib:fetch` | Refresh IB positions |
| `0 * * * *` | `app:momentum:warmup` | Update momentum signals |

---

## Testing

### Running Tests

```bash
# Full test suite
./vendor/bin/phpunit

# Specific test class
./vendor/bin/phpunit --filter=IbClientTest

# With coverage
./vendor/bin/phpunit --coverage-text
```

### Test Coverage

| Service | Tests | Coverage |
|---------|-------|----------|
| `IbClient` | 5 | XML parsing, cash report, edge cases |
| `SaxoClient` | 5 | Position parsing, empty data, auth URL |
| `MomentumService` | 10 | Score calculation, regime, monthly returns, equity constraints |

### Static Analysis

```bash
# PHPStan (level max)
./vendor/bin/phpstan analyse src/ --level=max

# Syntax check
php -l src/**/*.php

# Symfony linters
php bin/console lint:container
php bin/console lint:twig templates/
php bin/console lint:yaml config/
```

---

## Project Structure

```
midodash/
├── config/
│   ├── historical_crises.json     # Historical crisis reference data
│   ├── mido_v65.yaml              # Investment strategy v8.0 config
│   ├── packages/                  # Symfony bundle configuration
│   ├── routes.yaml                # Route configuration
│   └── services.yaml              # Service autowiring
├── public/
│   ├── index.php                  # Symfony front controller
│   └── assets/                    # Compiled frontend assets
├── src/
│   ├── Command/
│   │   ├── DashboardWarmupCommand.php
│   │   ├── IbFetchCommand.php
│   │   ├── MomentumWarmupCommand.php
│   │   └── SaxoRefreshCommand.php
│   ├── Controller/
│   │   ├── DashboardController.php    # Main dashboard (GET /)
│   │   ├── HealthController.php       # Health check (GET /health)
│   │   ├── LoginController.php        # Authentication
│   │   ├── McpController.php          # MCP server endpoint
│   │   └── SaxoAuthController.php     # Saxo OAuth2 flow
│   ├── Service/
│   │   ├── CalculationService.php     # CAPE, ERP, recession probability
│   │   ├── CrisisService.php          # 2-of-3 crisis protocol
│   │   ├── DashboardCacheService.php  # Dashboard cache layer
│   │   ├── DxyService.php             # US Dollar Index
│   │   ├── EurostatService.php        # EU inflation data
│   │   ├── FredApiService.php         # FRED macro data (20+ series)
│   │   ├── GoldPriceService.php       # Gold/silver prices
│   │   ├── IbClient.php               # Interactive Brokers Flex API
│   │   ├── MarketDataService.php      # Yahoo Finance prices
│   │   ├── Mcp/
│   │   │   ├── McpDashboardService.php
│   │   │   ├── McpIndicatorService.php
│   │   │   ├── McpMomentumService.php
│   │   │   └── McpProtocolService.php
│   │   ├── MomentumService.php        # Momentum scoring + regime
│   │   ├── PortfolioService.php       # Allocation engine
│   │   ├── SaxoClient.php             # Saxo Bank OAuth2 + API
│   │   └── TriggerService.php         # T1/T3/T5/T9 triggers
│   └── Kernel.php
├── templates/
│   ├── base.html.twig
│   ├── dashboard/index.html.twig      # Main dashboard view
│   └── login.html.twig
├── tests/
│   └── Service/
│       ├── IbClientTest.php
│       ├── MomentumServiceTest.php
│       └── SaxoClientTest.php
├── .env                               # Non-secret defaults + placeholders
├── CLAUDE.md                          # Development standards
├── SKILL.md                           # MCP tool documentation
├── Dockerfile                         # Production container image
├── deploy.sh                          # Coolify deployment script
├── composer.json
└── phpunit.xml.dist
```

---

## Security

### Secrets Management

- All secrets are stored in `.env.local` (gitignored) for local development and in Coolify environment variables for production
- The `.env` file committed to git contains only empty placeholders
- Never commit API keys, tokens, or password hashes to version control

### Authentication

- Dashboard access is protected by Symfony Security with form login
- Single user (`mido`) with bcrypt password hash stored in environment variable
- Session-based authentication

### CORS (MCP)

The MCP endpoint allows cross-origin requests from:
- `https://claude.ai`
- `https://www.claude.ai`
- `https://console.anthropic.com`
- Requests without an `Origin` header (desktop apps)

### Health Check

`GET /health` returns service status (200 OK or 503 Degraded) without requiring authentication, suitable for monitoring and load balancer health probes.
