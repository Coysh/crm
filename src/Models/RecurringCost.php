<?php
declare(strict_types=1);
namespace CoyshCRM\Models;

class RecurringCost extends Model
{
    protected string $table = 'recurring_costs';

    public static function toMonthly(float $amount, string $cycle): float
    {
        return $cycle === 'annual' ? $amount / 12.0 : $amount;
    }

    private function hasServerIdColumn(): bool
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

    private function hasCurrencyColumn(): bool
    {
        static $checked = null;
        if ($checked !== null) return $checked;
        try {
            $this->query("SELECT currency FROM recurring_costs LIMIT 0");
            $checked = true;
        } catch (\Throwable) {
            $checked = false;
        }
        return $checked;
    }

    public function findAllWithRelations(?int $categoryId = null, ?string $status = null, ?string $search = null): array
    {
        $sql    = "SELECT rc.*, ec.name AS category_name FROM recurring_costs rc JOIN expense_categories ec ON ec.id = rc.category_id WHERE 1=1";
        $params = [];
        if ($categoryId) { $sql .= ' AND rc.category_id = ?'; $params[] = $categoryId; }
        if ($status === 'active')   { $sql .= ' AND rc.is_active = 1'; }
        if ($status === 'inactive') { $sql .= ' AND rc.is_active = 0'; }
        if ($search) { $sql .= ' AND LOWER(rc.name) LIKE ?'; $params[] = '%' . strtolower($search) . '%'; }
        $sql .= ' ORDER BY rc.is_active DESC, LOWER(rc.name)';
        $costs = $this->query($sql, $params)->fetchAll();

        $hasServerCol  = $this->hasServerIdColumn();
        $fx            = $this->hasCurrencyColumn() ? new \CoyshCRM\Services\ExchangeRateService($this->db) : null;
        foreach ($costs as &$cost) {
            $cost['monthly_equivalent'] = self::toMonthly((float)$cost['amount'], $cost['billing_cycle']);
            $currency = $cost['currency'] ?? 'GBP';
            $cost['monthly_equivalent_gbp'] = $fx
                ? $fx->convertToGBP($cost['monthly_equivalent'], $currency)
                : $cost['monthly_equivalent'];
            $serverId = $hasServerCol ? ((int)($cost['server_id'] ?? 0) ?: null) : null;
            $cost['assignments']        = $this->getAssignmentSummary((int)$cost['id'], $serverId);
        }
        return $costs;
    }

    public function findByIdWithAssignments(int $id): ?array
    {
        $cost = $this->findById($id);
        if (!$cost) return null;
        $cost['monthly_equivalent'] = self::toMonthly((float)$cost['amount'], $cost['billing_cycle']);
        $cost['client_assignments'] = $this->query("SELECT client_id FROM recurring_cost_clients WHERE recurring_cost_id = ? AND client_id IS NOT NULL", [$id])->fetchAll(\PDO::FETCH_COLUMN);
        $cost['site_assignments']   = $this->query("SELECT client_site_id FROM recurring_cost_clients WHERE recurring_cost_id = ? AND client_site_id IS NOT NULL", [$id])->fetchAll(\PDO::FETCH_COLUMN);
        if ($this->hasServerIdColumn() && !empty($cost['server_id'])) {
            $srv = $this->query("SELECT id, name FROM servers WHERE id = ? LIMIT 1", [(int)$cost['server_id']])->fetch();
            $cost['server_name'] = $srv ? $srv['name'] : null;
        }
        return $cost;
    }

    /**
     * Get assignment summary for list display.
     * Server-linked costs: derive clients dynamically from client_sites.
     * Others: use junction table.
     */
    public function getAssignmentSummary(int $id, ?int $serverId = null): array
    {
        if ($serverId) {
            $clients = $this->query("
                SELECT DISTINCT c.id, c.name
                FROM client_sites cs
                JOIN clients c ON c.id = cs.client_id
                WHERE cs.server_id = ?
                ORDER BY c.name
            ", [$serverId])->fetchAll();
            return ['clients' => $clients, 'sites' => [], 'via_server' => true];
        }

        $clients = $this->query("
            SELECT DISTINCT c.id, c.name FROM recurring_cost_clients rcc
            JOIN clients c ON c.id = rcc.client_id
            WHERE rcc.recurring_cost_id = ? AND rcc.client_id IS NOT NULL
        ", [$id])->fetchAll();

        $sites = $this->query("
            SELECT cs.id, COALESCE(d.domain, 'Site #' || cs.id) AS domain_label, c.name AS client_name
            FROM recurring_cost_clients rcc
            JOIN client_sites cs ON cs.id = rcc.client_site_id
            LEFT JOIN domains d ON d.id = cs.domain_id
            LEFT JOIN clients c ON c.id = cs.client_id
            WHERE rcc.recurring_cost_id = ? AND rcc.client_site_id IS NOT NULL
        ", [$id])->fetchAll();

        return ['clients' => $clients, 'sites' => $sites, 'via_server' => false];
    }

    public function saveAssignments(int $id, string $type, array $ids): void
    {
        $this->query("DELETE FROM recurring_cost_clients WHERE recurring_cost_id = ?", [$id]);
        if ($type === 'client') {
            foreach ($ids as $clientId) {
                $this->query("INSERT INTO recurring_cost_clients (recurring_cost_id, client_id) VALUES (?, ?)", [$id, (int)$clientId]);
            }
        } elseif ($type === 'site') {
            foreach ($ids as $siteId) {
                $this->query("INSERT INTO recurring_cost_clients (recurring_cost_id, client_site_id) VALUES (?, ?)", [$id, (int)$siteId]);
            }
        }
    }

    public function totalMonthlyActive(): float
    {
        if ($this->hasCurrencyColumn()) {
            $rows = $this->query("
                SELECT amount, billing_cycle, COALESCE(currency, 'GBP') AS currency
                FROM recurring_costs WHERE is_active = 1
            ")->fetchAll();
            $fx    = new \CoyshCRM\Services\ExchangeRateService($this->db);
            $total = 0.0;
            foreach ($rows as $r) {
                $monthly = self::toMonthly((float)$r['amount'], $r['billing_cycle']);
                $total  += $fx->convertToGBP($monthly, $r['currency']);
            }
            return $total;
        }
        return (float)$this->query("
            SELECT COALESCE(SUM(CASE billing_cycle WHEN 'monthly' THEN amount ELSE amount/12.0 END), 0)
            FROM recurring_costs WHERE is_active = 1
        ")->fetchColumn();
    }
}
