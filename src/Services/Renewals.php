<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;

/**
 * Shared upcoming-renewals query: domains, recurring costs, FreeAgent
 * recurring invoices, and agreement renewal dates, annotated with relative
 * time and urgency. Used by the dashboard, insights, /renewals, and MCP.
 */
class Renewals
{
    public const TYPES = ['domain', 'recurring_cost', 'recurring_invoice', 'agreement'];

    public function __construct(private PDO $db) {}

    /**
     * @param int         $days     Horizon in days ahead (up to a year of overdue history always included,
     *                              so a forgotten renewal escalates rather than silently dropping off)
     * @param string      $type     'all' or one of self::TYPES
     * @param int|null    $clientId Restrict to one client (rows without a client are excluded)
     */
    public function fetch(int $days = 90, string $type = 'all', ?int $clientId = null): array
    {
        $today   = date('Y-m-d');
        $cutoff  = date('Y-m-d', strtotime('-365 days'));
        $horizon = date('Y-m-d', strtotime("+{$days} days"));

        $clientFilter = $clientId !== null;
        $parts  = [];
        $params = [];

        if ($type === 'all' || $type === 'domain') {
            $parts[] = "
                SELECT 'domain' AS type, d.id AS item_id, d.domain AS name, d.renewal_date AS due_date,
                       COALESCE(d.client_charge, d.annual_cost) AS amount, 'annual' AS cycle,
                       c.id AS client_id, c.name AS client_name,
                       NULL AS shared_with,
                       '/domains/' || d.id AS detail_url
                FROM domains d LEFT JOIN clients c ON c.id = d.client_id
                WHERE d.renewal_date IS NOT NULL
                  AND COALESCE(d.status, 'active') != 'archived'
                  AND COALESCE(c.status, 'active') != 'archived'
                  AND d.renewal_date BETWEEN ? AND ?"
                . ($clientFilter ? ' AND d.client_id = ?' : '');
            array_push($params, $cutoff, $horizon);
            if ($clientFilter) $params[] = $clientId;
        }

        if (($type === 'all' || $type === 'recurring_cost') && !$clientFilter) {
            $parts[] = "
                SELECT 'recurring_cost' AS type, rc.id AS item_id, rc.name, rc.renewal_date AS due_date,
                       rc.amount, rc.billing_cycle AS cycle,
                       NULL AS client_id, NULL AS client_name,
                       (SELECT COUNT(DISTINCT client_id) FROM recurring_cost_clients WHERE recurring_cost_id = rc.id AND client_id IS NOT NULL) || ' clients' AS shared_with,
                       '/expenses/recurring/' || rc.id || '/edit' AS detail_url
                FROM recurring_costs rc
                WHERE rc.renewal_date IS NOT NULL AND rc.is_active = 1
                  AND rc.name NOT LIKE 'Domain: %'
                  AND rc.renewal_date BETWEEN ? AND ?";
            array_push($params, $cutoff, $horizon);
        }

        if ($type === 'all' || $type === 'recurring_invoice') {
            $parts[] = "
                SELECT 'recurring_invoice' AS type, fri.id AS item_id, COALESCE(fri.reference, 'Recurring Invoice') AS name,
                       fri.next_recurs_on AS due_date,
                       fri.total_value AS amount, fri.frequency AS cycle,
                       c.id AS client_id, c.name AS client_name,
                       NULL AS shared_with,
                       '/clients/' || c.id AS detail_url
                FROM freeagent_recurring_invoices fri
                LEFT JOIN clients c ON c.id = fri.client_id
                WHERE fri.next_recurs_on IS NOT NULL AND fri.recurring_status = 'Active'
                  AND COALESCE(c.status, 'active') != 'archived'
                  AND fri.next_recurs_on BETWEEN ? AND ?"
                . ($clientFilter ? ' AND fri.client_id = ?' : '');
            array_push($params, $cutoff, $horizon);
            if ($clientFilter) $params[] = $clientId;
        }

        if ($type === 'all' || $type === 'agreement') {
            $parts[] = "
                SELECT 'agreement' AS type, a.id AS item_id, a.title AS name,
                       a.renewal_date AS due_date,
                       a.fee_amount AS amount, a.fee_billing_cycle AS cycle,
                       c.id AS client_id, c.name AS client_name,
                       NULL AS shared_with,
                       '/clients/' || c.id AS detail_url
                FROM agreements a JOIN clients c ON c.id = a.client_id
                WHERE a.status = 'active' AND a.renewal_date IS NOT NULL
                  AND c.status != 'archived'
                  AND a.renewal_date BETWEEN ? AND ?"
                . ($clientFilter ? ' AND a.client_id = ?' : '');
            array_push($params, $cutoff, $horizon);
            if ($clientFilter) $params[] = $clientId;
        }

        if (!$parts) return [];

        $sql  = implode(' UNION ALL ', $parts) . ' ORDER BY due_date ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $todayTs = strtotime($today);
        foreach ($rows as &$row) {
            $dueTs    = strtotime($row['due_date']);
            $diffDays = (int)round(($dueTs - $todayTs) / 86400);
            $row['days_diff'] = $diffDays;
            if ($diffDays < 0) {
                $row['relative'] = 'Overdue';
                $row['urgency']  = 'red';
            } elseif ($diffDays <= 7) {
                $row['relative'] = $diffDays === 0 ? 'Today' : ($diffDays === 1 ? 'Tomorrow' : "{$diffDays} days");
                $row['urgency']  = 'red';
            } elseif ($diffDays <= 30) {
                $weeks = round($diffDays / 7);
                $row['relative'] = $weeks <= 1 ? "{$diffDays} days" : "{$weeks} weeks";
                $row['urgency']  = 'amber';
            } else {
                $months = round($diffDays / 30);
                $row['relative'] = $months <= 1 ? "{$diffDays} days" : "{$months} months";
                $row['urgency']  = 'default';
            }
        }
        unset($row);

        return $rows;
    }

