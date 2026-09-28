<?php
// Runtime smoke tests for the NBK module. No OpenCart install, DB or network.
//
// Each module file is loaded into its own namespace. Unqualified curl_exec()
// then resolves to the stub below and serves tests/fixtures/rates_all.xml,
// while curl_init/curl_setopt/curl_close stay real, so their deprecations on
// newer PHP still surface. Any notice, warning or deprecation fails the run:
// one codebase has to stay clean on every PHP from 7.4 to 8.5.
//
// Must itself run on PHP 7.4: no match, nullsafe, named args, str_contains.
// Usage: php -d error_reporting=-1 tests/smoke.php

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('DB_PREFIX', 'oc_');
define('HTTPS_CATALOG', 'https://shop.test/');

$root   = dirname(__DIR__);
$issues = array();
$failed = 0;
$passed = 0;

// Namespaced copies of module files live here, mirroring their repo paths,
// so diagnostics point at e.g. admin/model/extension/currency/nbk.php:49.
$staging = sys_get_temp_dir() . '/nbk-smoke-' . getmypid();

register_shutdown_function(function () use ($staging) {
	if (!is_dir($staging)) {
		return;
	}

	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

	foreach ($items as $item) {
		$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}

	rmdir($staging);
});

// Temp dirs may be reported through a symlink (/var -> /private/var on macOS),
// so strip by pattern rather than by the exact staging prefix.
set_error_handler(function ($no, $message, $file, $line) use (&$issues) {
	$where = preg_replace('#^.*/nbk-smoke-\d+/[^/]+/#', '', $file) . ':' . $line;
	$key   = '[' . $no . '] ' . $message . ' @ ' . $where;
	$issues[$key] = isset($issues[$key]) ? $issues[$key] + 1 : 1;
	return true;
});

// --- OpenCart framework stand-ins -------------------------------------------

class Registry {
	private $data = array();

	public function get($key) {
		return isset($this->data[$key]) ? $this->data[$key] : null;
	}

	public function set($key, $value) {
		$this->data[$key] = $value;
	}
}

// Same magic as OC 3 Model/Controller: undeclared properties live in the
// registry, so the module never trips PHP 8.2 dynamic-property deprecations.
abstract class NbkBase {
	protected $registry;

	public function __construct($registry) {
		$this->registry = $registry;
	}

	public function __get($key) {
		return $this->registry->get($key);
	}

	public function __set($key, $value) {
		$this->registry->set($key, $value);
	}
}

abstract class Model extends NbkBase {}
abstract class Controller extends NbkBase {}

class FakeConfig {
	private $data;

	public function __construct(array $data) {
		$this->data = $data;
	}

	public function get($key) {
		return isset($this->data[$key]) ? $this->data[$key] : null;
	}
}

class FakeDb {
	public $queries = array();
	private $codes;

	public function __construct(array $codes) {
		$this->codes = $codes;
	}

	public function query($sql) {
		$this->queries[] = $sql;

		$result = new stdClass();
		$result->rows = array();

		if (stripos(ltrim($sql), 'SELECT') === 0) {
			foreach ($this->codes as $code) {
				$result->rows[] = array('code' => $code);
			}
		}

		return $result;
	}

	public function escape($value) {
		return addslashes($value);
	}

	// code => written value; the last UPDATE for a code wins, like in MySQL.
	public function writes() {
		$writes = array();

		foreach ($this->queries as $sql) {
			if (preg_match("/SET `value` = '([^']*)'.*WHERE `code` = '([^']*)'/", $sql, $m)) {
				$writes[$m[2]] = $m[1];
			}
		}

		return $writes;
	}
}

class FakeCache {
	public $deleted = array();

	public function delete($key) {
		$this->deleted[] = $key;
	}
}

// Models are pre-seeded into the registry by each test; load->model() only
// records which routes the code under test asked for.
class FakeLoader {
	public $models = array();

	public function model($route) {
		$this->models[] = $route;
	}

	public function language($route) {}

	public function controller($route) {
		return '';
	}

	// Hands the template data back, so tests can inspect what the page shows.
	public function view($route, $data = array()) {
		return $data;
	}
}

class FakeRequest {
	public $server = array();
	public $post = array();
	public $get = array();
}

class FakeResponse {
	public $headers = array();
	public $output = null;
	public $redirect = null;

	public function addHeader($header) {
		$this->headers[] = $header;
	}

	// The real one exits; recording the URL is enough to tell "saved" apart.
	public function redirect($url) {
		$this->redirect = $url;
	}

