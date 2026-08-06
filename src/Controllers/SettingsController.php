<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\ExchangeRateService;
use CoyshCRM\Services\FreeAgentClient;
use CoyshCRM\Services\PloiService;
use CoyshCRM\Services\PloiSync;
use CoyshCRM\Services\WpmgrService;
use CoyshCRM\Services\WpmgrSync;
use PDO;

class SettingsController
{
    private FreeAgentClient $fa;
    private PloiService $ploi;
    private WpmgrService $wpmgr;

    public function __construct(private PDO $db)
    {
        $this->fa = new FreeAgentClient($db);
        $this->ploi = new PloiService($db);
        $this->wpmgr = new WpmgrService($db);
    }

    public function index(): void
    {
        $faCfg = $this->fa->getConfig();
        $connected = $this->fa->isConnected();
        $ploiCfg = $this->ploi->getConfig();
        $ploiConnected = $this->ploi->isConnected();

        $ploiStats = [
            'servers_total'    => (int)$this->db->query("SELECT COUNT(*) FROM ploi_servers")->fetchColumn(),
            'sites_total'      => (int)$this->db->query("SELECT COUNT(*) FROM ploi_sites")->fetchColumn(),
            'sites_linked'     => (int)$this->db->query(
                "SELECT COUNT(*) FROM ploi_sites ps
                 JOIN client_sites cs ON cs.id = ps.client_site_id
                 WHERE cs.client_id IS NOT NULL"
            )->fetchColumn(),
            'unlinked_sites'   => $this->db->query(
                "SELECT ps.domain FROM ploi_sites ps
                 LEFT JOIN client_sites cs ON cs.id = ps.client_site_id
                 WHERE cs.id IS NULL OR cs.client_id IS NULL
                 ORDER BY ps.domain LIMIT 8"
            )->fetchAll(PDO::FETCH_COLUMN),
            'last_error'       => $this->lastPloiError(),
        ];

        $wpmgrCfg       = $this->wpmgr->getConfig();
        $wpmgrConnected = $this->wpmgr->isConnected();
        $wpmgrStats = [
            'sites_total'  => (int)$this->db->query("SELECT COUNT(*) FROM wpmgr_sites")->fetchColumn(),
            'sites_linked' => (int)$this->db->query("SELECT COUNT(*) FROM wpmgr_sites WHERE client_site_id IS NOT NULL")->fetchColumn(),
            'last_error'   => $this->lastWpmgrError(),
        ];

        $fxSvc         = new ExchangeRateService($this->db);
        $exchangeRates = $fxSvc->getCurrentRates();

        $dataQualityIssues = DataQualityController::issueCount($this->db);

        render('settings.index', compact('faCfg', 'connected', 'ploiCfg', 'ploiConnected', 'ploiStats', 'wpmgrCfg', 'wpmgrConnected', 'wpmgrStats', 'exchangeRates', 'dataQualityIssues'), 'Settings');
    }

    public function refreshExchangeRates(): void
    {
        $svc   = new ExchangeRateService($this->db);
        $rates = $svc->fetchFromApi();
        if (!empty($rates)) {
            $parts = array_map(fn($c, $r) => "1 GBP = " . number_format($r, 4) . " $c", array_keys($rates), $rates);
            flash('success', 'Exchange rates updated: ' . implode(', ', $parts));
        } else {
            flash('error', 'Failed to fetch exchange rates from Frankfurter API. Using last cached rates.');
        }
        redirect('/settings');
    }

    public function freeagent(): void
    {
        $faCfg       = $this->fa->getConfig();
        $connected   = $this->fa->isConnected();
        $errors      = [];
        $redirectUri = $this->buildRedirectUri();
        $breadcrumbs = [['Settings', '/settings'], ['FreeAgent', null]];
        render('settings.freeagent', compact('faCfg', 'connected', 'errors', 'redirectUri', 'breadcrumbs'), 'FreeAgent Settings');
    }

