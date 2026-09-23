<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;

/**
 * Single cron entry point. `scripts/cron.php` runs every minute, asks which
 * jobs are due, and launches each in its own background process so a slow
 * Ploi sync never delays the minutely campaign worker. Each job holds its own
 * flock, so an overrunning job is simply skipped until it finishes.
 *
 * Jobs are the existing standalone scripts (still runnable by hand); this
 * class only schedules them and records the outcome in `job_runs`.
 *
 * Schedule: `every` = seconds between runs; `at` = daily at HH:MM (UTC).
 * `stale` = seconds without a successful run before the job is flagged.
 */
class JobRunner
{
    public const JOBS = [
        // Light, frequent jobs run whenever due.
        'email-campaigns' => ['label' => 'Email campaigns', 'script' => 'email-campaigns.php', 'every' => 60,   'stale' => 900,    'url' => '/email'],
        'notifications'   => ['label' => 'Digest & alerts', 'script' => 'notify.php',           'every' => 300,  'stale' => 3600,   'url' => '/settings#notifications'],
        // Heavy syncs are `serial`: they queue on one shared lock rather than
        // writing to SQLite at the same time (concurrent runs hit "database is locked").
        'uptime-kuma'     => ['label' => 'Uptime Kuma',     'script' => 'uptime-kuma-sync.php', 'every' => 300,  'stale' => 1800,   'url' => '/settings/uptime-kuma', 'serial' => true],
        'freeagent'       => ['label' => 'FreeAgent',       'script' => 'freeagent-sync.php',   'every' => 3600, 'stale' => 14400,  'url' => '/settings/freeagent',   'serial' => true],
        'ploi'            => ['label' => 'Ploi',            'script' => 'ploi-sync.php',        'every' => 3600, 'stale' => 14400,  'url' => '/settings/ploi',        'serial' => true],
        'wpmgr'           => ['label' => 'WPMGR',           'script' => 'wpmgr-sync.php',       'every' => 3600, 'stale' => 14400,  'url' => '/settings/wpmgr',       'serial' => true],
        'cloudflare'      => ['label' => 'Cloudflare',      'script' => 'cloudflare-sync.php',  'at' => '06:00', 'stale' => 108000, 'url' => '/settings/cloudflare', 'serial' => true],
        'exchange-rates'  => ['label' => 'Exchange rates',  'script' => 'exchange-rates-sync.php', 'at' => '07:00', 'stale' => 108000, 'url' => '/settings',         'serial' => true],
        'backup'          => ['label' => 'Database backup', 'script' => 'backup.php',           'at' => '02:15', 'stale' => 108000, 'url' => '/settings',             'serial' => true],
    ];

    private const KEEP_DAYS    = 14;
    private const OUTPUT_LIMIT = 4000;
    /** How long a serial job waits for the one in front before giving up. */
    private const SERIAL_WAIT  = 1200;

    public function __construct(private PDO $db) {}

    /** @return string[] names of jobs due now */
    public function dueJobs(?int $now = null): array
    {
        $now  = $now ?? time();
        $last = $this->lastStarts();
        $due  = [];
        foreach (self::JOBS as $name => $job) {
            $lastTs = isset($last[$name]) ? strtotime($last[$name] . ' UTC') : null;
            if (isset($job['every'])) {
                // 30s tolerance: cron fires on the minute, runs start a few seconds in.
                if ($lastTs === null || $now - $lastTs >= $job['every'] - 30) $due[] = $name;
            } else {
                $target = strtotime(gmdate('Y-m-d', $now) . ' ' . $job['at'] . ' UTC');
                if ($now >= $target && ($lastTs === null || $lastTs < $target)) $due[] = $name;
            }
        }
        return $due;
    }

