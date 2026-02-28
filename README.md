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
- **MCP server** — Claude AI integration via Model Context Protocol (18 tools) with bearer token auth
- **Database persistence** — Doctrine ORM with daily portfolio snapshots, transaction history, price history, API response buffering
- **Open orders awareness** — PENDING status for positions with open orders, suppresses false rebalance warnings
- **Automated background refresh** — Cron-based data warming every 15–60 minutes with Docker env var forwarding
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
         │              │       MCP Services (18 tools)  │
         │              │  McpDashboardService           │
         │              │  McpIndicatorService (50+)     │
         │              │  McpMomentumService            │
         │              │  McpPortfolioService           │
         │              │  McpPerformanceService         │
         │              │  McpRiskService                │
         │              │  McpPlanningService            │
         │              │  McpProtocolService            │
         │              └──────────┬───────────────────┘
         │                         │
         ▼                         ▼
┌──────────────────────────────────────────────────────────┐
│                    Core Services                         │
│                                                          │
│  PortfolioService ──── config/mido_v65.yaml (targets)    │
│  ReturnsService ───── TransactionRepository              │
│  PortfolioSnapshotService ── daily snapshots              │
│  DataBufferService ── API response fallback               │
│  CrisisService ─────── config/historical_crises.json     │
│  TriggerService / MomentumService                        │
│  CalculationService / DashboardCacheService              │
│  TransactionImportService                                │
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

### Cache & Persistence Strategy

| Layer | File / Storage | TTL | Purpose |
|-------|---------------|-----|---------|
| Dashboard | `var/dashboard_cache.json` | 15 min | Full pre-computed dashboard |
| IB Statement | `var/ib_statement.xml` | 1 hour | Flex API XML response |
| Saxo Positions | `var/saxo_cache.json` | 15 min | Positions + balance |
| Saxo Tokens | `var/saxo_tokens.json` + DB | — | OAuth2 access/refresh tokens (dual-write) |
| Momentum | `var/momentum_cache.json` | 1 hour | Momentum scores + regime |
| DataBuffer | Database (`data_buffer`) | — | Last API responses for fallback |
| Snapshots | Database (`portfolio_snapshot`) | — | Daily portfolio snapshots for history |
| Transactions | Database (`transaction`) | — | IB + Saxo trade history |
| Price History | Database (`price_history`) | — | Daily ETF prices for risk metrics |
| Sessions | `var/sessions/` | 7 days | Symfony session files (survives cache:clear) |
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
2. **`/saxo/callback`** — Exchanges the authorization code for access + refresh tokens, verifies authentication
3. **Proactive refresh** — Tokens are refreshed at 50% lifetime (not last-minute), preventing race conditions
4. **Token merge** — `refreshToken()` merges old + new token fields so `refresh_token` and `refresh_token_expires_in` are never lost
5. **Retry logic** — 2x retry on 5xx server errors during refresh
6. **Dual-write persistence** — Tokens saved to both `var/saxo_tokens.json` (fast reads) and database `DataBuffer` (survives container rebuilds)
7. **Cron keep-alive** — `app:saxo:refresh` runs every 15 min via Docker cron, sources env vars from `/etc/midodash-env.sh`
8. **Graceful degradation** — When Saxo data unavailable, falls back to `DataBuffer` cached positions with `data_freshness: buffered` indicator

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
- **Authentication**: Bearer token via `Authorization: Bearer <token>` header. Tokens in `MCP_API_TOKENS` env var (comma-separated). Empty = auth disabled.

### Available Tools (18)

#### Macro & Strategy (6 tools)
| Tool | Description |
|------|-------------|
| `mido_macro_dashboard` | Full macro-economic dashboard with all indicators |
| `mido_indicator` | Single indicator lookup (50+ aliases for FRED series + calculated values) |
| `mido_triggers` | Evaluate all trigger conditions (T1, T3, T5, T9 + warnings) |
| `mido_crisis_dashboard` | Crisis protocol status with 2-of-3 signal evaluation |
| `mido_drawdown_calculator` | Calculate drawdown from 52-week high with phase classification |
| `mido_momentum_rebalancing` | Full drift × momentum rebalancing report with trade recommendations |

#### Portfolio & Cash (3 tools)
| Tool | Description |
|------|-------------|
| `mido_portfolio_snapshot` | Live positions with weights, P/L, drift vs target, data freshness |
| `mido_cash_overview` | Cash per platform, open orders, dry powder breakdown, deployable capital |
| `mido_currency_exposure` | FX exposure breakdown, EUR vs non-EUR split |

#### Performance & Attribution (2 tools)
| Tool | Description |
|------|-------------|
| `mido_performance_history` | Portfolio value over time, TWR calculation, benchmark comparison |
| `mido_attribution` | Return attribution per position/asset class/platform/geography |

#### Risk & Stress (2 tools)
| Tool | Description |
|------|-------------|
| `mido_risk_metrics` | Volatility, Sharpe, Sortino, VaR/CVaR, max drawdown |
| `mido_stress_test` | 5 preset scenarios (crash, rate hike, stagflation) + custom shocks |

