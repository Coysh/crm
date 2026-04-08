<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class Client extends Model
{
    protected string $table = 'clients';

    public function findAllWithStats(?string $status = null): array
    {
        $sql = "SELECT c.*, COUNT(DISTINCT cs.id) AS site_count, COALESCE(SUM(CASE sp.billing_cycle WHEN 'monthly' THEN sp.fee WHEN 'annual' THEN sp.fee / 12 ELSE 0 END), 0) AS mrr FROM clients c LEFT JOIN client_sites cs ON cs.client_id = c.id LEFT JOIN service_packages sp ON sp.client_id = c.id AND sp.is_active = 1";
        $params = [];
        if ($status) { $sql .= ' WHERE c.status = ?'; $params[] = $status; }
        $sql .= ' GROUP BY c.id ORDER BY c.name';
        return $this->query($sql, $params)->fetchAll();
    }

    public function getMRR(int $id): float { $row = $this->query("SELECT COALESCE(SUM(CASE billing_cycle WHEN 'monthly' THEN fee WHEN 'annual' THEN fee / 12 ELSE 0 END), 0) AS mrr FROM service_packages WHERE client_id = ? AND is_active = 1", [$id])->fetch(); return (float)$row['mrr']; }
    public function getApportionedServerCost(int $clientId): float { $rows = $this->query("SELECT s.monthly_cost, (SELECT COUNT(DISTINCT cs2.client_id) FROM client_sites cs2 WHERE cs2.server_id = s.id) AS client_count FROM client_sites cs JOIN servers s ON s.id = cs.server_id WHERE cs.client_id = ? GROUP BY s.id", [$clientId])->fetchAll(); $t=0.0; foreach($rows as $r){$t += (float)$r['monthly_cost']/max(1,(int)$r['client_count']);} return $t; }
    public function getMonthlyDomainCost(int $clientId): float { $row = $this->query("SELECT COALESCE(SUM(annual_cost / 12), 0) AS monthly_domain_cost FROM domains WHERE client_id = ?", [$clientId])->fetch(); return (float)$row['monthly_domain_cost']; }
    public function getMonthlyExpenses(int $clientId): float { $row = $this->query("SELECT COALESCE(SUM(CASE billing_cycle WHEN 'monthly' THEN amount WHEN 'annual' THEN amount / 12 WHEN 'one_off' THEN 0 ELSE amount END), 0) AS monthly FROM expenses WHERE client_id = ?", [$clientId])->fetch(); return (float)$row['monthly']; }
    public function getPL(int $clientId): array { $mrr=$this->getMRR($clientId); $serverCost=$this->getApportionedServerCost($clientId); $domainCost=$this->getMonthlyDomainCost($clientId); $directExpenses=$this->getMonthlyExpenses($clientId); $totalCosts=$serverCost+$domainCost+$directExpenses; $profit=$mrr-$totalCosts; $margin=$mrr>0?($profit/$mrr)*100:0; return compact('mrr','serverCost','domainCost','directExpenses','totalCosts','profit','margin'); }

    public function findWithFullDetails(int $id): ?array
    {
        $client = $this->findById($id); if (!$client) return null;
        $client['domains'] = $this->query("SELECT * FROM domains WHERE client_id = ? ORDER BY domain", [$id])->fetchAll();
        $client['sites'] = $this->query("SELECT cs.*, d.domain AS domain_name, s.name AS server_name, ps.domain AS ploi_domain, ps.project_type AS ploi_project_type, ps.php_version AS ploi_php_version, ps.repository AS ploi_repository, ps.branch AS ploi_branch, ps.has_ssl AS ploi_has_ssl, ps.web_directory AS ploi_web_directory, ps.test_domain AS ploi_test_domain, ps.status AS ploi_status, ps.is_stale AS ploi_is_stale FROM client_sites cs LEFT JOIN domains d ON d.id = cs.domain_id LEFT JOIN servers s ON s.id = cs.server_id LEFT JOIN ploi_sites ps ON ps.client_site_id = cs.id WHERE cs.client_id = ? ORDER BY d.domain", [$id])->fetchAll();
        $client['packages'] = $this->query("SELECT * FROM service_packages WHERE client_id = ? ORDER BY is_active DESC, name", [$id])->fetchAll();
        $client['projects'] = $this->query("SELECT * FROM projects WHERE client_id = ? ORDER BY created_at DESC", [$id])->fetchAll();
        $client['expenses'] = $this->query("SELECT e.*, s.name AS server_name, p.name AS project_name FROM expenses e LEFT JOIN servers s ON s.id = e.server_id LEFT JOIN projects p ON p.id = e.project_id WHERE e.client_id = ? ORDER BY e.date DESC", [$id])->fetchAll();
        $client['attachments'] = $this->query("SELECT * FROM client_attachments WHERE client_id = ? ORDER BY uploaded_at DESC", [$id])->fetchAll();
        $client['pl'] = $this->getPL($id);
        return $client;
    }
}