	public function setOutput($output) {
		$this->output = $output;
	}
}

class FakeNbkModel {
	public $result = true;
	public $calls = 0;

	public function refresh() {
		$this->calls++;
		return $this->result;
	}
}

// Records every call as array(method, args...).
class FakeRecorder {
	public $calls = array();
	public $groupId = 7;
	public $permitted = true;

	public function __call($method, $args) {
		$this->calls[] = array_merge(array($method), $args);
		return null;
	}

	public function getGroupId() {
		return $this->groupId;
	}

	public function hasPermission($action, $route) {
		return $this->permitted;
	}
}

// Returns the key itself, so tests can assert which message was chosen.
class FakeLanguage {
	public function get($key) {
		return $key;
	}
}

class FakeUrl {
	public function link($route, $args = '', $secure = false) {
		return 'url:' . $route;
	}
}

class FakeSession {
	public $data = array('user_token' => 't');
}

class NbkFeed {
	public static $response = false;
	public static $calls = 0;
}

// --- Helpers ----------------------------------------------------------------

// The prelude is glued onto the "<?php" line, so line numbers stay intact.
function nbk_load($file, $namespace) {
	global $root, $staging;

	$source = preg_replace('/^<\?php/', '', file_get_contents($root . '/' . $file), 1);
	$target = $staging . '/' . str_replace('\\', '_', $namespace) . '/' . $file;

	if (!is_dir(dirname($target))) {
		mkdir(dirname($target), 0700, true);
	}

	file_put_contents($target, '<?php namespace ' . $namespace . '; use Model; use Controller;'
		. ' function curl_exec($handle) { \NbkFeed::$calls++; return \NbkFeed::$response; } '
		. $source);

	require $target;
}

function check($name, $ok, $detail = '') {
	global $failed, $passed;

	if ($ok) {
		$passed++;
		echo 'ok   ' . $name . "\n";
	} else {
		$failed++;
		echo 'FAIL ' . $name . ($detail !== '' ? ' -- ' . $detail : '') . "\n";
	}
}

function show($value) {
	return str_replace("\n", ' ', var_export($value, true));
}

function nbk_model($namespace, array $config, array $codes) {
	$registry = new Registry();
	$registry->set('config', new FakeConfig($config));
	$registry->set('db', new FakeDb($codes));
	$registry->set('cache', new FakeCache());

	$class = $namespace . '\\ModelExtensionCurrencyNbk';

	return array(new $class($registry), $registry);
}

function nbk_controller($class, array $services) {
	$registry = new Registry();
	$registry->set('load', new FakeLoader());
	$registry->set('request', new FakeRequest());
	$registry->set('response', new FakeResponse());

	foreach ($services as $key => $service) {
		$registry->set($key, $service);
	}

	return array(new $class($registry), $registry);
}

function nbk_parse_margins($model) {
	$method = new ReflectionMethod($model, 'parseMargins');

	// Needed before 8.1, deprecated in 8.5.
	if (PHP_VERSION_ID < 80100) {
		$method->setAccessible(true);
	}

	return $method->invoke($model);
}

// Runs the admin settings page with the real admin model behind it.
function nbk_admin_index($method, array $post, array $config) {
	list($model) = nbk_model('NbkTest\\AdminModel', array(), array());
	$settings = new FakeRecorder();

	list($controller, $registry) = nbk_controller('NbkTest\\AdminController\\ControllerExtensionCurrencyNbk', array(
		'config'                       => new FakeConfig($config),
		'user'                         => new FakeRecorder(),
		'document'                     => new FakeRecorder(),
		'model_setting_setting'        => $settings,
		'language'                     => new FakeLanguage(),
		'url'                          => new FakeUrl(),
		'session'                      => new FakeSession(),
		'model_extension_currency_nbk' => $model,
	));

	$registry->get('request')->server['REQUEST_METHOD'] = $method;
	$registry->get('request')->post = $post;

	$controller->index();

	return array($registry->get('response'), $settings);
}

$fixture = file_get_contents(__DIR__ . '/fixtures/rates_all.xml');
$codes   = array('KZT', 'USD', 'EUR', 'AMD', 'RUB', 'CNY', 'GBP', 'XZR', 'XND', 'XQZ');

echo 'PHP ' . PHP_VERSION . "\n";

// --- Models: admin and catalog copies must behave the same ------------------

$models = array(
	'admin'   => 'admin/model/extension/currency/nbk.php',
	'catalog' => 'catalog/model/extension/currency/nbk.php',
);

