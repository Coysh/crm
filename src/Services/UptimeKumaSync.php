<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Read-only mirror of Uptime Kuma monitors into uptime_kuma_monitors, linked to
 * client_sites by domain match. Like WpmgrSync it never creates client_sites or
 * domains rows — an unmatched monitor stays informational and can be hand-linked
 * on /settings/uptime-kuma.
 *
 * Two things differ from every other integration here, both forced by /metrics
 * being the only authenticated REST surface Uptime Kuma offers:
 *
 *  1. **monitor_name is the sync key.** /metrics carries no monitor id, so a
 *     rename in Uptime Kuma reads as "old monitor gone, new monitor appeared":
 *     the old row goes stale and any manual link on it is lost. Visible on the
 *     settings page and re-linkable by hand.
 *  2. **Uptime is computed here, not read.** /metrics reports only the current
 *     status, so each sync appends a sample to uptime_kuma_checks and rolls it
 *     into uptime_kuma_daily. Figures therefore reflect the cron cadence, and
 *     only start accruing from the day the integration is switched on.
 */
class UptimeKumaSync
{
    /** Consecutive syncs a monitor must be absent from /metrics before it is stale. */
    private const STALE_AFTER_MISSES = 3;

    /** Days of raw per-sync samples to keep (the rollup carries the long history). */
    private const CHECK_RETENTION_DAYS = 7;

    public function __construct(private PDO $db, private UptimeKumaService $kuma) {}

    public function fullSync(): array
    {
        set_time_limit(120);
        $logId = $this->logStart('full');
        $results = ['monitors' => 0, 'errors' => []];

        try {
            $results['monitors'] = $this->syncMonitors();
        } catch (Throwable $e) {
            $results['errors']['monitors'] = $e->getMessage();
        }

        $hasErrors = !empty($results['errors']);
        $this->logComplete($logId, $hasErrors ? 'partial' : 'completed', $results['monitors'], implode('; ', $results['errors']));
        $this->db->exec("UPDATE uptime_kuma_config SET last_sync_at = datetime('now') WHERE id = 1");

        return $results;
    }

