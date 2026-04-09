<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Server extends Model
{
    protected string $table = 'servers';

    public function findAllWithStats(): array
    {
        $hasServerCol = $this->hasRecurringServerIdColumn();
        if ($hasServerCol) {
            return $this->query("
                SELECT s.*, ps.os_name, ps.os_version,
                       COUNT(DISTINCT cs.client_id) AS client_count,
                       COUNT(cs.id) AS site_count,
                       rc.id   AS recurring_cost_id,
                       rc.name AS recurring_cost_name,
                       COALESCE(CASE WHEN rc.billing_cycle = 'annual' THEN rc.amount / 12.0 ELSE rc.amount END, 0) AS monthly_cost,
                       CASE WHEN COUNT(DISTINCT cs.client_id) > 0
                            THEN ROUND(COALESCE(CASE WHEN rc.billing_cycle = 'annual' THEN rc.amount / 12.0 ELSE rc.amount END, 0) / COUNT(DISTINCT cs.client_id), 2)
                            ELSE COALESCE(CASE WHEN rc.billing_cycle = 'annual' THEN rc.amount / 12.0 ELSE rc.amount END, 0) END AS cost_per_client
                FROM servers s
                LEFT JOIN client_sites cs ON cs.server_id = s.id
                LEFT JOIN ploi_servers ps ON ps.server_id = s.id
                LEFT JOIN recurring_costs rc ON rc.server_id = s.id AND rc.is_active = 1
                GROUP BY s.id
                ORDER BY s.name
            ")->fetchAll();
        }
        // Pre-migration 011 fallback
        return $this->query("
            SELECT s.*, ps.os_name, ps.os_version,
                   COUNT(DISTINCT cs.client_id) AS client_count,
                   COUNT(cs.id) AS site_count,
                   NULL AS recurring_cost_id,
                   NULL AS recurring_cost_name,
                   s.monthly_cost,
                   CASE WHEN COUNT(DISTINCT cs.client_id) > 0
                        THEN ROUND(s.monthly_cost / COUNT(DISTINCT cs.client_id), 2)
                        ELSE s.monthly_cost END AS cost_per_client
            FROM servers s
            LEFT JOIN client_sites cs ON cs.server_id = s.id
            LEFT JOIN ploi_servers ps ON ps.server_id = s.id
            GROUP BY s.id
            ORDER BY s.name
        ")->fetchAll();
    }

    /** Returns the active recurring cost linked to a server (if any). */
    public function getLinkedRecurringCost(int $serverId): ?array
    {
        if (!$this->hasRecurringServerIdColumn()) return null;
        $row = $this->query("
            SELECT rc.*, ec.name AS category_name
            FROM recurring_costs rc
            JOIN expense_categories ec ON ec.id = rc.category_id
            WHERE rc.server_id = ? AND rc.is_active = 1
            LIMIT 1
        ", [$serverId])->fetch();
        return $row ?: null;
    }

    private function hasRecurringServerIdColumn(): bool
    {
        static $checked = null;
        if ($checked !== null) return $checked;
        try {
            $this->query("SELECT server_id FROM recurring_costs LIMIT 0");
            $checked = true;
        } catch (\Throwable) {
            $checked = false;
        }
        return $checked;
    }

    public function availablePloiServers(int $serverId): array
    {
        $stmt = $this->query("SELECT * FROM ploi_servers WHERE server_id IS NULL OR server_id = ? ORDER BY name", [$serverId]);
        return $stmt->fetchAll();
    }

    public function findPloiLinkInfo(int $serverId): ?array
    {
        $row = $this->query("SELECT ps.*, (SELECT COUNT(*) FROM ploi_sites s WHERE s.ploi_server_id = ps.id) AS site_count FROM ploi_servers ps WHERE ps.server_id = ? LIMIT 1", [$serverId])->fetch();
        if (!$row) return null;
        $row['sites'] = $this->query("SELECT domain, project_type FROM ploi_sites WHERE ploi_server_id = ? ORDER BY domain", [$row['id']])->fetchAll();
        return $row;
    }
}
