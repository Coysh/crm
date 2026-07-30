<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class FreeAgentRecurringInvoice extends Model
{
    protected string $table = 'freeagent_recurring_invoices';

    /**
     * Convert a recurring invoice value to a monthly equivalent.
     * Pass the net value for revenue figures (see monthlySql()).
     */
    public static function toMonthly(float $totalValue, string $frequency): float
    {
        return match($frequency) {
            'Weekly'       => $totalValue * 52 / 12,
            'Two Weekly'   => $totalValue * 26 / 12,
            'Four Weekly'  => $totalValue * 13 / 12,
            'Monthly'      => $totalValue,
            'Two Monthly'  => $totalValue / 2,
            'Quarterly'    => $totalValue / 3,
            'Biannually'   => $totalValue / 6,
            'Annually'     => $totalValue / 12,
            '2-Yearly'     => $totalValue / 24,
            default        => $totalValue,
        };
    }

    public static function toAnnual(float $totalValue, string $frequency): float
    {
        return self::toMonthly($totalValue, $frequency) * 12;
    }

    /**
     * SQL CASE expression that normalises the invoice value to a monthly figure.
     * Uses the net (ex-VAT) value for revenue, falling back to total_value for
     * legacy rows synced before net_value existed.
     * Accepts a table alias prefix (e.g. 'fri' → 'fri.net_value', 'fri.frequency').
     */
    public static function monthlySql(string $alias = ''): string
    {
        $p   = $alias ? "{$alias}." : '';
        $tv  = "COALESCE({$p}net_value, {$p}total_value)";
        $frq = "{$p}frequency";
        // Divisors are floats: SQLite gives these columns integer affinity, so a
        // whole-pound value like 850 would otherwise hit integer division and
        // truncate (850 / 12 = 70, not 70.83), silently understating MRR.
        return "CASE {$frq}
            WHEN 'Weekly'      THEN {$tv} * 52 / 12.0
            WHEN 'Two Weekly'  THEN {$tv} * 26 / 12.0
            WHEN 'Four Weekly' THEN {$tv} * 13 / 12.0
            WHEN 'Monthly'     THEN {$tv}
            WHEN 'Two Monthly' THEN {$tv} / 2.0
            WHEN 'Quarterly'   THEN {$tv} / 3.0
            WHEN 'Biannually'  THEN {$tv} / 6.0
            WHEN 'Annually'    THEN {$tv} / 12.0
            WHEN '2-Yearly'    THEN {$tv} / 24.0
            ELSE {$tv}
        END";
    }

    public function findByClient(int $clientId): array
    {
        return $this->query(
            "SELECT * FROM freeagent_recurring_invoices
             WHERE client_id = ?
             ORDER BY CASE recurring_status WHEN 'Active' THEN 0 ELSE 1 END, reference",
            [$clientId]
        )->fetchAll();
    }
}