    public function ploi(): void
    {
        $ploiCfg = $this->ploi->getConfig();
        $connected = $this->ploi->isConnected();
        $breadcrumbs = [['Settings', '/settings'], ['Ploi', null]];
        $lastError = $this->lastPloiError();

        $staleServers = $staleSites = $serverExclusions = $duplicateSites = [];
        $staleReport = ['servers' => [], 'sites' => []];
        try {
            $duplicateSites = (new PloiSync($this->db, $this->ploi))->duplicateSiteReport();
        } catch (\Throwable) {}
        try {
            $staleServers = $this->db->query("SELECT * FROM ploi_servers WHERE is_stale = 1 ORDER BY name")->fetchAll();
            $staleSites = $this->db->query("SELECT * FROM ploi_sites WHERE is_stale = 1 ORDER BY domain")->fetchAll();
            $serverExclusions = $this->db->query("SELECT * FROM ploi_server_exclusions ORDER BY created_at DESC")->fetchAll();
            $staleReport = (new PloiSync($this->db, $this->ploi))->staleReport();
        } catch (\Throwable) {}

        render('settings.ploi', compact('ploiCfg', 'connected', 'breadcrumbs', 'lastError', 'staleServers', 'staleSites', 'staleReport', 'duplicateSites', 'serverExclusions'), 'Ploi Settings');
    }

