<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use CoyshCRM\Models\FreeAgentRecurringInvoice;
use CoyshCRM\Services\ExchangeRateService;
use PDO;

class DashboardController
{
    public function __construct(private PDO $db) {}

    public function index(): void
    {
        $clientModel = new Client($this->db);

        // ── Summary stats ──────────────────────────────────────────────────

        $mrrSql = FreeAgentRecurringInvoice::monthlySql();

        $mrr = (float)$this->db->query("
            SELECT COALESCE(SUM($mrrSql), 0)
            FROM freeagent_recurring_invoices
            WHERE recurring_status = 'Active'
        ")->fetchColumn();

        $pipelineMrr = (float)$this->db->query("
            SELECT COALESCE(SUM($mrrSql), 0)
            FROM freeagent_recurring_invoices
            WHERE recurring_status = 'Draft'
        ")->fetchColumn();

        $monthlyExpenses = (float)$this->db->query("
            SELECT COALESCE(SUM(
                CASE billing_cycle
                    WHEN 'monthly' THEN amount
                    WHEN 'annual'  THEN amount / 12
                    ELSE 0
                END
            ), 0) AS monthly
            FROM expenses
            WHERE ignore_from_stats = 0
        ")->fetchColumn();

        // Recurring costs — convert non-GBP amounts if migration 014 applied
        $monthlyRecurringCosts = 0.0;
        try {
            $rcRows = $this->db->query("
                SELECT amount, billing_cycle, COALESCE(currency, 'GBP') AS currency
                FROM recurring_costs WHERE is_active = 1
            ")->fetchAll();
            $fx = new ExchangeRateService($this->db);
            foreach ($rcRows as $rc) {
                $monthly = $rc['billing_cycle'] === 'monthly' ? $rc['amount'] : $rc['amount'] / 12.0;
                $monthlyRecurringCosts += $fx->convertToGBP((float)$monthly, $rc['currency']);
            }
        } catch (\Throwable) {
            // Pre-migration fallback — no currency column
            $monthlyRecurringCosts = (float)$this->db->query("
                SELECT COALESCE(SUM(
                    CASE billing_cycle WHEN 'monthly' THEN amount ELSE amount / 12.0 END
                ), 0)
                FROM recurring_costs WHERE is_active = 1
            ")->fetchColumn();
        }

        $totalCosts = $monthlyExpenses + $monthlyRecurringCosts;
        $profit     = $mrr - $totalCosts;

        $activeClientCount = (int)$this->db->query(
            "SELECT COUNT(*) FROM clients WHERE status = 'active'"
        )->fetchColumn();

        $serverCount = (int)$this->db->query("SELECT COUNT(*) FROM servers")->fetchColumn();

        // ── Per-client P&L ────────────────────────────────────────────────

        $activeClients = $clientModel->findAll(['status' => 'active'], 'name');
        $clientPL = [];
        foreach ($activeClients as $client) {
            $pl = $clientModel->getPL((int)$client['id']);
            $clientPL[] = array_merge($client, $pl);
        }

        // ── Upcoming renewals (30 days overdue → 90 days ahead) ──────────

        $today   = date('Y-m-d');
        $cutoff  = date('Y-m-d', strtotime('-30 days'));
        $horizon = date('Y-m-d', strtotime('+90 days'));

        $stmt = $this->db->prepare("
            SELECT 'domain' AS type, d.domain AS name, d.renewal_date AS due_date,
                   d.annual_cost AS amount, 'annual' AS billing_cycle,
                   c.name AS client_name, c.id AS client_id,
                   '/clients/' || c.id AS detail_url
            FROM domains d LEFT JOIN clients c ON c.id = d.client_id
            WHERE d.renewal_date IS NOT NULL
              AND d.renewal_date BETWEEN ? AND ?

            UNION ALL

            SELECT 'recurring_cost' AS type, rc.name, rc.renewal_date AS due_date,
                   rc.amount, rc.billing_cycle,
                   NULL AS client_name, NULL AS client_id,
                   '/expenses/recurring/' || rc.id || '/edit' AS detail_url
            FROM recurring_costs rc
            WHERE rc.renewal_date IS NOT NULL AND rc.is_active = 1
              AND rc.renewal_date BETWEEN ? AND ?

            UNION ALL

            SELECT 'recurring_invoice' AS type, COALESCE(fri.reference, 'Recurring Invoice') AS name,
                   fri.next_recurs_on AS due_date,
                   fri.total_value AS amount, fri.frequency AS billing_cycle,
                   c.name AS client_name, c.id AS client_id,
                   '/clients/' || c.id AS detail_url
            FROM freeagent_recurring_invoices fri
            LEFT JOIN clients c ON c.id = fri.client_id
            WHERE fri.next_recurs_on IS NOT NULL AND fri.recurring_status = 'Active'
              AND fri.next_recurs_on BETWEEN ? AND ?

            ORDER BY due_date ASC
        ");
        $stmt->execute([$cutoff, $horizon, $cutoff, $horizon, $cutoff, $horizon]);
        $allRenewals = $stmt->fetchAll();

        // Annotate with relative time and urgency
        $todayTs = strtotime($today);
        foreach ($allRenewals as &$r) {
            $dueTs    = strtotime($r['due_date']);
            $diffDays = (int)round(($dueTs - $todayTs) / 86400);
            $r['days_diff'] = $diffDays;
            if ($diffDays < 0) {
                $r['relative'] = 'Overdue';
                $r['urgency']  = 'red';
            } elseif ($diffDays <= 7) {
                $r['relative'] = $diffDays === 0 ? 'Today' : ($diffDays === 1 ? 'Tomorrow' : "{$diffDays} days");
                $r['urgency']  = 'red';
            } elseif ($diffDays <= 30) {
                $weeks = max(1, (int)round($diffDays / 7));
                $r['relative'] = $weeks === 1 ? "{$diffDays} days" : "{$weeks} weeks";
                $r['urgency']  = 'amber';
            } else {
                $months = max(1, (int)round($diffDays / 30));
                $r['relative'] = "{$months} month" . ($months > 1 ? 's' : '');
                $r['urgency']  = 'default';
            }
        }
        unset($r);

        $totalRenewals    = count($allRenewals);
        $upcomingRenewals = array_slice($allRenewals, 0, 5);

        // ── Year-on-Year ──────────────────────────────────────────────────

        $fy = $_GET['fy'] ?? 'tax';
        $fy = in_array($fy, ['tax', 'calendar']) ? $fy : 'tax';

        $now   = new \DateTime('today');
        $month = (int)$now->format('n');
        $year  = (int)$now->format('Y');

        if ($fy === 'tax') {
            $fyStartYear = $month < 4 ? $year - 1 : $year;
            $startThis   = new \DateTime("{$fyStartYear}-04-01");
        } else {
            $startThis = new \DateTime("{$year}-01-01");
        }
        $endThis   = clone $now;
        $startLast = (clone $startThis)->modify('-1 year');
        $endLast   = (clone $endThis)->modify('-1 year');

        $startThisStr = $startThis->format('Y-m-d');
        $endThisStr   = $endThis->format('Y-m-d');
        $startLastStr = $startLast->format('Y-m-d');
        $endLastStr   = $endLast->format('Y-m-d');

        $daysThis  = $startThis->diff($endThis)->days + 1;
        $daysLast  = $startLast->diff($endLast)->days + 1;
        $monthsThis = $daysThis / 30.44;
        $monthsLast = $daysLast / 30.44;

        $yoyRevenueThis = $this->sumInvoices($startThisStr, $endThisStr);
        $yoyRevenueLast = $this->sumInvoices($startLastStr, $endLastStr);

        $yoyCostsThis = $this->sumCosts($startThisStr, $endThisStr, $monthsThis, $daysThis);
        $yoyCostsLast = $this->sumCosts($startLastStr, $endLastStr, $monthsLast, $daysLast);

        $yoyProfitThis = $yoyRevenueThis - $yoyCostsThis;
        $yoyProfitLast = $yoyRevenueLast - $yoyCostsLast;

        $yoyClientsThis = $this->activeInvoicedClientCount($startThisStr, $endThisStr);
        $yoyClientsLast = $this->activeInvoicedClientCount($startLastStr, $endLastStr);

        $yoyLabelThis = $startThis->format("M 'y") . ' – now';
        $yoyLabelLast = $startLast->format("M 'y") . ' – ' . $endLast->format("M 'y");

        // ── Client health ─────────────────────────────────────────────────

        $healthAll    = $clientModel->getHealthAll();
        $healthCounts = ['healthy' => 0, 'attention' => 0, 'at_risk' => 0];
        $healthRows   = [];

        foreach ($activeClients as $c) {
            $cid = (int)$c['id'];
            $h   = $healthAll[$cid] ?? ['status' => 'healthy', 'flags' => []];
            $healthCounts[$h['status']] = ($healthCounts[$h['status']] ?? 0) + 1;
            $healthRows[] = array_merge($c, $h);
        }

        render('dashboard.index', compact(
            'mrr', 'pipelineMrr', 'totalCosts', 'profit', 'activeClientCount', 'serverCount',
            'clientPL',
            'upcomingRenewals', 'totalRenewals',
            'fy', 'yoyLabelThis', 'yoyLabelLast',
            'yoyRevenueThis', 'yoyRevenueLast',
            'yoyCostsThis', 'yoyCostsLast',
            'yoyProfitThis', 'yoyProfitLast',
            'yoyClientsThis', 'yoyClientsLast',
            'healthAll', 'healthCounts', 'healthRows'
        ), 'Dashboard');
    }

    private function sumInvoices(string $start, string $end): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(total_value), 0) FROM freeagent_invoices
                WHERE dated_on BETWEEN ? AND ?
                  AND status IN ('paid', 'sent', 'overdue')
            ");
            $stmt->execute([$start, $end]);
            return (float)$stmt->fetchColumn();
        } catch (\Throwable) {
            return 0.0;
        }
    }

    private function sumCosts(string $start, string $end, float $months, int $days): float
    {
        $stmtDirect = $this->db->prepare("
            SELECT COALESCE(SUM(
                CASE billing_cycle
                    WHEN 'monthly' THEN amount * :months
                    WHEN 'annual'  THEN amount * (:days / 365.0)
                    ELSE amount
                END
            ), 0) FROM expenses WHERE date BETWEEN :start AND :end AND ignore_from_stats = 0
        ");
        $stmtDirect->execute([':months' => $months, ':days' => $days, ':start' => $start, ':end' => $end]);
        $direct = (float)$stmtDirect->fetchColumn();

        $stmtRecurring = $this->db->prepare("
            SELECT COALESCE(SUM(
                CASE billing_cycle
                    WHEN 'monthly' THEN amount * :months
                    ELSE amount * (:days / 365.0)
                END
            ), 0) FROM recurring_costs WHERE is_active = 1
        ");
        $stmtRecurring->execute([':months' => $months, ':days' => $days]);
        $recurring = (float)$stmtRecurring->fetchColumn();

        return $direct + $recurring;
    }

    private function activeInvoicedClientCount(string $start, string $end): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT client_id) FROM freeagent_invoices
                WHERE dated_on BETWEEN ? AND ?
                  AND status IN ('paid','sent','overdue')
                  AND client_id IS NOT NULL
            ");
            $stmt->execute([$start, $end]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
