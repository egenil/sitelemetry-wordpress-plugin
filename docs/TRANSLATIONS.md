# Translations

The text domain is `sitelemetry-audit` (the plugin slug), with `Domain Path: /languages`. Every
translatable string is in `languages/sitelemetry-audit.pot`. Complete translations ship in
`languages/`:

| Locale | Language | translate.wordpress.org set | Form of address |
| --- | --- | --- | --- |
| `tr_TR` | Turkish | `tr/default` | siz (imperative -in) |
| `es_ES` | Spanish (Spain) | `es/default` | tú |
| `de_DE` | German | `de/default` | du (lower case), as the German team requires for `de_DE` |
| `de_DE_formal` | German (formal) | `de/formal` | Sie, as in the browser extension and sitelemetry.com |
| `fr_FR` | French (France) | `fr/default` | vous |
| `pt_BR` | Portuguese (Brazil) | `pt-br/default` | você |
| `it_IT` | Italian | `it/default` | tu |
| `ja` | Japanese | `ja/default` | です/ます |
| `zh_CN` | Chinese (China) | `zh-cn/default` | 您, as in WordPress core and sitelemetry.com |

Each locale has a `.po` (the source, importable into translate.wordpress.org) and a compiled `.mo`.

## How WordPress loads them

WordPress.org language packs are the primary channel. Since WordPress 4.6 core loads a plugin's
translations just in time from `wp-content/languages/plugins/`, so the plugin does not call
`load_plugin_textdomain()`. Plugin Check flags that call for plugins hosted on WordPress.org
(`PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound`, added in Plugin
Check 1.6.0 and a warning since 1.9.0, because WordPress.org-hosted plugins get their translations
loaded automatically), and since WordPress 6.7 the function only registers a directory for
the same just-in-time loader anyway.

Language packs only exist once a locale is 90% translated and approved on translate.wordpress.org.
Until then, `Sitelemetry_Audit_I18n` (`includes/class-sitelemetry-audit-i18n.php`) answers core's
`lang_dir_for_domain` filter (WordPress 6.6 and later): when core found no translation file for
`sitelemetry-audit` in the current locale and the plugin ships `languages/sitelemetry-audit-LOCALE.mo`,
it returns the plugin's `languages/` directory. When a language pack is installed, core finds it
first and the filter returns core's path unchanged, so community translations always take
precedence. The filter never touches other text domains, and a locale is only used as a file name
after it matches the WordPress locale pattern. WordPress 6.0 to 6.5 have no such filter: there
`Sitelemetry_Audit_I18n::load_bundled_legacy()` loads the bundled `.mo` of the current locale with
`load_textdomain()` on `init` (in the admin, WP-Cron and AJAX requests only), unless a language pack
for that locale is installed in `wp-content/languages/plugins/` or the text domain is already loaded.
Without it those versions showed the plugin in English while Sitelemetry wrote the audit text in the
admin's language.

A language pack replaces the bundled file completely: strings the pack does not translate yet are
shown in English, not taken from the bundled file. Packs are built at 90% coverage, so a new release
can show up to 10% of its strings in English until the locale catches up on translate.wordpress.org.

The Polyglots handbook allows shipping translations this way and notes that community translations
take precedence over bundled ones. Once a locale has its own language pack, its bundled
`.po`/`.mo` can stay (harmless) or be removed from the release.

Server text (finding titles, evidence, fixes, module reasons) is not in these files: the audit is
requested with `lang` from the admin's locale and Sitelemetry writes those texts in that language.

## Terminology

Terms follow the Sitelemetry app (`public/home.js`, Verified Domains), the landing page
(`public/i18n-landing.js`) and the browser extension (`_locales/*/messages.json`). WordPress menu
names follow the WordPress locale (for example `Ajustes` in `es_ES`, `Réglages` in `fr_FR`).

