<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class ServicePackage extends Model
{
    protected string $table = 'service_packages';

    public function findByClient(int $clientId): array
    {
        return $this->query(
            "SELECT * FROM service_packages WHERE client_id = ? ORDER BY is_active DESC, name",
            [$clientId]
        )->fetchAll();
    }

    public function getMonthlyCost(array $package): float
    {
        return $package['billing_cycle'] === 'annual'
            ? (float)$package['fee'] / 12
            : (float)$package['fee'];
    }
}
