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
- `APP_SECRET`

Non-secret env vars (safe in `.env`):
- `APP_ENV`, `APP_SHARE_DIR`, `DEFAULT_URI`
- `SAXO_REDIRECT_URI`, `SAXO_AUTH_ENDPOINT`, `SAXO_TOKEN_ENDPOINT`, `SAXO_API_BASE`

### Docker
- Image: `php:8.4-apache`
- DocumentRoot: `public/`
- Build: Dockerfile in project root

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

- `src/Service/SaxoClient.php` — Saxo OAuth2 + positions API
- `src/Service/IbClient.php` — Interactive Brokers Flex API
- `src/Service/MomentumService.php` — ETF momentum rotation strategy
- `src/Controller/DashboardController.php` — Main dashboard
- `src/Controller/SaxoAuthController.php` — Saxo OAuth flow
- `src/Controller/LoginController.php` — Symfony form login
- `templates/dashboard/index.html.twig` — Dashboard template
- `deploy.sh` — Coolify deployment script
- `Dockerfile` — PHP 8.4 Apache image

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
