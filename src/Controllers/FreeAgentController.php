<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\FreeAgentRecurringInvoice;
use CoyshCRM\Services\FreeAgentClient;
use CoyshCRM\Services\FreeAgentSync;
use PDO;

class FreeAgentController
{
    private FreeAgentClient $fa;

    public function __construct(private PDO $db)
    {
        $this->fa = new FreeAgentClient($db);
    }

    // ── Overview page ─────────────────────────────────────────────────────

    public function index(): void
    {
        $connected = $this->fa->isConnected();

        if (!$connected) {
            render('freeagent.index', compact('connected'), 'FreeAgent');
            return;
        }

        // ── Summary stats ──────────────────────────────────────────────
        $thisYear = date('Y') . '-01-01';

        // Revenue aggregates exclude drafts, matching the dashboard and insights.
        $totalInvoiced = (float)$this->db->query("
            SELECT COALESCE(SUM(total_value), 0) FROM freeagent_invoices
            WHERE COALESCE(status_override, status) IN ('paid', 'sent', 'overdue')
        ")->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_value), 0) FROM freeagent_invoices
            WHERE COALESCE(status_override, status) IN ('paid', 'sent', 'overdue')
              AND dated_on >= ?
        ");
        $stmt->execute([$thisYear]);
        $thisYearInvoiced = (float)$stmt->fetchColumn();

        // Outstanding stays gross (ex-VAT net is only used for revenue aggregates).
        // One grouped pass gives both the headline figure and its sent/overdue split.
        $byStatus = [];
        foreach ($this->db->query("
            SELECT COALESCE(status_override, status) AS eff_status,
                   COUNT(*)                          AS cnt,
                   COALESCE(SUM(total_value), 0)     AS total
            FROM freeagent_invoices
            GROUP BY eff_status
        ")->fetchAll() as $row) {
            $byStatus[(string)$row['eff_status']] = [
                'count' => (int)$row['cnt'],
                'total' => (float)$row['total'],
            ];
        }

        $sentInvoiced    = $byStatus['sent']['total']    ?? 0.0;
        $sentCount       = $byStatus['sent']['count']    ?? 0;
        $overdueInvoiced = $byStatus['overdue']['total'] ?? 0.0;
        $overdueCount    = $byStatus['overdue']['count'] ?? 0;
        $unpaidInvoiced  = $sentInvoiced + $overdueInvoiced;
        $unpaidCount     = $sentCount + $overdueCount;

        // The invoices making up the unpaid figure, oldest due first.
        $unpaidInvoices = $this->db->query("
            SELECT fi.*,
                   COALESCE(fi.status_override, fi.status) AS eff_status,
                   c.name AS client_name
            FROM freeagent_invoices fi
            LEFT JOIN clients c ON c.id = fi.client_id
            WHERE COALESCE(fi.status_override, fi.status) IN ('sent', 'overdue')
            ORDER BY COALESCE(fi.due_date, fi.dated_on) ASC
        ")->fetchAll();

        $totalExpenses = (float)$this->db->query("
            SELECT COALESCE(SUM(ABS(gross_value)), 0) FROM freeagent_bank_transactions
            WHERE gross_value < 0
        ")->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(ABS(gross_value)), 0) FROM freeagent_bank_transactions
            WHERE gross_value < 0 AND dated_on >= ?
        ");
        $stmt->execute([$thisYear]);
        $thisYearExpenses = (float)$stmt->fetchColumn();

        $netIncome = $totalInvoiced - $totalExpenses;

        // ── By category ────────────────────────────────────────────────
        $byCategory = $this->db->query("
            SELECT
                COALESCE(category, 'Unmapped') AS category,
                COUNT(*) AS invoice_count,
                SUM(total_value) AS total
            FROM freeagent_invoices
            GROUP BY category
            ORDER BY total DESC
        ")->fetchAll();

        // ── Recent invoices ─────────────────────────────────────────────
        $recentInvoices = $this->db->query("
            SELECT fi.*, c.name AS client_name
            FROM freeagent_invoices fi
            LEFT JOIN clients c ON c.id = fi.client_id
            ORDER BY fi.dated_on DESC
            LIMIT 20
        ")->fetchAll();

        // ── Recent expenses ─────────────────────────────────────────────
        $recentExpenses = $this->db->query("
            SELECT * FROM freeagent_bank_transactions
            WHERE gross_value < 0
            ORDER BY dated_on DESC
            LIMIT 20
        ")->fetchAll();

        // ── Recurring invoices ──────────────────────────────────────────────
        $mrrSql = FreeAgentRecurringInvoice::monthlySql('fri');

        $confirmedMrr = (float)$this->db->query("
            SELECT COALESCE(SUM($mrrSql), 0)
            FROM freeagent_recurring_invoices fri
            WHERE recurring_status = 'Active'
        ")->fetchColumn();

        $pipelineMrr = (float)$this->db->query("
            SELECT COALESCE(SUM($mrrSql), 0)
            FROM freeagent_recurring_invoices fri
            WHERE recurring_status = 'Draft'
        ")->fetchColumn();

        $allRecurring = $this->db->query("
            SELECT fri.*, c.name AS client_name,
                   ($mrrSql) AS monthly_value
            FROM freeagent_recurring_invoices fri
            LEFT JOIN clients c ON c.id = fri.client_id
            ORDER BY CASE fri.recurring_status WHEN 'Active' THEN 0 ELSE 1 END,
                     c.name, fri.reference
        ")->fetchAll();

        // ── Sync history ────────────────────────────────────────────────
        $syncHistory = $this->db->query("
            SELECT * FROM freeagent_sync_log
            ORDER BY started_at DESC
            LIMIT 10
        ")->fetchAll();

        $lastSync = $this->db->query("
            SELECT * FROM freeagent_sync_log
            WHERE status = 'completed' AND sync_type = 'full'
            ORDER BY completed_at DESC LIMIT 1
        ")->fetch() ?: null;

        $lastError = $this->db->query("
            SELECT * FROM freeagent_sync_log
            WHERE status = 'failed'
            ORDER BY started_at DESC LIMIT 1
        ")->fetch() ?: null;

        $allClients = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();

        $includeCharts = true;

        render('freeagent.index', compact(
            'connected',
            'totalInvoiced', 'thisYearInvoiced', 'unpaidInvoiced', 'unpaidCount',
            'sentInvoiced', 'sentCount', 'overdueInvoiced', 'overdueCount', 'byStatus',
            'unpaidInvoices',
            'totalExpenses', 'thisYearExpenses', 'netIncome',
            'confirmedMrr', 'pipelineMrr', 'allRecurring',
            'byCategory', 'recentInvoices', 'recentExpenses',
            'syncHistory', 'lastSync', 'lastError', 'allClients', 'includeCharts'
        ), 'FreeAgent');
    }

    // ── AJAX sync trigger ─────────────────────────────────────────────────

    public function sync(): void
    {
        header('Content-Type: application/json');

        if (!$this->fa->isConnected()) {
            http_response_code(400);
            echo json_encode(['error' => 'FreeAgent is not connected.']);
            return;
        }

        set_time_limit(300);

        try {
            $sync    = new FreeAgentSync($this->db, $this->fa);
            $results = $sync->syncAll();
            echo json_encode(['ok' => true, 'results' => $results]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ── AJAX: assign client to invoice / recurring invoice ────────────────

    public function updateInvoiceClient(int $id): void
    {
        header('Content-Type: application/json');
        $clientId = $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : null;
        $this->db->prepare("UPDATE freeagent_invoices SET client_id = ? WHERE id = ?")->execute([$clientId, $id]);
        $name = $clientId ? $this->db->prepare("SELECT name FROM clients WHERE id = ? LIMIT 1")->execute([$clientId]) && null : null;
        if ($clientId) { $s = $this->db->prepare("SELECT name FROM clients WHERE id = ? LIMIT 1"); $s->execute([$clientId]); $name = $s->fetchColumn() ?: null; }
        echo json_encode(['ok' => true, 'client_name' => $name]);
        exit;
    }

    public function updateInvoiceStatusOverride(int $id): void
    {
        header('Content-Type: application/json');
        $raw  = trim($_POST['status_override'] ?? '');
        $note = trim($_POST['status_override_note'] ?? '');

        $override = in_array($raw, ['paid', 'sent', 'overdue'], true) ? $raw : null;
        $noteVal  = ($override !== null && $note !== '') ? $note : null;

        $this->db->prepare("UPDATE freeagent_invoices SET status_override = ?, status_override_note = ? WHERE id = ?")
            ->execute([$override, $noteVal, $id]);

        echo json_encode(['ok' => true, 'status_override' => $override, 'status_override_note' => $noteVal]);
        exit;
    }

    public function updateRecurringClient(int $id): void
    {
        header('Content-Type: application/json');
        $clientId = $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : null;
        $this->db->prepare("UPDATE freeagent_recurring_invoices SET client_id = ? WHERE id = ?")->execute([$clientId, $id]);
        $name = null;
        if ($clientId) { $s = $this->db->prepare("SELECT name FROM clients WHERE id = ? LIMIT 1"); $s->execute([$clientId]); $name = $s->fetchColumn() ?: null; }
        echo json_encode(['ok' => true, 'client_name' => $name]);
        exit;
    }

    // ── Per-client FreeAgent data (AJAX/partial render for client show) ───

    public function clientData(int $clientId): void
    {
        $invoices = $this->db->prepare("
            SELECT * FROM freeagent_invoices
            WHERE client_id = ?
            ORDER BY dated_on DESC
        ");
        $invoices->execute([$clientId]);
        $invoices = $invoices->fetchAll();

        $transactions = $this->db->prepare("
            SELECT * FROM freeagent_bank_transactions
            WHERE client_id = ? AND gross_value < 0
            ORDER BY dated_on DESC
        ");
        $transactions->execute([$clientId]);
        $transactions = $transactions->fetchAll();

        $totalInvoiced = array_sum(array_column($invoices, 'total_value'));
        $connected     = $this->fa->isConnected();

        header('Content-Type: application/json');
        echo json_encode(compact('invoices', 'transactions', 'totalInvoiced', 'connected'));
        exit;
    }
}
