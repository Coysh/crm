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
 * Two sources, in order of preference:
 *
 *  1. **Socket.IO** (when a username/password is configured). Gives Uptime
 *     Kuma's real monitor ids, its own uptime percentages, the paused/active
 *     flag, and heartbeats. This is the good path.
 *  2. **GET /metrics** (API key only). Prometheus text: no monitor id and no
 *     uptime percentage, so rows are keyed by name and uptime is computed from
 *     samples this sync takes. Kept because it needs only a scoped API key
 *     rather than the account password.
 *
 * Either way each run appends a sample to uptime_kuma_checks and folds it into
 * uptime_kuma_daily. Under Socket.IO those samples are only a fallback for
 * monitors Uptime Kuma has not reported an uptime for yet, but they also back
 * the 48-hour response-time chart, so they are always recorded.
 */
class UptimeKumaSync
{
    /** Consecutive syncs a monitor must be absent before it is stale. */
    private const STALE_AFTER_MISSES = 3;

    /** Days of raw per-sync samples to keep (the rollup carries the long history). */
    private const CHECK_RETENTION_DAYS = 7;

    /** Uptime Kuma reports uptime per period in hours. */
    private const PERIOD_24H = 24;
    private const PERIOD_30D = 720;

    public function __construct(private PDO $db, private UptimeKumaService $kuma) {}

    public function fullSync(): array
    {
        set_time_limit(180);
        $logId = $this->logStart('full');
        $results = ['monitors' => 0, 'source' => null, 'errors' => []];

        try {
            [$results['monitors'], $results['source']] = $this->syncMonitors();
        } catch (Throwable $e) {
            $results['errors']['monitors'] = $e->getMessage();
        }

        $hasErrors = !empty($results['errors']);
        $this->logComplete($logId, $hasErrors ? 'partial' : 'completed', $results['monitors'], implode('; ', $results['errors']));
        $this->db->exec("UPDATE uptime_kuma_config SET last_sync_at = datetime('now') WHERE id = 1");

        return $results;
    }

