# Submitting Sitelemetry Audit to WordPress.org

This is the step list for the first submission of the plugin (slug `sitelemetry-audit`) and for later releases. Everything here is done by a person with the Sitelemetry WordPress.org account; nothing is automated.

## 1. Before the upload

Work through this list on the built tree, not on the development checkout.

| Check | Where | Status in this tree |
| --- | --- | --- |
| `Contributors:` lists WordPress.org usernames (profiles.wordpress.org/NAME) | `readme.txt` | `ozandikici` (the WordPress.org account signed in on 2026-09-21; its e-mail is a personal address, see step 3) |
| `Stable tag:` equals the `Version:` header | `readme.txt`, `sitelemetry-audit.php` | Both `0.1.2` |
| `Tested up to:` is the current WordPress major after a real test in it | `readme.txt` | `7.1`, set after the Playground test in WordPress 7.1.1 (2026-09-21) |
| `Requires at least: 6.0`, `Requires PHP: 7.4` | `readme.txt`, `sitelemetry-audit.php` | Set |
| `Plugin URI` and `Author URI` differ | `sitelemetry-audit.php` | Plugin URI = the GitHub repository, Author URI = sitelemetry.com. The upload form rejects identical values ("Your plugin and author URIs are the same") |
| External service disclosure with links to the terms and the privacy policy | `readme.txt`, Description and Privacy | Written; links to https://sitelemetry.com/terms and https://sitelemetry.com/privacy |
| The Privacy section names everything the requests actually carry, including the User-Agent | `readme.txt` Privacy, `Sitelemetry_Audit_Client::user_agent()` | In sync: the header is `sitelemetry-audit-wordpress/VERSION` and discloses nothing about the site (no WordPress version). Re-check both whenever either changes |
| The API key claims are the same everywhere (stored verbatim in the options table, displayed masked) | `readme.txt` (Description, FAQ), `CHANGELOG.md`, `Sitelemetry_Audit_Settings` | In sync; never describe the stored key as masked or encrypted |
| The plugin name and slug use the Sitelemetry trademark | submission form | The submitting account must represent Sitelemetry (guideline 17); the review team asks for proof when it cannot link the account to the brand. The slug cannot be changed after approval |
| Plugin Check passes | test site with the Plugin Check plugin (`wordpress/plugin-check`) | Run in WordPress Playground (Plugin Check, all categories) on 2026-09-21: clean after the 0.1.1 fixes; WordPress.org runs it again during the upload |
| Zip built from `.distignore` | `tools/build-zip.mjs` or `wp dist-archive .` | `dist/sitelemetry-audit-0.1.2.zip`; it contains one top-level folder `sitelemetry-audit/` |

The zip must not contain `tests/`, `docs/`, `tools/`, `CHANGELOG.md` or another zip; `.distignore` excludes them.

## 2. Test in a real WordPress once

Done on 2026-09-21 in WordPress Playground (WordPress 7.1.1, plugin installed from the GitHub release zip with a blueprint in the URL fragment): steps 1-4 and 6 below passed with the owner's real key (settings saved, key shown masked, a real security audit of sitelemetry.com rendered "Completed with partial coverage", score 81/100 C, 14 findings, the not-measured list, the findings table with the severity filter, the plan and usage table, and the dashboard widget with the last score; the weekly schedule shows the next run). Step 5 (deactivate/delete cleanup) is covered by the php-wasm tests only. For a repeat, use Playground, `wp-env` (`npx @wordpress/env start` with the plugin folder mapped) or Local. Then:

1. Activate the plugin; **Settings > Sitelemetry** appears and the dashboard widget shows "Add API key".
2. Save a Sitelemetry MCP API key of an account you control; the settings page shows it masked.
3. Run the security audit against the site itself (or another site the account is authorized to test); the results tab shows the progress box, then the result. Check the severity filter, the "What was not measured" list on a Free account, the plan and usage box and the dashboard widget.
4. Enable the weekly audit and save; the settings page shows the next run. `wp cron event list` (or the WP Crontrol plugin) lists `sitelemetry_audit_weekly`.
5. Deactivate: the cron events disappear. Delete the plugin: `wp option list --search=sitelemetry_audit_*` returns nothing.
6. Install the Plugin Check plugin and run it on Sitelemetry Audit; fix every error it reports.

Set `Tested up to:` to the WordPress version used.

## 3. Account

1. The submitting account is `ozandikici` (profiles.wordpress.org/ozandikici). The plugin belongs to this account; further committers can be added later from the plugin's Advanced view.
2. Guideline 17: the plugin name starts with the Sitelemetry brand, and the review team verifies brand ownership through the e-mail address of the WordPress.org account. Before submitting, set that address to a sitelemetry.com mailbox at <https://profiles.wordpress.org/ozandikici/profile/edit/> and confirm it; otherwise expect a trademark question in the first review e-mail and answer it with proof (a reply from support@sitelemetry.com or a link to the plugin from sitelemetry.com).
3. The same login is used for the SVN repository after approval.

## 4. Upload

