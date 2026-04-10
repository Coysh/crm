<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Client extends Model
{
    protected string $table = 'clients';

    public function findAllWithStats(?string $status = null): array
    {
        $mrrSql = FreeAgentRecurringInvoice::monthlySql();
        $sql = "SELECT c.*,
            (SELECT COUNT(*) FROM client_sites cs WHERE cs.client_id = c.id) AS site_count,
            (SELECT COALESCE(SUM($mrrSql), 0)
             FROM freeagent_recurring_invoices fri
             WHERE fri.client_id = c.id AND fri.recurring_status = 'Active') AS mrr
        FROM clients c";
        $params = [];
        if ($status) { $sql .= ' WHERE c.status = ?'; $params[] = $status; }
        $sql .= ' ORDER BY c.name';
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Fetch clients with filtering, searching, and sorting.
     *
     * @param string|null $status  'active'|'archived'|null (all)
     * @param array       $filters {search, has_recurring, has_sites, mrr_range, cloudflare, sort, dir}
     */
    public function findAllWithFilters(?string $status, array $filters = []): array
    {
        $mrrSql = FreeAgentRecurringInvoice::monthlySql();

        // Cloudflare subquery — only if table exists
        $cfHasCol = $this->hasCfZonesTable();
        $cfSelect = $cfHasCol
            ? ", (CASE WHEN EXISTS(SELECT 1 FROM cloudflare_zones cz JOIN domains d ON d.id = cz.domain_id WHERE d.client_id = c.id) THEN 1 ELSE 0 END) AS has_cloudflare"
            : ", 0 AS has_cloudflare";

        $sql = "SELECT c.*,
            (SELECT COUNT(*) FROM client_sites cs WHERE cs.client_id = c.id) AS site_count,
            (SELECT COALESCE(SUM($mrrSql), 0)
             FROM freeagent_recurring_invoices fri
             WHERE fri.client_id = c.id AND fri.recurring_status = 'Active') AS mrr,
            (CASE WHEN EXISTS(SELECT 1 FROM freeagent_recurring_invoices fri
                WHERE fri.client_id = c.id AND fri.recurring_status = 'Active') THEN 1 ELSE 0 END) AS has_recurring,
            (SELECT COALESCE(SUM(fi.total_value), 0)
             FROM freeagent_invoices fi WHERE fi.client_id = c.id
               AND COALESCE(fi.status_override, fi.status) IN ('paid','sent','overdue')) AS total_invoiced,
            (SELECT COALESCE(SUM(fi.total_value), 0)
             FROM freeagent_invoices fi WHERE fi.client_id = c.id
               AND COALESCE(fi.status_override, fi.status) IN ('sent','overdue')) AS outstanding
            {$cfSelect}
        FROM clients c WHERE 1=1";

        $params = [];

        if ($status) {
            $sql .= ' AND c.status = ?';
            $params[] = $status;
        }

        if (!empty($filters['search'])) {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']) . '%';
            $sql .= ' AND (LOWER(c.name) LIKE LOWER(?) OR LOWER(COALESCE(c.contact_name,\'\')) LIKE LOWER(?) OR LOWER(COALESCE(c.contact_email,\'\')) LIKE LOWER(?))';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        if (($filters['has_recurring'] ?? 'all') === 'yes') {
            $sql .= " AND EXISTS(SELECT 1 FROM freeagent_recurring_invoices fri WHERE fri.client_id = c.id AND fri.recurring_status = 'Active')";
        } elseif (($filters['has_recurring'] ?? 'all') === 'no') {
            $sql .= " AND NOT EXISTS(SELECT 1 FROM freeagent_recurring_invoices fri WHERE fri.client_id = c.id AND fri.recurring_status = 'Active')";
        }

        if ($cfHasCol) {
            if (($filters['cloudflare'] ?? 'all') === 'yes') {
                $sql .= ' AND EXISTS(SELECT 1 FROM cloudflare_zones cz JOIN domains d ON d.id = cz.domain_id WHERE d.client_id = c.id)';
            } elseif (($filters['cloudflare'] ?? 'all') === 'no') {
                $sql .= ' AND NOT EXISTS(SELECT 1 FROM cloudflare_zones cz JOIN domains d ON d.id = cz.domain_id WHERE d.client_id = c.id)';
            }
        }

        $typeFilter = $filters['client_type'] ?? 'all';
        if (in_array($typeFilter, ['managed', 'support_only', 'consultancy_only'])) {
            $sql .= ' AND COALESCE(c.client_type, \'managed\') = ?';
            $params[] = $typeFilter;
        }

        $sortMap = [
            'name'            => 'LOWER(c.name)',
            'mrr'             => 'mrr',
            'sites'           => 'site_count',
            'status'          => 'c.status',
            'type'            => 'COALESCE(c.client_type,\'managed\')',
            'total_invoiced'  => 'total_invoiced',
            'outstanding'     => 'outstanding',
        ];
        $sortCol = $sortMap[$filters['sort'] ?? ''] ?? 'LOWER(c.name)';
        $sortDir = ($filters['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $sql .= " ORDER BY {$sortCol} {$sortDir}";

        $rows = $this->query($sql, $params)->fetchAll();

        // PHP-side filters for computed fields
        $mrrRange = $filters['mrr_range'] ?? 'all';
        $hasSites = $filters['has_sites'] ?? 'all';

        if ($mrrRange !== 'all' || $hasSites !== 'all') {
            $rows = array_values(array_filter($rows, function ($c) use ($mrrRange, $hasSites) {
                $mrr   = (float)$c['mrr'];
                $sites = (int)$c['site_count'];
                $mrrOk = match($mrrRange) {
                    'zero'    => $mrr == 0,
                    '1_100'   => $mrr > 0 && $mrr <= 100,
                    '100_500' => $mrr > 100 && $mrr <= 500,
                    '500plus' => $mrr > 500,
                    default   => true,
                };
                $sitesOk = match($hasSites) {
                    'yes' => $sites > 0,
                    'no'  => $sites === 0,
                    default => true,
                };
                return $mrrOk && $sitesOk;
            }));
        }

        return $rows;
    }

    private function hasCfZonesTable(): bool
    {
        static $checked = null;
        if ($checked !== null) return $checked;
        try {
            $this->query("SELECT zone_id FROM cloudflare_zones LIMIT 0");
            $checked = true;
        } catch (\Throwable) {
            $checked = false;
        }
        return $checked;
    }

    public function getMRR(int $id): float
    {
        $mrrSql = FreeAgentRecurringInvoice::monthlySql();
        $row = $this->query(
            "SELECT COALESCE(SUM($mrrSql), 0) AS mrr
             FROM freeagent_recurring_invoices
             WHERE client_id = ? AND recurring_status = 'Active'",
            [$id]
        )->fetch();
        return (float)$row['mrr'];
    }

    public function getMonthlyDomainCost(int $clientId): float
    {
        if ($this->hasCurrencyColumn()) {
            $rows = $this->query(
                "SELECT annual_cost / 12.0 AS monthly, COALESCE(currency, 'GBP') AS currency FROM domains WHERE client_id = ? AND annual_cost IS NOT NULL",
                [$clientId]
            )->fetchAll();
            $fx    = $this->fx();
            $total = 0.0;
            foreach ($rows as $r) {
                $total += $fx->convertToGBP((float)$r['monthly'], $r['currency']);
            }
            return $total;
        }
        $row = $this->query("SELECT COALESCE(SUM(annual_cost / 12), 0) AS c FROM domains WHERE client_id = ?", [$clientId])->fetch();
        return (float)$row['c'];
    }

    public function getMonthlyExpenses(int $clientId): float
    {
        $row = $this->query("
            SELECT COALESCE(SUM(CASE billing_cycle WHEN 'monthly' THEN amount WHEN 'annual' THEN amount/12 WHEN 'one_off' THEN 0 ELSE amount END), 0) AS monthly
            FROM expenses WHERE client_id = ? AND ignore_from_stats = 0
        ", [$clientId])->fetch();
        return (float)$row['monthly'];
    }

    /**
     * Monthly share of all active recurring costs attributed to this client.
     * Covers three assignment types:
     *   1. Server-linked costs — dynamic: monthly_eq / distinct clients on that server
     *   2. Per-client junction rows — monthly_eq / distinct linked clients
     *   3. Per-site junction rows — sum of (monthly_eq / total linked sites) for client's sites
     */
    /** Check once whether recurring_costs.server_id column exists (migration 011). */
    private function hasServerIdColumn(): bool
    {
        static $checked = null;
        if ($checked !== null) return $checked;
        try {
            $this->query("SELECT server_id FROM recurring_costs LIMIT 0");
            $checked = true;
        } catch (\Throwable) {
            $checked = false;
        }
        return $checked;
    }

    /** Check once whether recurring_costs.currency column exists (migration 014). */
    private function hasCurrencyColumn(): bool
    {
        static $checked = null;
        if ($checked !== null) return $checked;
        try {
            $this->query("SELECT currency FROM recurring_costs LIMIT 0");
            $checked = true;
        } catch (\Throwable) {
            $checked = false;
        }
        return $checked;
    }

    /** Lazy ExchangeRateService instance. */
    private function fx(): \CoyshCRM\Services\ExchangeRateService
    {
        static $svc = null;
        if ($svc === null) {
            $svc = new \CoyshCRM\Services\ExchangeRateService($this->db);
        }
        return $svc;
    }

    public function getMonthlyRecurringCosts(int $clientId): float
    {
        $fx           = $this->hasCurrencyColumn() ? $this->fx() : null;
        $serverShare  = 0.0;
        $serverFilter = $this->hasServerIdColumn() ? 'AND rc.server_id IS NULL' : '';
        $currCol      = $this->hasCurrencyColumn() ? ", COALESCE(rc.currency, 'GBP') AS currency" : '';

        // 1. Server-linked (dynamic apportionment) — requires migration 011
        if ($this->hasServerIdColumn()) {
            $rows = $this->query("
                SELECT
                    (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                    / MAX(1, (SELECT COUNT(DISTINCT cs2.client_id) FROM client_sites cs2 WHERE cs2.server_id = rc.server_id)) AS monthly_share
                    {$currCol}
                FROM recurring_costs rc
                WHERE rc.is_active = 1 AND rc.server_id IS NOT NULL
                  AND EXISTS (SELECT 1 FROM client_sites cs WHERE cs.server_id = rc.server_id AND cs.client_id = ?)
                GROUP BY rc.id
            ", [$clientId])->fetchAll();
            foreach ($rows as $r) {
                $share = (float)$r['monthly_share'];
                $serverShare += $fx ? $fx->convertToGBP($share, $r['currency'] ?? 'GBP') : $share;
            }
        }

        // 2. Per-client junction
        $rows = $this->query("
            SELECT
                (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                / MAX(1, (SELECT COUNT(DISTINCT c2.client_id) FROM recurring_cost_clients c2
                          WHERE c2.recurring_cost_id = rc.id AND c2.client_id IS NOT NULL)) AS monthly_share
                {$currCol}
            FROM recurring_costs rc
            JOIN recurring_cost_clients rcc ON rcc.recurring_cost_id = rc.id AND rcc.client_id = ?
            WHERE rc.is_active = 1 $serverFilter
        ", [$clientId])->fetchAll();
        $clientShare = 0.0;
        foreach ($rows as $r) {
            $share = (float)$r['monthly_share'];
            $clientShare += $fx ? $fx->convertToGBP($share, $r['currency'] ?? 'GBP') : $share;
        }

        // 3. Per-site junction
        $rows = $this->query("
            SELECT
                (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                / MAX(1, (SELECT COUNT(*) FROM recurring_cost_clients c2
                          WHERE c2.recurring_cost_id = rc.id AND c2.client_site_id IS NOT NULL))
                * COUNT(*) AS monthly_share
                {$currCol}
            FROM recurring_costs rc
            JOIN recurring_cost_clients rcc ON rcc.recurring_cost_id = rc.id
            JOIN client_sites cs ON cs.id = rcc.client_site_id AND cs.client_id = ?
            WHERE rc.is_active = 1 AND rcc.client_site_id IS NOT NULL $serverFilter
            GROUP BY rc.id
        ", [$clientId])->fetchAll();
        $siteShare = 0.0;
        foreach ($rows as $r) {
            $share = (float)$r['monthly_share'];
            $siteShare += $fx ? $fx->convertToGBP($share, $r['currency'] ?? 'GBP') : $share;
        }

        return $serverShare + $clientShare + $siteShare;
    }

    /**
     * Detailed breakdown of recurring costs per client (for P&L display).
     */
    public function getRecurringCostsBreakdown(int $clientId): array
    {
        $serverRows  = [];
        $serverFilter = $this->hasServerIdColumn() ? 'AND rc.server_id IS NULL' : '';

        $currCol = $this->hasCurrencyColumn() ? ", COALESCE(rc.currency, 'GBP') AS currency" : ", 'GBP' AS currency";
        $fx      = $this->hasCurrencyColumn() ? $this->fx() : null;

        // 1. Server-linked costs — requires migration 011
        if ($this->hasServerIdColumn()) {
            $serverRows = $this->query("
                SELECT rc.id, rc.name, rc.amount, rc.billing_cycle, rc.server_id,
                       (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                       / MAX(1, (SELECT COUNT(DISTINCT cs2.client_id) FROM client_sites cs2 WHERE cs2.server_id = rc.server_id)) AS monthly_share,
                       (SELECT COUNT(DISTINCT cs2.client_id) FROM client_sites cs2 WHERE cs2.server_id = rc.server_id) AS shared_count,
                       'server' AS assignment_type,
                       NULL AS total_sites,
                       NULL AS client_site_count
                       {$currCol}
                FROM recurring_costs rc
                WHERE rc.is_active = 1 AND rc.server_id IS NOT NULL
                  AND EXISTS (SELECT 1 FROM client_sites cs WHERE cs.server_id = rc.server_id AND cs.client_id = ?)
                GROUP BY rc.id
            ", [$clientId])->fetchAll();
            if ($fx) {
                foreach ($serverRows as &$r) {
                    $r['monthly_share_gbp'] = $fx->convertToGBP((float)$r['monthly_share'], $r['currency']);
                }
                unset($r);
            }
        }

        // 2. Per-client junction
        $clientRows = $this->query("
            SELECT rc.id, rc.name, rc.amount, rc.billing_cycle, NULL AS server_id,
                   (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                   / MAX(1, (SELECT COUNT(DISTINCT c2.client_id) FROM recurring_cost_clients c2
                             WHERE c2.recurring_cost_id = rc.id AND c2.client_id IS NOT NULL)) AS monthly_share,
                   (SELECT COUNT(DISTINCT c2.client_id) FROM recurring_cost_clients c2
                    WHERE c2.recurring_cost_id = rc.id AND c2.client_id IS NOT NULL) AS shared_count,
                   'client' AS assignment_type,
                   NULL AS total_sites,
                   NULL AS client_site_count
                   {$currCol}
            FROM recurring_costs rc
            JOIN recurring_cost_clients rcc ON rcc.recurring_cost_id = rc.id AND rcc.client_id = ?
            WHERE rc.is_active = 1 $serverFilter
        ", [$clientId])->fetchAll();
        if ($fx) {
            foreach ($clientRows as &$r) {
                $r['monthly_share_gbp'] = $fx->convertToGBP((float)$r['monthly_share'], $r['currency']);
            }
            unset($r);
        }

        // 3. Per-site junction
        $siteRows = $this->query("
            SELECT rc.id, rc.name, rc.amount, rc.billing_cycle, NULL AS server_id,
                   COUNT(*) AS client_site_count,
                   (SELECT COUNT(*) FROM recurring_cost_clients c2
                    WHERE c2.recurring_cost_id = rc.id AND c2.client_site_id IS NOT NULL) AS total_sites,
                   (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                   / MAX(1, (SELECT COUNT(*) FROM recurring_cost_clients c2
                             WHERE c2.recurring_cost_id = rc.id AND c2.client_site_id IS NOT NULL))
                   * COUNT(*) AS monthly_share,
                   'site' AS assignment_type,
                   NULL AS shared_count
                   {$currCol}
            FROM recurring_costs rc
            JOIN recurring_cost_clients rcc ON rcc.recurring_cost_id = rc.id
            JOIN client_sites cs ON cs.id = rcc.client_site_id AND cs.client_id = ?
            WHERE rc.is_active = 1 AND rcc.client_site_id IS NOT NULL $serverFilter
            GROUP BY rc.id
        ", [$clientId])->fetchAll();
        if ($fx) {
            foreach ($siteRows as &$r) {
                $r['monthly_share_gbp'] = $fx->convertToGBP((float)$r['monthly_share'], $r['currency']);
            }
            unset($r);
        }

        return array_merge($serverRows, $clientRows, $siteRows);
    }

    public function getPL(int $clientId): array
    {
        $mrr            = $this->getMRR($clientId);
        $domainCost     = $this->getMonthlyDomainCost($clientId);
        $directExpenses = $this->getMonthlyExpenses($clientId);
        $recurringCosts = $this->getMonthlyRecurringCosts($clientId);
        $totalCosts     = $domainCost + $directExpenses + $recurringCosts;
        $profit         = $mrr - $totalCosts;
        $margin         = $mrr > 0 ? ($profit / $mrr) * 100 : 0;
        return compact('mrr', 'domainCost', 'directExpenses', 'recurringCosts', 'totalCosts', 'profit', 'margin');
    }

    public function getHealth(int $clientId): array
    {
        $today      = date('Y-m-d');
        $twelveMonAgo = date('Y-m-d', strtotime('-12 months'));
        $flags      = [];

        $client     = $this->findById($clientId);
        $clientType = $client['client_type'] ?? 'managed';

        $pl = $this->getPL($clientId);
        if ($pl['profit'] < 0) $flags[] = 'loss_making';

        // Consultancy-only clients aren't expected to have a retainer
        if ($clientType !== 'consultancy_only') {
            $hasRetainer = (bool)$this->query(
                "SELECT 1 FROM freeagent_recurring_invoices WHERE client_id = ? AND recurring_status = 'Active' LIMIT 1",
                [$clientId]
            )->fetchColumn();
            if (!$hasRetainer) $flags[] = 'no_retainer';
        }

        try {
            $hasRecentInvoice = (bool)$this->query(
                "SELECT 1 FROM freeagent_invoices WHERE client_id = ? AND dated_on >= ? LIMIT 1",
                [$clientId, $twelveMonAgo]
            )->fetchColumn();
        } catch (\Throwable) { $hasRecentInvoice = true; }
        if (!$hasRecentInvoice) $flags[] = 'no_recent_invoice';

        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        try {
            $hasOverdue = (bool)$this->query(
                "SELECT 1 FROM freeagent_invoices WHERE client_id = ?
                 AND (COALESCE(status_override, status) = 'overdue'
                      OR (COALESCE(status_override, status) = 'sent' AND dated_on < ?))
                 LIMIT 1",
                [$clientId, $thirtyDaysAgo]
            )->fetchColumn();
        } catch (\Throwable) { $hasOverdue = false; }
        if ($hasOverdue) $flags[] = 'overdue_invoices';

        // Managed clients must have sites + domains; others only need domains (not necessarily full hosting)
        if ($clientType === 'managed') {
            $hasSites   = (bool)$this->query("SELECT 1 FROM client_sites WHERE client_id = ? LIMIT 1", [$clientId])->fetchColumn();
            $hasDomains = (bool)$this->query("SELECT 1 FROM domains WHERE client_id = ? LIMIT 1", [$clientId])->fetchColumn();
            if (!$hasSites || !$hasDomains) $flags[] = 'incomplete_setup';
        }

        // Support-only and consultancy-only clients should have agreement notes
        if (in_array($clientType, ['support_only', 'consultancy_only'])) {
            if (empty($client['agreement_notes'])) $flags[] = 'no_agreement';
        }

        $count  = count($flags);
        $status = match(true) {
            $count === 0 => 'healthy',
            $count <= 2  => 'attention',
            default      => 'at_risk',
        };

        return ['status' => $status, 'flags' => $flags, 'pl' => $pl];
    }

    public function getHealthAll(): array
    {
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        $twelveMonAgo  = date('Y-m-d', strtotime('-12 months'));

        $activeClients = $this->query("SELECT id, COALESCE(client_type,'managed') AS client_type, COALESCE(agreement_notes,'') AS agreement_notes FROM clients WHERE status = 'active'")->fetchAll();
        if (!$activeClients) return [];

        // Aggregate: has active retainer
        $retainerIds = $this->query(
            "SELECT DISTINCT client_id FROM freeagent_recurring_invoices WHERE recurring_status = 'Active' AND client_id IS NOT NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $retainerSet = array_flip($retainerIds);

        // Aggregate: has recent invoice
        $recentIds = [];
        try {
            $recentIds = $this->query(
                "SELECT DISTINCT client_id FROM freeagent_invoices WHERE dated_on >= ? AND client_id IS NOT NULL",
                [$twelveMonAgo]
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {}
        $recentSet = array_flip($recentIds);

        // Aggregate: has overdue invoices
        $overdueIds = [];
        try {
            $overdueIds = $this->query(
                "SELECT DISTINCT client_id FROM freeagent_invoices
                 WHERE client_id IS NOT NULL
                   AND (COALESCE(status_override, status) = 'overdue'
                        OR (COALESCE(status_override, status) = 'sent' AND dated_on < ?))",
                [$thirtyDaysAgo]
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {}
        $overdueSet = array_flip($overdueIds);

        // Aggregate: has sites
        $siteIds = $this->query(
            "SELECT DISTINCT client_id FROM client_sites WHERE client_id IS NOT NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $siteSet = array_flip($siteIds);

        // Aggregate: has domains
        $domainIds = $this->query(
            "SELECT DISTINCT client_id FROM domains WHERE client_id IS NOT NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $domainSet = array_flip($domainIds);

        $result = [];
        foreach ($activeClients as $row) {
            $cid        = (int)$row['id'];
            $clientType = $row['client_type'] ?? 'managed';
            $flags      = [];

            $pl = $this->getPL($cid);
            if ($pl['profit'] < 0) $flags[] = 'loss_making';

            if ($clientType !== 'consultancy_only' && !isset($retainerSet[$cid])) $flags[] = 'no_retainer';
            if (!isset($recentSet[$cid])) $flags[] = 'no_recent_invoice';
            if (isset($overdueSet[$cid])) $flags[] = 'overdue_invoices';

            if ($clientType === 'managed' && (!isset($siteSet[$cid]) || !isset($domainSet[$cid]))) {
                $flags[] = 'incomplete_setup';
            }

            if (in_array($clientType, ['support_only', 'consultancy_only']) && empty($row['agreement_notes'])) {
                $flags[] = 'no_agreement';
            }

            $count  = count($flags);
            $status = match(true) {
                $count === 0 => 'healthy',
                $count <= 2  => 'attention',
                default      => 'at_risk',
            };
            $result[$cid] = ['status' => $status, 'flags' => $flags];
        }
        return $result;
    }

    public function getAllTimePL(int $clientId): array
    {
        // Total invoiced (all sources, all time) — paid + sent + overdue
        $totalInvoiced = 0.0;
        try {
            $totalInvoiced = (float)$this->query(
                "SELECT COALESCE(SUM(total_value), 0) FROM freeagent_invoices WHERE client_id = ? AND COALESCE(status_override, status) IN ('paid','sent','overdue')",
                [$clientId]
            )->fetchColumn();
        } catch (\Throwable) {}

        // Total expenses (all time) — direct expenses
        $totalExpenses = 0.0;
        try {
            $totalExpenses = (float)$this->query(
                "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE client_id = ? AND COALESCE(ignore_from_pl, 0) = 0",
                [$clientId]
            )->fetchColumn();
        } catch (\Throwable) {}

        $profit = $totalInvoiced - $totalExpenses;
        return compact('totalInvoiced', 'totalExpenses', 'profit');
    }

    public function findWithFullDetails(int $id): ?array
    {
        $client = $this->findById($id); if (!$client) return null;
        $client['domains'] = $this->query("SELECT * FROM domains WHERE client_id = ? ORDER BY domain", [$id])->fetchAll();
        $client['sites'] = $this->query("SELECT cs.*, d.domain AS domain_name, s.name AS server_name, ps.domain AS ploi_domain, ps.project_type AS ploi_project_type, ps.php_version AS ploi_php_version, ps.repository AS ploi_repository, ps.branch AS ploi_branch, ps.has_ssl AS ploi_has_ssl, ps.web_directory AS ploi_web_directory, ps.test_domain AS ploi_test_domain, ps.status AS ploi_status, ps.is_stale AS ploi_is_stale FROM client_sites cs LEFT JOIN domains d ON d.id = cs.domain_id LEFT JOIN servers s ON s.id = cs.server_id LEFT JOIN ploi_sites ps ON ps.client_site_id = cs.id WHERE cs.client_id = ? ORDER BY d.domain", [$id])->fetchAll();
        $client['recurring_invoices'] = $this->query("SELECT * FROM freeagent_recurring_invoices WHERE client_id = ? ORDER BY CASE recurring_status WHEN 'Active' THEN 0 ELSE 1 END, reference", [$id])->fetchAll();
        $client['projects'] = $this->query("SELECT * FROM projects WHERE client_id = ? ORDER BY created_at DESC", [$id])->fetchAll();
        $client['expenses'] = $this->query("SELECT e.*, s.name AS server_name, p.name AS project_name FROM expenses e LEFT JOIN servers s ON s.id = e.server_id LEFT JOIN projects p ON p.id = e.project_id WHERE e.client_id = ? ORDER BY e.date DESC", [$id])->fetchAll();
        $client['attachments'] = $this->query("SELECT * FROM client_attachments WHERE client_id = ? ORDER BY uploaded_at DESC", [$id])->fetchAll();
        $client['pl']          = $this->getPL($id);
        $client['pl_recurring'] = $this->getRecurringCostsBreakdown($id);
        $client['pl_alltime']  = $this->getAllTimePL($id);
        return $client;
    }
}
