# CLAUDE.md — Sition Development Standards

## Project Context

- **Agency**: Sition (part of Strix Group) — e-commerce & web development agency, 30 developers
- **Project**: MidoDash — Investment portfolio dashboard for MIDO Holding B.V.
- **Stack**: Symfony 8, PHP 8.4+, Twig, Docker/Coolify deployment
- **APIs**: Interactive Brokers (Flex), Saxo Bank (OpenAPI), Yahoo Finance
- **Coding style**: PSR-12, `declare(strict_types=1)` in every file
- **Test framework**: PHPUnit (unit + integration)
- **Package manager**: Composer (PHP)
- **CI/CD**: GitHub Actions
- **Deployment**: Coolify via `./deploy.sh` on https://mido.barcelona2.doens.nl

> **Multi-project support**: This is the root CLAUDE.md with universal rules. For monorepos or multi-module projects, add a CLAUDE.md per directory to override or extend these rules.

---

## Deployment

### Coolify
- **Dashboard**: https://coolify.barcelona2.doens.nl
- **App UUID**: `mw0ks0s8sc8cw0csocwksskk`
- **Server**: `r0wg4g40c0kscg44wg0woogw` (localhost)
- **GitHub repo**: https://github.com/mdoens/midodash (public)

### Deploy Commands
```bash
# One-command deploy (commit + push + build + wait)
./deploy.sh "feat: my change"

# Local test first
php -S localhost:8080 -t public/

# Manual Coolify deploy via API
curl -X POST -H "Authorization: Bearer $COOLIFY_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"uuid":"mw0ks0s8sc8cw0csocwksskk"}' \
  https://coolify.barcelona2.doens.nl/api/v1/deploy
```

### Environment Variables & Secrets
All secrets are stored in two places — NEVER in git:
1. **`.env.local`** (gitignored) — for local development
2. **Coolify env vars** — for production

Secret env vars:
- `IB_TOKEN`, `IB_QUERY_ID`
- `SAXO_APP_KEY`, `SAXO_APP_SECRET`
- `DASHBOARD_PASSWORD_HASH`
- `COOLIFY_TOKEN` (used by `deploy.sh`, stored in `.env.local`)
- `MCP_API_TOKENS` (comma-separated bearer tokens for MCP endpoints)
- `APP_SECRET`

Non-secret env vars (safe in `.env`):
- `APP_ENV`, `APP_SHARE_DIR`, `DEFAULT_URI`
- `SAXO_REDIRECT_URI`, `SAXO_AUTH_ENDPOINT`, `SAXO_TOKEN_ENDPOINT`, `SAXO_API_BASE`

### Docker
- Image: `php:8.4-apache`
- DocumentRoot: `public/`
- Build: Dockerfile in project root
- Volume: `midodash-var` → `/var/www/html/var` (tokens, sessions, cache persist across deploys)
- Cron: sources `/etc/midodash-env.sh` for env vars (Docker cron has no access to container env vars)
- Entrypoint: `docker-entrypoint.sh` — force-removes stale Twig cache, runs migrations, warmup, then Apache
- Database: MySQL in production (Coolify `DATABASE_URL`), SQLite locally (`var/data/mido.sqlite`)

### Known Docker Pitfalls
- Compiled Twig templates on Docker volume survive deploys — `docker-entrypoint.sh` force-removes `var/cache/prod/twig`
- Cron jobs fail silently without env vars — always source `/etc/midodash-env.sh`
- Cron log directory (`var/log/`) must exist on Docker volume — entrypoint creates it via `mkdir -p`
- Saxo tokens must be on persistent volume AND in database (dual-write via `DataBufferService`)

### Coolify API — Log Access
Container logs ophalen via Coolify API (token in `.env.local` als `COOLIFY_TOKEN`):
```bash
# Runtime logs (last hour)
curl -s -H "Authorization: Bearer $COOLIFY_TOKEN" \
  "https://coolify.barcelona2.doens.nl/api/v1/applications/mw0ks0s8sc8cw0csocwksskk/logs?since=3600"

# Deployment logs
curl -s -H "Authorization: Bearer $COOLIFY_TOKEN" \
  "https://coolify.barcelona2.doens.nl/api/v1/deployments"

# Filter logs (pipe through python/jq)
curl -s ... | python3 -c "import sys,json; [print(l[:200]) for l in json.load(sys.stdin)['logs'].split('\n') if 'saxo' in l.lower()]"
```
Nuttig voor debugging zonder SSH — Coolify API docs: `https://coolify.barcelona2.doens.nl/docs/api-reference`

---

## Autonomy Level: Maximum

You are a senior PHP/Symfony developer. Act like one. Do not ask for permission, clarification, or confirmation unless you hit an explicit "Always Ask First" boundary listed below. Default behavior is: figure it out and do it.

