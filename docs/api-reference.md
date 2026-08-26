# 🌐 REST API Reference

The Homelab Uplink Monitor provides a **clean JSON REST API** and a real-time **Server-Sent Events (SSE)** streaming channel.

---

## 📡 Public Uplink Endpoints

### 1. Get Current Uplink Status & Health Score
`GET /api/v1/uplink`

**Response:**
```json
{
  "status": "ok",
  "data": {
    "state": "excellent",
    "state_label": "Excellent",
    "health_score": 98,
    "metrics": {
      "avg_latency_ms": 14.25,
      "avg_packet_loss_percent": 0.0,
      "avg_jitter_ms": 1.12
    },
    "targets_summary": {
      "total": 6,
      "healthy": 6
    },
    "targets": [
      {
        "target_id": "01M0ZS3TCFQ5XBAK01NSM2TE1Z",
        "host": "1.1.1.1",
        "state": "excellent",
        "packets_sent": 2,
        "packets_received": 2,
        "packet_loss_percent": 0.0,
        "min_latency_ms": 11.2,
        "avg_latency_ms": 11.5,
        "max_latency_ms": 11.8,
        "jitter_ms": 0.42,
        "latencies": [11.2, 11.8],
        "error_message": null,
        "probed_at": "2026-08-26T21:40:00+00:00"
      }
    ],
    "evaluated_at": "2026-08-26T21:40:00+00:00"
  }
}
```

---

### 2. Live Server-Sent Events (SSE) Stream
`GET /api/v1/uplink/stream`

Streams continuous real-time JSON events every N seconds.

**Parameters:**
- `interval` *(optional, default: `3`)*: Seconds between broadcast updates.
- `packets` *(optional, default: `2`)*: Packets per probe run.

**CLI Example:**
```bash
curl -N http://localhost:8080/api/v1/uplink/stream
```

---

### 3. Trigger Immediate Live Probe Run
`POST /api/v1/uplink/ping`

Triggers a concurrent probe run across all active database checks and returns the fresh summary.

**Parameters:**
- `packets` *(optional query param, default: `2`)*

---

### 4. Historical Series & Sparklines
`GET /api/v1/uplink/history?samples=30`

Returns the time-series history dataset and computed Unicode sparkline characters per target node.

---

### 5. Health Check
`GET /api/v1/health`

---

## 🛡️ Admin Management API

### List All Checks
`GET /api/v1/admin/checks?with_trashed=false&type=uplink`

### Create a Check
`POST /api/v1/admin/checks`
```json
{
  "name": "Local Gateway",
  "host": "192.168.1.1",
  "type": "uplink",
  "group_name": "LAN",
  "config": {
    "packets": 2,
    "timeout": 1,
    "provider": "Router"
  }
}
```

### Update a Check
`PUT /api/v1/admin/checks/{ulid}`

### Soft-Delete Check
`DELETE /api/v1/admin/checks/{ulid}`

### Restore Soft-Deleted Check
`POST /api/v1/admin/checks/{ulid}/restore`

### Permanent Deletion
`DELETE /api/v1/admin/checks/{ulid}/force`
