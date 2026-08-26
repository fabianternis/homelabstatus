# AGENT.md — Developer & AI Assistant Guide

This document provides architectural context, development guidelines, database conventions, and technical specifications for developers and AI coding assistants working on **Homelab Status Engine**.

---

## 1. Project Overview & Philosophy

- **Tech Stack**: PHP 8.5+, Symfony 8.1+, SQLite 3 (WAL mode), Tailwind CSS, Chart.js, Vanilla JS (No heavy SPA frameworks).
- **API-First Architecture**: Every feature available in the Web UI is backed by structured JSON REST APIs and Server-Sent Events (SSE) streaming.
- **Strict Styling Rule**: **DO NOT USE EMOJIS**. Use scalable inline SVGs or clean text badges for all iconography across views, console commands, and documentation.
- **Infinite Retention**: `check_executions` records are stored indefinitely with indexed limit/offset and date-range pagination. Do not introduce automatic pruning or dropping of execution telemetry.

---

## 2. Database & Identity Architecture

### Engine & Configuration
- **SQLite 3** located at `%kernel.project_dir%/var/data/homelabstatus.sqlite` (configurable via `UPLINK_DB_PATH`).
- WAL journal mode (`PRAGMA journal_mode = WAL;`), normal synchronous mode, and foreign keys enabled.

### Primary Keys & Identification
- **ULID Everywhere**: All primary keys across `checks`, `check_executions`, `audit_logs`, `permissions`, and `roles` are **26-character ULIDs** generated via `Symfony\Component\Uid\Ulid::generate()`.
- ULIDs are lexicographically sortable by millisecond creation time and URL-safe.

### Schema Structure
```sql
-- checks: Monitored endpoints, resolvers, and nodes
CREATE TABLE checks (
    id TEXT PRIMARY KEY,                       -- ULID (26 chars)
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL,                        -- 'uplink', 'http', 'icmp_ping', 'tcp', 'dns'
    group_name TEXT NOT NULL DEFAULT 'Uplink Probes',
    description TEXT,
    is_enabled INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'unknown',    -- 'excellent', 'good', 'degraded', 'offline', 'unknown'
    config JSON NOT NULL,                      -- Type-specific dynamic JSON configuration
    interval_sec INTEGER NOT NULL DEFAULT 60,  -- Execution interval in seconds
    last_metrics JSON,                         -- Latest computed telemetry payload
    last_executed_at TEXT,
    last_status_change_at TEXT,
    consecutive_failures INTEGER NOT NULL DEFAULT 0,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    deleted_at TEXT                            -- SoftDeletes timestamp (NULL = active)
);

-- check_executions: Immutable time-series execution history
CREATE TABLE check_executions (
    id TEXT PRIMARY KEY,                       -- ULID (26 chars)
    check_id TEXT NOT NULL,
    status TEXT NOT NULL,
    duration_ms REAL NOT NULL DEFAULT 0,
    result_data JSON NOT NULL,                 -- Type-specific execution payload
    error_message TEXT,
    executed_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (check_id) REFERENCES checks(id) ON DELETE CASCADE
);

-- audit_logs: Spatie-style activity and audit trail
CREATE TABLE audit_logs (
    id TEXT PRIMARY KEY,                       -- ULID (26 chars)
    log_name TEXT NOT NULL DEFAULT 'default',
    subject_type TEXT,
    subject_id TEXT,
    event TEXT NOT NULL,                       -- 'created', 'updated', 'deleted', 'restored', 'status_changed', 'bulk_*'
    causer_type TEXT,
    causer_id TEXT,
    properties JSON,                           -- { "old": {...}, "attributes": {...} }
    description TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
```

---

## 3. Core Subsystems & Service Layers

### Modular Check Runners (`src/Service/Checker/`)
- `CheckerInterface`: Defines `supports(string $type)`, `run(Check $check)`, and `runMulti(array $checks)`.
- `HttpChecker`: Implements parallel `curl_multi` async execution. Captures HTTP response codes, TTFB (Time To First Byte), total response time, DNS lookup time, TCP connect time, TLS/SSL certificate expiry countdown & issuer, and keyword presence verification.
- `SystemPingRunner`: Executes non-blocking concurrent ICMP pings via system `ping` binary across all active uplink targets in `< 1s`.
- `CheckManager`: Routes check execution by `type` and handles execution recording, metric caching, and audit logging on status transitions.

