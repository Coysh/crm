<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use CoyshCRM\Models\Domain;
use PDO;

class DomainController
{
    private Domain $model;
    private Client $clientModel;

    public function __construct(private PDO $db)
    {
        $this->model       = new Domain($db);
        $this->clientModel = new Client($db);
    }

    public function create(int $clientId): void
    {
        $client = $this->clientModel->findById($clientId);
        if (!$client) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $domain      = ['client_id' => $clientId];
        $errors      = [];
        $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Add Domain', null]];
        render('domains.form', compact('client', 'domain', 'errors', 'breadcrumbs'), 'Add Domain');
    }

    public function store(int $clientId): void
    {
        $client = $this->clientModel->findById($clientId);
        if (!$client) { redirect('/clients'); return; }

        $data   = $this->sanitise($_POST, $clientId);
        $errors = $this->validate($data);

        if ($errors) {
            $domain      = $data;
            $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Add Domain', null]];
            render('domains.form', compact('client', 'domain', 'errors', 'breadcrumbs'), 'Add Domain');
            return;
        }

        $this->model->insert($data);
        flash('success', "Domain '{$data['domain']}' added.");
        redirect("/clients/$clientId");
    }

    public function edit(int $clientId, int $id): void
    {
        $client = $this->clientModel->findById($clientId);
        $domain = $this->model->findById($id);
        if (!$client || !$domain) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $errors      = [];
        $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Edit Domain', null]];
        render('domains.form', compact('client', 'domain', 'errors', 'breadcrumbs'), 'Edit Domain');
    }

    public function update(int $clientId, int $id): void
    {
        $client = $this->clientModel->findById($clientId);
        $domain = $this->model->findById($id);
        if (!$client || !$domain) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $data   = $this->sanitise($_POST, $clientId);
        $errors = $this->validate($data);

        if ($errors) {
            $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$clientId"], ['Edit Domain', null]];
            render('domains.form', compact('client', 'domain', 'errors', 'breadcrumbs'), 'Edit Domain');
            return;
        }

        $this->model->update($id, $data);
        flash('success', "Domain '{$data['domain']}' updated.");
        redirect("/clients/$clientId");
    }

    public function destroy(int $clientId, int $id): void
    {
        $domain = $this->model->findById($id);
        if ($domain) {
            $this->model->delete($id);
            flash('success', "Domain '{$domain['domain']}' deleted.");
        }
        redirect("/clients/$clientId");
    }

    private function sanitise(array $post, int $clientId): array
    {
        return [
            'client_id'          => $clientId,
            'domain'             => trim($post['domain'] ?? ''),
            'registrar'          => trim($post['registrar'] ?? ''),
            'cloudflare_proxied' => isset($post['cloudflare_proxied']) ? 1 : 0,
            'renewal_date'       => $post['renewal_date'] ?: null,
            'renewal_years'      => max(1, (int)($post['renewal_years'] ?? 1)),
            'annual_cost'        => ($post['annual_cost'] ?? '') !== '' ? (float)$post['annual_cost'] : null,
            'client_charge'      => ($post['client_charge'] ?? '') !== '' ? (float)$post['client_charge'] : null,
            'client_charge_currency' => in_array($post['client_charge_currency'] ?? '', ['GBP','USD','EUR']) ? $post['client_charge_currency'] : 'GBP',
            'currency'           => in_array($post['currency'] ?? '', ['GBP','USD','EUR']) ? $post['currency'] : 'GBP',
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!$data['domain']) $errors['domain'] = 'Domain name is required.';
        return $errors;
    }
}
