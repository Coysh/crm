<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use PDO;

/**
 * Data-quality checks: each check is one labelled query returning offending
 * rows with a link to fix them. Kept read-only.
 */
class DataQualityController
{
    public function __construct(private PDO $db) {}

    public function index(): void
    {
        $checks = [];

        $checks[] = $this->check(
            'Sites without a client',
            'Ploi-imported or manually created sites not assigned to any client — their costs are not apportioned.',
            "SELECT cs.id, COALESCE(d.domain, ps.domain, 'Site #' || cs.id) AS label, '/sites/' || cs.id AS url
             FROM client_sites cs
             LEFT JOIN domains d ON d.id = cs.domain_id
             LEFT JOIN ploi_sites ps ON ps.client_site_id = cs.id
             WHERE cs.client_id IS NULL ORDER BY label"
        );

        $checks[] = $this->check(
            'Sites without a server',
            'Sites with no server link — server costs cannot be apportioned to them.',
            "SELECT cs.id, COALESCE(d.domain, 'Site #' || cs.id) AS label, '/sites/' || cs.id AS url
             FROM client_sites cs
             LEFT JOIN domains d ON d.id = cs.domain_id
             WHERE cs.server_id IS NULL ORDER BY label"
        );

        $checks[] = $this->check(
            'Domains without a client',
            'Domains not linked to a client — their cost lands nowhere in the P&L.',
            "SELECT d.id, d.domain AS label, '/domains/' || d.id AS url
             FROM domains d
             WHERE d.client_id IS NULL AND COALESCE(d.status, 'active') = 'active'
             ORDER BY d.domain"
        );

        $checks[] = $this->check(
            'Active domains missing renewal date or cost',
            'Renewals cannot be tracked and costs cannot be apportioned without these.',
            "SELECT d.id, d.domain || CASE WHEN d.renewal_date IS NULL AND d.annual_cost IS NULL THEN ' (renewal + cost)'
                                          WHEN d.renewal_date IS NULL THEN ' (renewal date)'
                                          ELSE ' (cost)' END AS label,
                    '/domains/' || d.id AS url
             FROM domains d
             WHERE COALESCE(d.status, 'active') = 'active'
               AND (d.renewal_date IS NULL OR d.annual_cost IS NULL)
             ORDER BY d.domain"
        );

        $checks[] = $this->check(
            'Active recurring invoices not mapped to a client',
            'Revenue from these FreeAgent recurring invoices is not counted in any client P&L.',
            "SELECT fri.id, COALESCE(fri.reference, 'Recurring invoice #' || fri.id) || ' — ' || COALESCE(fri.contact_name, 'unknown contact') AS label,
                    '/freeagent' AS url
             FROM freeagent_recurring_invoices fri
             WHERE fri.client_id IS NULL AND fri.recurring_status = 'Active'
             ORDER BY fri.reference"
        );

        $checks[] = $this->check(
            'Invoices not mapped to a client',
            'These invoices are excluded from per-client revenue. Map their contact in FreeAgent settings.',
            "SELECT fi.id, COALESCE(fi.reference, 'Invoice #' || fi.id) || ' (' || COALESCE(fi.dated_on, '?') || ')' AS label,
                    '/settings/freeagent/contacts' AS url
             FROM freeagent_invoices fi
             WHERE fi.client_id IS NULL
               AND COALESCE(fi.status_override, fi.status) IN ('paid','sent','overdue')
             ORDER BY fi.dated_on DESC LIMIT 50"
        );

        $checks[] = $this->check(
            'Active clients without a structured agreement',
            'No active agreement recorded — coverage, renewal, and hours cannot be tracked. (Managed clients are listed here rather than flagged on their health card.)',
            "SELECT c.id, c.name AS label, '/clients/' || c.id AS url
             FROM clients c
             WHERE c.status = 'active'
               AND NOT EXISTS (SELECT 1 FROM agreements a WHERE a.client_id = c.id AND a.status = 'active')
             ORDER BY c.name"
        );

        $checks[] = $this->check(
            'Stale Ploi records',
            'Servers/sites that no longer exist in Ploi. Purge them from Ploi settings.',
            "SELECT ps.id, 'Server: ' || ps.name AS label, '/settings/ploi' AS url FROM ploi_servers ps WHERE ps.is_stale = 1
             UNION ALL
             SELECT ps.id, 'Site: ' || ps.domain AS label, '/settings/ploi' AS url FROM ploi_sites ps WHERE ps.is_stale = 1"
        );

        $checks[] = $this->check(
            'Recurring costs with no assignment',
            'Active recurring costs not linked to a server, client, or site — they appear in totals but no client P&L.',
            "SELECT rc.id, rc.name AS label, '/expenses/recurring/' || rc.id || '/edit' AS url
             FROM recurring_costs rc
             WHERE rc.is_active = 1
               AND rc.server_id IS NULL
               AND NOT EXISTS (SELECT 1 FROM recurring_cost_clients rcc WHERE rcc.recurring_cost_id = rc.id)
             ORDER BY rc.name"
        );

        // Attachment files missing from disk (filesystem check, not SQL)
        $missingFiles = [];
        try {
            foreach ($this->db->query("SELECT id, client_id, original_name, file_path FROM client_attachments")->fetchAll() as $att) {
                if (!is_file($att['file_path'])) {
                    $missingFiles[] = [
                        'id'    => $att['id'],
                        'label' => $att['original_name'],
                        'url'   => '/clients/' . $att['client_id'],
                    ];
                }
            }
        } catch (\Throwable) {}
        $checks[] = [
            'title'       => 'Attachments missing on disk',
            'description' => 'Database rows whose PDF file no longer exists in data/attachments.',
            'rows'        => $missingFiles,
            'error'       => null,
        ];

        $totalIssues = array_sum(array_map(fn($c) => count($c['rows']), $checks));

        $breadcrumbs = [['Settings', '/settings'], ['Data Quality', null]];
        render('settings.data_quality', compact('checks', 'totalIssues', 'breadcrumbs'), 'Data Quality');
    }

