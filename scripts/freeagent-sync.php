#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use CoyshCRM\Services\FreeAgentClient;
use CoyshCRM\Services\FreeAgentSync;

$client = new FreeAgentClient($db);

if (!$client->isConnected()) {
    echo "[skip] FreeAgent is not connected. Configure OAuth in Settings first.\n";
    exit(0);
}

set_time_limit(300);

echo '[' . date('Y-m-d H:i:s') . "] Starting FreeAgent full sync...\n";

$sync    = new FreeAgentSync($db, $client);
$results = $sync->syncAll();

echo "  contacts:          {$results['contacts']}\n";
echo "  invoices:          {$results['invoices']}\n";
echo "  bank_transactions: {$results['bank_transactions']}\n";

if ($results['errors']) {
    echo "\nErrors:\n";
    foreach ($results['errors'] as $type => $msg) {
        echo "  [$type] $msg\n";
    }
    exit(1);
}

echo '[' . date('Y-m-d H:i:s') . "] Sync complete.\n";
exit(0);
