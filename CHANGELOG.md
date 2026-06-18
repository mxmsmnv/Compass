# Changelog

All notable changes to Compass will be documented in this file.

## 1.0.0 - 2026-06-17

Initial public release.

### Features

- Click heatmap tracking with touch support.
- Scroll depth tracking in 10% buckets.
- Rage click detection (3+ clicks within 30px / 700ms).
- Mouse movement tracking with configurable throttle and batch size.
- Device detection (desktop / mobile / tablet) via User-Agent + viewport width.
- Two-panel admin viewer: page list sidebar + iframe with canvas heatmap overlay.
- Date range filter (7 / 30 / 90 / 365 days).
- Device filter and device breakdown in the stats bar.
- PNG export of the heatmap layer.
- Dark mode via `--pw-*` CSS variables.
- Configurable exclusions by role and template.
- LazyCron data pruning with configurable retention period.
- Batched event delivery via `sendBeacon` with `fetch(keepalive)` fallback.

### Security

- Rate limiting: 300 events per 60-second window per session; oversized batches rejected with HTTP 413.
- `Origin` header validation on `/compass-track` rejects cross-origin requests.
- `/compass-data` restricted to superusers with CSRF token validation.
- `compass_sid` cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS.
- `X-Frame-Options: SAMEORIGIN` set on frontend pages for superusers (enables iframe viewer).

### Database

- Single `compass_events` table with indexes for page, device, time, and session lookups.
- Batched DELETE pruning (10 000 rows per iteration) to avoid long table locks.
- Safe upgrade path: column and index existence checked before `ALTER TABLE`.
