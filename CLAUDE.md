# CLAUDE.md

This file contains quick reference commands, architectural patterns, and code standards for working on **Homelab Status Engine**.

---

## 1. Common Development Commands

```bash
# Install dependencies
composer install

# Clear & warm up Symfony cache
php bin/console cache:clear

# Run test suite (PHPUnit 13)
vendor/bin/phpunit

# Run single test file
vendor/bin/phpunit tests/Feature/HttpCheckerTest.php

# Start local development server
php -S 0.0.0.0:8080 -t public

# Launch Interactive Terminal User Interface (TUI)
php bin/console uplink:tui

# Execute Probing Snapshots
php bin/console uplink:ping           # Probe active uplink targets
php bin/console uplink:ping --json    # Raw JSON snapshot
php bin/console check:http            # Probe HTTP endpoints & check SSL certs

# Check & Audit Administration
php bin/console check:list            # List checks (supports --type=http|uplink, --trashed)
php bin/console check:create <name> <host/url> [--type=http|uplink] [--interval=60] [--group="..."]
php bin/console check:toggle <id|slug>
php bin/console check:delete <id|slug> [--force]
php bin/console check:restore <id|slug>
php bin/console audit:list            # View recent audit trail
```

---

## 2. Architecture & Project Layout

- `src/Entity/`: Data entities (`Check`, `CheckExecution`, `AuditLog`).
- `src/Repository/`: PDO-based SQLite data repositories with WAL mode.
- `src/Service/Checker/`: Extensible check runner engine (`CheckerInterface`, `HttpChecker`, `CheckManager`).
- `src/Service/Ping/`: Concurrent ICMP probe runner (`SystemPingRunner`).
- `src/Service/Locale/`: Dynamic runtime translation discovery (`LocaleProvider`).
- `src/Controller/`: Web controllers (`HomeController`, `AdminController`).
- `src/Controller/Api/`: REST & SSE API controllers (`UplinkApiController`, `HttpApiController`, `AdminCheckApiController`).
- `src/Command/`: CLI & interactive Curses-style TUI commands.
- `templates/`: Twig views partitioned into `templates/home/` and `templates/admin/`.
- `translations/`: Localization files (`messages.en.yaml`, `messages.de.yaml`, `messages.fr.yaml`).
- `docs/`: Technical documentation and `docs/docs.en.yaml`.
- `public/`: Public web root, PWA assets (`manifest.json`, `sw.js`, `favicon.svg`).

---

## 3. Key Design & Data Conventions

### Strict Emojis Rule
- **Never use emojis** in code, views, console outputs, or documentation. Use clean, scalable **inline SVGs** with Tailwind CSS utility classes.

### Primary Keys & Identity
- All database primary keys are **26-character ULIDs** generated with `Symfony\Component\Uid\Ulid::generate()`.

### Dynamic JSON Columns
- `checks.config`: Stores target connection parameters (host, port, timeout, packets, expected_status, headers, check_ssl, keyword).
- `checks.last_metrics`: Cached latest execution summary for instant dashboard rendering.
- `check_executions.result_data`: Full telemetry payload for time-series inspection.
- `audit_logs.properties`: Spatie-style `{ "old": {...}, "attributes": {...} }` property diffs.

### Soft Deletion & Audit Trail
- Soft deletes are handled via `checks.deleted_at`.
- All check modifications, status transitions, creations, and bulk actions are recorded in `audit_logs`.

### Infinite History Policy
- `check_executions` are preserved **indefinitely** without automatic pruning. Always use paginated queries (`CheckExecutionRepository::paginate()`).

---

## 4. Frontend & UX Conventions

- **Granular Changed-Only Flashing**: Only DOM elements whose content actually changed during an update should trigger the `.live-updated` pulse highlight animation.
- **Privacy First**: The WAN/client IP address must remain blurred (`.blur-ip` CSS filter) by default and only unblur on hover.
- **Page Visibility**: SSE connections (`/api/v1/uplink/stream`) must close when `document.hidden === true` to conserve resources, and reconnect on focus.
- **Chart.js Micro-Sparklines**: Latency charts use cubic bezier curves (`tension: 0.4`) and update in-place with `chart.update('none')`.

---

## 5. Coding Standards

- Always declare strict types: `declare(strict_types=1);` on all PHP files.
- Follow PSR-12 and Symfony coding standards.
- Run `vendor/bin/phpunit` before committing any changes.