    /** Count of issues across all checks, for the settings-page badge. */
    public static function issueCount(PDO $db): int
    {
        $count = 0;
        $queries = [
            "SELECT COUNT(*) FROM client_sites WHERE client_id IS NULL",
            "SELECT COUNT(*) FROM client_sites WHERE server_id IS NULL",
            "SELECT COUNT(*) FROM domains WHERE client_id IS NULL AND COALESCE(status,'active') = 'active'",
            "SELECT COUNT(*) FROM domains WHERE COALESCE(status,'active') = 'active' AND (renewal_date IS NULL OR annual_cost IS NULL)",
            "SELECT COUNT(*) FROM freeagent_recurring_invoices WHERE client_id IS NULL AND recurring_status = 'Active'",
            "SELECT COUNT(*) FROM clients c WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM agreements a WHERE a.client_id = c.id AND a.status = 'active')",
            "SELECT (SELECT COUNT(*) FROM ploi_servers WHERE is_stale = 1) + (SELECT COUNT(*) FROM ploi_sites WHERE is_stale = 1)",
            "SELECT COUNT(*) FROM recurring_costs rc WHERE rc.is_active = 1 AND rc.server_id IS NULL AND NOT EXISTS (SELECT 1 FROM recurring_cost_clients rcc WHERE rcc.recurring_cost_id = rc.id)",
        ];
        foreach ($queries as $q) {
            try { $count += (int)$db->query($q)->fetchColumn(); } catch (\Throwable) {}
        }
        return $count;
    }

    private function check(string $title, string $description, string $sql): array
    {
        try {
            $rows = $this->db->query($sql)->fetchAll();
            return compact('title', 'description', 'rows') + ['error' => null];
        } catch (\Throwable $e) {
            return compact('title', 'description') + ['rows' => [], 'error' => $e->getMessage()];
        }
    }
}