    /** @return array{0: int, 1: string} count and which source produced it */
    public function syncMonitors(): array
    {
        $logId  = $this->logStart('monitors');
        $count  = 0;
        $source = $this->kuma->canWrite() ? 'socket' : 'metrics';

        try {
            $monitors = $source === 'socket' ? $this->readViaSocket() : $this->readViaMetrics();

            // A restarted Uptime Kuma reports nothing until each monitor's first
            // beat. Writing that through would stale-flag the whole fleet and
            // record a bogus sample for every site, so refuse it outright.
            if (!$monitors) {
                throw new RuntimeException('Uptime Kuma returned no monitors — refusing to sync (is it restarting?).');
            }

            // datetime('now') is UTC in SQLite; gmdate keeps every row in this
            // batch stamped identically.
            $now = gmdate('Y-m-d H:i:s');

            $this->db->beginTransaction();
            try {
                $localIds = [];
                foreach ($monitors as $monitor) {
                    $localIds[] = $this->upsertMonitor($monitor, $now);
                    $count++;
                }

                $this->flagStale($localIds);
                $this->recordSamples($localIds, $now);
                $this->recomputeLocalUptime();
                $this->applyReportedUptime($monitors);
                $this->pruneChecks();
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->logComplete($logId, 'completed', $count);
            return [$count, $source];
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', $count, $e->getMessage());
            throw $e;
        }
    }

    // ── source: Socket.IO ────────────────────────────────────────────────

    /**
     * Uptime Kuma pushes everything unprompted after login, so this connects,
     * waits for monitorList, then keeps draining briefly to let the per-monitor
     * events (one uptime/avgPing/heartbeatList each) land.
     *
     * @return array<int, array<string, mixed>> keyed by Uptime Kuma monitor id
     */
    private function readViaSocket(): array
    {
        $socket = $this->kuma->openSession();

        try {
            $socket->collect(['monitorList'], 25);
            $list = $socket->received('monitorList');
            if (!is_array($list)) return [];

            // Nothing but monitorList has arrived yet — the per-monitor events
            // follow. Uptime is the last of them and fires twice per monitor
            // (the 24h and 720h periods), so that count is the signal that the
            // initial burst is complete. Waiting on heartbeatList instead would
            // return before any uptime figure landed.
            $socket->drainFor(20, 'uptime', count($list) * 2);

            $beats    = $this->indexByMonitor($socket->receivedAll('heartbeatList'));
            $pings    = $this->indexByMonitor($socket->receivedAll('avgPing'));
            $certs    = $this->indexByMonitor($socket->receivedAll('certInfo'));
            $uptimes  = $this->indexUptime($socket->receivedAll('uptime'));

            $monitors = [];
            foreach ($list as $m) {
                $kumaId = (int)($m['id'] ?? 0);
                if (!$kumaId) continue;

                $last = $this->lastBeat($beats[$kumaId] ?? null);

                $monitors[$kumaId] = [
                    'kuma_id'             => $kumaId,
                    'monitor_name'        => (string)($m['name'] ?? ('Monitor ' . $kumaId)),
                    'monitor_type'        => $m['type'] ?? null,
                    'monitor_url'         => $m['url'] ?? null,
                    'monitor_hostname'    => $m['hostname'] ?? null,
                    'monitor_port'        => isset($m['port']) ? (string)$m['port'] : null,
                    'active'              => !empty($m['active']) ? 1 : 0,
                    'status'              => $last['status'] ?? null,
                    'response_time_ms'    => $last['ping'] ?? ($pings[$kumaId] ?? null),
                    'cert_days_remaining' => $this->certDays($certs[$kumaId] ?? null),
                    'cert_is_valid'       => $this->certValid($certs[$kumaId] ?? null),
                    'uptime_24h'          => $uptimes[$kumaId][self::PERIOD_24H] ?? null,
                    'uptime_30d'          => $uptimes[$kumaId][self::PERIOD_30D] ?? null,
                ];
            }

            return $monitors;
        } finally {
            $socket->close();
        }
    }

    /**
     * Per-monitor events arrive as [monitorID, payload, …]. The id comes back as
     * a string over the wire.
     *
     * @return array<int, mixed>
     */
    private function indexByMonitor(array $events): array
    {
        $out = [];
        foreach ($events as $args) {
            if (!is_array($args) || !isset($args[0])) continue;
            $out[(int)$args[0]] = $args[1] ?? null;
        }
        return $out;
    }

    /**
     * uptime arrives as [monitorID, periodHours, fraction] — note the value is
     * 0..1, not a percentage.
     *
     * @return array<int, array<int, float>>
     */
    private function indexUptime(array $events): array
    {
        $out = [];
        foreach ($events as $args) {
            if (!is_array($args) || count($args) < 3) continue;
            $out[(int)$args[0]][(int)$args[1]] = round(((float)$args[2]) * 100, 2);
        }
        return $out;
    }

    private function lastBeat($beats): ?array
    {
        if (!is_array($beats) || !$beats) return null;
        $last = end($beats);
        if (!is_array($last)) return null;
        return [
            'status' => isset($last['status']) ? (int)$last['status'] : null,
            'ping'   => isset($last['ping']) && $last['ping'] !== null ? (int)$last['ping'] : null,
        ];
    }

    private function certDays($tlsInfoJson): ?int
    {
        $info = $this->decodeTls($tlsInfoJson);
        $days = $info['certInfo']['daysRemaining'] ?? null;
        return $days === null ? null : (int)$days;
    }

    private function certValid($tlsInfoJson): ?int
    {
        $info = $this->decodeTls($tlsInfoJson);
        return isset($info['valid']) ? (int)(bool)$info['valid'] : null;
    }

    private function decodeTls($tlsInfoJson): array
    {
        if (is_array($tlsInfoJson)) return $tlsInfoJson;
        if (!is_string($tlsInfoJson) || $tlsInfoJson === '') return [];
        $decoded = json_decode($tlsInfoJson, true);
        return is_array($decoded) ? $decoded : [];
    }

    // ── source: /metrics ─────────────────────────────────────────────────

    /**
     * Folds the four per-monitor gauges (which share a label set) into one row
     * each. No monitor id here, so rows are keyed by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function readViaMetrics(): array
    {
        $fields = [
            'monitor_status'              => 'status',
            'monitor_response_time'       => 'response_time_ms',
            'monitor_cert_days_remaining' => 'cert_days_remaining',
            'monitor_cert_is_valid'       => 'cert_is_valid',
        ];

        $monitors = [];

        foreach (PrometheusParser::parse($this->kuma->fetchMetrics()) as $sample) {
            $column = $fields[$sample['metric']] ?? null;
            if ($column === null) continue;

            $name = $sample['labels']['monitor_name'] ?? null;
            if ($name === null) continue;

            if (!isset($monitors[$name])) {
                $monitors[$name] = [
                    'kuma_id'             => null,
                    'monitor_name'        => $name,
                    'monitor_type'        => $sample['labels']['monitor_type'] ?? null,
                    'monitor_url'         => $sample['labels']['monitor_url'] ?? null,
                    'monitor_hostname'    => $sample['labels']['monitor_hostname'] ?? null,
                    'monitor_port'        => $sample['labels']['monitor_port'] ?? null,
                    // /metrics only lists running monitors, so anything present is active.
                    'active'              => 1,
                    'status'              => null,
                    'response_time_ms'    => null,
                    'cert_days_remaining' => null,
                    'cert_is_valid'       => null,
                    'uptime_24h'          => null,
                    'uptime_30d'          => null,
                ];
            }

            $monitors[$name][$column] = $sample['value'] === null ? null : (int)round($sample['value']);
        }

        return $monitors;
    }

    // ── writing ──────────────────────────────────────────────────────────

    /**
     * Insert or update one monitor and return its local row id.
     *
     * Resolution order matters. A row is found by kuma_id when we have one;
     * failing that, by name among rows with no kuma_id yet — that is what lets
     * the first Socket.IO sync adopt rows the /metrics sync created, instead of
     * duplicating the whole fleet.
     */
    private function upsertMonitor(array $monitor, string $now): int
    {
        $clientSiteId = DomainMatcher::findClientSiteByHost($this->db, $this->matchHost($monitor));
        $existing     = $this->findExisting($monitor);

        if ($existing) {
            // status_changed_at must only move on a real transition, so the site
            // page can say "down for 2h" rather than "down for 5 minutes".
            $changed = ((int)$existing['status'] !== (int)$monitor['status'])
                || ($existing['status'] === null) !== ($monitor['status'] === null);

            $this->db->prepare(
                "UPDATE uptime_kuma_monitors SET
                    kuma_id             = COALESCE(?, kuma_id),
                    monitor_name        = ?,
                    monitor_type        = ?,
                    monitor_url         = ?,
                    monitor_hostname    = ?,
                    monitor_port        = ?,
                    active              = ?,
                    status              = ?,
                    status_changed_at   = CASE WHEN ? THEN ? ELSE status_changed_at END,
                    response_time_ms    = ?,
                    -- keep the last known certificate figures when this run
                    -- didn't carry any; certInfo is only pushed on a TLS check
                    cert_days_remaining = COALESCE(?, cert_days_remaining),
                    cert_is_valid       = COALESCE(?, cert_is_valid),
                    client_site_id      = CASE WHEN link_is_manual = 1 THEN client_site_id ELSE ? END,
                    missed_syncs        = 0,
                    is_stale            = 0,
                    last_synced_at      = ?
                 WHERE id = ?"
            )->execute([
                $monitor['kuma_id'],
                $monitor['monitor_name'],
                $monitor['monitor_type'],
                $monitor['monitor_url'],
                $monitor['monitor_hostname'],
                $monitor['monitor_port'],
                $monitor['active'],
                $monitor['status'],
                $changed ? 1 : 0,
                $now,
                $monitor['response_time_ms'],
                $monitor['cert_days_remaining'],
                $monitor['cert_is_valid'],
                $clientSiteId,
                $now,
                $existing['id'],
            ]);

            return (int)$existing['id'];
        }

        $this->db->prepare(
            "INSERT INTO uptime_kuma_monitors
                (kuma_id, monitor_name, monitor_type, monitor_url, monitor_hostname, monitor_port,
                 active, status, status_changed_at, response_time_ms, cert_days_remaining, cert_is_valid,
                 client_site_id, link_is_manual, missed_syncs, is_stale, first_seen_at, last_synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?)"
        )->execute([
            $monitor['kuma_id'],
            $monitor['monitor_name'],
            $monitor['monitor_type'],
            $monitor['monitor_url'],
            $monitor['monitor_hostname'],
            $monitor['monitor_port'],
            $monitor['active'],
            $monitor['status'],
            $now,
            $monitor['response_time_ms'],
            $monitor['cert_days_remaining'],
            $monitor['cert_is_valid'],
            $clientSiteId,
            $now,
            $now,
        ]);

        return (int)$this->db->lastInsertId();
    }

    private function findExisting(array $monitor): ?array
    {
        if (!empty($monitor['kuma_id'])) {
            $stmt = $this->db->prepare("SELECT id, status FROM uptime_kuma_monitors WHERE kuma_id = ? LIMIT 1");
            $stmt->execute([$monitor['kuma_id']]);
            if ($row = $stmt->fetch()) return $row;
        }

        // Adoption: a row the /metrics sync created, keyed only by name.
        $stmt = $this->db->prepare(
            "SELECT id, status FROM uptime_kuma_monitors WHERE monitor_name = ? AND kuma_id IS NULL LIMIT 1"
        );
        $stmt->execute([$monitor['monitor_name']]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Which label carries the thing to match on: URL-based monitor types expose
     * a url, connectivity ones (ping/port/dns) only a hostname.
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
     * rollup — a planned maintenance window shouldn't read as downtime. Paused
     * monitors are skipped entirely: a paused monitor isn't up or down.
     *
     * @param int[] $localIds
     */
    private function recordSamples(array $localIds, string $now): void
    {
        if (!$localIds) return;

        $in   = implode(',', array_fill(0, count($localIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT id, status, response_time_ms, active FROM uptime_kuma_monitors WHERE id IN ($in)"
        );
        $stmt->execute($localIds);
        $rows = $stmt->fetchAll();

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
            if (isset($row['active']) && (int)$row['active'] === 0) continue;

            $status = $row['status'] === null ? null : (int)$row['status'];
            $insertCheck->execute([$row['id'], $status, $row['response_time_ms'], $now]);

            if ($status === 0 || $status === 1) {
                $upsertDaily->execute([$row['id'], $day, $status === 1 ? 1 : 0, $row['response_time_ms']]);
            }
        }
    }

    /**
     * Monitors absent from this run get a strike rather than an immediate stale
     * flag. Under /metrics a paused monitor simply disappears, and so does the
     * whole fleet for a moment after Uptime Kuma restarts; several consecutive
     * misses tell the two apart.
     *
     * @param int[] $localIds
     */
    private function flagStale(array $localIds): void
    {
        if (!$localIds) return;
        $in = implode(',', array_fill(0, count($localIds), '?'));
        $this->db->prepare(
            "UPDATE uptime_kuma_monitors
             SET missed_syncs = missed_syncs + 1,
                 is_stale     = CASE WHEN missed_syncs + 1 >= " . self::STALE_AFTER_MISSES . " THEN 1 ELSE is_stale END
             WHERE id NOT IN ($in)"
        )->execute($localIds);
    }

    /**
     * Uptime from our own samples: 24h from the raw checks, 30d from the daily
     * rollup. Always computed, then overwritten by Uptime Kuma's own figures
     * where it reported them (see applyReportedUptime).
     */
    private function recomputeLocalUptime(): void
    {
        $this->db->exec(
            "UPDATE uptime_kuma_monitors SET uptime_24h = (
                SELECT CASE WHEN COUNT(*) = 0 THEN NULL
                            ELSE ROUND(100.0 * SUM(CASE WHEN c.status = 1 THEN 1 ELSE 0 END) / COUNT(*), 2) END
                FROM uptime_kuma_checks c
                WHERE c.monitor_id = uptime_kuma_monitors.id
                  AND c.status IN (0, 1)
                  AND c.checked_at >= datetime('now', '-24 hours')
            ), uptime_is_local = 1"
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

    /**
     * Prefer Uptime Kuma's own uptime, which covers the monitor's whole history
     * rather than just the period since this CRM started sampling.
     *
     * @param array<int|string, array<string, mixed>> $monitors
     */
    private function applyReportedUptime(array $monitors): void
    {
        $stmt = $this->db->prepare(
            "UPDATE uptime_kuma_monitors
             SET uptime_24h = COALESCE(?, uptime_24h),
                 uptime_30d = COALESCE(?, uptime_30d),
                 uptime_is_local = 0
             WHERE kuma_id = ?"
        );

        foreach ($monitors as $monitor) {
            if (empty($monitor['kuma_id'])) continue;
            if ($monitor['uptime_24h'] === null && $monitor['uptime_30d'] === null) continue;
            $stmt->execute([$monitor['uptime_24h'], $monitor['uptime_30d'], $monitor['kuma_id']]);
        }
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
