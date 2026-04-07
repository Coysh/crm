<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Server extends Model
{
    protected string $table = 'servers';

    public function findAllWithStats(): array
    {
        return $this->query("
            SELECT
                s.*,
                COUNT(DISTINCT cs.client_id) AS client_count,
                COUNT(cs.id) AS site_count,
                CASE
                    WHEN COUNT(DISTINCT cs.client_id) > 0
                    THEN ROUND(s.monthly_cost / COUNT(DISTINCT cs.client_id), 2)
                    ELSE s.monthly_cost
                END AS cost_per_client
            FROM servers s
            LEFT JOIN client_sites cs ON cs.server_id = s.id
            GROUP BY s.id
            ORDER BY s.name
        ")->fetchAll();
    }
}
