<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use Throwable;

/**
 * Read-only mirror of WPMGR sites into wpmgr_sites, linked to client_sites by
 * domain match. Unlike PloiSync, this never creates client_sites/domains rows
 * for an unmatched site — WPMGR sites usually already have a Ploi-synced
 * counterpart, so an unmatched site is left purely informational
 * (client_site_id NULL) for manual review on /settings/wpmgr.
 */
class WpmgrSync
{
    public function __construct(private PDO $db, private WpmgrService $wpmgr) {}

    public function fullSync(): array
    {
        set_time_limit(120);
        $logId = $this->logStart('full');
        $results = ['sites' => 0, 'errors' => []];

        try {
            $results['sites'] = $this->syncSites();
        } catch (Throwable $e) {
            $results['errors']['sites'] = $e->getMessage();
        }

        $hasErrors = !empty($results['errors']);
        $this->logComplete($logId, $hasErrors ? 'partial' : 'completed', $results['sites'], implode('; ', $results['errors']));
        $this->db->exec("UPDATE wpmgr_config SET last_sync_at = datetime('now') WHERE id = 1");

        return $results;
    }

    public function syncSites(): int
    {
        $logId = $this->logStart('sites');
        $count = 0;
        $seen  = [];
        $limit = 100;

        try {
            for ($offset = 0; ; $offset += $limit) {
                $sites = $this->wpmgr->listSites($limit, $offset);
                if (!$sites) break;

                foreach ($sites as $site) {
                    $wpmgrId = (string)($site['id'] ?? '');
                    if ($wpmgrId === '') continue;
                    $seen[] = $wpmgrId;

                    $url         = (string)($site['url'] ?? '');
                    $clientSiteId = $this->matchClientSite($url);

                    $this->db->prepare(
                        "INSERT INTO wpmgr_sites
                            (wpmgr_id, url, name, status, wp_version, php_version, health_status, connection_state,
                             multisite, active_theme, tags, agent_version, host_provider, updates_available,
                             last_backup_at, last_backup_status, up, uptime_pct, avg_latency_ms, tls_expires_at,
                             page_cache_enabled, object_cache_enabled, wpmgr_client_id, wpmgr_client_name,
                             enrolled_at, last_seen_at, client_site_id, is_stale, last_synced_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, datetime('now'))
                        ON CONFLICT(wpmgr_id) DO UPDATE SET
                            url                  = excluded.url,
                            name                 = excluded.name,
                            status               = excluded.status,
                            wp_version           = excluded.wp_version,
                            php_version          = excluded.php_version,
                            health_status        = excluded.health_status,
                            connection_state     = excluded.connection_state,
                            multisite            = excluded.multisite,
                            active_theme         = excluded.active_theme,
                            tags                 = excluded.tags,
                            agent_version        = excluded.agent_version,
                            host_provider        = excluded.host_provider,
                            updates_available    = excluded.updates_available,
                            last_backup_at       = excluded.last_backup_at,
                            last_backup_status   = excluded.last_backup_status,
                            up                   = excluded.up,
                            uptime_pct           = excluded.uptime_pct,
                            avg_latency_ms       = excluded.avg_latency_ms,
                            tls_expires_at       = excluded.tls_expires_at,
                            page_cache_enabled   = excluded.page_cache_enabled,
                            object_cache_enabled = excluded.object_cache_enabled,
                            wpmgr_client_id      = excluded.wpmgr_client_id,
                            wpmgr_client_name    = excluded.wpmgr_client_name,
                            enrolled_at          = excluded.enrolled_at,
                            last_seen_at         = excluded.last_seen_at,
                            client_site_id       = excluded.client_site_id,
                            is_stale             = 0,
                            last_synced_at       = excluded.last_synced_at"
                    )->execute([
                        $wpmgrId,
                        $url,
                        $site['name'] ?? null,
                        $site['status'] ?? null,
                        $site['wp_version'] ?? null,
                        $site['php_version'] ?? null,
                        $site['health_status'] ?? null,
                        $site['connection_state'] ?? null,
                        !empty($site['multisite']) ? 1 : 0,
                        $site['active_theme'] ?? null,
                        isset($site['tags']) ? json_encode($site['tags']) : null,
                        $site['agent_version'] ?? null,
                        $site['host_provider'] ?? null,
                        (int)($site['updates_available'] ?? 0),
                        $site['last_backup_at'] ?? null,
                        $site['last_backup_status'] ?? null,
                        isset($site['up']) ? (int)(bool)$site['up'] : null,
                        $site['uptime_pct'] ?? null,
                        $site['avg_latency_ms'] ?? null,
                        $site['tls_expires_at'] ?? null,
                        !empty($site['page_cache_enabled']) ? 1 : 0,
                        !empty($site['object_cache_enabled']) ? 1 : 0,
                        $site['client_id'] ?? null,
                        $site['client_name'] ?? null,
                        $site['enrolled_at'] ?? null,
                        $site['last_seen_at'] ?? null,
                        $clientSiteId,
                    ]);

                    $count++;
                }

                if (count($sites) < $limit) break;
            }

            $this->flagStale($seen);
            $this->logComplete($logId, 'completed', $count);
            return $count;
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', $count, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Domain match only — never auto-creates client_sites/domains rows.
     * Re-resolved on every sync (unlike Ploi, which locks a link in once
     * made) since WPMGR never owns/creates the CRM record. Deliberately
     * ignores this CRM's client_sites.status: a CRM-archived site can still
     * carry a WPMGR link (useful confirmation it's truly decommissioned),
     * and WPMGR's own connection_state ('archived' etc.) is unrelated and
     * purely informational here.
     */
    private function matchClientSite(string $url): ?int
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;
        $host = preg_replace('/^www\./i', '', strtolower(trim($host)));
        if (!$host) return null;

        try {
            $stmt = $this->db->prepare(
                "SELECT cs.id FROM client_sites cs
                 JOIN domains d ON d.id = cs.domain_id
                 WHERE LOWER(d.domain) = LOWER(?)
                 LIMIT 1"
            );
            $stmt->execute([$host]);
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function flagStale(array $seenWpmgrIds): void
    {
        if (!$seenWpmgrIds) return;
        $in = implode(',', array_fill(0, count($seenWpmgrIds), '?'));
        $this->db->prepare("UPDATE wpmgr_sites SET is_stale = 1 WHERE wpmgr_id NOT IN ($in)")->execute($seenWpmgrIds);
    }

    private function logStart(string $type): int
    {
        $this->db->prepare("INSERT INTO wpmgr_sync_log (sync_type, status, started_at) VALUES (?, 'running', datetime('now'))")->execute([$type]);
        return (int)$this->db->lastInsertId();
    }

    private function logComplete(int $id, string $status, int $count, ?string $error = null): void
    {
        $this->db->prepare("UPDATE wpmgr_sync_log SET status = ?, records_synced = ?, error_message = ?, completed_at = datetime('now') WHERE id = ?")
            ->execute([$status, $count, $error, $id]);
    }
}
