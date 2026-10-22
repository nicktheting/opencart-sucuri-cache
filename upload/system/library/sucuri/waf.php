<?php
/**
 * Sucuri Website Firewall (CloudProxy) API client - cache purging.
 *
 * Why this exists
 * ---------------
 * Sucuri caches every asset with a "static" extension (js, css, png, jpg, jpeg,
 * svg, gif, ico, txt, mp4, mp3, pdf, woff, ttf, swf, thumb - and, in practice,
 * xml too) for 24h REGARDLESS of the Caching Level setting, and
 * rewrites the origin's Cache-Control to max-age=86400. A CSS/JS deploy on a URL
 * without a cache-buster is therefore invisible to visitors for up to 24 hours
 * unless the edge cache is purged. For those files the only lever Sucuri offers
 * is a manual purge - or this API.
 *
 * API
 * ---
 *   GET https://waf.sucuri.net/api?v2&k=<key>&s=<secret>&a=clear_cache[&file=<path>]
 *   Response: JSON {status, messages[], action, request_time, verbose, output}
 *   status is 1 on success, 0 on failure (reason in messages[]).
 *
 * Limits
 * ------
 *   - 12 requests/minute and 60 requests/hour per API key (HTTP 429 + Retry-After).
 *   - Sucuri ignores a second full purge issued within ~2 minutes of the previous
 *     one, so we throttle full purges locally instead of burning a request.
 *
 * This class deliberately has no OpenCart dependencies, so the admin module, a
 * catalog controller and the CLI cron script can all share it.
 */
class SucuriWafClient {
	const ENDPOINT     = 'https://waf.sucuri.net/api';
	const MIN_INTERVAL = 120; // seconds enforced locally between two full purges
	const TIMEOUT      = 20;
	const MAX_BATCH    = 10;      // files per run, keeps us under the 12 requests/minute limit
	const BATCH_PAUSE  = 250000;  // microseconds between per-file purges

	private $key;
	private $secret;
	private $state_file;

	/**
	 * @param string $key        API_KEY, or the combined "KEY/SECRET" string the panel shows as "API Key (for plugin)".
	 * @param string $secret     API_SECRET (omit when $key holds the combined form).
	 * @param string $state_file Absolute path used to remember the last full purge. Optional.
	 */
	public function __construct($key, $secret = '', $state_file = '') {
		$key = trim((string)$key);

		// The panel offers the credentials both split and combined as "KEY/SECRET".
		if ($secret === '' && strpos($key, '/') !== false) {
			list($key, $secret) = explode('/', $key, 2);
		}

		$this->key        = trim($key);
		$this->secret     = trim((string)$secret);
		$this->state_file = $state_file;
	}

	public function isConfigured() {
		return $this->key !== '' && $this->secret !== '';
	}

	/**
	 * Purge the whole edge cache.
	 *
	 * @param bool $force Skip the local 2-minute throttle.
	 * @return array Result array, see result().
	 */
	public function clearCache($force = false) {
		if (!$force) {
			$wait = $this->throttleRemaining();

			if ($wait > 0) {
				return $this->result(false, 0, array(sprintf('Skipped: the last full purge was less than %d seconds ago, so Sucuri would ignore this one. Retry in %d seconds.', self::MIN_INTERVAL, $wait)), 0, array('throttled' => true, 'retry_after' => $wait));
			}
		}

		$result = $this->request(array('a' => 'clear_cache'));

		if ($result['ok']) {
			$this->rememberPurge();
		}

		return $result;
	}

	/**
	 * Purge a single file. Accepts a full URL or a site-relative path.
	 */
	public function clearFile($path) {
		$path = $this->normalisePath($path);

		if ($path === '') {
			return $this->result(false, 0, array('Empty file path.'));
		}

		return $this->request(array('a' => 'clear_cache', 'file' => $path));
	}

	/**
	 * Purge a list of files. Capped at MAX_BATCH per run so a single run always
	 * stays inside the 12 requests/minute limit; the overflow is reported back
	 * so the caller can purge it on the next run.
	 *
	 * @return array ['results' => array<path, result>, 'skipped' => string[]]
	 */
	public function clearFiles(array $paths) {
		$paths   = array_values(array_unique(array_filter(array_map(array($this, 'normalisePath'), $paths))));
		$batch   = array_slice($paths, 0, self::MAX_BATCH);
		$skipped = array_slice($paths, self::MAX_BATCH);
		$results = array();

		foreach ($batch as $i => $path) {
			if ($i > 0) {
				usleep(self::BATCH_PAUSE);
			}

			$results[$path] = $this->request(array('a' => 'clear_cache', 'file' => $path));
		}

		return array('results' => $results, 'skipped' => $skipped);
	}