| English | tr | es | de | fr | pt_BR | it | ja | zh_CN |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| audit | denetim | auditoría | Prüfung | audit | auditoria | audit | 監査 | 审计 |
| finding | bulgu | hallazgo | Befund | constat | achado | rilievo | 検出事項 | 发现 |
| pillar | alan | pilar | Bereich | pilier | pilar | pilastro | 領域 | 领域 |
| passing checks | başarılı kontroller | comprobaciones superadas | bestandene Prüfungen | contrôles réussis | verificações aprovadas | controlli superati | 合格したチェック | 通过的检查 |
| not measured | ölçülmedi | no medido | nicht gemessen | non mesuré | não medido | non misurato | 未測定 | 未测量 |
| allowance | hak | cuota | Kontingent | quota | cota | quota | 利用枠 | 额度 |
| plan | plan | plan | Plan | offre | plano | piano | プラン | 套餐 |
| target | hedef | objetivo | Ziel | cible | alvo | target | ターゲット | 目标 |
| token | belirteç | token | Token | jeton | token | token | トークン | 令牌 |
| verification code (the app's name for the token) | doğrulama kodu | código de verificación | Verifizierungscode | code de vérification | código de verificação | codice di verifica | 検証コード | 验证代码 |
| Check now (refresh the running audit) | Şimdi kontrol et | Comprobar ahora | Jetzt abfragen | Actualiser maintenant | Atualizar agora | Controlla ora | 今すぐ確認 | 立即检查 |
| network administrator | ağ yöneticisi | administrador de la red | Netzwerk-Administrator | administrateur du réseau | administrador da rede | amministratore del network | ネットワーク管理者 | 网络管理员 |
| AI fix prompt | AI düzeltme promptu | prompt de corrección con IA | KI-Korrektur-Prompt | prompt de correction IA | prompt de correção com IA | prompt di correzione AI | AI修正プロンプト | AI 修复提示词 |
| Verified Domains | Doğrulanmış Alan Adları | Dominios verificados | Verifizierte Domains | Domaines vérifiés | Domínios verificados | Domini verificati | 検証済みドメイン | 已验证域名 |
| Start verification | Doğrulama başlat | Iniciar verificación | Verifizierung starten | Démarrer la vérification | Iniciar verificação | Avvia verifica | 検証を開始 | 开始验证 |
| HTTP verification file | HTTP doğrulama dosyası | Archivo de verificación HTTP | HTTP-Verifizierungsdatei | Fichier de vérification HTTP | Arquivo de verificação HTTP | File di verifica HTTP | HTTP検証ファイル | HTTP验证文件 |
| Verify HTTP / Verify DNS | HTTP’yi doğrula / DNS’i doğrula | Verificar HTTP / Verificar DNS | HTTP verifizieren / DNS verifizieren | Vérifier HTTP / Vérifier le DNS | Verificar HTTP / Verificar DNS | Verifica HTTP / Verifica DNS | HTTPを検証 / DNSを検証 | 验证HTTP / 验证DNS |
| Reverify | Yeniden doğrula | Volver a verificar | Erneut verifizieren | Revérifier | Verificar novamente | Verifica di nuovo | 再検証 | 重新验证 |
| one-click verification | tek tıkla doğrulama | verificación con un clic | Verifizierung mit einem Klick | vérification en un clic | verificação com um clique | verifica con un clic | ワンクリック検証 | 一键验证 |
| renew / renewal (of a verification) | yenilemek / yenileme | renovar / renovación | erneuern / Erneuerung | renouveler / renouvellement | renovar / renovação | rinnovare / rinnovo | 更新 | 续期 |
| My account (app) | Hesabım | Mi cuenta | Mein Konto | Mon compte | Minha conta | Il mio account | マイアカウント | 我的账户 |
| Manual connection and API key (app) | Manuel bağlantı ve API anahtarı | Conexión manual y clave de API | Manuelle Verbindung und API-Schlüssel | Connexion manuelle et clé API | Conexão manual e chave de API | Connessione manuale e chiave API | 手動接続と API キー | 手动连接和 API 密钥 |
| Sign in / Create account (the app's sign-in tabs; the onboarding button "Sign in or create an account" uses both) | Giriş yap / Hesap oluştur | Iniciar sesión / Crear cuenta | Anmelden / Konto erstellen | Se connecter / Créer un compte | Entrar / Criar conta | Accedi / Crea account | サインイン / アカウント作成 | 登录 / 创建账户 |
| Settings > General (WordPress) | Ayarlar > Genel | Ajustes > Generales | Einstellungen > Allgemein | Réglages > Général | Configurações > Geral | Impostazioni > Generali | 設定 > 一般 | 设置 > 常规 |

Japanese puts a half-width space between Japanese text and numbers or placeholders (`30 日`, `%s 監査`), as WordPress ja does; app button names copied verbatim (`HTTPを検証`) keep their spelling. German and Chinese call a pillar `Bereich` and `领域`, as sitelemetry.com does. French and Portuguese translate "Check now" as a refresh, so it is not mistaken for ownership verification on the same page.

Unmeasured states are stated as neutral facts. In Turkish that means `ölçülmedi` / `kısmen ölçüldü`,
never a failure form such as `ölçülemedi`; `tests/test-i18n.php` checks this. Brand and plan names
(`Sitelemetry`, `Free`, `Starter`) are not translated; the price label `Free` has its own string.

Turkish follows the WordPress Türkiye team glossary. On 2026-09-28 the tr_TR team reviewed the
338 Stable strings on translate.wordpress.org and corrected 33 of them; the bundled `tr_TR` file
matches that approved set. Their choices: token = `belirteç` (first use `Doğrulama belirteci (token)`),
performance = `başarım`, host-level = `sunucu düzeyi`, secrets = `gizli bilgiler`. Product names stay
in English, as in the other locales and as agreed with the team in #ceviri: the plugin name
`Sitelemetry Audit`, the plan names `Free` and `Starter` (context "plan name"; the price label `Free`
is `Ücretsiz`) and Google's `Search Console`. Discuss term changes with the tr_TR team (Slack
#ceviri) before overriding their approved strings.

## Updating the files

All tools run with Node.js 20 or newer from `tools/` (`npm install` once):

```sh
node make-pot.mjs          # regenerate languages/sitelemetry-audit.pot from the PHP sources
node make-mo.mjs --sync    # merge the new template into every .po (msgmerge-style), then check and compile
node make-mo.mjs           # only check and compile
node run-tests.mjs         # tests/test-i18n.php parses every .mo and checks placeholders and coverage
```

`make-mo.mjs` fails when a translation adds or drops a printf placeholder (`%s`, `%d`, `%1$s` ...),
when a plural entry has the wrong number of forms for the locale's `Plural-Forms`, or when a
compiled `.mo` does not read back to the same strings. After `--sync`, new strings are empty in every
`.po`; translate them (or import them from translate.wordpress.org, below) before the release, then
run `node make-mo.mjs` again. A `.mo` is written only for a `.po` that passes.

To bring community edits back into the repository, export each locale from translate.wordpress.org
(Stable project, "Export" at the bottom of the table, format "Portable Object Message Catalog
(.po/.pot)", "Only matching the filter" off), replace `languages/sitelemetry-audit-LOCALE.po`, keep the
header lines `Language:` and `Plural-Forms:`, and run `node make-mo.mjs`.

## Importing into translate.wordpress.org

translate.wordpress.org takes its original strings from the released code: the "Stable (latest
release)" sub-project follows the `Stable tag`, "Development (trunk)" follows `trunk/`. Import
after the release that contains these strings is live (the directory shows the new version),
otherwise strings that are not yet in the project are skipped.

1. Sign in at <https://translate.wordpress.org/> with the plugin owner's WordPress.org account.
2. For each locale open the Stable project, for example
   <https://translate.wordpress.org/projects/wp-plugins/sitelemetry-audit/stable/tr/default/>.
   Replace `tr/default` with `es/default`, `de/default`, `de/formal`, `fr/default`, `pt-br/default`,
   `it/default`, `ja/default` or `zh-cn/default`.
3. Scroll to the bottom of the table, choose **Import Translations**, select
   `languages/sitelemetry-audit-LOCALE.po` (`de/formal` takes `sitelemetry-audit-de_DE_formal.po`),
   leave the format on auto-detect and import. Importing into Stable also fills Development: the
   two are kept in sync for plugins.
4. Imported strings get the status **Waiting**. Only a translation editor for that locale can
   approve them and turn them into **Current**. Two ways to get there:
   - Ask for Project Translation Editor (PTE) rights for the plugin: post on
     <https://make.wordpress.org/polyglots/> with the title "PTE Request for Sitelemetry Audit", the
     plugin link written as `- https://wordpress.org/plugins/sitelemetry-audit/` and one line per
     locale, for example `o #tr_TR – @ozandikici`, `o #de_DE – @username`. The locale teams decide;
     they usually prefer a translator who speaks the language, so name a native speaker per locale
     where there is one. See the Polyglots handbook page "PTE Request".
   - Or ask the locale team (their Slack channel or P2) to review the waiting strings.
5. When at least 90% of the Stable strings of a locale are Current, translate.wordpress.org builds
   a language pack (about 30 minutes after the last approval). Sites receive it with the
   normal translation updates in `wp-content/languages/plugins/`, and from then on it replaces the
   bundled file for that locale.
6. The readme (directory page) is a separate sub-project ("Stable Readme"); it is not covered by
   these files and can be translated on the same site.

Check the progress per locale at
<https://translate.wordpress.org/projects/wp-plugins/sitelemetry-audit/>.
