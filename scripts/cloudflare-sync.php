<?php

/**
 * Cloudflare sync script.
 * Syncs all Cloudflare zones and DNS records to local database.
 * Scheduled daily by scripts/cron.php; exits 1 if any zone's DNS failed.
 */

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');

require BASE_PATH . '/vendor/autoload.php';

$db = new PDO('sqlite:' . DATA_PATH . '/crm.db', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$db->exec('PRAGMA foreign_keys = ON');
$db->exec('PRAGMA busy_timeout = 30000'); // web, cron and MCP write concurrently

$cf   = new CoyshCRM\Services\CloudflareService($db);
$sync = new CoyshCRM\Services\CloudflareSync($cf, $db);

if (!$cf->isConnected()) {
    echo "[skip] Cloudflare is not connected.\n";
    exit(0);
}

try {
    $results = $sync->syncAll();
    echo "[" . date('Y-m-d H:i:s') . "] Zones synced: {$results['zones']}\n";
    echo "[" . date('Y-m-d H:i:s') . "] DNS records synced: {$results['dns_records']}\n";
    foreach ($results['errors'] as $zone => $err) {
        echo "  [dns] {$zone}: {$err}\n";
    }
    exit($results['errors'] ? 1 : 0);
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