    /** Launch a job in the background (detached from the dispatcher). */
    public function spawn(string $name): void
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BASE_PATH . '/scripts/cron.php')
             . ' --job=' . escapeshellarg($name) . ' > /dev/null 2>&1 &';
        exec($cmd);
    }

    /**
     * Run one job in the foreground, recording it in job_runs.
     * Returns null if another instance holds the job's lock.
     *
     * @return array{status:string, exit_code:int, output:string}|null
     */
    public function run(string $name): ?array
    {
        $job = self::JOBS[$name] ?? throw new \InvalidArgumentException("Unknown job: {$name}");

        $lock = fopen(DATA_PATH . "/cron-{$name}.lock", 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return null;

        // Serial jobs queue behind each other. The run is recorded only once the
        // queue lock is held, so a waiting job still counts as due and later
        // dispatches bounce off its own lock instead of piling up.
        $serial = null;
        if (!empty($job['serial'])) {
            $serial   = fopen(DATA_PATH . '/cron-serial.lock', 'c');
            $deadline = time() + self::SERIAL_WAIT;
            while (!flock($serial, LOCK_EX | LOCK_NB)) {
                if (time() >= $deadline) {
                    fclose($serial);
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    $this->db->prepare(
                        "INSERT INTO job_runs (job, finished_at, status, exit_code, output) VALUES (?, datetime('now'), 'failed', 75, ?)"
                    )->execute([$name, 'Gave up after waiting ' . intdiv(self::SERIAL_WAIT, 60) . ' min for another sync to finish']);
                    return ['status' => 'failed', 'exit_code' => 75, 'output' => 'Timed out waiting for the sync queue'];
                }
                sleep(5);
            }
        }

        try {
            $this->db->prepare("INSERT INTO job_runs (job) VALUES (?)")->execute([$name]);
            $runId = (int)$this->db->lastInsertId();

            $proc = proc_open(
                [PHP_BINARY, BASE_PATH . '/scripts/' . $job['script']],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
                BASE_PATH
            );
            if (!is_resource($proc)) {
                $output   = 'Could not start process';
                $exitCode = 127;
            } else {
                $output   = (string)stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $exitCode = proc_close($proc);
            }

            $status = $exitCode !== 0 ? 'failed' : (str_contains($output, '[skip]') ? 'skipped' : 'ok');
            $output = trim($output);
            if (strlen($output) > self::OUTPUT_LIMIT) {
                $output = '…' . substr($output, -self::OUTPUT_LIMIT);
            }

            $this->db->prepare(
                "UPDATE job_runs SET finished_at = datetime('now'), status = ?, exit_code = ?, output = ? WHERE id = ?"
            )->execute([$status, $exitCode, $output, $runId]);

            return ['status' => $status, 'exit_code' => $exitCode, 'output' => $output];
        } finally {
            if ($serial) { flock($serial, LOCK_UN); fclose($serial); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function prune(): void
    {
        $this->db->exec("DELETE FROM job_runs WHERE started_at < datetime('now', '-" . self::KEEP_DAYS . " days')");
        // A run whose process died mid-flight (OOM, reboot) never gets finished_at.
        $this->db->exec("UPDATE job_runs SET status = 'failed', output = COALESCE(output, 'Process ended without reporting (killed or crashed)')
                         WHERE status = 'running' AND started_at < datetime('now', '-2 hours')");
    }

    /**
     * Per-job health for the settings panel, digest and MCP.
     *
     * @return array<string, array{label:string, url:string, schedule:string, last_run:?array, last_ok_at:?string,
     *                              last_failure:?array, state:string}>
     *   state: ok | skipped | failed | stale | never | running
     */
    public function status(): array
    {
        $out = [];
        try {
            $rows = $this->db->query(
                "SELECT j.* FROM job_runs j
                 JOIN (SELECT job, MAX(id) AS id FROM job_runs GROUP BY job) m ON m.id = j.id"
            )->fetchAll(PDO::FETCH_ASSOC);
            $lastRun = array_column($rows, null, 'job');

            $lastOk = $this->db->query(
                "SELECT job, MAX(started_at) FROM job_runs WHERE status IN ('ok','skipped') GROUP BY job"
            )->fetchAll(PDO::FETCH_KEY_PAIR);

            $failRows = $this->db->query(
                "SELECT j.job, j.started_at, j.output FROM job_runs j
                 JOIN (SELECT job, MAX(id) AS id FROM job_runs WHERE status = 'failed' GROUP BY job) m ON m.id = j.id"
            )->fetchAll(PDO::FETCH_ASSOC);
            $lastFail = array_column($failRows, null, 'job');
        } catch (\Throwable) {
            $lastRun = $lastOk = $lastFail = []; // migration 037 not applied yet
        }

        foreach (self::JOBS as $name => $job) {
            $run   = $lastRun[$name] ?? null;
            $okAt  = $lastOk[$name] ?? null;
            $state = match (true) {
                $run === null                 => 'never',
                $run['status'] === 'running'  => 'running',
                $run['status'] === 'failed'   => 'failed',
                $okAt === null || time() - strtotime($okAt . ' UTC') > $job['stale'] => 'stale',
                default                       => $run['status'], // ok | skipped
            };
            $out[$name] = [
                'label'        => $job['label'],
                'url'          => $job['url'],
                'schedule'     => isset($job['every'])
                    ? ($job['every'] < 3600 ? 'every ' . intdiv($job['every'], 60) . ' min' : 'hourly')
                    : 'daily ' . $job['at'] . ' UTC',
                'last_run'     => $run,
                'last_ok_at'   => $okAt,
                'last_failure' => $lastFail[$name] ?? null,
                'state'        => $state,
            ];
        }
        return $out;
    }

    /** True once cron.php has recorded any run — i.e. the single cron line is installed. */
    public function isInstalled(): bool
    {
        try {
            return (bool)$this->db->query("SELECT 1 FROM job_runs LIMIT 1")->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, string> job => last started_at */
    private function lastStarts(): array
    {
        return $this->db->query("SELECT job, MAX(started_at) FROM job_runs GROUP BY job")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}
