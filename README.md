# Sucuri Cache for OpenCart 3.0.x

Purge the **Sucuri Website Firewall (CloudProxy)** edge cache from the OpenCart admin, from cron or from a deploy hook.

Sucuri caches static files (js, css, png, jpg, jpeg, svg, gif, ico, txt, mp4, mp3, pdf, woff, ttf, swf, thumb, and in practice xml too) for 24 hours. It does this regardless of the Caching Level setting and rewrites `Cache-Control` to `max-age=86400`. A CSS/JS deploy on an unversioned URL therefore stays invisible to visitors for up to a day, unless the edge cache is purged.

Built against the core branch [`opencart/opencart@3.0.x.x`](https://github.com/opencart/opencart/tree/3.0.x.x) (3.0.5.x). It should work on 3.0.3.x and later. No core files are edited: the only core touch is an OCMOD that adds a menu entry.

## Features

- **Purge everything**: one click, with a local 2-minute throttle. Sucuri ignores a second full purge within about 2 minutes, so a repeat click doesn't waste an API request.
- **Purge specific files**: one URL or path per line, up to 10 per run (the API allows 12 requests/minute). Anything over the limit is reported as skipped.
- **Asset list**: a saved list of files (e.g. theme CSS/JS) that you can purge with one button.
- **Test credentials**: a read-only `show_settings` call that does not touch the cache.
- **CLI script** for cron and deploy hooks, with exit codes.
- Rate-limit aware: HTTP 429 and `Retry-After` are reported back to the user.
- Every action is logged to the OpenCart error log, prefixed with `Sucuri WAF:`.
- Admin language: `en-gb`. For another language, copy `admin/language/en-gb/extension/module/sucuri_cache.php` into that language's folder and translate it.

## Requirements

- OpenCart 3.0.x (PHP version as required by your OpenCart build; 3.0.5.x needs PHP 8.1+)
- PHP cURL extension
- A site behind the Sucuri Firewall, and its API key/secret (Sucuri dashboard → Firewall → your site → Settings → API)

## Installation

### Option A: Extension Installer

1. Zip the **contents** of this folder (`install.xml` plus the `upload/` folder) as `sucuri_cache.ocmod.zip`.
2. Admin → Extensions → Installer → upload the zip.
3. Extensions → Modifications → **Refresh**. This step adds the menu entry.
4. Extensions → Extensions → Modules → **Sucuri Cache** → Install → Edit.
5. Enter the API key and secret, click **Test credentials**, then **Save**.

### Option B: Manual

1. Copy the contents of `upload/` into the OpenCart root.
2. Upload `install.xml` under Extensions → Installer, or skip it if you don't want the menu entry. Then go to Modifications → **Refresh**.
3. Carry on from step 4 above.

On install, the module grants the `access`/`modify` permission to the installing user's group, as every OpenCart extension does. Grant it to other groups under System → Users → User Groups.

## Credentials

The module resolves credentials in this order:

| Where | Admin module | CLI script |
|---|---|---|
| `SUCURI_WAF_KEY` / `SUCURI_WAF_SECRET` constants | `admin/config.php` | root `config.php` |
| Environment variables of the same name | – | yes |
| Module settings (database) | yes | yes |

Defining the constants keeps the secret out of the database and out of database backups:

```php
define('SUCURI_WAF_KEY', '...');
define('SUCURI_WAF_SECRET', '...');
```

The key field also accepts the combined `KEY/SECRET` value that Sucuri shows as "API Key (for plugin)".

## CLI / cron

The script lives under `system/`, which OpenCart's `.htaccess` blocks from HTTP access. The script also refuses to run outside the CLI.

```bash
php system/library/sucuri/purge.php                    # full purge (2-minute throttle)
php system/library/sucuri/purge.php --force            # full purge, ignore the throttle
php system/library/sucuri/purge.php --assets           # purge the asset list saved in the admin
php system/library/sucuri/purge.php product_feed.xml catalog/view/theme/default/stylesheet/stylesheet.css
```

| Argument | What it does |
|---|---|
| *(none)* | Full purge. Skipped if the previous one was less than 120 s ago. |
| `--force` | Full purge without the 120-second check |
| `--assets` | Purge only the asset list saved in the admin module |
| `file1 file2 …` | Purge only these files, up to 10 per run. Paths are relative to the store root; full URLs also work. |

Exit codes: `0` purged, `1` error, `2` skipped by the 120-second check.

The script finds `config.php` on its own, so it does not depend on the current directory. It does need an absolute path in the command. It reads the credentials as described in [Credentials](#credentials), so cron needs nothing extra.

### Running it from cron

Use the full path to the PHP binary. On cPanel this is usually `/usr/local/bin/php`; run `which php` over SSH to check.

Purge a generated feed 5 minutes after it is rebuilt (here the feed is rebuilt at minute 0 every 2 hours):

```
5 */2 * * * /usr/local/bin/php /path/to/opencart/system/library/sucuri/purge.php product_feed.xml >/dev/null 2>&1
```

Purge the theme CSS/JS from the asset list once a night:

```
30 4 * * * /usr/local/bin/php /path/to/opencart/system/library/sucuri/purge.php --assets >/dev/null 2>&1
```

Full purge right after a deploy, from a deploy script rather than a fixed time:

```bash
git pull --ff-only origin main && php system/library/sucuri/purge.php
```

To add a cron job, use cPanel → Cron Jobs, or run `crontab -e` as the site user. Don't add it as root: the throttle file `last_purge.json` would then be owned by root.

To keep your own copy of the output, replace `>/dev/null 2>&1` with `>> /path/to/sucuri-purge.log 2>&1`. Set `MAILTO=""` at the top of the crontab if you don't want an email for every run.

Before you schedule purges:

- **Prefer per-file purges over full purges.** A full purge empties the whole edge cache, so for a while all traffic goes straight to the origin.
- **Stay inside the API limits:** 12 requests/minute and 60 requests/hour per API key. Each file counts as one request.
- **Versioning beats a schedule.** For CSS/JS changed by a deploy, run the purge in the deploy itself, or version the URL (see the tip below), rather than on a fixed timer.

Every CLI purge is also written to the store's error log (System → Maintenance → Error Logs), in the same format as the admin purges and marked `(cli)`.

## Tip: avoid needing a purge at all

Version your theme assets with the file's modification time. The URL, and with it the Sucuri cache key, then changes only when the file changes. For example, in `catalog/controller/common/header.php`:

```php
$data['css_version'] = filemtime(DIR_APPLICATION . 'view/theme/default/stylesheet/stylesheet.css');
```

and in `header.twig`: `stylesheet.css?v={{ css_version }}`.

## Files

```
install.xml                                            OCMOD: System > Maintenance > Sucuri Cache
upload/admin/controller/extension/module/sucuri_cache.php
upload/admin/language/en-gb/extension/module/sucuri_cache.php
upload/admin/view/template/extension/module/sucuri_cache.twig
upload/system/library/sucuri/waf.php                   API client (no OpenCart dependency)
upload/system/library/sucuri/purge.php                 CLI entry point
```

The time of the last full purge is stored in `DIR_STORAGE . 'sucuri/last_purge.json'`.

## Uninstall

Extensions → Modules → Sucuri Cache → Uninstall removes the settings. Then delete the files listed above and the `sucuri_cache_menu` modification, and Refresh.
