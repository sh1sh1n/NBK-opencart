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

		$data['currency_nbk_cron'] = 'curl -s &quot;' . HTTPS_CATALOG . 'index.php?route=extension/currency/nbk/refresh&quot;';

		if (isset($this->request->post['currency_nbk_ip'])) {
			$data['currency_nbk_ip'] = $this->request->post['currency_nbk_ip'];
		} else {
			$data['currency_nbk_ip'] = (string)$this->config->get('currency_nbk_ip');
		}

		if (isset($this->request->post['currency_nbk_status'])) {
			$data['currency_nbk_status'] = $this->request->post['currency_nbk_status'];
		} else {
			$data['currency_nbk_status'] = $this->config->get('currency_nbk_status');
		}

		if (isset($this->request->post['currency_nbk_margins'])) {
			$data['currency_nbk_margins'] = $this->request->post['currency_nbk_margins'];
		} else {
			$data['currency_nbk_margins'] = (string)$this->config->get('currency_nbk_margins');
		}

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/currency/nbk', $data));
	}

	protected function validate() {
		if (!$this->user->hasPermission('modify', 'extension/currency/nbk')) {
			$this->error['warning'] = $this->language->get('error_permission');
		}

		if (!empty($this->request->post['currency_nbk_ip'])) {
			if (!filter_var($this->request->post['currency_nbk_ip'], FILTER_VALIDATE_IP)) {
				$this->error['ip'] = $this->language->get('error_ip');
			}
		}

		return !$this->error;
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
