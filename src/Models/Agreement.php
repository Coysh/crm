<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Agreement extends Model
{
    protected string $table = 'agreements';

    public const TYPES = [
        'support'       => 'Support / SLA',
        'build_bundled' => 'Build agreement (bundled cover)',
        'consultancy'   => 'Consultancy',
        'other'         => 'Other',
    ];

    public const STATUSES = ['active', 'expired', 'cancelled'];
    public const HOURS_PERIODS = ['monthly', 'quarterly', 'annually'];
    public const BILLING_CYCLES = ['monthly', 'quarterly', 'annually', 'one_off'];

    /**
     * SQL normalising an agreement's fee to a monthly equivalent, mirroring
     * FreeAgentRecurringInvoice::monthlySql(). `one_off` fees are not recurring
     * revenue and normalise to 0.
     */
    public static function monthlySql(string $alias = ''): string
    {
        $p = $alias ? "{$alias}." : '';
        // Float divisors — fee_amount has integer affinity in SQLite, so `/ 12`
        // would truncate a £400 annual fee to £33 instead of £33.33.
        return "CASE {$p}fee_billing_cycle
            WHEN 'monthly'   THEN COALESCE({$p}fee_amount, 0)
            WHEN 'quarterly' THEN COALESCE({$p}fee_amount, 0) / 3.0
            WHEN 'annually'  THEN COALESCE({$p}fee_amount, 0) / 12.0
            ELSE 0
        END";
    }

    /**
     * Correlated subquery summing the monthly-equivalent fees of a client's
     * active agreements that are NOT linked to a FreeAgent recurring invoice.
     *
     * Linked agreements are excluded because the recurring invoice they point at
     * is already counted as revenue — counting both would double the client's MRR.
     *
     * @param string $clientIdExpr SQL expression for the client id (e.g. 'c.id')
     */
    public static function unlinkedMrrSql(string $clientIdExpr): string
    {
        $fee = self::monthlySql('ag');
        return "(SELECT COALESCE(SUM($fee), 0)
                 FROM agreements ag
                 WHERE ag.client_id = $clientIdExpr
                   AND ag.status = 'active'
                   AND ag.freeagent_recurring_invoice_id IS NULL)";
    }

    /** All agreements for one client, with usage and attachment counts attached. */
    public function findByClient(int $clientId): array
    {
        $rows = $this->query(
            "SELECT a.*, fri.reference AS recurring_reference, fri.recurring_status AS recurring_status
             FROM agreements a
             LEFT JOIN freeagent_recurring_invoices fri ON fri.id = a.freeagent_recurring_invoice_id
             WHERE a.client_id = ?
             ORDER BY CASE a.status WHEN 'active' THEN 0 ELSE 1 END, a.renewal_date IS NULL, a.renewal_date",
            [$clientId]
        )->fetchAll();
        return array_map(fn($r) => $this->withUsage($r), $rows);
    }

    /** All agreements joined with client names (list page, renewals, MCP). */
    public function findAllWithClient(?string $status = null): array
    {
        $sql = "SELECT a.*, c.name AS client_name,
                       fri.reference AS recurring_reference, fri.recurring_status AS recurring_status
                FROM agreements a
                JOIN clients c ON c.id = a.client_id
                LEFT JOIN freeagent_recurring_invoices fri ON fri.id = a.freeagent_recurring_invoice_id";
        $params = [];
        if ($status !== null) {
            $sql .= " WHERE a.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY c.name, CASE a.status WHEN 'active' THEN 0 ELSE 1 END, a.title";
        $rows = $this->query($sql, $params)->fetchAll();
        return array_map(fn($r) => $this->withUsage($r), $rows);
    }

    /**
     * Start of the current allowance period, anchored to start_date.
     * Annual: most recent anniversary of start_date; quarterly/monthly likewise.
     * Falls back to period-so-far when no start date is set.
     */
    public function currentPeriodStart(array $agreement): ?string
    {
        if (empty($agreement['included_hours'])) return null;

        $period = $agreement['hours_period'] ?: 'annually';
        $start  = $agreement['start_date'] ?: null;

        if (!$start) {
            // No anchor — use the natural calendar period.
            return match ($period) {
                'monthly'   => date('Y-m-01'),
                'quarterly' => date('Y-m-01', mktime(0, 0, 0, (int)(floor((date('n') - 1) / 3) * 3 + 1), 1)),
                default     => date('Y-01-01'),
            };
        }

        $stepMonths = match ($period) { 'monthly' => 1, 'quarterly' => 3, default => 12 };
        $anchor = new \DateTimeImmutable($start);
        $today  = new \DateTimeImmutable('today');
        if ($anchor > $today) return $anchor->format('Y-m-d');

        // Walk forward from the anchor in whole periods to the latest one <= today.
        $diffMonths = ($today->format('Y') - $anchor->format('Y')) * 12
            + ($today->format('n') - $anchor->format('n'));
        $steps = intdiv(max(0, $diffMonths), $stepMonths);
        $candidate = $anchor->modify("+" . ($steps * $stepMonths) . " months");
        if ($candidate > $today) {
            $candidate = $anchor->modify("+" . (($steps - 1) * $stepMonths) . " months");
        }
        return $candidate->format('Y-m-d');
    }

    public function hoursUsed(int $agreementId, string $fromDate): float
    {
        return (float)$this->query(
            "SELECT COALESCE(SUM(hours), 0) FROM agreement_work_log WHERE agreement_id = ? AND work_date >= ?",
            [$agreementId, $fromDate]
        )->fetchColumn();
    }

    /** Attach period_start / hours_used / hours_remaining (NULL-safe for non-hours agreements). */
    public function withUsage(array $agreement): array
    {
        $agreement['period_start']    = null;
        $agreement['hours_used']      = null;
        $agreement['hours_remaining'] = null;

        if (!empty($agreement['included_hours'])) {
            $periodStart = $this->currentPeriodStart($agreement);
            $used = $this->hoursUsed((int)$agreement['id'], $periodStart ?? '1970-01-01');
            $agreement['period_start']    = $periodStart;
            $agreement['hours_used']      = $used;
            $agreement['hours_remaining'] = max(0.0, (float)$agreement['included_hours'] - $used);
        }
        return $agreement;
    }

    public function workLog(int $agreementId, int $limit = 50): array
    {
        return $this->query(
            "SELECT * FROM agreement_work_log WHERE agreement_id = ? ORDER BY work_date DESC, id DESC LIMIT $limit",
            [$agreementId]
        )->fetchAll();
    }

    public function addWork(int $agreementId, string $workDate, float $hours, string $description): int
    {
        $this->query(
            "INSERT INTO agreement_work_log (agreement_id, work_date, hours, description) VALUES (?, ?, ?, ?)",
            [$agreementId, $workDate, $hours, $description]
        );
        return (int)$this->db->lastInsertId();
    }

    public function deleteWork(int $agreementId, int $logId): void
    {
        $this->query("DELETE FROM agreement_work_log WHERE id = ? AND agreement_id = ?", [$logId, $agreementId]);
    }

    /** Attachments linked to a specific agreement. */
    public function attachments(int $agreementId): array
    {
        try {
            return $this->query(
                "SELECT * FROM client_attachments WHERE agreement_id = ? ORDER BY uploaded_at DESC",
                [$agreementId]
            )->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }
}
