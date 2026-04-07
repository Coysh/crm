<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class ClientSite extends Model
{
    protected string $table = 'client_sites';

    public function findByClient(int $clientId): array
    {
        return $this->query("
            SELECT cs.*, d.domain AS domain_name, s.name AS server_name
            FROM client_sites cs
            LEFT JOIN domains d ON d.id = cs.domain_id
            LEFT JOIN servers s ON s.id = cs.server_id
            WHERE cs.client_id = ?
            ORDER BY d.domain
        ", [$clientId])->fetchAll();
    }
}