	/**
	 * Read the firewall settings back. Used as a credentials test - it is a
	 * read-only call, so it never disturbs the cache and costs one request.
	 */
	public function showSettings() {
		return $this->request(array('a' => 'show_settings'));
	}

	/** Unix timestamp of the last successful full purge, or 0. */
	public function lastPurge() {
		if (!$this->state_file || !is_file($this->state_file)) {
			return 0;
		}

		$state = json_decode((string)file_get_contents($this->state_file), true);

		return isset($state['last_purge']) ? (int)$state['last_purge'] : 0;
	}

	/** Seconds still to wait before a full purge is worth sending. */
	public function throttleRemaining() {
		$last = $this->lastPurge();

		if (!$last) {
			return 0;
		}

		$elapsed = time() - $last;

		return $elapsed >= self::MIN_INTERVAL ? 0 : (self::MIN_INTERVAL - $elapsed);
	}

	/**
	 * Turn "https://www.example.gr/catalog/view/x.css?v=1" into "catalog/view/x.css?v=1".
	 * Sucuri keys its cache on the URL path; the leading slash must go.
	 */
	public function normalisePath($path) {
		$path = trim((string)$path);

		if ($path === '') {
			return '';
		}

		if (preg_match('~^https?://~i', $path)) {
			$parsed = parse_url($path);
			$path   = isset($parsed['path']) ? $parsed['path'] : '';

			if (!empty($parsed['query'])) {
				$path .= '?' . $parsed['query'];
			}
		}

		return ltrim($path, '/');
	}

	private function rememberPurge() {
		if (!$this->state_file) {
			return;
		}

		$dir = dirname($this->state_file);

		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		@file_put_contents($this->state_file, json_encode(array('last_purge' => time())), LOCK_EX);
	}

	/**
	 * @return array{ok:bool,status:int,messages:string[],http:int,retry_after:?int,throttled:bool,raw:string,action:string}
	 */
	private function request(array $params) {
		if (!$this->isConfigured()) {
			return $this->result(false, 0, array('Sucuri API key/secret are not configured.'));
		}

		if (!function_exists('curl_init')) {
			return $this->result(false, 0, array('cURL is not available on this server.'));
		}

		$params = array_merge(array('k' => $this->key, 's' => $this->secret), $params);
		$url    = self::ENDPOINT . '?v2&' . http_build_query($params);

		$retry_after = null;

		$curl = curl_init();
		curl_setopt_array($curl, array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_USERAGENT      => 'OpenCart-SucuriCache/1.0',
			CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$retry_after) {
				if (stripos($header, 'Retry-After:') === 0) {
					$retry_after = (int)trim(substr($header, 12));
				}

				return strlen($header);
			},
		));

		$body     = curl_exec($curl);
		$http     = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$curl_err = curl_error($curl);

		// curl_close() has been a no-op since PHP 8.0 and is deprecated in 8.5.
		if (PHP_VERSION_ID < 80000) {
			curl_close($curl);
		}

		if ($body === false) {
			return $this->result(false, 0, array('Connection to waf.sucuri.net failed: ' . $curl_err), $http);
		}

		$json = json_decode($body, true);

		if (!is_array($json)) {
			return $this->result(false, 0, array('Unexpected (non-JSON) response from Sucuri.'), $http, array('raw' => (string)$body));
		}

		$messages = array();

		if (isset($json['messages'])) {
			$messages = is_array($json['messages']) ? $json['messages'] : array((string)$json['messages']);
		}

		$ok = !empty($json['status']) && $http < 400;

		if ($http === 429 && $retry_after === null && isset($json['rate_limit']['reset_time'])) {
			$retry_after = max(0, (int)$json['rate_limit']['reset_time'] - time());
		}

		return $this->result($ok, (int)(isset($json['status']) ? $json['status'] : 0), $messages, $http, array(
			'retry_after' => $retry_after,
			'raw'         => (string)$body,
			'action'      => isset($json['action']) ? (string)$json['action'] : '',
		));
	}

	private function result($ok, $status, array $messages, $http = 0, array $extra = array()) {
		return array_merge(array(
			'ok'          => (bool)$ok,
			'status'      => (int)$status,
			'messages'    => $messages,
			'http'        => (int)$http,
			'retry_after' => null,
			'throttled'   => false,
			'raw'         => '',
			'action'      => '',
		), $extra);
	}
}
