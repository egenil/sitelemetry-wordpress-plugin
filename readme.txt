=== Sitelemetry Audit ===
Contributors: ozandikici
Tags: security, audit, seo, performance, accessibility
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Run Sitelemetry website audits from the WordPress dashboard and review the findings with concrete fixes.

== Description ==

Sitelemetry Audit connects your site to the hosted [Sitelemetry](https://sitelemetry.com) audit service. From **Settings > Sitelemetry** you run an audit of your site and read the result inside WordPress: a score and grade, every finding with its severity, location and fix, and a clear list of what was not measured.

**Audit kinds**

* Security (included in every plan, including Free)
* Technical SEO, AI visibility, Integrations, Accessibility, Performance and Full (all pillars with one blended score) on paid plans

**What the plugin does**

* Settings page with your Sitelemetry MCP API key (stored in the WordPress options table, shown masked, removable), the target (defaults to your site address), the audit kind and an optional weekly schedule.
* Runs the audit from your server, polls long audits until they finish (the page updates while Sitelemetry works) and stores the last result per audit kind.
* Results page with a status banner, score and grade, a findings table with a severity filter, the coverage notes of a partial result and a factual plan and usage box.
* Dashboard widget with the last score, the findings by severity, the remaining scans when known and a link to the results.
* Optional weekly audit through WP-Cron. Each completed audit uses one unit of the monthly allowance of the connected account.

**Plans and allowance**

A Free Sitelemetry account includes the security audit with the public security modules and a monthly number of security scans; the public plan catalogue at sitelemetry.com/api/plans is the source of truth and the plugin reads it for the plan and usage box. When the connected account does not include an audit kind or has used its monthly allowance, the audit is not started, no allowance is used and the results page says so.

**Ownership**

Audit only websites you own or are explicitly authorized to test. Every audit is performed by Sitelemetry against the live target and is recorded on the connected account. Protected checks (for example HTTP methods and exposed files) require ownership verification of the domain in the Sitelemetry app; results list the checks that were left unmeasured for this reason. Unmeasured checks are not passes.

**Thin client**

The plugin bundles no libraries and runs no scanner on your server. It sends requests to sitelemetry.com with the WordPress HTTP API and renders the response.

**External service**

This plugin relies on the hosted Sitelemetry service (https://sitelemetry.com) operated by Sitelemetry. It sends requests to the service only when you run an audit or after you have stored an API key, as described in the Privacy section below. The terms of service are published at https://sitelemetry.com/terms and the privacy policy at https://sitelemetry.com/privacy.

== Installation ==

1. Upload the `sitelemetry-audit` folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate the plugin.
3. Sign in at [sitelemetry.com/app](https://sitelemetry.com/app) (a Free account is enough), open **API key** and copy the MCP API key.
4. Go to **Settings > Sitelemetry**, paste the key, check the target and save.
5. Click **Run audit**. The results tab updates while the audit runs.

The site must be able to make outgoing HTTPS requests to sitelemetry.com.

== Frequently Asked Questions ==

= Does the plugin scan my site itself? =

No. Sitelemetry audits the live target from its own infrastructure. The plugin only starts the audit, polls its progress and shows the result.

= What does an audit cost? =

A completed audit uses one unit of the monthly allowance of the connected Sitelemetry account. Polling a running audit uses nothing more. Audits that are not started (plan not included, allowance exhausted, verification required) use nothing.

= Why does the result say "partial" or list checks that were not measured? =

Some checks need ownership verification of the target or a plan that includes them, or a measurement was unavailable for the target. The results page lists each of them under "What was not measured". Verify ownership of your domain in the Sitelemetry app to include the protected checks.

= How does polling work? =

Long audits return a job id. While the results page is open, the browser asks WordPress every few seconds (as Sitelemetry suggests) for one progress step; each step is one request from your server to Sitelemetry with the job arguments unchanged. If you leave the page, a WP-Cron event finishes the job in the background. A total time budget of 20 minutes applies; a job that exceeds it keeps running on the Sitelemetry side and the result stays available in the app.

= Where is the API key stored? =

In the WordPress options table, like other plugin settings. The settings page shows it masked, it is never written to logs and it is sent only to sitelemetry.com as a Bearer token.

= Can I audit a site other than this one? =

The target defaults to this site's address and can be changed to any URL you own or are authorized to test. Do not audit sites you are not authorized to test.

= Does the weekly audit work on a low-traffic site? =

WP-Cron runs when the site receives visits. If your host runs a system cron for WordPress (recommended), the weekly audit runs on time regardless of traffic.

== Screenshots ==

1. Settings > Sitelemetry: API key, target, audit kind and the weekly schedule.
2. Results: status banner, score and grade, findings by severity.
3. Findings table with the severity filter, locations and fixes.
4. A partial result with "What was not measured" and the verification step.
5. The plan and usage box with the remaining allowance and the plan comparison.
6. Dashboard widget with the last score and the findings by severity.

== Privacy ==

When you run an audit, the plugin sends to sitelemetry.com the target URL and the audit options you configured (the audit kind), authenticated with your API key. Every request also identifies the client in its User-Agent header (`sitelemetry-audit-wordpress/` and the plugin version); no WordPress version, site name, user, plugin list or other data from your site or your server is sent. Sitelemetry then audits the live target from its own infrastructure and records the audit on the connected account, as described in the Sitelemetry terms (https://sitelemetry.com/terms) and privacy policy (https://sitelemetry.com/privacy).

Once an API key is stored, the plugin also reads the public plan catalogue at sitelemetry.com/api/plans (no authentication, no data about your site beyond the same plugin User-Agent) to show the plan and usage box. Before a key is stored, the plugin makes no request to any external server.

The plugin stores in your WordPress database: the settings (API key, target, audit kind, weekly schedule), the last result of each audit kind (score, findings and coverage notes about the target) and, while an audit runs, the job state. It stores no data about visitors or users. Uninstalling the plugin removes all of it.

== Changelog ==

= 0.1.2 =
* Plugin URI now points at the plugin repository; the author URI stays sitelemetry.com (WordPress.org requires the two to differ).

= 0.1.1 =
* Tested with WordPress 7.1. Plugin Check (all categories) passes without errors or warnings.
* Description and Privacy sections link to the exact terms and privacy policy pages.

= 0.1.0 =
* First release: settings page, manual audits with progress polling, results page with severity filter and coverage notes, dashboard widget, optional weekly audit, plan and usage box.

== Upgrade Notice ==

= 0.1.2 =
Header metadata only. No functional change.

= 0.1.1 =
Tested with WordPress 7.1; exact terms and privacy links. No functional change.

= 0.1.0 =
First release.
