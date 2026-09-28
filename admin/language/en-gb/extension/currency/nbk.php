<?php
// Heading
$_['heading_title']    = 'National Bank of Kazakhstan';

// Text
$_['text_home']        = 'Home';
$_['text_extension']   = 'Extensions';
$_['text_success']     = 'Success: You have modified NBK currency settings!';
$_['text_edit']        = 'Rates are pulled from nationalbank.kz and converted relative to your store default currency (the feed base is KZT). Manage your currencies <a href="%1">here</a> and set the default in <a href="%2">store settings</a>. Run "Refresh" on the Currencies page or use the cron command below.';
$_['text_enabled']     = 'Enabled';
$_['text_disabled']    = 'Disabled';

// Entry
$_['entry_status']     = 'Status';
$_['entry_ip']         = 'Cron IP';
$_['entry_cron']       = 'Cron command';

// Help
$_['help_ip']          = 'Restrict the cron refresh URL to this single IP. Leave blank to allow any source.';

// Error
$_['error_permission'] = 'Warning: You do not have permission to modify NBK currency!';
$_['error_ip']         = 'Invalid IP address!';
$_['error_margins']    = 'Invalid markup format! Example: EUR:3,5,USD:2';
$_['error_margins_range'] = 'Markup must be greater than -100% and no more than 100%!';
$_['error_status'] = 'Invalid status value!';

// Margins
$_['entry_margins']    = 'Currency markups (%)';
$_['help_margins']     = 'Per-currency markup added on top of the official rate to absorb conversion spread. Format: CODE:percentage, pairs separated by commas; decimals may use a dot or comma, e.g. EUR:3,5,USD:2,RUB:5. Currencies not listed use the exact official rate. The default currency is never marked up.';

