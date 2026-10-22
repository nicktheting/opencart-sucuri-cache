<?php
/**
 * Sucuri Cache - admin module.
 *
 * Purges the Sucuri Website Firewall (CloudProxy) edge cache from inside
 * OpenCart, so a CSS/JS/image deploy actually reaches visitors instead of
 * sitting behind the 24h static-asset cache. See system/library/sucuri/waf.php
 * for the API details and the rate limits.
 *
 * Credentials: read from SUCURI_WAF_KEY / SUCURI_WAF_SECRET if those constants
 * are defined in admin/config.php (keeps them out of the database), otherwise
 * from the module settings.
 */
require_once(DIR_SYSTEM . 'library/sucuri/waf.php');

class ControllerExtensionModuleSucuriCache extends Controller {
	private $error = array();

	public function install() {
		$this->load->model('setting/setting');

		$this->model_setting_setting->editSetting('module_sucuri_cache', array(
			'module_sucuri_cache_status' => 0
		));
	}

	public function uninstall() {
		$this->load->model('setting/setting');

		$this->model_setting_setting->deleteSetting('module_sucuri_cache');
	}

	public function index() {
		$this->load->language('extension/module/sucuri_cache');
		$this->load->model('setting/setting');

		$this->document->setTitle($this->language->get('heading_title'));

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('module_sucuri_cache', $this->request->post);

			$this->session->data['success'] = $this->language->get('text_success');

			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true));
		}

		$data = array();

		foreach (array('heading_title', 'text_edit', 'text_enabled', 'text_disabled', 'text_purge_all', 'text_purge_all_help', 'text_purge_files', 'text_purge_files_help', 'text_assets_help', 'text_credentials', 'text_credentials_help', 'text_from_config', 'text_never', 'text_last_purge', 'text_static_note', 'text_working', 'entry_status', 'entry_key', 'entry_secret', 'entry_assets', 'entry_files', 'button_purge_all', 'button_purge_files', 'button_purge_assets', 'button_test', 'button_save', 'button_cancel') as $key) {
			$data[$key] = $this->language->get($key);
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/module/sucuri_cache', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action'] = $this->url->link('extension/module/sucuri_cache', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);

		// NOTE: do not use $this->url->link() for the AJAX endpoints. Url::link()
		// HTML-encodes the ampersands ("&amp;"), which is correct inside an href
		// but turns user_token into "amp;user_token" when the string is used in
		// JavaScript - the request then arrives unauthenticated and OpenCart
		// answers with the login page instead of JSON. The template builds these
		// URLs by hand from user_token, the same way OpenCart core does.
		$data['user_token'] = $this->session->data['user_token'];

		foreach (array('status', 'key', 'secret', 'assets') as $field) {
			$name = 'module_sucuri_cache_' . $field;

			$data[$name] = isset($this->request->post[$name]) ? $this->request->post[$name] : $this->config->get($name);
		}

		// Credentials pinned in admin/config.php win over the stored settings.
		$data['credentials_from_config'] = defined('SUCURI_WAF_KEY') && SUCURI_WAF_KEY !== '';

		$client = $this->getClient();

		$data['configured'] = $client->isConfigured();

		$last = $client->lastPurge();

		$data['last_purge'] = $last ? date($this->language->get('datetime_format'), $last) : $data['text_never'];

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/sucuri_cache', $data));
	}

	/**
	 * AJAX: purge the whole edge cache.
	 */
	public function purge() {
		$this->load->language('extension/module/sucuri_cache');

		$json = array();

		try {
			if (!$this->user->hasPermission('modify', 'extension/module/sucuri_cache')) {
				$json['error'] = $this->language->get('error_permission');
			} else {
				$client = $this->getClient();

				if (!$client->isConfigured()) {
					$json['error'] = $this->language->get('error_credentials');
				} else {
					$force  = !empty($this->request->post['force']);
					$result = $client->clearCache($force);

					$this->logResult('clear_cache (all)', $result);

					if ($result['ok']) {
						$json['success'] = $this->language->get('text_purged_all');
					} elseif ($result['throttled']) {
						$json['warning'] = implode(' ', $result['messages']);
					} else {
						$json['error'] = $this->formatError($result);
					}

					$json['last_purge'] = $client->lastPurge() ? date($this->language->get('datetime_format'), $client->lastPurge()) : '';
				}
			}
		} catch (\Throwable $e) {
			$json = array('error' => $this->exception($e));
		}

		$this->respond($json);
	}

	/**
	 * AJAX: purge a list of files. Posts either 'files' (textarea contents) or
	 * source=assets to use the saved asset list.
	 */
	public function purgeFiles() {
		$this->load->language('extension/module/sucuri_cache');

		$json = array();

		try {
			if (!$this->user->hasPermission('modify', 'extension/module/sucuri_cache')) {
				$json['error'] = $this->language->get('error_permission');
			} else {
				$client = $this->getClient();

				if (!$client->isConfigured()) {
					$json['error'] = $this->language->get('error_credentials');
				} elseif (!$paths = $this->requestedPaths()) {
					$json['error'] = $this->language->get('error_files');
				} else {
					$batch  = $client->clearFiles($paths);
					$lines  = array();
					$failed = 0;

					foreach ($batch['results'] as $path => $result) {
						$this->logResult('clear_cache ' . $path, $result);

						if ($result['ok']) {
							$lines[] = 'OK  ' . $path;
						} else {
							$failed++;
							$lines[] = 'ERR ' . $path . ' - ' . $this->formatError($result);
						}
					}

					foreach ($batch['skipped'] as $path) {
						$lines[] = sprintf($this->language->get('text_skipped'), $path);
					}

					$json['report'] = implode("\n", $lines);

					if ($failed) {
						$json['warning'] = sprintf($this->language->get('text_purged_files_partial'), count($batch['results']) - $failed, count($batch['results']));
					} else {
						$json['success'] = sprintf($this->language->get('text_purged_files'), count($batch['results']));
					}
				}
			}
		} catch (\Throwable $e) {
			$json = array('error' => $this->exception($e));
		}

		$this->respond($json);
	}

	/**
	 * AJAX: verify the credentials with a read-only call.
	 */
	public function test() {
		$this->load->language('extension/module/sucuri_cache');

		$json = array();

		try {
			if (!$this->user->hasPermission('modify', 'extension/module/sucuri_cache')) {
				$json['error'] = $this->language->get('error_permission');
			} else {
				// Test what is typed in the form, falling back to what is stored.
				$key    = isset($this->request->post['key']) ? $this->request->post['key'] : '';
				$secret = isset($this->request->post['secret']) ? $this->request->post['secret'] : '';

				$client = ($key !== '') ? new SucuriWafClient($key, $secret, $this->stateFile()) : $this->getClient();

				if (!$client->isConfigured()) {
					$json['error'] = $this->language->get('error_credentials');
				} else {
					$result = $client->showSettings();

					$this->logResult('show_settings (test)', $result);

					if ($result['ok']) {
						$json['success'] = $this->language->get('text_test_ok');
					} else {
						$json['error'] = $this->formatError($result);
					}
				}
			}
		} catch (\Throwable $e) {
			$json = array('error' => $this->exception($e));
		}

		$this->respond($json);
	}

	/**
	 * The paths a purgeFiles() call should act on: either the posted textarea
	 * or, when source=assets, the list saved in the module settings.
	 */
	private function requestedPaths() {
		if (isset($this->request->post['source']) && $this->request->post['source'] == 'assets') {
			$raw = (string)$this->config->get('module_sucuri_cache_assets');
		} else {
			$raw = isset($this->request->post['files']) ? (string)$this->request->post['files'] : '';
		}

		return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw))));
	}

	/**
	 * Always answer AJAX with JSON - never let a failure fall through to an
	 * HTML error page, which the browser reports as "Unexpected token '<'".
	 */
	private function respond(array $json) {
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	private function exception(\Throwable $e) {
		$message = get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();

		$this->log->write('Sucuri WAF: ' . $message);

		return $message;
	}

	private function getClient() {
		if (defined('SUCURI_WAF_KEY') && SUCURI_WAF_KEY !== '') {
			$key    = SUCURI_WAF_KEY;
			$secret = defined('SUCURI_WAF_SECRET') ? SUCURI_WAF_SECRET : '';
		} else {
			$key    = (string)$this->config->get('module_sucuri_cache_key');
			$secret = (string)$this->config->get('module_sucuri_cache_secret');
		}

		return new SucuriWafClient($key, $secret, $this->stateFile());
	}

	private function stateFile() {
		return DIR_STORAGE . 'sucuri/last_purge.json';
	}

	private function formatError(array $result) {
		$message = $result['messages'] ? implode(' ', $result['messages']) : $this->language->get('error_unknown');

		if ($result['http'] === 429) {
			$message .= ' ' . sprintf($this->language->get('error_rate_limit'), (int)$result['retry_after']);
		}

		return $message;
	}

	private function logResult($action, array $result) {
		$this->log->write(sprintf(
			'Sucuri WAF: %s -> %s (HTTP %d) %s',
			$action,
			$result['ok'] ? 'OK' : 'FAILED',
			$result['http'],
			implode(' | ', $result['messages'])
		));
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/module/sucuri_cache')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		return !$this->error;
	}
}
