<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use PDO;

/**
 * Data-quality checks: each check is one labelled query returning offending
 * rows with a link to fix them. Checks that declare a `fix` also get inline
 * controls (client picker / one-click suggestion) posting to fix().
 */
class DataQualityController
{
    /** Domain whose client differs from the (active) site that uses it; row id = domain id. */
    private const MISMATCH_SQL = "
        SELECT d.id, d.domain || ' — site: ' || sc.name || ', domain: ' || dc.name AS label,
               '/domains/' || d.id AS url, sc.id AS suggest_id, sc.name AS suggest_name
        FROM client_sites cs
        JOIN domains d  ON d.id = cs.domain_id
        JOIN clients sc ON sc.id = cs.client_id
        JOIN clients dc ON dc.id = d.client_id
        WHERE cs.client_id != d.client_id
          AND COALESCE(cs.status, 'active') = 'active' AND COALESCE(d.status, 'active') = 'active'
        GROUP BY d.id
        ORDER BY d.domain";

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
             WHERE cs.client_id IS NULL AND COALESCE(cs.status, 'active') = 'active' ORDER BY label",
            'site_client'
        );

        $checks[] = $this->check(
            'Sites without a server',
            'Sites with no server link — server costs cannot be apportioned to them.',
            "SELECT cs.id, COALESCE(d.domain, 'Site #' || cs.id) AS label, '/sites/' || cs.id AS url
             FROM client_sites cs
             LEFT JOIN domains d ON d.id = cs.domain_id
             WHERE cs.server_id IS NULL AND COALESCE(cs.status, 'active') = 'active' ORDER BY label"
        );

        $checks[] = $this->check(
            'Domains without a client',
            'Domains not linked to a client — their cost lands nowhere in the P&L.',
            "SELECT d.id, d.domain AS label, '/domains/' || d.id AS url,
                    (SELECT c.id FROM client_sites cs JOIN clients c ON c.id = cs.client_id
                     WHERE cs.domain_id = d.id ORDER BY cs.id LIMIT 1) AS suggest_id,
                    (SELECT c.name FROM client_sites cs JOIN clients c ON c.id = cs.client_id
                     WHERE cs.domain_id = d.id ORDER BY cs.id LIMIT 1) AS suggest_name
             FROM domains d
             WHERE d.client_id IS NULL AND COALESCE(d.status, 'active') = 'active'
             ORDER BY d.domain",
            'domain_client'
        );

        $checks[] = $this->check(
            'Sites and domains assigned to different clients',
            'The site belongs to one client but its domain to another, so the domain cost and renewal land on the wrong P&L. Usually the site is right.',
            self::MISMATCH_SQL,
            'domain_client'
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
            'fix'         => null,
        ];

        $totalIssues = array_sum(array_map(fn($c) => count($c['rows']), $checks));
        $clients     = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();

        $breadcrumbs = [['Settings', '/settings'], ['Data Quality', null]];
        render('settings.data_quality', compact('checks', 'totalIssues', 'clients', 'breadcrumbs'), 'Data Quality');
    }

    /** Count of issues across all checks, for the settings-page badge. */
    public static function issueCount(PDO $db): int
    {
        $count = 0;
        $queries = [
            "SELECT COUNT(*) FROM client_sites WHERE client_id IS NULL AND COALESCE(status, 'active') = 'active'",
            "SELECT COUNT(*) FROM client_sites WHERE server_id IS NULL AND COALESCE(status, 'active') = 'active'",
            "SELECT COUNT(*) FROM domains WHERE client_id IS NULL AND COALESCE(status,'active') = 'active'",
            "SELECT COUNT(*) FROM domains WHERE COALESCE(status,'active') = 'active' AND (renewal_date IS NULL OR annual_cost IS NULL)",
            "SELECT COUNT(*) FROM freeagent_recurring_invoices WHERE client_id IS NULL AND recurring_status = 'Active'",
            "SELECT COUNT(*) FROM clients c WHERE c.status = 'active' AND NOT EXISTS (SELECT 1 FROM agreements a WHERE a.client_id = c.id AND a.status = 'active')",
            "SELECT (SELECT COUNT(*) FROM ploi_servers WHERE is_stale = 1) + (SELECT COUNT(*) FROM ploi_sites WHERE is_stale = 1)",
            "SELECT COUNT(*) FROM recurring_costs rc WHERE rc.is_active = 1 AND rc.server_id IS NULL AND NOT EXISTS (SELECT 1 FROM recurring_cost_clients rcc WHERE rcc.recurring_cost_id = rc.id)",
            "SELECT COUNT(*) FROM (" . self::MISMATCH_SQL . ")",
        ];
        foreach ($queries as $q) {
            try { $count += (int)$db->query($q)->fetchColumn(); } catch (\Throwable) {}
        }
        return $count;
    }

    private function check(string $title, string $description, string $sql, ?string $fix = null): array
    {
        try {
            $rows = $this->db->query($sql)->fetchAll();
            return compact('title', 'description', 'rows', 'fix') + ['error' => null];
        } catch (\Throwable $e) {
            return compact('title', 'description', 'fix') + ['rows' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Inline fix from the Data Quality page (JSON).
     *   action=site_client   id=site   client_id  — also moves the site's domain (as SiteController::updateClient)
     *   action=domain_client id=domain client_id
     */
    public function fix(): void
    {
        header('Content-Type: application/json');
        if (!csrfCheck()) { http_response_code(419); echo json_encode(['error' => 'Invalid CSRF token']); exit; }

        $action   = (string)($_POST['action'] ?? '');
        $id       = (int)($_POST['id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);

        $stmt = $this->db->prepare("SELECT name FROM clients WHERE id = ?");
        $stmt->execute([$clientId]);
        $clientName = $stmt->fetchColumn();
        if (!$clientName || $id <= 0 || !in_array($action, ['site_client', 'domain_client'], true)) {
            http_response_code(422);
            echo json_encode(['error' => 'Choose a client']);
            exit;
        }

        if ($action === 'site_client') {
            $this->db->prepare("UPDATE client_sites SET client_id = ? WHERE id = ?")->execute([$clientId, $id]);
            $this->db->prepare(
                "UPDATE domains SET client_id = ? WHERE id = (SELECT domain_id FROM client_sites WHERE id = ?)"
            )->execute([$clientId, $id]);
        } else {
            $this->db->prepare("UPDATE domains SET client_id = ? WHERE id = ?")->execute([$clientId, $id]);
        }
        \CoyshCRM\Services\Attention::forgetBadge();
        echo json_encode(['ok' => true, 'message' => "Assigned to {$clientName}"]);
        exit;
    }
}
