<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Expense extends Model
{
    protected string $table = 'expenses';

    public function findAllWithRelations(?string $category = null, ?int $clientId = null, ?int $serverId = null): array
    {
        $sql = "
            SELECT e.*,
                   c.name AS client_name,
                   s.name AS server_name,
                   p.name AS project_name
            FROM expenses e
            LEFT JOIN clients c ON c.id = e.client_id
            LEFT JOIN servers s ON s.id = e.server_id
            LEFT JOIN projects p ON p.id = e.project_id
            WHERE 1=1
        ";
        $params = [];

        if ($category) {
            $sql .= ' AND e.category = ?';
            $params[] = $category;
        }
        if ($clientId) {
            $sql .= ' AND e.client_id = ?';
            $params[] = $clientId;
        }
        if ($serverId) {
            $sql .= ' AND e.server_id = ?';
            $params[] = $serverId;
        }

        $sql .= ' ORDER BY e.date DESC, e.created_at DESC';
        return $this->query($sql, $params)->fetchAll();
    }

    public static function categories(): array
    {
        return [
            'domain_registration' => 'Domain Registration',
            'email_hosting'       => 'Email Hosting',
            'hosting_costs'       => 'Hosting Costs',
            'plugin_licenses'     => 'Plugin Licenses',
        ];
    }

    public static function billingCycles(): array
    {
        return ['one_off' => 'One-off', 'monthly' => 'Monthly', 'annual' => 'Annual'];
    }
}