#### Planning & Analysis (5 tools)
| Tool | Description |
|------|-------------|
| `mido_cost_analysis` | Transaction costs + TER per position, total cost ratio |
| `mido_fundamentals` | P/E, dividend yield, AUM via Yahoo Finance |
| `mido_fund_lookthrough` | Top holdings, sector/geography breakdown per ETF |
| `mido_rebalance_advisor` | Concrete buy/sell orders with FBI warnings |
| `mido_scenario_planner` | Monte Carlo simulation (1000 runs) + milestones |

All tools support `format: 'markdown'|'json'` parameter. For detailed tool schemas and decision rules, see [SKILL.md](SKILL.md).

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

Position statuses:
| Status | Meaning |
|--------|---------|
| OK | Within band |
| MONITOR | 3-5% drift, watch closely |
| REBAL | >5% drift or >25% relative — action needed |
| PENDING | Open order exists that would bring position within band |
| ONTBREEKT | Target position missing (0% held, no open order) |

The PENDING status prevents false rebalance warnings when buy orders are placed but not yet executed (e.g., Saxo mutual fund orders that take 1-2 days).

---

## CLI Commands

| Command | Schedule | Description |
|---------|----------|-------------|
| `app:dashboard:warmup` | Every 15 min | Pre-compute and cache all dashboard data |
| `app:saxo:refresh` | Every 15 min | Refresh Saxo OAuth2 access token (proactive at 50% lifetime) |
| `app:ib:fetch` | Every 30 min | Fetch IB Flex statement (slow API, 1h cache) |
| `app:momentum:warmup` | Every hour | Compute momentum scores and regime detection |
| `app:transactions:import` | On startup | Import IB transactions into database |
| `app:db:migrate` | On startup | Run Doctrine schema migrations (SchemaTool) |
| `app:price:sync` | Manual | Sync ETF price history from Yahoo Finance |

Run manually:

```bash
php bin/console app:dashboard:warmup
php bin/console app:saxo:refresh
php bin/console app:ib:fetch
php bin/console app:momentum:warmup
php bin/console app:transactions:import
php bin/console app:db:migrate
```

### Docker Startup Sequence

