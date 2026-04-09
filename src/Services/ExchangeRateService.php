<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;

class ExchangeRateService
{
    private const API_BASE     = 'https://api.frankfurter.app';
    private const SUPPORTED    = ['USD', 'EUR'];
    private const SYMBOLS      = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];

    /** In-process cache so we only hit the DB once per request. */
    private array $cache = [];

    public function __construct(private PDO $db) {}

    /**
     * Get today's GBP→target rate (units of target per 1 GBP).
     * Returns 1.0 for GBP. Falls back to most recent cached rate if API is unavailable.
     */
    public function getLatestRate(string $currency): float
    {
        if ($currency === 'GBP') return 1.0;
        if (!in_array($currency, self::SUPPORTED, true)) return 1.0;

        if (isset($this->cache[$currency])) {
            return $this->cache[$currency];
        }

        $today = date('Y-m-d');

        // Try DB cache for today
        try {
            $stmt = $this->db->prepare(
                "SELECT rate FROM exchange_rates WHERE date = ? AND base_currency = 'GBP' AND target_currency = ? LIMIT 1"
            );
            $stmt->execute([$today, $currency]);
            if ($rate = $stmt->fetchColumn()) {
                return $this->cache[$currency] = (float)$rate;
            }
        } catch (\Throwable) {
            // Table may not exist yet — fall through
        }

        // Fetch from Frankfurter API
        $fetched = $this->fetchFromApi();
        if (!empty($fetched[$currency])) {
            return $this->cache[$currency] = $fetched[$currency];
        }

        // Fall back to most recent cached rate
        try {
            $stmt = $this->db->prepare(
                "SELECT rate FROM exchange_rates WHERE base_currency = 'GBP' AND target_currency = ? ORDER BY date DESC LIMIT 1"
            );
            $stmt->execute([$currency]);
            if ($rate = $stmt->fetchColumn()) {
                return $this->cache[$currency] = (float)$rate;
            }
        } catch (\Throwable) {}

        // No data at all — return 1 (neutral, won't convert)
        return 1.0;
    }

    /**
     * Convert an amount in the given currency to GBP.
     */
    public function convertToGBP(float $amount, string $currency): float
    {
        if ($currency === 'GBP') return $amount;
        $rate = $this->getLatestRate($currency);
        return $rate > 0 ? $amount / $rate : $amount;
    }

    /**
     * Return all current GBP-base rates for display (e.g. settings page).
     * Returns [ ['currency' => 'USD', 'rate' => 1.27, 'date' => '2024-01-15'], ... ]
     */
    public function getCurrentRates(): array
    {
        try {
            $rows = $this->db->query("
                SELECT target_currency AS currency, rate, date
                FROM exchange_rates
                WHERE base_currency = 'GBP'
                  AND date = (SELECT MAX(date) FROM exchange_rates WHERE base_currency = 'GBP')
                ORDER BY target_currency
            ")->fetchAll();
            return $rows ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Get the date of the most recently cached rates (or null).
     */
    public function getLastCachedDate(): ?string
    {
        try {
            $date = $this->db->query(
                "SELECT MAX(date) FROM exchange_rates WHERE base_currency = 'GBP'"
            )->fetchColumn();
            return $date ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetch rates from Frankfurter API, store in DB, return rate map.
     * Returns ['USD' => 1.27, 'EUR' => 1.16] or [] on failure.
     */
    public function fetchFromApi(): array
    {
        $url = self::API_BASE . '/latest?from=GBP&to=' . implode(',', self::SUPPORTED);
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);

        try {
            $json = @file_get_contents($url, false, $ctx);
            if (!$json) return [];
            $data = json_decode($json, true);
            if (empty($data['rates']) || empty($data['date'])) return [];

            $date  = $data['date'];
            $rates = $data['rates'];

            // Store in DB
            $stmt = $this->db->prepare("
                INSERT INTO exchange_rates (date, base_currency, target_currency, rate, created_at)
                VALUES (?, 'GBP', ?, ?, datetime('now'))
                ON CONFLICT(date, base_currency, target_currency) DO UPDATE SET rate = excluded.rate
            ");
            foreach ($rates as $cur => $rate) {
                $stmt->execute([$date, $cur, $rate]);
                $this->cache[$cur] = (float)$rate;
            }

            return $rates;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Symbol for a currency code.
     */
    public static function symbol(string $currency): string
    {
        return self::SYMBOLS[$currency] ?? $currency;
    }
}
