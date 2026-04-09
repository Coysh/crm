<?php

/**
 * Daily exchange rate sync script.
 * Fetches today's GBP→USD and GBP→EUR rates from Frankfurter API and caches them.
 *
 * Suggested cron:
 *   0 7 * * * php /path/to/coysh-crm/scripts/exchange-rates-sync.php
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');

require BASE_PATH . '/vendor/autoload.php';

$db = new PDO('sqlite:' . DATA_PATH . '/crm.db', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$svc    = new CoyshCRM\Services\ExchangeRateService($db);
$rates  = $svc->fetchFromApi();

if (empty($rates)) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Failed to fetch rates from Frankfurter API.\n";
    exit(1);
}

foreach ($rates as $currency => $rate) {
    echo "[" . date('Y-m-d H:i:s') . "] 1 GBP = {$rate} {$currency}\n";
}
exit(0);
