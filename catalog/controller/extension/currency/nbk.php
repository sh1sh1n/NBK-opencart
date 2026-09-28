<?php
class ControllerExtensionCurrencyNbk extends Controller {
	// Cron entry point: index.php?route=extension/currency/nbk/refresh&key=<currency_nbk_key>
	// Pulls fresh NBK rates into the currency table. Safe to hit repeatedly: without
	// the right key the request gets a 403 before the feed or the DB is touched.
	public function refresh() {
		// Disabled in settings -> do nothing (mirrors the model's own guard).
		if (!$this->config->get('currency_nbk_status')) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->setOutput('NBK: disabled');
			return;
		}

		// Optional single-IP lock for the cron URL. Empty setting -> any source allowed.
		$allowed = trim((string)$this->config->get('currency_nbk_ip'));

		if ($allowed !== '' && (empty($this->request->server['REMOTE_ADDR']) || $this->request->server['REMOTE_ADDR'] !== $allowed)) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->setOutput('NBK: forbidden');
			return;
		}

		// Mandatory shared secret, so a known URL alone cannot trigger heavy refreshes.
		// hash_equals() is constant-time, so the key cannot be guessed from timings.
		// An empty stored key means the settings were not re-saved after an update:
		// stay closed rather than open (hash_equals('', '') is true, hence the check).
		// A forged key[]=x is an array, and hash_equals() throws TypeError on it in 8.x.
		$key   = $this->config->get('currency_nbk_key');
		$given = isset($this->request->get['key']) ? $this->request->get['key'] : '';

		if (!is_string($key) || $key === '' || !is_string($given) || !hash_equals($key, $given)) {
			$this->response->addHeader('HTTP/1.1 403 Forbidden');
			$this->response->setOutput('NBK: forbidden');
			return;
		}

		$this->load->model('extension/currency/nbk');

		if ($this->model_extension_currency_nbk->refresh()) {
			$this->response->setOutput('NBK: ok');
		} else {
			$this->response->addHeader('HTTP/1.1 502 Bad Gateway');
			$this->response->setOutput('NBK: failed');
		}
	}
}
