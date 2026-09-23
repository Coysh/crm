#!/usr/bin/env php
<?php

// The only cron line this app needs:
//   * * * * * cd /path/to/coysh-crm && php scripts/cron.php >> data/cron.log 2>&1
//
// Every minute it launches whichever jobs are due (see Services\JobRunner::JOBS)
// as background processes and records each outcome in job_runs.
//
//   php scripts/cron.php               dispatch due jobs in the background
//   php scripts/cron.php --foreground  run due jobs one after another (debugging)
//   php scripts/cron.php --job=ploi    run one job now, whether due or not
//   php scripts/cron.php --status      print each job's health

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use CoyshCRM\Services\JobRunner;

$opts   = getopt('', ['job:', 'foreground', 'status']);
$runner = new JobRunner($db);

if (isset($opts['status'])) {
    foreach ($runner->status() as $name => $s) {
        printf("%-16s %-8s %-18s last ok: %s\n", $name, $s['state'], $s['schedule'], $s['last_ok_at'] ?? '—');
    }
    exit(0);
}

if (isset($opts['job'])) {
    $name = (string)$opts['job'];
    if (!isset(JobRunner::JOBS[$name])) {
        fwrite(STDERR, "Unknown job '{$name}'. Jobs: " . implode(', ', array_keys(JobRunner::JOBS)) . "\n");
        exit(2);
    }
    $result = $runner->run($name);
    if ($result === null) {
        echo '[' . date('Y-m-d H:i:s') . "] {$name}: already running, skipped.\n";
        exit(0);
    }
    echo '[' . date('Y-m-d H:i:s') . "] {$name}: {$result['status']}\n";
    exit($result['status'] === 'failed' ? 1 : 0);
}

try {
    $runner->prune();
    $due = $runner->dueJobs();
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] cron: ' . $e->getMessage() . " (run php scripts/migrate.php?)\n");
    exit(1);
}

foreach ($due as $name) {
    if (isset($opts['foreground'])) {
        $result = $runner->run($name);
        echo '[' . date('Y-m-d H:i:s') . "] {$name}: " . ($result['status'] ?? 'locked') . "\n";
    } else {
        $runner->spawn($name);
    }
}
