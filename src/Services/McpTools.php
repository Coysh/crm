<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use CoyshCRM\Models\Agreement;
use CoyshCRM\Models\Client;
use PDO;

/**
 * MCP tool definitions and dispatch. Read tools plus a few safe writes
 * (logging SLA work, appending a client note, marking a renewal done,
 * snoozing a Today item) — no deletes, no free-form edits.
 */
class McpTools
{
    private Client $clients;
    private Agreement $agreements;

    public function __construct(private PDO $db)
    {
        $this->clients    = new Client($db);
        $this->agreements = new Agreement($db);
    }

    /** Tool descriptors for tools/list. */
    public function list(): array
    {
        $obj = fn(array $props, array $required = []) => [
            'type'       => 'object',
            'properties' => $props ?: new \stdClass(),
            'required'   => $required,
        ];
        $id  = fn(string $desc) => ['type' => 'integer', 'description' => $desc];

        return [
            [
                'name' => 'list_clients',
                'description' => 'List CRM clients with monthly recurring revenue, invoiced totals, and site counts. Optionally filter by status or search by name.',
                'inputSchema' => $obj([
                    'status' => ['type' => 'string', 'enum' => ['active', 'archived'], 'description' => 'Filter by status (default active)'],
                    'search' => ['type' => 'string', 'description' => 'Case-insensitive name/contact search'],
                ]),
            ],
            [
                'name' => 'get_client',
                'description' => 'Full detail for one client: contacts, domains, sites, agreements with remaining SLA hours, recurring income, projects, expenses, and P&L.',
                'inputSchema' => $obj(['client_id' => $id('Client ID')], ['client_id']),
            ],
            [
                'name' => 'get_client_pl',
                'description' => 'Monthly and all-time profit & loss for one client (revenue, apportioned costs, profit, margin).',
                'inputSchema' => $obj(['client_id' => $id('Client ID')], ['client_id']),
            ],
            [
                'name' => 'list_agreements',
                'description' => 'List agreements/SLAs across all clients (or one client), including coverage, fees, renewal dates, and hours used/remaining this period.',
                'inputSchema' => $obj([
                    'client_id'   => $id('Restrict to one client'),
                    'active_only' => ['type' => 'boolean', 'description' => 'Only active agreements (default true)'],
                ]),
            ],
            [
                'name' => 'get_agreement',
                'description' => 'One agreement in full: terms, coverage, response commitments, hours allowance and usage, plus the recent work log.',
                'inputSchema' => $obj(['agreement_id' => $id('Agreement ID')], ['agreement_id']),
            ],
            [
                'name' => 'list_agreement_work',
                'description' => 'Work log entries for an agreement, optionally limited to a date range.',
                'inputSchema' => $obj([
                    'agreement_id' => $id('Agreement ID'),
                    'from'         => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD'],
                    'to'           => ['type' => 'string', 'description' => 'End date YYYY-MM-DD'],
                ], ['agreement_id']),
            ],
            [
                'name' => 'list_renewals',
                'description' => 'Upcoming renewals (domains, recurring costs, recurring invoices, agreement reviews) within a horizon, including anything up to a year overdue. Each row has type + id for mark_renewal_done.',
                'inputSchema' => $obj([
                    'days'      => ['type' => 'integer', 'description' => 'Days ahead to look (default 90, max 365)'],
                    'type'      => ['type' => 'string', 'enum' => ['all', 'domain', 'recurring_cost', 'recurring_invoice', 'agreement']],
                    'client_id' => $id('Restrict to one client (excludes shared recurring costs)'),
                ]),
            ],
            [
                'name' => 'list_domains',
                'description' => 'List domains with registrar, renewal date, cost, and client charge. Optionally filter by client or search.',
                'inputSchema' => $obj([
                    'client_id' => $id('Restrict to one client'),
                    'search'    => ['type' => 'string', 'description' => 'Substring match on the domain name'],
                ]),
            ],
            [
                'name' => 'list_site_uptime',
                'description' => 'Uptime Kuma monitor state per site: up/down/paused, how long it has been in that state, uptime percentages, response time and TLS certificate days remaining. Uptime normally comes from Uptime Kuma itself; where uptime_is_estimate is true it was calculated by the CRM from its own samples and only covers the period since the integration was switched on.',
                'inputSchema' => $obj([
                    'client_id' => $id('Restrict to one client'),
                    'status'    => ['type' => 'string', 'enum' => ['all', 'up', 'down'], 'description' => 'Filter by current state (default all)'],
                    'unlinked'  => ['type' => 'boolean', 'description' => 'Include monitors not linked to a CRM site (default false)'],
                ]),
            ],
            [
                'name' => 'business_summary',
                'description' => 'Headline business numbers: MRR, pipeline MRR, monthly costs, profit, client counts, health status counts, and upcoming renewal count.',
                'inputSchema' => $obj([]),
            ],
            [
                'name' => 'get_attention',
                'description' => 'The Today list: everything that needs attention, prioritised — sites down, failed/stale sync jobs, overdue invoices, renewals due within 14 days, SLA hours running out, and (optionally) chronic client health flags and data gaps. Each item has a severity (high = act now, medium = this week, low = housekeeping), a key for snooze_attention_item, and a link. Start here for "what needs doing?".',
                'inputSchema' => $obj([
                    'include_low'     => ['type' => 'boolean', 'description' => 'Include low-severity housekeeping items (default false)'],
                    'include_snoozed' => ['type' => 'boolean', 'description' => 'Include snoozed/dismissed items, flagged as such (default false)'],
                ]),
            ],
            [
                'name' => 'list_invoices',
                'description' => 'Unpaid FreeAgent invoices with gross amounts and days overdue. Imported (e.g. Hiveage) invoices are flagged — they never re-sync, so "unpaid" there often means the status was never updated.',
                'inputSchema' => $obj([
                    'status'    => ['type' => 'string', 'enum' => ['unpaid', 'overdue', 'draft'], 'description' => 'unpaid = sent or overdue (default); overdue = past due only'],
                    'client_id' => $id('Restrict to one client'),
                ]),
            ],
            [
                'name' => 'get_client_health',
                'description' => 'Client health flags (loss-making, no retainer, overdue invoices, incomplete setup, site down, SLA hours exhausted…) with labels. One client, or every active client that has at least one flag.',
                'inputSchema' => $obj(['client_id' => $id('One client; omit for all flagged clients')]),
            ],
            [
                'name' => 'search',
                'description' => 'Find clients, domains, sites, projects and agreements by name/domain/contact. Returns ids to use with the other tools.',
                'inputSchema' => $obj(['query' => ['type' => 'string', 'description' => 'At least 2 characters']], ['query']),
            ],
            [
                'name' => 'get_sync_status',
                'description' => 'Health of scheduled jobs (FreeAgent, Ploi, Cloudflare, WPMGR, Uptime Kuma syncs, email worker, backup, digest): state, last success, last error.',
                'inputSchema' => $obj([]),
            ],
            [
                'name' => 'mark_renewal_done',
                'description' => 'Mark a domain, recurring cost or agreement as renewed (write): rolls its renewal date forward one term (domains by their renewal years, costs by billing cycle, agreements by a year) until it is on or after today. Get type + id from list_renewals or get_attention. Recurring invoices cannot be renewed here — FreeAgent advances them.',
                'inputSchema' => $obj([
                    'type' => ['type' => 'string', 'enum' => Renewals::RENEWABLE],
                    'id'   => $id('The domain / recurring cost / agreement ID'),
                ], ['type', 'id']),
            ],
            [
                'name' => 'snooze_attention_item',
                'description' => 'Snooze a Today item for 7, 30 or 90 days, or dismiss it (write). Keys identify one incident, so the next occurrence still shows. Use the key from get_attention.',
                'inputSchema' => $obj([
                    'key'  => ['type' => 'string', 'description' => 'Item key from get_attention'],
                    'days' => ['type' => 'string', 'enum' => ['7', '30', '90', 'dismiss']],
                ], ['key', 'days']),
            ],
            [
                'name' => 'log_agreement_work',
                'description' => 'Log support/maintenance work against an agreement (write). Returns remaining hours for the current period.',
                'inputSchema' => $obj([
                    'agreement_id' => $id('Agreement ID'),
                    'work_date'    => ['type' => 'string', 'description' => 'Date YYYY-MM-DD (default today)'],
                    'hours'        => ['type' => 'number', 'description' => 'Hours spent, e.g. 1.5'],
                    'description'  => ['type' => 'string', 'description' => 'What was done'],
                ], ['agreement_id', 'hours', 'description']),
            ],
            [
                'name' => 'add_client_note',
                'description' => 'Append a dated note to a client record (write). Existing notes are preserved.',
                'inputSchema' => $obj([
                    'client_id' => $id('Client ID'),
                    'note'      => ['type' => 'string', 'description' => 'Note text to append'],
                ], ['client_id', 'note']),
            ],
        ];
    }