    /** Renewal types that "Mark renewed" can roll forward (recurring invoices are FreeAgent's). */
    public const RENEWABLE = ['domain', 'recurring_cost', 'agreement'];

    /**
     * Roll a renewal date forward by one term — domains by renewal_years,
     * recurring costs by billing cycle, agreements by a year — repeating
     * until it lands on or after today, so a late renewal keeps its
     * anniversary instead of stacking up overdue terms.
     *
     * @return array{name:string, old:string, new:string}
     */
    public function markRenewed(string $type, int $id): array
    {
        [$table, $nameCol, $select] = match ($type) {
            'domain'         => ['domains', 'domain', "renewal_date, COALESCE(NULLIF(renewal_years, 0), 1) || ' years' AS step"],
            'recurring_cost' => ['recurring_costs', 'name', "renewal_date, CASE billing_cycle WHEN 'monthly' THEN '1 months' ELSE '1 years' END AS step"],
            'agreement'      => ['agreements', 'title', "renewal_date, '1 years' AS step"],
            default          => throw new \InvalidArgumentException("Cannot mark {$type} as renewed"),
        };

        $stmt = $this->db->prepare("SELECT {$nameCol} AS name, {$select} FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['renewal_date'])) {
            throw new \RuntimeException('No renewal date to roll forward');
        }

        $today = new \DateTimeImmutable('today');
        $date  = new \DateTimeImmutable($row['renewal_date']);
        $step  = '+' . $row['step'];
        do {
            $date = $date->modify($step);
        } while ($date < $today);

        $new = $date->format('Y-m-d');
        $this->db->prepare("UPDATE {$table} SET renewal_date = ? WHERE id = ?")->execute([$new, $id]);

        return ['name' => (string)$row['name'], 'old' => (string)$row['renewal_date'], 'new' => $new];
    }
}
