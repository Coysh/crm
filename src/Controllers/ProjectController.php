<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use CoyshCRM\Models\Project;
use PDO;

class ProjectController
{
    private Project $model;
    private Client  $clientModel;

    public function __construct(private PDO $db)
    {
        $this->model       = new Project($db);
        $this->clientModel = new Client($db);
    }

    public function index(): void
    {
        $clientId = isset($_GET['client_id']) && $_GET['client_id'] !== '' ? (int)$_GET['client_id'] : null;
        $status   = isset($_GET['status'])    && $_GET['status']    !== '' ? $_GET['status']           : null;

        $projects = $this->model->findAllWithClient($clientId, $status);
        $clients  = $this->clientModel->findAll([], 'name');

        render('projects.index', compact('projects', 'clients', 'clientId', 'status'), 'Projects');
    }

    public function create(): void
    {
        $project     = ['status' => 'active'];
        $errors      = [];
        $clients     = $this->clientModel->findAll(['status' => 'active'], 'name');
        $categories  = Project::incomeCategories();
        $statuses    = Project::statuses();
        $breadcrumbs = [['Projects', '/projects'], ['Add Project', null]];

        if (isset($_GET['client_id'])) {
            $project['client_id'] = (int)$_GET['client_id'];
        }

        render('projects.form', compact('project', 'errors', 'clients', 'categories', 'statuses', 'breadcrumbs'), 'Add Project');
    }

    public function store(): void
    {
        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);
        $clients    = $this->clientModel->findAll(['status' => 'active'], 'name');
        $categories = Project::incomeCategories();
        $statuses   = Project::statuses();

        if ($errors) {
            $project     = $data;
            $breadcrumbs = [['Projects', '/projects'], ['Add Project', null]];
            render('projects.form', compact('project', 'errors', 'clients', 'categories', 'statuses', 'breadcrumbs'), 'Add Project');
            return;
        }

        $this->model->insert($data);
        flash('success', "Project '{$data['name']}' created.");
        redirect('/projects');
    }

    public function edit(int $id): void
    {
        $project = $this->model->findById($id);
        if (!$project) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $errors      = [];
        $clients     = $this->clientModel->findAll([], 'name');
        $categories  = Project::incomeCategories();
        $statuses    = Project::statuses();
        $breadcrumbs = [['Projects', '/projects'], ['Edit ' . $project['name'], null]];
        render('projects.form', compact('project', 'errors', 'clients', 'categories', 'statuses', 'breadcrumbs'), 'Edit Project');
    }

    public function update(int $id): void
    {
        $project = $this->model->findById($id);
        if (!$project) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);
        $clients    = $this->clientModel->findAll([], 'name');
        $categories = Project::incomeCategories();
        $statuses   = Project::statuses();

        if ($errors) {
            $breadcrumbs = [['Projects', '/projects'], ['Edit ' . $project['name'], null]];
            render('projects.form', compact('project', 'errors', 'clients', 'categories', 'statuses', 'breadcrumbs'), 'Edit Project');
            return;
        }

        $this->model->update($id, $data);
        flash('success', "Project '{$data['name']}' updated.");
        redirect('/projects');
    }

    public function destroy(int $id): void
    {
        $project = $this->model->findById($id);
        if ($project) {
            $this->model->delete($id);
            flash('success', "Project '{$project['name']}' deleted.");
        }
        redirect('/projects');
    }

    private function sanitise(array $post): array
    {
        $categories = array_keys(Project::incomeCategories());
        $statuses   = array_keys(Project::statuses());
        return [
            'client_id'       => (int)($post['client_id'] ?? 0),
            'name'            => trim($post['name'] ?? ''),
            'income_category' => in_array($post['income_category'] ?? '', $categories) ? $post['income_category'] : '',
            'income'          => (float)($post['income'] ?? 0),
            'income_target'   => (float)($post['income_target'] ?? 0),
            'income_invoiced' => (float)($post['income_invoiced'] ?? 0),
            'start_date'      => $post['start_date'] ?: null,
            'end_date'        => $post['end_date'] ?: null,
            'notes'           => trim($post['notes'] ?? ''),
            'status'          => in_array($post['status'] ?? '', $statuses) ? $post['status'] : 'active',
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!$data['name'])            $errors['name']            = 'Project name is required.';
        if (!$data['client_id'])       $errors['client_id']       = 'Please select a client.';
        if (!$data['income_category']) $errors['income_category'] = 'Income category is required.';
        return $errors;
    }
}
