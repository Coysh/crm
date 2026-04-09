<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use CoyshCRM\Models\ClientSite;
use CoyshCRM\Models\Domain;
use CoyshCRM\Models\Server;
use CoyshCRM\Services\PloiService;
use PDO;

class ClientSiteController
{
    private ClientSite $model;
    private Client $clientModel;
    private Domain $domainModel;
    private Server $serverModel;
    private PloiService $ploi;

    public function __construct(private PDO $db)
    {
        $this->model = new ClientSite($db);
        $this->clientModel = new Client($db);
        $this->domainModel = new Domain($db);
        $this->serverModel = new Server($db);
        $this->ploi = new PloiService($db);
    }

    public function create(int $clientId): void
    {
        $client = $this->clientModel->findById($clientId);
        if (!$client) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $site = ['client_id' => $clientId]; $errors = [];
        $domains = $this->domainModel->findByClient($clientId);
        $servers = $this->serverModel->findAll([], 'name');
        $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Add Site', null]];
        [$ploiConnected, $ploiSites] = $this->ploiSiteOptions(null, null);
        render('sites.form', compact('client', 'site', 'errors', 'domains', 'servers', 'breadcrumbs', 'ploiConnected', 'ploiSites'), 'Add Site');
    }

    public function store(int $clientId): void
    {
        $client = $this->clientModel->findById($clientId); if (!$client) { redirect('/clients'); return; }
        $data = $this->sanitise($_POST, $clientId); $errors = $this->validate($data);
        if ($errors) {
            $site = $data;
            $domains = $this->domainModel->findByClient($clientId);
            $servers = $this->serverModel->findAll([], 'name');
            $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Add Site', null]];
            [$ploiConnected, $ploiSites] = $this->ploiSiteOptions($data['server_id'] ?: null, null);
            render('sites.form', compact('client', 'site', 'errors', 'domains', 'servers', 'breadcrumbs', 'ploiConnected', 'ploiSites'), 'Add Site');
            return;
        }

        $id = $this->model->insert($data);
        $this->savePloiSiteLink($id, (int)($_POST['ploi_site_id'] ?? 0));
        flash('success', 'Site added.'); redirect("/clients/$clientId");
    }

    public function edit(int $clientId, int $id): void
    {
        $client = $this->clientModel->findById($clientId); $site = $this->model->findById($id);
        if (!$client || !$site) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $errors = [];
        $domains = $this->domainModel->findByClient($clientId);
        $servers = $this->serverModel->findAll([], 'name');
        $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Edit Site', null]];
        $currentPloi = $this->db->prepare("SELECT id FROM ploi_sites WHERE client_site_id = ? LIMIT 1"); $currentPloi->execute([$id]); $currentPloiId = $currentPloi->fetchColumn() ?: null;
        [$ploiConnected, $ploiSites] = $this->ploiSiteOptions($site['server_id'] ? (int)$site['server_id'] : null, $currentPloiId ? (int)$currentPloiId : null);
        render('sites.form', compact('client', 'site', 'errors', 'domains', 'servers', 'breadcrumbs', 'ploiConnected', 'ploiSites', 'currentPloiId'), 'Edit Site');
    }

