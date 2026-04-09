<?php
declare(strict_types=1);
namespace CoyshCRM\Models;

class ExpenseCategory extends Model
{
    protected string $table = 'expense_categories';

    public function findAllWithCounts(): array
    {
        return $this->query("
            SELECT ec.*,
                   (SELECT COUNT(*) FROM expenses e WHERE e.category_id = ec.id) AS expense_count,
                   (SELECT COUNT(*) FROM recurring_costs rc WHERE rc.category_id = ec.id) AS recurring_count
            FROM expense_categories ec
            ORDER BY ec.sort_order, ec.name
        ")->fetchAll();
    }

    /** Returns ['slug' => 'Name', ...] ordered by sort_order for dropdowns */
    public function asDropdown(): array
    {
        $rows = $this->query("SELECT slug, name FROM expense_categories ORDER BY sort_order, name")->fetchAll();
        $out  = [];
        foreach ($rows as $r) $out[$r['slug']] = $r['name'];
        return $out;
    }

    /** Returns [id => 'Name', ...] ordered by sort_order for dropdowns using id as key */
    public function asDropdownById(): array
    {
        $rows = $this->query("SELECT id, name FROM expense_categories ORDER BY sort_order, name")->fetchAll();
        $out  = [];
        foreach ($rows as $r) $out[(int)$r['id']] = $r['name'];
        return $out;
    }

    public function canDelete(int $id): bool
    {
        $cat = $this->findById($id);
        if (!$cat || $cat['is_default']) return false;
        $inUse = (int)$this->query("
            SELECT (SELECT COUNT(*) FROM expenses WHERE category_id = ?)
                 + (SELECT COUNT(*) FROM recurring_costs WHERE category_id = ?)
        ", [$id, $id])->fetchColumn();
        return $inUse === 0;
    }

    public function autoSlug(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($name)));
    }
}
