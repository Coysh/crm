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

        $totalInvoiced = (float)$this->db->query("
            SELECT COALESCE(SUM(total_value), 0) FROM freeagent_invoices
        ")->fetchColumn();

        $thisYearInvoiced = (float)$this->db->prepare("
            SELECT COALESCE(SUM(total_value), 0) FROM freeagent_invoices WHERE dated_on >= ?
        ")->execute([$thisYear]) ? $this->db->query("
            SELECT COALESCE(SUM(total_value), 0) FROM freeagent_invoices WHERE dated_on >= " . $this->db->quote($thisYear)
        )->fetchColumn() : 0;

        // Use prepared statements properly
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(total_value), 0) FROM freeagent_invoices WHERE dated_on >= ?");
        $stmt->execute([$thisYear]);
        $thisYearInvoiced = (float)$stmt->fetchColumn();

        $unpaidInvoiced = (float)$this->db->query("
            SELECT COALESCE(SUM(total_value), 0) FROM freeagent_invoices
            WHERE status IN ('sent', 'overdue')
        ")->fetchColumn();

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

        render('freeagent.index', compact(
            'connected',
            'totalInvoiced', 'thisYearInvoiced', 'unpaidInvoiced',
            'totalExpenses', 'thisYearExpenses', 'netIncome',
            'confirmedMrr', 'pipelineMrr', 'allRecurring',
            'byCategory', 'recentInvoices', 'recentExpenses',
            'syncHistory', 'lastSync', 'lastError', 'allClients'
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