On container startup, `docker-entrypoint.sh` runs:
1. Export env vars to `/etc/midodash-env.sh` (cron can't access Docker env vars)
2. Start cron daemon
3. Force-remove stale Twig cache from Docker volume
4. `cache:clear` + `cache:warmup`
5. Create sessions directory
6. Run database migrations
7. Refresh Saxo token
8. Pre-fetch IB data
9. Import IB transactions
10. Warm momentum cache
11. Warm dashboard cache
12. Start Apache in foreground

### Cron Environment Variables

Docker cron has no access to container env vars. The entrypoint writes all env vars as `export` statements to `/etc/midodash-env.sh`. Each cron job sources this file before executing.

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
| `*/15 * * * *` | `app:saxo:refresh` | Proactive Saxo token refresh (50% lifetime) |
| `*/15 * * * *` | `app:dashboard:warmup` | Pre-compute all dashboard data |
| `*/30 * * * *` | `app:ib:fetch` | Refresh IB Flex statement |
| `0 * * * *` | `app:momentum:warmup` | Update momentum scores + regime |
| `30 17 * * 1-5` | `app:prices:sync` | Yahoo Finance price history |
| `0 */6 * * *` | `app:transactions:import` | IB + Saxo transaction import |

All cron jobs source `/etc/midodash-env.sh` for Docker environment variables. Output is logged to `var/log/cron.log` (directory created by entrypoint on startup).

### Coolify API — Log Access

Container logs can be retrieved via the Coolify API without SSH:

```bash
# Runtime container logs (last hour)
curl -s -H "Authorization: Bearer $COOLIFY_TOKEN" \
  "https://coolify.barcelona2.doens.nl/api/v1/applications/mw0ks0s8sc8cw0csocwksskk/logs?since=3600"

# Deployment history & logs
curl -s -H "Authorization: Bearer $COOLIFY_TOKEN" \
  "https://coolify.barcelona2.doens.nl/api/v1/deployments"

# Filter for specific keywords (e.g., Saxo token issues)
curl -s ... | python3 -c "
import sys, json
for l in json.load(sys.stdin)['logs'].split('\n'):
    if 'saxo' in l.lower(): print(l[:200])
"
```

API docs: `https://coolify.barcelona2.doens.nl/docs/api-reference`

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
│   │   ├── DatabaseMigrateCommand.php   # Schema migrations (SchemaTool)
│   │   ├── DashboardWarmupCommand.php   # Pre-compute dashboard cache
│   │   ├── IbFetchCommand.php           # Fetch IB Flex statement
│   │   ├── MomentumWarmupCommand.php    # Momentum scores + regime
│   │   ├── PriceSyncCommand.php         # Sync ETF price history
│   │   ├── SaxoRefreshCommand.php       # Proactive Saxo token refresh
│   │   └── TransactionImportCommand.php # Import IB/Saxo transactions
│   ├── Controller/
│   │   ├── DashboardController.php    # Main dashboard + health checks
│   │   ├── LoginController.php        # Symfony form authentication
│   │   ├── McpController.php          # MCP server (18 tools)
│   │   ├── SaxoAuthController.php     # Saxo OAuth2 flow
│   │   └── TransactionController.php  # Transaction CRUD
│   ├── Entity/
│   │   ├── DataBuffer.php             # Cached API responses (fallback)
│   │   ├── PortfolioSnapshot.php      # Daily portfolio snapshots
│   │   ├── PositionSnapshot.php       # Position records per snapshot
│   │   ├── PriceHistory.php           # Daily ETF prices
│   │   └── Transaction.php           # Trade history (IB + Saxo)
│   ├── Service/
│   │   ├── CalculationService.php     # CAPE, ERP, recession probability
│   │   ├── CrisisService.php          # 2-of-3 crisis protocol
│   │   ├── DashboardCacheService.php  # Dashboard cache layer
│   │   ├── DataBufferService.php      # API response caching + fallback
│   │   ├── DxyService.php             # US Dollar Index
│   │   ├── EurostatService.php        # EU inflation data
│   │   ├── FredApiService.php         # FRED macro data (20+ series)
│   │   ├── GoldPriceService.php       # Gold/silver (Swissquote primary)
│   │   ├── IbClient.php               # Interactive Brokers Flex API
│   │   ├── MarketDataService.php      # Yahoo Finance prices
│   │   ├── Mcp/
│   │   │   ├── McpDashboardService.php    # Macro dashboard tool
│   │   │   ├── McpIndicatorService.php    # 50+ indicator aliases
│   │   │   ├── McpMomentumService.php     # Momentum rebalancing
│   │   │   ├── McpPerformanceService.php  # Performance + attribution
│   │   │   ├── McpPlanningService.php     # Cost, fundamentals, planning
│   │   │   ├── McpPortfolioService.php    # Portfolio + cash overview
│   │   │   ├── McpProtocolService.php     # JSON-RPC protocol handler
│   │   │   └── McpRiskService.php         # Risk metrics + stress test
│   │   ├── MomentumService.php        # Momentum scoring + regime
│   │   ├── PortfolioService.php       # Allocation engine + open orders
│   │   ├── PortfolioSnapshotService.php # Daily snapshot persistence
│   │   ├── ReturnsService.php         # P/L, dividends, total return
│   │   ├── SaxoClient.php             # Saxo OAuth2 + proactive refresh
│   │   ├── TransactionImportService.php # Transaction import + parsing
│   │   └── TriggerService.php         # T1/T3/T5/T9 triggers
│   └── Kernel.php
├── templates/
│   ├── base.html.twig
│   ├── dashboard/index.html.twig      # Main dashboard (6 tabs)
│   └── login.html.twig
├── tests/
│   └── Service/
│       ├── IbClientTest.php
│       ├── MomentumServiceTest.php
│       └── SaxoClientTest.php
├── var/
│   ├── sessions/                      # Session files (persistent)
│   ├── saxo_tokens.json               # Saxo OAuth2 tokens
│   └── data/mido.sqlite              # SQLite DB (local dev only)
├── .env                               # Non-secret defaults + placeholders
├── CHANGELOG.md                       # Deployment changelog (mandatory)
├── CLAUDE.md                          # Development standards
├── SKILL.md                           # MCP tool documentation
├── Dockerfile                         # Production container image
├── docker-entrypoint.sh              # Startup: migrations, warmup, cron
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

### MCP Authentication

Bearer token authentication on `/mcp` and `/mcp/info` endpoints:
- Tokens configured via `MCP_API_TOKENS` env var (comma-separated for multiple users)
- Empty `MCP_API_TOKENS` = auth disabled (backwards compatible)

### CORS (MCP)

The MCP endpoint allows cross-origin requests from:
- `https://claude.ai`
- `https://www.claude.ai`
- `https://console.anthropic.com`
- Requests without an `Origin` header (desktop apps)

### Health Checks

| Endpoint | Description |
|----------|-------------|
| `GET /health` | Service status (200 OK / 503 Degraded) |
| `GET /health/returns` | Full template render test with chart validation |
| `GET /health/ib` | IB data diagnostics |
| `GET /health/saxo` | Saxo token status + TTL |
| `GET /health/import` | Transaction import status |

### Database

| Environment | Database | Connection |
|-------------|----------|------------|
| Local dev | SQLite | `var/data/mido.sqlite` |
| Production | MySQL | Via Coolify `DATABASE_URL` env var |

Migrations run via `app:db:migrate` (Doctrine SchemaTool `updateSchema`), NOT Doctrine Migrations.

### Docker Volume

`midodash-var` mounted on `/var/www/html/var` persists:
- Saxo OAuth2 tokens (`saxo_tokens.json`)
- Session files (`sessions/`)
- Cached data (dashboard, IB, Saxo, momentum)
- **Note**: Compiled Twig cache on the volume can become stale — `docker-entrypoint.sh` force-removes it on startup
