# ⚡ Homelab Uplink Monitor

A lightweight, high-performance, **API-first** external uplink status and network latency monitoring engine built with **PHP 8.5** and **Symfony 8.1**.

Includes a full **Terminal User Interface (TUI)** for real-time live monitoring in SSH/terminal sessions alongside a JSON REST API and web preview.

---

## 🌟 Key Capabilities

- 🎯 **Multi-Target Anycast Probing**: Concurrent/sequential ICMP probing against upstream global DNS and Anycast targets (Cloudflare, Google, Quad9, OpenDNS).
- 📊 **Precision Network Metrics**: Measures Min/Avg/Max RTT latency, packet loss %, and jitter (standard deviation).
- 📈 **Unicode Sparklines**: Real-time ASCII/Unicode sparklines (` ▂▃▄▅▆▇█`) for terminal dashboards and API payloads.
- 🖥️ **Live Terminal User Interface (TUI)**: Interactive curses-style live terminal dashboard with automatic refresh.
- 🔌 **API-First Architecture**: Clean JSON REST API ready for integrations, Prometheus exporters, home automation, and frontend status dashboards.
- 💾 **Zero-Config SQLite Storage**: Embedded SQLite database with Write-Ahead Logging (WAL) for time-series probe history and aggregates.

---

## 🖥️ Terminal User Interface (TUI)

Launch the interactive live terminal dashboard:

```bash
php bin/console uplink:tui
```

Options:
- `--interval=3`: Refresh rate in seconds (default: `3`)
- `--packets=2`: ICMP packets per probe run (default: `2`)

Or run a single probe snapshot via CLI:
```bash
php bin/console uplink:ping
```
Or raw JSON output for scripting:
```bash
php bin/console uplink:ping --json
```

---

## 🌐 REST API Endpoints

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/uplink` | Current overall uplink state, composite health score (0-100), and active targets |
| `GET` | `/api/v1/uplink/targets` | List of all monitored target nodes with latest metrics |
| `GET` | `/api/v1/uplink/targets/{id}` | Target detail, 50-sample history, and latency sparkline |
| `POST` | `/api/v1/uplink/ping` | Trigger an immediate live probe run across all targets |
| `GET` | `/api/v1/uplink/history` | Historical time-series samples and sparklines |
| `GET` | `/api/v1/health` | Health check endpoint |

### Example API Response (`GET /api/v1/uplink`)

```json
{
  "status": "ok",
  "data": {
    "state": "excellent",
    "state_label": "Excellent",
    "health_score": 98,
    "metrics": {
      "avg_latency_ms": 14.25,
      "avg_packet_loss_percent": 0,
      "avg_jitter_ms": 1.12
    },
    "targets_summary": {
      "total": 6,
      "healthy": 6
    },
    "targets": [
      {
        "target_id": "cloudflare-primary",
        "host": "1.1.1.1",
        "state": "excellent",
        "avg_latency_ms": 11.2,
        "packet_loss_percent": 0,
        "jitter_ms": 0.8
      }
    ],
    "evaluated_at": "2026-08-26T21:20:00+00:00"
  }
}
```

---

## 🚀 Running the Server

```bash
# Start built-in server
php -S 0.0.0.0:8080 -t public
```
- **Web Dashboard & API Explorer**: `http://localhost:8080`
- **Uplink API**: `http://localhost:8080/api/v1/uplink`

---

## 🧪 Testing

```bash
vendor/bin/phpunit
```

---

## 📄 License

MIT License. Free for homelab and production use.