    public function update(int $clientId, int $id): void
    {
        $client = $this->clientModel->findById($clientId); $site = $this->model->findById($id);
        if (!$client || !$site) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $data = $this->sanitise($_POST, $clientId); $errors = $this->validate($data);
        if ($errors) {
            $domains = $this->domainModel->findByClient($clientId);
            $servers = $this->serverModel->findAll([], 'name');
            $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Edit Site', null]];
            [$ploiConnected, $ploiSites] = $this->ploiSiteOptions($data['server_id'] ?: null, null);
            render('sites.form', compact('client', 'site', 'errors', 'domains', 'servers', 'breadcrumbs', 'ploiConnected', 'ploiSites'), 'Edit Site');
            return;
        }

        $this->model->update($id, $data);
        $this->savePloiSiteLink($id, (int)($_POST['ploi_site_id'] ?? 0));

        // Keep linked domain's client_id in sync with the site's client
        if ($data['client_id'] ?? null) {
            $stmt = $this->db->prepare("SELECT domain_id FROM client_sites WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $domainId = $stmt->fetchColumn();
            if ($domainId) {
                $this->db->prepare("UPDATE domains SET client_id = ? WHERE id = ?")->execute([$data['client_id'], $domainId]);
            }
        }

        flash('success', 'Site updated.'); redirect("/clients/$clientId");
    }

    public function destroy(int $clientId, int $id): void
    {
        // Before unlinking, add any linked Ploi site to exclusions
        try {
            $stmtPloi = $this->db->prepare("
                SELECT ps.ploi_id, ps.ploi_server_id, d.domain AS domain_name
                FROM ploi_sites ps
                LEFT JOIN client_sites cs ON cs.id = ps.client_site_id
                LEFT JOIN domains d ON d.id = cs.domain_id
                WHERE ps.client_site_id = ?
            ");
            $stmtPloi->execute([$id]);
            $ploiRows = $stmtPloi->fetchAll();
            foreach ($ploiRows as $ps) {
                try {
                    $this->db->prepare("
                        INSERT OR IGNORE INTO ploi_sync_exclusions (ploi_site_id, ploi_server_id, domain, reason)
                        VALUES (?, ?, ?, 'Deleted from CRM')
                    ")->execute([$ps['ploi_id'], $ps['ploi_server_id'], $ps['domain_name']]);
                } catch (\Throwable) {}
            }
        } catch (\Throwable) {}

        $this->db->prepare("UPDATE ploi_sites SET client_site_id = NULL WHERE client_site_id = ?")->execute([$id]);
        $this->model->delete($id); flash('success', 'Site removed.'); redirect("/clients/$clientId");
    }

    private function ploiSiteOptions(?int $crmServerId, ?int $currentPloiSiteId): array
    {
        $connected = $this->ploi->isConnected();
        if (!$connected) return [false, []];

        $ploiServerId = null;
        if ($crmServerId) {
            $stmt = $this->db->prepare("SELECT id FROM ploi_servers WHERE server_id = ? LIMIT 1");
            $stmt->execute([$crmServerId]);
            $ploiServerId = $stmt->fetchColumn() ?: null;
        }

        $sql = "SELECT * FROM ploi_sites WHERE (client_site_id IS NULL" . ($currentPloiSiteId ? " OR id = ?" : "") . ")";
        $params = $currentPloiSiteId ? [$currentPloiSiteId] : [];
        if ($ploiServerId) { $sql .= " AND ploi_server_id = ?"; $params[] = $ploiServerId; }
        $sql .= " ORDER BY domain";
        $stmt = $this->db->prepare($sql); $stmt->execute($params);
        return [true, $stmt->fetchAll()];
    }

    private function savePloiSiteLink(int $clientSiteId, int $ploiSiteId): void
    {
        $this->db->prepare("UPDATE ploi_sites SET client_site_id = NULL WHERE client_site_id = ?")->execute([$clientSiteId]);
        if ($ploiSiteId > 0) $this->db->prepare("UPDATE ploi_sites SET client_site_id = ? WHERE id = ?")->execute([$clientSiteId, $ploiSiteId]);
    }

    private function sanitise(array $post, int $clientId): array
    {
        return ['client_id' => $clientId, 'domain_id' => $post['domain_id'] ? (int)$post['domain_id'] : null, 'server_id' => $post['server_id'] ? (int)$post['server_id'] : null, 'website_stack' => trim($post['website_stack'] ?? ''), 'css_framework' => trim($post['css_framework'] ?? ''), 'smtp_service' => trim($post['smtp_service'] ?? ''), 'git_repo' => trim($post['git_repo'] ?? ''), 'has_deployment_pipeline' => isset($post['has_deployment_pipeline']) ? 1 : 0, 'notes' => trim($post['notes'] ?? '')];
    }

    private function validate(array $data): array { return []; }
}
