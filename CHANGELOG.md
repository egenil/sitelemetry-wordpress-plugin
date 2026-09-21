# Changelog

## 0.1.1 - 2026-09-21

- Tested in WordPress 7.1.1 (WordPress Playground): activation, settings page, results tab, plugin list entry and dashboard widget render without notices. `Tested up to` raised to 7.1.
- Plugin Check 1.x (General, Plugin Repo, Security, Performance, Accessibility) reported one error (outdated `Tested up to`) and two warnings (unprefixed loop variables in `admin/views/settings.php`); all fixed, the run is clean.
- `readme.txt` links the exact terms (https://sitelemetry.com/terms) and privacy policy (https://sitelemetry.com/privacy) pages instead of the homepage.

## 0.1.0 - 2026-09-21

First release.

- Settings > Sitelemetry: MCP API key (stored in the options table, shown masked, removable), target (defaults to the site address), audit kind (security by default; other kinds labelled with the plan they require, read from the public plan catalogue) and the optional weekly schedule.
- Manual audits through admin-post with nonce and capability checks. The MCP JSON-RPC contract (initialize, then tools/call audit_<kind>) is spoken with the WordPress HTTP API; no bundled libraries.
- Polling: while the results page is open, an admin AJAX loop asks WordPress for one step at a time; each step re-sends the server's pollArguments unchanged, inside the MCP session negotiated once per audit, and waits the full retryAfterMs the service asked for. A WP-Cron single event finishes a job whose browser tab was closed, and drives the weekly audit. A 20-minute time budget (filter `sitelemetry_audit_time_budget`) bounds every job.
- Results page: status banner (completed, partial with "What was not measured", plan_required, quota_exhausted, verification_required, blocked), score and grade, findings table with a severity filter, timestamps, coverage notes, the next step in the app, and a factual "Plan and usage" box (pricing link with `utm_source=wordpress-plugin&utm_medium=plugin`, link to ownership verification).
- Dashboard widget: last score, findings by severity, remaining scans when known, one button to the results.
- Last result per audit kind stored in one option (no personal data); uninstall removes options, transients and cron events.
- All strings translatable (text domain `sitelemetry-audit`, `languages/sitelemetry-audit.pot`).