foreach ($models as $side => $file) {
	$ns = 'NbkTest\\' . ucfirst($side) . 'Model';
	nbk_load($file, $ns);

	// Store default KZT (the feed base), margin on USD only.
	NbkFeed::$response = $fixture;
	list($model, $registry) = nbk_model($ns, array('currency_nbk_status' => 1, 'config_currency' => 'KZT', 'currency_nbk_margins' => 'USD:2'), $codes);
	$result = $model->refresh();
	$writes = $registry->get('db')->writes();
	$expect = array('KZT' => '1.00000000', 'USD' => '0.00204000', 'EUR' => '0.00181818', 'AMD' => '0.80000000', 'RUB' => '0.18181818', 'CNY' => '0.01428571');
	ksort($writes);
	ksort($expect);
	check($side . ': default KZT refresh returns true', $result === true, show($result));
	check($side . ': default KZT rates, quant, decimal comma, USD margin', $writes === $expect, show($writes));
	check($side . ': currency cache is cleared', in_array('currency', $registry->get('cache')->deleted, true));

	// Store default USD: pivot through KZT, margins never touch the default.
	list($model, $registry) = nbk_model($ns, array('currency_nbk_status' => 1, 'config_currency' => 'USD', 'currency_nbk_margins' => 'USD:2,EUR:10,amd:-50'), $codes);
	$result = $model->refresh();
	$writes = $registry->get('db')->writes();
	$expect = array('KZT' => '500.00000000', 'USD' => '1.00000000', 'EUR' => '1.00000000', 'AMD' => '200.00000000', 'RUB' => '90.90909091', 'CNY' => '7.14285714');
	ksort($writes);
	ksort($expect);
	check($side . ': default USD refresh returns true', $result === true, show($result));
	check($side . ': default USD cross-rates, default stays 1, negative/lowercase margin', $writes === $expect, show($writes));

	// Default missing from the feed: abort without writing anything.
	list($model, $registry) = nbk_model($ns, array('currency_nbk_status' => 1, 'config_currency' => 'GBP'), $codes);
	$result = $model->refresh();
	check($side . ': default not in feed returns false', $result === false, show($result));
	check($side . ': default not in feed writes nothing', $registry->get('db')->writes() === array(), show($registry->get('db')->writes()));

	// Disabled: no fetch at all.
	$before = NbkFeed::$calls;
	list($model, $registry) = nbk_model($ns, array('currency_nbk_status' => 0, 'config_currency' => 'KZT'), $codes);
	$result = $model->refresh();
	check($side . ': disabled returns false without fetching', $result === false && NbkFeed::$calls === $before, show($result));
	check($side . ': disabled runs no queries', $registry->get('db')->queries === array());

	// Network failure and garbage payloads: abort without writing.
	foreach (array('network failure' => false, 'empty body' => '', 'non-XML body' => '<html>502 Bad Gateway') as $label => $response) {
		NbkFeed::$response = $response;
		list($model, $registry) = nbk_model($ns, array('currency_nbk_status' => 1, 'config_currency' => 'KZT'), $codes);
		$result = $model->refresh();
		check($side . ': ' . $label . ' returns false and writes nothing', $result === false && $registry->get('db')->writes() === array(), show($result));
	}

	NbkFeed::$response = $fixture;

	// Margin parsing.
	list($model) = nbk_model($ns, array('currency_nbk_margins' => ' usd:2, EUR:3.5 ,bad,:1,GBP:x'), array());
	$margins = nbk_parse_margins($model);
	check($side . ': parseMargins skips bad pairs, upper-cases codes', $margins === array('USD' => 2.0, 'EUR' => 3.5), show($margins));

	list($model) = nbk_model($ns, array(), array());
	$margins = nbk_parse_margins($model);
	check($side . ': parseMargins with no setting is empty', $margins === array(), show($margins));

	list($model) = nbk_model($ns, array('currency_nbk_margins' => 'EUR:3,5,USD:2'), array());
	$margins = nbk_parse_margins($model);
	check($side . ': parseMargins decimal comma', $margins === array('EUR' => 3.5, 'USD' => 2.0), show($margins));

	// Values saved before the fix must keep their meaning.
	list($model) = nbk_model($ns, array('currency_nbk_margins' => 'EUR:3,USD:2'), array());
	$margins = nbk_parse_margins($model);
	check($side . ': parseMargins stored format unchanged', $margins === array('EUR' => 3.0, 'USD' => 2.0), show($margins));

	list($model) = nbk_model($ns, array('currency_nbk_margins' => ' eur : 3,5 , usd:2.25, amd:-1,5, RUB:+4, CNY:.5, GBP:7% '), array());
	$margins = nbk_parse_margins($model);
	check($side . ': parseMargins edge cases', $margins === array('EUR' => 3.5, 'USD' => 2.25, 'AMD' => -1.5, 'RUB' => 4.0, 'CNY' => 0.5, 'GBP' => 7.0), show($margins));

	list($model) = nbk_model($ns, array('currency_nbk_margins' => 'EURO:3,XEUR:4,EUR:1'), array());
	$margins = nbk_parse_margins($model);
	check($side . ': parseMargins whole codes only', $margins === array('EUR' => 1.0), show($margins));

	// Default KZT: USD 1/500 * 1.025, EUR 1/550 * 1.035; the rest untouched.
	list($model, $registry) = nbk_model($ns, array('currency_nbk_status' => 1, 'config_currency' => 'KZT', 'currency_nbk_margins' => 'EUR:3,5,USD:2,5'), $codes);
	$result = $model->refresh();
	$writes = $registry->get('db')->writes();
	$expect = array('KZT' => '1.00000000', 'USD' => '0.00205000', 'EUR' => '0.00188182', 'AMD' => '0.80000000', 'RUB' => '0.18181818', 'CNY' => '0.01428571');
	ksort($writes);
	ksort($expect);
	check($side . ': refresh with decimal-comma margins', $result === true && $writes === $expect, show($writes));

	// Form validation is strict, unlike the runtime parser.
	foreach (array('', '   ', 'EUR:3,USD:2', 'EUR:3,5,USD:2', ' eur : 3,5 , usd:2.25 ', 'AMD:-1,5', 'RUB:+4', 'CNY:.5', 'GBP:7%', 'EUR:3,', 'EUR:3,5,') as $value) {
		check($side . ': validateMargins accepts ' . show($value), $model->validateMargins($value) === true);
	}

	foreach (array('EUR:3,bad', 'bad', ':1', 'GBP:x', 'EUR:3;USD:2', 'EUR 3', 'EURO:3', 'EUR:3, 5', 'EUR:3USD:2', 'EUR:', 'EUR:3:4', ',EUR:3', 'EUR:1e2', array('EUR:3')) as $value) {
		check($side . ': validateMargins rejects ' . show($value), $model->validateMargins($value) === false);
	}
}

