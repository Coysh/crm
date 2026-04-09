<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Server;
use CoyshCRM\Services\PloiService;
use PDO;

class ServerController
{
    private Server $model;
    private PloiService $ploi;

    public function __construct(private PDO $db)
    {
        $this->model = new Server($db);
        $this->ploi  = new PloiService($db);
    }

    public function index(): void
    {
        $servers = $this->model->findAllWithStats();
        render('servers.index', compact('servers'), 'Servers');
    }

    public function create(): void
    {
        $server      = [];
        $errors      = [];
        $linkedCost  = null;
        $breadcrumbs = [['Servers', '/servers'], ['Add Server', null]];
        $ploiConnected = $this->ploi->isConnected();
        $ploiOptions   = [];
        $ploiData      = null;
        render('servers.form', compact('server', 'errors', 'linkedCost', 'breadcrumbs', 'ploiConnected', 'ploiOptions', 'ploiData'), 'Add Server');
    }

    public function store(): void
    {
        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);
        if ($errors) {
            $server      = $data;
            $linkedCost  = null;
            $breadcrumbs = [['Servers', '/servers'], ['Add Server', null]];
            $ploiConnected = $this->ploi->isConnected();
            $ploiOptions   = [];
            $ploiData      = null;
            render('servers.form', compact('server', 'errors', 'linkedCost', 'breadcrumbs', 'ploiConnected', 'ploiOptions', 'ploiData'), 'Add Server');
            return;
        }

        $id = $this->model->insert($data);
        $this->savePloiLink($id, (int)($_POST['ploi_server_id'] ?? 0));
        flash('success', "Server '{$data['name']}' created.");
        redirect('/servers/' . $id . '/edit');
    }

    public function edit(int $id): void
    {
        $server = $this->model->findById($id);
        if (!$server) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $errors      = [];
        $linkedCost  = $this->model->getLinkedRecurringCost($id);
        $breadcrumbs = [['Servers', '/servers'], ['Edit ' . $server['name'], null]];
        $ploiConnected = $this->ploi->isConnected();
        $ploiOptions   = $this->model->availablePloiServers($id);
        $ploiData      = $this->model->findPloiLinkInfo($id);
        render('servers.form', compact('server', 'errors', 'linkedCost', 'breadcrumbs', 'ploiConnected', 'ploiOptions', 'ploiData'), 'Edit ' . $server['name']);
    }

    public function update(int $id): void
    {
        $server = $this->model->findById($id);
        if (!$server) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);
        if ($errors) {
            $linkedCost  = $this->model->getLinkedRecurringCost($id);
            $breadcrumbs = [['Servers', '/servers'], ['Edit ' . $server['name'], null]];
            $ploiConnected = $this->ploi->isConnected();
            $ploiOptions   = $this->model->availablePloiServers($id);
            $ploiData      = $this->model->findPloiLinkInfo($id);
            render('servers.form', compact('server', 'errors', 'linkedCost', 'breadcrumbs', 'ploiConnected', 'ploiOptions', 'ploiData'), 'Edit ' . $server['name']);
            return;
        }

        $this->model->update($id, $data);
        $this->savePloiLink($id, (int)($_POST['ploi_server_id'] ?? 0));
        flash('success', "Server '{$data['name']}' updated.");
        redirect('/servers/' . $id . '/edit');
    }

    public function destroy(int $id): void
    {
        $server = $this->model->findById($id);
        if ($server) {
            $this->db->prepare("UPDATE ploi_servers SET server_id = NULL WHERE server_id = ?")->execute([$id]);
            $this->db->prepare("UPDATE client_sites SET server_id = NULL WHERE server_id = ?")->execute([$id]);
            $this->db->prepare("UPDATE expenses SET server_id = NULL WHERE server_id = ?")->execute([$id]);
            // Delink any recurring costs linked to this server (don't delete, just delink)
            $this->db->prepare("UPDATE recurring_costs SET server_id = NULL WHERE server_id = ?")->execute([$id]);
            $this->model->delete($id);
            flash('success', "Server '{$server['name']}' deleted.");
        }
        redirect('/servers');
    }

    private function savePloiLink(int $serverId, int $ploiServerId): void
    {
        $this->db->prepare("UPDATE ploi_servers SET server_id = NULL WHERE server_id = ?")->execute([$serverId]);
        if ($ploiServerId > 0) {
            $this->db->prepare("UPDATE ploi_servers SET server_id = ? WHERE id = ?")->execute([$serverId, $ploiServerId]);
        }
    }

    private function sanitise(array $post): array
    {
        return [
            'name'     => trim($post['name'] ?? ''),
            'provider' => trim($post['provider'] ?? ''),
            'notes'    => trim($post['notes'] ?? ''),
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!$data['name']) $errors['name'] = 'Server name is required.';
        return $errors;
    }
}
