<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;

/**
 * Cross-record search used by the sidebar box (SearchController) and the
 * MCP `search` tool. A few matches per type, active records first.
 */
class Search
{
    public const PER_TYPE = 6;

    public function __construct(private PDO $db) {}

    /** @return list<array{type:string, id:int, title:string, sub:string, url:string, inactive:bool}> */
    public function find(string $q, int $perType = self::PER_TYPE): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
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
                $stmt = $this->db->prepare($sql . ' LIMIT ' . $perType);
                $stmt->execute([':q' => $like]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $results[] = [
                        'type'     => $type,
                        'id'       => (int)$r['id'],
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

        return $results;
    }
}
