# Web Dashboard & Progressive Web App (PWA)

The web dashboard is built for responsive, live monitoring with zero layout shifts and offline capability.

---

## 1. Granular Changed-Only Flashing

When the Server-Sent Events (SSE) stream or fallback polling delivers incoming probe metrics, the client-side DOM comparator compares the previous value with the new value:
- **Only cells or numbers whose value actually changed** trigger an animated pulse highlight (`.live-updated`).
- Static or unchanged values remain completely still.

---

## 2. Real-Time Chart.js Sparklines

- Embedded `<canvas>` micro-charts render cubic bezier curves (`tension: 0.4`) with emerald gradient area fills.
- Updated directly in-place via `chart.update('none')` without destroying or recreating the canvas.
- Hovering over a chart reveals exact latency timestamps in interactive tooltips.

---

## 3. Privacy-Preserving IP Reveal Widget

- Client / WAN IP is displayed in the top navbar.
- Styled with a CSS blur filter (`filter: blur(5px)`) by default.
- Automatically reveals smoothly when hovered with the mouse, preventing accidental leaks during screenshots or screen shares.

---

## 4. Smart Page Visibility Lifecycle

Using the **HTML5 Page Visibility API**:
- **Tab backgrounded / minimized**: Automatically closes the Server-Sent Events (SSE) connection (`IDLE (TAB IN BACKGROUND)`) to save CPU and server resources.
- **Tab focused / reopened**: Instantly reconnects the stream (`LIVE SSE STREAM`) and triggers an immediate probe refresh.

---

## 5. PWA & Service Worker Caching

- **`public/manifest.json`**: Enables "Add to Home Screen" or installing as a standalone desktop/mobile app.
- **`public/sw.js`**: Employs a **Stale-While-Revalidate** caching strategy for assets, while bypassing streaming and live API pings.

---

## 6. Multi-Language Auto-Discovery Dropdown

- **Dynamic Auto-Discovery**: Drop any `translations/messages.{locale}.yaml` file into the repository, and it is automatically added to the dropdown menu.
- **Session Persistence**: Language selection persists across browser sessions.
- Supported out of the box: **English (`en`)**, **German (`de`)**, and **French (`fr`)**.
