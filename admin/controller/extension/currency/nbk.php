<?php
class ControllerExtensionCurrencyNbk extends Controller {

	private $error = array();

	public function index() {
		$this->load->language('extension/currency/nbk');

		$this->document->setTitle($this->language->get('heading_title'));

		$this->load->model('setting/setting');

		if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
			$this->model_setting_setting->editSetting('currency_nbk', $this->request->post);
			$this->session->data['success'] = $this->language->get('text_success');
			$this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=currency', true));
		}

		$data['error_warning'] = isset($this->error['warning']) ? $this->error['warning'] : '';
		$data['error_ip']      = isset($this->error['ip']) ? $this->error['ip'] : '';
		$data['error_margins'] = isset($this->error['margins']) ? $this->error['margins'] : '';
		$data['error_key']     = isset($this->error['key']) ? $this->error['key'] : '';

		$data['breadcrumbs'] = array();

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=currency', true)
		);

		$data['breadcrumbs'][] = array(
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/currency/nbk', 'user_token=' . $this->session->data['user_token'], true)
		);

		$data['action'] = $this->url->link('extension/currency/nbk', 'user_token=' . $this->session->data['user_token'], true);
		$data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=currency', true);

		$data['text_edit'] = $this->language->get('text_edit');
		$data['text_edit'] = str_replace('%1', $this->url->link('localisation/currency', 'user_token=' . $this->session->data['user_token'], true), $data['text_edit']);
		$data['text_edit'] = str_replace('%2', $this->url->link('setting/store', 'user_token=' . $this->session->data['user_token'], true), $data['text_edit']);

		// Built from the stored key, not from POST: cron checks what is saved, so a
		// key typed in but not yet saved would give a command that gets a 403.
		$key = $this->config->get('currency_nbk_key');
		$data['currency_nbk_cron'] = (is_string($key) && $key !== '') ? $this->escapeAttr('curl -s "' . HTTPS_CATALOG . 'index.php?route=extension/currency/nbk/refresh&key=' . rawurlencode($key) . '"') : '';

		if (isset($this->request->post['currency_nbk_key'])) {
			$data['currency_nbk_key'] = $this->escapeAttr($this->request->post['currency_nbk_key']);
		} else {
			$data['currency_nbk_key'] = $this->escapeAttr($this->config->get('currency_nbk_key'));
		}

		if (isset($this->request->post['currency_nbk_ip'])) {
			$data['currency_nbk_ip'] = $this->escapeAttr($this->request->post['currency_nbk_ip']);
		} else {
			$data['currency_nbk_ip'] = $this->escapeAttr($this->config->get('currency_nbk_ip'));
		}

		// Same rule as the !config->get('currency_nbk_status') check in the model and
		// cron, so the select shows what the module really does and Twig never gets an array.
		$status = isset($this->request->post['currency_nbk_status']) ? $this->request->post['currency_nbk_status'] : $this->config->get('currency_nbk_status');
		$data['currency_nbk_status'] = empty($status) ? '0' : '1';

		if (isset($this->request->post['currency_nbk_margins'])) {
			$data['currency_nbk_margins'] = $this->escapeAttr($this->request->post['currency_nbk_margins']);
		} else {
			$data['currency_nbk_margins'] = $this->escapeAttr($this->config->get('currency_nbk_margins'));
		}

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/currency/nbk', $data));
	}

	// A forged name[]=x turns a field into an array; editSetting() would store it
	// as JSON, and the model and cron would later read an array where they expect
	// a string. So non-scalars are rejected before any other check sees them.
	// Status only ever comes from a 0/1 select, so anything else is a forged request
	// and is rejected rather than coerced: stored junk would later read as "enabled".
	// The ip check uses !== '' instead of empty(): empty('0') is true, so '0' used to
	// be saved and cron then treated it as a lock that no address can ever match.
	// The key is required: without it cron stays closed. Letters and digits only,
	// so it goes into the URL, the HTML attribute and the shell command unencoded;
	// \z rather than $, since $ also accepts a trailing newline. No trim: the key is
	// saved exactly as typed, so the cron command matches it byte for byte.
	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/currency/nbk')) {
			$this->error['warning'] = $this->language->get('error_permission');
		} elseif (isset($this->request->post['currency_nbk_status']) && !in_array($this->request->post['currency_nbk_status'], array('0', '1'), true)) {
			$this->error['warning'] = $this->language->get('error_status');
		}

		$ip = isset($this->request->post['currency_nbk_ip']) ? $this->request->post['currency_nbk_ip'] : '';
		if (!is_scalar($ip)) {
			$this->error['ip'] = $this->language->get('error_ip');
		} elseif ((string)$ip !== '' && !filter_var((string)$ip, FILTER_VALIDATE_IP)) {
			$this->error['ip'] = $this->language->get('error_ip');
		}

		$key = isset($this->request->post['currency_nbk_key']) ? $this->request->post['currency_nbk_key'] : '';
		if (!is_scalar($key) || !preg_match('/^[A-Za-z0-9]{32,64}\z/', (string)$key)) {
			$this->error['key'] = $this->language->get('error_key');
		}

		$margins = isset($this->request->post['currency_nbk_margins']) ? $this->request->post['currency_nbk_margins'] : '';
		if (!is_scalar($margins)) {
			$this->error['margins'] = $this->language->get('error_margins');
		} else {
			$margins = (string)$margins;
			$this->load->model('extension/currency/nbk');
			if (!$this->model_extension_currency_nbk->validateMargins($margins)) {
				$this->error['margins'] = $this->language->get('error_margins');
			} elseif (!$this->model_extension_currency_nbk->validateMarginRange($margins)) {
				$this->error['margins'] = $this->language->get('error_margins_range');
			}
		}

		return !$this->error;
	}

	// Twig in OC 3 runs with autoescape off, so attribute values are escaped here.
	// OC Request already applies htmlspecialchars(ENT_COMPAT) to POST and editSetting()
	// stores that form, so decode first: this keeps the function idempotent.
	// Flags are explicit because the defaults differ between 7.4 and 8.1+.
	// Non-scalars become '' since htmlspecialchars(array) throws TypeError on 8.x.
	private function escapeAttr($value) {
		if (!is_scalar($value)) {
			return '';
		}

		return htmlspecialchars(html_entity_decode((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	public function install() {
		// Grant the installing user's group access/modify rights so the settings
		// page is reachable right after a plain ocmod install, with no manual
		// "Users -> User Groups" step.
		$this->load->model('user/user_group');
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'access', 'extension/currency/nbk');
		$this->model_user_user_group->addPermission($this->user->getGroupId(), 'modify', 'extension/currency/nbk');

		// Stock OpenCart's "Refresh" button (admin/model/localisation/currency::refresh)
		// is hard-wired to the long-dead Yahoo Finance API and never calls currency
		// extensions. Hook our refresh onto its "after" event so the button and the
		// auto-update both end up writing NBK rates. Pure event registration -> no core
		// file edits, no ocmod conflicts.
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('currency_nbk');
		$this->model_setting_event->addEvent('currency_nbk', 'admin/model/localisation/currency/refresh/after', 'extension/currency/nbk/refreshEvent');
	}

	public function uninstall() {
		$this->load->model('setting/event');
		$this->model_setting_event->deleteEventByCode('currency_nbk');
	}

	// Event handler fired AFTER core currency refresh: ($route, $args, $output).
	// Runs last, so our rates overwrite whatever (nothing) Yahoo produced.
	// The model self-guards on currency_nbk_status, so no extra check needed here.
	public function refreshEvent($route, $args, $output) {
		$this->load->model('extension/currency/nbk');
		$this->model_extension_currency_nbk->refresh();
	}

	// Kept for OpenCart forks whose refresh loop calls extension/currency/<code>/currency.
	// Stock 3.0.3.x does NOT call this; the refreshEvent() hook is what drives the button.
	public function currency() {
		$this->load->model('extension/currency/nbk');
		$this->model_extension_currency_nbk->refresh();
		return null;
	}
}
