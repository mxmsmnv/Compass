# Changelog

All notable changes to Compass will be documented in this file.

## 1.2.4 - 2026-10-08

### Fixed

- Reused a shared nonce from enforced Content Security Policy headers on both
  injected tracker script elements. Report-only policies and conflicting
  multiple policies do not grant a nonce.

## 1.2.3 - 2026-09-30

### Fixed

- Corrected the minimum ProcessWire version to 3.0.182, where the schema
  introspection APIs used by Compass were introduced.

## 1.2.2 - 2026-09-26

### Fixed

- Replaced boolean `SUM()` expressions in device statistics with portable
  conditional aggregates for PostgreSQL.

## 1.2.1 - 2026-09-26

### Fixed

- Use ProcessWire's portable column and index introspection APIs during schema
  upgrades, allowing the same upgrade path on MySQL/MariaDB, SQLite, and
  PostgreSQL.

## 1.2.0 - 2026-07-30

### Fixed

- Tracker collection no longer creates or persists a ProcessWire session.
  Rate limiting now uses server-side WireCache, while the response retains only
  Compass's opaque `compass_sid` cookie. This keeps subsequent anonymous page
  views eligible for CloudCache.
- Tracker responses are explicitly private and non-cacheable.

## 1.1.0 - 2026-06-18

### Added

- Added optional Native Analytics dashboard integration: Compass now adds a tab when Native Analytics exposes hookable dashboard tabs.
- Kept Compass fully standalone when Native Analytics is not installed or does not expose the dashboard tab hooks.

## 1.0.1 - 2026-06-18

### Fixed

- Respect an intentionally empty `exclude_roles` setting instead of forcing `superuser` to remain excluded.
- Clarified the exclude roles setting note: leave it blank to track all roles.

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
