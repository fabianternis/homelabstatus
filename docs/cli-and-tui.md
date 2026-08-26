# 🖥️ CLI & Terminal User Interface (TUI)

Homelab Uplink Monitor comes with interactive live terminal dashboards and administration commands.

---

## 📺 1. Live Terminal User Interface (TUI)

Launch the real-time Curses-style terminal dashboard:

```bash
php bin/console uplink:tui
```

### Options:
- `-i, --interval=3`: Refresh interval in seconds (default: `3`)
- `-p, --packets=2`: ICMP packets per probe run (default: `2`)

### TUI Features:
- 🟢 🟡 🔴 Real-time ANSI colored health status badges.
- Dynamic ASCII Uplink Health Score progress bar (`[██████████████████░░░░░░░] 85/100`).
- Unicode Latency Sparkline history (` ▂▃▄▅▆▇█`).
- Concurrent RTT, Min/Max latency, and Jitter calculations.
- Clean cursor trapping and signal handling (`Ctrl+C`).

---

## 🔍 2. Probing & Scripting Commands

```bash
# Execute single probe snapshot and print formatted table
php bin/console uplink:ping

# Run continuously every 5 seconds
php bin/console uplink:ping --loop=5

# Output pure JSON for cron jobs or automation scripts
php bin/console uplink:ping --json
```

---

## ⚙️ 3. Database Check Management CLI

### List All Checks
```bash
php bin/console check:list
php bin/console check:list --trashed   # Include soft-deleted
php bin/console check:list --json      # Raw JSON output
```

### Create a New Check
```bash
php bin/console check:create "Cloudflare Secondary" "1.0.0.1" --group="DNS" --provider="Cloudflare" --packets=2 --timeout=2
```

### Enable / Disable a Check
```bash
php bin/console check:toggle <ulid|slug>
```

### Soft-Delete (Trash) or Destroy
```bash
# Move to trash
php bin/console check:delete <ulid|slug>

# Permanently destroy
php bin/console check:delete <ulid|slug> --force
```

### Restore from Trash
```bash
php bin/console check:restore <ulid|slug>
```

---

## 📜 4. Audit Trail CLI

```bash
# View recent system activity and check lifecycle events
php bin/console audit:list --limit=25
php bin/console audit:list --json
```
