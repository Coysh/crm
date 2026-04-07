<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Project extends Model
{
    protected string $table = 'projects';

    public function findAllWithClient(?int $clientId = null, ?string $status = null): array
    {
        $sql = "
            SELECT p.*, c.name AS client_name
            FROM projects p
            JOIN clients c ON c.id = p.client_id
            WHERE 1=1
        ";
        $params = [];

        if ($clientId) {
            $sql .= ' AND p.client_id = ?';
            $params[] = $clientId;
        }
        if ($status) {
            $sql .= ' AND p.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY p.created_at DESC';
        return $this->query($sql, $params)->fetchAll();
    }

    public static function incomeCategories(): array
    {
        return [
            'web_design'      => 'Web Design',
            'web_development' => 'Web Development',
            'consultancy'     => 'Consultancy',
            'hosting'         => 'Hosting',
            'email_hosting'   => 'Email Hosting',
            'domain'          => 'Domain',
        ];
    }

    public static function statuses(): array
    {
        return ['active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    }
}