### Operating Principles
- **Bias to action**: When in doubt, act. Fix it, build it, ship it.
- **No hand-holding**: Never ask "should I proceed?" or "would you like me to...?" — just do it.
- **Chain tasks**: When you finish one step, immediately continue to the next.
- **Self-unblock**: If stuck, read source code, check logs, try alternatives. Exhaust options before escalating.
- **Complete the loop**: Every change must be implemented, tested, verified, committed. Never stop halfway.
- **Think before complex work**: For architectural decisions — reason through step by step BEFORE writing code.

### Only Ask First When
- Database migrations or schema changes on production data
- Adding/removing `composer.json` dependencies
- Changes to payment, checkout, or order logic
- Modifying public API contracts used by external systems
- Anything involving credentials, secrets, or security policies
- Deleting files, branches, or data that cannot be recovered

### Everything Else: Just Do It

---

## Auto-Testing: Test After Every Change

### After Every PHP File Change
```bash
php -l [changed-file.php]
./vendor/bin/phpstan analyse [changed-file.php] --level=max
./vendor/bin/phpunit --filter=[RelatedTestClass]
```

### After Every Feature/Fix Completion
```bash
php -l src/**/*.php
./vendor/bin/phpstan analyse src/ --level=max
./vendor/bin/phpunit --testsuite Unit
php bin/console cache:clear
```

### Test-Driven Bug Fixing
1. Write a failing test FIRST
2. Fix the bug
3. Confirm the test passes
4. Run full test suite
5. Commit test and fix together

### If Tests Fail After Your Change
- Analyze and fix it yourself — don't ask the user
- If your fix broke existing tests, your fix is wrong — undo and try differently
- After 3 failed attempts: stop, present full diagnostics, ask for direction

---

## Key Files

### Controllers
- `src/Controller/DashboardController.php` — Main dashboard + health checks (`/health/returns`, `/health/saxo`, etc.)
- `src/Controller/McpController.php` — MCP server endpoint (18 tools, bearer token auth)
- `src/Controller/SaxoAuthController.php` — Saxo OAuth2 flow with post-exchange verification
- `src/Controller/LoginController.php` — Symfony form login

### Core Services
- `src/Service/SaxoClient.php` — Saxo OAuth2 + positions API (proactive refresh at 50% lifetime, token merge, dual-write persistence)
- `src/Service/IbClient.php` — Interactive Brokers Flex API
- `src/Service/PortfolioService.php` — Allocation engine with open orders matching + PENDING status
- `src/Service/ReturnsService.php` — P/L calculation, dividends, total return
- `src/Service/MomentumService.php` — ETF momentum rotation strategy
- `src/Service/DataBufferService.php` — API response caching + fallback when APIs unavailable
- `src/Service/PortfolioSnapshotService.php` — Daily portfolio snapshots for history charts

### MCP Services (v2.0)
- `src/Service/Mcp/McpPortfolioService.php` — Portfolio snapshot + cash overview (Saxo buffer fallback OUTSIDE catch block)
- `src/Service/Mcp/McpPerformanceService.php` — Performance history + return attribution
- `src/Service/Mcp/McpRiskService.php` — Risk metrics + stress testing
- `src/Service/Mcp/McpPlanningService.php` — Cost analysis, fundamentals, rebalance advisor, scenario planner

### Config & Deploy
- `config/mido_v65.yaml` — Strategy v8.0 config (targets, asset classes, momentum, symbol_map, TER, look-through)
- `templates/dashboard/index.html.twig` — Dashboard template (all `render_chart` calls have null guards)
- `docker-entrypoint.sh` — Startup: env export, Twig cache cleanup, migrations, warmup
- `Dockerfile` — PHP 8.4 Apache image with cron (sources `/etc/midodash-env.sh`)
- `deploy.sh` — Coolify deployment script

---

## MCP Server

### Endpoints
- `POST /mcp` — MCP JSON-RPC requests
- `GET /mcp` — MCP SSE stream for server notifications
- `DELETE /mcp` — Terminate MCP session
- `GET /mcp/info` — Server info page

### Authentication
- Bearer token via `Authorization: Bearer <token>` header
- Tokens configured via `MCP_API_TOKENS` env var (comma-separated for multiple users)
- Empty `MCP_API_TOKENS` = auth disabled (backwards compatible)
- Tokens stored in `.env.local` (local) and Coolify env vars (production)

### Tools (18)

**Macro & Strategy** (6):
- `mido_macro_dashboard`, `mido_indicator`, `mido_triggers`, `mido_crisis_dashboard`, `mido_drawdown_calculator`, `mido_momentum_rebalancing`

**Portfolio & Cash** (3):
- `mido_portfolio_snapshot` — Live positions, drift, P/L, data freshness
- `mido_cash_overview` — Cash per platform, open orders, dry powder
- `mido_currency_exposure` — FX exposure breakdown

**Performance** (2):
- `mido_performance_history` — TWR, benchmark comparison
- `mido_attribution` — Return attribution per position/class/platform/geo

