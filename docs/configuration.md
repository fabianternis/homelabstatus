# Configuration & Customization

## Check Types

Homelab Uplink Monitor supports dynamic check types configured in the `checks` table:

| Type | Description | JSON Config Keys |
|---|---|---|
| `uplink` | Multi-target external WAN and DNS resolver probing | `host`, `packets`, `timeout`, `provider`, `country` |
| `http` | HTTP/HTTPS response, TTFB latency, TLS/SSL expiration, keyword match | `url`, `method`, `expected_status`, `timeout`, `headers`, `check_ssl`, `keyword` |
| `icmp_ping` | General-purpose ICMP host availability check | `host`, `packets`, `timeout` |
| `tcp` | TCP socket connection check | `host`, `port`, `timeout` |
| `dns` | DNS resolution check | `host`, `record_type`, `nameserver` |

---

## Uplink Health Scoring Algorithm

The composite score (0–100) is evaluated across all active `type = 'uplink'` checks:

1. **Base Score**: 100 points
2. **Packet Loss Deduction**: Up to 50 points deducted proportional to average loss percentage.
3. **High Latency Deduction**: Deducted only when average RTT latency exceeds 80ms.
4. **Unreachable Target Deduction**: Penalizes unreachable Anycast nodes.

### Operational State Mapping:
- **`EXCELLENT`**: Health Score >= 90, 0% Packet Loss, Average RTT <= 80ms.
- **`GOOD`**: Health Score >= 70, Loss <= 15%, Average RTT <= 200ms.
- **`DEGRADED`**: Health Score < 70, Loss > 15%, or Average RTT > 200ms.
- **`OFFLINE`**: 0 reachable targets or Packet Loss >= 80%.