// --- Catalog controller: the cron endpoint contract -------------------------

nbk_load('catalog/controller/extension/currency/nbk.php', 'NbkTest\\CatalogController');

$cron = array(
	// label => array(settings, REMOTE_ADDR or null, model result, expected headers, expected output, model called?)
	'disabled'               => array(array('currency_nbk_status' => 0), '10.0.0.1', true, array('HTTP/1.1 403 Forbidden'), 'NBK: disabled', false),
	'IP mismatch'            => array(array('currency_nbk_status' => 1, 'currency_nbk_ip' => '10.0.0.1'), '10.0.0.2', true, array('HTTP/1.1 403 Forbidden'), 'NBK: forbidden', false),
	'IP set, no REMOTE_ADDR' => array(array('currency_nbk_status' => 1, 'currency_nbk_ip' => '10.0.0.1'), null, true, array('HTTP/1.1 403 Forbidden'), 'NBK: forbidden', false),
	'IP match (trimmed)'     => array(array('currency_nbk_status' => 1, 'currency_nbk_ip' => ' 10.0.0.1 '), '10.0.0.1', true, array(), 'NBK: ok', true),
	'no IP lock, ok'         => array(array('currency_nbk_status' => 1), '192.0.2.5', true, array(), 'NBK: ok', true),
	'no IP lock, failed'     => array(array('currency_nbk_status' => 1), '192.0.2.5', false, array('HTTP/1.1 502 Bad Gateway'), 'NBK: failed', true),
);

foreach ($cron as $label => $case) {
	$fake = new FakeNbkModel();
	$fake->result = $case[2];

	list($controller, $registry) = nbk_controller('NbkTest\\CatalogController\\ControllerExtensionCurrencyNbk', array(
		'config'                      => new FakeConfig($case[0]),
		'model_extension_currency_nbk' => $fake,
	));

	if ($case[1] !== null) {
		$registry->get('request')->server['REMOTE_ADDR'] = $case[1];
	}

	$controller->refresh();

	$response = $registry->get('response');
	check('cron ' . $label . ': ' . $case[4], $response->output === $case[4] && $response->headers === $case[3], show(array($response->headers, $response->output)));
	check('cron ' . $label . ': model ' . ($case[5] ? 'called' : 'not called'), $fake->calls === ($case[5] ? 1 : 0), 'calls=' . $fake->calls);
}

