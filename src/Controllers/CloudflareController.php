<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\CloudflareService;
use CoyshCRM\Services\CloudflareSync;
use PDO;

class CloudflareController
{
    private CloudflareService $cf;

    public function __construct(private PDO $db)
    {
        $this->cf = new CloudflareService($db);
    }

    public function settings(): void
    {
        $config    = $this->cf->getConfig();
        $connected = $this->cf->isConnected();

        // Zones list
        $zones = [];
        try {
            $zones = $this->db->query("SELECT cz.*, d.domain AS domain_name, d.id AS domain_id FROM cloudflare_zones cz LEFT JOIN domains d ON d.id = cz.domain_id ORDER BY cz.name")->fetchAll();
        } catch (\Throwable) {}

        // Domains without a linked zone (for manual link)
        $availableDomains = [];
        try {
            $availableDomains = $this->db->query("SELECT d.id, d.domain FROM domains d WHERE NOT EXISTS (SELECT 1 FROM cloudflare_zones cz WHERE cz.domain_id = d.id) ORDER BY d.domain")->fetchAll();
        } catch (\Throwable) {}

        // Mask token — show only last 4 chars
        $maskedToken = '';
        if (!empty($config['api_token'])) {
            $token = $config['api_token'];
            $maskedToken = str_repeat('•', max(0, strlen($token) - 4)) . substr($token, -4);
        }

        $breadcrumbs = [['Settings', '/settings'], ['Cloudflare', null]];
        render('settings.cloudflare', compact('config', 'connected', 'zones', 'availableDomains', 'maskedToken', 'breadcrumbs'), 'Cloudflare Settings');
    }

    public function save(): void
    {
        $token = trim($_POST['api_token'] ?? '');
        if (!$token) {
            flash('error', 'API token is required.');
            redirect('/settings/cloudflare');
            return;
        }

        try {
            $existing = $this->db->query("SELECT id FROM cloudflare_config WHERE id = 1")->fetch();
            if ($existing) {
                $this->db->prepare("UPDATE cloudflare_config SET api_token = ? WHERE id = 1")->execute([$token]);
            } else {
                $this->db->prepare("INSERT INTO cloudflare_config (id, api_token) VALUES (1, ?)")->execute([$token]);
            }
            flash('success', 'Cloudflare API token saved.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to save token: ' . $e->getMessage());
        }
        redirect('/settings/cloudflare');
    }

    public function test(): void
    {
        if (!$this->cf->isConnected()) {
            flash('error', 'No API token configured.');
            redirect('/settings/cloudflare');
            return;
        }

        try {
            $ok = $this->cf->verifyToken();
            if ($ok) {
                flash('success', 'Cloudflare token is valid.');
            } else {
                flash('error', 'Token verification failed — check the token has the correct permissions.');
            }
        } catch (\Throwable $e) {
            flash('error', 'Connection test failed: ' . $e->getMessage());
        }
        redirect('/settings/cloudflare');
    }

    public function sync(): void
    {
        if (!$this->cf->isConnected()) {
            flash('error', 'Cloudflare is not connected.');
            redirect('/settings/cloudflare');
            return;
        }

        try {
            $sync    = new CloudflareSync($this->cf, $this->db);
            $results = $sync->syncAll();
            flash('success', "Cloudflare sync complete. Zones: {$results['zones']}, DNS records: {$results['dns_records']}.");
        } catch (\Throwable $e) {
            flash('error', 'Sync failed: ' . $e->getMessage());
        }
        redirect('/settings/cloudflare');
    }

    public function disconnect(): void
    {
        try {
            $this->db->exec("UPDATE cloudflare_config SET api_token = NULL WHERE id = 1");
            flash('success', 'Cloudflare disconnected.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to disconnect: ' . $e->getMessage());
        }
        redirect('/settings/cloudflare');
    }

    public function linkZone(string $zoneId): void
    {
        $domainId = (int)($_POST['domain_id'] ?? 0);
        if (!$domainId) {
            flash('error', 'Please select a domain to link.');
            redirect('/settings/cloudflare');
            return;
        }

        try {
            $this->db->prepare("UPDATE cloudflare_zones SET domain_id = ? WHERE zone_id = ?")->execute([$domainId, $zoneId]);
            flash('success', 'Zone linked to domain.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to link zone: ' . $e->getMessage());
        }
        redirect('/settings/cloudflare');
    }

    public function unlinkZone(string $zoneId): void
    {
        try {
            $this->db->prepare("UPDATE cloudflare_zones SET domain_id = NULL WHERE zone_id = ?")->execute([$zoneId]);
            flash('success', 'Zone unlinked.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to unlink zone: ' . $e->getMessage());
        }
        redirect('/settings/cloudflare');
    }

