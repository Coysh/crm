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
                    LIMIT 1) AS linked_recurring_cost_id
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

        // Enrich each domain with its latest matching FreeAgent bill / invoice
        // so the Payment column reflects real payment status, not just a future
        // renewal date on the recurring cost.
        $domains = $this->attachPaymentInfo($domains, $today);

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

        // Linked recurring cost + FA payment data
        $recurringCost = null;
        if ($domain['client_id']) {
            $recurringCost = $this->findLinkedRecurringCost($id, $domain['domain'], (int)$domain['client_id']);
        }
        [$bills, $invoices] = $this->fetchFreeAgentForDomain($domain, $recurringCost);

        // Candidate records for the "Link existing invoice / bill" pickers —
        // everything not already linked, scoped to the client where that makes
        // sense.
        $linkedInvoiceIds = array_map('intval', array_column($invoices, 'id'));
        $linkedBillIds    = array_map('intval', array_column($bills, 'id'));
        $candidateInvoices = $this->fetchCandidateInvoices((int)($domain['client_id'] ?? 0), $linkedInvoiceIds);
        $candidateBills    = $this->fetchCandidateBills($linkedBillIds);

        // Reuse the same paid-state logic as the list view so both pages agree.
        $today = date('Y-m-d');
        $domainWithRc = $domain;
        $domainWithRc['linked_recurring_cost_id'] = $recurringCost['id'] ?? null;
        $paymentState = $this->derivePaymentState(
            $domainWithRc,
            $bills[0] ?? null,
            $invoices[0] ?? null,
            $today
        );

        $breadcrumbs = [['Domains', '/domains'], [$domain['domain'], null]];
        render(
            'domains.show',
            compact(
                'domain', 'client', 'cfZone', 'recurringCost',
                'bills', 'invoices', 'candidateInvoices', 'candidateBills',
                'paymentState', 'breadcrumbs'
            ),
            $domain['domain']
        );
    }

    public function linkInvoice(int $domainId): void
    {
        $invoiceId = (int)($_POST['freeagent_invoice_id'] ?? 0);
        if ($invoiceId > 0) {
            try {
                $this->db->prepare("
                    INSERT OR IGNORE INTO domain_invoice_links (domain_id, freeagent_invoice_id)
                    VALUES (?, ?)
                ")->execute([$domainId, $invoiceId]);
                flash('success', 'Invoice linked.');
            } catch (\Throwable $e) {
                flash('error', 'Could not link invoice: ' . $e->getMessage());
            }
        }
        redirect("/domains/$domainId");
    }

    public function unlinkInvoice(int $domainId, int $invoiceId): void
    {
        try {
            $this->db->prepare("
                DELETE FROM domain_invoice_links
                WHERE domain_id = ? AND freeagent_invoice_id = ?
            ")->execute([$domainId, $invoiceId]);
            flash('success', 'Invoice unlinked.');
        } catch (\Throwable $e) {
            flash('error', 'Could not unlink invoice: ' . $e->getMessage());
        }
        redirect("/domains/$domainId");
    }

    public function linkBill(int $domainId): void
    {
        $billId = (int)($_POST['freeagent_bill_id'] ?? 0);
        if ($billId > 0) {
            try {
                $this->db->prepare("
                    INSERT OR IGNORE INTO domain_bill_links (domain_id, freeagent_bill_id)
                    VALUES (?, ?)
                ")->execute([$domainId, $billId]);
                flash('success', 'Bill linked.');
            } catch (\Throwable $e) {
                flash('error', 'Could not link bill: ' . $e->getMessage());
            }
        }
        redirect("/domains/$domainId");
    }

    public function unlinkBill(int $domainId, int $billId): void
    {
        try {
            $this->db->prepare("
                DELETE FROM domain_bill_links
                WHERE domain_id = ? AND freeagent_bill_id = ?
            ")->execute([$domainId, $billId]);
            flash('success', 'Bill unlinked.');
        } catch (\Throwable $e) {
            flash('error', 'Could not unlink bill: ' . $e->getMessage());
        }
        redirect("/domains/$domainId");
    }

    /**
     * Returns [bills, invoices] for a domain. Records come from two sources:
     *   - manual links in domain_bill_links / domain_invoice_links (tagged
     *     link_type = 'manual')
     *   - auto matches: bills by recurring_cost_id, invoices by reference
     *     LIKE '%domain%' for the domain's client (tagged 'auto')
     *
     * @return array{0: array<int, array<string,mixed>>, 1: array<int, array<string,mixed>>}
     */
    private function fetchFreeAgentForDomain(array $domain, ?array $recurringCost): array
    {
        $domainId = (int)($domain['id'] ?? 0);

        // ── Bills ──────────────────────────────────────────────────────────
        $bills   = [];
        $seenBillIds = [];

        // Manual links first so "manual" label sticks even if the bill would
        // also match the auto query.
        try {
            $stmt = $this->db->prepare("
                SELECT fb.*, 'manual' AS link_type, dbl.id AS link_id
                FROM domain_bill_links dbl
                JOIN freeagent_bills fb ON fb.id = dbl.freeagent_bill_id
                WHERE dbl.domain_id = ?
                ORDER BY fb.dated_on DESC, fb.id DESC
            ");
            $stmt->execute([$domainId]);
            foreach ($stmt->fetchAll() as $row) {
                $bills[] = $row;
                $seenBillIds[(int)$row['id']] = true;
            }
        } catch (\Throwable) {}

        if (!empty($recurringCost['id'])) {
            try {
                $stmt = $this->db->prepare("
                    SELECT *, 'auto' AS link_type, NULL AS link_id
                    FROM freeagent_bills
                    WHERE recurring_cost_id = ?
                    ORDER BY dated_on DESC, id DESC
                ");
                $stmt->execute([(int)$recurringCost['id']]);
                foreach ($stmt->fetchAll() as $row) {
                    if (!isset($seenBillIds[(int)$row['id']])) {
                        $bills[] = $row;
                    }
                }
            } catch (\Throwable) {}
        }

        // ── Invoices ───────────────────────────────────────────────────────
        $invoices       = [];
        $seenInvoiceIds = [];

        try {
            $stmt = $this->db->prepare("
                SELECT fi.*, 'manual' AS link_type, dil.id AS link_id
                FROM domain_invoice_links dil
                JOIN freeagent_invoices fi ON fi.id = dil.freeagent_invoice_id
                WHERE dil.domain_id = ?
                ORDER BY fi.dated_on DESC, fi.id DESC
            ");
            $stmt->execute([$domainId]);
            foreach ($stmt->fetchAll() as $row) {
                $invoices[] = $row;
                $seenInvoiceIds[(int)$row['id']] = true;
            }
        } catch (\Throwable) {}

        if (!empty($domain['client_id']) && !empty($domain['domain'])) {
            try {
                $stmt = $this->db->prepare("
                    SELECT *, 'auto' AS link_type, NULL AS link_id
                    FROM freeagent_invoices
                    WHERE client_id = ?
                      AND reference IS NOT NULL
                      AND LOWER(reference) LIKE LOWER(?)
                    ORDER BY dated_on DESC, id DESC
                ");
                $stmt->execute([(int)$domain['client_id'], '%' . $domain['domain'] . '%']);
                foreach ($stmt->fetchAll() as $row) {
                    if (!isset($seenInvoiceIds[(int)$row['id']])) {
                        $invoices[] = $row;
                    }
                }
            } catch (\Throwable) {}
        }

        // Keep each list ordered by dated_on DESC across both sources
        $byDateDesc = fn ($a, $b) => strcmp((string)($b['dated_on'] ?? ''), (string)($a['dated_on'] ?? ''));
        usort($bills,    $byDateDesc);
        usort($invoices, $byDateDesc);

        return [$bills, $invoices];
    }

    /**
     * FreeAgent invoices that could be manually linked to this domain.
     * Scoped to the domain's client (if any) and to records not already linked.
     *
     * @param int[] $excludeIds
     * @return array<int, array<string,mixed>>
     */
    private function fetchCandidateInvoices(int $clientId, array $excludeIds): array
    {
        try {
            $sql = "SELECT id, reference, dated_on, total_value, currency, status, status_override, paid_on
                    FROM freeagent_invoices WHERE 1=1";
            $params = [];
            if ($clientId > 0) { $sql .= " AND client_id = ?"; $params[] = $clientId; }
            if ($excludeIds)   {
                $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
                $sql    .= " AND id NOT IN ($placeholders)";
                $params  = array_merge($params, $excludeIds);
            }
            $sql .= " ORDER BY dated_on DESC, id DESC LIMIT 200";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * FreeAgent bills that could be manually linked to this domain. Not
     * filtered by client (bills come from suppliers, so the client link isn't
     * meaningful) — limited to recent records to keep the dropdown manageable.
     *
     * @param int[] $excludeIds
     * @return array<int, array<string,mixed>>
     */
    private function fetchCandidateBills(array $excludeIds): array
    {
        try {
            $sql = "SELECT id, reference, dated_on, total_value, currency, status, contact_name
                    FROM freeagent_bills WHERE 1=1";
            $params = [];
            if ($excludeIds) {
                $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
                $sql    .= " AND id NOT IN ($placeholders)";
                $params  = $excludeIds;
            }
            $sql .= " ORDER BY dated_on DESC, id DESC LIMIT 200";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
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

        // Create the recurring cost using client charge (what the client pays), not my cost
        $stmt = $this->db->prepare("INSERT INTO recurring_costs (name, category_id, amount, billing_cycle, renewal_date, is_active, currency, created_at, updated_at) VALUES (?, ?, ?, 'annual', ?, 1, ?, datetime('now'), datetime('now'))");
        $stmt->execute([
            'Domain: ' . $domain['domain'],
            $catId,
            (float)($domain['client_charge'] ?? $domain['annual_cost'] ?? 0),
            $domain['renewal_date'] ?: null,
            $domain['client_charge_currency'] ?? 'GBP',
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

    /**
     * For each domain, attach the latest matching FreeAgent bill (supplier-side)
     * and the latest matching FreeAgent invoice (client-side), then derive a
     * payment_state of 'paid' | 'overdue' | 'unpaid' | 'pending' | 'unknown' | 'na'.
     *
     * - 'paid'    – a bill (or invoice) for this domain in the current renewal
     *               period has status 'Paid' (FA) / paid_on set (invoice).
     * - 'overdue' – the renewal_date is in the past, OR the latest bill/invoice
     *               has status 'Overdue'.
     * - 'unpaid' – there's a matching FA bill/invoice that's not paid (Open).
     * - 'pending' – linked recurring cost has renewal_date in the future but no
     *               FA data confirms payment yet (was previously shown as
     *               "Paid" — that was the bug).
     * - 'unknown' – linked recurring cost exists but renewal_date is not set
     *               and there's no FA data either.
     * - 'na'      – domain has no linked recurring cost, so there's nothing to
     *               check against.
     */
    private function attachPaymentInfo(array $domains, string $today): array
    {
        if (empty($domains)) return $domains;

        $domainIds = array_map('intval', array_column($domains, 'id'));

        // Bills keyed by recurring_cost_id (latest dated_on wins)
        $rcIds = array_values(array_unique(array_filter(array_column($domains, 'linked_recurring_cost_id'))));
        $billsByRcId = [];
        if ($rcIds) {
            try {
                $placeholders = implode(',', array_fill(0, count($rcIds), '?'));
                $stmt = $this->db->prepare("
                    SELECT * FROM freeagent_bills
                    WHERE recurring_cost_id IN ($placeholders)
                    ORDER BY dated_on DESC, id DESC
                ");
                $stmt->execute($rcIds);
                foreach ($stmt->fetchAll() as $b) {
                    $rcId = (int)$b['recurring_cost_id'];
                    if (!isset($billsByRcId[$rcId])) $billsByRcId[$rcId] = $b;
                }
            } catch (\Throwable) {}
        }

        // Manually-linked bills / invoices keyed by domain_id (latest wins).
        // These take precedence over auto matches so a user-curated link
        // always drives the payment state.
        $manualBillByDomain    = $this->latestManualLinksByDomain($domainIds, 'bill');
        $manualInvoiceByDomain = $this->latestManualLinksByDomain($domainIds, 'invoice');

        foreach ($domains as &$d) {
            $did  = (int)($d['id'] ?? 0);
            $rcId = (int)($d['linked_recurring_cost_id'] ?? 0);

            $bill    = $manualBillByDomain[$did]
                     ?? ($rcId ? ($billsByRcId[$rcId] ?? null) : null);
            $invoice = $manualInvoiceByDomain[$did] ?? null;

            // Fall back to reference-based auto match for invoices only if no
            // manual link exists.
            if (!$invoice && !empty($d['client_id']) && !empty($d['domain'])) {
                try {
                    $stmt = $this->db->prepare("
                        SELECT * FROM freeagent_invoices
                        WHERE client_id = ?
                          AND reference IS NOT NULL
                          AND LOWER(reference) LIKE LOWER(?)
                        ORDER BY dated_on DESC, id DESC
                        LIMIT 1
                    ");
                    $stmt->execute([(int)$d['client_id'], '%' . $d['domain'] . '%']);
                    $invoice = $stmt->fetch() ?: null;
                } catch (\Throwable) {}
            }

            $d['latest_bill']    = $bill ?: null;
            $d['latest_invoice'] = $invoice ?: null;
            $d['payment_state']  = $this->derivePaymentState($d, $bill, $invoice, $today);
        }
        unset($d);

        return $domains;
    }

    /**
     * Returns [domain_id => latest linked record] for the given domain IDs and
     * link type ('bill' or 'invoice'). "Latest" is by the underlying FA
     * record's dated_on.
     *
     * @param int[] $domainIds
     * @return array<int, array<string,mixed>>
     */
    private function latestManualLinksByDomain(array $domainIds, string $type): array
    {
        if (!$domainIds) return [];

        [$linkTable, $fkColumn, $targetTable] = $type === 'bill'
            ? ['domain_bill_links',    'freeagent_bill_id',    'freeagent_bills']
            : ['domain_invoice_links', 'freeagent_invoice_id', 'freeagent_invoices'];

        try {
            $placeholders = implode(',', array_fill(0, count($domainIds), '?'));
            $stmt = $this->db->prepare("
                SELECT link.domain_id, fa.*
                FROM $linkTable link
                JOIN $targetTable fa ON fa.id = link.$fkColumn
                WHERE link.domain_id IN ($placeholders)
                ORDER BY fa.dated_on DESC, fa.id DESC
            ");
            $stmt->execute($domainIds);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $did = (int)$row['domain_id'];
                if (!isset($out[$did])) $out[$did] = $row;
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function derivePaymentState(array $domain, ?array $bill, ?array $invoice, string $today): string
    {
        if (empty($domain['linked_recurring_cost_id']) || empty($domain['client_id'])) {
            return 'na';
        }

        $renewalYears = max(1, (int)($domain['renewal_years'] ?? 1));
        $renewalDate  = $domain['renewal_date'] ?? null;

        // Current renewal period: the renewal_years window leading up to the
        // next renewal. Anything paid inside that window covers the current
        // period; older payments don't.
        $periodStart = null;
        if ($renewalDate) {
            $periodStart = date('Y-m-d', strtotime("$renewalDate -{$renewalYears} years"));
        }

        $billPaid    = $this->isBillPaidInPeriod($bill, $periodStart);
        $invoicePaid = $this->isInvoicePaidInPeriod($invoice, $periodStart);

        if ($billPaid || $invoicePaid) return 'paid';

        if ($renewalDate && $renewalDate < $today) return 'overdue';

        $billOverdue    = $bill    && strtolower((string)$bill['status'])    === 'overdue';
        $invoiceOverdue = $invoice && strtolower((string)($invoice['status_override'] ?? $invoice['status'])) === 'overdue';
        if ($billOverdue || $invoiceOverdue) return 'overdue';

        if ($bill || $invoice) return 'unpaid';

        // Renewal date set in the future but no FreeAgent record confirms
        // payment. The previous version called this "Paid" — which was wrong.
        if ($renewalDate && $renewalDate >= $today) return 'pending';

        return 'unknown';
    }

    private function isBillPaidInPeriod(?array $bill, ?string $periodStart): bool
    {
        if (!$bill) return false;
        if (strtolower((string)$bill['status']) !== 'paid') return false;
        if ($periodStart && !empty($bill['dated_on']) && $bill['dated_on'] < $periodStart) return false;
        return true;
    }

    private function isInvoicePaidInPeriod(?array $invoice, ?string $periodStart): bool
    {
        if (!$invoice) return false;
        $status = strtolower((string)($invoice['status_override'] ?? $invoice['status']));
        $paidOn = $invoice['paid_on'] ?? null;
        if ($status !== 'paid' && empty($paidOn)) return false;
        $checkDate = $paidOn ?: ($invoice['dated_on'] ?? null);
        if ($periodStart && $checkDate && $checkDate < $periodStart) return false;
        return true;
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