// --- Admin controller: install/uninstall wiring and the refresh hook --------

nbk_load('admin/controller/extension/currency/nbk.php', 'NbkTest\\AdminController');

$events = new FakeRecorder();
$groups = new FakeRecorder();
$user   = new FakeRecorder();

list($controller) = nbk_controller('NbkTest\\AdminController\\ControllerExtensionCurrencyNbk', array(
	'config'                => new FakeConfig(array()),
	'user'                  => $user,
	'model_setting_event'   => $events,
	'model_user_user_group' => $groups,
));

$controller->install();

check('install: re-registers the refresh/after event', $events->calls === array(
	array('deleteEventByCode', 'currency_nbk'),
	array('addEvent', 'currency_nbk', 'admin/model/localisation/currency/refresh/after', 'extension/currency/nbk/refreshEvent'),
), show($events->calls));
check('install: grants access and modify to the installer group', $groups->calls === array(
	array('addPermission', 7, 'access', 'extension/currency/nbk'),
	array('addPermission', 7, 'modify', 'extension/currency/nbk'),
), show($groups->calls));

$events->calls = array();
$controller->uninstall();
check('uninstall: removes the event', $events->calls === array(array('deleteEventByCode', 'currency_nbk')), show($events->calls));

// --- Admin controller: settings form validation -----------------------------

$post = array('currency_nbk_margins' => 'EUR:3,5,USD:2', 'currency_nbk_status' => '1');
list($response, $settings) = nbk_admin_index('POST', $post, array());
$output = is_array($response->output) ? $response->output : array();
check('settings: valid margins are saved', $settings->calls === array(array('editSetting', 'currency_nbk', $post)) && $response->redirect !== null && isset($output['error_margins']) && $output['error_margins'] === '', show(array($settings->calls, $response->redirect, $output)));

list($response, $settings) = nbk_admin_index('POST', array('currency_nbk_margins' => 'EUR:3,bad', 'currency_nbk_status' => '1'), array());
$output = is_array($response->output) ? $response->output : array();
check('settings: invalid margins are rejected', $settings->calls === array() && $response->redirect === null && isset($output['error_margins'], $output['currency_nbk_margins']) && $output['error_margins'] === 'error_margins' && $output['currency_nbk_margins'] === 'EUR:3,bad', show(array($settings->calls, $response->redirect, $output)));

list($response, $settings) = nbk_admin_index('POST', array('currency_nbk_margins' => array('x'), 'currency_nbk_status' => '1'), array());
$output = is_array($response->output) ? $response->output : array();
check('settings: array margins are rejected without warnings', $settings->calls === array() && isset($output['error_margins']) && $output['error_margins'] === 'error_margins', show(array($settings->calls, $output)));

// A bad value already in the DB is shown as is; only saving validates it.
list($response, $settings) = nbk_admin_index('GET', array(), array('currency_nbk_margins' => 'EUR:3,bad'));
$output = is_array($response->output) ? $response->output : array();
check('settings: GET shows stored value without error', isset($output['error_margins'], $output['currency_nbk_margins']) && $output['error_margins'] === '' && $output['currency_nbk_margins'] === 'EUR:3,bad', show($output));

$fake = new FakeNbkModel();
list($controller) = nbk_controller('NbkTest\\AdminController\\ControllerExtensionCurrencyNbk', array(
	'config'                       => new FakeConfig(array()),
	'model_extension_currency_nbk' => $fake,
));
$output = null;
$controller->refreshEvent('localisation/currency/refresh', array(), $output);
check('refreshEvent: runs the NBK refresh once', $fake->calls === 1, 'calls=' . $fake->calls);

$controller->currency();
check('currency(): runs the NBK refresh (fork compatibility)', $fake->calls === 2, 'calls=' . $fake->calls);

// --- PHP diagnostics --------------------------------------------------------

restore_error_handler();

$lines = array();

foreach ($issues as $issue => $count) {
	$lines[] = $issue . ($count > 1 ? ' (x' . $count . ')' : '');
}

check('no notices, warnings or deprecations', $issues === array(), "\n     " . implode("\n     ", $lines));

echo 'PHP ' . PHP_VERSION . ': ' . $passed . ' passed, ' . $failed . " failed\n";

exit($failed ? 1 : 0);
