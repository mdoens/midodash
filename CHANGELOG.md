# Changelog — MidoDash

## 2026-02-28

### Fixed
- **MCP portfolio Saxo fallback** — buffer fallback zat in catch-block maar getPositions() returnt null zonder exception. Nu als aparte check na try/catch
- **Open orders in platform split** — open order waarde meegeteld in Saxo cash (MCP + Dashboard)
- **Cron env vars** — Docker cron had geen toegang tot env vars. Nu als export statements via /etc/midodash-env.sh
- **Template null check** — history_chart en allocation_chart null check voorkomt crash bij health render

### Added
- **PENDING status** voor posities met open orders — onderdrukt valse REBAL/ONTBREEKT waarschuwingen
- **Saxo data waarschuwing** in platform verdeling als Saxo data ontbreekt
- **Auth verificatie** na Saxo login in callback — diagnostisch flash message bij falen

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
