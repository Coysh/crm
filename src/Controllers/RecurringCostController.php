<?php
declare(strict_types=1);
namespace CoyshCRM\Controllers;

use CoyshCRM\Models\RecurringCost;
use CoyshCRM\Models\ExpenseCategory;
use PDO;

class RecurringCostController
{
    private RecurringCost   $model;
    private ExpenseCategory $catModel;

    public function __construct(private PDO $db)
    {
        $this->model    = new RecurringCost($db);
        $this->catModel = new ExpenseCategory($db);
    }

    public function create(): void
    {
        $cost    = [];
        $errors  = [];

        // Pre-fill from an existing expense record
        if (!empty($_GET['from_expense'])) {
            $exp = $this->db->prepare("SELECT * FROM expenses WHERE id = ? LIMIT 1");
            $exp->execute([(int)$_GET['from_expense']]);
            if ($row = $exp->fetch()) {
                $cost = [
                    'name'          => $row['name'],
                    'amount'        => $row['amount'],
                    'billing_cycle' => in_array($row['billing_cycle'], ['monthly','annual']) ? $row['billing_cycle'] : 'monthly',
                    'category_id'   => $row['category_id'],
                    'client_prefill'=> $row['client_id'],
                ];
            }
        }

        // Pre-fill from a FreeAgent bill
        if (!empty($_GET['from_bill'])) {
            $stmt = $this->db->prepare("SELECT * FROM freeagent_bills WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$_GET['from_bill']]);
            if ($row = $stmt->fetch()) {
                $cost = [
                    'name'          => trim(($row['contact_name'] ?? '') . ($row['reference'] ? ' — ' . $row['reference'] : '')),
                    'amount'        => $row['total_value'],
                    'billing_cycle' => $row['is_recurring'] ? 'monthly' : 'monthly',
                    'provider'      => $row['contact_name'],
                ];
            }
        }

        // Pre-fill for a server (linked hosting cost)
        if (!empty($_GET['for_server'])) {
            $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$_GET['for_server']]);
            if ($row = $stmt->fetch()) {
                $hostingCatId = (int)($this->db->query("SELECT id FROM expense_categories WHERE slug='hosting_costs' LIMIT 1")->fetchColumn() ?: 0);
                $cost = [
                    'name'          => $row['name'] . ' — Hosting',
                    'provider'      => $row['provider'],
                    'billing_cycle' => 'monthly',
                    'category_id'   => $hostingCatId ?: null,
                    '_for_server'   => (int)$_GET['for_server'],
                ];
            }
        }

