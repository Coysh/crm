<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use CoyshCRM\Controllers\DataQualityController;
use CoyshCRM\Models\Agreement;
use CoyshCRM\Models\Client;
use PDO;

/**
 * The "what needs me today" list: one prioritised feed built from the
 * sources that were previously spread across the dashboard, /renewals,
 * /agreements, /freeagent, Data Quality and the sync pages.
 *
 * Every item carries a stable `key` naming one incident (a renewal *and its
 * due date*, a site outage *and when it started*), so snoozing or dismissing
 * it never hides the next occurrence. Snoozes live in attention_snoozes.
 *
 * Severity: high = act now (drives the nav badge along with medium),
 * medium = this week, low = chronic housekeeping (health flags, data gaps).
 *
 * @phpstan-type Item array{key:string, severity:string, kind:string, title:string, detail:string,
 *                          url:string, client_id:?int, client_name:?string, action:?array, sort:string,
 *                          snoozed_until?:?string, snoozed?:bool}
 */
class Attention
{
    public const SEVERITIES = ['high' => 0, 'medium' => 1, 'low' => 2];

    /** Chronic health flags surfaced as low-priority items (the rest have dedicated sources). */
    private const HEALTH_FLAGS = [
        'loss_making'       => '#pl',
        'no_retainer'       => '#income',
        'no_recent_invoice' => '#freeagent',
        'incomplete_setup'  => '#sites',
        'no_agreement'      => '#agreements',
        'site_unmonitored'  => '#sites',
    ];

    private const BADGE_CACHE_TTL = 60;

    public function __construct(private PDO $db) {}

    /**
     * @param bool $includeLow     include chronic low-severity items (costlier: runs the P&L)
     * @param bool $includeSnoozed include snoozed/dismissed items, flagged `snoozed`
     * @return list<array>
     */
    public function items(bool $includeLow = true, bool $includeSnoozed = false): array
    {
        $sources = ['sitesDown', 'jobs', 'overdueInvoices', 'renewals', 'hours'];
        if ($includeLow) array_push($sources, 'healthFlags', 'dataQuality');

        $items = [];
        foreach ($sources as $source) {
            try {
                array_push($items, ...$this->$source());
            } catch (\Throwable) {
                // A source whose tables aren't migrated yet simply contributes nothing.
            }
        }

        $snoozes = $this->snoozes();
        $today   = date('Y-m-d');
        $out     = [];
        foreach ($items as $item) {
            $snoozed = array_key_exists($item['key'], $snoozes)
                && ($snoozes[$item['key']] === null || $snoozes[$item['key']] > $today);
            if ($snoozed && !$includeSnoozed) continue;
            $item['snoozed']       = $snoozed;
            $item['snoozed_until'] = $snoozed ? $snoozes[$item['key']] : null;
            $out[] = $item;
        }

        usort($out, fn($a, $b) =>
            [self::SEVERITIES[$a['severity']], $a['sort'], $a['title']]
            <=> [self::SEVERITIES[$b['severity']], $b['sort'], $b['title']]);

        return $out;
    }

