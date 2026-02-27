# Changelog — MidoDash

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
