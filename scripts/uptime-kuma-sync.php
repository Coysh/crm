#!/usr/bin/env php
<?php

// Mirror Uptime Kuma monitor state into the CRM.
//
// Runs far more often than the other syncs — /metrics is one cheap request for
// the whole fleet, and each run doubles as the sample the CRM uses to compute
// its own uptime percentages (Uptime Kuma's REST surface exposes no uptime %).
//
// Suggested cron — every 5 minutes:
//   */5 * * * * php /path/to/coysh-crm/scripts/uptime-kuma-sync.php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use CoyshCRM\Services\UptimeKumaService;
use CoyshCRM\Services\UptimeKumaSync;

$kuma = new UptimeKumaService($db);

if (!$kuma->isConnected()) {
    echo "[skip] Uptime Kuma is not connected.\n";
    exit(0);
}

set_time_limit(120);

$sync    = new UptimeKumaSync($db, $kuma);
$results = $sync->fullSync();

// One line per run — this fires 288 times a day.
if (!empty($results['errors'])) {
    echo '[' . date('Y-m-d H:i:s') . '] Uptime Kuma sync failed: ' . implode('; ', $results['errors']) . "\n";
    exit(1);
}

echo '[' . date('Y-m-d H:i:s') . "] Uptime Kuma sync complete — {$results['monitors']} monitor(s).\n";
