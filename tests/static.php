<?php
// Version-independent checks for the NBK module: invariants and portability
// rules that php -l and the smoke run cannot see. Must run on PHP 7.4+.
// Usage: php tests/static.php

error_reporting(E_ALL);

$root   = dirname(__DIR__);
$failed = 0;
$passed = 0;

function check($name, $ok, $detail = '') {
	global $failed, $passed;

	if ($ok) {
		$passed++;
		echo 'ok   ' . $name . "\n";
	} else {
		$failed++;
		echo 'FAIL ' . $name . ($detail !== '' ? "\n     " . str_replace("\n", "\n     ", rtrim($detail)) : '') . "\n";
	}
}

// Repo-relative paths of every file on disk, minus git internals and
// local junk that .gitignore already keeps out of commits.
function repo_files($root) {
	$files = array();
	$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

	foreach ($items as $item) {
		$path = substr($item->getPathname(), strlen($root) + 1);

		if (preg_match('#(^|/)(\.git|graphify-out)/#', $path) || basename($path) === '.DS_Store' || $path === '.claude/settings.local.json') {
			continue;
		}

		$files[] = $path;
	}

	sort($files);

	return $files;
}

$files = repo_files($root);
$code  = array_values(array_filter($files, function ($path) {
	return preg_match('/\.(php|twig)$/', $path) === 1;
}));

// --- Module layout -----------------------------------------------------------

$allowed = array(
	'#^(admin|catalog)/(controller|model)/extension/currency/nbk\.php$#',
	'#^admin/language/[a-z]{2}-[a-z]{2}/extension/currency/nbk\.php$#',
	'#^admin/view/template/extension/currency/nbk\.twig$#',
	'#^(tests|tasks|\.claude)/#',
	'#^(CLAUDE\.md|README\.md|LICENSE|\.gitignore)$#',
);

$unexpected = array();

foreach ($files as $path) {
	$known = false;

	foreach ($allowed as $pattern) {
		if (preg_match($pattern, $path)) {
			$known = true;
			break;
		}
	}

	if (!$known) {
		$unexpected[] = $path;
	}
}

check('only module, tests, tasks and project docs in the tree (no core files, no OCMOD)', !$unexpected, implode("\n", $unexpected));

// --- Invariants ---------------------------------------------------------------

check(
	'admin and catalog models are identical',
	file_get_contents($root . '/admin/model/extension/currency/nbk.php') === file_get_contents($root . '/catalog/model/extension/currency/nbk.php')
);

$keys  = array();
$empty = array();

foreach (glob($root . '/admin/language/*/extension/currency/nbk.php') as $file) {
	$_ = array();
	include $file;

	$locale        = basename(dirname(dirname(dirname($file))));
	$keys[$locale] = array_keys($_);

	foreach ($_ as $key => $value) {
		if (trim((string)$value) === '') {
			$empty[] = $locale . ': ' . $key;
		}
	}
}

$all     = array_unique(call_user_func_array('array_merge', array_values($keys)));
$missing = array();

foreach ($keys as $locale => $list) {
	$gap = array_diff($all, $list);

	if ($gap) {
		$missing[] = $locale . ' missing: ' . implode(', ', $gap);
	}
}

check('language keys match across ' . implode(', ', array_keys($keys)), !$missing, implode("\n", $missing));
check('no empty language strings', !$empty, implode("\n", $empty));

$twig    = file_get_contents($root . '/admin/view/template/extension/currency/nbk.twig');
$balance = array();

foreach (array('if', 'for', 'block') as $tag) {
	$open  = preg_match_all('/\{%-?\s*' . $tag . '\s/', $twig);
	$close = preg_match_all('/\{%-?\s*end' . $tag . '\s*-?%\}/', $twig);

	if ($open !== $close) {
		$balance[] = $tag . ': ' . $open . ' open, ' . $close . ' closed';
	}
}

check('twig block tags are balanced', !$balance, implode("\n", $balance));

