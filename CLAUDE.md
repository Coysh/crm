# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

A self-hosted CRM for Coysh Digital (freelance web dev / digital consultancy). **Low-maintenance by design** — set up once, updated only when client details change. No time tracking. Provides an overview of each client's technical setup, billing, and P&L.

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

# Build Tailwind CSS (dev, with watch)
npx tailwindcss -i public/css/app.css -o public/css/app.css --watch

# Build Tailwind CSS (production)
npx tailwindcss -i public/css/app.css -o public/css/app.css --minify
```

No test suite currently. There are no linting commands configured.

## Tech Stack

- **Backend:** PHP 8.2+, no framework — uses a slim router (e.g. Bramus/Router)
- **Database:** SQLite, stored at `data/crm.db` (gitignored)
- **Frontend:** Tailwind CSS + vanilla JS (`fetch()` for AJAX, no frameworks)
- **Auth:** Single-user login with password + mandatory TOTP 2FA. Enforced by a
  global `before` guard in `src/routes.php`; flow lives in `AuthController` and
  `src/Views/auth/`. Sessions use HttpOnly/SameSite (Secure under HTTPS) cookies
  with idle/absolute timeouts. First run redirects to `/setup`.
- **Secrets at rest:** API tokens (FreeAgent/Ploi/Cloudflare) and TOTP seeds are
  encrypted via `Services\Secrets` (libsodium). Key from `APP_KEY` env, falling back
  to `data/app.key` (auto-created, gitignored). Encrypted values are prefixed
  `enc:v1:`; decrypt tolerates legacy plaintext. Run `scripts/encrypt-existing-secrets.php`
  once to migrate existing plaintext tokens.
- **Accounting:** FreeAgent API (OAuth2) — optional, final phase

## Architecture

Request flow: `public/index.php` (front controller) → `src/bootstrap.php` (DB + autoload) → `src/routes.php` → `src/Controllers/` → `src/Models/` → `src/Views/`

- Controllers handle HTTP requests and delegate to models
- Models interact with SQLite via PDO
- Views are plain PHP templates; `src/Views/layouts/main.php` wraps all pages
- Migrations are numbered SQL files in `migrations/` run in order by `scripts/migrate.php`
- No ORM — raw PDO queries

## Data Model Key Points

**Relationships:** clients → domains, client_sites (links client + domain + server), service_packages, projects, expenses

**Cost apportionment** (calculated dynamically, never stored):
- Server cost per client = `server.monthly_cost / count(active client_sites on that server)`
- Domain cost per client = `domain.annual_cost / 12`

**Per-client P&L:**
- Revenue = monthly service packages + project income (period)
- Costs = apportioned server cost + direct expenses + apportioned domain costs
- Profit = Revenue − Costs

**Billing normalisation:** annual fees ÷ 12 to compare with monthly figures

**Enum-style TEXT columns** (enforce at app layer, not DB):
- `clients.status`: `active` | `archived`
- `service_packages.billing_cycle`: `monthly` | `annual`
- `projects.income_category`: `web_design` | `web_development` | `consultancy` | `hosting` | `email_hosting` | `domain`
- `projects.status`: `active` | `completed` | `cancelled`
- `expenses.category`: `domain_registration` | `email_hosting` | `hosting_costs` | `plugin_licenses`
- `expenses.billing_cycle`: `one_off` | `monthly` | `annual`

## FreeAgent Integration

Build as a **separate, optional module** — core CRM must work without it. Tokens stored in `freeagent_config` table. Auto-refresh before expiry. Category mappings in `freeagent_category_mappings` table. Implement last.

## Design Guidelines

- Slate/gray Tailwind palette with a single accent colour
- Compact tables, simple forms — no decorative elements, no gradients
- Desktop-first but mobile-friendly
- `prose` class for long-text areas

## Build Order

1. Migrations + seed data
2. Clients + Servers (CRUD)
3. Domains, client_sites, service_packages
4. Projects + Expenses
5. Dashboard (requires all underlying data)
6. FreeAgent integration (final phase)
