<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use Throwable;

class PloiSync
{
    public function __construct(private PDO $db, private PloiService $ploiService) {}

    public function fullSync(): array
    {
        set_time_limit(120);
        $logId = $this->logStart('full');
        $results = ['servers' => 0, 'sites' => 0, 'errors' => []];

        try {
            $results['servers'] = $this->syncServers();
        } catch (Throwable $e) {
            $results['errors']['servers'] = $e->getMessage();
        }
        try {
            $results['sites'] = $this->syncSites();
        } catch (Throwable $e) {
            $results['errors']['sites'] = $e->getMessage();
        }
        $total = $results['servers'] + $results['sites'];
        $hasErrors = !empty($results['errors']);
        $this->logComplete($logId, $hasErrors ? 'partial' : 'completed', $total, implode('; ', $results['errors']));
        $this->db->exec("UPDATE ploi_config SET last_sync_at = datetime('now') WHERE id = 1");

        return $results;
    }

    public function syncServers(): int
    {
        $logId = $this->logStart('servers');
        $seen = [];
        $count = 0;

        try {
            $ploi = $this->ploiService->sdk();
            for ($page = 1; $page <= 100; $page++) {
                $resp = $ploi->servers()->perPage(50)->page($page);
                $servers = $this->toArray($resp->getData());
                if (!$servers) break;

                foreach ($servers as $server) {
                    $sid = (int)($server['id'] ?? 0);
                    if (!$sid) continue;
                    $seen[] = $sid;

                    $detail = $ploi->servers($sid)->get();
                    $d = $this->toArray($detail->getData());

                    $phpVersions = array_values(array_filter(array_map(
                        fn($v) => is_array($v) ? ($v['version'] ?? ($v['name'] ?? null)) : $v,
                        $d['installed_php_versions'] ?? ($d['php_versions'] ?? [])
                    )));

                    $provider = is_array($d['provider'] ?? null)
                        ? ($d['provider']['name'] ?? null)
                        : ($d['provider'] ?? null);

                    $region = is_array($d['region'] ?? null)
                        ? ($d['region']['name'] ?? null)
                        : ($d['region'] ?? null);

                    $name        = $d['name'] ?? 'Unknown';
                    $ipAddress   = $d['ip_address'] ?? ($d['ip'] ?? null);
                    $phpCli      = $d['php_cli_version'] ?? ($d['php_version'] ?? null);
                    $statusVal   = $d['status'] ?? null;

                    // OS info — Ploi returns various field names depending on provider
                    $osRaw     = $d['operating_system'] ?? ($d['os'] ?? ($d['type'] ?? null));
                    $osVersion = $d['os_version'] ?? ($d['ubuntu_version'] ?? null);
                    // If os is a compound string like "Ubuntu 22.04", split it
                    if ($osRaw && !$osVersion && preg_match('/^(\D+?)\s+([\d.]+)$/i', trim($osRaw), $m)) {
                        $osRaw     = trim($m[1]);
                        $osVersion = trim($m[2]);
                    }

                    $this->db->prepare(
                        "INSERT INTO ploi_servers
                            (ploi_id, name, ip_address, provider, region, status, php_versions, php_cli_version, os_name, os_version, is_stale, last_synced_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, datetime('now'))
                        ON CONFLICT(ploi_id) DO UPDATE SET
                            name            = excluded.name,
                            ip_address      = excluded.ip_address,
                            provider        = excluded.provider,
                            region          = excluded.region,
                            status          = excluded.status,
                            php_versions    = excluded.php_versions,
                            php_cli_version = excluded.php_cli_version,
                            os_name         = excluded.os_name,
                            os_version      = excluded.os_version,
                            is_stale        = 0,
                            last_synced_at  = excluded.last_synced_at"
                    )->execute([
                        $sid, $name, $ipAddress, $provider, $region,
                        $statusVal, json_encode($phpVersions), $phpCli, $osRaw, $osVersion,
                    ]);

                    // Auto-create a servers record if not already linked
                    $row = $this->db->prepare("SELECT server_id FROM ploi_servers WHERE ploi_id = ?");
                    $row->execute([$sid]);
                    $existing = $row->fetchColumn();

                    if (!$existing) {
                        $this->db->prepare(
                            "INSERT INTO servers (name, provider, monthly_cost, notes)
                             VALUES (?, ?, 0, ?)"
                        )->execute([$name, $provider, 'Imported from Ploi (IP: ' . $ipAddress . ')']);
                        $newServerId = (int)$this->db->lastInsertId();
                        $this->db->prepare(
                            "UPDATE ploi_servers SET server_id = ? WHERE ploi_id = ?"
                        )->execute([$newServerId, $sid]);
                    }

                    $count++;
                }
            }

            $this->flagStale('ploi_servers', $seen);
            $this->logComplete($logId, 'completed', $count);
            return $count;
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', $count, $e->getMessage());
            throw $e;
        }
    }