// The controller escapes these; a Twig filter on top would double-escape them.
$unfiltered = array();

foreach (array('currency_nbk_ip', 'currency_nbk_margins', 'currency_nbk_cron') as $name) {
	if (strpos($twig, 'value="{{ ' . $name . ' }}"') === false || preg_match('/' . $name . '\s*\|/', $twig)) {
		$unfiltered[] = $name;
	}
}

check('twig prints settings values unfiltered (escaped in controller)', !$unfiltered, implode("\n", $unfiltered));

// --- Portability: one codebase for PHP 7.4 .. 8.5 ----------------------------
// php -l on 7.4 already rejects 8.x syntax; these catch what lint cannot.

$newer = 'str_contains|str_starts_with|str_ends_with|get_debug_type|get_resource_id|fdiv|preg_last_error_msg|array_is_list|enum_exists|array_find|array_find_key|array_any|array_all|mb_trim|mb_ltrim|mb_rtrim|mb_ucfirst|mb_lcfirst|array_first|array_last|grapheme_levenshtein';
$gone  = 'utf8_encode|utf8_decode|strftime|gmstrftime|libxml_disable_entity_loader|create_function|each|money_format|mhash|date_sunrise|date_sunset|key_exists|odbc_result_all|mysqli_ping';

$portability = array();
$encoding    = array();

foreach ($code as $path) {
	$source = file_get_contents($root . '/' . $path);

	if (substr($source, 0, 3) === "\xEF\xBB\xBF") {
		$encoding[] = $path . ': UTF-8 BOM';
	}

	if (strpos($source, "\r") !== false) {
		$encoding[] = $path . ': CRLF line endings';
	}

	// This file spells out the patterns it hunts for, so it cannot scan itself.
	if (substr($path, -4) !== '.php' || $path === 'tests/static.php') {
		continue;
	}

	if (preg_match('/\?>\s*$/', $source)) {
		$encoding[] = $path . ': closing ?> tag';
	}

	foreach (explode("\n", $source) as $i => $line) {
		$at = $path . ':' . ($i + 1) . ': ';

		// PHP 8.x-only functions are fine only behind a function_exists() guard.
		if (preg_match_all('/(?<![\w$>:\\\\])(' . $newer . ')\s*\(/', $line, $m)) {
			foreach ($m[1] as $function) {
				if (strpos($source, "function_exists('" . $function . "')") === false) {
					$portability[] = $at . $function . '() needs PHP 8+, guard it with function_exists()';
				}
			}
		}

		if (preg_match('/(?<![\w$>:\\\\])(' . $gone . ')\s*\(/', $line, $m)) {
			$portability[] = $at . $m[1] . '() is deprecated or removed in PHP 8.x';
		}

		if (preg_match('/(?<![\w$>:\\\\])curl_close\s*\(/', $line) && strpos($source, 'PHP_VERSION_ID < 80000') === false) {
			$portability[] = $at . 'curl_close() is deprecated in 8.5; call it only when PHP_VERSION_ID < 80000';
		}

		if (preg_match('/\((integer|boolean|double|binary|real)\)/', $line, $m)) {
			$portability[] = $at . '(' . $m[1] . ') cast is deprecated in 8.5, use (int)/(bool)/(float)/(string)';
		}

		if (preg_match('/"[^"]*\$\{/', $line)) {
			$portability[] = $at . '"${var}" interpolation is deprecated in 8.2, use "{$var}"';
		}

		if (strpos($line, 'FILTER_SANITIZE_STRING') !== false) {
			$portability[] = $at . 'FILTER_SANITIZE_STRING is deprecated in 8.1';
		}
	}
}

check('no PHP 8-only or deprecated APIs', !$portability, implode("\n", $portability));
check('UTF-8 without BOM, LF, no closing ?>', !$encoding, implode("\n", $encoding));

echo 'static: ' . $passed . ' passed, ' . $failed . " failed\n";

exit($failed ? 1 : 0);
