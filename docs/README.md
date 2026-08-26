# Homelab Uplink Monitor — Documentation

Welcome to the documentation for **Homelab Uplink Monitor**, a lightweight, enterprise-grade, **API-first** network latency, ICMP probe, and external uplink status engine built with **PHP 8.5** and **Symfony 8.1**.

---

## Table of Contents

1. [Getting Started](file:///Users/fabianternis/Code/GitHub/fabianternis/homelabstatus/docs/getting-started.md)
   - System Requirements
   - Quick Start Installation
   - Development & Production Web Servers
   - Environment Configuration

2. [System Architecture](file:///Users/fabianternis/Code/GitHub/fabianternis/homelabstatus/docs/architecture.md)
   - ULID Primary Keys
   - Dynamic `checks` and `check_executions` Database Schema
   - Type-specific JSON Columns
   - Soft-Deletes & Spatie-Style Audit Logging
   - Infinite Telemetry Retention

3. [REST API Reference](file:///Users/fabianternis/Code/GitHub/fabianternis/homelabstatus/docs/api-reference.md)
   - `GET /api/v1/uplink` (Status & Composite SLA Score)
   - `GET /api/v1/uplink/stream` (Server-Sent Events Live Stream)
   - `POST /api/v1/uplink/ping` (Trigger Immediate Probe)
   - `GET /api/v1/uplink/history` (Historical Series & Sparklines)
   - `GET /api/v1/health` (Health Check)
   - `/api/v1/admin/checks*` (Admin CRUD API)

4. [CLI & Terminal User Interface (TUI)](file:///Users/fabianternis/Code/GitHub/fabianternis/homelabstatus/docs/cli-and-tui.md)
   - Live ANSI Curses-Style TUI (`php bin/console uplink:tui`)
   - CLI Probing & Scripting (`php bin/console uplink:ping`)
   - Check Management CLI (`check:list`, `check:create`, `check:toggle`, `check:delete`, `check:restore`)
   - Audit Trail CLI (`audit:list`)

5. [Web Dashboard & PWA](file:///Users/fabianternis/Code/GitHub/fabianternis/homelabstatus/docs/web-dashboard.md)
   - Reactive Live DOM Updates (Changed-only highlight flashing)
   - Chart.js Canvas Sparklines
   - Privacy-Preserving IP Reveal Widget
   - HTML5 Page Visibility Lifecycle
   - Service Worker Caching & PWA Manifest
   - Multi-Language Auto-Discovery Dropdown (EN, DE, FR)

6. [Configuration & Customization](file:///Users/fabianternis/Code/GitHub/fabianternis/homelabstatus/docs/configuration.md)
   - Adding Custom Resolvers & Gateways
   - Check Types (`uplink`, `icmp_ping`, `http`, `tcp`, `dns`)
   - Health Scoring & Threshold Tuning
