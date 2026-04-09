<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Models\Client;
use CoyshCRM\Models\ClientSite;
use CoyshCRM\Models\Domain;
use CoyshCRM\Models\Server;
use CoyshCRM\Services\PloiService;
use PDO;

class SiteController
{
    private ClientSite $model;
    private PloiService $ploi;

    public function __construct(private PDO $db)
    {
        $this->model = new ClientSite($db);
        $this->ploi  = new PloiService($db);
    }

    public function index(): void
    {
        $group  = in_array($_GET['group'] ?? '', ['server', 'client']) ? $_GET['group'] : 'all';
        $ploiConnected = $this->ploi->isConnected();

        $sites = $this->db->query("
            SELECT cs.*,
                d.domain         AS domain_name,
                c.id             AS client_id,
                c.name           AS client_name,
                s.id             AS server_id,
                s.name           AS server_name,
                ps.domain        AS ploi_domain,
                ps.status        AS ploi_status,
                ps.id            AS ploi_site_id
            FROM client_sites cs
            LEFT JOIN domains d   ON d.id  = cs.domain_id
            LEFT JOIN clients c   ON c.id  = cs.client_id
            LEFT JOIN servers s   ON s.id  = cs.server_id
            LEFT JOIN ploi_sites ps ON ps.client_site_id = cs.id
            ORDER BY LOWER(COALESCE(d.domain, '')), cs.id
        ")->fetchAll();

        // Grouped views
        $grouped = [];
        if ($group === 'server') {
            foreach ($sites as $site) {
                $key = $site['server_name'] ?? 'No Server';
                $grouped[$key][] = $site;
            }
            ksort($grouped);
        } elseif ($group === 'client') {
            foreach ($sites as $site) {
                $key = $site['client_name'] ?? '__unassigned';
                $grouped[$key][] = $site;
            }
            ksort($grouped);
            // Move "Unassigned" to end
            if (isset($grouped['__unassigned'])) {
                $unassigned = $grouped['__unassigned'];
                unset($grouped['__unassigned']);
                $grouped['Unassigned'] = $unassigned;
            }
        }

        $servers    = $this->db->query("SELECT id, name FROM servers ORDER BY name")->fetchAll();
        $stacks     = $this->db->query(
            "SELECT DISTINCT website_stack FROM client_sites WHERE website_stack != '' AND website_stack IS NOT NULL ORDER BY website_stack"
        )->fetchAll(PDO::FETCH_COLUMN);
        $allClients = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();

        render('sites.index', compact('sites', 'grouped', 'group', 'servers', 'stacks', 'allClients', 'ploiConnected'), 'Sites');
    }

    public function show(int $id): void
    {
        $site = $this->fetchSiteFull($id);
        if (!$site) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $ploiDetails = null;
        if ($site['ploi_site_id']) {
            $stmt = $this->db->prepare("SELECT * FROM ploi_sites WHERE id = ? LIMIT 1");
            $stmt->execute([$site['ploi_site_id']]);
            $ploiDetails = $stmt->fetch() ?: null;
        }

        $breadcrumbs = [['Sites', '/sites'], [$site['domain_name'] ?? 'Site #' . $id, null]];
        render('sites.show', compact('site', 'ploiDetails', 'breadcrumbs'), $site['domain_name'] ?? 'Site');
    }

    public function create(): void
    {
        $site        = [];
        $errors      = [];
        $breadcrumbs = [['Sites', '/sites'], ['Add Site', null]];
        $clients     = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();
        $servers     = $this->db->query("SELECT id, name FROM servers ORDER BY name")->fetchAll();
        $domains     = $this->db->query("SELECT id, domain, client_id FROM domains WHERE COALESCE(status, 'active') = 'active' ORDER BY domain")->fetchAll();
        [$ploiConnected, $ploiSites] = $this->ploiSiteOptions(null, null);
        render('sites.form_standalone', compact('site', 'errors', 'clients', 'servers', 'domains', 'breadcrumbs', 'ploiConnected', 'ploiSites'), 'Add Site');
    }

    public function store(): void
    {
        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            $site        = $data;
            $breadcrumbs = [['Sites', '/sites'], ['Add Site', null]];
            $clients     = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();
            $servers     = $this->db->query("SELECT id, name FROM servers ORDER BY name")->fetchAll();
            $domains     = $this->db->query("SELECT id, domain, client_id FROM domains WHERE COALESCE(status, 'active') = 'active' ORDER BY domain")->fetchAll();
            [$ploiConnected, $ploiSites] = $this->ploiSiteOptions($data['server_id'] ?? null, null);
            render('sites.form_standalone', compact('site', 'errors', 'clients', 'servers', 'domains', 'breadcrumbs', 'ploiConnected', 'ploiSites'), 'Add Site');
            return;
        }

        $id = $this->model->insert($data);
        $this->savePloiSiteLink($id, (int)($_POST['ploi_site_id'] ?? 0));
        flash('success', 'Site created.');
        redirect("/sites/$id");
    }

