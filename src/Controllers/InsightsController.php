<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use PDO;

class InsightsController
{
    public function __construct(private PDO $db) {}

    public function index(): void
    {
        $fy     = $_GET['fy'] ?? 'tax';
        $fy     = in_array($fy, ['tax', 'calendar']) ? $fy : 'tax';
        $today  = new \DateTime('today');

        // ── Financial year boundaries ──────────────────────────────────────

        if ($fy === 'tax') {
            $year = (int)$today->format('Y');
            $month = (int)$today->format('n');
            if ($month < 4) {
                $fyStartYear = $year - 1;
            } else {
                $fyStartYear = $year;
            }
            $startThis = new \DateTime("{$fyStartYear}-04-01");
            $endThis   = clone $today;
            // Same elapsed time last year
            $startLast = (clone $startThis)->modify('-1 year');
            $endLast   = (clone $endThis)->modify('-1 year');
        } else {
            $year      = (int)$today->format('Y');
            $startThis = new \DateTime("{$year}-01-01");
            $endThis   = clone $today;
            $startLast = new \DateTime(($year - 1) . '-01-01');
            $endLast   = (clone $endThis)->modify('-1 year');
        }

        $startThisStr = $startThis->format('Y-m-d');
        $endThisStr   = $endThis->format('Y-m-d');
        $startLastStr = $startLast->format('Y-m-d');
        $endLastStr   = $endLast->format('Y-m-d');

        $daysThis = $startThis->diff($endThis)->days + 1;
        $daysLast = $startLast->diff($endLast)->days + 1;

        // Months in period (approximate, for monthly expenses)
        $monthsThis = $daysThis / 30.44;
        $monthsLast = $daysLast / 30.44;

        // ── Revenue (from freeagent_invoices) ─────────────────────────────

        $revenueThis = $this->sumInvoices($startThisStr, $endThisStr);
        $revenueLast = $this->sumInvoices($startLastStr, $endLastStr);

        // ── Costs ─────────────────────────────────────────────────────────

        $costsThis = $this->sumCosts($startThisStr, $endThisStr, $monthsThis, $daysThis);
        $costsLast = $this->sumCosts($startLastStr, $endLastStr, $monthsLast, $daysLast);

        // ── Profit ────────────────────────────────────────────────────────

        $profitThis = $revenueThis - $costsThis;
        $profitLast = $revenueLast - $costsLast;

        // ── Active clients ────────────────────────────────────────────────

        $clientsThis = $this->activeClientCount($startThisStr, $endThisStr);
        $clientsLast = $this->activeClientCount($startLastStr, $endLastStr);

        // ── Labels ────────────────────────────────────────────────────────

        $labelThis = $startThis->format("M 'y") . ' – now';
        $labelLast = $startLast->format("M 'y") . ' – ' . $endLast->format("M 'y");

        // ── Monthly bar chart data ────────────────────────────────────────

        $months = $this->getMonthlyRevenue($startThis, $endThis, $startLast, $endLast);

        // ── Per-client revenue comparison ────────────────────────────────

        $perClient = $this->perClientRevenue($startThisStr, $endThisStr, $startLastStr, $endLastStr);

        // ── Upcoming renewals (full list) ─────────────────────────────────

        $timeframe    = (int)($_GET['timeframe'] ?? 90);
        $timeframe    = in_array($timeframe, [30, 60, 90, 180, 365]) ? $timeframe : 90;
        $typeFilter   = $_GET['type'] ?? 'all';
        $typeFilter   = in_array($typeFilter, array_merge(['all'], \CoyshCRM\Services\Renewals::TYPES)) ? $typeFilter : 'all';

        $renewals = (new \CoyshCRM\Services\Renewals($this->db))->fetch($timeframe, $typeFilter);

        // ── Income category breakdown ─────────────────────────────────────

        $categoryBreakdown = $this->categoryBreakdown($startThisStr, $endThisStr, $startLastStr, $endLastStr);

        // ── Client health ─────────────────────────────────────────────────

        $clientModel  = new Client($this->db);
        $healthAll    = $clientModel->getHealthAll($clientModel->getPLAll());

        $allClients = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();

        $healthRows = [];
        foreach ($allClients as $c) {
            $cid = (int)$c['id'];
            $healthRows[] = array_merge($c, $healthAll[$cid] ?? ['status' => 'healthy', 'flags' => []]);
        }

        $healthStatusFilter = $_GET['health'] ?? 'all';

        // ── Yearly P&L ────────────────────────────────────────────────────
        $yearlyPL = $this->getYearlyPL($fy);

        $currentFYStart = $startThis->format('Y-m-d');

        // Whether "prev year" navigation is possible — check if data exists before this FY
        $chartHasPrev = false;
        try {
            $minInvoice = $this->db->query("SELECT MIN(dated_on) FROM freeagent_invoices WHERE dated_on IS NOT NULL AND COALESCE(status_override, status) IN ('paid','sent','overdue')")->fetchColumn();
            if ($minInvoice) {
                $prevFYStart = (clone $startThis)->modify('-1 year')->format('Y-m-d');
                $chartHasPrev = $prevFYStart >= substr($minInvoice, 0, 7) . '-01';
            }
        } catch (\Throwable) {}

        $includeCharts = true;

        render('insights.index', compact(
            'includeCharts',
            'fy', 'labelThis', 'labelLast',
            'revenueThis', 'revenueLast',
            'costsThis', 'costsLast',
            'profitThis', 'profitLast',
            'clientsThis', 'clientsLast',
            'months',
            'currentFYStart',
            'chartHasPrev',
            'perClient',
            'categoryBreakdown',
            'renewals', 'timeframe', 'typeFilter',
            'healthRows', 'healthStatusFilter',
            'yearlyPL'
        ), 'Insights');
    }

