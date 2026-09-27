=== Sitelemetry Audit – Security, SEO & AI Readiness Scanner ===
Contributors: ozandikici
Tags: security, seo, security scanner, site audit, ai
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Audit your site for security (free plan), SEO and AI readiness: a score, every finding with its fix, passing checks and an AI fix prompt.

== Description ==

Sitelemetry Audit connects your site to the hosted [Sitelemetry](https://sitelemetry.com) audit service. From **Settings > Sitelemetry** you run a security scan or a full site audit and read the result inside WordPress: a score and grade, every finding with its severity, location and fix, the checks that passed, a clear list of what was not measured and why, and a ready-made prompt for your AI assistant to fix the findings.

**Audit kinds**

* Security (included in every plan, including Free): DNS and email DNS, WHOIS/RDAP, TLS, HTTP security headers, HTTPS posture, technology fingerprint and, once the site is verified, exposed files, HTTP methods and API exposure. Paid plans add the WordPress checks, port scans and more modules; these need a verified site.
* Technical SEO, AI visibility (AI readiness), Integrations, Accessibility, Performance and Full (all pillars with one blended score) on paid plans.

**What the plugin does**

* **Results in WordPress**: a status banner, the score and grade, the findings with a severity filter and each finding's evidence, impact and fix.
* **Passing checks**: the checks that passed, grouped by security module, each with its evidence.
* **What was not measured**: the checks that did not run, with readable module names and the likely cause when the site's own answers explain it (a redirect, a rate limit, a server error, no answer in time, ports a firewall silently drops, the per-audit script limit), the pages a crawl found but could not assess (for example because robots.txt disallows them), the metrics that were not measured (such as the Core Web Vitals when PageSpeed Insights returns no data), and the individual checks that ended without a pass or fail result (an error, no result, a manual review, not applicable). Unmeasured checks are never counted as passes.
* **Copy AI fix prompt**: one click copies a prompt built from the result (the findings with evidence and fixes, and what was not measured) for ChatGPT, Claude or your coding assistant. The prompt is built in wp-admin and only copied to your clipboard; nothing is sent. The assistant is asked to answer in your WordPress admin language.
* **Verify this site**: some checks run only on sites whose ownership is verified. With one click the plugin gets a verification code from Sitelemetry, publishes the verification file for this site and has Sitelemetry check it, so you need no FTP access; it renews the verification automatically when it expires and tells you exactly what to fix when a check fails (for example a server that blocks /.well-known/ or a redirect between www and non-www). Where one-click verification is not available yet, paste the token from the Sitelemetry app instead; a "Test the file" button checks that the file is reachable. On a multisite network only a network administrator can publish the file.
* **Dashboard widget** with the last score, the findings by severity, a reminder when the site needs to be verified or its verification renewed, and a link to the results. After a weekly audit that needs verification, a notice on the Dashboard and Plugins screens says so until you dismiss it.
* **Settings page** with a **Sign in or create an account** button (the Sitelemetry sign-in page, where new users create an account on a free or paid plan) and a link to the page that shows your API key, your Sitelemetry MCP API key (stored in the WordPress options table, shown masked, removable), the target (defaults to your site address), the audit kind and an optional weekly audit through WP-Cron.
* **Plan and usage box** with the connected plan and what each paid plan includes, read from the public plan catalogue.

**Languages**

The plugin's screens are available in English, Turkish, Spanish, German (informal and formal), French, Portuguese (Brazil), Italian, Japanese and Simplified Chinese. The audit itself is requested in your WordPress admin language when Sitelemetry supports it (English, Turkish, Spanish, German, French, Portuguese, Italian, Japanese, Simplified Chinese), so findings, evidence and fixes arrive in that language too; otherwise in English. Translations from translate.wordpress.org take precedence over the bundled ones, which the plugin uses when no language pack is installed.

**Plans and allowance**

Sitelemetry has free and paid plans. A Free Sitelemetry account includes the security audit with the public security modules and a monthly number of security scans; the public plan catalogue at sitelemetry.com/api/plans is the source of truth and the plugin reads it for the plan and usage box. Each completed audit uses one unit of the monthly allowance of the connected account; polling a running audit uses nothing more. When the connected account does not include an audit kind or has used its monthly allowance, the audit is not started, no allowance is used and the results page says so.

**Ownership**

Audit only websites you own or are explicitly authorized to test. Every audit is performed by Sitelemetry against the live target and is recorded on the connected account. Protected checks (for example HTTP methods and exposed files, and on paid plans the WordPress checks) require ownership verification of the host in Sitelemetry; results list the checks that were left unmeasured for this reason.

To verify from WordPress, save your API key and click **Verify this site** in **Settings > Sitelemetry**. The plugin asks Sitelemetry for a verification code for this site's exact host (www and non-www are different hosts), answers `/.well-known/sitelemetry-verification.txt` on this host with that code and nothing else, tests the file and asks Sitelemetry to check it; the page then says "Verification complete ✓ valid until" and the date, or which problem to fix. A verification lasts 30 days; the plugin renews it automatically in the background (WP-Cron) when it expires or when an audit reports that it must be renewed, never earlier, and shows a notice only if the renewal fails. **Remove token** stops publishing the file and the automatic renewal until you click **Verify this site** again or save a token. Where one-click verification is not available yet, the manual steps remain: in the Sitelemetry app open **Verified Domains**, enter the host and click **Start verification**, copy the verification code (the token) shown under **HTTP verification file**, paste it into **Verify this site**, click **Test the file**, then click **Verify HTTP** in the app (renew with **Reverify** in the app). Without a token the path answers 404. A DNS TXT record or Google Search Console work as alternatives. On a multisite network the web server behind a site's host serves the whole network, so only a network administrator can publish the file there.

**Thin client**

The plugin bundles no libraries and runs no scanner on your server. It sends requests to sitelemetry.com with the WordPress HTTP API and renders the response. There is no tracking and no analytics.

**External service**

This plugin relies on the hosted Sitelemetry service (https://sitelemetry.com) operated by Sitelemetry. It sends requests to the service only when you run an audit or after you have stored an API key, as described in the Privacy section below. The terms of service are published at https://sitelemetry.com/terms and the privacy policy at https://sitelemetry.com/privacy.

== Installation ==

1. Upload the `sitelemetry-audit` folder to `/wp-content/plugins/` or install it from the Plugins screen.
2. Activate the plugin.
3. Sign in at [sitelemetry.com/app](https://sitelemetry.com/app) (the settings page's **Sign in or create an account** button opens it). New to Sitelemetry? Switch to **Create account** on the same page and choose a plan: Sitelemetry has free and paid plans, and the Free plan includes the security audit. Then open **My account**, expand **Manual connection and API key** and copy the API key (the settings page's **Get your API key** link opens that page).
4. Go to **Settings > Sitelemetry**, paste the key, check the target and save.
5. Click **Run audit**. The results tab updates while the audit runs.
6. Optional: click **Verify this site** on the same page to include the protected checks.

The site must be able to make outgoing HTTPS requests to sitelemetry.com.

== Frequently Asked Questions ==

= Does the plugin scan my site itself? =

No. Sitelemetry audits the live target from its own infrastructure. The plugin only starts the audit, polls its progress and shows the result.

= What does an audit cost? =

A completed audit uses one unit of the monthly allowance of the connected Sitelemetry account. Polling a running audit uses nothing more. Audits that are not started (plan not included, allowance exhausted, verification required) use nothing.

= What data does the plugin send, and where? =

Only to sitelemetry.com, and only after you store an API key: the target URL, the audit kind and the report language (from your WordPress admin language), authenticated with your API key, plus the plugin's User-Agent. For ownership verification the plugin sends this site's address (scheme and host). No site content, users, visitors, plugin list or WordPress version are sent. "Test the file" requests this site's own verification file from your server and contacts no other host. The Privacy section below lists every request.

= Why does the result say "partial" or list checks that were not measured? =

Some checks need ownership verification of the target or a plan that includes them, or a measurement was unavailable for the target. The results page lists each of them under "What was not measured", with the likely cause when the site's own answers explain it. Use **Verify this site** in the plugin settings, a DNS record or Google Search Console to include the protected checks.

= How does "Verify this site" work? =

Sitelemetry proves that you control a host by fetching `https://<host>/.well-known/sitelemetry-verification.txt` and comparing it with a token it generated for your account. With one click the plugin requests that token with your API key, serves the file for this site's own host (only for exactly this host and path) and asks Sitelemetry to check it, so no FTP upload is needed; it renews the verification in WP-Cron when it expires and never replaces a verification that is still valid. When a check fails, the page names the cause and the fix: a web server that keeps /.well-known/ for itself, a firewall or password, a redirect between www and non-www, a cached or other file at that path, no answer in time, DNS or TLS problems, too many attempts or a key Sitelemetry did not accept. Where one-click verification is not available yet, paste the token from the app; "Test the file" fetches the file the way Sitelemetry will and reports redirects to another host, 404 answers from the web server, firewall or maintenance pages and wrong content. Local and private sites cannot be verified this way, and subdirectory installs usually cannot; use the DNS record instead. Sitelemetry fetches the default port of the host (443 or 80), so a site that runs on another port must also answer there. On a multisite network only a network administrator (the `manage_network_options` capability, filterable with `sitelemetry_audit_verification_capability`) can publish the file, because the server behind the host is shared by the whole network.

= Does the AI fix prompt send my data anywhere? =

No. The prompt is built by WordPress from the stored result and the button only copies it to your clipboard. It contains the audited address and the findings, never your API key. You decide where to paste it.

= Where is the full report? =

The results page in WordPress is the result: Sitelemetry does not keep a separate report of audits run through the plugin. Run the audit again to refresh it.

= Which languages does the plugin support? =

The screens are translated into Turkish, Spanish, German (informal and formal), French, Portuguese (Brazil), Italian, Japanese and Simplified Chinese, and audit results arrive in your admin language when Sitelemetry supports it. The bundled translations work from WordPress 6.0; a language pack from translate.wordpress.org replaces them completely, so strings a pack does not translate yet appear in English. You can improve or add translations at translate.wordpress.org.

= How does polling work? =

Long audits return a job id. While the results page is open, the browser asks WordPress every few seconds (as Sitelemetry suggests) for one progress step; each step is one request from your server to Sitelemetry with the job arguments unchanged. If you leave the page, a WP-Cron event finishes the job in the background. A total time budget of 20 minutes applies; when a job exceeds it, run the audit again later to get a result.

= Where is the API key stored? =

In the WordPress options table, like other plugin settings. The settings page shows it masked, it is never written to logs and it is sent only to sitelemetry.com as a Bearer token.

= Can I audit a site other than this one? =

The target defaults to this site's address and can be changed to any URL you own or are authorized to test. Do not audit sites you are not authorized to test. The verification helper publishes the file only for this site's own host; verify other hosts in the Sitelemetry app.

= Does the weekly audit work on a low-traffic site? =

WP-Cron runs when the site receives visits. If your host runs a system cron for WordPress (recommended), the weekly audit runs on time regardless of traffic.

== Screenshots ==

1. Settings: the stored API key shown masked with where to find it in the app, the target, the audit kind and the weekly audit with its next scheduled run.
2. Results of a completed security audit: status banner, score and grade, findings by severity, the Copy AI fix prompt button, the findings table and the collapsed Passing checks section.
3. The findings table with the severity filter and a row expanded to show evidence, impact and the fix.
4. A partially covered audit: what was not measured, with readable module names, the likely cause of each gap, and the checks without a result or that do not apply (unmeasured checks are not passes).
5. The plan and usage box: the connected plan and what each paid plan includes, with links to pricing and the app.
6. The dashboard widget with the last score, grade, status and findings by severity, one click from the full results.
7. The passing checks, grouped by security module, each with its evidence.
8. Verify this site after one click: the exact host, "Verification complete" with its expiry date, the published file tested, automatic renewal, and the other ways to verify (a token from the app, a DNS record or Google Search Console).

== Privacy ==

When you run an audit, the plugin sends to sitelemetry.com the target URL, the audit options you configured (the audit kind) and the report language (derived from your WordPress admin language), authenticated with your API key. Every request also identifies the client in its User-Agent header (`sitelemetry-audit-wordpress/` and the plugin version); no WordPress version, site name, user, plugin list or other data from your site or your server is sent. Sitelemetry then audits the live target from its own infrastructure and records the audit on the connected account, as described in the Sitelemetry terms (https://sitelemetry.com/terms) and privacy policy (https://sitelemetry.com/privacy).

Once an API key is stored, the plugin also reads the public plan catalogue at sitelemetry.com/api/plans (no authentication, no data about your site beyond the same plugin User-Agent) to show the plan and usage box.

For ownership verification the plugin uses the verification endpoints of sitelemetry.com (`/api/v1/verification/status`, `/challenge` and `/verify`), authenticated with your API key, and sends only this site's address (scheme and host). It asks for the status when an administrator opens the settings page (the answer is kept for up to a week, or for an hour while the service offers no verification endpoints), and requests and checks a verification code when you click Verify this site and when the automatic renewal runs in WP-Cron (when the verification expires or an audit reports that it must be renewed). To check the code, Sitelemetry requests `/.well-known/sitelemetry-verification.txt` from this site.

Before a key is stored, the plugin makes no request to any external server.

The plugin stores in your WordPress database: the settings (API key, target, audit kind, weekly schedule), the last result of each audit kind (score, findings, passing checks and coverage notes about the target), the verification token you paste or the plugin obtains (with the time and outcome of the last file test), the verification status Sitelemetry reported for this site's host and the outcome of the last verification or renewal, for each administrator which weekly-audit and renewal notices they dismissed and, while an audit runs, the job state. It stores no data about visitors or users and uses no cookies or tracking. Uninstalling the plugin removes all of it.

The verification token is public by design: once you save it, the plugin serves it at `/.well-known/sitelemetry-verification.txt` so Sitelemetry can confirm you control the site. "Test the file" requests that address from your own server; it makes no request to any other host. The AI fix prompt is only copied to your clipboard.

== Changelog ==

= 0.1.3 =
* Passing checks: the results page lists the checks that passed, grouped by security module, each with its evidence (full audits included).
* What was not measured: readable module names and the likely cause (redirects, rate limits, server errors, no answer in time, silently dropped ports, the per-audit script limit), with the service's own reason underneath, the pages a crawl could not assess and the metrics that were not measured, and the individual checks without a pass or fail result, grouped by status. Both sections are collapsible.
* Copy AI fix prompt: builds a prompt from the result in wp-admin and copies it to the clipboard; nothing is sent.
* Verify this site with one click: the plugin gets the verification code from Sitelemetry with your API key, publishes the verification file for this site's host, tests it and has Sitelemetry check it, then shows "Verification complete ✓ valid until" the date, or the exact cause and fix of a failure. It renews the verification automatically in WP-Cron when it expires or an audit reports that it must be renewed, and shows a notice only if the renewal fails. Where the service does not offer one-click verification yet, the manual helper (paste the token from the app, Test the file) stays as before. On multisite, only network administrators can publish the file.
* Settings: a "Sign in or create an account" button (the Sitelemetry sign-in page; new users create an account there on a free or paid plan) and a "Get your API key" link for a first setup.
* The running-audit status line is the plugin's own; it no longer shows the service's instructions for AI clients.
* Translations: Turkish, Spanish, German (informal and formal), French, Portuguese (Brazil), Italian, Japanese and Simplified Chinese ship with the plugin (WordPress 6.0 and later); translate.wordpress.org language packs take precedence.
* Audits are requested in your WordPress admin language when Sitelemetry supports it.
* Removed the "Open the full report" link and the remaining-scans lines: the service returns neither to the plugin, and no text promises a report in the app anymore.
* New directory name: Sitelemetry Audit – Security, SEO & AI Readiness Scanner. The slug and settings are unchanged.

= 0.1.2 =
* Plugin URI now points at the plugin repository; the author URI stays sitelemetry.com (WordPress.org requires the two to differ).

= 0.1.1 =
* Tested with WordPress 7.1. Plugin Check (all categories) passes without errors or warnings.
* Description and Privacy sections link to the exact terms and privacy policy pages.

= 0.1.0 =
* First release: settings page, manual audits with progress polling, results page with severity filter and coverage notes, dashboard widget, optional weekly audit, plan and usage box.

== Upgrade Notice ==

= 0.1.3 =
Passing checks, explained coverage gaps, a copy-ready AI fix prompt, one-click site verification with automatic renewal and the plugin in nine languages.

= 0.1.2 =
Header metadata only. No functional change.

= 0.1.1 =
Tested with WordPress 7.1; exact terms and privacy links. No functional change.

= 0.1.0 =
First release.
