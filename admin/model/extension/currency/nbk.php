<?php
class ModelExtensionCurrencyNbk extends Model {
	public function editValueByCode($code, $value) {
		$value = $this->formatValue($value);
		$this->db->query("UPDATE `" . DB_PREFIX . "currency` SET `value` = '" . $value . "', `date_modified` = NOW() WHERE `code` = '" . $this->db->escape((string)$code) . "'");
		$this->cache->delete('currency');
	}

	// decimal(15,8)-safe fixed-point string with 8 digits: a plain float-to-string
	// cast can give scientific notation for very small/large cross-rates. The
	// uppercase F ignores LC_NUMERIC, which the host or another extension may set:
	// the lowercase one would print "0,002..." there and break the SQL value.
	private function formatValue($value) {
		return sprintf('%.8F', (float)$value);
	}

	// `value` is (15,8): at most 7 integer digits, i.e. up to 9999999.99999999.
	// Strict MySQL rejects a larger UPDATE, non-strict silently clips it.
	private const VALUE_LIMIT = 10000000;

	// Upper margin bound for the settings form only, see validateMarginRange().
	private const MARGIN_MAX = 100;

	// Whether the rate survives the column as a positive number. Compared at the
	// 8-digit DB precision: 9999999.999999999 is stored as 10000000.00000000 and
	// a tiny rate as 0.00000000, both refused. INF/NAN from garbage never fit.
	private function fitsColumn($value) {
		$stored = (float)$this->formatValue($value);

		return is_finite((float)$value) && $stored > 0 && $stored < self::VALUE_LIMIT;
	}

	// One "CODE:percent" pair of the margin setting "EUR:3,5,USD:2,RUB:5".
	// A currency code never starts with a digit, so a comma followed by a digit
	// always belongs to the number: pairs are matched over the whole string
	// instead of splitting on commas, and both "3,5" and "3.5" work.
	// "+", a leading dot (".5") and a trailing "%" stay allowed because the old
	// explode-based parser accepted them and such values may already be stored.
	private const MARGIN_PAIR = '([A-Za-z]{3})\s*:\s*([+-]?(?:\d+(?:[.,]\d+)?|\.\d+))%?';

	// Parse the per-currency margin setting into array('EUR' => 3.5, ...).
	// Forgiving by design (runs from cron): garbage between pairs is skipped,
	// codes upper-cased, the last duplicate wins. The lookbehind keeps "EURO:3"
	// or "XEUR:4" from yielding URO/EUR. Change the storage format here only.
	private function parseMargins() {
		$margins = array();

		foreach ($this->matchMarginPairs((string)$this->config->get('currency_nbk_margins')) as $pair) {
			$margins[$pair[0]] = $pair[1];
		}

		return $margins;
	}

	// All pairs in order of appearance as array(array('EUR', 3.5), ...), duplicates
	// kept: parseMargins() lets the last one win, validateMarginRange() checks each.
	private function matchMarginPairs($string) {
		$pairs = array();

		preg_match_all('/(?<![A-Za-z])' . self::MARGIN_PAIR . '/', $string, $matches, PREG_SET_ORDER);

		foreach ($matches as $m) {
			$pairs[] = array(strtoupper($m[1]), (float)str_replace(',', '.', $m[2]));
		}

		return $pairs;
	}

	// Strict check for the settings form: the whole value must be a list of
	// pairs (one trailing comma allowed); empty means "no margins". Reads no config.
	public function validateMargins($value) {
		return is_string($value) && preg_match('/^\s*(?:' . self::MARGIN_PAIR . '(?:\s*,\s*' . self::MARGIN_PAIR . ')*\s*,?)?\s*$/', $value) === 1;
	}

