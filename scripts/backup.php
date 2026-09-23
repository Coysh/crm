#!/usr/bin/env php
<?php

// Nightly snapshot of the SQLite database plus the encryption key.
// Without app.key every encrypted token in a backup is unrecoverable, so the
// two are always copied together. Keeps the newest N snapshots (default 14).
//
// Suggested cron (or let scripts/cron.php run it):
//   15 2 * * * php /path/to/coysh-crm/scripts/backup.php
//
//   php scripts/backup.php [--keep=14] [--dir=/path/to/backups]

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use CoyshCRM\Services\Backup;

$opts = getopt('', ['keep::', 'dir::']);
$keep = isset($opts['keep']) ? max(1, (int)$opts['keep']) : 14;
$dir  = isset($opts['dir']) && $opts['dir'] !== '' ? (string)$opts['dir'] : null;

try {
    $result = (new Backup($db, $dir))->run($keep);
    echo '[' . date('Y-m-d H:i:s') . "] Backup written: {$result['path']} ("
        . number_format($result['bytes'] / 1024, 0) . " KB, {$result['pruned']} old snapshot(s) pruned)\n";
    if (!$result['key_copied']) {
        echo "  [warn] No data/app.key found — APP_KEY is set in the environment; back that up separately.\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] Backup failed: ' . $e->getMessage() . "\n");
    exit(1);
}
