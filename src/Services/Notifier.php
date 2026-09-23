<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;

/**
 * Pushes the attention list to the CRM owner so nothing depends on opening
 * the app: a daily digest email, and an immediate email when a monitored
 * site goes down. Sent through Mailgun as system mail (no tracking or
 * unsubscribe headers). Driven every 5 minutes by scripts/notify.php.
 */
class Notifier
{
    public const TIMEZONE = 'Europe/London';

    public function __construct(private PDO $db) {}

    public function config(): array
    {
        try {
            $row = $this->db->query("SELECT * FROM notification_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            $row = false; // migration 039 not applied yet
        }
        return $row ?: ['digest_enabled' => 0, 'recipient' => null, 'digest_time' => '07:30',
                        'include_low' => 0, 'site_down_alerts' => 0, 'app_url' => null, 'last_digest_at' => null];
    }

    public function isDigestDue(?\DateTimeImmutable $now = null): bool
    {
        $cfg = $this->config();
        if (empty($cfg['digest_enabled'])) return false;

        $tz  = new \DateTimeZone(self::TIMEZONE);
        $now = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz);
        $sendAt = new \DateTimeImmutable($now->format('Y-m-d') . ' ' . $cfg['digest_time'], $tz);
        if ($now < $sendAt) return false;

        if (empty($cfg['last_digest_at'])) return true;
        $last = new \DateTimeImmutable($cfg['last_digest_at'], new \DateTimeZone('UTC'));
        return $last < $sendAt;
    }

