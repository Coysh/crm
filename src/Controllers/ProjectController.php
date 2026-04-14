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

        $projects   = $this->model->findAllWithClient($clientId, $status);
        $clients    = $this->clientModel->findAll([], 'name');
        $categories = Project::incomeCategories();
        $statuses   = Project::statuses();
        $includeQuill = true;

        render('projects.index', compact('projects', 'clients', 'clientId', 'status', 'categories', 'statuses', 'includeQuill'), 'Projects');
    }

    public function create(): void
    {
        $project     = ['status' => 'active'];
        $errors      = [];
        $clients     = $this->clientModel->findAll(['status' => 'active'], 'name');
        $categories  = Project::incomeCategories();
        $statuses    = Project::statuses();
        $breadcrumbs = [['Projects', '/projects'], ['Add Project', null]];
        $includeQuill = true;

        if (isset($_GET['client_id'])) {
            $project['client_id'] = (int)$_GET['client_id'];
        }

        render('projects.form', compact('project', 'errors', 'clients', 'categories', 'statuses', 'breadcrumbs', 'includeQuill'), 'Add Project');
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
            $includeQuill = true;
            render('projects.form', compact('project', 'errors', 'clients', 'categories', 'statuses', 'breadcrumbs', 'includeQuill'), 'Add Project');
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
        $includeQuill = true;
        render('projects.form', compact('project', 'errors', 'clients', 'categories', 'statuses', 'breadcrumbs', 'includeQuill'), 'Edit Project');
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
            $includeQuill = true;
            render('projects.form', compact('project', 'errors', 'clients', 'categories', 'statuses', 'breadcrumbs', 'includeQuill'), 'Edit Project');
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

    public function updateStatus(int $id): void
    {
        header('Content-Type: application/json');
        $project = $this->model->findById($id);
        if (!$project) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }

        $statuses = array_keys(Project::statuses());
        $status = $_POST['status'] ?? '';
        if (!in_array($status, $statuses)) {
            echo json_encode(['error' => 'Invalid status']);
            exit;
        }

        $this->model->update($id, ['status' => $status]);
        echo json_encode(['ok' => true, 'status' => $status]);
        exit;
    }

    public function quickCreate(): void
    {
        header('Content-Type: application/json');
        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            echo json_encode(['error' => implode(' ', $errors)]);
            exit;
        }

        $id = $this->model->insert($data);

        $clientName = '';
        if ($data['client_id']) {
            $s = $this->db->prepare("SELECT name FROM clients WHERE id = ?");
            $s->execute([$data['client_id']]);
            $clientName = $s->fetchColumn() ?: '';
        }

        echo json_encode([
            'ok'              => true,
            'id'              => $id,
            'name'            => $data['name'],
            'client_id'       => $data['client_id'],
            'client_name'     => $clientName,
            'income_category' => $data['income_category'],
            'status'          => $data['status'],
        ]);
        exit;
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
            'start_date'      => ($post['start_date'] ?? '') ?: null,
            'end_date'        => ($post['end_date'] ?? '') ?: null,
            'notes'           => $this->sanitiseHtml($post['notes'] ?? ''),
            'status'          => in_array($post['status'] ?? '', $statuses) ? $post['status'] : 'active',
        ];
    }

    private function sanitiseHtml(string $html): string
    {
        $allowed = '<p><br><strong><em><u><s><ul><ol><li><a><h2><h3><blockquote>';
        $clean = strip_tags(trim($html), $allowed);
        // Strip event handler attributes (onclick, onerror, etc.)
        $clean = preg_replace('/(<[^>]+)\s+on\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)/i', '$1', $clean);
        // Strip javascript: URIs from href
        $clean = preg_replace('/href\s*=\s*["\']?\s*javascript\s*:/i', 'href="', $clean);
        return $clean;
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
