#!/usr/bin/env php
<?php

// Daily digest + immediate site-down alerts (Services\Notifier).
// Scheduled every 5 minutes by scripts/cron.php; decides for itself whether
// the digest is due (Settings → Notifications, UK time).
//
//   php scripts/notify.php                 send whatever is due
//   php scripts/notify.php --dry-run       print the digest that would be sent; change nothing
//   php scripts/notify.php --digest-now    send the digest now regardless of time

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use CoyshCRM\Services\Notifier;

$opts   = getopt('', ['dry-run', 'digest-now']);
$dryRun = isset($opts['dry-run']);
$n      = new Notifier($db);
$cfg    = $n->config();
$stamp  = fn() => '[' . date('Y-m-d H:i:s') . '] ';

if (empty($cfg['digest_enabled']) && empty($cfg['site_down_alerts']) && !$dryRun && !isset($opts['digest-now'])) {
    echo "[skip] Notifications are turned off (Settings → Notifications).\n";
    exit(0);
}

$failed = false;

try {
    $alerted = $n->sendSiteDownAlerts($dryRun);
    if ($alerted) echo $stamp() . ($dryRun ? 'Would alert' : 'Alerted') . ' site down: ' . implode(', ', $alerted) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $stamp() . 'Site-down alert failed: ' . $e->getMessage() . "\n");
    $failed = true;
}

if ($dryRun || isset($opts['digest-now']) || $n->isDigestDue()) {
    try {
        $result = $n->sendDigest($dryRun);
        echo $stamp() . $result['reason'] . "\n";
        if ($dryRun && isset($result['text'])) echo "\nSubject: {$result['subject']}\n\n{$result['text']}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $stamp() . 'Digest failed: ' . $e->getMessage() . "\n");
        $failed = true;
    }
}

exit($failed ? 1 : 0);
