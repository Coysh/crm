#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use CoyshCRM\Services\PloiService;
use CoyshCRM\Services\PloiSync;

$ploi = new PloiService($db);

if (!$ploi->isConnected()) {
    echo "[skip] Ploi is not connected.\n";
    exit(0);
}

set_time_limit(600);
echo '[' . date('Y-m-d H:i:s') . "] Starting Ploi full sync...\n";

$sync = new PloiSync($db, $ploi);
$results = $sync->fullSync();

echo "  servers: {$results['servers']}\n";
echo "  sites:   {$results['sites']}\n";

if (!empty($results['errors'])) {
    foreach ($results['errors'] as $type => $err) {
        echo "  [$type] $err\n";
    }
    exit(1);
}

echo '[' . date('Y-m-d H:i:s') . "] Sync complete.\n";
