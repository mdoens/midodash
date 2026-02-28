# Changelog — MidoDash

## 2026-02-28 (d)

### Fixed
- **T9 Recession trigger** — was hardcoded `active: false`, nu gebaseerd op `CalculationService::calculateRecessionProbability()` (multi-factor: yield curve, HY spread, VIX, jobless claims, consumer sentiment). Activeert bij score ≥ 30% (ELEVATED). MCP dashboard toont probability in trigger label
- **Recession probability VIX** — `calculateRecessionProbability()` gebruikte FRED (vorige dag), nu Yahoo Finance real-time als primary met FRED fallback — consistent met T1 en crisis protocol

---

## 2026-02-28 (c)

### Fixed
- **MCP cash dubbeltelling** — `McpPortfolioService::fetchLiveAllocation()` telde open order waarde op bij Saxo CashBalance, maar CashBalance bevat dit al. Portfolio was €1.65M i.p.v. €1.44M via MCP

### Added
- **Real-time VIX** — Yahoo Finance `^VIX` als primaire bron (1h cache), FRED als fallback. Geldt voor dashboard, crisis protocol, T1 trigger en MCP tools. FRED levert alleen vorige dag's slotkoers
- **Cron jobs draaiden niet (2 oorzaken)** — (1) `var/log/` directory bestond niet op Docker volume → output redirect failde → commando's niet uitgevoerd. (2) `php` niet in cron PATH → `php: command not found`. Fix: `mkdir -p var/log` in entrypoint + `PATH=/usr/local/sbin:/usr/local/bin:...` in crontab
- **Saxo token file permissions** — entrypoint draait als root, Apache als www-data. Files op Docker volume waren root-owned → `Permission denied` bij schrijven `saxo_tokens.json` en `dashboard_cache.json`. Fix: `chown -R www-data:www-data var/` na warmup
- **Saxo token loadTokens()** — las altijd het bestand eerst, zelfs als DB nieuwere tokens had (na gefaalde file write). Nu vergelijkt `created_at` timestamps en gebruikt de nieuwste bron

### Changed
- **docker-entrypoint.sh** — `mkdir -p var/log` + `chown -R www-data:www-data var/`
- **Dockerfile** — `PATH` regel toegevoegd aan crontab
- **CLAUDE.md** — Coolify API log access documentatie toegevoegd
- **README.md** — Coolify API log access, cron schedule tabel compleet

---

## 2026-02-28 (b)

### Fixed
- **Saxo login status** — dashboard cache wordt geïnvalideerd na Saxo callback, zodat "Saxo ✓" direct zichtbaar is
- **Saxo cash dubbeltelling** — CashBalance bevat al open order geld, niet meer optellen bij allocatie
- **Saxo dividenden** — `BkRecordId` als fallback external ID in cash import (CorporateAction heeft geen TransactionId/BookingId)
- **Saxo deposits over-counted** — clean reimport: 11 deposits (€547K) i.p.v. 17 (€839K) door import bug
- **Totaal gestort correct** — was €1.709K, nu €1.417K (rendement: +1.4% i.p.v. -15.9%)
- **Totaal gestort fallback** — 3-layer: Saxo API → DB deposit transacties → cost basis
- **Import command robuuster** — `ensureValidToken()` probeert actief te refreshen

### Added
- **`/health/audit`** — volledige breakdown van deposits, dividenden, returns per platform
- **`/health/reimport-saxo`** — schone Saxo transactie re-import (delete + reimport)
- **Post-login import** — Saxo trades + cash transacties worden direct geïmporteerd na Saxo login

### Changed
- **Import cron** — van 1x/dag (19:00) naar elke 6 uur
- **SaxoClient** — nieuwe `ensureValidToken()` public methode

### Known Issues
- **IB dividenden ontbreken** — IB Flex query bevat geen CashTransaction dividend records. Moet handmatig aangepast worden in IB Flex Query portal.

---

## 2026-02-28

### Fixed
- **MCP portfolio Saxo fallback** — buffer fallback zat in catch-block maar getPositions() returnt null zonder exception. Nu als aparte check na try/catch (`516bd4e`)
- **Open orders in platform split** — open order waarde meegeteld in Saxo cash (MCP + Dashboard) (`516bd4e`)
- **Cron env vars** — Docker cron had geen toegang tot env vars. Nu als export statements via /etc/midodash-env.sh (`cc9522c`)
- **Template null check** — alle 5 render_chart() calls bewaakt met null guards (`3e5cb0a`)
- **Health check charts** — history_chart en allocation_chart werden niet gebouwd in health render path (`3e5cb0a`)
- **Twig cache staleness** — compiled Twig cache op Docker volume overleefde deploys, force-remove in entrypoint (`a373468`)

### Added
- **PENDING status** voor posities met open orders — onderdrukt valse REBAL/ONTBREEKT waarschuwingen (`bbbab6c`)
- **Saxo data waarschuwing** in platform verdeling als Saxo data ontbreekt
- **Auth verificatie** na Saxo login in callback — diagnostisch flash message bij falen (`cc9522c`)

### Changed
- **README.md** volledig bijgewerkt — MCP v2.0 (18 tools), database, Docker cron, Saxo proactive refresh, PENDING status (`3e5cb0a`)
- **CLAUDE.md** bijgewerkt — key files, MCP tools, Docker pitfalls, env vars (`3e5cb0a`)

---

## 2026-02-27 — Saxo Token Fix

