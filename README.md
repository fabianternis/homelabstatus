# ⚡ HomelabStatus

A modern, lightweight, high-performance self-hosted Homelab Status and Monitoring application powered by **Symfony 8.1** & **PHP 8.5** with **SQLite**.

![PHP](https://img.shields.io/badge/PHP-8.4%2B-blue.svg)
![Framework](https://img.shields.io/badge/Framework-Symfony%208.1%20Flex-black.svg)
![Database](https://img.shields.io/badge/Database-SQLite%20WAL-emerald.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)

---

## ✨ Features

- 🖥️ **Modern Reactive Dashboard**: Dark-mode first UI styled with Tailwind CSS, showing overall SLA, live host vitals, 30/60/90-day availability history bars, and response latencies.
- 🔍 **Multi-Protocol Monitoring Engine**:
  - **HTTP/HTTPS**: Status code checks, keyword matching, response time benchmarking, self-signed SSL tolerance.
  - **TCP Port**: Check accessibility and latency for databases, SSH, Redis, game servers, Proxmox, etc.
  - **ICMP Ping**: Real-time packet round-trip latency & packet loss detection.
  - **SSL Certificates**: Expiration alerts & days-remaining tracking.
  - **Docker Containers**: Direct health inspect & running state checks via local Docker daemon.
  - **Host Node Metrics**: Auto-collection of CPU utilization, RAM usage, storage volume disk space, system load, and uptime.
- 🚨 **Incident & Maintenance Timeline**: Publish scheduled maintenance alerts and track active/historical outages.
- 🛡️ **Dynamic Status Badges**: Shields.io compatible SVG badges (`/badge/{id}/status`, `/badge/{id}/uptime`, `/badge/global/status`).
- 🔔 **Multi-Channel Alerts**: Webhook notifications for Discord, Telegram, Slack, and generic webhooks.
- 🚀 **Zero-Config Self-Hosting**: Built-in SQLite WAL database, zero external dependencies required, single Docker Compose command.
- ⚙️ **Admin Console & REST API**: Interactive web management portal and fully documented JSON API.

---

## 🚀 Quick Start

### 1. Local Development (PHP Built-in Server)

```bash
# Clone the repository
git clone https://github.com/fabianternis/homelabstatus.git
cd homelabstatus

# Install dependencies
composer install

# Seed sample homelab monitors (optional)
php bin/console homelab:seed

# Start the local development server
php -S 0.0.0.0:8080 -t public
```
Visit `http://localhost:8080` in your browser.
Admin portal: `http://localhost:8080/admin` (Default password: `admin` — change in `.env`).

---

### 2. Docker Compose Deployment

```yaml
services:
  homelabstatus:
    image: ghcr.io/fabianternis/homelabstatus:latest # Or build locally: build: .
    container_name: homelabstatus
    restart: unless-stopped
    ports:
      - "8080:8080"
    environment:
      - APP_ENV=prod
      - APP_SECRET=generate_a_random_32_char_secret
      - APP_NAME="My Homelab Status"
      - ADMIN_PASSWORD=your_secure_password
      - ENABLE_HOST_METRICS=true
      - DISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/...
    volumes:
      - ./var/data:/app/var/data
      - /var/run/docker.sock:/var/run/docker.sock:ro
```

Run:
```bash
docker compose up -d
```

---

## ⏱️ Background Checks & Scheduling

Add a cron job to execute monitor checks automatically every minute:

```bash
* * * * * cd /path/to/homelabstatus && php bin/console homelab:check >> /dev/null 2>&1
```

Or run in daemon/loop mode:
```bash
php bin/console homelab:check --loop=60
```

---

## 🔌 REST API Reference

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/status` | Overall system health summary, SLA, and active monitors |
| `GET` | `/api/monitors` | List all active monitors |
| `GET` | `/api/monitors/{id}` | Detailed monitor metrics, 50-check logs, and uptime |
| `POST` | `/api/check` | Trigger execution of all checks (Requires `Authorization: Bearer <API_KEY>`) |
| `GET` | `/api/metrics` | Host machine CPU, RAM, Disk, Load, and Uptime |
| `GET` | `/api/incidents` | Active and historical incident logs |
| `GET` | `/badge/{id}/status` | SVG Status Badge (`online`, `degraded`, `offline`) |
| `GET` | `/badge/{id}/uptime` | SVG Uptime Badge (e.g. `99.98%`) |

---

## 🧪 Running Tests

```bash
vendor/bin/phpunit
```

---

## 📄 License

MIT License. Free for personal and commercial homelab use.
