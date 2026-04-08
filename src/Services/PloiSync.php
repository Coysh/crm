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
        $logId = $this->logStart('full');
        $results = ['servers' => 0, 'sites' => 0, 'errors' => []];

        try {
            $results['servers'] = $this->syncServers();
            $results['sites'] = $this->syncSites();
            $this->logComplete($logId, 'completed', $results['servers'] + $results['sites']);
            $this->db->exec("UPDATE ploi_config SET last_sync_at = datetime('now') WHERE id = 1");
        } catch (Throwable $e) {
            $results['errors']['full'] = $e->getMessage();
            $this->logComplete($logId, 'failed', $results['servers'] + $results['sites'], $e->getMessage());
        }

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
                $resp = $ploi->servers()->perPage(50)->page($page)->get();
                $servers = $resp['data'] ?? [];
                if (!$servers) break;

                foreach ($servers as $server) {
                    $sid = (int)($server['id'] ?? 0);
                    if (!$sid) continue;
                    $seen[] = $sid;

                    $detail = $ploi->servers($sid)->get();
                    $d = $detail['data'] ?? $detail;
                    $phpVersions = array_values(array_filter(array_map(
                        fn($v) => $v['version'] ?? ($v['name'] ?? null),
                        $d['php_versions'] ?? []
                    )));

                    $this->db->prepare("\n                        INSERT INTO ploi_servers\n                            (ploi_id, name, ip_address, provider, region, status, php_versions, php_cli_version, is_stale, last_synced_at)\n                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, datetime('now'))\n                        ON CONFLICT(ploi_id) DO UPDATE SET\n                            name = excluded.name,\n                            ip_address = excluded.ip_address,\n                            provider = excluded.provider,\n                            region = excluded.region,\n                            status = excluded.status,\n                            php_versions = excluded.php_versions,\n                            php_cli_version = excluded.php_cli_version,\n                            is_stale = 0,\n                            last_synced_at = excluded.last_synced_at\n                    ")->execute([
                        $sid,
                        $d['name'] ?? 'Unknown',
                        $d['ip_address'] ?? ($d['ip'] ?? null),
                        $d['provider'] ?? null,
                        $d['region'] ?? null,
                        $d['status'] ?? null,
                        json_encode($phpVersions),
                        $d['php_version'] ?? null,
                    ]);
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

        try {
            $ploi = $this->ploiService->sdk();
            $servers = $this->db->query("SELECT id, ploi_id FROM ploi_servers")->fetchAll();
            foreach ($servers as $server) {
                $sid = (int)$server['ploi_id'];
                for ($page = 1; $page <= 100; $page++) {
                    $resp = $ploi->servers($sid)->sites()->perPage(50)->page($page)->get();
                    $sites = $resp['data'] ?? [];
                    if (!$sites) break;

                    foreach ($sites as $site) {
                        $siteId = (int)($site['id'] ?? 0);
                        if (!$siteId) continue;
                        $seen[] = $siteId;

                        $repo = null;
                        $branch = null;
                        try {
                            $repoResp = $ploi->servers($sid)->sites($siteId)->repository()->get();
                            $repoData = $repoResp['data'] ?? $repoResp;
                            $repo = $repoData['repository'] ?? ($repoData['url'] ?? null);
                            $branch = $repoData['branch'] ?? null;
                        } catch (Throwable) {}

                        $hasSsl = 0;
                        try {
                            $certResp = $ploi->servers($sid)->sites($siteId)->certificates()->get();
                            $certs = $certResp['data'] ?? [];
                            $hasSsl = count($certs) > 0 ? 1 : 0;
                        } catch (Throwable) {}

                        $this->db->prepare("\n                            INSERT INTO ploi_sites\n                                (ploi_id, ploi_server_id, domain, project_type, php_version, web_directory, project_root, repository, branch, has_ssl, test_domain, status, is_stale, last_synced_at)\n                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, datetime('now'))\n                            ON CONFLICT(ploi_id) DO UPDATE SET\n                                ploi_server_id = excluded.ploi_server_id,\n                                domain = excluded.domain,\n                                project_type = excluded.project_type,\n                                php_version = excluded.php_version,\n                                web_directory = excluded.web_directory,\n                                project_root = excluded.project_root,\n                                repository = excluded.repository,\n                                branch = excluded.branch,\n                                has_ssl = excluded.has_ssl,\n                                test_domain = excluded.test_domain,\n                                status = excluded.status,\n                                is_stale = 0,\n                                last_synced_at = excluded.last_synced_at\n                        ")->execute([
                            $siteId,
                            $server['id'],
                            $site['domain'] ?? ($site['name'] ?? 'unknown'),
                            $site['project_type'] ?? null,
                            $site['php_version'] ?? null,
                            $site['web_directory'] ?? null,
                            $site['project_root'] ?? null,
                            $repo,
                            $branch,
                            $hasSsl,
                            $site['test_domain'] ?? null,
                            $site['status'] ?? null,
                        ]);
                        $count++;
                    }
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

    private function flagStale(string $table, array $seenPloiIds): void
    {
        if (!$seenPloiIds) return;
        $in = implode(',', array_fill(0, count($seenPloiIds), '?'));
        $this->db->prepare("UPDATE $table SET is_stale = 1 WHERE ploi_id NOT IN ($in)")->execute($seenPloiIds);
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