### Fixed
- **Saxo token refresh fundamenteel herschreven** — tokens verliepen steeds waardoor "Saxo inloggen" bleef verschijnen:
  - `refreshToken()` merged nu oude token-velden met nieuwe response — `refresh_token` en `refresh_token_expires_in` gaan niet meer verloren bij refresh
  - Proactieve token refresh bij 50% lifetime (was: pas < 2 min voor expiry) — voorkomt race conditions
  - Als proactieve refresh faalt, wordt bestaande geldige access token gewoon gebruikt
  - Retry (2x) bij server errors (5xx) in token refresh
  - HTTP 4xx errors correct afgehandeld (was: alleen >= 500)
  - Dashboard zet `saxoAuthenticated` niet meer op `false` als `getPositions()` null retourneert (API down ≠ niet ingelogd)

---

## 2026-02-27 — MCP v2.0

### Added
- **12 nieuwe MCP tools** (totaal nu 18):
  - `mido_portfolio_snapshot` — Live posities met gewichten, P/L, drift vs target
  - `mido_cash_overview` — Cash per platform, open orders, dry powder breakdown
  - `mido_currency_exposure` — FX-exposure, EUR vs non-EUR split
  - `mido_performance_history` — Portfolio waarde over tijd, TWR berekening
  - `mido_attribution` — Return attributie per positie/asset class/platform/geografie
  - `mido_risk_metrics` — Volatiliteit, Sharpe, Sortino, VaR/CVaR, max drawdown
  - `mido_stress_test` — 5 preset scenario's (crash, rate hike, stagflatie) + custom
  - `mido_cost_analysis` — Transactiekosten + TER per positie, total cost ratio
  - `mido_fundamentals` — P/E, dividend yield, AUM via Yahoo Finance
  - `mido_fund_lookthrough` — Top holdings, sector/geografie breakdown per ETF
  - `mido_rebalance_advisor` — Concrete koop/verkoop orders met FBI-waarschuwingen
  - `mido_scenario_planner` — Monte Carlo simulatie (1000 runs) + milestones
- 4 nieuwe service classes: McpPortfolioService, McpPerformanceService, McpRiskService, McpPlanningService
- TER per target positie in mido_v65.yaml
- Static look-through data voor NT World en NT EM funds in mido_v65.yaml

---

## 2026-02-27

### Fixed
- Saxo auth: access token werd onterecht als ongeldig gezien wanneer refresh token verlopen was, terwijl access token nog uren geldig was (`getValidToken()` logica)
- Dashboard sessie: cookie lifetime van 0 (browser session) naar 7 dagen — voorkomt opnieuw inloggen na deploy
- Saxo cash transacties (dividenden, stortingen, fees) werden met verkeerde velden geïmporteerd — `BookedAmount` i.p.v. `Amount`, `Instrument.Symbol` i.p.v. `InstrumentSymbol`, type-mapping via `Event` veld

### Added
- Open orders tonen nu waarde (CashAmount voor mutual fund orders) in plaats van lege kolom
- Totaal orderwaarde zichtbaar boven orders tabel, met vermelding dat het onderdeel is van Saxo cash
- Cash row in posities tab toont hoeveel er in open orders zit

### Changed
- Orders tabel: "Aantal" kolom vervangen door "Waarde" kolom
- Bearer token authenticatie op MCP endpoints (`/mcp`, `/mcp/info`) — meerdere tokens ondersteund via `MCP_API_TOKENS` env var
- IB/Saxo data timestamps in dashboard header (IB: datum/tijd, Saxo: live status)
- `/health/ib` endpoint voor IB data diagnostiek
- CLAUDE.md: changelog bijhouden is nu verplicht bij elke deploy

---

## 2025-02-26

### Added
- 5 nieuwe Saxo OpenAPI endpoints: closed positions, performance metrics, currency exposure, account values (`2fa6ea4`)
- Saxo cash transacties importeren: dividenden, stortingen, rente (`eefc66d`)
- Ticker codes overal zichtbaar in dashboard (`9eaf735`)

### Fixed
- `saxo_from_buffer` flag werd niet gewist wanneer Saxo authenticated was op cached path (`333846c`)
- Cash posities uitgesloten van ETF momentum scores (`29e62e2`)
- ERNX cross-platform P/L berekening (`9eaf735`)

---

## 2025-02-25

### Added
- Saxo open orders weergave op dashboard (`653bad5`)
- Transactie overzicht gesplitst per platform: IBKR/Saxo tabs (`136a386`)

### Fixed
- Saxo 502 Bad Gateway bij token endpoint graceful afgehandeld (`a04545e`)
- Saxo Direction=None: gebruik TradedValue sign voor buy/sell detectie (`efedc74`)
- Totaal gestort berekening gecorrigeerd (`653bad5`)
- Saxo positie matching verbeterd: strip exchange suffix, ISIN fallback (`8af0d81`)

---

## 2025-02-24

### Fixed
- Saxo tokens persistent in database opgeslagen — overleeft deploys (`0deaf4b`)
- Saxo fund descriptions remapping + fout-geïmporteerde trades verwijderd (`890126f`)
- Sessions + Saxo tokens overleven nu cache:clear na deploys (`24b9aba`)
- Total return formule: realized P/L alleen voor gesloten posities (`a3cb4d6`)
- ClientKey resolven via `/port/v1/clients/me` voor Saxo trade reports (`3e298dd`)
- `position_name` kolom vergroot van VARCHAR(50) naar 255 (`a525220`)
