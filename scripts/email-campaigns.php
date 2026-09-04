#!/usr/bin/env php
<?php

// Process scheduled email campaigns in small, retryable batches.
// Suggested cron:
//   * * * * * php /path/to/coysh-crm/scripts/email-campaigns.php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use CoyshCRM\Services\CampaignDispatcher;

$lockPath = DATA_PATH . '/email-campaigns.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . date('Y-m-d H:i:s') . "] Email worker already running.\n";
    exit(0);
}

try {
    $result = (new CampaignDispatcher($db))->run(50);
    echo sprintf(
        "[%s] Email worker complete — %d accepted, %d failed, %d unknown.\n",
        date('Y-m-d H:i:s'), $result['sent'], $result['failed'], $result['unknown']
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Email worker failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
}
