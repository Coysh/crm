<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Server;
use PDO;

class ServerController
{
    private Server $model;

    public function __construct(private PDO $db)
    {
        $this->model = new Server($db);
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
        $breadcrumbs = [['Servers', '/servers'], ['Add Server', null]];
        render('servers.form', compact('server', 'errors', 'breadcrumbs'), 'Add Server');
    }

    public function store(): void
    {
        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            $server      = $data;
            $breadcrumbs = [['Servers', '/servers'], ['Add Server', null]];
            render('servers.form', compact('server', 'errors', 'breadcrumbs'), 'Add Server');
            return;
        }

        $this->model->insert($data);
        flash('success', "Server '{$data['name']}' created.");
        redirect('/servers');
    }

    public function edit(int $id): void
    {
        $server = $this->model->findById($id);
        if (!$server) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $errors      = [];
        $breadcrumbs = [['Servers', '/servers'], ['Edit ' . $server['name'], null]];
        render('servers.form', compact('server', 'errors', 'breadcrumbs'), 'Edit ' . $server['name']);
    }

    public function update(int $id): void
    {
        $server = $this->model->findById($id);
        if (!$server) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            $breadcrumbs = [['Servers', '/servers'], ['Edit ' . $server['name'], null]];
            render('servers.form', compact('server', 'errors', 'breadcrumbs'), 'Edit ' . $server['name']);
            return;
        }

        $this->model->update($id, $data);
        flash('success', "Server '{$data['name']}' updated.");
        redirect('/servers');
    }

    public function destroy(int $id): void
    {
        $server = $this->model->findById($id);
        if ($server) {
            $this->model->delete($id);
            flash('success', "Server '{$server['name']}' deleted.");
        }
        redirect('/servers');
    }

    private function sanitise(array $post): array
    {
        return [
            'name'         => trim($post['name'] ?? ''),
            'provider'     => trim($post['provider'] ?? ''),
            'monthly_cost' => (float)($post['monthly_cost'] ?? 0),
            'notes'        => trim($post['notes'] ?? ''),
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!$data['name']) $errors['name'] = 'Server name is required.';
        if ($data['monthly_cost'] < 0) $errors['monthly_cost'] = 'Monthly cost cannot be negative.';
        return $errors;
    }
}