### Dynamic Localization (`src/Service/Locale/LocaleProvider.php`)
- **Runtime Discovery**: Scans `translations/` for `messages.{locale}.*` (`yaml`, `json`, `php`).
- **Native Language Names**: Translates locale codes dynamically via `Symfony\Component\Intl\Languages::getName($code, $code)`.
- **Session Persistence**: Stored and retrieved from `$session->get('_locale')` / `$session->set('_locale')`.

---

## 4. Route Map & Endpoints

### Public Web Dashboard
- `GET /` — Main overview dashboard with unified health cards and all services table.
- `GET /uplink` — Dedicated WAN uplink probing page with Chart.js canvas sparklines and SLA gauge.
- `GET /http` — Dedicated HTTP & SSL certificate services page.

### Public REST API & Streaming
- `GET /api/v1/uplink` — Overall uplink status, composite SLA health score (0–100), and resolver metrics.
- `GET /api/v1/uplink/stream` — Server-Sent Events (SSE) live metric stream (`text/event-stream`).
- `POST /api/v1/uplink/ping` — Trigger on-demand parallel uplink probe run.
- `GET /api/v1/uplink/history` — Time-series history and Unicode sparklines dataset.
- `GET /api/v1/http` — Monitored HTTP endpoints with latest status codes and SSL details.
- `POST /api/v1/http/check` — Trigger on-demand parallel HTTP check run.
- `GET /api/v1/health` — System and database connectivity health probe.

### Admin Web & REST API
- `GET /admin` — Admin control center overview.
- `GET /admin/checks` — Checks management with mass-actions toolbar and active/trash filters.
- `GET /admin/checks/create` & `POST /admin/checks/create` — Create check with dynamic JSON configuration.
- `GET /admin/checks/{id}/edit` & `POST /admin/checks/{id}/edit` — Edit check parameters.
- `POST /admin/checks/{id}/toggle` — Enable / disable a check.
- `POST /admin/checks/{id}/delete` — Soft-delete check.
- `POST /admin/checks/{id}/restore` — Restore check from trash.
- `POST /admin/checks/{id}/force-delete` — Permanently destroy check.
- `POST /admin/checks/bulk-action` — Mass-action execution (`enable`, `disable`, `trash`, `restore`, `force_delete`).
- `GET /admin/executions` — Paginated execution history explorer.
- `GET /admin/audit-logs` — Activity trail and property diff viewer.
- `GET /api/v1/admin/checks` — JSON checks listing.
- `POST /api/v1/admin/checks/bulk` — JSON bulk operation endpoint.

---

## 5. Console & CLI Commands

```bash
# Live Curses-Style ANSI Terminal Dashboard
php bin/console uplink:tui [-i 3] [-p 2]

# Probing & Scripting
php bin/console uplink:ping [--json] [--loop=5]
php bin/console check:http [--json] [--loop=5]

# Check Administration
php bin/console check:list [--type=uplink|http] [--trashed] [--json]
php bin/console check:create <name> <host/url> [--type=uplink|http] [--interval=60] [--group="..."]
php bin/console check:toggle <id|slug>
php bin/console check:delete <id|slug> [--force]
php bin/console check:restore <id|slug>

# Audit Trail
php bin/console audit:list [--limit=25] [--json]
```

---

## 6. Development & Coding Guidelines

1. **Strict Types**: Always place `declare(strict_types=1);` at the top of every PHP file.
2. **Icons**: Use SVG elements with Tailwind utility classes (`w-4 h-4 text-emerald-400`). **Never use Unicode emojis**.
3. **Changed-Only DOM Highlights**: When updating client-side dashboard elements, diff the incoming value against `el.textContent` and apply the CSS animation class `.live-updated` only to altered elements.
4. **Privacy Protection**: Keep the client/WAN IP address wrapped in `.blur-ip` (CSS `filter: blur(5px)`) so it is blurred by default and only revealed on hover.
5. **Page Visibility**: SSE connections must disconnect when `document.hidden === true` and reconnect on focus.
6. **Tests**: Always run `vendor/bin/phpunit` to ensure all unit and feature tests pass before committing.
