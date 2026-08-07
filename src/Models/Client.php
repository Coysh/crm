<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Client extends Model
{
    protected string $table = 'clients';

    /** Memoised per instance — see uptimeMonitoringActive(). */
    private ?bool $uptimeActive = null;

    public function findAllWithStats(?string $status = null): array
    {
        $mrrSql = FreeAgentRecurringInvoice::monthlySql();
        $agrSql = $this->agreementMrrSql('c.id');
        $siteActiveCs = $this->siteStatusFilter('cs');
        $sql = "SELECT c.*,
            (SELECT COUNT(*) FROM client_sites cs WHERE cs.client_id = c.id{$siteActiveCs}) AS site_count,
            (SELECT COALESCE(SUM($mrrSql), 0)
             FROM freeagent_recurring_invoices fri
             WHERE fri.client_id = c.id AND fri.recurring_status = 'Active')
            + $agrSql AS mrr
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

        $agrSql = $this->agreementMrrSql('c.id');
        $siteActiveCs = $this->siteStatusFilter('cs');

        $sql = "SELECT c.*,
            (SELECT COUNT(*) FROM client_sites cs WHERE cs.client_id = c.id{$siteActiveCs}) AS site_count,
            (SELECT COALESCE(SUM($mrrSql), 0)
             FROM freeagent_recurring_invoices fri
             WHERE fri.client_id = c.id AND fri.recurring_status = 'Active')
            + $agrSql AS mrr,
            (CASE WHEN EXISTS(SELECT 1 FROM freeagent_recurring_invoices fri
                WHERE fri.client_id = c.id AND fri.recurring_status = 'Active')
                OR $agrSql > 0 THEN 1 ELSE 0 END) AS has_recurring,
            (SELECT COALESCE(SUM(COALESCE(fi.net_value, fi.total_value)), 0)
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
        $mrr = (float)$row['mrr'];

        // Plus any active agreement not billed through a linked recurring invoice.
        if ($this->hasAgreementsTable()) {
            $agr = $this->query(
                "SELECT " . Agreement::unlinkedMrrSql('?') . " AS agreement_mrr",
                [$id]
            )->fetch();
            $mrr += (float)($agr['agreement_mrr'] ?? 0);
        }

        return $mrr;
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

    /** Check once whether client_sites.status column exists (migration 030). */
    private function hasSiteStatusColumn(): bool
    {
        static $checked = null;
        if ($checked !== null) return $checked;
        try {
            $this->query("SELECT status FROM client_sites LIMIT 0");
            $checked = true;
        } catch (\Throwable) {
            $checked = false;
        }
        return $checked;
    }

    /** Check once whether recurring_costs.currency column exists (migration 014). */
    /**
     * Whether the agreements table is present (tolerates partially-migrated DBs,
     * same feature-detection pattern as hasCurrencyColumn()).
     */
    private function hasAgreementsTable(): bool
    {
        static $checked = null;
        if ($checked !== null) return $checked;
        try {
            $this->query("SELECT id FROM agreements LIMIT 0");
            $checked = true;
        } catch (\Throwable) {
            $checked = false;
        }
        return $checked;
    }

    /**
     * Monthly-equivalent fees from active agreements that aren't billed through a
     * linked FreeAgent recurring invoice. Returns '0' when the table is absent so
     * callers can always interpolate it into their SELECT.
     */
    private function agreementMrrSql(string $clientIdExpr): string
    {
        return $this->hasAgreementsTable()
            ? Agreement::unlinkedMrrSql($clientIdExpr)
            : '0';
    }

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

    /** SQL fragment excluding archived sites, or '' pre-migration 030. */
    private function siteStatusFilter(string $alias): string
    {
        return $this->hasSiteStatusColumn() ? " AND COALESCE($alias.status,'active')='active'" : '';
    }

    /**
     * Whether uptime health checks should run at all (migration 032 applied AND
     * Uptime Kuma actually connected).
     *
     * Without this gate every client would trip site_unmonitored the moment the
     * migration lands and before the first sync — a whole dashboard of false
     * red. "Not monitored" is only meaningful once monitoring exists.
     */
    private function uptimeMonitoringActive(): bool
    {
        // Instance property, not a `static` local like the schema checks above:
        // those memoise a fact about the schema, which can't change while the
        // process runs. This reads a config row the user can change by hitting
        // Connect/Disconnect, and a `static` would leak one instance's answer
        // into every later Client in the same process.
        if ($this->uptimeActive !== null) return $this->uptimeActive;
        try {
            $this->query("SELECT id FROM uptime_kuma_monitors LIMIT 0");
            $key = $this->query("SELECT api_key FROM uptime_kuma_config WHERE id = 1")->fetchColumn();
            $this->uptimeActive = !empty($key);
        } catch (\Throwable) {
            $this->uptimeActive = false;
        }
        return $this->uptimeActive;
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
            $siteActiveCs2 = $this->siteStatusFilter('cs2');
            $siteActiveCs  = $this->siteStatusFilter('cs');
            $rows = $this->query("
                SELECT
                    (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                    / MAX(1, (SELECT COUNT(DISTINCT cs2.client_id) FROM client_sites cs2 WHERE cs2.server_id = rc.server_id{$siteActiveCs2})) AS monthly_share
                    {$currCol}
                FROM recurring_costs rc
                WHERE rc.is_active = 1 AND rc.server_id IS NOT NULL
                  AND EXISTS (SELECT 1 FROM client_sites cs WHERE cs.server_id = rc.server_id AND cs.client_id = ?{$siteActiveCs})
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
        $siteActiveCs  = $this->siteStatusFilter('cs');
        $siteActiveCs3 = $this->siteStatusFilter('cs3');
        $rows = $this->query("
            SELECT
                (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                / MAX(1, (SELECT COUNT(*) FROM recurring_cost_clients c2
                          JOIN client_sites cs3 ON cs3.id = c2.client_site_id
                          WHERE c2.recurring_cost_id = rc.id AND c2.client_site_id IS NOT NULL{$siteActiveCs3}))
                * COUNT(*) AS monthly_share
                {$currCol}
            FROM recurring_costs rc
            JOIN recurring_cost_clients rcc ON rcc.recurring_cost_id = rc.id
            JOIN client_sites cs ON cs.id = rcc.client_site_id AND cs.client_id = ?{$siteActiveCs}
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
            $siteActiveCs2 = $this->siteStatusFilter('cs2');
            $siteActiveCs  = $this->siteStatusFilter('cs');
            $serverRows = $this->query("
                SELECT rc.id, rc.name, rc.amount, rc.billing_cycle, rc.server_id,
                       (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                       / MAX(1, (SELECT COUNT(DISTINCT cs2.client_id) FROM client_sites cs2 WHERE cs2.server_id = rc.server_id{$siteActiveCs2})) AS monthly_share,
                       (SELECT COUNT(DISTINCT cs2.client_id) FROM client_sites cs2 WHERE cs2.server_id = rc.server_id{$siteActiveCs2}) AS shared_count,
                       'server' AS assignment_type,
                       NULL AS total_sites,
                       NULL AS client_site_count
                       {$currCol}
                FROM recurring_costs rc
                WHERE rc.is_active = 1 AND rc.server_id IS NOT NULL
                  AND EXISTS (SELECT 1 FROM client_sites cs WHERE cs.server_id = rc.server_id AND cs.client_id = ?{$siteActiveCs})
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
        $siteActiveCs  = $this->siteStatusFilter('cs');
        $siteActiveCs3 = $this->siteStatusFilter('cs3');
        $siteRows = $this->query("
            SELECT rc.id, rc.name, rc.amount, rc.billing_cycle, NULL AS server_id,
                   COUNT(*) AS client_site_count,
                   (SELECT COUNT(*) FROM recurring_cost_clients c2
                    JOIN client_sites cs3 ON cs3.id = c2.client_site_id
                    WHERE c2.recurring_cost_id = rc.id AND c2.client_site_id IS NOT NULL{$siteActiveCs3}) AS total_sites,
                   (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                   / MAX(1, (SELECT COUNT(*) FROM recurring_cost_clients c2
                             JOIN client_sites cs3 ON cs3.id = c2.client_site_id
                             WHERE c2.recurring_cost_id = rc.id AND c2.client_site_id IS NOT NULL{$siteActiveCs3}))
                   * COUNT(*) AS monthly_share,
                   'site' AS assignment_type,
                   NULL AS shared_count
                   {$currCol}
            FROM recurring_costs rc
            JOIN recurring_cost_clients rcc ON rcc.recurring_cost_id = rc.id
            JOIN client_sites cs ON cs.id = rcc.client_site_id AND cs.client_id = ?{$siteActiveCs}
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

    /**
     * P&L for every active client in a handful of grouped queries instead of
     * ~7 queries per client. Returns client_id => same shape as getPL().
     */
    public function getPLAll(): array
    {
        $clientIds = $this->query("SELECT id FROM clients WHERE status = 'active'")->fetchAll(\PDO::FETCH_COLUMN);
        if (!$clientIds) return [];

        $zero = ['mrr' => 0.0, 'domainCost' => 0.0, 'directExpenses' => 0.0, 'recurringCosts' => 0.0];
        $pl = array_fill_keys(array_map('intval', $clientIds), $zero);

        $mrrSql = FreeAgentRecurringInvoice::monthlySql();
        foreach ($this->query(
            "SELECT client_id, COALESCE(SUM($mrrSql), 0) AS mrr
             FROM freeagent_recurring_invoices
             WHERE recurring_status = 'Active' AND client_id IS NOT NULL
             GROUP BY client_id"
        )->fetchAll() as $r) {
            if (isset($pl[(int)$r['client_id']])) $pl[(int)$r['client_id']]['mrr'] = (float)$r['mrr'];
        }

        // Agreements billed outside FreeAgent add to MRR (linked ones are already
        // counted above via their recurring invoice).
        if ($this->hasAgreementsTable()) {
            $agrSql = Agreement::monthlySql('ag');
            foreach ($this->query(
                "SELECT ag.client_id, COALESCE(SUM($agrSql), 0) AS mrr
                 FROM agreements ag
                 WHERE ag.status = 'active'
                   AND ag.freeagent_recurring_invoice_id IS NULL
                   AND ag.client_id IS NOT NULL
                 GROUP BY ag.client_id"
            )->fetchAll() as $r) {
                $cid = (int)$r['client_id'];
                if (isset($pl[$cid])) $pl[$cid]['mrr'] += (float)$r['mrr'];
            }
        }

        // Domains — FX-converted per row, matching getMonthlyDomainCost()
        $fx = $this->hasCurrencyColumn() ? $this->fx() : null;
        foreach ($this->query(
            "SELECT client_id, annual_cost / 12.0 AS monthly, COALESCE(currency, 'GBP') AS currency
             FROM domains WHERE client_id IS NOT NULL AND annual_cost IS NOT NULL"
        )->fetchAll() as $r) {
            $cid = (int)$r['client_id'];
            if (!isset($pl[$cid])) continue;
            $pl[$cid]['domainCost'] += $fx ? $fx->convertToGBP((float)$r['monthly'], $r['currency']) : (float)$r['monthly'];
        }

        foreach ($this->query(
            "SELECT client_id, COALESCE(SUM(CASE billing_cycle WHEN 'monthly' THEN amount WHEN 'annual' THEN amount/12 WHEN 'one_off' THEN 0 ELSE amount END), 0) AS monthly
             FROM expenses WHERE client_id IS NOT NULL AND ignore_from_stats = 0
             GROUP BY client_id"
        )->fetchAll() as $r) {
            if (isset($pl[(int)$r['client_id']])) $pl[(int)$r['client_id']]['directExpenses'] = (float)$r['monthly'];
        }

        // Recurring cost shares — the same three apportionment paths as
        // getMonthlyRecurringCosts(), grouped by client.
        $currCol      = $this->hasCurrencyColumn() ? ", COALESCE(rc.currency, 'GBP') AS currency" : ", 'GBP' AS currency";
        $serverFilter = $this->hasServerIdColumn() ? 'AND rc.server_id IS NULL' : '';

        $siteActiveCs2 = $this->siteStatusFilter('cs2');
        $siteActiveCs  = $this->siteStatusFilter('cs');
        $siteActiveCs3 = $this->siteStatusFilter('cs3');
        $siteActiveInner = $this->hasSiteStatusColumn() ? "AND COALESCE(status,'active')='active'" : '';

        $rcRows = [];
        if ($this->hasServerIdColumn()) {
            $rcRows = array_merge($rcRows, $this->query("
                SELECT cs.client_id,
                       (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                       / MAX(1, (SELECT COUNT(DISTINCT cs2.client_id) FROM client_sites cs2 WHERE cs2.server_id = rc.server_id{$siteActiveCs2})) AS monthly_share
                       {$currCol}
                FROM recurring_costs rc
                JOIN (SELECT DISTINCT server_id, client_id FROM client_sites WHERE client_id IS NOT NULL {$siteActiveInner}) cs
                  ON cs.server_id = rc.server_id
                WHERE rc.is_active = 1 AND rc.server_id IS NOT NULL
            ")->fetchAll());
        }
        $rcRows = array_merge($rcRows, $this->query("
            SELECT rcc.client_id,
                   (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                   / MAX(1, (SELECT COUNT(DISTINCT c2.client_id) FROM recurring_cost_clients c2
                             WHERE c2.recurring_cost_id = rc.id AND c2.client_id IS NOT NULL)) AS monthly_share
                   {$currCol}
            FROM recurring_costs rc
            JOIN (SELECT DISTINCT recurring_cost_id, client_id FROM recurring_cost_clients WHERE client_id IS NOT NULL) rcc
              ON rcc.recurring_cost_id = rc.id
            WHERE rc.is_active = 1 $serverFilter
        ")->fetchAll());
        $rcRows = array_merge($rcRows, $this->query("
            SELECT cs.client_id,
                   (CASE rc.billing_cycle WHEN 'monthly' THEN rc.amount ELSE rc.amount / 12.0 END)
                   / MAX(1, (SELECT COUNT(*) FROM recurring_cost_clients c2
                             JOIN client_sites cs3 ON cs3.id = c2.client_site_id
                             WHERE c2.recurring_cost_id = rc.id AND c2.client_site_id IS NOT NULL{$siteActiveCs3}))
                   * COUNT(*) AS monthly_share
                   {$currCol}
            FROM recurring_costs rc
            JOIN recurring_cost_clients rcc ON rcc.recurring_cost_id = rc.id AND rcc.client_site_id IS NOT NULL
            JOIN client_sites cs ON cs.id = rcc.client_site_id AND cs.client_id IS NOT NULL{$siteActiveCs}
            WHERE rc.is_active = 1 $serverFilter
            GROUP BY rc.id, cs.client_id
        ")->fetchAll());

        foreach ($rcRows as $r) {
            $cid = (int)$r['client_id'];
            if (!isset($pl[$cid])) continue;
            $share = (float)$r['monthly_share'];
            $pl[$cid]['recurringCosts'] += $fx ? $fx->convertToGBP($share, $r['currency'] ?? 'GBP') : $share;
        }

        foreach ($pl as &$row) {
            $row['totalCosts'] = $row['domainCost'] + $row['directExpenses'] + $row['recurringCosts'];
            $row['profit']     = $row['mrr'] - $row['totalCosts'];
            $row['margin']     = $row['mrr'] > 0 ? ($row['profit'] / $row['mrr']) * 100 : 0;
        }
        unset($row);

        return $pl;
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

        // Consultancy-only clients aren't expected to have a retainer.
        // An active agreement counts as a retainer even when it isn't billed
        // through a FreeAgent recurring invoice.
        if ($clientType !== 'consultancy_only') {
            $hasRetainer = (bool)$this->query(
                "SELECT 1 FROM freeagent_recurring_invoices WHERE client_id = ? AND recurring_status = 'Active' LIMIT 1",
                [$clientId]
            )->fetchColumn();
            if (!$hasRetainer && $this->hasAgreementsTable()) {
                $hasRetainer = (bool)$this->query(
                    "SELECT 1 FROM agreements WHERE client_id = ? AND status = 'active' LIMIT 1",
                    [$clientId]
                )->fetchColumn();
            }
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
            $hasSites   = (bool)$this->query("SELECT 1 FROM client_sites WHERE client_id = ?" . $this->siteStatusFilter('client_sites') . " LIMIT 1", [$clientId])->fetchColumn();
            $hasDomains = (bool)$this->query("SELECT 1 FROM domains WHERE client_id = ? LIMIT 1", [$clientId])->fetchColumn();
            if (!$hasSites || !$hasDomains) $flags[] = 'incomplete_setup';
        }

        // Structured agreements: renewal overdue / hours exhausted apply to everyone;
        // support/consultancy clients are flagged when they have neither an active
        // agreement nor legacy agreement notes.
        $agreements = [];
        try {
            $agreements = (new Agreement($this->db))->findByClient($clientId);
        } catch (\Throwable) {}
        $activeAgreements = array_filter($agreements, fn($a) => $a['status'] === 'active');

        if (in_array($clientType, ['support_only', 'consultancy_only'])) {
            if (!$activeAgreements && empty($client['agreement_notes'])) $flags[] = 'no_agreement';
        }
        $today = date('Y-m-d');
        foreach ($activeAgreements as $a) {
            if (!empty($a['renewal_date']) && $a['renewal_date'] < $today && !in_array('agreement_renewal_overdue', $flags)) {
                $flags[] = 'agreement_renewal_overdue';
            }
            if ($a['hours_remaining'] !== null && $a['hours_remaining'] <= 0 && !in_array('hours_exhausted', $flags)) {
                $flags[] = 'hours_exhausted';
            }
        }

        // Uptime — skipped entirely unless Uptime Kuma is connected, so a fresh
        // install never shows every client as unmonitored.
        if ($this->uptimeMonitoringActive()) {
            $siteActive = $this->siteStatusFilter('cs');
            try {
                $isDown = (bool)$this->query(
                    "SELECT 1 FROM client_sites cs
                     JOIN uptime_kuma_monitors m ON m.client_site_id = cs.id
                     WHERE cs.client_id = ? AND m.is_stale = 0 AND m.status = 0" . $siteActive . " LIMIT 1",
                    [$clientId]
                )->fetchColumn();
                if ($isDown) $flags[] = 'site_down';

                if ($clientType === 'managed') {
                    $unmonitored = (bool)$this->query(
                        "SELECT 1 FROM client_sites cs
                         WHERE cs.client_id = ?{$siteActive}
                           AND NOT EXISTS (
                               SELECT 1 FROM uptime_kuma_monitors m
                               WHERE m.client_site_id = cs.id AND m.is_stale = 0
                           ) LIMIT 1",
                        [$clientId]
                    )->fetchColumn();
                    if ($unmonitored) $flags[] = 'site_unmonitored';
                }
            } catch (\Throwable) {}
        }

        $count  = count($flags);
        $status = match(true) {
            $count === 0 => 'healthy',
            $count <= 2  => 'attention',
            default      => 'at_risk',
        };

        return ['status' => $status, 'flags' => $flags, 'pl' => $pl];
    }

    /**
     * @param array|null $plByClient Optional precomputed getPLAll() map to avoid
     *                               recomputing the P&L per client.
     */
    public function getHealthAll(?array $plByClient = null): array
    {
        $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
        $twelveMonAgo  = date('Y-m-d', strtotime('-12 months'));

        $activeClients = $this->query("SELECT id, COALESCE(client_type,'managed') AS client_type, COALESCE(agreement_notes,'') AS agreement_notes FROM clients WHERE status = 'active'")->fetchAll();
        if (!$activeClients) return [];

        // Aggregate: has active retainer — an Active FreeAgent recurring invoice,
        // or an active agreement (which may be billed outside FreeAgent).
        $retainerIds = $this->query(
            "SELECT DISTINCT client_id FROM freeagent_recurring_invoices WHERE recurring_status = 'Active' AND client_id IS NOT NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);
        if ($this->hasAgreementsTable()) {
            $retainerIds = array_merge($retainerIds, $this->query(
                "SELECT DISTINCT client_id FROM agreements WHERE status = 'active' AND client_id IS NOT NULL"
            )->fetchAll(\PDO::FETCH_COLUMN));
        }
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

        // Aggregate: has sites (active only — an all-archived client should
        // still trip incomplete_setup)
        $siteIds = $this->query(
            "SELECT DISTINCT client_id FROM client_sites WHERE client_id IS NOT NULL" . $this->siteStatusFilter('client_sites')
        )->fetchAll(\PDO::FETCH_COLUMN);
        $siteSet = array_flip($siteIds);

        // Aggregate: has domains
        $domainIds = $this->query(
            "SELECT DISTINCT client_id FROM domains WHERE client_id IS NOT NULL"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $domainSet = array_flip($domainIds);

        // Aggregate: active structured agreements (with usage for hours flags)
        $agreementSet = $renewalOverdueSet = $hoursExhaustedSet = [];
        try {
            $today = date('Y-m-d');
            foreach ((new Agreement($this->db))->findAllWithClient('active') as $a) {
                $acid = (int)$a['client_id'];
                $agreementSet[$acid] = true;
                if (!empty($a['renewal_date']) && $a['renewal_date'] < $today) $renewalOverdueSet[$acid] = true;
                if ($a['hours_remaining'] !== null && $a['hours_remaining'] <= 0) $hoursExhaustedSet[$acid] = true;
            }
        } catch (\Throwable) {}

        // Aggregate: uptime. Both are no-ops unless Uptime Kuma is connected.
        $siteDownSet = $unmonitoredSet = [];
        if ($this->uptimeMonitoringActive()) {
            $siteActive = $this->siteStatusFilter('cs');
            try {
                $siteDownSet = array_flip($this->query(
                    "SELECT DISTINCT cs.client_id
                     FROM client_sites cs
                     JOIN uptime_kuma_monitors m ON m.client_site_id = cs.id
                     WHERE cs.client_id IS NOT NULL AND m.is_stale = 0 AND m.status = 0" . $siteActive
                )->fetchAll(\PDO::FETCH_COLUMN));

                // An active site with no live monitor pointing at it.
                $unmonitoredSet = array_flip($this->query(
                    "SELECT DISTINCT cs.client_id
                     FROM client_sites cs
                     WHERE cs.client_id IS NOT NULL{$siteActive}
                       AND NOT EXISTS (
                           SELECT 1 FROM uptime_kuma_monitors m
                           WHERE m.client_site_id = cs.id AND m.is_stale = 0
                       )"
                )->fetchAll(\PDO::FETCH_COLUMN));
            } catch (\Throwable) {}
        }

        $result = [];
        foreach ($activeClients as $row) {
            $cid        = (int)$row['id'];
            $clientType = $row['client_type'] ?? 'managed';
            $flags      = [];

            $pl = $plByClient[$cid] ?? $this->getPL($cid);
            if ($pl['profit'] < 0) $flags[] = 'loss_making';

            if ($clientType !== 'consultancy_only' && !isset($retainerSet[$cid])) $flags[] = 'no_retainer';
            if (!isset($recentSet[$cid])) $flags[] = 'no_recent_invoice';
            if (isset($overdueSet[$cid])) $flags[] = 'overdue_invoices';

            if ($clientType === 'managed' && (!isset($siteSet[$cid]) || !isset($domainSet[$cid]))) {
                $flags[] = 'incomplete_setup';
            }

            if (in_array($clientType, ['support_only', 'consultancy_only'])
                && !isset($agreementSet[$cid]) && empty($row['agreement_notes'])) {
                $flags[] = 'no_agreement';
            }
            if (isset($renewalOverdueSet[$cid])) $flags[] = 'agreement_renewal_overdue';
            if (isset($hoursExhaustedSet[$cid])) $flags[] = 'hours_exhausted';

            if (isset($siteDownSet[$cid])) $flags[] = 'site_down';
            if ($clientType === 'managed' && isset($unmonitoredSet[$cid])) $flags[] = 'site_unmonitored';

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
                "SELECT COALESCE(SUM(COALESCE(net_value, total_value)), 0) FROM freeagent_invoices WHERE client_id = ? AND COALESCE(status_override, status) IN ('paid','sent','overdue')",
                [$clientId]
            )->fetchColumn();
        } catch (\Throwable) {}

        // Total expenses (all time) — direct expenses
        $totalExpenses = 0.0;
        try {
            $totalExpenses = (float)$this->query(
                "SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE client_id = ? AND COALESCE(ignore_from_stats, 0) = 0",
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
        $sitesSelect = "SELECT cs.*, d.domain AS domain_name, s.name AS server_name, ps.domain AS ploi_domain, ps.project_type AS ploi_project_type, ps.php_version AS ploi_php_version, ps.repository AS ploi_repository, ps.branch AS ploi_branch, ps.has_ssl AS ploi_has_ssl, ps.web_directory AS ploi_web_directory, ps.test_domain AS ploi_test_domain, ps.status AS ploi_status, ps.is_stale AS ploi_is_stale";
        $sitesFrom   = " FROM client_sites cs LEFT JOIN domains d ON d.id = cs.domain_id LEFT JOIN servers s ON s.id = cs.server_id LEFT JOIN ploi_sites ps ON ps.client_site_id = cs.id";
        $kumaSelect  = $kumaJoin = '';
        if ($this->uptimeMonitoringActive()) {
            $kumaSelect = ", uk.monitor_count AS kuma_monitor_count, uk.status AS kuma_status, uk.uptime_30d AS kuma_uptime_30d";
            $kumaJoin   = " LEFT JOIN (" . \CoyshCRM\Services\UptimeKumaService::siteRollupSql() . ") uk ON uk.client_site_id = cs.id";
        }
        $client['sites'] = $this->query($sitesSelect . $kumaSelect . $sitesFrom . $kumaJoin . " WHERE cs.client_id = ? ORDER BY d.domain", [$id])->fetchAll();
        $client['recurring_invoices'] = $this->query("SELECT * FROM freeagent_recurring_invoices WHERE client_id = ? ORDER BY CASE recurring_status WHEN 'Active' THEN 0 ELSE 1 END, reference", [$id])->fetchAll();
        $client['projects'] = $this->query("SELECT * FROM projects WHERE client_id = ? ORDER BY created_at DESC", [$id])->fetchAll();
        $client['expenses'] = $this->query("SELECT e.*, s.name AS server_name, p.name AS project_name FROM expenses e LEFT JOIN servers s ON s.id = e.server_id LEFT JOIN projects p ON p.id = e.project_id WHERE e.client_id = ? ORDER BY e.date DESC", [$id])->fetchAll();
        $client['attachments'] = $this->query("SELECT * FROM client_attachments WHERE client_id = ? ORDER BY uploaded_at DESC", [$id])->fetchAll();
        $client['agreements'] = [];
        try {
            $agreementModel = new Agreement($this->db);
            $client['agreements'] = array_map(function ($a) use ($agreementModel) {
                $a['work_log']    = $agreementModel->workLog((int)$a['id'], 20);
                $a['attachments'] = $agreementModel->attachments((int)$a['id']);
                return $a;
            }, $agreementModel->findByClient($id));
        } catch (\Throwable) {}
        $client['pl']          = $this->getPL($id);
        $client['pl_recurring'] = $this->getRecurringCostsBreakdown($id);
        $client['pl_alltime']  = $this->getAllTimePL($id);
        return $client;
    }
}