1. Open <https://wordpress.org/plugins/developers/add/> while signed in.
2. Upload `dist/sitelemetry-audit-0.1.2.zip`. The form shows the slug that will be assigned (`sitelemetry-audit`, derived from the Plugin Name). If it differs, change the `Plugin Name` header and rebuild before submitting.
3. Confirm the guideline and trademark statements on the form and submit.
4. The automated Plugin Check runs at once. If it reports errors, fix them, rebuild and upload again.

## 5. Review

- The plugin review team (plugins@wordpress.org) reviews by hand. The add page shows the current queue length; the first reply usually arrives within days to a few weeks.
- Every reply is an e-mail. Answer each point, upload the corrected zip through the link in that e-mail (not as a new submission) and say what changed. Submissions without an answer are closed after a period of inactivity.
- Likely questions for this plugin and the factual answers:
  - *Calls to an external service.* The plugin is a thin client for the hosted Sitelemetry service; the Description and Privacy sections say exactly what is sent (target URL and audit kind, authenticated with the key). No request leaves the site before the admin stores a key.
  - *Sanitization, escaping, nonces.* Every admin-post and AJAX handler checks `manage_options` and a nonce; settings pass through `Sitelemetry_Audit_Settings::sanitize()`; every value printed by the views is escaped.
  - *Prefixes.* Functions, classes, options, transients, hooks and script handles use `sitelemetry_audit` / `Sitelemetry_Audit_`.
  - *Trademark.* Provide the proof that the submitting account represents Sitelemetry.
  - *Files that do not belong in the zip.* Tests, docs and tools are excluded by `.distignore`; confirm by listing the zip.
- Approval comes by e-mail with the SVN URL `https://plugins.svn.wordpress.org/sitelemetry-audit/`.

## 6. First SVN commit

```sh
svn co https://plugins.svn.wordpress.org/sitelemetry-audit sitelemetry-audit-svn
cd sitelemetry-audit-svn
# unzip the built archive and copy its contents (the files, not the folder) into trunk/
svn add trunk/*
svn cp trunk tags/0.1.2
# directory assets, see section 7
svn add assets/*
svn ci -m "Release 0.1.2" --username WORDPRESS_ORG_USER
```

The plugin page appears after the first commit; the directory builds the download from the `Stable tag` (`tags/0.1.2`).

## 7. Directory assets

Placed in the `assets/` folder of the SVN repository (not inside the plugin):

| File | Size | Notes |
| --- | --- | --- |
| `icon-128x128.png`, `icon-256x256.png` (or `icon.svg`) | 128x128, 256x256 | Sitelemetry mark on a plain background |
| `banner-772x250.png`, `banner-1544x500.png` | 772x250, 1544x500 | Optional; product name and one line |
| `screenshot-1.png` to `screenshot-6.png` | 1280 px wide recommended | Order and captions follow the `== Screenshots ==` section of `readme.txt` |

## 8. Screenshots to prepare

Taken on 2026-09-21/22 with headless Chrome from WordPress Playground (WordPress 7.1.1, plugin 0.1.2) at 1280 px width, default color scheme, with real audit results of sitelemetry.com stored through the plugin's own classes and a placeholder key (the plugin shows keys masked, so no screenshot exposes a key). Tooling and the reproducible commands live outside the repository.

| File | Caption in readme.txt | State to capture |
| --- | --- | --- |
| `screenshot-1.png` | Settings: the stored API key shown masked, the target, the audit kind and the weekly audit with its next scheduled run | Key stored (masked), target https://sitelemetry.com, audit kind select (closed; a native select cannot be captured open), weekly audit checked with the next run shown |
| `screenshot-2.png` | Results of a completed security audit: status banner, score and grade, findings by severity and the passing checks | A completed security audit (limited module selection so every module is measured): banner "Completed", the score card, the severity chips, passing checks |
| `screenshot-3.png` | The findings table with the severity filter and a row expanded to show evidence, impact and the fix | The findings table filtered to the highest severity present (Medium in the real data), one row with "Evidence and impact" expanded |
| `screenshot-4.png` | A partially covered audit: the banner, the score and the list of what was not measured (unmeasured checks are not passes) | A full-scope audit with partial engine coverage: banner "Completed with partial coverage", the score card, the "What was not measured" list (no verification sentence: the target is verified) |
| `screenshot-5.png` | The plan and usage box: the connected plan and what each paid plan includes, with links to pricing and the app | The box with the connected-plan line, the paid plans table and both links (the remaining-scans line appears only when the service reports it) |
| `screenshot-6.png` | The dashboard widget with the last score, grade, status and findings by severity, one click from the full results | The WordPress dashboard (Welcome panel dismissed) with the widget after a completed audit |

## 9. Releasing an update

1. Bump `Version:` in `sitelemetry-audit.php`, `SITELEMETRY_AUDIT_VERSION`, `Stable tag:` in `readme.txt`; add the changelog entry to `readme.txt` and `CHANGELOG.md`.
2. Regenerate `languages/sitelemetry-audit.pot`, run the tests, build the zip.
3. Copy the built files over `trunk/`, `svn cp trunk tags/VERSION`, commit. The directory picks up the new stable tag within minutes.
4. After each WordPress release, test and raise `Tested up to:` in `trunk/readme.txt` (a commit to trunk is enough).
