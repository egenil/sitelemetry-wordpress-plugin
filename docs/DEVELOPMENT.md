# Development notes

Sitelemetry Audit is a thin WordPress client for the hosted Sitelemetry audit service. It speaks the MCP JSON-RPC contract of the service with the WordPress HTTP API and renders the result in the admin. No library is bundled; nothing is scanned on the WordPress server.

## Layout

| Path | Purpose |
| --- | --- |
| `sitelemetry-audit.php` | Plugin header, constants, `require` of the classes, activation and deactivation hooks |
| `includes/class-sitelemetry-audit-plugin.php` | Bootstrap: wires runner, cron, admin and dashboard widget |
| `includes/class-sitelemetry-audit-settings.php` | Options API: defaults, `sanitize()`, key masking, target validation, base URL, time budget |
| `includes/class-sitelemetry-audit-client.php` | MCP client: `initialize`, `notifications/initialized`, `tools/call`; JSON and SSE bodies; HTTP, JSON-RPC and transport errors as `WP_Error` |
| `includes/class-sitelemetry-audit-runner.php` | Job state machine: `start()`, one `step()` per call, `advance()` (pure), `finish()`, `progress()` |
| `includes/class-sitelemetry-audit-outcome.php` | Pure: status classification, findings normalization, "not measured" entries, result model |
| `includes/class-sitelemetry-audit-labels.php` | Every translatable label: audit kinds, severities, status headings, reason leads, not-measured lines |
| `includes/class-sitelemetry-audit-plans.php` | Public plan catalogue (`GET {base}/api/plans`): fetch, cache, normalize, required plan per audit kind |
| `includes/class-sitelemetry-audit-links.php` | Pricing and app links with UTM parameters, verification step, "Plan and usage" box data |
| `includes/class-sitelemetry-audit-results.php` | Last result per audit kind in one option |
| `includes/class-sitelemetry-audit-cron.php` | Weekly event, background poll event, safety net for manual jobs |
| `includes/class-sitelemetry-audit-admin.php` | Settings > Sitelemetry (Settings and Results tabs), admin-post actions, AJAX poll, assets |
| `includes/class-sitelemetry-audit-dashboard.php` | Dashboard widget |
| `admin/views/*.php` | Templates (settings, results, plan box, dashboard widget); every output escaped |
| `admin/css`, `admin/js` | One stylesheet and one script, enqueued only on the plugin page (CSS also on the dashboard) |
| `languages/sitelemetry-audit.pot` | Translation template (text domain `sitelemetry-audit`) |
| `uninstall.php` | Removes options, transients and cron events, on every site of a network |
| `readme.txt` | WordPress.org readme |
| `tests/` | PHPUnit-style suite with in-memory WordPress stand-ins and an in-process mock of the service (not shipped) |
| `tools/` | Node scripts for php-wasm linting and tests, the .pot and the zip (not shipped) |
| `docs/` | This file and `SUBMISSION.md` (not shipped) |

## How an audit runs

1. **Start.** `Run audit` posts to `admin-post.php?action=sitelemetry_audit_run` (capability `manage_options` and a nonce are checked). `Sitelemetry_Audit_Runner::start()` validates the kind and the target, refuses when a job is already stored and not expired, stores a job option `sitelemetry_audit_job` with `args = { target }` and fires `sitelemetry_audit_job_started`. No request to the service happens yet.
2. **Step.** `Sitelemetry_Audit_Runner::step()` performs exactly one `tools/call audit_<kind>` with the stored arguments and applies the answer with the pure `advance()`. The handshake runs once per audit, not once per step: the first step calls `initialize`, and the `Mcp-Session-Id` and the negotiated protocol version are stored on the job, so every later step restores them (`Sitelemetry_Audit_Client::resume_session()`) and is a single request inside the session the job was issued in. A session the service no longer knows (HTTP 404, or 400 with a session error code) costs one fresh handshake on that step and the call is sent again.
   - `status: running` with a `jobId`: the server's `pollArguments` replace the stored arguments unchanged (no scan option is added) and `retryAfterMs` is stored; the first text line becomes the phase shown to the admin.
   - HTTP 429 without the commercial usage code: wait `Retry-After` (default 5 s), up to 40 times.
   - Transient errors (network, 5xx, 408): back off 4, 8, 16 s, up to 3 times.
   - `action_required` with reason `audit_job_busy`: wait 15 s, up to 10 times.
   - Anything else is final: the outcome is interpreted, stored as the last result of that kind, the job is removed and `sitelemetry_audit_job_finished` fires.
3. **Who calls `step()`, and how often.** Two callers share the same function:
   - **The results page (admin AJAX loop), for manual audits.** While the page is open, `admin/js/sitelemetry-audit-admin.js` posts `wp_ajax_sitelemetry_audit_poll` (nonce and capability checked), the server runs one step and answers with the progress (state, phase, elapsed, `retry_after_ms`; never the key or the arguments). The script waits the whole `retry_after_ms` the service asked for (at least 2 seconds, and never past the end of the time budget) and asks again, and reloads the page when the job is done; the 30-second ceiling applies only to the backoff the script itself picks after a failed progress request. A "Check now" link performs one step without JavaScript.
   - **A WP-Cron single event (`sitelemetry_audit_poll_job`), for the weekly audit and as a safety net.** The weekly event starts the audit and polls it through this event; a manual job also schedules one event 120 seconds after the start, so a closed browser tab does not strand a job. While the job runs, the event reschedules itself after `retry_after_ms`, raised to at least 60 seconds because WP-Cron has no finer granularity and only fires on page loads unless a system cron calls `wp-cron.php`.

   The AJAX loop is the primary path for manual audits because it can honour the service's `retryAfterMs` (a few seconds) precisely, needs no traffic to fire and gives the admin live progress. WP-Cron alone would poll at best once a minute and only when the site is visited. A transient lock (`sitelemetry_audit_step_lock`) makes the two callers take turns: the loser reports `locked` and retries a few seconds later.
