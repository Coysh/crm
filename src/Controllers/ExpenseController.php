<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use CoyshCRM\Models\Expense;
use CoyshCRM\Models\Project;
use CoyshCRM\Models\Server;
use PDO;

class ExpenseController
{
    private Expense $model;
    private Client  $clientModel;
    private Server  $serverModel;
    private Project $projectModel;

    public function __construct(private PDO $db)
    {
        $this->model        = new Expense($db);
        $this->clientModel  = new Client($db);
        $this->serverModel  = new Server($db);
        $this->projectModel = new Project($db);
    }

    public function index(): void
    {
        $category = isset($_GET['category']) && $_GET['category'] !== '' ? $_GET['category'] : null;
        $clientId = isset($_GET['client_id']) && $_GET['client_id'] !== '' ? (int)$_GET['client_id'] : null;
        $serverId = isset($_GET['server_id']) && $_GET['server_id'] !== '' ? (int)$_GET['server_id'] : null;

        $expenses   = $this->model->findAllWithRelations($category, $clientId, $serverId);
        $clients    = $this->clientModel->findAll([], 'name');
        $servers    = $this->serverModel->findAll([], 'name');
        $categories = Expense::categories();

        render('expenses.index', compact('expenses', 'clients', 'servers', 'categories', 'category', 'clientId', 'serverId'), 'Expenses');
    }

    public function create(): void
    {
        $expense     = [];
        $errors      = [];
        $clients     = $this->clientModel->findAll([], 'name');
        $servers     = $this->serverModel->findAll([], 'name');
        $projects    = $this->projectModel->findAll([], 'name');
        $categories  = Expense::categories();
        $cycles      = Expense::billingCycles();
        $breadcrumbs = [['Expenses', '/expenses'], ['Add Expense', null]];
        render('expenses.form', compact('expense', 'errors', 'clients', 'servers', 'projects', 'categories', 'cycles', 'breadcrumbs'), 'Add Expense');
    }

    public function store(): void
    {
        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);
        $clients    = $this->clientModel->findAll([], 'name');
        $servers    = $this->serverModel->findAll([], 'name');
        $projects   = $this->projectModel->findAll([], 'name');
        $categories = Expense::categories();
        $cycles     = Expense::billingCycles();

        if ($errors) {
            $expense     = $data;
            $breadcrumbs = [['Expenses', '/expenses'], ['Add Expense', null]];
            render('expenses.form', compact('expense', 'errors', 'clients', 'servers', 'projects', 'categories', 'cycles', 'breadcrumbs'), 'Add Expense');
            return;
        }

        $this->model->insert($data);
        flash('success', "Expense '{$data['name']}' added.");
        redirect('/expenses');
    }

    public function edit(int $id): void
    {
        $expense = $this->model->findById($id);
        if (!$expense) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $errors      = [];
        $clients     = $this->clientModel->findAll([], 'name');
        $servers     = $this->serverModel->findAll([], 'name');
        $projects    = $this->projectModel->findAll([], 'name');
        $categories  = Expense::categories();
        $cycles      = Expense::billingCycles();
        $breadcrumbs = [['Expenses', '/expenses'], ['Edit Expense', null]];
        render('expenses.form', compact('expense', 'errors', 'clients', 'servers', 'projects', 'categories', 'cycles', 'breadcrumbs'), 'Edit Expense');
    }

    public function update(int $id): void
    {
        $expense = $this->model->findById($id);
        if (!$expense) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);
        $clients    = $this->clientModel->findAll([], 'name');
        $servers    = $this->serverModel->findAll([], 'name');
        $projects   = $this->projectModel->findAll([], 'name');
        $categories = Expense::categories();
        $cycles     = Expense::billingCycles();

        if ($errors) {
            $breadcrumbs = [['Expenses', '/expenses'], ['Edit Expense', null]];
            render('expenses.form', compact('expense', 'errors', 'clients', 'servers', 'projects', 'categories', 'cycles', 'breadcrumbs'), 'Edit Expense');
            return;
        }

        $this->model->update($id, $data);
        flash('success', "Expense '{$data['name']}' updated.");
        redirect('/expenses');
    }

    public function destroy(int $id): void
    {
        $expense = $this->model->findById($id);
        if ($expense) {
            $this->model->delete($id);
            flash('success', "Expense '{$expense['name']}' deleted.");
        }
        redirect('/expenses');
    }

    private function sanitise(array $post): array
    {
        $categories = array_keys(Expense::categories());
        $cycles     = array_keys(Expense::billingCycles());
        return [
            'name'          => trim($post['name'] ?? ''),
            'category'      => in_array($post['category'] ?? '', $categories) ? $post['category'] : '',
            'amount'        => (float)($post['amount'] ?? 0),
            'billing_cycle' => in_array($post['billing_cycle'] ?? '', $cycles) ? $post['billing_cycle'] : 'one_off',
            'client_id'     => $post['client_id'] !== '' ? (int)$post['client_id'] : null,
            'server_id'     => $post['server_id'] !== '' ? (int)$post['server_id'] : null,
            'project_id'    => $post['project_id'] !== '' ? (int)$post['project_id'] : null,
            'date'          => $post['date'] ?: null,
            'notes'         => trim($post['notes'] ?? ''),
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!$data['name'])     $errors['name']     = 'Expense name is required.';
        if (!$data['category']) $errors['category'] = 'Category is required.';
        if ($data['amount'] <= 0) $errors['amount'] = 'Amount must be greater than 0.';
        return $errors;
    }
}
