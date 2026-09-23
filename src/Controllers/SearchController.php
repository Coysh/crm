<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use PDO;

/**
 * GET /search?q= — JSON for the global search box in the sidebar
 * (public/js/search.js). A handful of matches per record type.
 */
class SearchController
{
    private const PER_TYPE = 6;

    public function __construct(private PDO $db) {}

    public function search(): void
    {
        header('Content-Type: application/json');
        $q = trim((string)($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            echo json_encode(['results' => []]);
            exit;
        }
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $queries = [
            'Client' => "SELECT id, name AS title, COALESCE(contact_name, contact_email, '') AS sub,
                                '/clients/' || id AS url, status
                         FROM clients
                         WHERE name LIKE :q ESCAPE '\\' OR contact_name LIKE :q ESCAPE '\\' OR contact_email LIKE :q ESCAPE '\\'
                         ORDER BY status = 'archived', name",
            'Domain' => "SELECT d.id, d.domain AS title, COALESCE(c.name, 'No client') AS sub,
                                '/domains/' || d.id AS url, COALESCE(d.status, 'active') AS status
                         FROM domains d LEFT JOIN clients c ON c.id = d.client_id
                         WHERE d.domain LIKE :q ESCAPE '\\'
                         ORDER BY d.status = 'archived', d.domain",
            'Site'   => "SELECT cs.id, COALESCE(d.domain, 'Site #' || cs.id) AS title,
                                COALESCE(c.name, 'No client') || COALESCE(' · ' || cs.website_stack, '') AS sub,
                                '/sites/' || cs.id AS url, COALESCE(cs.status, 'active') AS status
                         FROM client_sites cs
                         LEFT JOIN domains d ON d.id = cs.domain_id
                         LEFT JOIN clients c ON c.id = cs.client_id
                         WHERE d.domain LIKE :q ESCAPE '\\' OR cs.website_stack LIKE :q ESCAPE '\\'
                         ORDER BY cs.status = 'archived', d.domain",
            'Project' => "SELECT p.id, p.name AS title, COALESCE(c.name, '') AS sub,
                                 '/projects/' || p.id || '/edit' AS url, p.status
                          FROM projects p LEFT JOIN clients c ON c.id = p.client_id
                          WHERE p.name LIKE :q ESCAPE '\\'
                          ORDER BY p.status != 'active', p.name",
            'Agreement' => "SELECT a.id, a.title, c.name AS sub, '/clients/' || c.id || '#agreements' AS url, a.status
                            FROM agreements a JOIN clients c ON c.id = a.client_id
                            WHERE a.title LIKE :q ESCAPE '\\' OR c.name LIKE :q ESCAPE '\\'
                            ORDER BY a.status != 'active', a.title",
        ];

        $results = [];
        foreach ($queries as $type => $sql) {
            try {
                $stmt = $this->db->prepare($sql . ' LIMIT ' . self::PER_TYPE);
                $stmt->execute([':q' => $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $results[] = [
                        'type'     => $type,
                        'title'    => $r['title'],
                        'sub'      => $r['sub'],
                        'url'      => $r['url'],
                        'inactive' => in_array($r['status'], ['archived', 'completed', 'cancelled', 'expired'], true),
                    ];
                }
            } catch (\Throwable) {
                // Table not migrated yet — skip this type.
            }
        }

        echo json_encode(['results' => $results]);
        exit;
    }
}
