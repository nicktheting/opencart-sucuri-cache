<?php
/**
 * Sucuri edge-cache purge - CLI entry point (cron / deploy hook).
 *
 * Lives under system/ so it is unreachable over HTTP (system/.htaccess denies
 * everything), and refuses to run outside the CLI anyway.
 *
 * Usage
 * -----
 *   php system/library/sucuri/purge.php                 full purge (2-minute local throttle)
 *   php system/library/sucuri/purge.php --force         full purge, ignore the throttle
 *   php system/library/sucuri/purge.php --assets        purge the asset list saved in the admin module
 *   php system/library/sucuri/purge.php product_feed.xml catalog/view/.../custom.css
 *
 * Credentials, in order of precedence:
 *   1. SUCURI_WAF_KEY / SUCURI_WAF_SECRET constants in the store's config.php
 *   2. SUCURI_WAF_KEY / SUCURI_WAF_SECRET environment variables
 *   3. the module_sucuri_cache_key / _secret settings saved in the admin module
 *
 * Exit codes: 0 = purged, 1 = error, 2 = skipped by the throttle.
 *
 * Example cron - purge a generated product feed 5 minutes past every second hour,
 * i.e. just after the feed rebuild (minute 5, hour step 2, every day):
 *   /usr/local/bin/php /path/to/opencart/system/library/sucuri/purge.php product_feed.xml
 */

if (PHP_SAPI !== 'cli') {
	header('HTTP/1.1 403 Forbidden');
	exit('CLI only.');
}

$docroot = dirname(__DIR__, 3) . '/';

if (!is_file($docroot . 'config.php')) {
	fwrite(STDERR, "Cannot locate OpenCart config.php (looked in {$docroot}).\n");
	exit(1);
}

require_once($docroot . 'config.php');
require_once(__DIR__ . '/waf.php');

/**
 * Read module_sucuri_cache_* settings (and the error log filename) straight
 * from oc_setting.
 */
function sucuri_settings() {
	$settings = array();

	if (!defined('DB_HOSTNAME') || !function_exists('mysqli_connect')) {
		return $settings;
	}

	$link = @mysqli_connect(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, defined('DB_PORT') ? (int)DB_PORT : 3306);

	if (!$link) {
		fwrite(STDERR, 'Warning: cannot read settings from the database: ' . mysqli_connect_error() . "\n");

		return $settings;
	}

	mysqli_set_charset($link, 'utf8');

	$result = mysqli_query($link, "SELECT `key`, `value` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = 0 AND (`key` LIKE 'module\\_sucuri\\_cache\\_%' OR `key` = 'config_error_filename')");

	if ($result) {
		while ($row = mysqli_fetch_assoc($result)) {
			$settings[$row['key']] = $row['value'];
		}

		mysqli_free_result($result);
	}

	mysqli_close($link);

	return $settings;
}

/**
 * Write the result to the store's error log in the same format as the admin
 * module, so a purge from cron or a deploy hook shows up next to the ones
 * made from the admin. The line format matches OpenCart's Log::write().
 */
function sucuri_log($action, array $result, array $settings) {
	if (!defined('DIR_LOGS')) {
		return;
	}

	$file = !empty($settings['config_error_filename']) ? basename($settings['config_error_filename']) : 'error.log';

	$message = sprintf(
		'Sucuri WAF: %s (cli) -> %s (HTTP %d) %s',
		$action,
		$result['ok'] ? 'OK' : 'FAILED',
		$result['http'],
		implode(' | ', $result['messages'])
	);

	@file_put_contents(DIR_LOGS . $file, date('Y-m-d G:i:s') . ' - ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

$settings = sucuri_settings();

if (defined('SUCURI_WAF_KEY') && SUCURI_WAF_KEY !== '') {
	$key    = SUCURI_WAF_KEY;
	$secret = defined('SUCURI_WAF_SECRET') ? SUCURI_WAF_SECRET : '';
} elseif (getenv('SUCURI_WAF_KEY')) {
	$key    = getenv('SUCURI_WAF_KEY');
	$secret = (string)getenv('SUCURI_WAF_SECRET');
} else {
	$key    = isset($settings['module_sucuri_cache_key']) ? $settings['module_sucuri_cache_key'] : '';
	$secret = isset($settings['module_sucuri_cache_secret']) ? $settings['module_sucuri_cache_secret'] : '';
}

$client = new SucuriWafClient($key, $secret, DIR_STORAGE . 'sucuri/last_purge.json');

if (!$client->isConfigured()) {
	fwrite(STDERR, "Sucuri API key/secret are not configured.\n");
	exit(1);
}

$args   = array_slice($argv, 1);
$force  = in_array('--force', $args, true);
$assets = in_array('--assets', $args, true);
$files  = array_values(array_filter($args, function ($a) {
	return strpos($a, '--') !== 0;
}));

if ($assets) {
	$raw   = isset($settings['module_sucuri_cache_assets']) ? $settings['module_sucuri_cache_assets'] : '';
	$files = array_merge($files, array_filter(array_map('trim', preg_split('/\R/', $raw))));
}

$stamp = '[' . date('Y-m-d H:i:s') . '] ';

if ($files) {
	$batch    = $client->clearFiles($files);
	$failures = 0;

	foreach ($batch['results'] as $path => $result) {
		sucuri_log('clear_cache ' . $path, $result, $settings);

		echo $stamp . ($result['ok'] ? 'OK  ' : 'ERR ') . $path . ' ' . implode(' | ', $result['messages']) . "\n";

		if (!$result['ok']) {
			$failures++;
		}
	}

	foreach ($batch['skipped'] as $path) {
		echo $stamp . 'SKIP ' . $path . " (over the 10 files per run limit)\n";
		$failures++;
	}

	exit($failures ? 1 : 0);
}

$result = $client->clearCache($force);

sucuri_log('clear_cache (all)', $result, $settings);

echo $stamp . ($result['ok'] ? 'OK  ' : 'ERR ') . 'clear_cache (all) ' . implode(' | ', $result['messages']) . "\n";

if ($result['ok']) {
	exit(0);
}

exit($result['throttled'] ? 2 : 1);
