<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use PDO;

class DashboardController
{
    public function __construct(private PDO $db) {}

    public function index(): void
    {
        $clientModel = new Client($this->db);

        // ── Summary stats ──────────────────────────────────────────────────

        $mrr = (float)$this->db->query("
            SELECT COALESCE(SUM(
                CASE billing_cycle
                    WHEN 'monthly' THEN fee
                    WHEN 'annual'  THEN fee / 12
                    ELSE 0
                END
            ), 0) AS mrr
            FROM service_packages
            WHERE is_active = 1
        ")->fetchColumn();

        $totalServerCosts = (float)$this->db->query(
            "SELECT COALESCE(SUM(monthly_cost), 0) FROM servers"
        )->fetchColumn();

        $monthlyExpenses = (float)$this->db->query("
            SELECT COALESCE(SUM(
                CASE billing_cycle
                    WHEN 'monthly' THEN amount
                    WHEN 'annual'  THEN amount / 12
                    ELSE 0
                END
            ), 0) AS monthly
            FROM expenses
        ")->fetchColumn();

        $totalCosts = $totalServerCosts + $monthlyExpenses;
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

        // ── Upcoming renewals (next 30 days) ─────────────────────────────

        $today   = date('Y-m-d');
        $in30    = date('Y-m-d', strtotime('+30 days'));

        $renewals = $this->db->prepare("
            SELECT 'package' AS type, sp.name, sp.renewal_date AS due_date,
                   sp.fee AS amount, sp.billing_cycle, c.name AS client_name, c.id AS client_id
            FROM service_packages sp
            JOIN clients c ON c.id = sp.client_id
            WHERE sp.renewal_date BETWEEN ? AND ? AND sp.is_active = 1

            UNION ALL

            SELECT 'domain' AS type, d.domain AS name, d.renewal_date AS due_date,
                   d.annual_cost AS amount, 'annual' AS billing_cycle,
                   c.name AS client_name, c.id AS client_id
            FROM domains d
            JOIN clients c ON c.id = d.client_id
            WHERE d.renewal_date BETWEEN ? AND ?

            ORDER BY due_date ASC
        ");
        $renewals->execute([$today, $in30, $today, $in30]);
        $upcomingRenewals = $renewals->fetchAll();

        render('dashboard.index', compact(
            'mrr', 'totalCosts', 'profit', 'activeClientCount', 'serverCount',
            'clientPL', 'upcomingRenewals'
        ), 'Dashboard');
    }
}
