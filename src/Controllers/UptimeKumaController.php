<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\UptimeKumaService;
use CoyshCRM\Services\UptimeKumaSync;
use PDO;

class UptimeKumaController
{
    private UptimeKumaService $kuma;

    public function __construct(private PDO $db)
    {
        $this->kuma = new UptimeKumaService($db);
    }

    public function index(): void
    {
        $kumaCfg   = $this->kuma->getConfig();
        $connected = $this->kuma->isConnected();
        $lastError = self::lastError($this->db);

        // Every query below tolerates a not-yet-migrated DB so the page still renders.
        $monitors = [];
        try {
            $monitors = $this->db->query("
                SELECT m.*, d.domain AS client_site_domain, c.name AS client_name
                FROM uptime_kuma_monitors m
                LEFT JOIN client_sites cs ON cs.id = m.client_site_id
                LEFT JOIN domains d       ON d.id  = cs.domain_id
                LEFT JOIN clients c       ON c.id  = cs.client_id
                ORDER BY m.is_stale, LOWER(m.monitor_name)
            ")->fetchAll();
        } catch (\Throwable) {}

        // Candidates for a manual link — active sites only, labelled by domain.
        $siteOptions = [];
        try {
            $siteOptions = $this->db->query("
                SELECT cs.id, COALESCE(d.domain, 'Site #' || cs.id) AS label, c.name AS client_name
                FROM client_sites cs
                LEFT JOIN domains d ON d.id = cs.domain_id
                LEFT JOIN clients c ON c.id = cs.client_id
                WHERE COALESCE(cs.status, 'active') = 'active'
                ORDER BY LOWER(COALESCE(d.domain, ''))
            ")->fetchAll();
        } catch (\Throwable) {}

        $breadcrumbs = [['Settings', '/settings'], ['Uptime Kuma', null]];
        render('settings.uptime_kuma', compact('kumaCfg', 'connected', 'lastError', 'monitors', 'siteOptions', 'breadcrumbs'), 'Uptime Kuma Settings');
    }

    public function save(): void
    {
        $this->guard();

        $baseUrl = trim($_POST['base_url'] ?? '');
        $apiKey  = trim($_POST['api_key'] ?? '');

        if (!$baseUrl) {
            flash('error', 'Uptime Kuma base URL is required.');
            redirect('/settings/uptime-kuma');
        }

        // Blank key means "keep the existing one" — re-save the decrypted value
        // so saveConfig() re-encrypts it under the current key.
        if (!$apiKey) {
            if ($this->kuma->isConnected()) {
                $cfg = $this->kuma->getConfig();
                $this->kuma->saveConfig($baseUrl, $cfg['api_key']);
                flash('success', 'Uptime Kuma settings saved (existing API key unchanged).');
            } else {
                flash('error', 'Uptime Kuma API key is required.');
            }
            redirect('/settings/uptime-kuma');
        }

        $this->kuma->saveConfig($baseUrl, $apiKey);
        flash('success', 'Uptime Kuma settings saved.');
        redirect('/settings/uptime-kuma');
    }

    public function test(): void
    {
        $this->guard();

        try {
            $result = $this->kuma->validateConnection();
            flash('success', "Uptime Kuma connection successful — {$result['monitors']} monitor(s) reporting.");
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/settings/uptime-kuma');
    }

    public function sync(): void
    {
        $this->guard();

        if (!$this->kuma->isConnected()) {
            flash('error', 'Uptime Kuma is not connected.');
            redirect('/settings/uptime-kuma');
        }

        try {
            $results = (new UptimeKumaSync($this->db, $this->kuma))->fullSync();
            $msg = "Uptime Kuma sync complete. Monitors: {$results['monitors']}";
            if (!empty($results['errors'])) {
                flash('warning', $msg . ' (partial — ' . implode('; ', $results['errors']) . ')');
            } else {
                flash('success', $msg);
            }
        } catch (\Throwable $e) {
            flash('error', 'Uptime Kuma sync failed: ' . $e->getMessage());
        }
        redirect('/settings/uptime-kuma');
    }

    public function disconnect(): void
    {
        $this->guard();

        $this->kuma->disconnect();
        flash('success', 'Uptime Kuma disconnected.');
        redirect('/settings/uptime-kuma');
    }

    public function dismissError(): void
    {
        $this->guard();

        try {
            $this->db->exec("UPDATE uptime_kuma_sync_log SET dismissed = 1 WHERE status = 'failed' AND dismissed = 0");
            flash('success', 'Sync error dismissed.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to dismiss error: ' . $e->getMessage());
        }
        redirect('/settings/uptime-kuma');
    }

    /**
     * Hand-link a monitor to a CRM site. link_is_manual stops the next sync
     * re-resolving it by domain — necessary because Uptime Kuma monitor names
     * and URLs are freeform and often won't match a domain at all.
     */
    public function link(int $id): void
    {
        $this->guard();

        $siteId = (int)($_POST['client_site_id'] ?? 0);
        if ($siteId <= 0) {
            flash('error', 'Choose a site to link.');
            redirect('/settings/uptime-kuma');
        }

        $exists = $this->db->prepare("SELECT 1 FROM client_sites WHERE id = ?");
        $exists->execute([$siteId]);
        if (!$exists->fetchColumn()) {
            flash('error', 'That site no longer exists.');
            redirect('/settings/uptime-kuma');
        }

        $this->db->prepare("UPDATE uptime_kuma_monitors SET client_site_id = ?, link_is_manual = 1 WHERE id = ?")
            ->execute([$siteId, $id]);

        flash('success', 'Monitor linked.');
        redirect('/settings/uptime-kuma');
    }

    /** Clears the link and the manual flag, so the next sync re-resolves by domain. */
    public function unlink(int $id): void
    {
        $this->guard();

        $this->db->prepare("UPDATE uptime_kuma_monitors SET client_site_id = NULL, link_is_manual = 0 WHERE id = ?")
            ->execute([$id]);

        flash('success', 'Monitor unlinked — the next sync will try to match it by domain.');
        redirect('/settings/uptime-kuma');
    }

    /**
     * Latest undismissed sync failure since the last successful full sync.
     * Older failures are considered resolved once a full sync completes.
     * Static so SettingsController::index() can reuse it for the settings card.
     */
    public static function lastError(PDO $db): ?array
    {
        try {
            return $db->query(
                "SELECT * FROM uptime_kuma_sync_log
                 WHERE status = 'failed' AND COALESCE(dismissed, 0) = 0
                   AND started_at >= COALESCE(
                       (SELECT MAX(started_at) FROM uptime_kuma_sync_log
                        WHERE sync_type = 'full' AND status = 'completed'), '1970-01-01')
                 ORDER BY started_at DESC LIMIT 1"
            )->fetch() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function guard(): void
    {
        if (!csrfCheck()) {
            flash('error', 'Invalid form token — please try again.');
            redirect('/settings/uptime-kuma');
        }
    }
}