    /**
     * Execute a tool. Returns the data payload; throws InvalidArgumentException
     * for bad input (surfaced to the model as an isError tool result).
     */
    public function call(string $name, array $args): mixed
    {
        return match ($name) {
            'list_clients'        => $this->listClients($args),
            'get_client'          => $this->getClient($args),
            'get_client_pl'       => $this->getClientPl($args),
            'list_agreements'     => $this->listAgreements($args),
            'get_agreement'       => $this->getAgreement($args),
            'list_agreement_work' => $this->listAgreementWork($args),
            'list_renewals'       => $this->listRenewals($args),
            'list_domains'        => $this->listDomains($args),
            'list_site_uptime'    => $this->listSiteUptime($args),
            'business_summary'    => $this->businessSummary(),
            'get_attention'       => $this->getAttention($args),
            'list_invoices'       => $this->listInvoices($args),
            'get_client_health'   => $this->getClientHealth($args),
            'search'              => $this->search($args),
            'get_sync_status'     => $this->getSyncStatus(),
            'mark_renewal_done'   => $this->markRenewalDone($args),
            'snooze_attention_item' => $this->snoozeAttentionItem($args),
            'log_agreement_work'  => $this->logAgreementWork($args),
            'add_client_note'     => $this->addClientNote($args),
            default               => throw new \InvalidArgumentException("Unknown tool: $name"),
        };
    }

