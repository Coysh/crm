<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use CoyshCRM\Models\ServicePackage;
use PDO;

class ServicePackageController
{
    private ServicePackage $model;
    private Client         $clientModel;

    public function __construct(private PDO $db)
    {
        $this->model       = new ServicePackage($db);
        $this->clientModel = new Client($db);
    }

    public function create(int $clientId): void
    {
        $client = $this->clientModel->findById($clientId);
        if (!$client) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $package     = ['client_id' => $clientId, 'is_active' => 1, 'billing_cycle' => 'monthly'];
        $errors      = [];
        $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Add Package', null]];
        render('packages.form', compact('client', 'package', 'errors', 'breadcrumbs'), 'Add Package');
    }

    public function store(int $clientId): void
    {
        $client = $this->clientModel->findById($clientId);
        if (!$client) { redirect('/clients'); return; }

        $data   = $this->sanitise($_POST, $clientId);
        $errors = $this->validate($data);

        if ($errors) {
            $package     = $data;
            $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Add Package', null]];
            render('packages.form', compact('client', 'package', 'errors', 'breadcrumbs'), 'Add Package');
            return;
        }

        $this->model->insert($data);
        flash('success', "Package '{$data['name']}' added.");
        redirect("/clients/$clientId");
    }

    public function edit(int $clientId, int $id): void
    {
        $client  = $this->clientModel->findById($clientId);
        $package = $this->model->findById($id);
        if (!$client || !$package) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $errors      = [];
        $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Edit Package', null]];
        render('packages.form', compact('client', 'package', 'errors', 'breadcrumbs'), 'Edit Package');
    }

    public function update(int $clientId, int $id): void
    {
        $client  = $this->clientModel->findById($clientId);
        $package = $this->model->findById($id);
        if (!$client || !$package) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $data   = $this->sanitise($_POST, $clientId);
        $errors = $this->validate($data);

        if ($errors) {
            $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Edit Package', null]];
            render('packages.form', compact('client', 'package', 'errors', 'breadcrumbs'), 'Edit Package');
            return;
        }

        $this->model->update($id, $data);
        flash('success', "Package '{$data['name']}' updated.");
        redirect("/clients/$clientId");
    }

    public function destroy(int $clientId, int $id): void
    {
        $package = $this->model->findById($id);
        if ($package) {
            $this->model->delete($id);
            flash('success', "Package '{$package['name']}' deleted.");
        }
        redirect("/clients/$clientId");
    }

    private function sanitise(array $post, int $clientId): array
    {
        return [
            'client_id'     => $clientId,
            'name'          => trim($post['name'] ?? ''),
            'fee'           => (float)($post['fee'] ?? 0),
            'billing_cycle' => in_array($post['billing_cycle'] ?? '', ['monthly', 'annual']) ? $post['billing_cycle'] : 'monthly',
            'renewal_date'  => $post['renewal_date'] ?: null,
            'notes'         => trim($post['notes'] ?? ''),
            'is_active'     => isset($post['is_active']) ? 1 : 0,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!$data['name']) $errors['name'] = 'Package name is required.';
        if ($data['fee'] < 0) $errors['fee'] = 'Fee cannot be negative.';
        return $errors;
    }
}
