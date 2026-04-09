<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use CoyshCRM\Services\ExchangeRateService;
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
        $status = $_GET['status'] ?? 'active';
        $filter = in_array($status, ['active', 'archived', 'all']) ? $status : 'active';

        $filters = [
            'search'       => trim($_GET['search'] ?? ''),
            'health'       => in_array($_GET['health'] ?? '', ['healthy','attention','at_risk']) ? $_GET['health'] : 'all',
            'has_recurring'=> in_array($_GET['has_recurring'] ?? '', ['yes','no']) ? $_GET['has_recurring'] : 'all',
            'has_sites'    => in_array($_GET['has_sites'] ?? '', ['yes','no']) ? $_GET['has_sites'] : 'all',
            'mrr_range'    => in_array($_GET['mrr_range'] ?? '', ['zero','1_100','100_500','500plus']) ? $_GET['mrr_range'] : 'all',
            'cloudflare'   => in_array($_GET['cloudflare'] ?? '', ['yes','no']) ? $_GET['cloudflare'] : 'all',
            'client_type'  => in_array($_GET['client_type'] ?? '', ['managed','support_only','consultancy_only']) ? $_GET['client_type'] : 'all',
            'sort'         => in_array($_GET['sort'] ?? '', ['name','mrr','sites','status','type','total_invoiced','outstanding','health']) ? $_GET['sort'] : 'name',
            'dir'          => strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC',
        ];

        $clients      = $this->model->findAllWithFilters($filter === 'all' ? null : $filter, $filters);
        $clientHealth = $this->model->getHealthAll();

        // Apply health filter in PHP (uses computed health data)
        if ($filters['health'] !== 'all') {
            $clients = array_values(array_filter($clients, function ($c) use ($clientHealth, $filters) {
                $h = $clientHealth[(int)$c['id']] ?? ['status' => 'healthy'];
                return $h['status'] === $filters['health'];
            }));
        }

        // Health sort — computed field, must sort in PHP
        if ($filters['sort'] === 'health') {
            $order = ['at_risk' => 0, 'attention' => 1, 'healthy' => 2];
            usort($clients, function ($a, $b) use ($clientHealth, $order, $filters) {
                $ha = $order[$clientHealth[(int)$a['id']]['status'] ?? 'healthy'] ?? 2;
                $hb = $order[$clientHealth[(int)$b['id']]['status'] ?? 'healthy'] ?? 2;
                return $filters['dir'] === 'DESC' ? $hb - $ha : $ha - $hb;
            });
        }

        $activeFilterCount = array_sum([
            $filters['search'] !== '' ? 1 : 0,
            $filters['health'] !== 'all' ? 1 : 0,
            $filters['has_recurring'] !== 'all' ? 1 : 0,
            $filters['has_sites'] !== 'all' ? 1 : 0,
            $filters['mrr_range'] !== 'all' ? 1 : 0,
            $filters['cloudflare'] !== 'all' ? 1 : 0,
            $filters['client_type'] !== 'all' ? 1 : 0,
        ]);

        render('clients.index', compact('clients', 'filter', 'clientHealth', 'filters', 'activeFilterCount'), 'Clients');
    }

    public function bulkArchive(): void
    {
        $ids = array_map('intval', (array)($_POST['client_ids'] ?? []));
        if (empty($ids)) { redirect('/clients'); return; }

        $count = 0;
        foreach ($ids as $id) {
            $client = $this->model->findById($id);
            if ($client && $client['status'] === 'active') {
                $this->model->update($id, ['status' => 'archived', 'updated_at' => date('Y-m-d H:i:s')]);
                $count++;
            }
        }
        flash('success', "$count client" . ($count !== 1 ? 's' : '') . " archived.");
        redirect('/clients');
    }

    public function bulkRestore(): void
    {
        $ids = array_map('intval', (array)($_POST['client_ids'] ?? []));
        if (empty($ids)) { redirect('/clients?status=archived'); return; }

        $count = 0;
        foreach ($ids as $id) {
            $client = $this->model->findById($id);
            if ($client && $client['status'] === 'archived') {
                $this->model->update($id, ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')]);
                $count++;
            }
        }
        flash('success', "$count client" . ($count !== 1 ? 's' : '') . " restored.");
        redirect('/clients?status=archived');
    }

    public function bulkDelete(): void
    {
        $typedConfirm = trim($_POST['confirm_text'] ?? '');
        if ($typedConfirm !== 'DELETE') {
            flash('error', 'Confirmation text did not match. Bulk delete cancelled.');
            redirect('/clients?status=archived');
            return;
        }

        $ids = array_map('intval', (array)($_POST['client_ids'] ?? []));
        if (empty($ids)) { redirect('/clients?status=archived'); return; }

        $deleted = 0;
        $failed  = 0;

        foreach ($ids as $id) {
            $client = $this->model->findById($id);
            if (!$client || $client['status'] !== 'archived') continue;

            try {
                $this->db->beginTransaction();
                $sitesCount    = (int)$this->db->query("SELECT COUNT(*) FROM client_sites WHERE client_id = $id")->fetchColumn();
                $domainsCount  = (int)$this->db->query("SELECT COUNT(*) FROM domains WHERE client_id = $id")->fetchColumn();
                $expensesCount = (int)$this->db->query("SELECT COUNT(*) FROM expenses WHERE client_id = $id")->fetchColumn();

                try { $this->db->prepare("DELETE FROM recurring_cost_clients WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                try { $this->db->prepare("UPDATE freeagent_bank_transactions SET client_id = NULL WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                try { $this->db->prepare("UPDATE freeagent_invoices SET client_id = NULL WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                try { $this->db->prepare("UPDATE freeagent_recurring_invoices SET client_id = NULL WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                try { $this->db->prepare("UPDATE freeagent_contacts SET client_id = NULL, auto_matched = 0 WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                $this->db->prepare("DELETE FROM expenses WHERE client_id = ?")->execute([$id]);
                try {
                    $stmtPloi = $this->db->prepare("SELECT ps.ploi_id, ps.ploi_server_id, d.domain AS domain_name FROM ploi_sites ps JOIN client_sites cs ON cs.id = ps.client_site_id LEFT JOIN domains d ON d.id = cs.domain_id WHERE cs.client_id = ?");
                    $stmtPloi->execute([$id]);
                    foreach ($stmtPloi->fetchAll() as $ps) {
                        try { $this->db->prepare("INSERT OR IGNORE INTO ploi_sync_exclusions (ploi_site_id, ploi_server_id, domain, reason) VALUES (?, ?, ?, 'Deleted from CRM')")->execute([$ps['ploi_id'], $ps['ploi_server_id'], $ps['domain_name']]); } catch (\Throwable) {}
                    }
                    $this->db->prepare("UPDATE ploi_sites SET client_site_id = NULL WHERE client_site_id IN (SELECT id FROM client_sites WHERE client_id = ?)")->execute([$id]);
                } catch (\Throwable) {}
                $this->db->prepare("DELETE FROM client_sites WHERE client_id = ?")->execute([$id]);
                try { $this->db->prepare("DELETE FROM client_attachments WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                $this->db->prepare("DELETE FROM domains WHERE client_id = ?")->execute([$id]);
                $this->db->prepare("INSERT INTO deletion_log (entity_type, entity_id, entity_name, related_data, deleted_at) VALUES ('client', ?, ?, ?, datetime('now'))")->execute([$id, $client['name'], json_encode(['sites' => $sitesCount, 'domains' => $domainsCount, 'expenses' => $expensesCount])]);
                $this->model->delete($id);
                $this->db->commit();
                $deleted++;
            } catch (\Throwable $e) {
                $this->db->rollBack();
                $failed++;
            }
        }

        $msg = "$deleted client" . ($deleted !== 1 ? 's' : '') . " permanently deleted.";
        if ($failed) $msg .= " ($failed failed)";
        flash($failed === 0 ? 'success' : 'error', $msg);
        redirect('/clients?status=archived');
    }

    public function show(int $id): void
    {
        $client = $this->model->findWithFullDetails($id);
        if (!$client) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $health      = $this->model->getHealth($id);
        $fx          = new ExchangeRateService($this->db);
        $breadcrumbs = [['Clients', '/clients'], [$client['name'], null]];
        render('clients.show', compact('client', 'health', 'fx', 'breadcrumbs'), $client['name']);
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
            // AJAX request: return JSON
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Not found']);
                exit;
            }
            redirect('/clients');
            return;
        }

        $newStatus = $client['status'] === 'active' ? 'archived' : 'active';
        $this->model->update($id, ['status' => $newStatus, 'updated_at' => date('Y-m-d H:i:s')]);

        // Return JSON for AJAX requests
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'new_status' => $newStatus]);
            exit;
        }

        $label = $newStatus === 'archived' ? 'archived' : 'restored';
        flash('success', "Client '{$client['name']}' $label.");
        redirect("/clients/$id");
    }

    public function destroy(int $id): void
    {
        $client = $this->model->findById($id);
        if (!$client) {
            flash('error', 'Client not found.');
            redirect('/clients?status=archived');
            return;
        }

        // Require typed confirmation
        $typedName = trim($_POST['confirm_name'] ?? '');
        if ($typedName !== $client['name']) {
            flash('error', 'Confirmation name did not match. Delete cancelled.');
            redirect('/clients?status=archived');
            return;
        }

        try {
            $this->db->beginTransaction();

            // Count related data for log
            $sitesCount   = (int)$this->db->prepare("SELECT COUNT(*) FROM client_sites WHERE client_id = ?")->execute([$id]) ? (int)$this->db->query("SELECT COUNT(*) FROM client_sites WHERE client_id = $id")->fetchColumn() : 0;
            $domainsCount = (int)$this->db->query("SELECT COUNT(*) FROM domains WHERE client_id = $id")->fetchColumn();
            $expensesCount = (int)$this->db->query("SELECT COUNT(*) FROM expenses WHERE client_id = $id")->fetchColumn();

            // 1. Remove from recurring_cost_clients
            try {
                $this->db->prepare("DELETE FROM recurring_cost_clients WHERE client_id = ?")->execute([$id]);
            } catch (\Throwable) {}

            // 2-5. Unlink FreeAgent records
            try { $this->db->prepare("UPDATE freeagent_bank_transactions SET client_id = NULL WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
            try { $this->db->prepare("UPDATE freeagent_invoices SET client_id = NULL WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
            try { $this->db->prepare("UPDATE freeagent_recurring_invoices SET client_id = NULL WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
            try { $this->db->prepare("UPDATE freeagent_contacts SET client_id = NULL, auto_matched = 0 WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}

            // 6. Delete expenses
            $this->db->prepare("DELETE FROM expenses WHERE client_id = ?")->execute([$id]);

            // 7. Handle ploi_sites: add to exclusions, then unlink
            try {
                $ploiRows = $this->db->prepare("
                    SELECT ps.ploi_id, ps.ploi_server_id, cs.id AS cs_id
                    FROM ploi_sites ps
                    JOIN client_sites cs ON cs.id = ps.client_site_id
                    WHERE cs.client_id = ?
                ")->execute([$id]) ? null : null;
                $stmtPloi = $this->db->prepare("
                    SELECT ps.ploi_id, ps.ploi_server_id, d.domain AS domain_name
                    FROM ploi_sites ps
                    JOIN client_sites cs ON cs.id = ps.client_site_id
                    LEFT JOIN domains d ON d.id = cs.domain_id
                    WHERE cs.client_id = ?
                ");
                $stmtPloi->execute([$id]);
                $ploiSiteRows = $stmtPloi->fetchAll();

                foreach ($ploiSiteRows as $ps) {
                    try {
                        $this->db->prepare("
                            INSERT OR IGNORE INTO ploi_sync_exclusions (ploi_site_id, ploi_server_id, domain, reason)
                            VALUES (?, ?, ?, 'Deleted from CRM')
                        ")->execute([$ps['ploi_id'], $ps['ploi_server_id'], $ps['domain_name']]);
                    } catch (\Throwable) {}
                }

                // Unlink ploi_sites from client_sites
                $this->db->prepare(
                    "UPDATE ploi_sites SET client_site_id = NULL
                     WHERE client_site_id IN (SELECT id FROM client_sites WHERE client_id = ?)"
                )->execute([$id]);
            } catch (\Throwable) {}

            // 8. Delete client_sites
            $this->db->prepare("DELETE FROM client_sites WHERE client_id = ?")->execute([$id]);

            // Delete client_attachments if table exists
            try { $this->db->prepare("DELETE FROM client_attachments WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}

            // 9. Delete domains
            $this->db->prepare("DELETE FROM domains WHERE client_id = ?")->execute([$id]);

            // 10. Log deletion then delete client
            $relatedData = json_encode(['sites' => $sitesCount, 'domains' => $domainsCount, 'expenses' => $expensesCount]);
            $this->db->prepare("
                INSERT INTO deletion_log (entity_type, entity_id, entity_name, related_data, deleted_at)
                VALUES ('client', ?, ?, ?, datetime('now'))
            ")->execute([$id, $client['name'], $relatedData]);

            $this->model->delete($id);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            flash('error', 'Delete failed: ' . $e->getMessage());
            redirect('/clients?status=archived');
            return;
        }

        flash('success', "Client '{$client['name']}' permanently deleted.");
        redirect('/clients?status=archived');
    }

    public function destroyAllArchived(): void
    {
        // Require typing "DELETE ALL"
        $typedConfirm = trim($_POST['confirm_text'] ?? '');
        if ($typedConfirm !== 'DELETE ALL') {
            flash('error', 'Confirmation text did not match. Bulk delete cancelled.');
            redirect('/clients?status=archived');
            return;
        }

        $archived = $this->model->findAll(['status' => 'archived']);
        if (empty($archived)) {
            flash('success', 'No archived clients to delete.');
            redirect('/clients?status=archived');
            return;
        }

        $deleted = 0;
        $failed  = 0;

        foreach ($archived as $client) {
            $id = (int)$client['id'];
            try {
                $this->db->beginTransaction();

                $sitesCount    = (int)$this->db->query("SELECT COUNT(*) FROM client_sites WHERE client_id = $id")->fetchColumn();
                $domainsCount  = (int)$this->db->query("SELECT COUNT(*) FROM domains WHERE client_id = $id")->fetchColumn();
                $expensesCount = (int)$this->db->query("SELECT COUNT(*) FROM expenses WHERE client_id = $id")->fetchColumn();

                try { $this->db->prepare("DELETE FROM recurring_cost_clients WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                try { $this->db->prepare("UPDATE freeagent_bank_transactions SET client_id = NULL WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                try { $this->db->prepare("UPDATE freeagent_invoices SET client_id = NULL WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                try { $this->db->prepare("UPDATE freeagent_recurring_invoices SET client_id = NULL WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                try { $this->db->prepare("UPDATE freeagent_contacts SET client_id = NULL, auto_matched = 0 WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}

                $this->db->prepare("DELETE FROM expenses WHERE client_id = ?")->execute([$id]);

                // Ploi exclusions
                try {
                    $stmtPloi = $this->db->prepare("
                        SELECT ps.ploi_id, ps.ploi_server_id, d.domain AS domain_name
                        FROM ploi_sites ps
                        JOIN client_sites cs ON cs.id = ps.client_site_id
                        LEFT JOIN domains d ON d.id = cs.domain_id
                        WHERE cs.client_id = ?
                    ");
                    $stmtPloi->execute([$id]);
                    foreach ($stmtPloi->fetchAll() as $ps) {
                        try {
                            $this->db->prepare("
                                INSERT OR IGNORE INTO ploi_sync_exclusions (ploi_site_id, ploi_server_id, domain, reason)
                                VALUES (?, ?, ?, 'Deleted from CRM')
                            ")->execute([$ps['ploi_id'], $ps['ploi_server_id'], $ps['domain_name']]);
                        } catch (\Throwable) {}
                    }
                    $this->db->prepare(
                        "UPDATE ploi_sites SET client_site_id = NULL
                         WHERE client_site_id IN (SELECT id FROM client_sites WHERE client_id = ?)"
                    )->execute([$id]);
                } catch (\Throwable) {}

                $this->db->prepare("DELETE FROM client_sites WHERE client_id = ?")->execute([$id]);
                try { $this->db->prepare("DELETE FROM client_attachments WHERE client_id = ?")->execute([$id]); } catch (\Throwable) {}
                $this->db->prepare("DELETE FROM domains WHERE client_id = ?")->execute([$id]);

                $relatedData = json_encode(['sites' => $sitesCount, 'domains' => $domainsCount, 'expenses' => $expensesCount]);
                $this->db->prepare("
                    INSERT INTO deletion_log (entity_type, entity_id, entity_name, related_data, deleted_at)
                    VALUES ('client', ?, ?, ?, datetime('now'))
                ")->execute([$id, $client['name'], $relatedData]);

                $this->model->delete($id);
                $this->db->commit();
                $deleted++;
            } catch (\Throwable $e) {
                $this->db->rollBack();
                $failed++;
            }
        }

        if ($failed > 0) {
            flash('error', "Deleted $deleted clients; $failed failed.");
        } else {
            flash('success', "All $deleted archived clients permanently deleted.");
        }
        redirect('/clients?status=archived');
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
            'domains'                         => 'client_id',
            'client_sites'                    => 'client_id',
            'projects'                        => 'client_id',
            'expenses'                        => 'client_id',
            'freeagent_contacts'              => 'client_id',
            'freeagent_invoices'              => 'client_id',
            'freeagent_recurring_invoices'    => 'client_id',
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
            'name'            => trim($post['name'] ?? ''),
            'status'          => in_array($post['status'] ?? '', ['active', 'archived']) ? $post['status'] : 'active',
            'contact_name'    => trim($post['contact_name'] ?? ''),
            'contact_email'   => trim($post['contact_email'] ?? ''),
            'notes'           => trim($post['notes'] ?? ''),
            'client_type'     => in_array($post['client_type'] ?? '', ['managed', 'support_only', 'consultancy_only']) ? $post['client_type'] : 'managed',
            'agreement_notes' => trim($post['agreement_notes'] ?? ''),
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