    public function isWriteTool(string $name): bool
    {
        return in_array($name, ['log_agreement_work', 'add_client_note', 'mark_renewal_done', 'snooze_attention_item'], true);
    }

    // ── Read tools ───────────────────────────────────────────────────────

    private function listClients(array $args): array
    {
        $status  = in_array($args['status'] ?? '', ['active', 'archived']) ? $args['status'] : 'active';
        $filters = [];
        if (!empty($args['search']) && is_string($args['search'])) $filters['search'] = $args['search'];

        return array_map(fn($c) => [
            'id'             => (int)$c['id'],
            'name'           => $c['name'],
            'status'         => $c['status'],
            'client_type'    => $c['client_type'] ?? 'managed',
            'contact_name'   => $c['contact_name'],
            'contact_email'  => $c['contact_email'],
            'mrr'            => round((float)$c['mrr'], 2),
            'total_invoiced' => round((float)$c['total_invoiced'], 2),
            'outstanding'    => round((float)$c['outstanding'], 2),
            'site_count'     => (int)$c['site_count'],
        ], $this->clients->findAllWithFilters($status, $filters));
    }

    private function getClient(array $args): array
    {
        $client = $this->clients->findWithFullDetails($this->requireId($args, 'client_id'));
        if (!$client) throw new \InvalidArgumentException('Client not found');

        return [
            'id'            => (int)$client['id'],
            'name'          => $client['name'],
            'status'        => $client['status'],
            'client_type'   => $client['client_type'] ?? 'managed',
            'contact_name'  => $client['contact_name'],
            'contact_email' => $client['contact_email'],
            'notes'         => $client['notes'],
            'agreement_notes' => $client['agreement_notes'] ?? null,
            'domains'       => array_map(fn($d) => [
                'id' => (int)$d['id'], 'domain' => $d['domain'], 'registrar' => $d['registrar'],
                'renewal_date' => $d['renewal_date'], 'annual_cost' => $d['annual_cost'],
                'client_charge' => $d['client_charge'] ?? null, 'status' => $d['status'] ?? 'active',
            ], $client['domains']),
            'sites'         => array_map(fn($s) => [
                'id' => (int)$s['id'], 'domain' => $s['domain_name'] ?? $s['ploi_domain'] ?? null,
                'server' => $s['server_name'], 'stack' => $s['website_stack'],
                'php_version' => $s['ploi_php_version'] ?? null, 'git_repo' => $s['git_repo'],
            ], $client['sites']),
            'agreements'    => array_map(fn($a) => $this->agreementSummary($a), $client['agreements']),
            'recurring_income' => array_map(fn($ri) => [
                'reference' => $ri['reference'], 'frequency' => $ri['frequency'],
                'net_value' => $ri['net_value'], 'status' => $ri['recurring_status'],
                'next_recurs_on' => $ri['next_recurs_on'],
            ], $client['recurring_invoices']),
            'projects'      => array_map(fn($p) => [
                'id' => (int)$p['id'], 'name' => $p['name'], 'status' => $p['status'],
                'income_category' => $p['income_category'],
                'income_target' => $p['income_target'] ?? null, 'income_invoiced' => $p['income_invoiced'] ?? null,
            ], $client['projects']),
            'pl_monthly'    => $this->roundPl($client['pl']),
            'pl_all_time'   => array_map(fn($v) => round((float)$v, 2), $client['pl_alltime']),
        ];
    }

