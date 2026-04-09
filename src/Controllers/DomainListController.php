<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use CoyshCRM\Models\Domain;
use PDO;

class DomainListController
{
    private Domain $model;

    public function __construct(private PDO $db)
    {
        $this->model = new Domain($db);
    }

    public function index(): void
    {
        $today = date('Y-m-d');
        $day30 = date('Y-m-d', strtotime('+30 days'));
        $day90 = date('Y-m-d', strtotime('+90 days'));

        // Filters
        $search       = trim($_GET['search'] ?? '');
        $registrar    = trim($_GET['registrar'] ?? '');
        $cfProxied    = $_GET['cf_proxied'] ?? '';
        $cfLinked     = $_GET['cf_linked'] ?? '';
        $renewal      = $_GET['renewal'] ?? '';
        $clientFilter = ($_GET['client_id'] ?? '') !== '' ? (int)$_GET['client_id'] : 0;
        $statusFilter = in_array($_GET['status'] ?? '', ['active', 'archived', 'all']) ? $_GET['status'] : 'active';

        $filters = compact('search', 'registrar', 'cfProxied', 'cfLinked', 'renewal', 'clientFilter', 'statusFilter');

        // Check if status column exists
        $hasStatus = $this->hasColumn('domains', 'status');

        // Base query with CF join and recurring cost billing check
        $sql = "
            SELECT d.*, c.name AS client_name, c.id AS client_id,
                   cz.zone_id AS cf_zone_id, cz.status AS cf_status, cz.plan AS cf_plan,
                   (SELECT rc.id FROM recurring_costs rc
                    JOIN recurring_cost_clients rcc ON rcc.recurring_cost_id = rc.id
                    WHERE rc.name LIKE 'Domain: %' AND rcc.client_id = d.client_id
                    AND rc.name = 'Domain: ' || d.domain
                    LIMIT 1) AS linked_recurring_cost_id,
                   (SELECT rc.renewal_date FROM recurring_costs rc
                    JOIN recurring_cost_clients rcc ON rcc.recurring_cost_id = rc.id
                    WHERE rc.name LIKE 'Domain: %' AND rcc.client_id = d.client_id
                    AND rc.name = 'Domain: ' || d.domain
                    LIMIT 1) AS linked_rc_renewal_date
            FROM domains d
            LEFT JOIN clients c ON c.id = d.client_id
            LEFT JOIN cloudflare_zones cz ON cz.domain_id = d.id
            WHERE 1=1
        ";
        $params = [];

        if ($hasStatus && $statusFilter !== 'all') {
            $sql .= " AND COALESCE(d.status, 'active') = ?";
            $params[] = $statusFilter;
        }

        if ($search !== '') {
            $sql .= " AND d.domain LIKE ?";
            $params[] = '%' . $search . '%';
        }
        if ($registrar !== '') {
            $sql .= " AND d.registrar = ?";
            $params[] = $registrar;
        }
        if ($cfProxied === '1') {
            $sql .= " AND d.cloudflare_proxied = 1";
        } elseif ($cfProxied === '0') {
            $sql .= " AND d.cloudflare_proxied = 0";
        }
        if ($renewal === 'overdue') {
            $sql .= " AND d.renewal_date < ?";
            $params[] = $today;
        } elseif ($renewal === '30d') {
            $sql .= " AND d.renewal_date BETWEEN ? AND ?";
            $params[] = $today;
            $params[] = $day30;
        } elseif ($renewal === '90d') {
            $sql .= " AND d.renewal_date BETWEEN ? AND ?";
            $params[] = $today;
            $params[] = $day90;
        }
        if ($clientFilter > 0) {
            $sql .= " AND d.client_id = ?";
            $params[] = $clientFilter;
        }

        if ($cfLinked === '1') {
            $sql .= " AND cz.zone_id IS NOT NULL";
        } elseif ($cfLinked === '0') {
            $sql .= " AND cz.zone_id IS NULL";
        }

        $sql .= " ORDER BY CASE WHEN d.renewal_date IS NULL THEN 1 ELSE 0 END, d.renewal_date ASC";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $domains = $stmt->fetchAll();
        } catch (\Throwable) {
            $domains = [];
        }

        // Summary (scoped to active domains by default)
        $summaryWhere = $hasStatus ? "WHERE COALESCE(status, 'active') = 'active'" : "WHERE 1=1";
        try {
            $overdue    = (int)$this->db->query("SELECT COUNT(*) FROM domains $summaryWhere AND renewal_date < '$today' AND renewal_date IS NOT NULL")->fetchColumn();
            $due30      = (int)$this->db->query("SELECT COUNT(*) FROM domains $summaryWhere AND renewal_date BETWEEN '$today' AND '$day30'")->fetchColumn();
            $totalCost  = (float)$this->db->query("SELECT COALESCE(SUM(annual_cost), 0) FROM domains $summaryWhere AND annual_cost IS NOT NULL")->fetchColumn();
            $totalCount = (int)$this->db->query("SELECT COUNT(*) FROM domains $summaryWhere")->fetchColumn();
        } catch (\Throwable) {
            $overdue = $due30 = 0; $totalCost = 0.0; $totalCount = 0;
        }
        $summary = compact('totalCount', 'overdue', 'due30', 'totalCost');

