<?php
// Heading
$_['heading_title']             = 'Sucuri Cache';

// Text
$_['text_extension']            = 'Extensions';
$_['text_success']              = 'Success: You have modified the Sucuri Cache module!';
$_['text_edit']                 = 'Edit Sucuri Cache';
$_['text_credentials']          = 'Firewall API credentials';
$_['text_credentials_help']     = 'Sucuri dashboard &rarr; Firewall &rarr; your site &rarr; Settings &rarr; API. You may paste either the split API_KEY / API_SECRET, or the combined "API Key (for plugin)" value into the key field.';
$_['text_from_config']          = 'Credentials are pinned in admin/config.php (SUCURI_WAF_KEY / SUCURI_WAF_SECRET) and override the fields below.';
$_['text_purge_all']            = 'Purge the whole edge cache';
$_['text_purge_all_help']       = 'Clears every cached page and static asset at the Sucuri edge. Sucuri ignores a second full purge within 2 minutes, so the button is throttled locally.';
$_['text_purge_files']          = 'Purge specific files';
$_['text_purge_files_help']     = 'One URL or path per line, e.g. catalog/view/theme/default/stylesheet/stylesheet.css. Maximum 10 per run (API limit is 12 requests/minute); anything beyond that is reported as skipped.';
$_['text_assets_help']          = 'Files purged by the "Purge asset list" button - typically the theme CSS/JS that has no ?v= cache-buster. One per line.';
$_['text_static_note']          = 'Sucuri always caches js, css, png, jpg, jpeg, svg, gif, ico, txt, mp4, mp3, pdf, woff, ttf, swf and thumb files for 24h, regardless of the Caching Level setting. A front-end deploy on an unversioned URL stays invisible until you purge here.';
$_['text_last_purge']           = 'Last full purge:';
$_['text_never']                = 'never';
$_['text_purged_all']           = 'Success: the Sucuri edge cache has been purged.';
$_['text_purged_files']         = 'Success: %d file(s) purged.';
$_['text_purged_files_partial'] = 'Warning: only %d of %d file(s) were purged - see the report below.';
$_['text_skipped']              = 'SKIPPED %s - over the 10 files per run limit, purge it in the next run.';
$_['text_test_ok']              = 'Success: the API credentials are valid.';
$_['text_working']              = 'Working...';

// Entry
$_['entry_status']              = 'Status';
$_['entry_key']                 = 'API key';
$_['entry_secret']              = 'API secret';
$_['entry_assets']              = 'Asset list';
$_['entry_files']               = 'Files to purge';

// Button
$_['button_purge_all']          = 'Purge everything';
$_['button_purge_files']        = 'Purge these files';
$_['button_purge_assets']       = 'Purge asset list';
$_['button_test']               = 'Test credentials';

// Error
$_['error_permission']          = 'Warning: You do not have permission to modify the Sucuri Cache module!';
$_['error_credentials']         = 'Warning: the Sucuri API key and secret are not configured.';
$_['error_files']               = 'Warning: no files given.';
$_['error_rate_limit']          = 'Rate limit reached, retry in %d seconds.';
$_['error_unknown']             = 'Sucuri returned an unknown error.';
