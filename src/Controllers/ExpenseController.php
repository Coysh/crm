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
        $tab = $_GET['tab'] ?? 'expenses';
        if (!in_array($tab, ['expenses', 'recurring', 'bills'])) $tab = 'expenses';

        $catModel   = new \CoyshCRM\Models\ExpenseCategory($this->db);
        $categories = $catModel->asDropdown();

        // ── Bills tab ─────────────────────────────────────────────────────────
        if ($tab === 'bills') {
            $billStatus     = in_array($_GET['bill_status'] ?? '', ['unreviewed','reviewed','recurring']) ? $_GET['bill_status'] : 'unreviewed';
            $showDuplicates = ($_GET['show_duplicates'] ?? '1') !== '0';

            $sql = "SELECT b.*, rc.name AS linked_cost_name FROM freeagent_bills b LEFT JOIN recurring_costs rc ON rc.id = b.recurring_cost_id WHERE 1=1";
            if ($billStatus === 'unreviewed') { $sql .= ' AND b.reviewed = 0'; }
            elseif ($billStatus === 'reviewed')  { $sql .= ' AND b.reviewed = 1'; }
            elseif ($billStatus === 'recurring')  { $sql .= ' AND b.is_recurring = 1'; }
            if (!$showDuplicates) { $sql .= ' AND b.potential_duplicate = 0'; }
            $sql  .= ' ORDER BY b.dated_on DESC LIMIT 500';
            try {
                $bills = $this->db->query($sql)->fetchAll();
            } catch (\Throwable) {
                $bills = []; // table doesn't exist yet (migration not run)
            }

            render('expenses.index', compact('tab','bills','billStatus','showDuplicates'), 'FreeAgent Bills');
            return;
        }

        // ── Recurring tab ────────────────────────────────────────────────────
        if ($tab === 'recurring') {
            $rcModel    = new \CoyshCRM\Models\RecurringCost($this->db);
            $categoryId = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? (int)$_GET['category_id'] : null;
            $status     = in_array($_GET['status'] ?? '', ['active','inactive']) ? $_GET['status'] : 'active';
            $search     = trim($_GET['search'] ?? '');
            $recurringCosts   = $rcModel->findAllWithRelations($categoryId, $status, $search ?: null);
            $totalMonthly     = $rcModel->totalMonthlyActive();
            $dbCategories     = $catModel->asDropdownById();
            $clients          = $this->clientModel->findAll([], 'name');
            $servers          = $this->serverModel->findAll([], 'name');
            $fx               = new \CoyshCRM\Services\ExchangeRateService($this->db);
            render('expenses.index', compact('tab','recurringCosts','totalMonthly','dbCategories','categories','clients','servers','categoryId','status','search','fx'), 'Recurring Costs');
            return;
        }

        // ── Expenses tab ─────────────────────────────────────────────────────
        $category = isset($_GET['category']) && $_GET['category'] !== '' ? $_GET['category'] : null;
        $clientId = isset($_GET['client_id']) && $_GET['client_id'] !== '' ? (int)$_GET['client_id'] : null;
        $serverId = isset($_GET['server_id']) && $_GET['server_id'] !== '' ? (int)$_GET['server_id'] : null;
        $source   = in_array($_GET['source'] ?? '', ['manual', 'freeagent']) ? $_GET['source'] : null;

        $expenses    = $this->model->findAllWithRelations($category, $clientId, $serverId, $source);
        $clients     = $this->clientModel->findAll([], 'name');
        $servers     = $this->serverModel->findAll([], 'name');
        $suggestions = $this->detectSuggestions();

        render('expenses.index', compact('tab','expenses','clients','servers','categories','category','clientId','serverId','source','suggestions'), 'Expenses');
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

        if (($expense['source'] ?? 'manual') === 'freeagent') {
            $breadcrumbs = [['Expenses', '/expenses'], [e($expense['name']), null]];
            $categories  = Expense::categories();
            $clients     = $this->clientModel->findAll([], 'name');
            render('expenses.readonly', compact('expense', 'breadcrumbs', 'categories', 'clients'), $expense['name']);
            return;
        }

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

        if (($expense['source'] ?? 'manual') === 'freeagent') {
            flash('error', 'FreeAgent expenses cannot be edited here.');
            redirect('/expenses');
            return;
        }

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

    public function toggleIgnore(int $id): void
    {
        $expense = $this->model->findById($id);
        if (!$expense) { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }

        $new = $expense['ignore_from_stats'] ? 0 : 1;
        $this->db->prepare("UPDATE expenses SET ignore_from_stats = ? WHERE id = ?")->execute([$new, $id]);

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'ignore_from_stats' => $new]);
        exit;
    }

    public function updateClient(int $id): void
    {
        header('Content-Type: application/json');
        $expense = $this->model->findById($id);
        if (!$expense) { http_response_code(404); echo json_encode(['error' => 'Not found']); return; }

        $clientId = $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : null;
        $this->db->prepare("UPDATE expenses SET client_id = ? WHERE id = ?")->execute([$clientId, $id]);

        $clientName = null;
        if ($clientId) {
            $row = $this->db->prepare("SELECT name FROM clients WHERE id = ? LIMIT 1");
            $row->execute([$clientId]);
            $clientName = $row->fetchColumn() ?: null;
        }

        echo json_encode(['ok' => true, 'client_name' => $clientName]);
        exit;
    }

    public function destroy(int $id): void
    {
        $expense = $this->model->findById($id);
        if (!$expense) { redirect('/expenses'); return; }

        if (($expense['source'] ?? 'manual') === 'freeagent') {
            flash('error', 'FreeAgent expenses are managed by the sync and cannot be deleted manually.');
            redirect('/expenses');
            return;
        }

        $this->model->delete($id);
        flash('success', "Expense '{$expense['name']}' deleted.");
        redirect('/expenses');
    }

    // ── Suggestions ───────────────────────────────────────────────────────────

    public function dismissSuggestion(): void
    {
        $key = trim($_POST['key'] ?? '');
        if ($key) {
            $row = $this->db->query("SELECT value FROM settings WHERE key='dismissed_suggestions' LIMIT 1")->fetch();
            $dismissed = $row ? (json_decode($row['value'], true) ?? []) : [];
            if (!in_array($key, $dismissed)) {
                $dismissed[] = $key;
                $json = json_encode($dismissed);
                $this->db->prepare("INSERT INTO settings (key,value,updated_at) VALUES ('dismissed_suggestions',?,datetime('now')) ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=excluded.updated_at")
                    ->execute([$json]);
            }
        }
        redirect('/expenses');
    }

    // ── Bills ─────────────────────────────────────────────────────────────────

    public function billDismiss(int $id): void
    {
        $this->db->prepare("UPDATE freeagent_bills SET reviewed = 1 WHERE id = ?")->execute([$id]);
        redirect('/expenses?tab=bills');
    }

    // ── Expense Categories ────────────────────────────────────────────────────

    public function categories(): void
    {
        $catModel    = new \CoyshCRM\Models\ExpenseCategory($this->db);
        $categories  = $catModel->findAllWithCounts();
        $breadcrumbs = [['Expenses', '/expenses'], ['Manage Categories', null]];
        render('expenses.categories', compact('categories', 'breadcrumbs'), 'Expense Categories');
    }

    public function storeCategory(): void
    {
        $name = trim($_POST['name'] ?? '');
        if (!$name) { flash('error', 'Name is required.'); redirect('/expenses/categories'); return; }
        $catModel = new \CoyshCRM\Models\ExpenseCategory($this->db);
        $slug     = $catModel->autoSlug($name);
        try {
            $this->db->prepare("INSERT INTO expense_categories (name, slug, sort_order, created_at) VALUES (?, ?, 99, datetime('now'))")->execute([$name, $slug]);
            flash('success', "Category '{$name}' added.");
        } catch (\Throwable) {
            flash('error', 'A category with that name already exists.');
        }
        redirect('/expenses/categories');
    }

    public function updateCategory(int $id): void
    {
        $name = trim($_POST['name'] ?? '');
        if (!$name) { flash('error', 'Name is required.'); redirect('/expenses/categories'); return; }
        $this->db->prepare("UPDATE expense_categories SET name = ? WHERE id = ?")->execute([$name, $id]);
        flash('success', 'Category updated.');
        redirect('/expenses/categories');
    }

    public function destroyCategory(int $id): void
    {
        $catModel = new \CoyshCRM\Models\ExpenseCategory($this->db);
        if (!$catModel->canDelete($id)) {
            flash('error', 'Cannot delete: category is a default or is in use.');
            redirect('/expenses/categories');
            return;
        }
        $this->db->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$id]);
        flash('success', 'Category deleted.');
        redirect('/expenses/categories');
    }

    // ── Detection ─────────────────────────────────────────────────────────────

    /**
     * Scan expenses for repeating patterns that suggest recurring costs.
     * Returns array of suggestion rows; already filters dismissed keys.
     */
    private function detectSuggestions(): array
    {
        // Only scan if the settings table exists (migration 011 applied)
        try {
            $this->db->query("SELECT 1 FROM settings LIMIT 1");
        } catch (\Throwable) {
            return [];
        }
        // Skip if no expenses to analyse
        $count = (int)$this->db->query("SELECT COUNT(*) FROM expenses WHERE date >= date('now','-15 months')")->fetchColumn();
        if ($count < 2) return [];

        $settingsRow = $this->db->query("SELECT value FROM settings WHERE key='dismissed_suggestions' LIMIT 1")->fetch();
        $dismissed   = $settingsRow ? (json_decode($settingsRow['value'], true) ?? []) : [];

        $expenses = $this->db->query("
            SELECT id, name, amount, billing_cycle, date, client_id, category_id
            FROM expenses
            WHERE date >= date('now', '-15 months') AND date IS NOT NULL
            ORDER BY LOWER(TRIM(name)), amount, date
        ")->fetchAll();

        // Group by normalised name + amount
        $groups = [];
        foreach ($expenses as $exp) {
            $norm = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($exp['name'])));
            $key  = $norm . '::' . number_format((float)$exp['amount'], 2);
            $groups[$key][] = $exp;
        }

        $suggestions = [];
        foreach ($groups as $key => $records) {
            if (count($records) < 2) continue;
            if (in_array($key, $dismissed)) continue;

            $dates = array_map(fn($r) => strtotime($r['date']), $records);
            sort($dates);

            $gaps = [];
            for ($i = 1; $i < count($dates); $i++) {
                $gaps[] = ($dates[$i] - $dates[$i - 1]) / 86400;
            }
            $avgGap = count($gaps) > 0 ? array_sum($gaps) / count($gaps) : 0;

            if ($avgGap >= 25 && $avgGap <= 35) {
                $frequency = 'monthly';
            } elseif ($avgGap >= 335 && $avgGap <= 395) {
                $frequency = 'annual';
            } elseif (count($records) >= 3) {
                // Enough occurrences to flag even with irregular spacing
                $frequency = $avgGap < 60 ? 'monthly' : 'annual';
            } else {
                continue;
            }

            // Determine shared client/category (null if mixed)
            $clientIds  = array_unique(array_column($records, 'client_id'));
            $categoryIds = array_unique(array_column($records, 'category_id'));
            $sharedClientId   = count($clientIds) === 1   ? $clientIds[0]   : null;
            $sharedCategoryId = count($categoryIds) === 1 ? $categoryIds[0] : null;

            $suggestions[] = [
                'key'        => $key,
                'name'       => $records[0]['name'],
                'amount'     => (float)$records[0]['amount'],
                'frequency'  => $frequency,
                'occurrences'=> count($records),
                'last_seen'  => date('Y-m-d', max($dates)),
                'client_id'  => $sharedClientId,
                'category_id'=> $sharedCategoryId,
                'expense_ids'=> array_column($records, 'id'),
            ];
        }

        usort($suggestions, fn($a, $b) => $b['occurrences'] - $a['occurrences']);
        return array_slice($suggestions, 0, 10); // cap at 10
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
            'notes'             => trim($post['notes'] ?? ''),
            'ignore_from_stats' => isset($post['ignore_from_stats']) ? 1 : 0,
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
