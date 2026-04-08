<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\FreeAgentClient;
use CoyshCRM\Services\PloiService;
use CoyshCRM\Services\PloiSync;
use PDO;

class SettingsController
{
    private FreeAgentClient $fa;
    private PloiService $ploi;

    public function __construct(private PDO $db)
    {
        $this->fa = new FreeAgentClient($db);
        $this->ploi = new PloiService($db);
    }

    public function index(): void
    {
        $faCfg = $this->fa->getConfig();
        $connected = $this->fa->isConnected();
        $ploiCfg = $this->ploi->getConfig();
        $ploiConnected = $this->ploi->isConnected();

        $ploiStats = [
            'servers_total' => (int)$this->db->query("SELECT COUNT(*) FROM ploi_servers")->fetchColumn(),
            'servers_linked' => (int)$this->db->query("SELECT COUNT(*) FROM ploi_servers WHERE server_id IS NOT NULL")->fetchColumn(),
            'sites_total' => (int)$this->db->query("SELECT COUNT(*) FROM ploi_sites")->fetchColumn(),
            'sites_linked' => (int)$this->db->query("SELECT COUNT(*) FROM ploi_sites WHERE client_site_id IS NOT NULL")->fetchColumn(),
            'unlinked_servers' => $this->db->query("SELECT name FROM ploi_servers WHERE server_id IS NULL ORDER BY name LIMIT 8")->fetchAll(PDO::FETCH_COLUMN),
            'unlinked_sites' => $this->db->query("SELECT domain FROM ploi_sites WHERE client_site_id IS NULL ORDER BY domain LIMIT 8")->fetchAll(PDO::FETCH_COLUMN),
            'last_error' => $this->db->query("SELECT * FROM ploi_sync_log WHERE status = 'failed' ORDER BY started_at DESC LIMIT 1")->fetch() ?: null,
        ];

        render('settings.index', compact('faCfg', 'connected', 'ploiCfg', 'ploiConnected', 'ploiStats'), 'Settings');
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
        $lastError = $this->db->query("SELECT * FROM ploi_sync_log WHERE status = 'failed' ORDER BY started_at DESC LIMIT 1")->fetch() ?: null;
        render('settings.ploi', compact('ploiCfg', 'connected', 'breadcrumbs', 'lastError'), 'Ploi Settings');
    }

    public function savePloi(): void
    {
        $token = trim($_POST['api_token'] ?? '');
        if (!$token) {
            flash('error', 'Ploi API token is required.');
            redirect('/settings/ploi');
        }
        $this->ploi->saveToken($token);
        flash('success', 'Ploi token saved.');
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

    public function syncPloi(): void
    {
        if (!$this->ploi->isConnected()) {
            flash('error', 'Ploi is not connected.');
            redirect('/settings/ploi');
        }

        try {
            $sync = new PloiSync($this->db, $this->ploi);
            $results = $sync->fullSync();
            flash('success', "Ploi sync complete. Servers: {$results['servers']}, Sites: {$results['sites']}");
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

        $exists = $this->db->query("SELECT id FROM freeagent_config WHERE id = 1")->fetch();
        if ($exists) {
            $this->db->prepare("UPDATE freeagent_config SET client_id = ?, client_secret = ?, use_sandbox = ? WHERE id = 1")
                ->execute([$clientId, $clientSecret, $useSandbox]);
        } else {
            $this->db->prepare("INSERT INTO freeagent_config (id, client_id, client_secret, use_sandbox) VALUES (1, ?, ?, ?)")
                ->execute([$clientId, $clientSecret, $useSandbox]);
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
        $connected = $this->fa->isConnected();
        $breadcrumbs = [['Settings', '/settings'], ['FreeAgent', '/settings/freeagent'], ['Contact Mapping', null]];
        render('settings.fa_contacts', compact('contacts', 'clients', 'stats', 'connected', 'breadcrumbs'), 'Contact Mapping');
    }

    public function saveContactMap(int $id): void
    {
        $clientId = $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : null;
        $this->db->prepare("UPDATE freeagent_contacts SET client_id = ?, auto_matched = 0 WHERE id = ?")->execute([$clientId, $id]);
        $contact = $this->db->prepare("SELECT freeagent_url FROM freeagent_contacts WHERE id = ?");
        $contact->execute([$id]);
        if ($row = $contact->fetch()) $this->db->prepare("UPDATE freeagent_invoices SET client_id = ? WHERE freeagent_contact_url = ?")->execute([$clientId, $row['freeagent_url']]);
        flash('success', 'Contact mapping saved.');
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
        header('Content-Type: application/json'); echo json_encode(['ok' => true, 'client_id' => $clientId, 'client_name' => $name]); exit;
    }

    public function rematchContacts(): void { $sync = new \CoyshCRM\Services\FreeAgentSync($this->db, $this->fa); $matched = $sync->rematchContacts(); flash('success', "Re-match complete — $matched contact(s) matched."); redirect('/settings/freeagent/contacts'); }

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
        if ($existing->fetch()) {
            $this->db->prepare("UPDATE freeagent_category_mappings SET local_category = ? WHERE freeagent_category = ? AND type = ?")->execute([$local, $faCategory, $type]);
        } else {
            $this->db->prepare("INSERT INTO freeagent_category_mappings (freeagent_category, local_category, type) VALUES (?, ?, ?)")->execute([$faCategory, $local, $type]);
        }
    }

    private function buildRedirectUri(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8080';
        return "$scheme://$host/settings/freeagent/callback";
    }
}
