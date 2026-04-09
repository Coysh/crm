<?php

/**
 * Cloudflare sync script.
 * Syncs all Cloudflare zones and DNS records to local database.
 *
 * Suggested cron:
 *   0 6 * * * php /path/to/coysh-crm/scripts/cloudflare-sync.php
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');

require BASE_PATH . '/vendor/autoload.php';

$db = new PDO('sqlite:' . DATA_PATH . '/crm.db', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$cf   = new CoyshCRM\Services\CloudflareService($db);
$sync = new CoyshCRM\Services\CloudflareSync($cf, $db);

if (!$cf->isConnected()) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: Cloudflare API token not configured.\n";
    exit(1);
}

try {
    $results = $sync->syncAll();
    echo "[" . date('Y-m-d H:i:s') . "] Zones synced: {$results['zones']}\n";
    echo "[" . date('Y-m-d H:i:s') . "] DNS records synced: {$results['dns_records']}\n";
    exit(0);
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
