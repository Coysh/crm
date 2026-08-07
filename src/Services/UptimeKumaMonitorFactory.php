<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;

/**
 * Creates Uptime Kuma monitors for CRM sites by cloning a nominated template
 * monitor, so a new site arrives with the same notifications, check interval,
 * retry policy and accepted status codes as the rest of the fleet.
 *
 * Cloning the template's own object — rather than assembling one from a field
 * list — is deliberate. Uptime Kuma's `add` handler imports whatever you send
 * straight into monitor table columns, so any field its schema doesn't have is
 * a hard SQL error, and the column set differs between versions (1.21 has no
 * `timeout`, 1.22+ does). Starting from an object the server itself produced
 * sidesteps the whole problem.
 *
 * The catch is that `monitorList` also carries computed fields that are NOT
 * columns. Those are stripped — first from a known list, then adaptively from
 * whatever the server complains about, so a version we have not seen still
 * works instead of failing outright.
 */
class UptimeKumaMonitorFactory
{
    /**
     * Fields `monitorList` reports that are not monitor columns. Correct for
     * 1.21–1.23; anything else is caught by the adaptive retry below.
     */
    private const COMPUTED_FIELDS = ['id', 'includeSensitiveData', 'maintenance', 'tags', 'forceInactive', 'pathName', 'parent', 'childrenIDs', 'screenshot'];

    private const MAX_ADAPTIVE_RETRIES = 8;

    public function __construct(private PDO $db, private UptimeKumaService $kuma) {}

    /**
     * Create monitors for the given client_sites ids.
     *
     * One session for the whole batch — logging in per site would be slow and
     * would hammer the login endpoint on a bulk backfill.
     *
     * @param int[] $siteIds
     * @return array{created: int, failed: array<string, string>, skipped: array<string,string>}
     */
    public function createForSites(array $siteIds): array
    {
        $result = ['created' => 0, 'failed' => [], 'skipped' => []];
        if (!$siteIds) return $result;

        $cfg = $this->kuma->getConfig();
        $templateId = $cfg['template_monitor_id'] ?? null;
        if (!$templateId) {
            throw new RuntimeException('No template monitor chosen — pick one on /settings/uptime-kuma first.');
        }

        $sites = $this->fetchSites($siteIds);
        if (!$sites) {
            throw new RuntimeException('None of those sites could be found.');
        }

        $socket = $this->kuma->openSession();

        try {
            $socket->collect(['monitorList'], 20);
            $monitors = $socket->received('monitorList');
            if (!is_array($monitors)) {
                throw new RuntimeException('Uptime Kuma did not return its monitor list.');
            }

            $template = $monitors[(string)$templateId] ?? null;
            if (!$template) {
                throw new RuntimeException('The chosen template monitor no longer exists in Uptime Kuma — pick another.');
            }

            // Existing targets, so a re-run of a bulk create is a no-op rather
            // than a pile of duplicates.
            $existing = [];
            foreach ($monitors as $m) {
                $host = DomainMatcher::hostFromUrl((string)($m['url'] ?? $m['hostname'] ?? ''));
                if ($host !== '') $existing[$host] = true;
            }

            foreach ($sites as $site) {
                $label = $site['domain'] ?: ('Site #' . $site['id']);

                if (empty($site['domain'])) {
                    $result['skipped'][$label] = 'no domain on the site record';
                    continue;
                }
                if (isset($existing[strtolower($site['domain'])])) {
                    $result['skipped'][$label] = 'already monitored in Uptime Kuma';
                    continue;
                }

                try {
                    $kumaId = $this->addClone($socket, $template, $site);
                    $this->recordNewMonitor($kumaId, $site, $template);
                    $existing[strtolower($site['domain'])] = true;
                    $result['created']++;
                } catch (\Throwable $e) {
                    $result['failed'][$label] = $e->getMessage();
                }
            }
        } finally {
            $socket->close();
        }

        return $result;
    }

    /** @return int Uptime Kuma's id for the new monitor. */
    private function addClone(UptimeKumaSocket $socket, array $template, array $site): int
    {
        $monitor = $template;
        foreach (self::COMPUTED_FIELDS as $field) {
            unset($monitor[$field]);
        }

        $monitor['name']   = $this->monitorName($site);
        $monitor['url']    = 'https://' . $site['domain'];
        $monitor['active'] = true;

        // A cloned template may carry connectivity fields aimed at its own
        // target; for an http monitor they are meaningless and misleading.
        if (($monitor['type'] ?? 'http') === 'http') {
            $monitor['hostname'] = null;
            $monitor['port']     = null;
        }

        for ($attempt = 0; $attempt <= self::MAX_ADAPTIVE_RETRIES; $attempt++) {
            $res = $socket->emit('add', [$monitor]);

            if (is_array($res) && !empty($res['ok']) && !empty($res['monitorID'])) {
                return (int)$res['monitorID'];
            }

            $msg = is_array($res) ? (string)($res['msg'] ?? '') : '';

            // "table monitor has no column named foo_bar" — a computed field this
            // version doesn't store. RedBean snake_cases on import, so map the
            // column name back to the property we sent, drop it, and retry.
            if (preg_match('/no column named (\w+)/', $msg, $m)) {
                $key = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $m[1]))));
                if (array_key_exists($key, $monitor)) {
                    unset($monitor[$key]);
                    continue;
                }
                if (array_key_exists($m[1], $monitor)) {
                    unset($monitor[$m[1]]);
                    continue;
                }
            }

            throw new RuntimeException($msg !== '' ? $msg : 'Uptime Kuma refused to create the monitor.');
        }

        throw new RuntimeException('Uptime Kuma kept rejecting fields copied from the template monitor.');
    }

    /**
     * Name it after the client and domain so it reads sensibly in Uptime Kuma's
     * own list, which is sorted by name and has no notion of our clients.
     */
    private function monitorName(array $site): string
    {
        return $site['client_name']
            ? $site['client_name'] . ' — ' . $site['domain']
            : $site['domain'];
    }

    /**
     * Insert the mirror row immediately rather than waiting for the next sync,
     * so the site page reflects the new monitor as soon as the request returns.
     */
    private function recordNewMonitor(int $kumaId, array $site, array $template): void
    {
        $this->db->prepare(
            "INSERT INTO uptime_kuma_monitors
                (kuma_id, monitor_name, monitor_type, monitor_url, status, active,
                 client_site_id, link_is_manual, created_by_crm, first_seen_at, last_synced_at)
             VALUES (?, ?, ?, ?, NULL, 1, ?, 0, 1, datetime('now'), datetime('now'))
             ON CONFLICT(kuma_id) DO UPDATE SET
                monitor_name   = excluded.monitor_name,
                client_site_id = excluded.client_site_id,
                created_by_crm = 1,
                is_stale       = 0"
        )->execute([
            $kumaId,
            $this->monitorName($site),
            $template['type'] ?? 'http',
            'https://' . $site['domain'],
            $site['id'],
        ]);
    }

    /** @param int[] $ids */
    private function fetchSites(array $ids): array
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT cs.id, LOWER(d.domain) AS domain, c.name AS client_name
             FROM client_sites cs
             LEFT JOIN domains d ON d.id = cs.domain_id
             LEFT JOIN clients c ON c.id = cs.client_id
             WHERE cs.id IN ($in)
             ORDER BY d.domain"
        );
        $stmt->execute(array_map('intval', $ids));
        return $stmt->fetchAll();
    }
}