    public function edit(int $id): void
    {
        $site = $this->model->findById($id);
        if (!$site) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $errors      = [];
        $breadcrumbs = [['Sites', '/sites'], [$this->siteName($site), "/sites/$id"], ['Edit', null]];
        $clients     = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();
        $servers     = $this->db->query("SELECT id, name FROM servers ORDER BY name")->fetchAll();
        $domains     = $this->db->query("SELECT id, domain, client_id FROM domains WHERE COALESCE(status, 'active') = 'active' ORDER BY domain")->fetchAll();

        $stmt = $this->db->prepare("SELECT id FROM ploi_sites WHERE client_site_id = ? LIMIT 1");
        $stmt->execute([$id]);
        $currentPloiId = $stmt->fetchColumn() ?: null;
        [$ploiConnected, $ploiSites] = $this->ploiSiteOptions($site['server_id'] ? (int)$site['server_id'] : null, $currentPloiId ? (int)$currentPloiId : null);

        render('sites.form_standalone', compact('site', 'errors', 'clients', 'servers', 'domains', 'breadcrumbs', 'ploiConnected', 'ploiSites', 'currentPloiId'), 'Edit Site');
    }

    public function update(int $id): void
    {
        $site = $this->model->findById($id);
        if (!$site) {
            http_response_code(404);
            render('errors.404', [], '404 Not Found');
            return;
        }

        $data   = $this->sanitise($_POST);
        $errors = $this->validate($data);

        if ($errors) {
            $breadcrumbs = [['Sites', '/sites'], [$this->siteName($site), "/sites/$id"], ['Edit', null]];
            $clients     = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();
            $servers     = $this->db->query("SELECT id, name FROM servers ORDER BY name")->fetchAll();
            $domains     = $this->db->query("SELECT id, domain, client_id FROM domains WHERE COALESCE(status, 'active') = 'active' ORDER BY domain")->fetchAll();
            [$ploiConnected, $ploiSites] = $this->ploiSiteOptions($data['server_id'] ?? null, null);
            render('sites.form_standalone', compact('site', 'errors', 'clients', 'servers', 'domains', 'breadcrumbs', 'ploiConnected', 'ploiSites'), 'Edit Site');
            return;
        }

        $this->model->update($id, $data);
        $this->savePloiSiteLink($id, (int)($_POST['ploi_site_id'] ?? 0));
        flash('success', 'Site updated.');
        redirect("/sites/$id");
    }

    public function destroy(int $id): void
    {
        $this->db->prepare("UPDATE ploi_sites SET client_site_id = NULL WHERE client_site_id = ?")->execute([$id]);
        $this->model->delete($id);
        flash('success', 'Site deleted.');
        redirect('/sites');
    }

