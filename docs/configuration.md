# ⚙️ Configuration & Customization

## 🎯 Check Types

Homelab Uplink Monitor supports dynamic check types configured in the `checks` table:

| Type | Description | JSON Config Keys |
|---|---|---|
| `uplink` | Multi-target external WAN and DNS resolver probing | `host`, `packets`, `timeout`, `provider`, `country` |
| `icmp_ping` | General-purpose ICMP host availability check | `host`, `packets`, `timeout` |
| `http` | HTTP/HTTPS response and status code check | `url`, `method`, `expected_status`, `timeout`, `headers` |
| `tcp` | TCP socket connection check | `host`, `port`, `timeout` |
| `dns` | DNS resolution check | `host`, `record_type`, `nameserver` |

---

## ⚖️ Uplink Health Scoring Algorithm

The composite score (0–100) is evaluated across all active `type = 'uplink'` checks:

1. **Base Score**: 100 points
2. **Packet Loss Deduction**: Up to 50 points deducted proportional to average loss percentage ($Loss \times 1.5$).
3. **High Latency Deduction**: Deducted only when average RTT latency exceeds 80ms ($(RTT - 80) \times 0.3$).
4. **Unreachable Target Deduction**: Penalizes unreachable Anycast nodes.

### Operational State Mapping:
- **`EXCELLENT`**: Health Score $\ge 90$, 0% Packet Loss, Average RTT $\le 80\text{ms}$.
- **`GOOD`**: Health Score $\ge 70$, Loss $\le 15\%$, Average RTT $\le 200\text{ms}$.
- **`DEGRADED`**: Health Score $< 70$, Loss $> 15\%$, or Average RTT $> 200\text{ms}$.
- **`OFFLINE`**: 0 reachable targets or Packet Loss $\ge 80\%$.
