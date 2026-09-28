<?php
class ControllerExtensionCurrencyNbk extends Controller {
	// Cron entry point: index.php?route=extension/currency/nbk/refresh
	// Pulls fresh NBK rates into the currency table. Safe to hit repeatedly.
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

		$this->load->model('extension/currency/nbk');

		if ($this->model_extension_currency_nbk->refresh()) {
			$this->response->setOutput('NBK: ok');
		} else {
			$this->response->addHeader('HTTP/1.1 502 Bad Gateway');
			$this->response->setOutput('NBK: failed');
		}
	}
}
