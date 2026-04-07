<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Domain extends Model
{
    protected string $table = 'domains';

    public function findByClient(int $clientId): array
    {
        return $this->query(
            "SELECT * FROM domains WHERE client_id = ? ORDER BY domain",
            [$clientId]
        )->fetchAll();
    }
}
