# Compass

Heatmap analytics for ProcessWire. Tracks where visitors click, how far they scroll, rage clicks, and mouse movement — visualised as an interactive heatmap directly in the admin.

---

## Features

- **Click heatmap** — see exactly where visitors click on each page
- **Mouse movement** — thermal map of cursor paths
- **Rage clicks** — detect frustration: 3+ clicks in the same spot within 700ms
- **Scroll depth** — how far visitors scroll before leaving
- **Per-page viewer** — browse any tracked page with iframe + canvas overlay
- **Date range filter** — last 7 / 30 / 90 days or full year
- **Device filter** — compare desktop, mobile and tablet behaviour
- **Device stats** — see traffic split in the admin viewer
- **Export PNG** — save the heatmap layer as an image
- **Dark mode** — uses `--pw-*` CSS variables, works with AdminThemeUikit automatically
- **Native Analytics integration** — adds an optional Compass tab when Native Analytics supports hookable dashboard tabs
- **Zero external requests** — all data stays on your server

---

## Requirements

- ProcessWire 3.0.0+
- PHP 8.0+

---

## Installation

1. Download or clone this repository into `/site/modules/Compass/`
2. In the ProcessWire admin go to **Modules → Refresh**, then find **Compass** and click **Install**
3. The module creates the `compass_events` table automatically on install

---

## File structure

```
Compass/
├── Compass.module.php           # Core module — autoload, hooks, DB, config
├── CompassAPI.php               # AJAX endpoints: /compass-track and /compass-data
├── ProcessCompass.module.php    # Admin UI — page list + iframe heatmap viewer
├── js/
│   ├── tracker.js               # Frontend tracker (clicks, scroll, rage, movement)
│   └── viewer.js                # Admin viewer (iframe + canvas overlay)
├── css/
│   └── viewer.css               # Admin UI styles
└── lib/
    └── heatmap.min.js           # heatmap.js v2 (MIT) — place manually, see Installation
```

---

## How it works

### Tracking

Once installed, Compass automatically injects a small tracker script (`tracker.js`, ~3kb minified) into every frontend page. The script collects events and sends them in batches to the site-root `compass-track` endpoint using `navigator.sendBeacon` (with `fetch` as fallback). No page load performance impact.

Events are stored in a single MySQL table `compass_events`.

### Viewing

Go to **Setup → Compass** in the admin. Select any page from the left sidebar — its URL loads in an iframe on the right, with the heatmap rendered as a canvas overlay. Switch between event types and date ranges using the toolbar.

If Native Analytics is installed and exposes hookable dashboard tabs, Compass also appears as a tab inside its dashboard. This integration is optional; Compass remains fully usable from **Setup → Compass** without Native Analytics.

---

## Configuration

Go to **Modules → Compass → Settings**.

| Setting | Default | Description |
|---|---|---|
| Track clicks | ✅ | Record click/tap coordinates |
| Track scroll depth | ✅ | Record how far users scroll (10% buckets) |
| Track mouse movement | ✅ | Record cursor path |
| Track rage clicks | ✅ | Detect repeated frustrated clicks |
| Exclude roles | `superuser` | Comma-separated list of roles to not track |
| Exclude templates | _(empty)_ | Comma-separated list of templates to skip |
| Data retention | `90` days | Events older than this are pruned by LazyCron |
| Mouse move throttle | `100` ms | Minimum interval between recorded move points |
| Mouse move batch | `50` points | How many move points to buffer before flushing |
| Beacon interval | `5000` ms | How often the tracker sends data to the server |

---

## Privacy & GDPR

Compass does **not** collect:
- IP addresses
- User agents
- Personal identifiers

Each visitor is assigned an anonymous session cookie (`compass_sid`) — a random 32-character hex string with no link to user identity. The cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` when the site runs over HTTPS. It expires after 1 year.

Depending on your jurisdiction you may still need to disclose heatmap tracking in your privacy policy.

---

## Rate limiting

The `/compass-track` endpoint limits each session to **300 events per 60 seconds** and rejects oversized batches above **300 events per request**. Requests exceeding the rolling event limit return HTTP 429; oversized payloads return HTTP 413. Adjust the constants in `Compass.module.php` if needed:

```php
const RATE_LIMIT_WINDOW      = 60;   // seconds
const RATE_LIMIT_MAX         = 300;  // events per window
const MAX_EVENTS_PER_REQUEST = 300;
```

---

## Security

- **`/compass-track`** — public endpoint, accepts `POST` only. Cross-origin requests (mismatched `Origin` header) are rejected with HTTP 403 to prevent third-party sites from polluting your analytics data.
- **`/compass-data`** — restricted to logged-in superusers. Every request must include a valid ProcessWire CSRF token.
- **X-Frame-Options** — Compass sets `X-Frame-Options: SAMEORIGIN` on frontend pages when a superuser is browsing, allowing the iframe viewer to display them. Admin pages and regular visitor responses are unaffected.

---

## License

MIT © [Maxim Semenov](https://smnv.org) — [maxim@smnv.org](mailto:maxim@smnv.org)

If this project helps your work, consider supporting future development: [GitHub Sponsors](https://github.com/sponsors/mxmsmnv) or [smnv.org/sponsor](https://smnv.org/sponsor/).
