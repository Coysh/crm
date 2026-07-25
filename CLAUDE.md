# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A self-hosted CRM for Coysh Digital (freelance web dev / digital consultancy). **Low-maintenance by design** — set up once, updated only when client details change. No timers/time tracking (a lightweight manual SLA work log is the only hours record). Provides an overview of each client's technical setup, billing, agreements/SLAs, and P&L, plus an MCP endpoint so Claude (web/app) can query and update it.

## Commands

```bash
# Start development server (from project root)
php -S localhost:8080 -t public/

# Run database migrations
php scripts/migrate.php

# Seed sample data
php scripts/seed.php

# Install PHP dependencies
composer install

# Sync scripts (cron-friendly, bootstrap independently)
php scripts/ploi-sync.php
php scripts/freeagent-sync.php
php scripts/cloudflare-sync.php
php scripts/exchange-rates-sync.php

# Build Tailwind CSS (dev, with watch)
npx tailwindcss -i public/css/app.css -o public/css/app.css --watch

# Build Tailwind CSS (production)
npx tailwindcss -i public/css/app.css -o public/css/app.css --minify
```

No test suite currently. There are no linting commands configured.

## Tech Stack

- **Backend:** PHP 8.2+, no framework — Bramus/Router
- **Database:** SQLite, stored at `data/crm.db` (gitignored). `PRAGMA foreign_keys = ON`, `busy_timeout = 5000` (web, cron, and MCP can write concurrently).
- **Frontend:** Tailwind CSS + vanilla JS (`fetch()` for AJAX, no frameworks)
- **Auth:** Single-user login with password + mandatory TOTP 2FA. Enforced by a
  global `before` guard in `src/routes.php` covering **all HTTP verbs**; flow lives in
  `AuthController` and `src/Views/auth/`. Sessions use HttpOnly/SameSite (Secure under
  HTTPS) cookies with idle/absolute timeouts. First run redirects to `/setup`.
  Guard-exempt paths: auth routes, static assets, `/.well-known/*`, `/mcp`,
  `/oauth/register`, `/oauth/token` (the latter three use bearer/PKCE auth instead).
  `/oauth/authorize` + `/oauth/approve` deliberately stay behind the session guard.
- **Secrets at rest:** API tokens (FreeAgent/Ploi/Cloudflare) and TOTP seeds are
  encrypted via `Services\Secrets` (libsodium). Key from `APP_KEY` env, falling back
  to `data/app.key` (auto-created, gitignored). Encrypted values are prefixed
  `enc:v1:`; decrypt tolerates legacy plaintext.
- **Env vars:** `APP_ENV` (production toggles), `APP_KEY`, `APP_URL`
  (canonical base URL, e.g. `https://crm.coysh.digital` — required for a stable
  OAuth issuer; falls back to the request Host). No dotenv loader — set them in
  the server/FPM environment.

## Architecture

Request flow: `public/index.php` (front controller) → `src/bootstrap.php` (DB + helpers) → `src/routes.php` → `src/Controllers/` → `src/Models/` → `src/Views/`

- Controllers handle HTTP requests and delegate to models; no base controller class
- Models extend `Models\Model` (raw PDO, no ORM)
- Views are plain PHP templates; `src/Views/layouts/main.php` wraps app pages, `layouts/auth.php` wraps login/consent pages
- Migrations are numbered SQL files in `migrations/` (currently 001–028) run in order by `scripts/migrate.php`, tracked in `_migrations`
- Shared helpers live in `src/bootstrap.php`: `render()`, `redirect()`, `flash()`, `csrfToken/csrfField/csrfCheck()`, `e()`, `money()`, `formatCurrency()`, `formatDate()`, `statusBadge()`, `healthFlagLabel()`, `appUrl()`
- Column feature-detection via `try { SELECT col LIMIT 0 } catch` is used to tolerate partially-migrated DBs — follow the same pattern for new columns

## Data Model Key Points

**Revenue (read-only, synced):** `freeagent_recurring_invoices` is the source of truth for recurring revenue (the old `service_packages` table was dropped in migration 008). `Client::getMRR()`/`FreeAgentRecurringInvoice::monthlySql()` normalise per-frequency values to monthly using **net (ex-VAT)** values: `COALESCE(net_value, total_value)`. Invoice-based revenue aggregates also use net; "outstanding" figures stay gross. Manual invoice status overrides (`freeagent_invoices.status_override`) are respected via `COALESCE(status_override, status)`.

**Costs:** `recurring_costs` (+ `recurring_cost_clients` junction) with three apportionment paths, calculated dynamically, never stored:
- Server-linked: `monthly_eq / COUNT(DISTINCT clients on that server)`
- Per-client junction: `monthly_eq / COUNT(DISTINCT linked clients)`
- Per-site junction: `monthly_eq / total linked sites × client's linked sites`
- Domain cost per client = `domain.annual_cost / 12` (FX-converted)

