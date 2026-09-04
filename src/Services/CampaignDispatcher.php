<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;
use Throwable;

final class CampaignDispatcher
{
    public function __construct(
        private PDO $db,
        private ?EmailTransport $transport = null,
    ) {}

    public function schedule(int $campaignId, string $scheduledAt): array
    {
        $campaign = $this->campaign($campaignId);
        if ($campaign['status'] !== 'draft') throw new RuntimeException('Only draft campaigns can be scheduled.');
        if (trim((string)$campaign['subject']) === '') throw new RuntimeException('Campaign subject is required.');
        if (empty($campaign['segment_id'])) throw new RuntimeException('Choose a segment before scheduling.');
        $config = $this->config();
        foreach (['sending_domain', 'from_name', 'from_email', 'business_name', 'business_address'] as $field) {
            if (trim((string)($config[$field] ?? '')) === '') throw new RuntimeException("Email setting '$field' is required.");
        }
        if (!filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The configured sender email is invalid.');
        $base = appUrl();
        $scheme = strtolower((string)parse_url($base, PHP_URL_SCHEME));
        $host = strtolower((string)parse_url($base, PHP_URL_HOST));
        if ($scheme !== 'https' || $host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('APP_URL must be set to the public HTTPS CRM URL before scheduling.');
        }

        $audience = (new SegmentEvaluator($this->db))->audience((int)$campaign['segment_id']);
        if (!$audience['included']) throw new RuntimeException('This segment has no eligible recipients.');
        $content = json_decode((string)$campaign['content_json'], true) ?: EmailRenderer::blankContent();
        $content['preheader'] = (string)($campaign['preheader'] ?? '');
        $rendered = (new EmailRenderer($this->db))->render(
            $content,
            ['name' => '{{name}}', 'company_name' => '{{company}}'],
            '{{unsubscribe_url}}'
        );

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM email_campaign_recipients WHERE campaign_id=?')->execute([$campaignId]);
            $insert = $this->db->prepare("INSERT INTO email_campaign_recipients
                (campaign_id, contact_id, name, company_name, email, email_norm, status, unsubscribe_token)
                VALUES (?,?,?,?,?,?, 'pending', ?)");
            foreach ($audience['included'] as $contact) {
                $insert->execute([
                    $campaignId, $contact['id'], $contact['name'], $contact['company_name'],
                    $contact['email'], $contact['email_norm'], Secrets::encrypt(bin2hex(random_bytes(24))),
                ]);
            }
            $stmt = $this->db->prepare("UPDATE email_campaigns SET status='scheduled', scheduled_at=?,
                rendered_html=?, rendered_text=?, from_name=?, from_email=?, reply_to=?,
                tracking_opens=?, tracking_clicks=?, total_recipients=?, excluded_recipients=?, updated_at=datetime('now')
                WHERE id=?");
            $stmt->execute([
                $scheduledAt, $rendered['html'], $rendered['text'], $config['from_name'], $config['from_email'],
                $config['reply_to'] ?: null, (int)$campaign['tracking_opens'], (int)$campaign['tracking_clicks'],
                count($audience['included']), count($audience['excluded']), $campaignId,
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
        return $audience;
    }

    public function returnToDraft(int $campaignId): void
    {
        $campaign = $this->campaign($campaignId);
        if ($campaign['status'] !== 'scheduled') throw new RuntimeException('Only scheduled campaigns can return to draft.');
        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM email_campaign_recipients WHERE campaign_id=?')->execute([$campaignId]);
            $this->db->prepare("UPDATE email_campaigns SET status='draft', scheduled_at=NULL, rendered_html=NULL, rendered_text=NULL, total_recipients=0, excluded_recipients=0, updated_at=datetime('now') WHERE id=?")->execute([$campaignId]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function pause(int $campaignId): void
    {
        $this->db->prepare("UPDATE email_campaigns SET status='paused', updated_at=datetime('now') WHERE id=? AND status='sending'")->execute([$campaignId]);
    }

    public function resume(int $campaignId): void
    {
        $this->db->prepare("UPDATE email_campaigns SET status='sending', updated_at=datetime('now') WHERE id=? AND status='paused'")->execute([$campaignId]);
    }

    public function cancel(int $campaignId): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE email_campaign_recipients SET status='cancelled' WHERE campaign_id=? AND status IN ('pending','retry')")->execute([$campaignId]);
            $this->db->prepare("UPDATE email_campaigns SET status='cancelled', cancelled_at=datetime('now'), updated_at=datetime('now') WHERE id=? AND status IN ('scheduled','sending','paused')")->execute([$campaignId]);
            $this->db->commit();
        } catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }

    public function run(int $limit = 50): array
    {
        $this->db->exec("INSERT INTO email_marketing_config (id, worker_last_run_at) VALUES (1, datetime('now')) ON CONFLICT(id) DO UPDATE SET worker_last_run_at=datetime('now')");
        // A worker may die after claiming a recipient. Never auto-resend that
        // ambiguous delivery: surface it for an explicit operator decision.
        $this->db->exec("UPDATE email_campaign_recipients SET status='unknown',
            last_error='Worker interrupted while delivery status was unknown', processing_started_at=NULL
            WHERE status='processing' AND processing_started_at <= datetime('now','-30 minutes')");
        $this->db->exec("UPDATE email_campaigns SET status='sending', started_at=COALESCE(started_at,datetime('now')) WHERE status='scheduled' AND scheduled_at <= datetime('now')");
        $sent = $failed = $unknown = 0;
        $hasWork = (int)$this->db->query("SELECT COUNT(*) FROM email_campaign_recipients r JOIN email_campaigns c ON c.id=r.campaign_id WHERE c.status='sending' AND r.status IN ('pending','retry') AND (r.next_attempt_at IS NULL OR r.next_attempt_at <= datetime('now'))")->fetchColumn();
        if ($hasWork === 0) { $this->completeCampaigns(); return compact('sent', 'failed', 'unknown'); }
        $transport = $this->transport ??= new MailgunTransport($this->db);

        for ($i = 0; $i < $limit; $i++) {
            $row = $this->db->query("SELECT r.*, c.subject, c.rendered_html, c.rendered_text, c.from_name, c.from_email,
                    c.reply_to, c.tracking_opens, c.tracking_clicks
                FROM email_campaign_recipients r JOIN email_campaigns c ON c.id=r.campaign_id
                WHERE c.status='sending' AND r.status IN ('pending','retry')
                  AND (r.next_attempt_at IS NULL OR r.next_attempt_at <= datetime('now'))
                ORDER BY c.scheduled_at, r.id LIMIT 1")->fetch();
            if (!$row) break;

            $claimed = $this->db->prepare("UPDATE email_campaign_recipients SET status='processing', processing_started_at=datetime('now'), attempts=attempts+1 WHERE id=? AND status IN ('pending','retry')");
            $claimed->execute([$row['id']]);
            if ($claimed->rowCount() !== 1) continue;
            $row['attempts']++;
            $blocked = $this->db->prepare("SELECT 1 FROM marketing_suppressions WHERE email_norm=? AND cleared_at IS NULL
                UNION ALL SELECT 1 FROM marketing_contacts WHERE id=? AND (status<>'active' OR unsubscribed_at IS NOT NULL) LIMIT 1");
            $blocked->execute([$row['email_norm'], $row['contact_id']]);
            if ($blocked->fetchColumn()) {
                $this->db->prepare("UPDATE email_campaign_recipients SET status='suppressed', last_error='Suppressed after scheduling', processing_started_at=NULL WHERE id=?")->execute([$row['id']]);
                $this->attempt((int)$row['id'], (int)$row['attempts'], 'suppressed', null, 'Suppressed after scheduling');
                continue;
            }
            $token = Secrets::decrypt($row['unsubscribe_token']);
            $unsubscribeUrl = appUrl() . '/email/unsubscribe/' . $row['id'] . '/' . rawurlencode($token);
            $values = [
                '{{name}}' => htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8'),
                '{{company}}' => htmlspecialchars((string)$row['company_name'], ENT_QUOTES, 'UTF-8'),
                '{{unsubscribe_url}}' => htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8'),
            ];
            $plainValues = ['{{name}}' => (string)$row['name'], '{{company}}' => (string)$row['company_name'], '{{unsubscribe_url}}' => $unsubscribeUrl];
            try {
                $result = $transport->send([
                    'from' => $row['from_name'] . ' <' . $row['from_email'] . '>', 'to' => $row['email'],
                    'reply_to' => $row['reply_to'], 'subject' => strtr($row['subject'], $plainValues),
                    'html' => strtr($row['rendered_html'], $values), 'text' => strtr($row['rendered_text'], $plainValues),
                    'tracking_opens' => $row['tracking_opens'], 'tracking_clicks' => $row['tracking_clicks'],
                    'campaign_id' => $row['campaign_id'], 'recipient_id' => $row['id'], 'unsubscribe_url' => $unsubscribeUrl,
                ]);
                $this->db->prepare("UPDATE email_campaign_recipients SET status='accepted', mailgun_message_id=?, accepted_at=datetime('now'), processing_started_at=NULL WHERE id=?")
                    ->execute([$result['id'], $row['id']]);
                $this->attempt((int)$row['id'], (int)$row['attempts'], 'accepted', $result['id']);
                $sent++;
            } catch (Throwable $e) {
                $code = (int)$e->getCode();
                if (($code === 429 || $code >= 500) && $row['attempts'] < 4) {
                    $delay = [1 => 1, 2 => 5, 3 => 15][$row['attempts']] ?? 15;
                    $this->db->prepare("UPDATE email_campaign_recipients SET status='retry', next_attempt_at=datetime('now', ?), last_error=?, processing_started_at=NULL WHERE id=?")
                        ->execute(['+' . $delay . ' minutes', $e->getMessage(), $row['id']]);
                    $this->attempt((int)$row['id'], (int)$row['attempts'], 'retry', null, $e->getMessage());
                } elseif (($code === 429 || $code >= 500) || ($code >= 400 && $code < 500)) {
                    $this->db->prepare("UPDATE email_campaign_recipients SET status='permanent_failed', failed_at=datetime('now'), last_error=?, processing_started_at=NULL WHERE id=?")
                        ->execute([$e->getMessage(), $row['id']]);
                    $this->attempt((int)$row['id'], (int)$row['attempts'], 'permanent_failed', null, $e->getMessage());
                    $failed++;
                } else {
                    $this->db->prepare("UPDATE email_campaign_recipients SET status='unknown', failed_at=datetime('now'), last_error=?, processing_started_at=NULL WHERE id=?")
                        ->execute([$e->getMessage(), $row['id']]);
                    $this->attempt((int)$row['id'], (int)$row['attempts'], 'unknown', null, $e->getMessage());
                    $unknown++;
                }
            }
        }
        $this->completeCampaigns();
        return compact('sent', 'failed', 'unknown');
    }

    private function attempt(int $recipientId, int $number, string $result, ?string $messageId = null, ?string $error = null): void
    {
        $this->db->prepare('INSERT INTO email_send_attempts (recipient_id,attempt_number,result,provider_message_id,error_message) VALUES (?,?,?,?,?)')
            ->execute([$recipientId, $number, $result, $messageId, $error]);
    }

    private function completeCampaigns(): void
    {
        $this->db->exec("UPDATE email_campaigns SET status='completed', completed_at=datetime('now'), updated_at=datetime('now')
            WHERE status='sending' AND NOT EXISTS (
                SELECT 1 FROM email_campaign_recipients r WHERE r.campaign_id=email_campaigns.id AND r.status IN ('pending','retry','processing')
            )");
    }

    private function campaign(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM email_campaigns WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new RuntimeException('Campaign not found.');
        return $row;
    }

    private function config(): array
    {
        return $this->db->query('SELECT * FROM email_marketing_config WHERE id=1')->fetch() ?: [];
    }
}