    public function syncMonitors(): int
    {
        $logId = $this->logStart('monitors');
        $count = 0;

        try {
            $monitors = $this->collectMonitors($this->kuma->fetchMetrics());

            // A restarted Uptime Kuma serves an empty registry until each monitor's
            // first beat. Writing that through would stale-flag the whole fleet and
            // record a bogus sample for every site, so refuse it outright.
            if (!$monitors) {
                throw new RuntimeException('Uptime Kuma returned no monitors — refusing to sync (is it restarting?).');
            }

            // datetime('now') is UTC in SQLite; gmdate keeps every row in this
            // batch stamped identically.
            $now = gmdate('Y-m-d H:i:s');

            $this->db->beginTransaction();
            try {
                foreach ($monitors as $monitor) {
                    $this->upsertMonitor($monitor, $now);
                    $count++;
                }

                $this->flagStale(array_keys($monitors));
                $this->recordSamples(array_keys($monitors), $now);
                $this->recomputeUptime();
                $this->pruneChecks();
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->logComplete($logId, 'completed', $count);
            return $count;
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', $count, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Folds the four per-monitor gauges (which share a label set) into one row
     * each, keyed by monitor_name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function collectMonitors(string $metrics): array
    {
        $fields = [
            'monitor_status'              => 'status',
            'monitor_response_time'       => 'response_time_ms',
            'monitor_cert_days_remaining' => 'cert_days_remaining',
            'monitor_cert_is_valid'       => 'cert_is_valid',
        ];

        $monitors = [];

        foreach (PrometheusParser::parse($metrics) as $sample) {
            $column = $fields[$sample['metric']] ?? null;
            if ($column === null) continue;

            $name = $sample['labels']['monitor_name'] ?? null;
            if ($name === null) continue;

            if (!isset($monitors[$name])) {
                $monitors[$name] = [
                    'monitor_name'        => $name,
                    'monitor_type'        => $sample['labels']['monitor_type'] ?? null,
                    'monitor_url'         => $sample['labels']['monitor_url'] ?? null,
                    'monitor_hostname'    => $sample['labels']['monitor_hostname'] ?? null,
                    'monitor_port'        => $sample['labels']['monitor_port'] ?? null,
                    'status'              => null,
                    'response_time_ms'    => null,
                    'cert_days_remaining' => null,
                    'cert_is_valid'       => null,
                ];
            }

            $monitors[$name][$column] = $sample['value'] === null ? null : (int)round($sample['value']);
        }

        return $monitors;
    }

    private function upsertMonitor(array $monitor, string $now): void
    {
        $clientSiteId = DomainMatcher::findClientSiteByHost($this->db, $this->matchHost($monitor));

        $this->db->prepare(
            "INSERT INTO uptime_kuma_monitors
                (monitor_name, monitor_type, monitor_url, monitor_hostname, monitor_port,
                 status, status_changed_at, response_time_ms, cert_days_remaining, cert_is_valid,
                 client_site_id, link_is_manual, missed_syncs, is_stale, first_seen_at, last_synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)
            ON CONFLICT(monitor_name) DO UPDATE SET
                monitor_type        = excluded.monitor_type,
                monitor_url         = excluded.monitor_url,
                monitor_hostname    = excluded.monitor_hostname,
                monitor_port        = excluded.monitor_port,
                status              = excluded.status,
                -- only moves when the status actually differs, so the site page can
                -- say 'down for 2h' rather than 'down for 5 minutes' forever
                status_changed_at   = CASE
                    WHEN uptime_kuma_monitors.status IS NOT excluded.status
                    THEN excluded.status_changed_at
                    ELSE uptime_kuma_monitors.status_changed_at END,
                response_time_ms    = excluded.response_time_ms,
                cert_days_remaining = excluded.cert_days_remaining,
                cert_is_valid       = excluded.cert_is_valid,
                -- a hand-made link must survive re-syncs; an automatic one is
                -- re-resolved every time so a domain edit takes effect
                client_site_id      = CASE
                    WHEN uptime_kuma_monitors.link_is_manual = 1
                    THEN uptime_kuma_monitors.client_site_id
                    ELSE excluded.client_site_id END,
                missed_syncs        = 0,
                is_stale            = 0,
                last_synced_at      = excluded.last_synced_at"
        )->execute([
            $monitor['monitor_name'],
            $monitor['monitor_type'],
            $monitor['monitor_url'],
            $monitor['monitor_hostname'],
            $monitor['monitor_port'],
            $monitor['status'],
            $now,
            $monitor['response_time_ms'],
            $monitor['cert_days_remaining'],
            $monitor['cert_is_valid'],
            $clientSiteId,
            $now,
            $now,
        ]);
    }

    /**
     * Which label carries the thing to match on: URL-based monitor types expose
     * monitor_url, connectivity ones (ping/port/dns) only monitor_hostname.
     */
    private function matchHost(array $monitor): string
    {
        $candidates = in_array($monitor['monitor_type'], ['ping', 'port', 'dns'], true)
            ? [$monitor['monitor_hostname'], $monitor['monitor_url']]
            : [$monitor['monitor_url'], $monitor['monitor_hostname']];

        foreach ($candidates as $candidate) {
            if (!empty($candidate)) {
                $host = DomainMatcher::hostFromUrl((string)$candidate);
                if ($host !== '') return $host;
            }
        }

        return '';
    }

    /**
     * Appends one raw sample per monitor and folds it into the daily rollup.
     * Pending (2) and maintenance (3) samples are recorded but excluded from the
     * rollup — a planned maintenance window shouldn't read as downtime.
     */
    private function recordSamples(array $names, string $now): void
    {
        $rows = $this->monitorRows($names);

        $insertCheck = $this->db->prepare(
            "INSERT INTO uptime_kuma_checks (monitor_id, status, response_time_ms, checked_at) VALUES (?, ?, ?, ?)"
        );

        $upsertDaily = $this->db->prepare(
            "INSERT INTO uptime_kuma_daily (monitor_id, day, up_checks, total_checks, response_time_ms)
            VALUES (?, ?, ?, 1, ?)
            ON CONFLICT(monitor_id, day) DO UPDATE SET
                up_checks        = uptime_kuma_daily.up_checks + excluded.up_checks,
                total_checks     = uptime_kuma_daily.total_checks + 1,
                response_time_ms = CASE
                    WHEN excluded.response_time_ms IS NULL THEN uptime_kuma_daily.response_time_ms
                    WHEN uptime_kuma_daily.response_time_ms IS NULL THEN excluded.response_time_ms
                    ELSE CAST(
                        (uptime_kuma_daily.response_time_ms * uptime_kuma_daily.total_checks + excluded.response_time_ms)
                        / (uptime_kuma_daily.total_checks + 1) AS INTEGER)
                    END"
        );

        $day = substr($now, 0, 10);

        foreach ($rows as $row) {
            $status = $row['status'] === null ? null : (int)$row['status'];
            $insertCheck->execute([$row['id'], $status, $row['response_time_ms'], $now]);

            if ($status === 0 || $status === 1) {
                $upsertDaily->execute([$row['id'], $day, $status === 1 ? 1 : 0, $row['response_time_ms']]);
            }
        }
    }

    /** @return array<int, array{id:int, status:?int, response_time_ms:?int}> */
    private function monitorRows(array $names): array
    {
        if (!$names) return [];
        $in = implode(',', array_fill(0, count($names), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, status, response_time_ms FROM uptime_kuma_monitors WHERE monitor_name IN ($in)"
        );
        $stmt->execute($names);
        return $stmt->fetchAll();
    }

    /**
     * Paused monitors legitimately disappear from /metrics — but so does the whole
     * fleet for a moment after Uptime Kuma restarts. Requiring several consecutive
     * misses tells the two apart.
     */
    private function flagStale(array $seenNames): void
    {
        if (!$seenNames) return;
        $in = implode(',', array_fill(0, count($seenNames), '?'));
        $this->db->prepare(
            "UPDATE uptime_kuma_monitors
             SET missed_syncs = missed_syncs + 1,
                 is_stale     = CASE WHEN missed_syncs + 1 >= " . self::STALE_AFTER_MISSES . " THEN 1 ELSE is_stale END
             WHERE monitor_name NOT IN ($in)"
        )->execute($seenNames);
    }

    /**
     * Two grouped statements, never per-row: 24h from the raw samples, 30d from
     * the daily rollup (which is why raw samples only need a week's retention).
     */
    private function recomputeUptime(): void
    {
        $this->db->exec(
            "UPDATE uptime_kuma_monitors SET uptime_24h = (
                SELECT CASE WHEN COUNT(*) = 0 THEN NULL
                            ELSE ROUND(100.0 * SUM(CASE WHEN c.status = 1 THEN 1 ELSE 0 END) / COUNT(*), 2) END
                FROM uptime_kuma_checks c
                WHERE c.monitor_id = uptime_kuma_monitors.id
                  AND c.status IN (0, 1)
                  AND c.checked_at >= datetime('now', '-24 hours')
            )"
        );

        $this->db->exec(
            "UPDATE uptime_kuma_monitors SET uptime_30d = (
                SELECT CASE WHEN COALESCE(SUM(d.total_checks), 0) = 0 THEN NULL
                            ELSE ROUND(100.0 * SUM(d.up_checks) / SUM(d.total_checks), 2) END
                FROM uptime_kuma_daily d
                WHERE d.monitor_id = uptime_kuma_monitors.id
                  AND d.day >= date('now', '-30 days')
            )"
        );
    }

    private function pruneChecks(): void
    {
        $this->db->exec(
            "DELETE FROM uptime_kuma_checks WHERE checked_at < datetime('now', '-" . self::CHECK_RETENTION_DAYS . " days')"
        );
    }

    private function logStart(string $type): int
    {
        $this->db->prepare("INSERT INTO uptime_kuma_sync_log (sync_type, status, started_at) VALUES (?, 'running', datetime('now'))")->execute([$type]);
        return (int)$this->db->lastInsertId();
    }

    private function logComplete(int $id, string $status, int $count, ?string $error = null): void
    {
        $this->db->prepare("UPDATE uptime_kuma_sync_log SET status = ?, records_synced = ?, error_message = ?, completed_at = datetime('now') WHERE id = ?")
            ->execute([$status, $count, $error, $id]);
    }
}