4. **Time budget.** Every job has 20 minutes (`sitelemetry_audit_time_budget` filter) from its start. An expired job ends with status `blocked`, reason `timeout`, and the job id, so the admin can find the audit in the app, where the service keeps running it.
5. **Stop waiting.** The admin can abandon the local job (`admin_post_sitelemetry_audit_cancel`); the service is not told to stop, and the result stays in the app.

## Result model and status classification

`Sitelemetry_Audit_Outcome::interpret()` produces one array per run (stored per audit kind in `sitelemetry_audit_results`, autoload off):

| Input | `status` | `reason` |
| --- | --- | --- |
| `structuredContent.status = action_required`, reason `entitlement_required` | `plan_required` | the server reason |
| same, reason `usage_limit_reached` | `quota_exhausted` | the server reason |
| same, reason `target_verification_required`, `target_reverification_required`, `verification_scope_required` or `authorization_consent_required` | `verification_required` | the server reason (the banner heading follows it) |
| same, any other reason | `blocked` | the server reason |
| `isError: true` | from the text (plan, quota, verification or blocked) | `tool_error` |
| HTTP 401 or 403 | `blocked` | `unauthorized` |
| HTTP 402 or a plan message | `plan_required` | server code or `http_STATUS` |
| JSON-RPC error | from the text | `rpc_CODE` |
| network failure, non-JSON body | `blocked` | `transport` |
| time budget exceeded | `blocked` | `timeout` |
| a measured result with `status: partial`, `complete: false`, `coverageStatus` partial or unavailable, or any not-measured entry | `partial` | none |
| a measured result otherwise | `completed` | none |

Findings are normalized to `id`, `severity` (critical, high, medium, low, info; unknown becomes info), `title`, `evidence`, `impact`, `fix` (remediation, fix or recommendation), `category`, `location` (location, url or path) and `pillar`, each clipped. Counts come from the server when present, otherwise from the findings. "Not measured" entries are stored structured (failed pillars, pillars skipped by plan or scope, modules outside the plan, unavailable pillars, modules that require verification, module results with status unavailable or partial and their reasons) and rendered by `Sitelemetry_Audit_Labels::not_measured_line()` so the text is translatable.

## Plan and usage box

`Sitelemetry_Audit_Links::plan_box()` builds neutral facts only: the gate or the connected plan (Free: module count and scans per month from the catalogue), the remaining scans when the result carries them, a table of the paid plans from the catalogue (label, price, audit kinds, module count, security scans), and two links: `https://sitelemetry.com/pricing?utm_source=wordpress-plugin&utm_medium=plugin` and `https://sitelemetry.com/app` (ownership verification, API keys, full reports). The catalogue is read only after a key is stored and cached for 12 hours (10 minutes after a failed fetch).

## Storage

| Name | Type | Autoload | Content |
| --- | --- | --- | --- |
| `sitelemetry_audit_settings` | option | yes | `api_key`, `target`, `kind`, `weekly_enabled` |
| `sitelemetry_audit_results` | option | no | last result model per audit kind |
| `sitelemetry_audit_job` | option | no | the running job (kind, target, tool, origin, args, job id, counters, retry_after_ms, phase, MCP session id and protocol version) |
| `sitelemetry_audit_plans` | transient | n/a | normalized plan catalogue |
| `sitelemetry_audit_step_lock` | transient | n/a | one step at a time |
| `sitelemetry_audit_weekly`, `sitelemetry_audit_poll_job` | cron hooks | n/a | weekly audit, background poll |

No personal data is stored; results contain the target URL and what the service reported about it. `uninstall.php` removes all of the above on every site of a network. Deactivation removes the cron events and a running job, and keeps settings and results.

## Extension points

- `SITELEMETRY_AUDIT_BASE_URL` constant or `sitelemetry_audit_base_url` filter: service base URL (staging).
- `sitelemetry_audit_time_budget` filter: seconds per job (default 1200, minimum 60).
- `sitelemetry_audit_request_timeout` filter: seconds per HTTP request (default 60, minimum 10).
- `sitelemetry_audit_job_started( $job )` and `sitelemetry_audit_job_finished( $model_or_null, $job )` actions.

## Tests

The suite in `tests/` is PHPUnit-style and runs without WordPress: `tests/bootstrap.php` provides in-memory stand-ins for the WordPress functions the classes call (options, transients, cron, translation, escaping, HTTP API) and an in-process mock of the hosted service with the behaviour of the reference mock server (running-to-completed jobs, plan gate, quota gate, verification gates, rate limiting, unauthorized, SSE bodies). Fixtures in `tests/fixtures/` are the reference shapes of the service.

With PHP installed:

```sh
php tests/run.php                       # no PHPUnit needed
phpunit -c tests/phpunit.xml.dist       # with PHPUnit 9 or 10
```

Without PHP (php-wasm through Node.js 20 or newer):

```sh
cd tools && npm install
node lint.mjs        # php -l of every PHP file on PHP 7.4 and 8.3
node run-tests.mjs   # the suite on PHP 7.4 and 8.3
node make-pot.mjs    # regenerates languages/sitelemetry-audit.pot and fails on a call without the text domain
node build-zip.mjs   # dist/sitelemetry-audit-VERSION.zip from .distignore
```

Each script takes the plugin directory as an optional first argument (default: the parent of `tools/`).

Coding standard: WordPress Coding Standards (`phpcs --standard=WordPress .` with the WPCS ruleset installed). Plugin Check (`wordpress/plugin-check`) should be run on a test site before every WordPress.org release.
