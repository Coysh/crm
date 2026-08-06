#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use CoyshCRM\Services\WpmgrService;
use CoyshCRM\Services\WpmgrSync;

$wpmgr = new WpmgrService($db);

if (!$wpmgr->isConnected()) {
    echo "[skip] WPMGR is not connected.\n";
    exit(0);
}

set_time_limit(600);
echo '[' . date('Y-m-d H:i:s') . "] Starting WPMGR full sync...\n";

$sync = new WpmgrSync($db, $wpmgr);
$results = $sync->fullSync();

echo "  sites: {$results['sites']}\n";

if (!empty($results['errors'])) {
    foreach ($results['errors'] as $type => $err) {
        echo "  [$type] $err\n";
    }
    exit(1);
}

echo '[' . date('Y-m-d H:i:s') . "] Sync complete.\n";
