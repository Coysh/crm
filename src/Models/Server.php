<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Server extends Model
{
    protected string $table = 'servers';

    public function findAllWithStats(): array
    {
        return $this->query("SELECT s.*, COUNT(DISTINCT cs.client_id) AS client_count, COUNT(cs.id) AS site_count, CASE WHEN COUNT(DISTINCT cs.client_id) > 0 THEN ROUND(s.monthly_cost / COUNT(DISTINCT cs.client_id), 2) ELSE s.monthly_cost END AS cost_per_client FROM servers s LEFT JOIN client_sites cs ON cs.server_id = s.id GROUP BY s.id ORDER BY s.name")->fetchAll();
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