        $categories  = $this->catModel->asDropdownById();
        $clients     = $this->db->query("SELECT id, name FROM clients WHERE status='active' ORDER BY name")->fetchAll();
        $sites       = $this->fetchSitesGrouped();
        $breadcrumbs = [['Expenses', '/expenses?tab=recurring'], ['Add Recurring Cost', null]];
        render('expenses.recurring_form', compact('cost','errors','categories','clients','sites','breadcrumbs'), 'Add Recurring Cost');
    }

    public function store(): void
    {
        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            $cost        = array_merge($_POST, $data);
            $categories  = $this->catModel->asDropdownById();
            $clients     = $this->db->query("SELECT id, name FROM clients WHERE status='active' ORDER BY name")->fetchAll();
            $sites       = $this->fetchSitesGrouped();
            $breadcrumbs = [['Expenses', '/expenses?tab=recurring'], ['Add Recurring Cost', null]];
            render('expenses.recurring_form', compact('cost','errors','categories','clients','sites','breadcrumbs'), 'Add Recurring Cost');
            return;
        }

        $serverId = !empty($_POST['_for_server']) ? (int)$_POST['_for_server'] : null;

        $this->db->prepare("
            INSERT INTO recurring_costs (name,category_id,amount,billing_cycle,renewal_date,provider,url,notes,server_id,currency,is_active,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))
        ")->execute([$data['name'],$data['category_id'],$data['amount'],$data['billing_cycle'],$data['renewal_date'],$data['provider'],$data['url'],$data['notes'],$serverId,$data['currency'],$data['is_active']]);
        $id = (int)$this->db->lastInsertId();

        // Server-linked costs use dynamic apportionment — no junction rows needed
        if (!$serverId) {
            $this->model->saveAssignments($id, $_POST['assignment_type'] ?? 'none', (array)($_POST['assignment_ids'] ?? []));
        }

        // Link a FreeAgent bill to this recurring cost if we came from one
        if (!empty($_POST['_from_bill'])) {
            $this->db->prepare("UPDATE freeagent_bills SET recurring_cost_id = ?, reviewed = 1 WHERE id = ?")->execute([$id, (int)$_POST['_from_bill']]);
        }

        flash('success', "Recurring cost '{$data['name']}' added.");
        redirect('/expenses?tab=recurring');
    }

    public function edit(int $id): void
    {
        $cost = $this->model->findByIdWithAssignments($id);
        if (!$cost) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $errors      = [];
        $categories  = $this->catModel->asDropdownById();
        $clients     = $this->db->query("SELECT id, name FROM clients WHERE status='active' ORDER BY name")->fetchAll();
        $sites       = $this->fetchSitesGrouped();
        $breadcrumbs = [['Expenses', '/expenses?tab=recurring'], [e($cost['name']), null]];
        render('expenses.recurring_form', compact('cost','errors','categories','clients','sites','breadcrumbs'), 'Edit Recurring Cost');
    }

    public function update(int $id): void
    {
        $cost = $this->model->findById($id);
        if (!$cost) { http_response_code(404); render('errors.404', [], '404 Not Found'); return; }

        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            $cost        = array_merge($cost, $data);
            $cost['client_assignments'] = (array)($_POST['assignment_ids'] ?? []);
            $cost['site_assignments']   = [];
            $categories  = $this->catModel->asDropdownById();
            $clients     = $this->db->query("SELECT id, name FROM clients WHERE status='active' ORDER BY name")->fetchAll();
            $sites       = $this->fetchSitesGrouped();
            $breadcrumbs = [['Expenses', '/expenses?tab=recurring'], ['Edit', null]];
            render('expenses.recurring_form', compact('cost','errors','categories','clients','sites','breadcrumbs'), 'Edit Recurring Cost');
            return;
        }

        $this->db->prepare("UPDATE recurring_costs SET name=?,category_id=?,amount=?,billing_cycle=?,renewal_date=?,provider=?,url=?,notes=?,currency=?,is_active=?,updated_at=datetime('now') WHERE id=?")
            ->execute([$data['name'],$data['category_id'],$data['amount'],$data['billing_cycle'],$data['renewal_date'],$data['provider'],$data['url'],$data['notes'],$data['currency'],$data['is_active'],$id]);

        // Only save junction assignments for non-server-linked costs
        if (empty($cost['server_id'])) {
            $this->model->saveAssignments($id, $_POST['assignment_type'] ?? 'none', (array)($_POST['assignment_ids'] ?? []));
        }

        flash('success', "Recurring cost '{$data['name']}' updated.");
        redirect('/expenses?tab=recurring');
    }

    public function destroy(int $id): void
    {
        $cost = $this->model->findById($id);
        if (!$cost) { redirect('/expenses?tab=recurring'); return; }
        $this->db->prepare("DELETE FROM recurring_costs WHERE id = ?")->execute([$id]);
        flash('success', "Recurring cost '{$cost['name']}' deleted.");
        redirect('/expenses?tab=recurring');
    }

    public function toggle(int $id): void
    {
        $cost = $this->model->findById($id);
        if (!$cost) { redirect('/expenses?tab=recurring'); return; }
        $newState = $cost['is_active'] ? 0 : 1;
        $this->db->prepare("UPDATE recurring_costs SET is_active = ?, updated_at = datetime('now') WHERE id = ?")->execute([$newState, $id]);
        flash('success', "Recurring cost '{$cost['name']}' " . ($newState ? 'activated' : 'deactivated') . '.');
        redirect('/expenses?tab=recurring');
    }

    private function fetchSitesGrouped(): array
    {
        $rows = $this->db->query("
            SELECT cs.id, cs.client_id, c.name AS client_name, COALESCE(d.domain, 'Site #'||cs.id) AS domain_label
            FROM client_sites cs
            LEFT JOIN clients c ON c.id = cs.client_id
            LEFT JOIN domains d ON d.id = cs.domain_id
            ORDER BY c.name, d.domain
        ")->fetchAll();

        $grouped = [];
        foreach ($rows as $r) {
            $grouped[$r['client_name'] ?? 'Unassigned'][] = $r;
        }
        return $grouped;
    }

    private function sanitise(array $post): array
    {
        return [
            'name'         => trim($post['name'] ?? ''),
            'category_id'  => (int)($post['category_id'] ?? 0),
            'amount'       => (float)($post['amount'] ?? 0),
            'billing_cycle'=> in_array($post['billing_cycle'] ?? '', ['monthly','annual']) ? $post['billing_cycle'] : 'monthly',
            'renewal_date' => $post['renewal_date'] ?: null,
            'provider'     => trim($post['provider'] ?? ''),
            'url'          => trim($post['url'] ?? ''),
            'notes'        => trim($post['notes'] ?? ''),
            'currency'     => in_array($post['currency'] ?? '', ['GBP','USD','EUR']) ? $post['currency'] : 'GBP',
            'is_active'    => isset($post['is_active']) ? 1 : 0,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (!$data['name'])        $errors['name']        = 'Name is required.';
        if (!$data['category_id']) $errors['category_id'] = 'Category is required.';
        if ($data['amount'] <= 0)  $errors['amount']      = 'Amount must be greater than 0.';
        return $errors;
    }
}