**Risk** (2):
- `mido_risk_metrics` — Volatility, Sharpe, Sortino, VaR/CVaR
- `mido_stress_test` — 5 preset scenarios + custom shocks

**Planning** (5):
- `mido_cost_analysis`, `mido_fundamentals`, `mido_fund_lookthrough`, `mido_rebalance_advisor`, `mido_scenario_planner`

All tools support `format: 'markdown'|'json'`. Services in `src/Service/Mcp/`.

### Claude Desktop Configuration
Claude Desktop gebruikt `mcp-remote` als stdio proxy voor remote HTTP servers.
Installeer eerst: `npm install -g mcp-remote`

Config in `~/Library/Application Support/Claude/claude_desktop_config.json`:
```json
{
  "mido": {
    "command": "/opt/homebrew/bin/mcp-remote",
    "args": [
      "https://mido.barcelona2.doens.nl/mcp",
      "--header", "Authorization: Bearer <MCP_TOKEN>"
    ]
  }
}
```
Let op: gebruik het volledige pad naar `mcp-remote`, niet `npx` — dat is te traag en geeft timeouts in Claude Desktop.

### Claude Code Configuration
```bash
claude mcp add --transport http mido https://mido.barcelona2.doens.nl/mcp \
  --header "Authorization: Bearer <MCP_TOKEN>"
```

---

## Workflow Orchestration

### 1. Plan Mode Default
- Enter plan mode for ANY non-trivial task (3+ steps or architectural decisions)
- If something goes sideways, re-plan immediately

### 2. Subagent Strategy
- Use subagents to keep main context window clean
- One task per subagent for focused execution

### 3. Verification Before Done
- Never mark a task complete without proving it works
- Run tests, check logs, demonstrate correctness
- Always test locally with `php -S localhost:8080 -t public/` before deploying

### 4. Self-Improvement Loop
- After ANY correction from the user: update `tasks/lessons.md`
- Write rules to prevent the same mistake

---

## Git Conventions

### Commits
- Use Conventional Commits: `feat:`, `fix:`, `refactor:`, `chore:`, `test:`, `docs:`
- Commit after every meaningful step — small atomic commits
- Never amend or force-push shared branches

### Changelog
- Maintain `CHANGELOG.md` in the project root
- Update the changelog with EVERY deploy — this is mandatory, not optional
- Format: group entries by date, use `### Added`, `### Fixed`, `### Changed`, `### Removed` sections
- Write entries in Dutch (user's language), concise but clear
- Include the git commit hash for traceability

---

## Symfony Rules

### Mandatory
- Follow Symfony best practices and directory structure
- Services autowired by default — use constructor injection
- Environment-specific config via `.env` files and `%env()%` parameter syntax
- Use `#[Route]` attributes for routing
- Use Voters for authorization logic
- Use Symfony Console Commands (`#[AsCommand]`) for CLI tasks

### Verification Commands
```bash
php -l src/**/*.php
./vendor/bin/phpstan analyse src/ --level=max
php bin/console lint:container
php bin/console lint:twig templates/
php bin/console lint:yaml config/
php bin/console cache:clear
```

---

## Forbidden Patterns

### PHP
- NEVER use mixed or untyped parameters — strict types everywhere
- NO `die()`, `exit()`, `var_dump()`, or `dd()` in committed code
- NO hardcoded credentials — use `.env` variables
- NEVER use `@suppress` or `@phpstan-ignore` without documenting why
- NO `__construct` property promotion without `readonly` keyword

### Security — CRITICAL
- **NEVER commit secrets, API keys, tokens, or passwords to git** — not in `.env`, not in any file
- Secrets belong ONLY in `.env.local` (gitignored) for local dev and in Coolify env vars for production
- The `.env` file in git may ONLY contain empty placeholders (e.g. `IB_TOKEN=`)
- Before every commit, verify no secrets are staged: check `.env`, config files, and any new files
- If secrets are accidentally committed, immediately remove them from git history (force push) and rotate the compromised credentials

### Symfony
- NO business logic in controllers — use services
- NO service locator pattern — use dependency injection
- NEVER call `$container->get()` outside of tests
- NO direct `$_GET`, `$_POST`, `$_SESSION` access — use Request object

### Git
- NEVER push directly to `main` without testing locally first
- NEVER force-push to shared branches

---

## Error Handling & Escalation

- After 3 failed attempts at the same fix, stop and present full diagnostics
- If tests fail after a fix: analyze it yourself, don't ask the user
- When uncertain about framework internals: check vendor source code directly
- For production incidents: prioritize stability over elegance — hotfix first, refactor later

---

## Core Principles

- **Simplicity First**: Make every change as simple as possible.
- **No Laziness**: Find root causes. No temporary fixes. Senior developer standards.
- **Minimal Impact**: Changes should only touch what's necessary.
- **Prove It Works**: Every change must be verified. No "it should work" — show that it does.
- **Maximum Autonomy**: Act first, ask only at hard boundaries. The user's time is sacred.