    public function syncSites(): int
    {
        $logId = $this->logStart('sites');
        $count = 0;
        $seen = [];

        // Pre-fetch full exclusion list to avoid per-site queries
        $excludedPloiIds = [];
        try {
            $excludedPloiIds = $this->db->query("SELECT ploi_site_id FROM ploi_sync_exclusions")->fetchAll(\PDO::FETCH_COLUMN);
            $excludedPloiIds = array_map('intval', $excludedPloiIds);
        } catch (\Throwable) {}

        try {
            $ploi = $this->ploiService->sdk();
            $servers = $this->db->query('SELECT id, ploi_id FROM ploi_servers')->fetchAll();

            foreach ($servers as $server) {
                $sid = (int)$server['ploi_id'];
                try {
                for ($page = 1; $page <= 100; $page++) {
                    $resp = $ploi->servers($sid)->sites()->perPage(50)->page($page);
                    $sites = $this->toArray($resp->getData());
                    if (!$sites) break;

                    foreach ($sites as $site) {
                        $siteId = (int)($site['id'] ?? 0);
                        if (!$siteId) continue;
                        $seen[] = $siteId;

                        // Skip excluded sites
                        if (in_array($siteId, $excludedPloiIds)) continue;

                        // Use listing data only — no per-site API calls to avoid timeout
                        $repo   = null;
                        $branch = null;
                        // has_repository flag in listing; actual repo detail synced lazily
                        // (the ploi_sites.repository column updated when viewing a site)

                        // SSL: not available in listing — default 0, updated on next detail sync
                        $hasSsl = 0;

                        $phpVersion  = is_array($site['php_version'] ?? null)  ? ($site['php_version']['version'] ?? null) : ($site['php_version'] ?? null);
                        $projectType = is_array($site['project_type'] ?? null) ? ($site['project_type']['name'] ?? null)   : ($site['project_type'] ?? null);
                        $status      = is_array($site['status'] ?? null)       ? ($site['status']['name'] ?? null)         : ($site['status'] ?? null);

                        $this->db->prepare(
                            "INSERT INTO ploi_sites
                                (ploi_id, ploi_server_id, domain, project_type, php_version, web_directory, project_root, repository, branch, has_ssl, test_domain, status, is_stale, last_synced_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, datetime('now'))
                            ON CONFLICT(ploi_id) DO UPDATE SET
                                ploi_server_id = excluded.ploi_server_id,
                                domain         = excluded.domain,
                                project_type   = excluded.project_type,
                                php_version    = excluded.php_version,
                                web_directory  = excluded.web_directory,
                                project_root   = excluded.project_root,
                                repository     = excluded.repository,
                                branch         = excluded.branch,
                                has_ssl        = excluded.has_ssl,
                                test_domain    = excluded.test_domain,
                                status         = excluded.status,
                                is_stale       = 0,
                                last_synced_at = excluded.last_synced_at"
                        )->execute([
                            $siteId,
                            $server['id'],
                            $site['domain'] ?? ($site['name'] ?? 'unknown'),
                            $projectType,
                            $phpVersion,
                            $site['web_directory'] ?? null,
                            $site['project_root'] ?? null,
                            $repo,
                            $branch,
                            $hasSsl,
                            $site['test_domain'] ?? null,
                            $status,
                        ]);

                        // Auto-create a client_sites record if not already linked
                        $cs = $this->db->prepare("SELECT client_site_id FROM ploi_sites WHERE ploi_id = ?");
                        $cs->execute([$siteId]);
                        $existingCsId = $cs->fetchColumn();

                        $domain = $site['domain'] ?? ($site['name'] ?? 'unknown');

                        if (!$existingCsId) {
                            // Resolve the linked server_id from the ploi_server row
                            $srvRow = $this->db->prepare("SELECT server_id FROM ploi_servers WHERE id = ?");
                            $srvRow->execute([$server['id']]);
                            $linkedServerId = $srvRow->fetchColumn() ?: null;

                            $this->db->prepare(
                                "INSERT INTO client_sites
                                    (client_id, server_id, website_stack, git_repo, notes, created_at)
                                VALUES (NULL, ?, ?, ?, ?, datetime('now'))"
                            )->execute([
                                $linkedServerId,
                                $projectType,
                                $repo,
                                'Imported from Ploi: ' . $domain,
                            ]);
                            $newCsId = (int)$this->db->lastInsertId();
                            $this->db->prepare(
                                "UPDATE ploi_sites SET client_site_id = ? WHERE ploi_id = ?"
                            )->execute([$newCsId, $siteId]);
                            $existingCsId = $newCsId;
                        }

                        // Auto-create or link domain record for this site
                        if ($existingCsId) {
                            $this->ensureDomainForSite($domain, (int)$existingCsId, null);
                        }

                        $count++;
                    }
                }
                } catch (Throwable $serverEx) {
                    // Rate limit or error for this server — skip it, continue with others
                    $this->logComplete($this->logStart('sites_server_' . $sid), 'failed', 0, $serverEx->getMessage());
                }
            }

            $this->flagStale('ploi_sites', $seen);
            $this->logComplete($logId, 'completed', $count);
            return $count;
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', $count, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Re-process all existing ploi_sites to create/link missing domain records.
     * Safe to run multiple times — skips sites that already have domain_id set.
     */
    public function syncDomains(): int
    {
        $logId = $this->logStart('domains');
        $count = 0;
        try {
            $rows = $this->db->query("
                SELECT ps.domain, cs.id AS cs_id, cs.client_id, cs.domain_id
                FROM ploi_sites ps
                JOIN client_sites cs ON cs.id = ps.client_site_id
                WHERE ps.domain IS NOT NULL AND ps.domain != ''
            ")->fetchAll();

            foreach ($rows as $row) {
                if ($this->ensureDomainForSite($row['domain'], (int)$row['cs_id'], $row['client_id'])) {
                    $count++;
                }
            }

            $this->logComplete($logId, 'completed', $count);
            return $count;
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', $count, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Find or create a domain record for the given domain string, then link it to
     * client_sites.domain_id if not already set. Returns true if a domain was created or linked.
     */
    private function ensureDomainForSite(string $domain, int $clientSiteId, ?int $clientId): bool
    {
        // Skip Ploi-generated test/preview domains
        if (str_contains($domain, '.ploi.site') || str_contains($domain, '.ploi-app.site')) {
            return false;
        }

        // Don't overwrite an existing domain link
        $csRow = $this->db->prepare("SELECT domain_id FROM client_sites WHERE id = ? LIMIT 1");
        $csRow->execute([$clientSiteId]);
        if ($csRow->fetchColumn()) return false;

        // Look for an existing domain record (case-insensitive)
        $domRow = $this->db->prepare("SELECT id FROM domains WHERE LOWER(domain) = LOWER(?) LIMIT 1");
        $domRow->execute([$domain]);
        $domainId = $domRow->fetchColumn();

        if (!$domainId) {
            // Create a new domain record
            $this->db->prepare(
                "INSERT INTO domains (client_id, domain, cloudflare_proxied, created_at) VALUES (?, ?, 0, datetime('now'))"
            )->execute([$clientId, $domain]);
            $domainId = (int)$this->db->lastInsertId();
        }

        $this->db->prepare("UPDATE client_sites SET domain_id = ? WHERE id = ?")->execute([$domainId, $clientSiteId]);
        return true;
    }

    private function flagStale(string $table, array $seenPloiIds): void
    {
        if (!$seenPloiIds) return;
        $in = implode(',', array_fill(0, count($seenPloiIds), '?'));
        $this->db->prepare("UPDATE $table SET is_stale = 1 WHERE ploi_id NOT IN ($in)")->execute($seenPloiIds);
    }

    private function toArray(mixed $data): array
    {
        if ($data === null) return [];
        return json_decode(json_encode($data), true) ?? [];
    }

    private function logStart(string $type): int
    {
        $this->db->prepare("INSERT INTO ploi_sync_log (sync_type, status, started_at) VALUES (?, 'running', datetime('now'))")->execute([$type]);
        return (int)$this->db->lastInsertId();
    }

    private function logComplete(int $id, string $status, int $count, ?string $error = null): void
    {
        $this->db->prepare("UPDATE ploi_sync_log SET status = ?, records_synced = ?, error_message = ?, completed_at = datetime('now') WHERE id = ?")
            ->execute([$status, $count, $error, $id]);
    }
}