	// Range check for the settings form, run after validateMargins(). The rate is
	// multiplied by (1 + percent / 100): -100 zeroes it and anything lower makes
	// it negative. 100 already doubles the rate, so more is almost surely a typo.
	// Every pair counts, even one a later duplicate would override, so nothing
	// like that is ever stored. refresh() deliberately does not apply the upper
	// bound to values saved earlier; it only guards the column range.
	public function validateMarginRange($value) {
		if (!is_string($value)) {
			return false;
		}

		foreach ($this->matchMarginPairs($value) as $pair) {
			if ($pair[1] <= -100 || $pair[1] > self::MARGIN_MAX) {
				return false;
			}
		}

		return true;
	}

	public function refresh($force = false) {
		if (!$this->config->get('currency_nbk_status')) {
			return false;
		}

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, 'https://nationalbank.kz/rss/rates_all.xml');
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HEADER, false);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_USERAGENT, 'OpenCart/3 NBK currency updater');
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($curl, CURLOPT_TIMEOUT, 30);
		$response = curl_exec($curl);
		// No-op since PHP 8.0 (the handle is freed with its last reference) and
		// deprecated in 8.5, where the notice would leak into the cron response.
		if (PHP_VERSION_ID < 80000) {
			curl_close($curl);
		}

		if (!$response) {
			return false;
		}

		libxml_use_internal_errors(true);
		$dom = new \DOMDocument('1.0', 'UTF-8');
		if (!$dom->loadXml($response)) {
			libxml_clear_errors();
			return false;
		}

		// NBK feed item: <description> tenge per <quant> units of <title>.
		// Build "KZT per 1 unit" table. KZT is the feed base and is NOT listed -> add it manually.
		$rates = array('KZT' => 1.0);

		foreach ($dom->getElementsByTagName('item') as $item) {
			$title = $item->getElementsByTagName('title')->item(0);
			$desc  = $item->getElementsByTagName('description')->item(0);
			$quant = $item->getElementsByTagName('quant')->item(0);

			if (!$title || !$desc) {
				continue;
			}

			$code     = trim($title->nodeValue);
			$rate     = (float)str_replace(',', '.', $desc->nodeValue);
			$quantity = $quant ? (float)$quant->nodeValue : 1.0;

			if ($code === '' || $rate <= 0 || $quantity <= 0) {
				continue;
			}

			$rates[$code] = $rate / $quantity; // KZT per 1 unit of $code
		}

		$default = $this->config->get('config_currency');

		// If the store default isn't KZT and isn't covered by the feed, there is
		// nothing reliable to pivot through -> abort instead of writing garbage.
		if (!isset($rates[$default])) {
			return false;
		}

		$margins = $this->parseMargins();

		// value_X = units of X per 1 unit of store default = kzt_per[default] / kzt_per[X],
		// then padded by an optional per-currency margin to absorb conversion spread.
		$query = $this->db->query("SELECT `code` FROM `" . DB_PREFIX . "currency`");

		foreach ($query->rows as $result) {
			$code = $result['code'];

			if (!isset($rates[$code])) {
				continue; // not in NBK feed -> leave its manually-set value untouched
			}

			$value = $rates[$default] / $rates[$code];

			// A rate the column cannot hold is skipped like a currency outside the
			// feed: strict MySQL would abort the update halfway, and a clipped or
			// zero rate would corrupt prices.
			if (!$this->fitsColumn($value)) {
				continue;
			}

			// Margin applies to foreign currencies only; the default must stay exactly 1.
			if ($code !== $default && isset($margins[$code]) && $margins[$code] != 0) {
				$marked = $value * (1 + ($margins[$code] / 100));

				// -100% or less (possibly saved before the form checked the range)
				// would zero or negate prices, and a huge margin can push the rate
				// past the column; keep the official rate in both cases, which also
				// repairs zeros written by older versions. Checked at the 8-digit
				// DB precision, so a rate that rounds to 0 is refused too.
				if ($this->fitsColumn($marked)) {
					$value = $marked;
				}
			}

			$this->editValueByCode($code, $value);
		}

		// Normalise the default currency to exactly 1.
		$this->editValueByCode($default, 1);

		return true;
	}
}
