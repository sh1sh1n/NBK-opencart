<?php
// Runtime smoke tests for the NBK module. No OpenCart install, DB or network.
//
// Each module file is loaded into its own namespace. Unqualified curl_exec()
// then resolves to the stub below and serves tests/fixtures/rates_all.xml,
// while curl_init/curl_setopt/curl_close stay real, so their deprecations on
// newer PHP still surface. Unqualified filter_var() resolves to a pass-through
// wrapper that counts non-scalar arguments in NbkProbe, so tests can prove a
// forged array never reaches it. Any notice, warning or deprecation fails the run:
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

// Counts non-scalar values that reached the namespaced filter_var() wrapper.
class NbkProbe {
	public static $filterVarNonScalar = 0;
}

// Wraps the real admin model and records every call as array(method, args...),
// so tests can see what the controller hands to the validators.
class NbkModelSpy {
	public $calls = array();
	private $inner;

	public function __construct($inner) {
		$this->inner = $inner;
	}

	public function __call($method, $args) {
		$this->calls[] = array_merge(array($method), $args);
		return call_user_func_array(array($this->inner, $method), $args);
	}
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
		. ' function curl_exec($handle) { \NbkFeed::$calls++; return \NbkFeed::$response; }'
		. ' function filter_var($value, $filter = FILTER_DEFAULT, $options = 0) { if (!is_scalar($value)) { \NbkProbe::$filterVarNonScalar++; } return \filter_var($value, $filter, $options); } '
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

// Calls from a spy log that got at least one non-scalar argument.
function nbk_non_scalar_calls(array $calls) {
	$found = array();

	foreach ($calls as $call) {
		foreach (array_slice($call, 1) as $arg) {
			if (!is_scalar($arg)) {
				$found[] = $call;
				break;
			}
		}
	}

	return $found;
}

// Runs the admin settings page with the real admin model behind a spy.
function nbk_admin_index($method, array $post, array $config, $permitted = true) {
	list($model) = nbk_model('NbkTest\\AdminModel', array(), array());
	$settings = new FakeRecorder();
	$user     = new FakeRecorder();
	$spy      = new NbkModelSpy($model);

	$user->permitted = $permitted;

	list($controller, $registry) = nbk_controller('NbkTest\\AdminController\\ControllerExtensionCurrencyNbk', array(
		'config'                       => new FakeConfig($config),
		'user'                         => $user,
		'document'                     => new FakeRecorder(),
		'model_setting_setting'        => $settings,
		'language'                     => new FakeLanguage(),
		'url'                          => new FakeUrl(),
		'session'                      => new FakeSession(),
		'model_extension_currency_nbk' => $spy,
	));

	$registry->get('request')->server['REQUEST_METHOD'] = $method;
	$registry->get('request')->post = $post;

	$controller->index();

	return array($registry->get('response'), $settings, $spy);
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

	// -100% or less must never reach the DB: the official rate is written instead.
	foreach (array('USD:-100', 'USD:-150') as $setting) {
		list($model, $registry) = nbk_model($ns, array('currency_nbk_status' => 1, 'config_currency' => 'KZT', 'currency_nbk_margins' => $setting), $codes);
		$result = $model->refresh();
		$writes = $registry->get('db')->writes();
		$expect = array('KZT' => '1.00000000', 'USD' => '0.00200000', 'EUR' => '0.00181818', 'AMD' => '0.80000000', 'RUB' => '0.18181818', 'CNY' => '0.01428571');
		ksort($writes);
		ksort($expect);
		check($side . ': refresh with ' . $setting . ' returns true', $result === true, show($result));
		check($side . ': refresh with ' . $setting . ' writes the official USD rate', $writes === $expect, show($writes));

		$positive = $writes !== array();

		foreach ($writes as $v) {
			if (!((float)$v > 0)) {
				$positive = false;
			}
		}

		check($side . ': refresh with ' . $setting . ' writes only positive values', $positive, show($writes));
	}

	// Only the bad pair is dropped; EUR 1/550 * 1.1 = 0.002.
	list($model, $registry) = nbk_model($ns, array('currency_nbk_status' => 1, 'config_currency' => 'KZT', 'currency_nbk_margins' => 'USD:-150,EUR:10'), $codes);
	$result = $model->refresh();
	$writes = $registry->get('db')->writes();
	$expect = array('KZT' => '1.00000000', 'USD' => '0.00200000', 'EUR' => '0.00200000', 'AMD' => '0.80000000', 'RUB' => '0.18181818', 'CNY' => '0.01428571');
	ksort($writes);
	ksort($expect);
	check($side . ': refresh drops only the out-of-range margin', $result === true && $writes === $expect, show($writes));

	// Default USD: KZT 500 * 0 is refused, EUR 500/550 * 0.01 = 0.00909091 is kept.
	list($model, $registry) = nbk_model($ns, array('currency_nbk_status' => 1, 'config_currency' => 'USD', 'currency_nbk_margins' => 'KZT:-100,EUR:-99'), $codes);
	$result = $model->refresh();
	$writes = $registry->get('db')->writes();
	$expect = array('KZT' => '500.00000000', 'USD' => '1.00000000', 'EUR' => '0.00909091', 'AMD' => '400.00000000', 'RUB' => '90.90909091', 'CNY' => '7.14285714');
	ksort($writes);
	ksort($expect);
	check($side . ': default USD, KZT:-100 refused, EUR:-99 applied', $result === true && $writes === $expect, show($writes));

	// USD 1/500 * 0.0001 = 0.0000002 survives; * 0.000000001 rounds to 0 and is refused.
	foreach (array('USD:-99,99' => '0.00000020', 'USD:-99.9999999' => '0.00200000') as $setting => $usd) {
		list($model, $registry) = nbk_model($ns, array('currency_nbk_status' => 1, 'config_currency' => 'KZT', 'currency_nbk_margins' => $setting), $codes);
		$result = $model->refresh();
		$writes = $registry->get('db')->writes();
		check($side . ': refresh with ' . $setting . ' writes USD ' . $usd, $result === true && isset($writes['USD']) && $writes['USD'] === $usd, show($writes));
	}

	foreach (array('', 'EUR:3,USD:2', 'AMD:-1,5', 'USD:-99,99') as $value) {
		check($side . ': validateMarginRange accepts ' . show($value), $model->validateMarginRange($value) === true);
	}

	foreach (array('USD:-100', 'USD:-150', 'USD:-100,0', 'usd:-100%', 'EUR:3,USD:-100', 'USD:-150,USD:2', array('USD:-150')) as $value) {
		check($side . ': validateMarginRange rejects ' . show($value), $model->validateMarginRange($value) === false);
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

// Well-formed but -100% or less: rejected with its own message, input kept.
foreach (array('USD:-100', 'USD:-150') as $value) {
	list($response, $settings) = nbk_admin_index('POST', array('currency_nbk_margins' => $value, 'currency_nbk_status' => '1'), array());
	$output = is_array($response->output) ? $response->output : array();
	check('settings: ' . $value . ' is rejected as out of range', $settings->calls === array() && $response->redirect === null && isset($output['error_margins'], $output['currency_nbk_margins']) && $output['error_margins'] === 'error_margins_range' && $output['currency_nbk_margins'] === $value, show(array($settings->calls, $response->redirect, $output)));
}

// Format is checked first, so a malformed value keeps the format message.
list($response, $settings) = nbk_admin_index('POST', array('currency_nbk_margins' => 'EUR:3,bad', 'currency_nbk_status' => '1'), array());
$output = is_array($response->output) ? $response->output : array();
check('settings: format error wins over range error', isset($output['error_margins']) && $output['error_margins'] === 'error_margins', show($output));

$post = array('currency_nbk_margins' => 'USD:-99,99', 'currency_nbk_status' => '1');
list($response, $settings) = nbk_admin_index('POST', $post, array());
check('settings: USD:-99,99 is in range and saved', $settings->calls === array(array('editSetting', 'currency_nbk', $post)) && $response->redirect !== null, show(array($settings->calls, $response->redirect)));

// An out-of-range value already in the DB is shown as is; only saving validates it.
list($response, $settings) = nbk_admin_index('GET', array(), array('currency_nbk_margins' => 'USD:-150'));
$output = is_array($response->output) ? $response->output : array();
check('settings: GET shows stored out-of-range value without error', isset($output['error_margins'], $output['currency_nbk_margins']) && $output['error_margins'] === '' && $output['currency_nbk_margins'] === 'USD:-150', show($output));

// Twig in OC 3 does not autoescape, so the controller must hand over attribute-safe values.
$x   = '"><b>x</b>';
$esc = '&quot;&gt;&lt;b&gt;x&lt;/b&gt;';

list($response, $settings) = nbk_admin_index('POST', array('currency_nbk_ip' => $x, 'currency_nbk_margins' => $x, 'currency_nbk_status' => '1'), array());
$output = is_array($response->output) ? $response->output : array();
check('settings: POST markup in ip and margins is escaped for the attribute', $settings->calls === array() && isset($output['error_ip'], $output['error_margins'], $output['currency_nbk_ip'], $output['currency_nbk_margins']) && $output['error_ip'] === 'error_ip' && $output['error_margins'] === 'error_margins' && $output['currency_nbk_ip'] === '&quot;&gt;&lt;b&gt;x&lt;/b&gt;' && $output['currency_nbk_margins'] === '&quot;&gt;&lt;b&gt;x&lt;/b&gt;', show(array($settings->calls, $output)));

// OC Request already runs POST through htmlspecialchars; that must not become &amp;quot;.
list($response, $settings) = nbk_admin_index('POST', array('currency_nbk_ip' => $esc, 'currency_nbk_margins' => $esc, 'currency_nbk_status' => '1'), array());
$output = is_array($response->output) ? $response->output : array();
check('settings: pre-escaped POST (as OC Request delivers it) is not double-escaped', isset($output['currency_nbk_ip'], $output['currency_nbk_margins']) && $output['currency_nbk_ip'] === '&quot;&gt;&lt;b&gt;x&lt;/b&gt;' && $output['currency_nbk_margins'] === '&quot;&gt;&lt;b&gt;x&lt;/b&gt;', show($output));

list($response, $settings) = nbk_admin_index('GET', array(), array('currency_nbk_ip' => $x, 'currency_nbk_margins' => $esc));
$output = is_array($response->output) ? $response->output : array();
check('settings: stored markup is escaped on GET', isset($output['currency_nbk_ip'], $output['currency_nbk_margins'], $output['error_ip'], $output['error_margins']) && $output['currency_nbk_ip'] === '&quot;&gt;&lt;b&gt;x&lt;/b&gt;' && $output['currency_nbk_margins'] === '&quot;&gt;&lt;b&gt;x&lt;/b&gt;' && $output['error_ip'] === '' && $output['error_margins'] === '', show($output));

list($response, $settings) = nbk_admin_index('GET', array(), array('currency_nbk_margins' => "a'b&c"));
$output = is_array($response->output) ? $response->output : array();
check('settings: quote and ampersand are escaped', isset($output['currency_nbk_margins']) && $output['currency_nbk_margins'] === 'a&#039;b&amp;c', show($output));

list($response, $settings) = nbk_admin_index('GET', array(), array('currency_nbk_ip' => '2001:db8::1', 'currency_nbk_margins' => 'EUR:3,5,USD:2'));
$output = is_array($response->output) ? $response->output : array();
check('settings: valid values pass through unchanged', isset($output['currency_nbk_ip'], $output['currency_nbk_margins']) && $output['currency_nbk_ip'] === '2001:db8::1' && $output['currency_nbk_margins'] === 'EUR:3,5,USD:2', show($output));

list($response, $settings) = nbk_admin_index('GET', array(), array());
$output = is_array($response->output) ? $response->output : array();
check('settings: missing settings render as empty strings', isset($output['currency_nbk_ip'], $output['currency_nbk_margins']) && $output['currency_nbk_ip'] === '' && $output['currency_nbk_margins'] === '', show($output));
check('settings: cron command is unchanged', isset($output['currency_nbk_cron']) && $output['currency_nbk_cron'] === 'curl -s &quot;https://shop.test/index.php?route=extension/currency/nbk/refresh&quot;', show($output));

list($response, $settings) = nbk_admin_index('POST', array('currency_nbk_ip' => array('x'), 'currency_nbk_margins' => array('x'), 'currency_nbk_status' => '1'), array());
$output = is_array($response->output) ? $response->output : array();
check('settings: array ip and margins render as empty strings', $settings->calls === array() && isset($output['currency_nbk_ip'], $output['currency_nbk_margins']) && $output['currency_nbk_ip'] === '' && $output['currency_nbk_margins'] === '', show(array($settings->calls, $output)));

list($response, $settings) = nbk_admin_index('GET', array(), array('currency_nbk_margins' => "EUR\xFF"));
$output = is_array($response->output) ? $response->output : array();
check('settings: invalid UTF-8 is substituted the same on every PHP', isset($output['currency_nbk_margins']) && $output['currency_nbk_margins'] === "EUR\xEF\xBF\xBD", show($output));

// A forged currency_nbk_status[]=1 must not be stored as a JSON array.
$post = array('currency_nbk_status' => array('1'), 'currency_nbk_ip' => '', 'currency_nbk_margins' => 'EUR:3');
list($response, $settings) = nbk_admin_index('POST', $post, array());
$output = is_array($response->output) ? $response->output : array();
check('settings: array status is rejected and not saved', $settings->calls === array() && $response->redirect === null && isset($output['error_warning'], $output['error_ip'], $output['error_margins']) && $output['error_warning'] === 'error_status' && $output['error_ip'] === '' && $output['error_margins'] === '', show(array($settings->calls, $response->redirect, $output)));

$status = array(
	// label => array(method, post, config, expected)
	'POST array'        => array('POST', $post, array(), '1'),
	'stored array'      => array('GET', array(), array('currency_nbk_status' => array('1')), '1'),
	'stored int 1'      => array('GET', array(), array('currency_nbk_status' => 1), '1'),
	'stored string 0'   => array('GET', array(), array('currency_nbk_status' => '0'), '0'),
	'not stored'        => array('GET', array(), array(), '0'),
	'POST 0, bad input' => array('POST', array('currency_nbk_status' => '0', 'currency_nbk_margins' => 'EUR:3,bad'), array(), '0'),
);

foreach ($status as $label => $case) {
	list($response, $settings) = nbk_admin_index($case[0], $case[1], $case[2]);
	$output = is_array($response->output) ? $response->output : array();
	check('settings: status reaches the template as \'0\'/\'1\', never an array (' . $label . ')', isset($output['currency_nbk_status']) && $output['currency_nbk_status'] === $case[3], show($output));
}

list($response, $settings) = nbk_admin_index('POST', $post, array(), false);
$output = is_array($response->output) ? $response->output : array();
check('settings: permission error wins over array status', $settings->calls === array() && isset($output['error_warning']) && $output['error_warning'] === 'error_permission', show(array($settings->calls, $output)));

$probe = NbkProbe::$filterVarNonScalar;
list($response, $settings) = nbk_admin_index('POST', array('currency_nbk_status' => '1', 'currency_nbk_ip' => array('10.0.0.1'), 'currency_nbk_margins' => 'EUR:3'), array());
$output = is_array($response->output) ? $response->output : array();
check('settings: array ip is rejected without reaching filter_var', $settings->calls === array() && isset($output['error_ip'], $output['error_margins'], $output['error_warning'], $output['currency_nbk_ip']) && $output['error_ip'] === 'error_ip' && $output['error_margins'] === '' && $output['error_warning'] === '' && $output['currency_nbk_ip'] === '' && NbkProbe::$filterVarNonScalar === $probe, show(array($settings->calls, NbkProbe::$filterVarNonScalar - $probe, $output)));

list($response, $settings, $spy) = nbk_admin_index('POST', array('currency_nbk_status' => '1', 'currency_nbk_ip' => '10.0.0.1', 'currency_nbk_margins' => array('EUR:3')), array());
$output = is_array($response->output) ? $response->output : array();
check('settings: array margins never reach the model validators', $settings->calls === array() && isset($output['error_margins'], $output['error_ip']) && $output['error_margins'] === 'error_margins' && $output['error_ip'] === '' && nbk_non_scalar_calls($spy->calls) === array(), show(array($settings->calls, $spy->calls, $output)));

$probe = NbkProbe::$filterVarNonScalar;
list($response, $settings, $spy) = nbk_admin_index('POST', array('currency_nbk_status' => array(array('1')), 'currency_nbk_ip' => array(array('x')), 'currency_nbk_margins' => array('a' => array('b'))), array());
$output = is_array($response->output) ? $response->output : array();
check('settings: nested arrays in all three fields are rejected', $settings->calls === array() && isset($output['error_warning'], $output['error_ip'], $output['error_margins']) && $output['error_warning'] === 'error_status' && $output['error_ip'] === 'error_ip' && $output['error_margins'] === 'error_margins' && NbkProbe::$filterVarNonScalar === $probe && nbk_non_scalar_calls($spy->calls) === array(), show(array($settings->calls, NbkProbe::$filterVarNonScalar - $probe, $spy->calls, $output)));

$post = array('currency_nbk_status' => '0', 'currency_nbk_ip' => '10.0.0.1', 'currency_nbk_margins' => 'EUR:3,5');
list($response, $settings) = nbk_admin_index('POST', $post, array());
check('settings: scalar POST is saved exactly as sent', $settings->calls === array(array('editSetting', 'currency_nbk', $post)) && $response->redirect !== null, show(array($settings->calls, $response->redirect)));

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

check('filter_var never received a non-scalar during the run', NbkProbe::$filterVarNonScalar === 0, 'count=' . NbkProbe::$filterVarNonScalar);

restore_error_handler();

$lines = array();

foreach ($issues as $issue => $count) {
	$lines[] = $issue . ($count > 1 ? ' (x' . $count . ')' : '');
}

check('no notices, warnings or deprecations', $issues === array(), "\n     " . implode("\n     ", $lines));

echo 'PHP ' . PHP_VERSION . ': ' . $passed . ' passed, ' . $failed . " failed\n";

exit($failed ? 1 : 0);