    public function updateClient(int $id): void
    {
        $clientId = $_POST['client_id'] !== '' ? (int)$_POST['client_id'] : null;
        $this->db->prepare("UPDATE client_sites SET client_id = ? WHERE id = ?")->execute([$clientId, $id]);

        // Keep linked domain's client_id in sync
        if ($clientId) {
            $stmt = $this->db->prepare("SELECT domain_id FROM client_sites WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $domainId = $stmt->fetchColumn();
            if ($domainId) {
                $this->db->prepare("UPDATE domains SET client_id = ? WHERE id = ?")->execute([$clientId, $domainId]);
            }
        }

        $clientName = null;
        if ($clientId) {
            $s = $this->db->prepare("SELECT name FROM clients WHERE id = ? LIMIT 1");
            $s->execute([$clientId]);
            $clientName = $s->fetchColumn() ?: null;
        }

        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'client_id' => $clientId, 'client_name' => $clientName]);
        exit;
    }

    public function matching(): void
    {
        $allClients = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();
        $matches    = $this->computeMatches();

        $withSuggestions = count(array_filter($matches, fn($m) => !empty($m['suggestions'])));
        $stats = [
            'total'           => count($matches),
            'withSuggestions' => $withSuggestions,
            'noSuggestions'   => count($matches) - $withSuggestions,
        ];

        $breadcrumbs = [['Sites', '/sites'], ['Auto-match', null]];
        render('sites.matching', compact('matches', 'stats', 'allClients', 'breadcrumbs'), 'Site Auto-Matching');
    }

    private function computeMatches(): array
    {
        $unassigned = $this->db->query("
            SELECT cs.id, cs.server_id,
                   COALESCE(d.domain, ps.domain) AS domain_raw,
                   s.name AS server_name
            FROM client_sites cs
            LEFT JOIN domains d   ON d.id  = cs.domain_id
            LEFT JOIN servers s   ON s.id  = cs.server_id
            LEFT JOIN ploi_sites ps ON ps.client_site_id = cs.id
            WHERE cs.client_id IS NULL
            ORDER BY LOWER(COALESCE(d.domain, ps.domain, ''))
        ")->fetchAll();

        $clients = $this->db->query("
            SELECT id, name, contact_email
            FROM clients WHERE status = 'active'
        ")->fetchAll();

        $results = [];
        foreach ($unassigned as $site) {
            $domain = $site['domain_raw'] ?? '';
            if (!$domain) {
                $results[(int)$site['id']] = ['site' => $site, 'suggestions' => []];
                continue;
            }

            $cleanedDomain = $this->cleanDomain($domain);
            $scores = [];

            foreach ($clients as $client) {
                $score = $this->scoreSiteClient($cleanedDomain, $client);
                if ($score >= 20) {
                    $scores[] = ['client' => $client, 'score' => $score];
                }
            }

            usort($scores, fn($a, $b) => $b['score'] - $a['score']);

            $results[(int)$site['id']] = [
                'site'        => $site,
                'suggestions' => array_slice($scores, 0, 3),
            ];
        }

        return $results;
    }

    private function scoreSiteClient(string $cleanedDomain, array $client): int
    {
        $cleanedName   = $this->cleanClientText($client['name']);
        $domainCompact = str_replace(' ', '', $cleanedDomain);
        $nameCompact   = str_replace(' ', '', $cleanedName);

        if (!$domainCompact || !$nameCompact) return 0;

        $score = 0;

        // 1. Email domain match
        if (!empty($client['contact_email']) && str_contains($client['contact_email'], '@')) {
            [, $emailHost] = explode('@', $client['contact_email'], 2);
            $emailClean    = str_replace(' ', '', $this->cleanDomain($emailHost));
            if ($emailClean) {
                if ($emailClean === $domainCompact) {
                    $score = max($score, 90);
                } elseif (str_contains($domainCompact, $emailClean) || str_contains($emailClean, $domainCompact)) {
                    $shorter = min(strlen($domainCompact), strlen($emailClean));
                    $longer  = max(strlen($domainCompact), strlen($emailClean));
                    $score   = max($score, (int)($shorter / $longer * 80 + 8));
                }
            }
        }

        // 2. Exact match
        if ($domainCompact === $nameCompact) return 100;

        // 3. Full-string contains
        if (str_contains($domainCompact, $nameCompact) || str_contains($nameCompact, $domainCompact)) {
            $shorter = min(strlen($domainCompact), strlen($nameCompact));
            $longer  = max(strlen($domainCompact), strlen($nameCompact));
            $score   = max($score, (int)($shorter / $longer * 75 + 15));
        }

        // 4. Word-level: does domain contain any word from client name, or vice versa?
        $clientWords = array_values(array_filter(explode(' ', $cleanedName), fn($w) => strlen($w) >= 4));
        $siteWords   = array_values(array_filter(explode(' ', $cleanedDomain), fn($w) => strlen($w) >= 4));

        foreach ($clientWords as $word) {
            if (str_contains($domainCompact, $word)) {
                $score = max($score, (int)(strlen($word) / strlen($domainCompact) * 75 + 10));
            }
        }
        foreach ($siteWords as $word) {
            if (str_contains($nameCompact, $word)) {
                $score = max($score, (int)(strlen($word) / strlen($nameCompact) * 75 + 10));
            }
        }

        // 5. Word overlap (when both have multiple words)
        if ($clientWords && $siteWords) {
            $overlap = count(array_intersect($siteWords, $clientWords));
            if ($overlap > 0) {
                $score = max($score, min(65, $overlap * 22 + 12));
            }
        }

        // 6. Levenshtein for short single-word strings
        if (strlen($domainCompact) >= 4 && strlen($domainCompact) <= 16 &&
            strlen($nameCompact)   >= 4 && strlen($nameCompact)   <= 16) {
            $dist     = levenshtein($domainCompact, $nameCompact);
            $maxLen   = max(strlen($domainCompact), strlen($nameCompact));
            $levScore = (int)((1 - $dist / $maxLen) * 55);
            if ($levScore >= 28) $score = max($score, $levScore);
        }

        return min(100, $score);
    }

    private function cleanDomain(string $domain): string
    {
        $domain = strtolower(preg_replace('/^www\./', '', trim($domain)));
        $domain = preg_replace('/\.(com|co\.uk|org\.uk|org|net|io|church|co|uk|me|dev|app|online|biz|info|co\.nz|com\.au|co\.za)$/', '', $domain);
        $domain = str_replace('-', ' ', $domain);
        $domain = preg_replace('/[^a-z0-9\s]/', ' ', $domain);
        return trim(preg_replace('/\s+/', ' ', $domain));
    }

    private function cleanClientText(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/\b(ltd|limited|llc|inc|plc|group|digital|creative|church|company|solutions|services|agency|studio|studios|design|media|consultancy|consulting|photography|marketing|communications?|technologies?|tech)\b/', ' ', $text);
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function fetchSiteFull(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT cs.*,
                d.domain         AS domain_name,
                d.registrar,
                d.cloudflare_proxied,
                d.renewal_date   AS domain_renewal,
                d.annual_cost    AS domain_cost,
                c.id             AS client_id,
                c.name           AS client_name,
                s.id             AS server_id,
                s.name           AS server_name,
                s.provider       AS server_provider,
                ps.id            AS ploi_site_id,
                ps.domain        AS ploi_domain,
                ps.status        AS ploi_status,
                ps.project_type  AS ploi_project_type,
                ps.php_version   AS ploi_php_version,
                ps.repository    AS ploi_repository,
                ps.branch        AS ploi_branch,
                ps.has_ssl       AS ploi_has_ssl,
                ps.test_domain   AS ploi_test_domain,
                ps.web_directory AS ploi_web_directory,
                ps.is_stale      AS ploi_is_stale
            FROM client_sites cs
            LEFT JOIN domains d     ON d.id  = cs.domain_id
            LEFT JOIN clients c     ON c.id  = cs.client_id
            LEFT JOIN servers s     ON s.id  = cs.server_id
            LEFT JOIN ploi_sites ps ON ps.client_site_id = cs.id
            WHERE cs.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function ploiSiteOptions(?int $crmServerId, ?int $currentPloiSiteId): array
    {
        if (!$this->ploi->isConnected()) return [false, []];

        $ploiServerId = null;
        if ($crmServerId) {
            $stmt = $this->db->prepare("SELECT id FROM ploi_servers WHERE server_id = ? LIMIT 1");
            $stmt->execute([$crmServerId]);
            $ploiServerId = $stmt->fetchColumn() ?: null;
        }

        $sql    = "SELECT * FROM ploi_sites WHERE (client_site_id IS NULL" . ($currentPloiSiteId ? " OR id = ?" : "") . ")";
        $params = $currentPloiSiteId ? [$currentPloiSiteId] : [];
        if ($ploiServerId) { $sql .= " AND ploi_server_id = ?"; $params[] = $ploiServerId; }
        $sql .= " ORDER BY domain";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return [true, $stmt->fetchAll()];
    }

    private function savePloiSiteLink(int $clientSiteId, int $ploiSiteId): void
    {
        $this->db->prepare("UPDATE ploi_sites SET client_site_id = NULL WHERE client_site_id = ?")->execute([$clientSiteId]);
        if ($ploiSiteId > 0) {
            $this->db->prepare("UPDATE ploi_sites SET client_site_id = ? WHERE id = ?")->execute([$clientSiteId, $ploiSiteId]);
        }
    }

    private function sanitise(array $post): array
    {
        return [
            'client_id'              => ($post['client_id'] ?? '') !== '' ? (int)$post['client_id'] : null,
            'domain_id'              => ($post['domain_id'] ?? '') !== '' ? (int)$post['domain_id'] : null,
            'server_id'              => ($post['server_id'] ?? '') !== '' ? (int)$post['server_id'] : null,
            'website_stack'          => trim($post['website_stack'] ?? ''),
            'css_framework'          => trim($post['css_framework'] ?? ''),
            'smtp_service'           => trim($post['smtp_service'] ?? ''),
            'git_repo'               => trim($post['git_repo'] ?? ''),
            'has_deployment_pipeline' => isset($post['has_deployment_pipeline']) ? 1 : 0,
            'notes'                  => trim($post['notes'] ?? ''),
        ];
    }

    private function validate(array $data): array
    {
        return [];
    }

    private function siteName(array $site): string
    {
        if (!empty($site['domain_name'])) return $site['domain_name'];
        $stmt = $this->db->prepare("SELECT domain FROM domains WHERE id = ? LIMIT 1");
        $stmt->execute([$site['domain_id'] ?? 0]);
        return $stmt->fetchColumn() ?: 'Site #' . $site['id'];
    }
}
