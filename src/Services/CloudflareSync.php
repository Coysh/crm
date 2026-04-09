<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;

class CloudflareSync
{
    public function __construct(private CloudflareService $cf, private PDO $db) {}

    public function syncAll(): array
    {
        $zones      = $this->syncZones();
        $dnsRecords = 0;

        // Sync DNS records for all zones
        try {
            $rows = $this->db->query("SELECT zone_id FROM cloudflare_zones")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $zoneId) {
                $dnsRecords += $this->syncDnsRecords($zoneId);
            }
        } catch (\Throwable) {}

        // Update last sync timestamp
        try {
            $this->db->exec("UPDATE cloudflare_config SET last_sync_at = datetime('now') WHERE id = 1");
        } catch (\Throwable) {}

        return ['zones' => $zones, 'dns_records' => $dnsRecords];
    }

    public function syncZones(): int
    {
        $zones = $this->cf->listZones();
        $count = 0;

        foreach ($zones as $zone) {
            $zoneId = $zone['id'] ?? null;
            if (!$zoneId) continue;

            $nameServers = json_encode($zone['name_servers'] ?? []);
            $plan        = is_array($zone['plan'] ?? null) ? ($zone['plan']['name'] ?? null) : ($zone['plan'] ?? null);
            $ssl         = is_array($zone['meta'] ?? null) ? null : null;
            // SSL status from settings
            $sslStatus   = $zone['ssl'] ?? null;
            if (is_array($sslStatus)) {
                $sslStatus = $sslStatus['status'] ?? null;
            }
            $alwaysHttps = $zone['always_use_https'] ?? 0;

            // Determine domain_id by matching zone name to domains table
            $domainId = null;
            try {
                // Only auto-link if not already linked
                $existing = $this->db->prepare("SELECT id, domain_id FROM cloudflare_zones WHERE zone_id = ? LIMIT 1");
                $existing->execute([$zoneId]);
                $existingRow = $existing->fetch();

                if ($existingRow && $existingRow['domain_id']) {
                    // Already linked — keep existing link
                    $domainId = $existingRow['domain_id'];
                } else {
                    // Try to auto-match by domain name — skip archived domains
                    $matchStmt = $this->db->prepare("SELECT id FROM domains WHERE LOWER(TRIM(domain)) = LOWER(TRIM(?)) AND COALESCE(status, 'active') = 'active' LIMIT 1");
                    $matchStmt->execute([$zone['name'] ?? '']);
                    $domainId = $matchStmt->fetchColumn() ?: null;
                }
            } catch (\Throwable) {}

            try {
                $this->db->prepare("
                    INSERT INTO cloudflare_zones
                        (zone_id, name, status, name_servers, plan, ssl_status, always_use_https, domain_id, last_synced_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
                    ON CONFLICT(zone_id) DO UPDATE SET
                        name             = excluded.name,
                        status           = excluded.status,
                        name_servers     = excluded.name_servers,
                        plan             = excluded.plan,
                        ssl_status       = excluded.ssl_status,
                        always_use_https = excluded.always_use_https,
                        domain_id        = CASE WHEN cloudflare_zones.domain_id IS NULL THEN excluded.domain_id ELSE cloudflare_zones.domain_id END,
                        last_synced_at   = excluded.last_synced_at
                ")->execute([
                    $zoneId,
                    $zone['name'] ?? '',
                    $zone['status'] ?? null,
                    $nameServers,
                    $plan,
                    $sslStatus,
                    $alwaysHttps ? 1 : 0,
                    $domainId,
                ]);
                $count++;
            } catch (\Throwable) {}
        }

        return $count;
    }

    public function syncDnsRecords(string $zoneId): int
    {
        try {
            $records = $this->cf->listDnsRecords($zoneId);
        } catch (\Throwable) {
            return 0;
        }

        $count = 0;
        foreach ($records as $record) {
            $recordId = $record['id'] ?? null;
            if (!$recordId) continue;

            try {
                $this->db->prepare("
                    INSERT INTO cloudflare_dns_records
                        (record_id, zone_id, type, name, content, ttl, proxied, priority, last_synced_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
                    ON CONFLICT(record_id) DO UPDATE SET
                        zone_id        = excluded.zone_id,
                        type           = excluded.type,
                        name           = excluded.name,
                        content        = excluded.content,
                        ttl            = excluded.ttl,
                        proxied        = excluded.proxied,
                        priority       = excluded.priority,
                        last_synced_at = excluded.last_synced_at
                ")->execute([
                    $recordId,
                    $zoneId,
                    $record['type'] ?? '',
                    $record['name'] ?? '',
                    $record['content'] ?? '',
                    $record['ttl'] ?? 1,
                    ($record['proxied'] ?? false) ? 1 : 0,
                    $record['priority'] ?? null,
                ]);
                $count++;
            } catch (\Throwable) {}
        }

        return $count;
    }
}