    /**
     * Latest undismissed sync failure since the last successful full sync.
     * Older failures are considered resolved once a full sync completes.
     */
    private function lastPloiError(): ?array
    {
        try {
            return $this->db->query(
                "SELECT * FROM ploi_sync_log
                 WHERE status = 'failed' AND COALESCE(dismissed, 0) = 0
                   AND started_at >= COALESCE(
                       (SELECT MAX(started_at) FROM ploi_sync_log
                        WHERE sync_type = 'full' AND status = 'completed'), '1970-01-01')
                 ORDER BY started_at DESC LIMIT 1"
            )->fetch() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function wpmgr(): void
    {
        $wpmgrCfg  = $this->wpmgr->getConfig();
        $connected = $this->wpmgr->isConnected();
        $breadcrumbs = [['Settings', '/settings'], ['WPMGR', null]];
        $lastError = $this->lastWpmgrError();

        $sites = [];
        try {
            $sites = $this->db->query("
                SELECT ws.*, d.domain AS client_site_domain
                FROM wpmgr_sites ws
                LEFT JOIN client_sites cs ON cs.id = ws.client_site_id
                LEFT JOIN domains d ON d.id = cs.domain_id
                ORDER BY ws.url
            ")->fetchAll();
        } catch (\Throwable) {}

        render('settings.wpmgr', compact('wpmgrCfg', 'connected', 'breadcrumbs', 'lastError', 'sites'), 'WPMGR Settings');
    }

    /**
     * Latest undismissed sync failure since the last successful full sync.
     * Older failures are considered resolved once a full sync completes.
     */
    private function lastWpmgrError(): ?array
    {
        try {
            return $this->db->query(
                "SELECT * FROM wpmgr_sync_log
                 WHERE status = 'failed' AND COALESCE(dismissed, 0) = 0
                   AND started_at >= COALESCE(
                       (SELECT MAX(started_at) FROM wpmgr_sync_log
                        WHERE sync_type = 'full' AND status = 'completed'), '1970-01-01')
                 ORDER BY started_at DESC LIMIT 1"
            )->fetch() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function saveWpmgr(): void
    {
        $baseUrl = trim($_POST['base_url'] ?? '');
        $apiKey  = trim($_POST['api_key'] ?? '');

        if (!$baseUrl) {
            flash('error', 'WPMGR base URL is required.');
            redirect('/settings/wpmgr');
        }
        if (!$apiKey) {
            if ($this->wpmgr->isConnected()) {
                $cfg = $this->wpmgr->getConfig();
                $this->wpmgr->saveConfig($baseUrl, $cfg['api_key']);
                flash('success', 'WPMGR settings saved (existing API key unchanged).');
            } else {
                flash('error', 'WPMGR API key is required.');
            }
            redirect('/settings/wpmgr');
        }

        $this->wpmgr->saveConfig($baseUrl, $apiKey);
        flash('success', 'WPMGR settings saved.');
        redirect('/settings/wpmgr');
    }

    public function testWpmgr(): void
    {
        try {
            $this->wpmgr->validateConnection();
            flash('success', 'WPMGR connection successful.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/settings/wpmgr');
    }

    public function syncWpmgr(): void
    {
        if (!$this->wpmgr->isConnected()) {
            flash('error', 'WPMGR is not connected.');
            redirect('/settings/wpmgr');
        }

        try {
            $sync = new WpmgrSync($this->db, $this->wpmgr);
            $results = $sync->fullSync();
            $msg = "WPMGR sync complete. Sites: {$results['sites']}";
            if (!empty($results['errors'])) {
                $msg .= ' (partial — ' . implode('; ', $results['errors']) . ')';
                flash('warning', $msg);
            } else {
                flash('success', $msg);
            }
        } catch (\Throwable $e) {
            flash('error', 'WPMGR sync failed: ' . $e->getMessage());
        }
        redirect('/settings/wpmgr');
    }

    public function disconnectWpmgr(): void
    {
        $this->wpmgr->disconnect();
        flash('success', 'WPMGR disconnected.');
        redirect('/settings/wpmgr');
    }

    public function dismissWpmgrError(): void
    {
        try {
            $this->db->exec("UPDATE wpmgr_sync_log SET dismissed = 1 WHERE status = 'failed' AND dismissed = 0");
            flash('success', 'Sync error dismissed.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to dismiss error: ' . $e->getMessage());
        }
        redirect('/settings/wpmgr');
    }

    /**
     * Manually create a client_sites record (+ domain, if new) for an
     * unmatched WPMGR site — e.g. a site the user manages via WPMGR but
     * doesn't host, so it has no Ploi counterpart to have already created
     * one. WpmgrSync itself never does this automatically (see its
     * docblock); this is an explicit, one-off action from the settings page.
     */
    public function createSiteFromWpmgr(int $id): void
    {
        $stmt = $this->db->prepare("SELECT * FROM wpmgr_sites WHERE id = ?");
        $stmt->execute([$id]);
        $wpmgrSite = $stmt->fetch();
        if (!$wpmgrSite) {
            flash('error', 'WPMGR site not found.');
            redirect('/settings/wpmgr');
        }
        if ($wpmgrSite['client_site_id']) {
            flash('info', 'This WPMGR site is already linked to a CRM site.');
            redirect('/settings/wpmgr');
        }

        $host = WpmgrSync::hostFromUrl($wpmgrSite['url']);
        if (!$host) {
            flash('error', 'Could not determine a domain from ' . $wpmgrSite['url'] . '.');
            redirect('/settings/wpmgr');
        }

        $domRow = $this->db->prepare("SELECT id FROM domains WHERE LOWER(domain) = LOWER(?) LIMIT 1");
        $domRow->execute([$host]);
        $domainId = $domRow->fetchColumn();
        if (!$domainId) {
            $this->db->prepare(
                "INSERT INTO domains (client_id, domain, cloudflare_proxied, created_at) VALUES (NULL, ?, 0, datetime('now'))"
            )->execute([$host]);
            $domainId = (int)$this->db->lastInsertId();
        }

        $this->db->prepare(
            "INSERT INTO client_sites (client_id, domain_id, notes, created_at) VALUES (NULL, ?, ?, datetime('now'))"
        )->execute([$domainId, 'Imported from WPMGR: ' . $wpmgrSite['url']]);
        $clientSiteId = (int)$this->db->lastInsertId();

        $this->db->prepare("UPDATE wpmgr_sites SET client_site_id = ? WHERE id = ?")->execute([$clientSiteId, $id]);

        flash('success', "Site created for $host. Assign a client to finish setup.");
        redirect("/sites/$clientSiteId/edit");
    }

    public function savePloi(): void
    {
        $token = trim($_POST['api_token'] ?? '');
        if (!$token) {
            if ($this->ploi->isConnected()) {
                flash('success', 'Existing Ploi token unchanged.');
            } else {
                flash('error', 'Ploi API token is required.');
            }
            redirect('/settings/ploi');
        }
        $this->ploi->saveToken($token);
        flash('success', 'Ploi token saved.');
        redirect('/settings/ploi');
    }

    public function dismissPloiError(): void
    {
        try {
            $this->db->exec("UPDATE ploi_sync_log SET dismissed = 1 WHERE status = 'failed' AND dismissed = 0");
            flash('success', 'Sync error dismissed.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to dismiss error: ' . $e->getMessage());
        }
        redirect('/settings/ploi');
    }

    public function purgeStalePloi(): void
    {
        try {
            $sync = new PloiSync($this->db, $this->ploi);
            $counts = $sync->purgeStale();
            flash('success', "Purged {$counts['servers']} stale server(s) and {$counts['sites']} stale site(s).");
        } catch (\Throwable $e) {
            flash('error', 'Failed to purge stale records: ' . $e->getMessage());
        }
        redirect('/settings/ploi');
    }

    /**
     * Clear up after a server was deleted in Ploi: move each old site's CRM
     * record onto the site that replaced it, or keep/delete it, then drop the
     * stale Ploi rows.
     */
    public function reconcileStalePloi(): void
    {
        if (!csrfCheck()) {
            flash('error', 'Your session expired. Please try again.');
            redirect('/settings/ploi');
        }

        $decisions = [];
        foreach ((array)($_POST['action'] ?? []) as $staleId => $action) {
            $staleId = (int)$staleId;
            if (!$staleId) continue;
            $decisions[$staleId] = [
                'action'    => in_array($action, ['transfer', 'keep', 'delete'], true) ? $action : 'keep',
                'successor' => (int)($_POST['successor'][$staleId] ?? 0),
            ];
        }

        try {
            $sync = new PloiSync($this->db, $this->ploi);
            $r = $sync->reconcileStale($decisions, !empty($_POST['remove_crm_servers']));

            $parts = [];
            if ($r['transferred'])   $parts[] = "{$r['transferred']} site(s) transferred to their new server";
            if ($r['deleted_sites']) $parts[] = "{$r['deleted_sites']} CRM site record(s) deleted";
            if ($r['kept'])          $parts[] = "{$r['kept']} CRM site record(s) left untouched";
            $parts[] = "{$r['ploi_sites']} stale Ploi site(s) and {$r['ploi_servers']} stale server(s) removed";
            if ($r['crm_servers'])   $parts[] = "{$r['crm_servers']} CRM server record(s) deleted";
            flash('success', ucfirst(implode(', ', $parts)) . '.');

            foreach ($r['notes'] as $note) {
                flash('error', $note);
            }
        } catch (\Throwable $e) {
            flash('error', 'Reconcile failed: ' . $e->getMessage());
        }
        redirect('/settings/ploi');
    }

    /**
     * Fold duplicate CRM site records back together after a server migration
     * whose stale Ploi rows were purged before the records could follow.
     */
    public function mergeDuplicatePloiSites(): void
    {
        if (!csrfCheck()) {
            flash('error', 'Your session expired. Please try again.');
            redirect('/settings/ploi');
        }

        $ids = array_map('intval', (array)($_POST['merge'] ?? []));
        $removeServers = !empty($_POST['remove_empty_servers']);

        if (!$ids && !$removeServers) {
            flash('error', 'Nothing selected to merge.');
            redirect('/settings/ploi');
        }

        try {
            $sync = new PloiSync($this->db, $this->ploi);
            $r = $sync->mergeDuplicateSites($ids, $removeServers);

            $parts = [];
            if ($r['merged'])  $parts[] = "{$r['merged']} duplicate site record(s) merged";
            if ($r['servers']) $parts[] = "{$r['servers']} unused CRM server record(s) deleted";
            flash($parts ? 'success' : 'error', $parts ? ucfirst(implode(' and ', $parts)) . '.' : 'Nothing was merged.');

            foreach ($r['notes'] as $note) {
                flash('warning', $note);
            }
        } catch (\Throwable $e) {
            flash('error', 'Merge failed: ' . $e->getMessage());
        }
        redirect('/settings/ploi');
    }

    public function excludePloiServer(int $ploiId): void
    {
        try {
            $name = null;
            $stmt = $this->db->prepare("SELECT name FROM ploi_servers WHERE ploi_id = ? LIMIT 1");
            $stmt->execute([$ploiId]);
            $name = $stmt->fetchColumn() ?: null;
            $this->db->prepare(
                "INSERT OR IGNORE INTO ploi_server_exclusions (ploi_server_id, name, reason) VALUES (?, ?, 'Excluded from settings')"
            )->execute([$ploiId, $name]);
            flash('success', 'Server excluded from future Ploi syncs.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to exclude server: ' . $e->getMessage());
        }
        redirect('/settings/ploi');
    }

    public function removePloiServerExclusion(int $id): void
    {
        try {
            $this->db->prepare("DELETE FROM ploi_server_exclusions WHERE id = ?")->execute([$id]);
            flash('success', 'Exclusion removed. Server will be included in future syncs.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to remove exclusion: ' . $e->getMessage());
        }
        redirect('/settings/ploi');
    }

    public function testPloi(): void
    {
        try {
            $result = $this->ploi->validateConnection();
            flash('success', 'Connected as ' . ($result['name'] ?? 'Unknown') . ' (' . ($result['email'] ?? 'no email') . ')');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/settings/ploi');
    }

    public function disconnectPloi(): void
    {
        $this->ploi->disconnect();
        flash('success', 'Ploi disconnected.');
        redirect('/settings/ploi');
    }

    public function syncPloiDomains(): void
    {
        try {
            $sync  = new PloiSync($this->db, $this->ploi);
            $count = $sync->syncDomains();
            flash('success', "Domain re-sync complete — $count domain(s) created or linked.");
        } catch (\Throwable $e) {
            flash('error', 'Domain sync failed: ' . $e->getMessage());
        }
        redirect('/settings/ploi');
    }

    public function syncPloi(): void
    {
        if (!$this->ploi->isConnected()) {
            flash('error', 'Ploi is not connected.');
            redirect('/settings/ploi');
        }

        try {
            $sync = new PloiSync($this->db, $this->ploi);
            $results = $sync->fullSync();
            $msg = "Ploi sync complete. Servers: {$results['servers']}, Sites: {$results['sites']}";
            if (!empty($results['errors'])) {
                $msg .= ' (partial — ' . implode('; ', $results['errors']) . ')';
                flash('warning', $msg);
            } else {
                flash('success', $msg);
            }

            // Point at the reconcile panel when a server disappeared from Ploi
            // and its sites turned up elsewhere.
            $report  = $sync->staleReport();
            $moved   = count(array_filter($report['sites'], fn($s) => !empty($s['candidates']) && !empty($s['client_site_id'])));
            $servers = count($report['servers']);
            if ($moved || $servers) {
                $bits = [];
                if ($servers) $bits[] = "$servers server(s)";
                if ($moved)   $bits[] = "$moved site(s) that now live on another server";
                flash('warning', 'Deleted in Ploi: ' . implode(' and ', $bits) . '. Review them below to transfer or remove their CRM records.');
            }
        } catch (\Throwable $e) {
            flash('error', 'Ploi sync failed: ' . $e->getMessage());
        }
        redirect('/settings/ploi');
    }

    public function saveFreeagent(): void
    {
        $clientId     = trim($_POST['client_id'] ?? '');
        $clientSecret = trim($_POST['client_secret'] ?? '');
        $useSandbox   = isset($_POST['use_sandbox']) ? 1 : 0;

        $errors = [];
        if (!$clientId)     $errors['client_id']     = 'Client ID is required.';
        if (!$clientSecret) $errors['client_secret'] = 'Client Secret is required.';

        if ($errors) {
            $faCfg       = $this->fa->getConfig();
            $connected   = $this->fa->isConnected();
            $redirectUri = $this->buildRedirectUri();
            $breadcrumbs = [['Settings', '/settings'], ['FreeAgent', null]];
            render('settings.freeagent', compact('faCfg', 'connected', 'errors', 'redirectUri', 'breadcrumbs'), 'FreeAgent Settings');
            return;
        }

        $encryptedSecret = \CoyshCRM\Services\Secrets::encrypt($clientSecret);
        $exists = $this->db->query("SELECT id FROM freeagent_config WHERE id = 1")->fetch();
        if ($exists) {
            $this->db->prepare("UPDATE freeagent_config SET client_id = ?, client_secret = ?, use_sandbox = ? WHERE id = 1")
                ->execute([$clientId, $encryptedSecret, $useSandbox]);
        } else {
            $this->db->prepare("INSERT INTO freeagent_config (id, client_id, client_secret, use_sandbox) VALUES (1, ?, ?, ?)")
                ->execute([$clientId, $encryptedSecret, $useSandbox]);
        }

        flash('success', 'FreeAgent credentials saved.');
        redirect('/settings/freeagent');
    }

    public function connect(): void { $redirectUri = $this->buildRedirectUri(); try { $url = $this->fa->buildAuthorizationUrl($redirectUri); redirect($url); } catch (\RuntimeException $e) { flash('error', 'Cannot connect: ' . $e->getMessage()); redirect('/settings/freeagent'); } }
    public function callback(): void { if (isset($_GET['error'])) { flash('error', 'FreeAgent denied access: ' . e($_GET['error_description'] ?? $_GET['error'])); redirect('/settings/freeagent'); return; } $code = $_GET['code'] ?? ''; if (!$code) { flash('error', 'No authorization code received from FreeAgent.'); redirect('/settings/freeagent'); return; } try { $this->fa->exchangeCodeForTokens($code, $this->buildRedirectUri()); flash('success', 'FreeAgent connected successfully.'); } catch (\RuntimeException $e) { flash('error', 'OAuth error: ' . $e->getMessage()); } redirect('/settings/freeagent'); }
    public function disconnect(): void { $this->fa->disconnect(); flash('success', 'FreeAgent disconnected.'); redirect('/settings/freeagent'); }

    public function contacts(): void
    {
        $contacts = $this->db->query("SELECT fc.*, c.name AS client_name FROM freeagent_contacts fc LEFT JOIN clients c ON c.id = fc.client_id ORDER BY fc.client_id IS NULL DESC, fc.name")->fetchAll();
        $clients = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();
        $stats = ['total' => count($contacts), 'auto' => count(array_filter($contacts, fn($r) => $r['client_id'] && $r['auto_matched'])), 'manual' => count(array_filter($contacts, fn($r) => $r['client_id'] && !$r['auto_matched'])), 'unmatched' => count(array_filter($contacts, fn($r) => !$r['client_id']))];

        // Contacts sharing an email address. Auto-matching used to key on email,
        // so these are the ones most likely mapped to the wrong client — and
        // several distinct businesses can end up funnelling into one.
        $emailCounts = [];
        foreach ($contacts as $c) {
            $e = strtolower(trim((string)($c['email'] ?? '')));
            if ($e !== '') $emailCounts[$e] = ($emailCounts[$e] ?? 0) + 1;
        }
        $sharedEmails = array_keys(array_filter($emailCounts, fn($n) => $n > 1));

        // Group the collisions so the page can show which contacts collide and
        // whether they've been funnelled onto a single client.
        $collisions = [];
        foreach ($sharedEmails as $e) {
            $group = array_values(array_filter($contacts, fn($c) => strtolower(trim((string)($c['email'] ?? ''))) === $e));
            $distinctClients = array_unique(array_filter(array_map(fn($c) => $c['client_id'], $group)));
            $collisions[$e] = ['contacts' => $group, 'distinct_clients' => count($distinctClients)];
        }
        $stats['shared_email'] = count(array_filter($collisions, fn($g) => $g['distinct_clients'] <= 1));
        $connected = $this->fa->isConnected();
        $breadcrumbs = [['Settings', '/settings'], ['FreeAgent', '/settings/freeagent'], ['Contact Mapping', null]];
        render('settings.fa_contacts', compact('contacts', 'clients', 'stats', 'connected', 'breadcrumbs', 'sharedEmails', 'collisions'), 'Contact Mapping');
    }

    public function saveContactMap(int $id): void
    {
        $clientId = $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : null;
        $this->db->prepare("UPDATE freeagent_contacts SET client_id = ?, auto_matched = 0 WHERE id = ?")->execute([$clientId, $id]);
        $contact = $this->db->prepare("SELECT freeagent_url FROM freeagent_contacts WHERE id = ?");
        $contact->execute([$id]);
        if ($row = $contact->fetch()) {
            $this->db->prepare("UPDATE freeagent_invoices SET client_id = ? WHERE freeagent_contact_url = ?")
                ->execute([$clientId, $row['freeagent_url']]);
            // Recurring invoices too, otherwise re-pointing a contact moves its
            // invoices but strands its MRR on the old client until a full sync.
            $this->db->prepare("UPDATE freeagent_recurring_invoices SET client_id = ? WHERE freeagent_contact_url = ?")
                ->execute([$clientId, $row['freeagent_url']]);
        }
        flash('success', 'Contact mapping saved. Invoices and recurring income re-pointed.');
        redirect('/settings/freeagent/contacts');
    }

    public function createClientFromContact(int $id): void
    {
        $stmt = $this->db->prepare("SELECT * FROM freeagent_contacts WHERE id = ?");
        $stmt->execute([$id]);
        $contact = $stmt->fetch();
        if (!$contact) { header('Content-Type: application/json'); echo json_encode(['error' => 'Contact not found']); exit; }

        $name = $contact['organisation_name'] ?: ($contact['name'] ?? 'Unknown');
        $this->db->prepare("INSERT INTO clients (name, contact_email, status, created_at, updated_at) VALUES (?, ?, 'active', datetime('now'), datetime('now'))")
            ->execute([$name, $contact['email']]);
        $clientId = (int)$this->db->lastInsertId();
        $this->db->prepare("UPDATE freeagent_contacts SET client_id = ?, auto_matched = 0 WHERE id = ?")->execute([$clientId, $id]);
        $this->db->prepare("UPDATE freeagent_invoices SET client_id = ? WHERE freeagent_contact_url = ?")->execute([$clientId, $contact['freeagent_url']]);
        $this->db->prepare("UPDATE freeagent_recurring_invoices SET client_id = ? WHERE freeagent_contact_url = ?")->execute([$clientId, $contact['freeagent_url']]);
        header('Content-Type: application/json'); echo json_encode(['ok' => true, 'client_id' => $clientId, 'client_name' => $name]); exit;
    }

    public function rematchContacts(): void { $sync = new \CoyshCRM\Services\FreeAgentSync($this->db, $this->fa); $matched = $sync->rematchContacts(); flash('success', "Re-match complete — $matched contact(s) matched."); redirect('/settings/freeagent/contacts'); }

    public function createClientsForUnmatched(): void { $sync = new \CoyshCRM\Services\FreeAgentSync($this->db, $this->fa); $count = $sync->createClientsForUnmatched(); flash('success', "Created $count client(s) for unmatched FreeAgent contacts."); redirect('/settings/freeagent/contacts'); }

    public function categories(): void
    {
        $faIncomeCategories = $this->db->query("SELECT DISTINCT category AS fa_category FROM freeagent_invoices WHERE category IS NOT NULL ORDER BY category")->fetchAll(\PDO::FETCH_COLUMN);
        $faExpenseCategories = $this->db->query("SELECT DISTINCT freeagent_category_display AS fa_category FROM freeagent_bank_transactions WHERE freeagent_category_display IS NOT NULL ORDER BY freeagent_category_display")->fetchAll(\PDO::FETCH_COLUMN);
        $mappings = $this->db->query("SELECT freeagent_category, local_category, type FROM freeagent_category_mappings")->fetchAll();
        $mappingIndex = []; foreach ($mappings as $m) $mappingIndex[$m['type'] . ':' . $m['freeagent_category']] = $m['local_category'];
        $incomeCategories = \CoyshCRM\Models\Project::incomeCategories();
        $expenseCategories = \CoyshCRM\Models\Expense::categories();
        $connected = $this->fa->isConnected();
        $breadcrumbs = [['Settings', '/settings'], ['FreeAgent', '/settings/freeagent'], ['Category Mapping', null]];
        render('settings.fa_categories', compact('faIncomeCategories', 'faExpenseCategories', 'mappingIndex', 'incomeCategories', 'expenseCategories', 'connected', 'breadcrumbs'), 'Category Mapping');
    }

    public function saveCategories(): void
    {
        $incomeMappings = $_POST['income'] ?? []; $expenseMappings = $_POST['expense'] ?? [];
        foreach ($incomeMappings as $faCategory => $localCategory) $this->upsertCategoryMapping($faCategory, $localCategory ?: null, 'income');
        foreach ($expenseMappings as $faCategory => $localCategory) {
            $this->upsertCategoryMapping($faCategory, $localCategory ?: null, 'expense');
            if ($localCategory) $this->db->prepare("UPDATE freeagent_bank_transactions SET crm_category = ? WHERE freeagent_category_display = ?")->execute([$localCategory, $faCategory]);
        }
        flash('success', 'Category mappings saved.');
        redirect('/settings/freeagent/categories');
    }

    private function upsertCategoryMapping(string $faCategory, ?string $local, string $type): void
    {
        $existing = $this->db->prepare("SELECT id FROM freeagent_category_mappings WHERE freeagent_category = ? AND type = ?");
        $existing->execute([$faCategory, $type]);
        $row = $existing->fetch();

        if ($local === null) {
            // No mapping selected — remove any existing row
            if ($row) {
                $this->db->prepare("DELETE FROM freeagent_category_mappings WHERE freeagent_category = ? AND type = ?")->execute([$faCategory, $type]);
            }
            return;
        }

        if ($row) {
            $this->db->prepare("UPDATE freeagent_category_mappings SET local_category = ? WHERE freeagent_category = ? AND type = ?")->execute([$local, $faCategory, $type]);
        } else {
            $this->db->prepare("INSERT INTO freeagent_category_mappings (freeagent_category, local_category, type) VALUES (?, ?, ?)")->execute([$faCategory, $local, $type]);
        }
    }

    public function deletionLog(): void
    {
        try {
            $rows = $this->db->query("SELECT * FROM deletion_log ORDER BY deleted_at DESC LIMIT 500")->fetchAll();
        } catch (\Throwable) {
            $rows = [];
        }
        $breadcrumbs = [['Settings', '/settings'], ['Deletion Log', null]];
        render('settings.deletion_log', compact('rows', 'breadcrumbs'), 'Deletion Log');
    }

    public function removePloiExclusion(int $id): void
    {
        try {
            $this->db->prepare("DELETE FROM ploi_sync_exclusions WHERE id = ?")->execute([$id]);
            flash('success', 'Exclusion removed. Site will be included in future syncs.');
        } catch (\Throwable $e) {
            flash('error', 'Failed to remove exclusion: ' . $e->getMessage());
        }
        redirect('/settings/ploi');
    }

    public function mcp(): void
    {
        $clients = $tokens = [];
        try {
            $clients = $this->db->query("
                SELECT oc.*,
                       (SELECT COUNT(*) FROM oauth_tokens ot WHERE ot.client_id = oc.client_id AND ot.token_type = 'refresh' AND ot.revoked = 0 AND ot.expires_at > datetime('now')) AS active_grants,
                       (SELECT MAX(ot.last_used_at) FROM oauth_tokens ot WHERE ot.client_id = oc.client_id) AS last_used_at
                FROM oauth_clients oc ORDER BY oc.created_at DESC
            ")->fetchAll();
            $tokens = $this->db->query("
                SELECT family_id, client_id, MIN(created_at) AS granted_at, MAX(last_used_at) AS last_used_at,
                       SUM(CASE WHEN revoked = 0 AND expires_at > datetime('now') THEN 1 ELSE 0 END) AS live_tokens
                FROM oauth_tokens GROUP BY family_id, client_id
                HAVING live_tokens > 0 ORDER BY granted_at DESC
            ")->fetchAll();
        } catch (\Throwable) {}

        $mcpUrl = appUrl() . '/mcp';
        $breadcrumbs = [['Settings', '/settings'], ['MCP Access', null]];
        render('settings.mcp', compact('clients', 'tokens', 'mcpUrl', 'breadcrumbs'), 'MCP Access');
    }

    public function revokeMcpClient(int $id): void
    {
        if (!csrfCheck()) { flash('error', 'Invalid form token — please try again.'); redirect('/settings/mcp'); }
        try {
            $stmt = $this->db->prepare("SELECT client_id, client_name FROM oauth_clients WHERE id = ?");
            $stmt->execute([$id]);
            if ($row = $stmt->fetch()) {
                (new \CoyshCRM\Services\OAuthService($this->db))->revokeClient($row['client_id']);
                flash('success', "Revoked access for '" . ($row['client_name'] ?: $row['client_id']) . "'.");
            }
        } catch (\Throwable $e) {
            flash('error', 'Failed to revoke: ' . $e->getMessage());
        }
        redirect('/settings/mcp');
    }

    private function buildRedirectUri(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
        return "$scheme://$host/settings/freeagent/callback";
    }
}
