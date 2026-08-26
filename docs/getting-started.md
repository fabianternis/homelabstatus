# Getting Started

## System Requirements

- **PHP 8.4+** or **PHP 8.5+**
- PHP Extensions: `pdo_sqlite`, `intl`, `mbstring`, `json`, `curl`, `pcntl` (optional, for graceful TUI exit signals)
- **Composer 2.x**
- System `ping` binary accessible in `$PATH`

---

## Quick Start

```bash
# 1. Clone repository
git clone https://github.com/fabianternis/homelabstatus.git
cd homelabstatus

# 2. Install dependencies
composer install

# 3. Clear & Warmup Cache
php bin/console cache:clear

# 4. Start local web server
php -S 0.0.0.0:8080 -t public
```

Now open:
- **Public Dashboard**: [http://localhost:8080](http://localhost:8080)
- **Uplink Monitor**: [http://localhost:8080/uplink](http://localhost:8080/uplink)
- **HTTP Services**: [http://localhost:8080/http](http://localhost:8080/http)
- **Admin Control Center**: [http://localhost:8080/admin](http://localhost:8080/admin)
- **JSON REST API**: [http://localhost:8080/api/v1/uplink](http://localhost:8080/api/v1/uplink)

---

## Launching Terminal User Interface (TUI)

In your terminal or SSH session:
```bash
php bin/console uplink:tui
```

---

## Environment Variables

Configure settings in `.env.local`:

| Variable | Default | Description |
|---|---|---|
| `APP_ENV` | `dev` | Environment mode (`dev` or `prod`) |
| `APP_SECRET` | `auto-generated` | Symfony application secret |
| `UPLINK_DB_PATH` | `%kernel.project_dir%/var/data/homelabstatus.sqlite` | SQLite database file location |
