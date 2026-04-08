<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use PDO;

class ClientController
{
    private Client $model;

    public function __construct(private PDO $db)
    {
        $this->model = new Client($db);
    }

    public function index(): void
    {
        $status  = $_GET['status'] ?? 'active';
        $filter  = in_array($status, ['active', 'archived', 'all']) ? $status : 'active';
        $clients = $this->model->findAllWithStats($filter === 'all' ? null : $filter);

        render('clients.index', compact('clients', 'filter'), 'Clients');
    }

    public function show(int $id): void
    {
        $client = $this->model->findWithFullDetails($id);
        if (!$client) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $breadcrumbs = [['Clients', '/clients'], [$client['name'], null]];
        render('clients.show', compact('client', 'breadcrumbs'), $client['name']);
    }

    public function create(): void
    {
        $client      = [];
        $errors      = [];
        $breadcrumbs = [['Clients', '/clients'], ['Add Client', null]];
        render('clients.form', compact('client', 'errors', 'breadcrumbs'), 'Add Client');
    }

    public function store(): void
    {
        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            $client      = $data;
            $breadcrumbs = [['Clients', '/clients'], ['Add Client', null]];
            render('clients.form', compact('client', 'errors', 'breadcrumbs'), 'Add Client');
            return;
        }

        $id = $this->model->insert($data);
        flash('success', "Client '{$data['name']}' created.");
        redirect("/clients/$id");
    }

    public function edit(int $id): void
    {
        $client = $this->model->findById($id);
        if (!$client) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $errors      = [];
        $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$id"], ['Edit', null]];
        render('clients.form', compact('client', 'errors', 'breadcrumbs'), 'Edit ' . $client['name']);
    }

    public function update(int $id): void
    {
        $client = $this->model->findById($id);
        if (!$client) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$id"], ['Edit', null]];
            render('clients.form', compact('client', 'errors', 'breadcrumbs'), 'Edit ' . $client['name']);
            return;
        }

        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->model->update($id, $data);
        flash('success', "Client '{$data['name']}' updated.");
        redirect("/clients/$id");
    }

    public function archive(int $id): void
    {
        $client = $this->model->findById($id);
        if (!$client) {
            redirect('/clients');
            return;
        }

        $newStatus = $client['status'] === 'active' ? 'archived' : 'active';
        $this->model->update($id, ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')]);

        $label = $newStatus === 'archived' ? 'archived' : 'restored';
        flash('success', "Client '{$client['name']}' $label.");
        redirect("/clients/$id");
    }

    public function merge(int $id): void
    {
        $client = $this->model->findById($id);
        if (!$client) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        // All other clients as merge targets
        $stmt = $this->db->prepare("SELECT id, name, status FROM clients WHERE id != ? ORDER BY name");
        $stmt->execute([$id]);
        $targets = $stmt->fetchAll();

        $breadcrumbs = [['Clients', '/clients'], [$client['name'], "/clients/$id"], ['Merge', null]];
        render('clients.merge', compact('client', 'targets', 'breadcrumbs'), 'Merge ' . $client['name']);
    }

    public function doMerge(int $id): void
    {
        $client = $this->model->findById($id);
        if (!$client) {
            redirect('/clients');
            return;
        }

        $targetId = (int)($_POST['target_id'] ?? 0);
        if (!$targetId || $targetId === $id) {
            flash('error', 'Please select a valid target client.');
            redirect("/clients/$id/merge");
            return;
        }

        $target = $this->model->findById($targetId);
        if (!$target) {
            flash('error', 'Target client not found.');
            redirect("/clients/$id/merge");
            return;
        }

        $tables = [
            'domains'               => 'client_id',
            'client_sites'          => 'client_id',
            'service_packages'      => 'client_id',
            'projects'              => 'client_id',
            'expenses'              => 'client_id',
            'freeagent_contacts'    => 'client_id',
            'freeagent_invoices'    => 'client_id',
        ];

        try {
            $this->db->beginTransaction();

            foreach ($tables as $table => $col) {
                $this->db->prepare("UPDATE $table SET $col = ? WHERE $col = ?")
                    ->execute([$targetId, $id]);
            }

            $this->db->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            flash('error', 'Merge failed: ' . $e->getMessage());
            redirect("/clients/$id/merge");
            return;
        }

        flash('success', "Merged '{$client['name']}' into '{$target['name']}'.");
        redirect("/clients/$targetId");
    }


    public function uploadAttachment(int $id): void
    {
        $client = $this->model->findById($id);
        if (!$client) { redirect('/clients'); return; }

        $type = in_array($_POST['type'] ?? '', ['proposal', 'contract']) ? $_POST['type'] : 'proposal';
        $file = $_FILES['attachment'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) { flash('error', 'Upload failed.'); redirect("/clients/$id"); }
        if (($file['type'] ?? '') !== 'application/pdf') { flash('error', 'Only PDF files are allowed.'); redirect("/clients/$id"); }

        $dir = DATA_PATH . '/attachments';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $safe = time() . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '-', basename($file['name']));
        $target = $dir . '/' . $safe;
        move_uploaded_file($file['tmp_name'], $target);

        $this->db->prepare("INSERT INTO client_attachments (client_id, type, original_name, file_path) VALUES (?, ?, ?, ?)")
            ->execute([$id, $type, $file['name'], $target]);

        flash('success', 'Attachment uploaded.');
        redirect("/clients/$id");
    }

    public function downloadAttachment(int $clientId, int $attachmentId): void
    {
        $stmt = $this->db->prepare("SELECT * FROM client_attachments WHERE id = ? AND client_id = ? LIMIT 1");
        $stmt->execute([$attachmentId, $clientId]);
        $a = $stmt->fetch();
        if (!$a || !is_file($a['file_path'])) { http_response_code(404); echo 'Not found'; return; }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($a['original_name']) . '"');
        readfile($a['file_path']);
        exit;
    }

    public function deleteAttachment(int $clientId, int $attachmentId): void
    {
        $stmt = $this->db->prepare("SELECT * FROM client_attachments WHERE id = ? AND client_id = ? LIMIT 1");
        $stmt->execute([$attachmentId, $clientId]);
        $a = $stmt->fetch();
        if ($a) {
            if (is_file($a['file_path'])) @unlink($a['file_path']);
            $this->db->prepare("DELETE FROM client_attachments WHERE id = ?")->execute([$attachmentId]);
        }
        flash('success', 'Attachment deleted.');
        redirect("/clients/$clientId");
    }

    private function sanitise(array $post): array
    {
        return [
            'name'          => trim($post['name'] ?? ''),
            'status'        => in_array($post['status'] ?? '', ['active', 'archived']) ? $post['status'] : 'active',
            'contact_name'  => trim($post['contact_name'] ?? ''),
            'contact_email' => trim($post['contact_email'] ?? ''),
            'notes'         => trim($post['notes'] ?? ''),
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!$data['name']) $errors['name'] = 'Client name is required.';
        if ($data['contact_email'] && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'Invalid email address.';
        }
        return $errors;
    }
}