    private function getClientPl(array $args): array
    {
        $id = $this->requireId($args, 'client_id');
        $client = $this->clients->findById($id);
        if (!$client) throw new \InvalidArgumentException('Client not found');
        return [
            'client'   => $client['name'],
            'monthly'  => $this->roundPl($this->clients->getPL($id)),
            'all_time' => array_map(fn($v) => round((float)$v, 2), $this->clients->getAllTimePL($id)),
        ];
    }

    private function listAgreements(array $args): array
    {
        $activeOnly = ($args['active_only'] ?? true) !== false;
        $rows = $this->agreements->findAllWithClient($activeOnly ? 'active' : null);
        if (!empty($args['client_id'])) {
            $cid  = (int)$args['client_id'];
            $rows = array_values(array_filter($rows, fn($a) => (int)$a['client_id'] === $cid));
        }
        return array_map(fn($a) => $this->agreementSummary($a) + ['client' => $a['client_name']], $rows);
    }

    private function getAgreement(array $args): array
    {
        $id = $this->requireId($args, 'agreement_id');
        $a  = $this->agreements->findById($id);
        if (!$a) throw new \InvalidArgumentException('Agreement not found');
        $a = $this->agreements->withUsage($a);
        $client = $this->clients->findById((int)$a['client_id']);

        return $this->agreementSummary($a) + [
            'client'         => $client['name'] ?? null,
            'response_terms' => $a['response_terms'],
            'notes'          => $a['notes'],
            'start_date'     => $a['start_date'],
            'recent_work'    => array_map(fn($w) => [
                'date' => $w['work_date'], 'hours' => (float)$w['hours'], 'description' => $w['description'],
            ], $this->agreements->workLog($id, 20)),
        ];
    }

    private function listAgreementWork(array $args): array
    {
        $id = $this->requireId($args, 'agreement_id');
        if (!$this->agreements->findById($id)) throw new \InvalidArgumentException('Agreement not found');

        $sql = "SELECT work_date, hours, description FROM agreement_work_log WHERE agreement_id = ?";
        $params = [$id];
        if (!empty($args['from'])) { $sql .= " AND work_date >= ?"; $params[] = $args['from']; }
        if (!empty($args['to']))   { $sql .= " AND work_date <= ?"; $params[] = $args['to']; }
        $sql .= " ORDER BY work_date DESC LIMIT 200";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        return [
            'entries'     => array_map(fn($w) => ['date' => $w['work_date'], 'hours' => (float)$w['hours'], 'description' => $w['description']], $rows),
            'total_hours' => round(array_sum(array_map(fn($w) => (float)$w['hours'], $rows)), 2),
        ];
    }