**Per-client P&L:** `Client::getPL()` (one client) and `Client::getPLAll()` (all active clients in a few grouped queries — use this on list pages/dashboard; pass its result to `Client::getHealthAll($plAll)` to avoid recomputation).

**Agreements/SLAs (migration 026):** `agreements` covers both hours-based SLAs and build agreements with bundled cover:
- `agreement_type`: `support` | `build_bundled` | `consultancy` | `other`
- `status`: `active` | `expired` | `cancelled`
- Coverage flags: `covers_hosting/support/maintenance`
- `included_hours` (nullable = no allowance) + `hours_period` (`monthly|quarterly|annually`), allowance window anchored to `start_date` (`Agreement::currentPeriodStart()`)
- `agreement_work_log`: lightweight manual entries (date, hours, description)
- Optional `freeagent_recurring_invoice_id` links the fee to synced revenue
- PDFs go through `client_attachments` (type `agreement`, optional `agreement_id`)

**Health flags** (`Client::getHealth/getHealthAll`): `loss_making`, `no_retainer`, `no_recent_invoice`, `overdue_invoices`, `incomplete_setup`, `no_agreement` (support/consultancy types only; satisfied by an active agreement OR legacy `agreement_notes`), `agreement_renewal_overdue`, `hours_exhausted`. Labels via `healthFlagLabel()`.

**Renewals:** `Services\Renewals::fetch($days, $type, $clientId)` unions domains, recurring costs, recurring invoices, and agreement renewal dates — used by the dashboard, `/renewals`, insights, and MCP.

**Enum-style TEXT columns** (enforce at app layer, not DB):
- `clients.status`: `active` | `archived`; `clients.client_type`: `managed` | `support_only` | `consultancy_only`
- `projects.income_category`: `web_design` | `web_development` | `consultancy` | `hosting` | `email_hosting` | `domain`
- `projects.status`: `active` | `completed` | `cancelled`
- `expenses.billing_cycle`: `one_off` | `monthly` | `annual`
- `recurring_costs.billing_cycle`: `monthly` | `annual`

## Integrations

All optional — core CRM works without them. Config in per-integration tables (`ploi_config`, `cloudflare_config`, `freeagent_config`), tokens encrypted.

- **FreeAgent** (OAuth2): contacts, invoices (incl. `net_value`/`sales_tax_value`), recurring invoices, bills, bank transactions. `Services\FreeAgentClient` + `FreeAgentSync`.
- **Ploi** (read-only): servers/sites mirrored into `ploi_servers`/`ploi_sites` by Ploi numeric ID. Records deleted in Ploi are marked `is_stale = 1` and skipped by later syncs (purge them from `/settings/ploi`). `ploi_sync_exclusions` (sites) and `ploi_server_exclusions` (servers) stop deleted records re-importing. Sync errors log to `ploi_sync_log`; the settings page shows only undismissed failures newer than the last successful full sync.
- **Cloudflare:** zones/DNS mirrored, matched to `domains` by name.

## MCP Server (migration 028)

Remote MCP endpoint for Claude web/app custom connectors: `POST /mcp` — Streamable HTTP, POST-only JSON (no SSE), stateless. `McpController` (transport/JSON-RPC) + `Services\McpTools` (tool schemas + dispatch). Read tools: `list_clients`, `get_client`, `get_client_pl`, `list_agreements`, `get_agreement`, `list_agreement_work`, `list_renewals`, `list_domains`, `business_summary`. Write tools (no deletes/edits): `log_agreement_work`, `add_client_note`.

Auth is OAuth 2.1 (`OAuthController` + `Services\OAuthService`): discovery at `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource`, anonymous DCR at `/oauth/register` (https redirect URIs only), consent at `/oauth/authorize`/`/oauth/approve` behind the CRM login+TOTP, `/oauth/token` with mandatory PKCE S256, 1h access + 30d refresh tokens (sha256-hashed at rest), refresh rotation with family-wide revocation on reuse. Unauthenticated `/mcp` returns 401 + `WWW-Authenticate: Bearer resource_metadata=...` (never a redirect). Rate limiting via `mcp_request_log`. Manage/revoke connected apps at `/settings/mcp`.

Deployment notes: set `APP_URL`; ensure the web server doesn't intercept `/.well-known/` or strip the `Authorization` header.

## Design Guidelines

- Slate/gray Tailwind palette with a single accent colour
- Compact tables, simple forms — no decorative elements, no gradients
- Desktop-first but mobile-friendly
- `prose` class for long-text areas

## Conventions for New Code

- New browser-form POST endpoints must call `csrfCheck()` and render `csrfField()` in their forms (legacy forms predate this; `/mcp`, `/oauth/token`, `/oauth/register` are correctly CSRF-exempt — no session semantics)
- Never echo decrypted secrets into HTML (masked placeholder + empty value instead)
- Next migration number: 029