    /** High + medium count for the nav badge, cached briefly in the session. */
    public function badgeCount(): int
    {
        $cache = $_SESSION['_attention_badge'] ?? null;
        if (is_array($cache) && $cache['at'] > time() - self::BADGE_CACHE_TTL) {
            return (int)$cache['count'];
        }
        $count = count($this->items(false));
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['_attention_badge'] = ['count' => $count, 'at' => time()];
        }
        return $count;
    }

    public static function forgetBadge(): void
    {
        unset($_SESSION['_attention_badge']);
    }

    /** @param int|null $days null = dismiss indefinitely */
    public function snooze(string $key, ?int $days): void
    {
        $until = $days === null ? null : date('Y-m-d', strtotime("+{$days} days"));
        $this->db->prepare(
            "INSERT INTO attention_snoozes (item_key, snoozed_until) VALUES (?, ?)
             ON CONFLICT(item_key) DO UPDATE SET snoozed_until = excluded.snoozed_until, created_at = datetime('now')"
        )->execute([$key, $until]);
        // Expired snoozes are just noise once their date has passed.
        $this->db->exec("DELETE FROM attention_snoozes WHERE snoozed_until IS NOT NULL AND snoozed_until < date('now', '-30 days')");
        self::forgetBadge();
    }

    public function unsnooze(string $key): void
    {
        $this->db->prepare("DELETE FROM attention_snoozes WHERE item_key = ?")->execute([$key]);
        self::forgetBadge();
    }

    // ── Sources ─────────────────────────────────────────────────────────────

    private function sitesDown(): array
    {
        if (!(new Client($this->db))->uptimeMonitoringActive()) return [];

        $rows = $this->db->query("
            SELECT m.monitor_name, m.status_changed_at, cs.id AS site_id, d.domain,
                   c.id AS client_id, c.name AS client_name
            FROM uptime_kuma_monitors m
            JOIN client_sites cs ON cs.id = m.client_site_id
            LEFT JOIN domains d  ON d.id = cs.domain_id
            LEFT JOIN clients c  ON c.id = cs.client_id
            WHERE m.is_stale = 0 AND m.status = 0 AND COALESCE(m.active, 1) = 1
              AND COALESCE(cs.status, 'active') = 'active'
        ")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($r) => $this->item(
            key: "site_down:{$r['site_id']}:{$r['status_changed_at']}",
            severity: 'high',
            kind: 'Site down',
            title: $r['domain'] ?: $r['monitor_name'],
            detail: $r['status_changed_at'] ? 'Down for ' . formatDurationSince($r['status_changed_at']) : 'Down',
            url: "/sites/{$r['site_id']}",
            row: $r,
            sort: (string)$r['status_changed_at'],
        ), $rows);
    }

    private function jobs(): array
    {
        $runner = new JobRunner($this->db);
        if (!$runner->isInstalled()) {
            return [$this->item(
                key: 'cron:not_installed',
                severity: 'medium',
                kind: 'Scheduled jobs',
                title: 'Scheduled jobs aren\'t running through cron.php',
                detail: 'Syncs, backups and the daily digest need the single cron line — see Settings.',
                url: '/settings#jobs',
            )];
        }

        $items = [];
        foreach ($runner->status() as $name => $job) {
            if ($job['state'] === 'failed') {
                $lines = array_filter(array_map('trim', explode("\n", (string)($job['last_run']['output'] ?? ''))));
                $items[] = $this->item(
                    key: "job:{$name}:failed",
                    severity: 'high',
                    kind: 'Job failed',
                    title: "{$job['label']} failed",
                    detail: mb_strimwidth((string)end($lines), 0, 140, '…'),
                    url: $job['url'],
                );
            } elseif ($job['state'] === 'stale') {
                $items[] = $this->item(
                    key: "job:{$name}:stale",
                    severity: 'high',
                    kind: 'Job stale',
                    title: "{$job['label']} hasn't succeeded recently",
                    detail: $job['last_ok_at'] ? 'Last success ' . formatDurationSince($job['last_ok_at']) . ' ago' : 'No successful run recorded',
                    url: '/settings#jobs',
                );
            }
        }
        return $items;
    }

    private function overdueInvoices(): array
    {
        $rows = $this->db->query("
            SELECT fi.id, fi.reference, fi.total_value, COALESCE(fi.currency, 'GBP') AS currency,
                   COALESCE(fi.source, 'freeagent') AS source,
                   COALESCE(fi.due_date, date(fi.dated_on, '+30 days')) AS due,
                   c.id AS client_id, c.name AS client_name
            FROM freeagent_invoices fi
            LEFT JOIN clients c ON c.id = fi.client_id
            WHERE COALESCE(fi.status_override, fi.status) = 'overdue'
               OR (COALESCE(fi.status_override, fi.status) = 'sent'
                   AND COALESCE(fi.due_date, date(fi.dated_on, '+30 days')) < date('now'))
        ")->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            $days = max(0, (int)floor((time() - strtotime($r['due'])) / 86400));
            // Imported invoices (e.g. Hiveage) are never re-synced, so "sent" there usually
            // means "paid but never updated" — housekeeping, fixed with a status override.
            $legacy = $r['source'] !== 'freeagent';
            return $this->item(
                key: "invoice_overdue:{$r['id']}",
                severity: $legacy ? 'low' : ($days > 14 ? 'high' : 'medium'),
                kind: $legacy ? 'Imported invoice unpaid?' : 'Overdue invoice',
                title: ($r['reference'] ?: 'Invoice') . ' — ' . formatCurrency($r['total_value'], $r['currency']),
                detail: $legacy
                    ? "Imported from {$r['source']}, still marked unpaid — set a status override if it was paid"
                    : ($days === 0 ? 'Due today' : "{$days} day" . ($days === 1 ? '' : 's') . ' overdue'),
                url: $r['client_id'] ? "/clients/{$r['client_id']}#freeagent" : '/freeagent',
                row: $r,
                sort: (string)$r['due'],
            );
        }, $rows);
    }

    private function renewals(): array
    {
        $items = [];
        foreach ((new Renewals($this->db))->fetch(14) as $r) {
            if (!in_array($r['type'], Renewals::RENEWABLE, true)) continue; // FreeAgent raises recurring invoices itself
            $label = match ($r['type']) { 'domain' => 'Domain renewal', 'agreement' => 'Agreement renewal', default => 'Cost renewal' };
            $items[] = $this->item(
                key: "renewal:{$r['type']}:{$r['item_id']}:{$r['due_date']}",
                severity: $r['days_diff'] < 0 ? 'high' : ($r['days_diff'] <= 7 ? 'medium' : 'low'),
                kind: $label,
                title: $r['name'],
                detail: ($r['days_diff'] < 0 ? 'Overdue since ' : 'Due ') . formatDate($r['due_date'])
                    . ($r['amount'] !== null ? ' · ' . money($r['amount']) : ''),
                url: $r['detail_url'],
                row: $r,
                sort: $r['due_date'],
                action: ['type' => 'renew', 'renewal_type' => $r['type'], 'id' => (int)$r['item_id']],
            );
        }
        return $items;
    }

    private function hours(): array
    {
        $items = [];
        foreach ((new Agreement($this->db))->findAllWithClient('active') as $a) {
            if (empty($a['included_hours'])) continue;
            $used = (float)$a['hours_used'];
            $pct  = $used / (float)$a['included_hours'];
            if ($pct < 0.8) continue;
            $exhausted = $a['hours_remaining'] <= 0;
            $items[] = $this->item(
                key: "hours:{$a['id']}:{$a['period_start']}:" . ($exhausted ? 'exhausted' : 'high'),
                severity: $exhausted ? 'medium' : 'low',
                kind: $exhausted ? 'SLA hours used up' : 'SLA hours running low',
                title: $a['title'],
                detail: rtrim(rtrim(number_format($used, 2), '0'), '.') . ' of '
                    . rtrim(rtrim(number_format((float)$a['included_hours'], 2), '0'), '.') . ' h used this period',
                url: "/clients/{$a['client_id']}#agreements",
                row: $a,
            );
        }
        return $items;
    }

    private function healthFlags(): array
    {
        $model  = new Client($this->db);
        $health = $model->getHealthAll($model->getPLAll());
        $names  = $this->db->query("SELECT id, name FROM clients WHERE status = 'active'")->fetchAll(PDO::FETCH_KEY_PAIR);

        $items = [];
        foreach ($health as $cid => $h) {
            foreach ($h['flags'] as $flag) {
                if (!isset(self::HEALTH_FLAGS[$flag])) continue;
                $items[] = $this->item(
                    key: "health:{$flag}:{$cid}",
                    severity: 'low',
                    kind: healthFlagLabel($flag),
                    title: (string)($names[$cid] ?? "Client #{$cid}"),
                    detail: '',
                    url: "/clients/{$cid}" . self::HEALTH_FLAGS[$flag],
                    row: ['client_id' => $cid, 'client_name' => null],
                );
            }
        }
        return $items;
    }

    private function dataQuality(): array
    {
        $count = DataQualityController::issueCount($this->db);
        if ($count === 0) return [];
        return [$this->item(
            key: 'data_quality',
            severity: 'low',
            kind: 'Data quality',
            title: "{$count} record" . ($count === 1 ? '' : 's') . ' need tidying',
            detail: 'Missing links and incomplete records that skew P&L or hide renewals',
            url: '/settings/data-quality',
        )];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function item(string $key, string $severity, string $kind, string $title, string $detail, string $url,
                          array $row = [], string $sort = '', ?array $action = null): array
    {
        return [
            'key'         => $key,
            'severity'    => $severity,
            'kind'        => $kind,
            'title'       => $title,
            'detail'      => $detail,
            'url'         => $url,
            'client_id'   => isset($row['client_id']) ? (int)$row['client_id'] : null,
            'client_name' => $row['client_name'] ?? null,
            'action'      => $action,
            'sort'        => $sort,
        ];
    }

    /** @return array<string, ?string> item_key => snoozed_until */
    private function snoozes(): array
    {
        try {
            return $this->db->query("SELECT item_key, snoozed_until FROM attention_snoozes")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable) {
            return [];
        }
    }
}