    private function listRenewals(array $args): array
    {
        $days = min(365, max(1, (int)($args['days'] ?? 90)));
        $type = in_array($args['type'] ?? 'all', array_merge(['all'], Renewals::TYPES)) ? ($args['type'] ?? 'all') : 'all';
        $clientId = !empty($args['client_id']) ? (int)$args['client_id'] : null;
        return array_map(fn($r) => [
            'type'     => $r['type'],
            'id'       => (int)$r['item_id'],
            'name'     => $r['name'],
            'client'   => $r['client_name'],
            'due_date' => $r['due_date'],
            'amount'   => $r['amount'] !== null ? round((float)$r['amount'], 2) : null,
            'cycle'    => $r['cycle'],
            'relative' => $r['relative'],
            'renewable' => in_array($r['type'], Renewals::RENEWABLE, true),
        ], (new Renewals($this->db))->fetch($days, $type, $clientId));
    }

    private function listDomains(array $args): array
    {
        $sql = "SELECT d.*, c.name AS client_name FROM domains d LEFT JOIN clients c ON c.id = d.client_id WHERE 1=1";
        $params = [];
        if (!empty($args['client_id'])) { $sql .= " AND d.client_id = ?"; $params[] = (int)$args['client_id']; }
        if (!empty($args['search']) && is_string($args['search'])) {
            $sql .= " AND d.domain LIKE ?";
            $params[] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $args['search']) . '%';
        }
        $sql .= " ORDER BY d.domain LIMIT 200";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map(fn($d) => [
            'id'            => (int)$d['id'],
            'domain'        => $d['domain'],
            'client'        => $d['client_name'],
            'registrar'     => $d['registrar'],
            'status'        => $d['status'] ?? 'active',
            'renewal_date'  => $d['renewal_date'],
            'annual_cost'   => $d['annual_cost'],
            'client_charge' => $d['client_charge'] ?? null,
        ], $stmt->fetchAll());
    }

    private function listSiteUptime(array $args): array
    {
        // Stale monitors (absent from Uptime Kuma for several syncs — usually
        // paused or renamed) are never reported; their state is meaningless.
        $sql = "SELECT m.*, d.domain AS site_domain, c.name AS client_name
                FROM uptime_kuma_monitors m
                LEFT JOIN client_sites cs ON cs.id = m.client_site_id
                LEFT JOIN domains d       ON d.id  = cs.domain_id
                LEFT JOIN clients c       ON c.id  = cs.client_id
                WHERE m.is_stale = 0";
        $params = [];

        if (!empty($args['client_id'])) {
            $sql .= " AND cs.client_id = ?";
            $params[] = (int)$args['client_id'];
        } elseif (empty($args['unlinked'])) {
            $sql .= " AND m.client_site_id IS NOT NULL";
        }

        $status = $args['status'] ?? 'all';
        if ($status === 'down')    $sql .= " AND m.status = 0";
        elseif ($status === 'up')  $sql .= " AND m.status = 1";

        $sql .= " ORDER BY m.status, LOWER(m.monitor_name) LIMIT 200";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }

        $states = [0 => 'down', 1 => 'up', 2 => 'pending', 3 => 'maintenance'];

        return array_map(fn($m) => [
            'monitor'             => $m['monitor_name'],
            'type'                => $m['monitor_type'],
            'target'              => $m['monitor_url'] ?: $m['monitor_hostname'],
            'client'              => $m['client_name'],
            'site_id'             => $m['client_site_id'] !== null ? (int)$m['client_site_id'] : null,
            'site_domain'         => $m['site_domain'],
            'status'              => $states[(int)$m['status']] ?? 'unknown',
            'in_state_since'      => $m['status_changed_at'],
            'paused'              => isset($m['active']) ? ((int)$m['active'] === 0) : false,
            'uptime_24h_pct'      => $m['uptime_24h'] !== null ? (float)$m['uptime_24h'] : null,
            'uptime_30d_pct'      => $m['uptime_30d'] !== null ? (float)$m['uptime_30d'] : null,
            // true = calculated from this CRM's samples, so only covers the
            // period since the integration was switched on
            'uptime_is_estimate'  => !empty($m['uptime_is_local']),
            'response_time_ms'    => $m['response_time_ms'] !== null ? (int)$m['response_time_ms'] : null,
            'cert_days_remaining' => $m['cert_days_remaining'] !== null ? (int)$m['cert_days_remaining'] : null,
            'last_checked'        => $m['last_synced_at'],
        ], $rows);
    }

    private function businessSummary(): array
    {
        $mrrSql = \CoyshCRM\Models\FreeAgentRecurringInvoice::monthlySql();
        $mrr = (float)$this->db->query("SELECT COALESCE(SUM($mrrSql),0) FROM freeagent_recurring_invoices WHERE recurring_status = 'Active'")->fetchColumn();
        $pipeline = (float)$this->db->query("SELECT COALESCE(SUM($mrrSql),0) FROM freeagent_recurring_invoices WHERE recurring_status = 'Draft'")->fetchColumn();

        $plAll = $this->clients->getPLAll();
        $totalCosts  = round(array_sum(array_column($plAll, 'totalCosts')), 2);
        $totalProfit = round(array_sum(array_column($plAll, 'profit')), 2);

        $health = $this->clients->getHealthAll($plAll);
        $healthCounts = ['healthy' => 0, 'attention' => 0, 'at_risk' => 0];
        foreach ($health as $h) $healthCounts[$h['status']]++;

        $renewals = (new Renewals($this->db))->fetch(90);
        $overdue  = count(array_filter($renewals, fn($r) => $r['days_diff'] < 0));

        return [
            'mrr'                  => round($mrr, 2),
            'pipeline_mrr'         => round($pipeline, 2),
            'monthly_costs'        => $totalCosts,
            'monthly_profit'       => $totalProfit,
            'active_clients'       => count($plAll),
            'client_health'        => $healthCounts,
            'renewals_next_90d'    => count($renewals),
            'renewals_overdue'     => $overdue,
        ];
    }

    private function getAttention(array $args): array
    {
        $items = (new Attention($this->db))->items(!empty($args['include_low']), !empty($args['include_snoozed']));
        $base  = appUrl();
        return array_map(fn($i) => array_filter([
            'key'           => $i['key'],
            'severity'      => $i['severity'],
            'kind'          => $i['kind'],
            'title'         => $i['title'],
            'detail'        => $i['detail'] ?: null,
            'client_id'     => $i['client_id'],
            'client'        => $i['client_name'],
            'url'           => $base . $i['url'],
            'renewal'       => ($i['action']['type'] ?? null) === 'renew'
                ? ['type' => $i['action']['renewal_type'], 'id' => $i['action']['id']] : null,
            'snoozed_until' => $i['snoozed'] ? ($i['snoozed_until'] ?? 'dismissed') : null,
        ], fn($v) => $v !== null), $items);
    }

    private function listInvoices(array $args): array
    {
        $status = $args['status'] ?? 'unpaid';
        $where = match ($status) {
            'draft'   => "COALESCE(fi.status_override, fi.status) = 'draft'",
            'overdue' => "(COALESCE(fi.status_override, fi.status) = 'overdue'
                          OR (COALESCE(fi.status_override, fi.status) = 'sent'
                              AND COALESCE(fi.due_date, date(fi.dated_on, '+30 days')) < date('now')))",
            default   => "COALESCE(fi.status_override, fi.status) IN ('sent', 'overdue')",
        };
        $sql = "SELECT fi.id, fi.reference, fi.total_value, fi.net_value, COALESCE(fi.currency, 'GBP') AS currency,
                       COALESCE(fi.status_override, fi.status) AS status, fi.dated_on, fi.due_date,
                       COALESCE(fi.source, 'freeagent') AS source, c.id AS client_id, c.name AS client_name
                FROM freeagent_invoices fi LEFT JOIN clients c ON c.id = fi.client_id
                WHERE $where";
        $params = [];
        if (!empty($args['client_id'])) { $sql .= " AND fi.client_id = ?"; $params[] = (int)$args['client_id']; }
        $sql .= " ORDER BY COALESCE(fi.due_date, fi.dated_on)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $today = strtotime('today');
        $rows  = array_map(function ($r) use ($today) {
            $due = $r['due_date'] ?: ($r['dated_on'] ? date('Y-m-d', strtotime($r['dated_on'] . ' +30 days')) : null);
            return [
                'id'           => (int)$r['id'],
                'reference'    => $r['reference'],
                'client_id'    => $r['client_id'] ? (int)$r['client_id'] : null,
                'client'       => $r['client_name'],
                'status'       => $r['status'],
                'total_gross'  => round((float)$r['total_value'], 2),
                'net'          => $r['net_value'] !== null ? round((float)$r['net_value'], 2) : null,
                'currency'     => $r['currency'],
                'dated_on'     => $r['dated_on'],
                'due_date'     => $due,
                'days_overdue' => $due ? max(0, (int)floor(($today - strtotime($due)) / 86400)) : null,
                'imported'     => $r['source'] !== 'freeagent',
            ];
        }, $stmt->fetchAll());

        $totals = [];
        foreach ($rows as $r) $totals[$r['currency']] = round(($totals[$r['currency']] ?? 0) + $r['total_gross'], 2);
        return ['count' => count($rows), 'total_gross_by_currency' => $totals, 'invoices' => $rows];
    }

    private function getClientHealth(array $args): array
    {
        $label = fn(array $h) => ['status' => $h['status'], 'flags' => array_map(
            fn($f) => ['flag' => $f, 'label' => healthFlagLabel($f)], $h['flags'])];

        if (!empty($args['client_id'])) {
            $id = (int)$args['client_id'];
            $client = $this->clients->findById($id) ?: throw new \InvalidArgumentException('Client not found');
            return ['client_id' => $id, 'client' => $client['name']] + $label($this->clients->getHealth($id));
        }

        $names = $this->db->query("SELECT id, name FROM clients WHERE status = 'active'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $out = [];
        foreach ($this->clients->getHealthAll($this->clients->getPLAll()) as $cid => $h) {
            if (!$h['flags']) continue;
            $out[] = ['client_id' => (int)$cid, 'client' => $names[$cid] ?? null] + $label($h);
        }
        usort($out, fn($a, $b) => count($b['flags']) <=> count($a['flags']));
        return ['flagged_clients' => count($out), 'active_clients' => count($names), 'clients' => $out];
    }

    private function search(array $args): array
    {
        $q = trim((string)($args['query'] ?? ''));
        if (mb_strlen($q) < 2) throw new \InvalidArgumentException('query must be at least 2 characters');
        $base = appUrl();
        return array_map(fn($r) => [
            'type'     => strtolower($r['type']),
            'id'       => $r['id'],
            'name'     => $r['title'],
            'detail'   => $r['sub'],
            'inactive' => $r['inactive'],
            'url'      => $base . $r['url'],
        ], (new Search($this->db))->find($q, 10));
    }

    private function getSyncStatus(): array
    {
        $runner = new JobRunner($this->db);
        $jobs = [];
        foreach ($runner->status() as $name => $j) {
            $lines = array_filter(array_map('trim', explode("\n", (string)($j['last_failure']['output'] ?? ''))));
            $jobs[] = [
                'job'          => $name,
                'label'        => $j['label'],
                'schedule'     => $j['schedule'],
                'state'        => $j['state'],
                'last_success' => $j['last_ok_at'],
                'last_error'   => $j['last_failure'] ? ['at' => $j['last_failure']['started_at'], 'message' => (string)end($lines)] : null,
            ];
        }
        return ['cron_installed' => $runner->isInstalled(), 'jobs' => $jobs];
    }

    // ── Write tools ──────────────────────────────────────────────────────

    private function markRenewalDone(array $args): array
    {
        $type = (string)($args['type'] ?? '');
        if (!in_array($type, Renewals::RENEWABLE, true)) {
            throw new \InvalidArgumentException('type must be one of: ' . implode(', ', Renewals::RENEWABLE));
        }
        try {
            $r = (new Renewals($this->db))->markRenewed($type, $this->requireId($args, 'id'));
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        }
        return ['type' => $type, 'name' => $r['name'], 'previous_due' => $r['old'], 'next_due' => $r['new']];
    }

    private function snoozeAttentionItem(array $args): array
    {
        $key  = trim((string)($args['key'] ?? ''));
        $days = (string)($args['days'] ?? '');
        if ($key === '' || strlen($key) > 255) throw new \InvalidArgumentException('key is required');
        if (!in_array($days, ['7', '30', '90', 'dismiss'], true)) throw new \InvalidArgumentException('days must be 7, 30, 90 or dismiss');
        (new Attention($this->db))->snooze($key, $days === 'dismiss' ? null : (int)$days);
        return ['key' => $key, 'snoozed' => $days === 'dismiss' ? 'dismissed' : "for {$days} days"];
    }

    private function logAgreementWork(array $args): array
    {
        $id = $this->requireId($args, 'agreement_id');
        $agreement = $this->agreements->findById($id);
        if (!$agreement) throw new \InvalidArgumentException('Agreement not found');

        $hours = (float)($args['hours'] ?? 0);
        if ($hours <= 0 || $hours > 100) throw new \InvalidArgumentException('hours must be between 0 and 100');

        $date = (string)($args['work_date'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new \InvalidArgumentException('work_date must be YYYY-MM-DD');

        $description = trim((string)($args['description'] ?? ''));
        if ($description === '') throw new \InvalidArgumentException('description is required');

        $this->agreements->addWork($id, $date, $hours, mb_substr($description, 0, 500));
        $updated = $this->agreements->withUsage($this->agreements->findById($id));

        return [
            'logged'          => ['agreement' => $agreement['title'], 'date' => $date, 'hours' => $hours, 'description' => $description],
            'hours_used'      => $updated['hours_used'],
            'hours_remaining' => $updated['hours_remaining'],
            'included_hours'  => $updated['included_hours'] !== null ? (float)$updated['included_hours'] : null,
            'period_start'    => $updated['period_start'],
        ];
    }

    private function addClientNote(array $args): array
    {
        $id = $this->requireId($args, 'client_id');
        $client = $this->clients->findById($id);
        if (!$client) throw new \InvalidArgumentException('Client not found');

        $note = trim((string)($args['note'] ?? ''));
        if ($note === '') throw new \InvalidArgumentException('note is required');

        $stamped = $this->clients->appendNote($id, $note, 'MCP');

        return ['client' => $client['name'], 'note_added' => $stamped];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function agreementSummary(array $a): array
    {
        return [
            'id'              => (int)$a['id'],
            'client_id'       => (int)$a['client_id'],
            'title'           => $a['title'],
            'type'            => $a['agreement_type'],
            'status'          => $a['status'],
            'covers'          => array_keys(array_filter([
                'hosting'     => !empty($a['covers_hosting']),
                'support'     => !empty($a['covers_support']),
                'maintenance' => !empty($a['covers_maintenance']),
            ])),
            'included_hours'  => $a['included_hours'] !== null ? (float)$a['included_hours'] : null,
            'hours_period'    => $a['hours_period'],
            'hours_used'      => $a['hours_used'] ?? null,
            'hours_remaining' => $a['hours_remaining'] ?? null,
            'fee'             => $a['fee_amount'] !== null ? (float)$a['fee_amount'] : null,
            'fee_currency'    => $a['fee_currency'] ?? 'GBP',
            'fee_cycle'       => $a['fee_billing_cycle'],
            'renewal_date'    => $a['renewal_date'],
        ];
    }

    private function roundPl(array $pl): array
    {
        return array_map(fn($v) => round((float)$v, 2), $pl);
    }

    private function requireId(array $args, string $key): int
    {
        $v = $args[$key] ?? null;
        if (!is_numeric($v) || (int)$v <= 0) throw new \InvalidArgumentException("$key (positive integer) is required");
        return (int)$v;
    }
}