    /**
     * JSON endpoint: GET /insights/month-detail?month=2026-01
     * Returns invoices, one-off expenses, recurring costs, and domain costs for that calendar month.
     */
    public function monthDetail(): void
    {
        header('Content-Type: application/json');

        $monthParam = $_GET['month'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
            echo json_encode(['error' => 'Invalid month']);
            exit;
        }

        $mStart = $monthParam . '-01';
        try {
            $dt   = new \DateTime($mStart);
            $mEnd = $dt->format('Y-m-t');
        } catch (\Throwable) {
            echo json_encode(['error' => 'Invalid month']);
            exit;
        }

        $label = $dt->format('F Y');

        // ── Invoices ────────────────────────────────────────────────────────
        $invoices = [];
        try {
            $stmt = $this->db->prepare("
                SELECT fi.id, fi.reference, fi.total_value, fi.status, fi.dated_on,
                       fi.source, fi.freeagent_url,
                       c.name AS client_name, c.id AS client_id
                FROM freeagent_invoices fi
                LEFT JOIN clients c ON c.id = fi.client_id
                WHERE fi.dated_on BETWEEN ? AND ?
                  AND fi.status IN ('paid','sent','overdue')
                ORDER BY fi.dated_on DESC, fi.total_value DESC
            ");
            $stmt->execute([$mStart, $mEnd]);
            $invoices = $stmt->fetchAll();
        } catch (\Throwable) {}

        // ── One-off / direct expenses ────────────────────────────────────────
        $expenses = [];
        try {
            $stmt = $this->db->prepare("
                SELECT e.id, e.name AS description, e.amount, e.billing_cycle, e.date,
                       ec.name AS category_name,
                       c.name AS client_name, c.id AS client_id
                FROM expenses e
                LEFT JOIN expense_categories ec ON ec.id = e.category_id
                LEFT JOIN clients c ON c.id = e.client_id
                WHERE e.date BETWEEN ? AND ? AND e.ignore_from_stats = 0
                ORDER BY e.date DESC, e.amount DESC
            ");
            $stmt->execute([$mStart, $mEnd]);
            $expenses = $stmt->fetchAll();
        } catch (\Throwable) {}

        // ── Recurring costs (all active; show monthly equivalent) ────────────
        $recurring = [];
        try {
            $stmt = $this->db->prepare("
                SELECT rc.id, rc.name, rc.amount, rc.billing_cycle,
                       CASE billing_cycle WHEN 'annual' THEN rc.amount / 12.0 ELSE rc.amount END AS monthly_equivalent,
                       ec.name AS category_name
                FROM recurring_costs rc
                LEFT JOIN expense_categories ec ON ec.id = rc.category_id
                WHERE rc.is_active = 1
                ORDER BY monthly_equivalent DESC
            ");
            $stmt->execute();
            $recurring = $stmt->fetchAll();
        } catch (\Throwable) {}

        // ── Domain costs ──────────────────────────────────────────────────────
        $domains = [];
        try {
            $stmt = $this->db->prepare("
                SELECT d.id, d.domain, d.annual_cost,
                       COALESCE(d.currency, 'GBP') AS currency,
                       d.annual_cost / 12.0 AS monthly_equivalent,
                       c.name AS client_name, c.id AS client_id
                FROM domains d
                LEFT JOIN clients c ON c.id = d.client_id
                WHERE d.annual_cost IS NOT NULL AND d.annual_cost > 0
                ORDER BY d.annual_cost DESC
            ");
            $stmt->execute();
            $domains = $stmt->fetchAll();
        } catch (\Throwable) {}

        echo json_encode(compact('label', 'invoices', 'expenses', 'recurring', 'domains'));
        exit;
    }

    /**
     * JSON endpoint: GET /insights/monthly-chart?fy=tax&year_start=2024-04-01
     * Returns monthly revenue + costs bars for the requested year and the previous year for comparison.
     */
    public function monthlyChart(): void
    {
        header('Content-Type: application/json');

        $fy        = in_array($_GET['fy'] ?? '', ['tax','calendar']) ? $_GET['fy'] : 'tax';
        $yearStart = $_GET['year_start'] ?? null;

        // Parse requested year start, fall back to current FY
        try {
            $startThis = $yearStart ? new \DateTime($yearStart) : null;
        } catch (\Throwable) {
            $startThis = null;
        }

        $today = new \DateTime('today');
        if ($startThis === null) {
            $year  = (int)$today->format('Y');
            $month = (int)$today->format('n');
            if ($fy === 'tax') {
                $fyStart = $month < 4 ? $year - 1 : $year;
                $startThis = new \DateTime("{$fyStart}-04-01");
            } else {
                $startThis = new \DateTime("{$year}-01-01");
            }
        }

        // Determine end of this period
        $endThis   = clone $startThis;
        $isCurrent = false;
        if ($fy === 'tax') {
            $endFull = (clone $startThis)->modify('+1 year')->modify('-1 day');
            if ($endFull >= $today) { $endThis = clone $today; $isCurrent = true; }
            else                    { $endThis = $endFull; }
            $yearLabel = $startThis->format('Y') . '/' . substr((string)((int)$startThis->format('Y') + 1), -2);
        } else {
            $endFull = new \DateTime($startThis->format('Y') . '-12-31');
            if ($endFull >= $today) { $endThis = clone $today; $isCurrent = true; }
            else                    { $endThis = $endFull; }
            $yearLabel = $startThis->format('Y');
        }

        // Previous year boundaries
        $startPrev = (clone $startThis)->modify('-1 year');
        $endPrev   = (clone $endThis)->modify('-1 year');

        // Next year start (for navigation)
        $startNext = (clone $startThis)->modify('+1 year');

        // Find earliest data year for "has previous" check
        $earliestDate = null;
        try {
            $earliestDate = $this->db->query("SELECT MIN(dated_on) FROM freeagent_invoices WHERE dated_on IS NOT NULL AND COALESCE(status_override, status) IN ('paid','sent','overdue')")->fetchColumn();
        } catch (\Throwable) {}

        $hasPrev = $earliestDate && $startPrev->format('Y-m-d') >= substr($earliestDate, 0, 7) . '-01';
        $hasNext = !$isCurrent;

        // Build monthly data
        $months = [];
        $cursor = clone $startThis;
        while ($cursor <= $endThis) {
            $mStart = $cursor->format('Y-m-01');
            $mEnd   = min($cursor->format('Y-m-t'), $endThis->format('Y-m-d'));

            try {
                $stmt = $this->db->prepare("SELECT COALESCE(SUM(COALESCE(net_value, total_value)), 0) FROM freeagent_invoices WHERE dated_on BETWEEN ? AND ? AND COALESCE(status_override, status) IN ('paid','sent','overdue')");
                $stmt->execute([$mStart, $mEnd]);
                $thisRev = (float)$stmt->fetchColumn();
            } catch (\Throwable) { $thisRev = 0.0; }

            $mDays    = (new \DateTime($mStart))->diff(new \DateTime($mEnd))->days + 1;
            $mMonths  = $mDays / 30.44;
            $thisCosts = $this->sumCosts($mStart, $mEnd, $mMonths, $mDays);

            $pStart = (clone $cursor)->modify('-1 year')->format('Y-m-01');
            $pEnd   = (clone $cursor)->modify('-1 year')->format('Y-m-t');
            try {
                $stmt = $this->db->prepare("SELECT COALESCE(SUM(COALESCE(net_value, total_value)), 0) FROM freeagent_invoices WHERE dated_on BETWEEN ? AND ? AND COALESCE(status_override, status) IN ('paid','sent','overdue')");
                $stmt->execute([$pStart, $pEnd]);
                $prevRev = (float)$stmt->fetchColumn();
            } catch (\Throwable) { $prevRev = 0.0; }

            $months[] = [
                'label'      => $cursor->format("M 'y"),
                'this_year'  => $thisRev,
                'last_year'  => $prevRev,
                'this_costs' => $thisCosts,
            ];
            $cursor->modify('+1 month');
        }

        $maxVal = 1;
        foreach ($months as $m) {
            $maxVal = max($maxVal, $m['this_year'], $m['last_year']);
        }

        echo json_encode([
            'year_label'  => $yearLabel,
            'is_current'  => $isCurrent,
            'year_start'  => $startThis->format('Y-m-d'),
            'prev_start'  => $startPrev->format('Y-m-d'),
            'next_start'  => $startNext->format('Y-m-d'),
            'has_prev'    => $hasPrev,
            'has_next'    => $hasNext,
            'months'      => $months,
            'max_val'     => $maxVal,
        ]);
        exit;
    }

    private function sumInvoices(string $start, string $end): float
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(SUM(COALESCE(net_value, total_value)), 0) FROM freeagent_invoices
                WHERE dated_on BETWEEN ? AND ?
                  AND COALESCE(status_override, status) IN ('paid', 'sent', 'overdue')
            ");
            $stmt->execute([$start, $end]);
            return (float)$stmt->fetchColumn();
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Revenue by FreeAgent income category for the current and comparison
     * periods, with YoY delta. Sparse categories show as Uncategorised until a
     * full FreeAgent resync backfills them.
     */
    private function categoryBreakdown(string $startThis, string $endThis, string $startLast, string $endLast): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COALESCE(NULLIF(TRIM(category), ''), 'Uncategorised') AS category,
                       COALESCE(SUM(CASE WHEN dated_on BETWEEN :sT AND :eT THEN COALESCE(net_value, total_value) ELSE 0 END), 0) AS this_period,
                       COALESCE(SUM(CASE WHEN dated_on BETWEEN :sL AND :eL THEN COALESCE(net_value, total_value) ELSE 0 END), 0) AS last_period,
                       SUM(CASE WHEN dated_on BETWEEN :sT AND :eT THEN 1 ELSE 0 END) AS invoice_count
                FROM freeagent_invoices
                WHERE COALESCE(status_override, status) IN ('paid','sent','overdue')
                  AND (dated_on BETWEEN :sT AND :eT OR dated_on BETWEEN :sL AND :eL)
                GROUP BY 1
                HAVING this_period > 0 OR last_period > 0
                ORDER BY this_period DESC
            ");
            $stmt->execute([':sT' => $startThis, ':eT' => $endThis, ':sL' => $startLast, ':eL' => $endLast]);
            return $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
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

    private function activeClientCount(string $start, string $end): int
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT client_id) FROM freeagent_invoices
                WHERE dated_on BETWEEN ? AND ?
                  AND COALESCE(status_override, status) IN ('paid','sent','overdue')
                  AND client_id IS NOT NULL
            ");
            $stmt->execute([$start, $end]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function getMonthlyRevenue(\DateTime $startThis, \DateTime $endThis, \DateTime $startLast, \DateTime $endLast): array
    {
        $months = [];
        $cursor = clone $startThis;
        while ($cursor <= $endThis) {
            $monthStart = $cursor->format('Y-m-01');
            $monthEnd   = $cursor->format('Y-m-t');

            $stmtThis = $this->db->prepare("
                SELECT COALESCE(SUM(COALESCE(net_value, total_value)), 0) FROM freeagent_invoices
                WHERE dated_on BETWEEN ? AND ? AND COALESCE(status_override, status) IN ('paid','sent','overdue')
            ");
            $stmtThis->execute([$monthStart, min($monthEnd, $endThis->format('Y-m-d'))]);
            $thisVal = (float)$stmtThis->fetchColumn();

            // Corresponding month last year
            $lastMonthStart = (clone $cursor)->modify('-1 year')->format('Y-m-01');
            $lastMonthEnd   = (clone $cursor)->modify('-1 year')->format('Y-m-t');
            $stmtLast = $this->db->prepare("
                SELECT COALESCE(SUM(COALESCE(net_value, total_value)), 0) FROM freeagent_invoices
                WHERE dated_on BETWEEN ? AND ? AND COALESCE(status_override, status) IN ('paid','sent','overdue')
            ");
            $stmtLast->execute([$lastMonthStart, min($lastMonthEnd, $endLast->format('Y-m-d'))]);
            $lastVal = (float)$stmtLast->fetchColumn();

            // Costs for the same window, so the chart's cost line is populated
            // on first paint too (not only after an AJAX year navigation).
            $cEnd   = min($monthEnd, $endThis->format('Y-m-d'));
            $cDays  = (new \DateTime($monthStart))->diff(new \DateTime($cEnd))->days + 1;
            $thisCosts = $this->sumCosts($monthStart, $cEnd, $cDays / 30.44, $cDays);

            $months[] = [
                'label'      => $cursor->format("M 'y"),
                'this_year'  => $thisVal,
                'last_year'  => $lastVal,
                'this_costs' => $thisCosts,
            ];

            $cursor->modify('+1 month');
        }
        return $months;
    }

    private function perClientRevenue(string $startThis, string $endThis, string $startLast, string $endLast): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT
                    c.id, c.name,
                    COALESCE(SUM(CASE WHEN fi.dated_on BETWEEN :sT AND :eT THEN COALESCE(fi.net_value, fi.total_value) ELSE 0 END), 0) AS this_year,
                    COALESCE(SUM(CASE WHEN fi.dated_on BETWEEN :sL AND :eL THEN COALESCE(fi.net_value, fi.total_value) ELSE 0 END), 0) AS last_year
                FROM clients c
                LEFT JOIN freeagent_invoices fi ON fi.client_id = c.id AND COALESCE(fi.status_override, fi.status) IN ('paid','sent','overdue')
                WHERE c.status = 'active'
                GROUP BY c.id, c.name
                ORDER BY this_year DESC
            ");
            $stmt->execute([':sT' => $startThis, ':eT' => $endThis, ':sL' => $startLast, ':eL' => $endLast]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $t = (float)$row['this_year'];
            $l = (float)$row['last_year'];
            if ($t > 0 && $l === 0.0)    $row['trend'] = 'new';
            elseif ($t === 0.0 && $l > 0) $row['trend'] = 'lost';
            elseif ($l > 0 && ($t - $l) / $l > 0.10) $row['trend'] = 'grew';
            elseif ($l > 0 && ($t - $l) / $l < -0.10) $row['trend'] = 'declined';
            else                           $row['trend'] = 'stable';
        }
        unset($row);

        return $rows;
    }

    private function getYearlyPL(string $fy): array
    {
        $today = new \DateTime('today');
        $todayStr = $today->format('Y-m-d');

        // Find earliest invoice date to know how far back to go
        $minDate = null;
        try {
            $minDate = $this->db->query("SELECT MIN(dated_on) FROM freeagent_invoices WHERE dated_on IS NOT NULL AND COALESCE(status_override, status) IN ('paid','sent','overdue')")->fetchColumn();
        } catch (\Throwable) {}

        if (!$minDate) {
            return [];
        }

        // Find start year of data
        $minDateTime = new \DateTime($minDate);
        $minYear = (int)$minDateTime->format('Y');
        $minMonth = (int)$minDateTime->format('n');

        // Current year boundaries
        $currentYear = (int)$today->format('Y');
        $currentMonth = (int)$today->format('n');

        // Build list of financial years from data start to now
        $years = [];
        if ($fy === 'tax') {
            // Financial year: Apr to Mar
            // Min FY start
            $startFY = ($minMonth < 4) ? $minYear - 1 : $minYear;
            // Current FY start
            $currentFYStart = ($currentMonth < 4) ? $currentYear - 1 : $currentYear;

            for ($y = $startFY; $y <= $currentFYStart; $y++) {
                $isCurrent = ($y === $currentFYStart);
                $start = "{$y}-04-01";
                $end   = $isCurrent ? $todayStr : (($y + 1) . '-03-31');
                $label = $y . '/' . substr((string)($y + 1), -2);
                $years[] = compact('label', 'isCurrent', 'start', 'end');
            }
        } else {
            // Calendar year
            for ($y = $minYear; $y <= $currentYear; $y++) {
                $isCurrent = ($y === $currentYear);
                $start = "{$y}-01-01";
                $end   = $isCurrent ? $todayStr : "{$y}-12-31";
                $label = (string)$y;
                $years[] = compact('label', 'isCurrent', 'start', 'end');
            }
        }

        $result = [];
        foreach (array_reverse($years) as $yr) {
            $startDt = new \DateTime($yr['start']);
            $endDt   = new \DateTime($yr['end']);
            $days    = $startDt->diff($endDt)->days + 1;
            $months  = $days / 30.44;

            $revenue = $this->sumInvoices($yr['start'], $yr['end']);
            $costs   = $this->sumCosts($yr['start'], $yr['end'], $months, $days);
            $profit  = $revenue - $costs;
            $margin  = $revenue > 0 ? ($profit / $revenue) * 100 : 0;

            try {
                $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM freeagent_invoices WHERE dated_on BETWEEN ? AND ? AND COALESCE(status_override, status) IN ('paid','sent','overdue')");
                $stmtCount->execute([$yr['start'], $yr['end']]);
                $invoiceCount = (int)$stmtCount->fetchColumn();
            } catch (\Throwable) {
                $invoiceCount = 0;
            }
            $clientCount = $this->activeClientCount($yr['start'], $yr['end']);

            // Monthly breakdown
            $monthRows = [];
            $cursor = clone $startDt;
            while ($cursor <= $endDt) {
                $mStart = $cursor->format('Y-m-01');
                $mEnd   = min($cursor->format('Y-m-t'), $yr['end']);
                $mDays  = (new \DateTime($mStart))->diff(new \DateTime($mEnd))->days + 1;
                $mMonths = $mDays / 30.44;

                $mRevenue = $this->sumInvoices($mStart, $mEnd);
                $mCosts   = $this->sumCosts($mStart, $mEnd, $mMonths, $mDays);
                $monthRows[] = [
                    'label'      => $cursor->format('M Y'),
                    'year_month' => $cursor->format('Y-m'),
                    'revenue'    => $mRevenue,
                    'costs'      => $mCosts,
                    'profit'     => $mRevenue - $mCosts,
                ];
                $cursor->modify('+1 month');
            }

            $result[] = [
                'label'         => $yr['label'],
                'is_current'    => $yr['isCurrent'],
                'start'         => $yr['start'],
                'end'           => $yr['end'],
                'revenue'       => $revenue,
                'costs'         => $costs,
                'profit'        => $profit,
                'margin'        => $margin,
                'invoice_count' => $invoiceCount,
                'client_count'  => $clientCount,
                'months'        => $monthRows,
            ];
        }

        return $result;
    }
}
