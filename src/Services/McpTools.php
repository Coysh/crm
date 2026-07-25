<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use CoyshCRM\Models\Agreement;
use CoyshCRM\Models\Client;
use PDO;

/**
 * MCP tool definitions and dispatch. Read tools plus two safe writes
 * (logging SLA work, appending a client note) — no deletes, no edits.
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
                'description' => 'Upcoming renewals (domains, recurring costs, recurring invoices, agreement reviews) within a horizon, including anything up to 30 days overdue.',
                'inputSchema' => $obj([
                    'days' => ['type' => 'integer', 'description' => 'Days ahead to look (default 90, max 365)'],
                    'type' => ['type' => 'string', 'enum' => ['all', 'domain', 'recurring_cost', 'recurring_invoice', 'agreement']],
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
                'name' => 'business_summary',
                'description' => 'Headline business numbers: MRR, pipeline MRR, monthly costs, profit, client counts, health status counts, and upcoming renewal count.',
                'inputSchema' => $obj([]),
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
            'business_summary'    => $this->businessSummary(),
            'log_agreement_work'  => $this->logAgreementWork($args),
            'add_client_note'     => $this->addClientNote($args),
            default               => throw new \InvalidArgumentException("Unknown tool: $name"),
        };
    }

    public function isWriteTool(string $name): bool
    {
        return in_array($name, ['log_agreement_work', 'add_client_note'], true);
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
        return array_map(fn($r) => [
            'type'     => $r['type'],
            'name'     => $r['name'],
            'client'   => $r['client_name'],
            'due_date' => $r['due_date'],
            'amount'   => $r['amount'] !== null ? round((float)$r['amount'], 2) : null,
            'cycle'    => $r['cycle'],
            'relative' => $r['relative'],
        ], (new Renewals($this->db))->fetch($days, $type));
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

    // ── Write tools ──────────────────────────────────────────────────────

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

        $stamped  = '[' . date('Y-m-d') . ' via MCP] ' . mb_substr($note, 0, 2000);
        $existing = trim((string)($client['notes'] ?? ''));
        $combined = $existing === '' ? $stamped : $existing . "\n\n" . $stamped;

        $this->db->prepare("UPDATE clients SET notes = ?, updated_at = datetime('now') WHERE id = ?")->execute([$combined, $id]);

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