        // Distinct registrars for filter
        try {
            $registrars = $this->db->query("SELECT DISTINCT registrar FROM domains WHERE registrar IS NOT NULL AND registrar != '' ORDER BY registrar")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            $registrars = [];
        }

        // Clients list for filter dropdown
        $clients = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();

        $breadcrumbs = [['Domains', null]];
        render('domains.index', compact('domains', 'filters', 'summary', 'registrars', 'clients', 'today', 'day30', 'breadcrumbs'), 'Domains');
    }

    public function create(): void
    {
        $domain  = [];
        $errors  = [];
        $clients = $this->db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
        $breadcrumbs = [['Domains', '/domains'], ['Add Domain', null]];
        render('domains.standalone_form', compact('domain', 'errors', 'clients', 'breadcrumbs'), 'Add Domain');
    }

    public function store(): void
    {
        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            $domain  = $data;
            $clients = $this->db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
            $breadcrumbs = [['Domains', '/domains'], ['Add Domain', null]];
            render('domains.standalone_form', compact('domain', 'errors', 'clients', 'breadcrumbs'), 'Add Domain');
            return;
        }

        $id = $this->model->insert($data);
        flash('success', "Domain '{$data['domain']}' added.");
        redirect("/domains/$id");
    }

    public function show(int $id): void
    {
        $domain = $this->model->findById($id);
        if (!$domain) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $client = null;
        if ($domain['client_id']) {
            $clientModel = new Client($this->db);
            $client = $clientModel->findById((int)$domain['client_id']);
        }

        // CF zone info
        $cfZone = null;
        try {
            $stmt = $this->db->prepare("SELECT * FROM cloudflare_zones WHERE domain_id = ? LIMIT 1");
            $stmt->execute([$id]);
            $cfZone = $stmt->fetch() ?: null;
        } catch (\Throwable) {}

        $breadcrumbs = [['Domains', '/domains'], [$domain['domain'], null]];
        render('domains.show', compact('domain', 'client', 'cfZone', 'breadcrumbs'), $domain['domain']);
    }

    public function edit(int $id): void
    {
        $domain = $this->model->findById($id);
        if (!$domain) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $errors  = [];
        $clients = $this->db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
        $linkedRecurringCost = $this->findLinkedRecurringCost($id, $domain['domain'], (int)($domain['client_id'] ?? 0));
        $breadcrumbs = [['Domains', '/domains'], [$domain['domain'], "/domains/$id"], ['Edit', null]];
        render('domains.standalone_form', compact('domain', 'errors', 'clients', 'linkedRecurringCost', 'breadcrumbs'), 'Edit Domain');
    }

    public function update(int $id): void
    {
        $domain = $this->model->findById($id);
        if (!$domain) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            $clients = $this->db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
            $breadcrumbs = [['Domains', '/domains'], [$domain['domain'], "/domains/$id"], ['Edit', null]];
            render('domains.standalone_form', compact('domain', 'errors', 'clients', 'breadcrumbs'), 'Edit Domain');
            return;
        }

        $this->model->update($id, $data);
        flash('success', "Domain '{$data['domain']}' updated.");
        redirect("/domains/$id");
    }

    public function destroy(int $id): void
    {
        $domain = $this->model->findById($id);
        if (!$domain) {
            flash('error', 'Domain not found.');
            redirect('/domains');
            return;
        }

        $this->deleteDomain($id, $domain['domain']);
        flash('success', "Domain '{$domain['domain']}' deleted.");
        redirect('/domains');
    }

    public function bulkDelete(): void
    {
        $typedConfirm = trim($_POST['confirm_text'] ?? '');
        if ($typedConfirm !== 'DELETE') {
            flash('error', 'Confirmation text did not match. Bulk delete cancelled.');
            redirect('/domains');
            return;
        }

        $ids = array_map('intval', (array)($_POST['domain_ids'] ?? []));
        if (empty($ids)) { redirect('/domains'); return; }

        $deleted = 0;
        foreach ($ids as $id) {
            $domain = $this->model->findById($id);
            if ($domain) {
                $this->deleteDomain($id, $domain['domain']);
                $deleted++;
            }
        }

        flash('success', "$deleted domain" . ($deleted !== 1 ? 's' : '') . " deleted.");
        redirect('/domains');
    }

    public function createRecurringCost(int $id): void
    {
        $domain = $this->model->findById($id);
        if (!$domain || !$domain['client_id']) {
            flash('error', 'Domain not found or has no client assigned.');
            redirect('/domains');
            return;
        }

        // Check if one already exists
        $existing = $this->findLinkedRecurringCost($id, $domain['domain'], (int)$domain['client_id']);
        if ($existing) {
            flash('info', 'A recurring cost already exists for this domain.');
            redirect("/domains/$id/edit");
            return;
        }

        // Find the "Domain Registration" category
        $catId = $this->db->query("SELECT id FROM expense_categories WHERE LOWER(name) LIKE '%domain%' LIMIT 1")->fetchColumn();
        if (!$catId) {
            $catId = $this->db->query("SELECT id FROM expense_categories ORDER BY id LIMIT 1")->fetchColumn();
        }

        // Create the recurring cost
        $stmt = $this->db->prepare("INSERT INTO recurring_costs (name, category_id, amount, billing_cycle, renewal_date, is_active, currency, created_at, updated_at) VALUES (?, ?, ?, 'annual', ?, 1, ?, datetime('now'), datetime('now'))");
        $stmt->execute([
            'Domain: ' . $domain['domain'],
            $catId,
            (float)($domain['annual_cost'] ?? 0),
            $domain['renewal_date'] ?: null,
            $domain['currency'] ?? 'GBP',
        ]);
        $rcId = (int)$this->db->lastInsertId();

        // Link to the client
        $this->db->prepare("INSERT INTO recurring_cost_clients (recurring_cost_id, client_id) VALUES (?, ?)")->execute([$rcId, $domain['client_id']]);

        flash('success', "Recurring cost created for domain '{$domain['domain']}'.");
        redirect("/domains/$id/edit");
    }

    public function archive(int $id): void
    {
        $domain = $this->model->findById($id);
        if (!$domain) { flash('error', 'Domain not found.'); redirect('/domains'); return; }

        $newStatus = ($domain['status'] ?? 'active') === 'active' ? 'archived' : 'active';
        $this->model->update($id, ['status' => $newStatus]);
        $label = $newStatus === 'archived' ? 'archived' : 'restored';
        flash('success', "Domain '{$domain['domain']}' {$label}.");
        redirect('/domains' . ($newStatus === 'archived' ? '?status=archived' : ''));
    }

    public function bulkArchive(): void
    {
        $ids = array_map('intval', (array)($_POST['domain_ids'] ?? []));
        if (empty($ids)) { redirect('/domains'); return; }

        $count = 0;
        foreach ($ids as $id) {
            $domain = $this->model->findById($id);
            if ($domain) {
                $this->model->update($id, ['status' => 'archived']);
                $count++;
            }
        }

        flash('success', "$count domain" . ($count !== 1 ? 's' : '') . " archived.");
        redirect('/domains');
    }

    private function deleteDomain(int $id, string $name): void
    {
        // Unlink sites referencing this domain (don't delete the site)
        $this->db->prepare("UPDATE client_sites SET domain_id = NULL WHERE domain_id = ?")->execute([$id]);
        // Unlink Cloudflare zone
        try { $this->db->prepare("UPDATE cloudflare_zones SET domain_id = NULL WHERE domain_id = ?")->execute([$id]); } catch (\Throwable) {}
        // Log deletion
        try {
            $this->db->prepare("INSERT INTO deletion_log (entity_type, entity_id, entity_name, related_data, deleted_at) VALUES ('domain', ?, ?, ?, datetime('now'))")->execute([$id, $name, json_encode(['unlinked_sites' => true])]);
        } catch (\Throwable) {}
        $this->model->delete($id);
    }

    private function sanitise(array $post): array
    {
        return [
            'client_id'          => ($post['client_id'] ?? '') !== '' ? (int)$post['client_id'] : null,
            'domain'             => trim($post['domain'] ?? ''),
            'registrar'          => trim($post['registrar'] ?? ''),
            'cloudflare_proxied' => isset($post['cloudflare_proxied']) ? 1 : 0,
            'renewal_date'       => $post['renewal_date'] ?: null,
            'annual_cost'        => $post['annual_cost'] !== '' ? (float)$post['annual_cost'] : null,
            'currency'           => in_array($post['currency'] ?? '', ['GBP','USD','EUR']) ? $post['currency'] : 'GBP',
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!$data['domain']) $errors['domain'] = 'Domain name is required.';
        return $errors;
    }

    private function findLinkedRecurringCost(int $domainId, string $domainName, int $clientId): ?array
    {
        if (!$clientId) return null;
        try {
            $stmt = $this->db->prepare("
                SELECT rc.* FROM recurring_costs rc
                JOIN recurring_cost_clients rcc ON rcc.recurring_cost_id = rc.id
                WHERE rc.name = ? AND rcc.client_id = ?
                LIMIT 1
            ");
            $stmt->execute(['Domain: ' . $domainName, $clientId]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $this->db->query("SELECT $column FROM $table LIMIT 0");
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
