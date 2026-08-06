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
        $excludedServerIds = $this->excludedServerPloiIds();

        try {
            $ploi = $this->ploiService->sdk();
            for ($page = 1; $page <= 100; $page++) {
                $resp = $ploi->servers()->perPage(50)->page($page);
                $servers = $this->toArray($resp->getData());
                if (!$servers) break;

                foreach ($servers as $server) {
                    $sid = (int)($server['id'] ?? 0);
                    if (!$sid) continue;
                    if (in_array($sid, $excludedServerIds, true)) continue;
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

        $excludedServerIds = $this->excludedServerPloiIds();

        try {
            $ploi = $this->ploiService->sdk();
            // Skip servers that no longer exist in Ploi (stale) or were excluded —
            // fetching sites for them 404s on every sync.
            $servers = $this->db->query('SELECT id, ploi_id FROM ploi_servers WHERE is_stale = 0')->fetchAll();

            foreach ($servers as $server) {
                $sid = (int)$server['ploi_id'];
                if (in_array($sid, $excludedServerIds, true)) continue;
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

                        // Resolve the linked CRM server_id from the ploi_server row
                        $srvRow = $this->db->prepare("SELECT server_id FROM ploi_servers WHERE id = ?");
                        $srvRow->execute([$server['id']]);
                        $linkedServerId = $srvRow->fetchColumn() ?: null;

                        if (!$existingCsId) {
                            // A site rebuilt on another server comes back under a new
                            // Ploi id. Adopt the CRM record already holding that domain
                            // rather than stranding it and starting an empty duplicate.
                            $existingCsId = $this->findUnlinkedClientSite($domain);

                            if (!$existingCsId) {
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
                                $existingCsId = (int)$this->db->lastInsertId();
                            }

                            $this->db->prepare(
                                "UPDATE ploi_sites SET client_site_id = ? WHERE ploi_id = ?"
                            )->execute([$existingCsId, $siteId]);
                            if ($linkedServerId) {
                                $this->db->prepare("UPDATE client_sites SET server_id = ? WHERE id = ?")
                                    ->execute([$linkedServerId, (int)$existingCsId]);
                            }
                        } elseif ($linkedServerId) {
                            // The site already exists locally. If it has moved to a
                            // different server in Ploi, follow that move — otherwise
                            // client_sites.server_id silently keeps pointing at the old
                            // server, skewing the sites/servers views and the
                            // server-linked recurring cost apportionment. Deliberately
                            // doesn't touch `status` — a dead (archived) site's recorded
                            // infrastructure should still stay accurate even though it's
                            // no longer billable.
                            $this->db->prepare(
                                "UPDATE client_sites SET server_id = ?
                                 WHERE id = ? AND (server_id IS NULL OR server_id != ?)"
                            )->execute([$linkedServerId, (int)$existingCsId, $linkedServerId]);
                        }

                        // Auto-create or link domain record for this site
                        if ($existingCsId) {
                            $this->ensureDomainForSite($domain, (int)$existingCsId, null);
                        }

                        $count++;
                    }
                }
                } catch (\Ploi\Exceptions\Http\NotFound) {
                    // Server no longer exists in Ploi — mark stale so future syncs
                    // skip it, and log informationally rather than as a failure.
                    $this->db->prepare("UPDATE ploi_servers SET is_stale = 1 WHERE ploi_id = ?")->execute([$sid]);
                    $this->logComplete(
                        $this->logStart('sites_server_' . $sid),
                        'skipped_stale',
                        0,
                        "Server $sid no longer exists in Ploi — marked stale."
                    );
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

    /**
     * Remove shadow rows for servers/sites that no longer exist in Ploi.
     * CRM records (servers, client_sites) are untouched — only the Ploi
     * mirror rows and their links disappear.
     */
    public function purgeStale(): array
    {
        $sites = $this->db->exec("DELETE FROM ploi_sites WHERE is_stale = 1");
        $servers = $this->db->exec("DELETE FROM ploi_servers WHERE is_stale = 1");
        return ['servers' => (int)$servers, 'sites' => (int)$sites];
    }

    /**
     * Everything the reconcile UI needs after a server has been deleted in Ploi:
     * each stale site paired with the live Ploi site(s) now serving the same
     * domain, plus the stale servers and what still points at their CRM row.
     */
    public function staleReport(): array
    {
        $report = ['servers' => [], 'sites' => []];

        try {
            $report['servers'] = $this->db->query("
                SELECT pss.id, pss.ploi_id, pss.name, pss.ip_address, pss.server_id,
                       s.name AS crm_server_name
                FROM ploi_servers pss
                LEFT JOIN servers s ON s.id = pss.server_id
                WHERE pss.is_stale = 1
                ORDER BY pss.name
            ")->fetchAll();

            foreach ($report['servers'] as &$srv) {
                $srv['blockers'] = $srv['server_id']
                    ? $this->crmServerBlockers((int)$srv['server_id'], true)
                    : [];
            }
            unset($srv);

            $report['sites'] = $this->db->query("
                SELECT ps.id, ps.ploi_id, ps.domain, ps.client_site_id,
                       old.name  AS old_server_name,
                       c.name    AS client_name,
                       d.domain  AS crm_domain,
                       cs.notes  AS crm_notes
                FROM ploi_sites ps
                LEFT JOIN ploi_servers old ON old.id = ps.ploi_server_id
                LEFT JOIN client_sites cs  ON cs.id  = ps.client_site_id
                LEFT JOIN clients c        ON c.id   = cs.client_id
                LEFT JOIN domains d        ON d.id   = cs.domain_id
                WHERE ps.is_stale = 1
                ORDER BY LOWER(ps.domain)
            ")->fetchAll();

            // A site moved between servers keeps its domain but gets a brand new
            // Ploi id, so the domain is what pairs the old record with the new one.
            $cands = $this->db->prepare("
                SELECT ps.id, ps.ploi_id, ps.domain, ps.client_site_id,
                       srv.name AS server_name,
                       c.name   AS client_name
                FROM ploi_sites ps
                LEFT JOIN ploi_servers srv ON srv.id = ps.ploi_server_id
                LEFT JOIN client_sites cs  ON cs.id  = ps.client_site_id
                LEFT JOIN clients c        ON c.id   = cs.client_id
                WHERE ps.is_stale = 0 AND LOWER(ps.domain) = LOWER(?) AND ps.id != ?
                ORDER BY ps.last_synced_at DESC
            ");
            foreach ($report['sites'] as &$site) {
                $cands->execute([$site['domain'], (int)$site['id']]);
                $site['candidates'] = $cands->fetchAll();
            }
            unset($site);
        } catch (Throwable) {}

        return $report;
    }

    /**
     * The CRM record for a domain that no Ploi site points at — what a site
     * rebuilt on a new server leaves behind. Prefers a record with a client on
     * it, then the oldest.
     */
    /**
     * Deliberately excludes archived sites: a re-appearing domain never
     * silently reactivates a site the user archived from the CRM. It falls
     * through to the normal "create a new empty client_sites row" path
     * instead — a human has to hit Restore if it's really the same site.
     */
    private function findUnlinkedClientSite(string $domain): ?int
    {
        if ($domain === '' || str_contains($domain, '.ploi.site') || str_contains($domain, '.ploi-app.site')) {
            return null;
        }
        try {
            $stmt = $this->db->prepare("
                SELECT cs.id
                FROM client_sites cs
                JOIN domains d ON d.id = cs.domain_id
                WHERE LOWER(d.domain) = LOWER(?)
                  AND COALESCE(cs.status, 'active') = 'active'
                  AND NOT EXISTS (SELECT 1 FROM ploi_sites p WHERE p.client_site_id = cs.id)
                ORDER BY cs.client_id IS NULL, cs.id
                LIMIT 1
            ");
            $stmt->execute([$domain]);
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Duplicate CRM site records left behind when a server was decommissioned
     * and its stale Ploi rows purged before the old records could be moved
     * across: the curated record sits on the dead server with no Ploi link,
     * while an empty one holds the live site. Pairs them up by domain.
     */
    public function duplicateSiteReport(): array
    {
        $pairs = [];
        try {
            $live = $this->db->query("
                SELECT ps.id, ps.ploi_id, ps.domain, ps.client_site_id,
                       srv.name      AS server_name,
                       srv.server_id AS crm_server_id,
                       lcs.client_id AS linked_client_id,
                       lcs.domain_id AS linked_domain_id,
                       lc.name       AS linked_client_name,
                       ls.name       AS linked_server_name
                FROM ploi_sites ps
                LEFT JOIN ploi_servers srv ON srv.id  = ps.ploi_server_id
                JOIN client_sites lcs      ON lcs.id  = ps.client_site_id
                LEFT JOIN clients lc       ON lc.id   = lcs.client_id
                LEFT JOIN servers ls       ON ls.id   = lcs.server_id
                WHERE ps.is_stale = 0
                ORDER BY LOWER(ps.domain)
            ")->fetchAll();

            $strandedStmt = $this->db->prepare("
                SELECT cs.id, cs.client_id, cs.notes,
                       c.name AS client_name,
                       s.name AS server_name,
                       d.domain
                FROM client_sites cs
                LEFT JOIN domains d ON d.id = cs.domain_id
                LEFT JOIN clients c ON c.id = cs.client_id
                LEFT JOIN servers s ON s.id = cs.server_id
                WHERE cs.id != ?
                  AND NOT EXISTS (SELECT 1 FROM ploi_sites p WHERE p.client_site_id = cs.id)
                  AND (LOWER(COALESCE(d.domain, '')) = LOWER(?)
                       OR (? IS NOT NULL AND cs.domain_id = ?))
                ORDER BY cs.client_id IS NULL, cs.id
            ");

            foreach ($live as $site) {
                $domain = (string)$site['domain'];
                if (str_contains($domain, '.ploi.site') || str_contains($domain, '.ploi-app.site')) continue;

                $strandedStmt->execute([
                    (int)$site['client_site_id'],
                    $domain,
                    $site['linked_domain_id'],
                    $site['linked_domain_id'],
                ]);
                foreach ($strandedStmt->fetchAll() as $stranded) {
                    // Keep whichever record carries the client; the curated one
                    // wins a tie, being the older of the two.
                    $keepStranded = !empty($stranded['client_id']) || empty($site['linked_client_id']);
                    $pairs[] = [
                        'ploi_site_id'    => (int)$site['id'],
                        'domain'          => $domain,
                        'new_server_name' => $site['server_name'],
                        'crm_server_id'   => $site['crm_server_id'] ? (int)$site['crm_server_id'] : null,
                        'stranded'        => $stranded,
                        'linked'          => [
                            'id'          => (int)$site['client_site_id'],
                            'client_id'   => $site['linked_client_id'],
                            'client_name' => $site['linked_client_name'],
                            'server_name' => $site['linked_server_name'],
                        ],
                        'keep_id'         => $keepStranded ? (int)$stranded['id'] : (int)$site['client_site_id'],
                        'drop_id'         => $keepStranded ? (int)$site['client_site_id'] : (int)$stranded['id'],
                        'both_assigned'   => !empty($stranded['client_id']) && !empty($site['linked_client_id']),
                    ];
                }
            }
        } catch (Throwable) {}

        return $pairs;
    }

    /**
     * Fold the stranded CRM site records into the ones holding the live Ploi
     * sites (or the other way round, whichever carries the client), then tidy
     * up the CRM server rows the old sites left behind.
     *
     * @param int[] $ploiSiteIds which pairs from duplicateSiteReport() to merge
     */
    public function mergeDuplicateSites(array $ploiSiteIds, bool $removeEmptyServers = false): array
    {
        $summary = ['merged' => 0, 'servers' => 0, 'notes' => []];
        $wanted  = array_flip(array_map('intval', $ploiSiteIds));
        if (!$wanted && !$removeEmptyServers) return $summary;

        $pairs = array_filter($this->duplicateSiteReport(), fn($p) => isset($wanted[$p['ploi_site_id']]));

        $this->db->beginTransaction();
        try {
            $done = [];
            foreach ($pairs as $pair) {
                // One live site can only absorb one record; ignore later pairs
                // for the same site (and anything already merged away).
                if (isset($done[$pair['ploi_site_id']]) || isset($done['cs' . $pair['drop_id']])) continue;
                if ($pair['both_assigned']) {
                    $summary['notes'][] = $pair['domain'] . ' has two records with a client on each — left alone, merge that one by hand.';
                    continue;
                }

                $keep = $pair['keep_id'];
                $drop = $pair['drop_id'];

                $this->mergeClientSites($keep, $drop);
                $this->db->prepare("UPDATE ploi_sites SET client_site_id = ? WHERE client_site_id = ?")
                    ->execute([$keep, $drop]);
                $this->repointCostLinks($keep, $drop);
                $this->deleteClientSite($drop, $pair['domain'], 'Merged into the CRM site record kept for this domain');

                if ($pair['crm_server_id']) {
                    $this->db->prepare("UPDATE client_sites SET server_id = ? WHERE id = ?")
                        ->execute([$pair['crm_server_id'], $keep]);
                }

                $done[$pair['ploi_site_id']] = true;
                $done['cs' . $drop] = true;
                $summary['merged']++;
            }

            if ($removeEmptyServers) {
                // Only ever the rows the Ploi import created for itself, and only
                // once their Ploi mirror is gone and nothing else points at them.
                $candidates = $this->db->query("
                    SELECT s.id, s.name
                    FROM servers s
                    WHERE s.notes LIKE 'Imported from Ploi%'
                      AND NOT EXISTS (SELECT 1 FROM ploi_servers p WHERE p.server_id = s.id)
                    ORDER BY s.name
                ")->fetchAll();

                foreach ($candidates as $srv) {
                    $blockers = $this->crmServerBlockers((int)$srv['id']);
                    if ($blockers) continue;
                    $this->db->prepare(
                        "INSERT INTO deletion_log (entity_type, entity_id, entity_name, related_data, deleted_at)
                         VALUES ('server', ?, ?, ?, datetime('now'))"
                    )->execute([(int)$srv['id'], (string)$srv['name'], json_encode(['reason' => 'No longer in Ploi and nothing linked to it'])]);
                    $this->db->prepare("DELETE FROM servers WHERE id = ?")->execute([(int)$srv['id']]);
                    $summary['servers']++;
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $summary;
    }

    /**
     * Clear up after a server migration. Each stale Ploi site gets one of three
     * decisions, keyed by ploi_sites.id:
     *   transfer — move its CRM record onto the live site now serving that domain
     *   keep     — leave the CRM record alone (default)
     *   delete   — delete the CRM site record as well
     * The stale Ploi mirror rows always go, along with the stale servers.
     *
     * @param array<int, array{action?: string, successor?: int}> $decisions
     */
    public function reconcileStale(array $decisions, bool $removeCrmServers = false): array
    {
        $summary = [
            'transferred'   => 0,
            'kept'          => 0,
            'deleted_sites' => 0,
            'ploi_sites'    => 0,
            'ploi_servers'  => 0,
            'crm_servers'   => 0,
            'notes'         => [],
        ];

        $stale = $this->db->query("SELECT * FROM ploi_sites WHERE is_stale = 1")->fetchAll();

        $this->db->beginTransaction();
        try {
            foreach ($stale as $row) {
                $id       = (int)$row['id'];
                $action   = $decisions[$id]['action'] ?? 'keep';
                $csId     = $row['client_site_id'] ? (int)$row['client_site_id'] : null;
                $domain   = (string)$row['domain'];

                if ($action === 'transfer') {
                    $successor = null;
                    $successorId = (int)($decisions[$id]['successor'] ?? 0);
                    if ($successorId) {
                        $stmt = $this->db->prepare("SELECT * FROM ploi_sites WHERE id = ? AND is_stale = 0");
                        $stmt->execute([$successorId]);
                        $successor = $stmt->fetch() ?: null;
                    }
                    if (!$successor) {
                        $summary['notes'][] = "No live site chosen for $domain — its CRM record was left as it is.";
                        $summary['kept']++;
                        continue;
                    }
                    $this->transferSiteRecord($row, $successor);
                    $summary['transferred']++;
                } elseif ($action === 'delete') {
                    if ($csId) {
                        $this->deleteClientSite($csId, $domain, 'Removed with the deleted Ploi server');
                        $summary['deleted_sites']++;
                    } else {
                        $summary['kept']++;
                    }
                } else {
                    $summary['kept']++;
                }
            }

            $summary['ploi_sites'] = (int)$this->db->exec("DELETE FROM ploi_sites WHERE is_stale = 1");

            $staleServers = $this->db->query("SELECT id, ploi_id, name, server_id FROM ploi_servers WHERE is_stale = 1")->fetchAll();
            $summary['ploi_servers'] = (int)$this->db->exec("DELETE FROM ploi_servers WHERE is_stale = 1");

            if ($removeCrmServers) {
                foreach ($staleServers as $srv) {
                    $crmId = $srv['server_id'] ? (int)$srv['server_id'] : null;
                    if (!$crmId) continue;

                    $blockers = $this->crmServerBlockers($crmId);
                    if ($blockers) {
                        $summary['notes'][] = 'Kept CRM server "' . $srv['name'] . '" — still linked to ' . implode(', ', $blockers) . '.';
                        continue;
                    }

                    $nameStmt = $this->db->prepare("SELECT name FROM servers WHERE id = ?");
                    $nameStmt->execute([$crmId]);
                    $crmName = $nameStmt->fetchColumn();
                    if ($crmName === false) continue;

                    $this->db->prepare(
                        "INSERT INTO deletion_log (entity_type, entity_id, entity_name, related_data, deleted_at)
                         VALUES ('server', ?, ?, ?, datetime('now'))"
                    )->execute([$crmId, (string)$crmName, json_encode(['reason' => 'Deleted in Ploi', 'ploi_id' => (int)$srv['ploi_id']])]);
                    $this->db->prepare("DELETE FROM servers WHERE id = ?")->execute([$crmId]);
                    $summary['crm_servers']++;
                }
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $summary;
    }

    /**
     * Move the CRM record behind a stale Ploi site onto the live site that now
     * serves the same domain. The curated record wins: the throwaway one the
     * sync auto-created for the new site is merged into it and removed.
     */
    private function transferSiteRecord(array $stale, array $successor): void
    {
        $oldCs = $stale['client_site_id'] ? (int)$stale['client_site_id'] : null;
        $newCs = $successor['client_site_id'] ? (int)$successor['client_site_id'] : null;
        if (!$oldCs) return;

        $stmt = $this->db->prepare("SELECT server_id FROM ploi_servers WHERE id = ?");
        $stmt->execute([(int)$successor['ploi_server_id']]);
        $crmServerId = $stmt->fetchColumn() ?: null;

        if ($newCs && $newCs !== $oldCs) {
            $this->mergeClientSites($oldCs, $newCs);
            // Every Ploi mirror row on the throwaway record follows the merge…
            $this->db->prepare("UPDATE ploi_sites SET client_site_id = ? WHERE client_site_id = ?")
                ->execute([$oldCs, $newCs]);
            $this->repointCostLinks($oldCs, $newCs);
            // …so nothing references it by the time it goes.
            $this->deleteClientSite($newCs, (string)$successor['domain'], 'Merged into the existing CRM site record');
        } else {
            $this->db->prepare("UPDATE ploi_sites SET client_site_id = ? WHERE id = ?")
                ->execute([$oldCs, (int)$successor['id']]);
        }

        if ($crmServerId) {
            $this->db->prepare("UPDATE client_sites SET server_id = ? WHERE id = ?")
                ->execute([(int)$crmServerId, $oldCs]);
        }
    }

    /**
     * Copy anything the auto-created record knows that the kept one doesn't.
     * Never overwrites a value already set by hand.
     */
    private function mergeClientSites(int $keepId, int $dropId): void
    {
        $stmt = $this->db->prepare("SELECT * FROM client_sites WHERE id = ?");
        $stmt->execute([$keepId]);
        $keep = $stmt->fetch();
        $stmt->execute([$dropId]);
        $drop = $stmt->fetch();
        if (!$keep || !$drop) return;

        $sets = [];
        $params = [];
        foreach (['client_id', 'domain_id', 'website_stack', 'css_framework', 'smtp_service', 'git_repo'] as $col) {
            $keepVal = $keep[$col] ?? null;
            $dropVal = $drop[$col] ?? null;
            if (($keepVal === null || $keepVal === '') && $dropVal !== null && $dropVal !== '') {
                $sets[] = "$col = ?";
                $params[] = $dropVal;
            }
        }
        if (empty($keep['has_deployment_pipeline']) && !empty($drop['has_deployment_pipeline'])) {
            $sets[] = 'has_deployment_pipeline = 1';
        }
        if (!$sets) return;

        $params[] = $keepId;
        $this->db->prepare("UPDATE client_sites SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }

    /**
     * Move per-site recurring cost links off the record that is about to go,
     * dropping any that would duplicate a link the kept record already has.
     */
    private function repointCostLinks(int $keepId, int $dropId): void
    {
        try {
            $this->db->prepare(
                "DELETE FROM recurring_cost_clients
                 WHERE client_site_id = ?
                   AND recurring_cost_id IN (SELECT recurring_cost_id FROM recurring_cost_clients WHERE client_site_id = ?)"
            )->execute([$dropId, $keepId]);
            $this->db->prepare("UPDATE recurring_cost_clients SET client_site_id = ? WHERE client_site_id = ?")
                ->execute([$keepId, $dropId]);
        } catch (Throwable) {}
    }

    private function deleteClientSite(int $id, string $domain, string $reason): void
    {
        $this->db->prepare(
            "INSERT INTO deletion_log (entity_type, entity_id, entity_name, related_data, deleted_at)
             VALUES ('client_site', ?, ?, ?, datetime('now'))"
        )->execute([$id, $domain !== '' ? $domain : ('Site #' . $id), json_encode(['reason' => $reason])]);

        $this->db->prepare("UPDATE ploi_sites SET client_site_id = NULL WHERE client_site_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM client_sites WHERE id = ?")->execute([$id]);
    }

    /**
     * What still points at a CRM server row, in words. `$ignorePloiMirror`
     * skips the Ploi mirror row itself — the preview needs that, since the
     * stale mirror row is about to be deleted but hasn't been yet.
     *
     * @return string[]
     */
    private function crmServerBlockers(int $serverId, bool $ignorePloiMirror = false): array
    {
        $checks = [
            'client_sites'    => ['SELECT COUNT(*) FROM client_sites WHERE server_id = ?', 'site', 'sites'],
            'recurring_costs' => ['SELECT COUNT(*) FROM recurring_costs WHERE server_id = ?', 'recurring cost', 'recurring costs'],
            'expenses'        => ['SELECT COUNT(*) FROM expenses WHERE server_id = ?', 'expense', 'expenses'],
        ];
        if (!$ignorePloiMirror) {
            $checks['ploi_servers'] = ['SELECT COUNT(*) FROM ploi_servers WHERE server_id = ?', 'Ploi server', 'Ploi servers'];
        }

        $blockers = [];
        foreach ($checks as [$sql, $one, $many]) {
            try {
                $stmt = $this->db->prepare($sql);
                $stmt->execute([$serverId]);
                $n = (int)$stmt->fetchColumn();
                if ($n > 0) $blockers[] = $n . ' ' . ($n === 1 ? $one : $many);
            } catch (Throwable) {}
        }
        return $blockers;
    }

    private function excludedServerPloiIds(): array
    {
        try {
            $ids = $this->db->query("SELECT ploi_server_id FROM ploi_server_exclusions")->fetchAll(PDO::FETCH_COLUMN);
            return array_map('intval', $ids);
        } catch (Throwable) {
            return [];
        }
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