    /**
     * @return array{sent:bool, reason:string, subject?:string, text?:string, html?:string}
     */
    public function sendDigest(bool $dryRun = false): array
    {
        $cfg   = $this->config();
        $items = (new Attention($this->db))->items((bool)$cfg['include_low']);
        $lowCount = 0;
        if (!$cfg['include_low']) {
            // Mention housekeeping without listing it.
            $lowCount = count((new Attention($this->db))->items(true)) - count($items);
        }

        // Stamp the day as handled even when there's nothing to send, so an
        // empty morning doesn't retry every 5 minutes.
        if (!$dryRun) {
            $this->db->exec("UPDATE notification_config SET last_digest_at = datetime('now') WHERE id = 1");
        }

        if (!$items) {
            return ['sent' => false, 'reason' => 'Nothing needs attention — no digest sent'];
        }

        $counts  = array_count_values(array_column($items, 'severity'));
        $parts   = array_filter([
            ($counts['high'] ?? 0) ? "{$counts['high']} urgent" : null,
            ($counts['medium'] ?? 0) ? "{$counts['medium']} this week" : null,
            ($counts['low'] ?? 0) ? "{$counts['low']} housekeeping" : null,
        ]);
        $subject = 'CRM: ' . implode(', ', $parts) . ' — '
                 . (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('D j M');
        [$html, $text] = $this->renderList('Today in the CRM', $items, $lowCount);

        if ($dryRun) {
            return ['sent' => false, 'reason' => 'Dry run', 'subject' => $subject, 'text' => $text, 'html' => $html];
        }

        $this->send($subject, $html, $text);
        return ['sent' => true, 'reason' => "Digest sent to {$cfg['recipient']}", 'subject' => $subject];
    }

    /**
     * Email once per outage (keyed like the attention item, so a site that
     * recovers and fails again alerts again). Snoozed outages don't alert.
     *
     * @return string[] titles of sites alerted on
     */
    public function sendSiteDownAlerts(bool $dryRun = false): array
    {
        $cfg = $this->config();
        if (empty($cfg['site_down_alerts'])) return [];

        $attention = new Attention($this->db);
        $snoozedKeys = array_column(array_filter($attention->items(false, true), fn($i) => $i['snoozed']), 'key');
        $down = array_filter($attention->sitesDown(), fn($i) => !in_array($i['key'], $snoozedKeys, true));
        if (!$down) return [];

        $already = $this->db->query("SELECT item_key FROM notification_log")->fetchAll(PDO::FETCH_COLUMN);
        $new = array_values(array_filter($down, fn($i) => !in_array($i['key'], $already, true)));
        if (!$new) return [];

        $titles  = array_column($new, 'title');
        $subject = count($new) === 1 ? "Site down: {$titles[0]}" : count($new) . ' sites down: ' . implode(', ', array_slice($titles, 0, 3));
        [$html, $text] = $this->renderList($subject, $new, 0);

        if (!$dryRun) {
            $this->send($subject, $html, $text);
            $stmt = $this->db->prepare("INSERT OR IGNORE INTO notification_log (item_key) VALUES (?)");
            foreach ($new as $i) $stmt->execute([$i['key']]);
            $this->db->exec("DELETE FROM notification_log WHERE sent_at < datetime('now', '-90 days')");
        }
        return $titles;
    }

    // ── Rendering / transport ───────────────────────────────────────────────

    private function send(string $subject, string $html, string $text): void
    {
        $to = trim((string)($this->config()['recipient'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('No valid notification recipient set in Settings.');
        }
        (new MailgunTransport($this->db))->sendSystem($to, $subject, $html, $text);
    }

    private function baseUrl(): string
    {
        $env = $_ENV['APP_URL'] ?? getenv('APP_URL');
        if ($env) return rtrim((string)$env, '/');
        $stored = $this->config()['app_url'] ?? '';
        return $stored !== '' ? rtrim($stored, '/') : appUrl();
    }

    /** @return array{0:string, 1:string} [html, text] */
    private function renderList(string $heading, array $items, int $lowCount): array
    {
        $base   = $this->baseUrl();
        $h      = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $groups = ['high' => 'Needs action now', 'medium' => 'This week', 'low' => 'Housekeeping'];
        $dots   = ['high' => '#dc2626', 'medium' => '#f59e0b', 'low' => '#94a3b8'];

        $rows = '';
        $text = [$heading, str_repeat('=', mb_strlen($heading)), ''];
        foreach ($groups as $sev => $label) {
            $group = array_filter($items, fn($i) => $i['severity'] === $sev);
            if (!$group) continue;
            $rows .= '<tr><td style="padding:18px 0 6px;font-size:13px;font-weight:bold;color:#334155;text-transform:uppercase;letter-spacing:.04em;">'
                   . $h($label) . ' (' . count($group) . ')</td></tr>';
            $text[] = strtoupper($label) . ' (' . count($group) . ')';
            foreach ($group as $i) {
                $url = str_starts_with($i['url'], 'http') ? $i['url'] : $base . $i['url'];
                $meta = trim(implode(' · ', array_filter([$i['kind'], $i['client_name'], $i['detail']])));
                $rows .= '<tr><td style="padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:14px;line-height:1.4;">'
                       . '<span style="display:inline-block;width:8px;height:8px;border-radius:4px;background:' . $dots[$sev] . ';margin-right:8px;"></span>'
                       . '<a href="' . $h($url) . '" style="color:#0f172a;font-weight:bold;text-decoration:none;">' . $h($i['title']) . '</a>'
                       . '<div style="color:#64748b;font-size:12px;margin-left:16px;">' . $h($meta) . '</div></td></tr>';
                $text[] = "- {$i['title']} — {$meta}\n  {$url}";
            }
            $text[] = '';
        }
        if ($lowCount > 0) {
            $rows .= '<tr><td style="padding:14px 0 0;font-size:13px;color:#64748b;">Plus ' . $lowCount
                   . ' housekeeping item' . ($lowCount === 1 ? '' : 's') . ' on the Today page.</td></tr>';
            $text[] = "Plus {$lowCount} housekeeping items on the Today page.";
        }

        $todayUrl = $base . '/today';
        $html = '<!doctype html><html><body style="margin:0;padding:0;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;">'
              . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f8fafc;"><tr><td align="center" style="padding:24px 12px;">'
              . '<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:600px;max-width:100%;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;">'
              . '<tr><td style="padding:24px 28px 8px;"><div style="font-size:12px;color:#64748b;">Coysh Digital CRM</div>'
              . '<h1 style="margin:4px 0 0;font-size:20px;color:#0f172a;">' . $h($heading) . '</h1></td></tr>'
              . '<tr><td style="padding:0 28px 8px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0">' . $rows . '</table></td></tr>'
              . '<tr><td style="padding:16px 28px 24px;"><a href="' . $h($todayUrl) . '" style="display:inline-block;background:#a1c63e;color:#0f172a;font-weight:bold;text-decoration:none;padding:10px 16px;border-radius:6px;font-size:14px;">Open Today</a>'
              . '<div style="margin-top:14px;font-size:11px;color:#94a3b8;">Snooze or dismiss items on the Today page to keep them out of this email. Change or turn off notifications under Settings.</div></td></tr>'
              . '</table></td></tr></table></body></html>';

        $text[] = "Open Today: {$todayUrl}";
        return [$html, implode("\n", $text)];
    }
}