    public function dnsIndex(int $domainId): void
    {
        $domain = null;
        try {
            $stmt = $this->db->prepare("SELECT * FROM domains WHERE id = ? LIMIT 1");
            $stmt->execute([$domainId]);
            $domain = $stmt->fetch() ?: null;
        } catch (\Throwable) {}

        if (!$domain) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        // Get zone
        $zone = null;
        $dnsRecords = [];
        try {
            $stmt = $this->db->prepare("SELECT * FROM cloudflare_zones WHERE domain_id = ? LIMIT 1");
            $stmt->execute([$domainId]);
            $zone = $stmt->fetch() ?: null;

            if ($zone) {
                $stmt2 = $this->db->prepare("SELECT * FROM cloudflare_dns_records WHERE zone_id = ? ORDER BY type, name");
                $stmt2->execute([$zone['zone_id']]);
                $dnsRecords = $stmt2->fetchAll();
            }
        } catch (\Throwable) {}

        $breadcrumbs = [['Domains', '/domains'], [$domain['domain'], "/domains/$domainId"], ['DNS Records', null]];
        render('domains.dns', compact('domain', 'zone', 'dnsRecords', 'breadcrumbs'), 'DNS — ' . $domain['domain']);
    }

    public function createDns(int $domainId): void
    {
        $domain = null;
        try {
            $stmt = $this->db->prepare("SELECT * FROM domains WHERE id = ? LIMIT 1");
            $stmt->execute([$domainId]);
            $domain = $stmt->fetch() ?: null;
        } catch (\Throwable) {}

        if (!$domain) {
            flash('error', 'Domain not found.');
            redirect('/domains');
            return;
        }

        // Get zone
        $zone = null;
        try {
            $stmt = $this->db->prepare("SELECT * FROM cloudflare_zones WHERE domain_id = ? LIMIT 1");
            $stmt->execute([$domainId]);
            $zone = $stmt->fetch() ?: null;
        } catch (\Throwable) {}

        if (!$zone) {
            flash('error', 'No Cloudflare zone linked to this domain.');
            redirect("/domains/$domainId");
            return;
        }

        $recordData = [
            'type'    => strtoupper(trim($_POST['type'] ?? '')),
            'name'    => trim($_POST['name'] ?? ''),
            'content' => trim($_POST['content'] ?? ''),
            'ttl'     => (int)($_POST['ttl'] ?? 1),
            'proxied' => isset($_POST['proxied']),
        ];
        if (isset($_POST['priority'])) $recordData['priority'] = (int)$_POST['priority'];

        try {
            $result = $this->cf->createDnsRecord($zone['zone_id'], $recordData);
            // Sync back the created record
            if (!empty($result['result']['id'])) {
                $r = $result['result'];
                $this->db->prepare("
                    INSERT OR IGNORE INTO cloudflare_dns_records
                        (record_id, zone_id, type, name, content, ttl, proxied, priority, last_synced_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
                ")->execute([
                    $r['id'], $zone['zone_id'], $r['type'], $r['name'],
                    $r['content'], $r['ttl'] ?? 1, ($r['proxied'] ?? false) ? 1 : 0, $r['priority'] ?? null,
                ]);
            }
            flash('success', 'DNS record created.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to create DNS record: ' . $e->getMessage());
        }
        redirect("/domains/$domainId/dns");
    }

    public function updateDns(int $domainId, string $recordId): void
    {
        $domain = null;
        try {
            $stmt = $this->db->prepare("SELECT * FROM domains WHERE id = ? LIMIT 1");
            $stmt->execute([$domainId]);
            $domain = $stmt->fetch() ?: null;
        } catch (\Throwable) {}

        if (!$domain) {
            flash('error', 'Domain not found.');
            redirect('/domains');
            return;
        }

        $zone = null;
        try {
            $stmt = $this->db->prepare("SELECT * FROM cloudflare_zones WHERE domain_id = ? LIMIT 1");
            $stmt->execute([$domainId]);
            $zone = $stmt->fetch() ?: null;
        } catch (\Throwable) {}

        if (!$zone) {
            flash('error', 'No Cloudflare zone linked.');
            redirect("/domains/$domainId");
            return;
        }

        $recordData = [
            'type'    => strtoupper(trim($_POST['type'] ?? '')),
            'name'    => trim($_POST['name'] ?? ''),
            'content' => trim($_POST['content'] ?? ''),
            'ttl'     => (int)($_POST['ttl'] ?? 1),
            'proxied' => isset($_POST['proxied']),
        ];
        if (isset($_POST['priority'])) $recordData['priority'] = (int)$_POST['priority'];

        try {
            $result = $this->cf->updateDnsRecord($zone['zone_id'], $recordId, $recordData);
            // Update local cache
            if (!empty($result['result']['id'])) {
                $r = $result['result'];
                $this->db->prepare("
                    UPDATE cloudflare_dns_records SET
                        type = ?, name = ?, content = ?, ttl = ?, proxied = ?, priority = ?, last_synced_at = datetime('now')
                    WHERE record_id = ?
                ")->execute([$r['type'], $r['name'], $r['content'], $r['ttl'] ?? 1, ($r['proxied'] ?? false) ? 1 : 0, $r['priority'] ?? null, $r['id']]);
            }
            flash('success', 'DNS record updated.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to update DNS record: ' . $e->getMessage());
        }
        redirect("/domains/$domainId/dns");
    }
}
