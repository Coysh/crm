<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\Search;
use PDO;

/**
 * GET /search?q= — JSON for the global search box in the sidebar
 * (public/js/search.js).
 */
class SearchController
{
    public function __construct(private PDO $db) {}

    public function search(): void
    {
        header('Content-Type: application/json');
        echo json_encode(['results' => (new Search($this->db))->find((string)($_GET['q'] ?? ''))]);
        exit;
    }
}
