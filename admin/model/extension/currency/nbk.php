<?php
class ModelExtensionCurrencyNbk extends Model {
	public function editValueByCode($code, $value) {
		// decimal(15,8)-safe fixed-point string; avoids scientific notation that
		// a plain (float) cast can produce for very small/large cross-rates.
		$value = sprintf('%.8f', (float)$value);
		$this->db->query("UPDATE `" . DB_PREFIX . "currency` SET `value` = '" . $value . "', `date_modified` = NOW() WHERE `code` = '" . $this->db->escape((string)$code) . "'");
		$this->cache->delete('currency');
	}

	// Parse the per-currency margin setting "EUR:3,USD:2,RUB:5" into ['EUR'=>3.0,...].
	// Forgiving by design: bad/empty pairs are skipped, codes upper-cased, comma or
	// dot accepted as decimal separator. Change the storage format here only.
	private function parseMargins() {
		$margins = array();

		foreach (explode(',', (string)$this->config->get('currency_nbk_margins')) as $pair) {
			$parts = explode(':', trim($pair));

			if (count($parts) !== 2) {
				continue;
			}

			$code = strtoupper(trim($parts[0]));
			$pct  = (float)str_replace(',', '.', trim($parts[1]));

			if ($code !== '') {
				$margins[$code] = $pct;
			}
		}

		return $margins;
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
		curl_close($curl);

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

			// Margin applies to foreign currencies only; the default must stay exactly 1.
			if ($code !== $default && isset($margins[$code]) && $margins[$code] != 0) {
				$value *= 1 + ($margins[$code] / 100);
			}

			$this->editValueByCode($code, $value);
		}

		// Normalise the default currency to exactly 1.
		$this->editValueByCode($default, 1);

		return true;
	}
}
