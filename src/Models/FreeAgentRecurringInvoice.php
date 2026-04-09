<?php

declare(strict_types=1);

namespace CoyshCRM\Models;

class FreeAgentRecurringInvoice extends Model
{
    protected string $table = 'freeagent_recurring_invoices';

    /**
     * Convert a recurring invoice's total_value to a monthly equivalent.
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
     * SQL CASE expression that normalises total_value to a monthly figure.
     * Accepts a table alias prefix (e.g. 'fri' → 'fri.total_value', 'fri.frequency').
     */
    public static function monthlySql(string $alias = ''): string
    {
        $tv  = $alias ? "{$alias}.total_value" : 'total_value';
        $frq = $alias ? "{$alias}.frequency"   : 'frequency';
        return "CASE {$frq}
            WHEN 'Weekly'      THEN {$tv} * 52 / 12
            WHEN 'Two Weekly'  THEN {$tv} * 26 / 12
            WHEN 'Four Weekly' THEN {$tv} * 13 / 12
            WHEN 'Monthly'     THEN {$tv}
            WHEN 'Two Monthly' THEN {$tv} / 2
            WHEN 'Quarterly'   THEN {$tv} / 3
            WHEN 'Biannually'  THEN {$tv} / 6
            WHEN 'Annually'    THEN {$tv} / 12
            WHEN '2-Yearly'    THEN {$tv} / 24
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
