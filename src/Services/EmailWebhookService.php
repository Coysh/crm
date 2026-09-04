<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;

final class EmailWebhookService
{
    public function __construct(private PDO $db) {}

    public function handle(array $payload): void
    {
        $signature = $payload['signature'] ?? [];
        $timestamp = (int)($signature['timestamp'] ?? 0);
        $token = (string)($signature['token'] ?? '');
        $provided = (string)($signature['signature'] ?? '');
        $config = $this->config();
        $key = Secrets::decrypt($config['webhook_signing_key'] ?? '');
        if (!$timestamp || !$token || !$provided || !$key
            || abs(time() - $timestamp) > 86400
            || !hash_equals(hash_hmac('sha256', (string)$timestamp . $token, $key), $provided)) {
            throw new RuntimeException('Invalid Mailgun webhook signature.');
        }

        try {
            $this->db->prepare('INSERT INTO email_webhook_receipts (token) VALUES (?)')->execute([$token]);
        } catch (\Throwable) {
            return; // A retried or replayed webhook was already applied.
        }

        $event = $payload['event-data'] ?? $payload;
        $eventType = (string)($event['event'] ?? '');
        $severity = (string)($event['severity'] ?? '');
        if ($eventType === 'failed') $eventType = $severity === 'permanent' ? 'permanent_fail' : 'temporary_fail';
        $eventType = str_replace('-', '_', $eventType);
        $allowed = ['accepted', 'delivered', 'temporary_fail', 'permanent_fail', 'opened', 'clicked', 'unsubscribed', 'complained'];
        if (!in_array($eventType, $allowed, true)) return;

        $variables = $event['user-variables'] ?? [];
        if (is_string($variables)) $variables = json_decode($variables, true) ?: [];
        $recipientId = (int)($variables['crm_recipient_id'] ?? 0);
        $messageId = trim((string)($event['message']['headers']['message-id'] ?? ''), '<>');
        $recipient = null;
        if ($recipientId) {
            $stmt = $this->db->prepare('SELECT * FROM email_campaign_recipients WHERE id=?');
            $stmt->execute([$recipientId]);
            $recipient = $stmt->fetch() ?: null;
        }
        if (!$recipient && $messageId !== '') {
            $stmt = $this->db->prepare('SELECT * FROM email_campaign_recipients WHERE mailgun_message_id=? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$messageId]);
            $recipient = $stmt->fetch() ?: null;
        }
        if (!$recipient) return;

        $eventAt = date('Y-m-d H:i:s', (int)($event['timestamp'] ?? time()));
        $providerEventId = (string)($event['id'] ?? '');
        $url = $eventType === 'clicked' ? (string)($event['url'] ?? '') : null;
        $bot = !empty($event['client-info']['bot']) || !empty($event['bot']);
        $details = $event['delivery-status']['description'] ?? $event['reason'] ?? null;
        $this->db->prepare("INSERT OR IGNORE INTO email_events
            (recipient_id,campaign_id,contact_id,provider_event_id,event_type,event_at,url,is_bot,severity,details)
            VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([
                $recipient['id'], $recipient['campaign_id'], $recipient['contact_id'], $providerEventId ?: null,
                $eventType, $eventAt, $url, $bot ? 1 : 0, $severity ?: null, is_scalar($details) ? (string)$details : null,
            ]);

        $updates = match ($eventType) {
            'accepted' => ["status=CASE WHEN status IN ('pending','processing') THEN 'accepted' ELSE status END, accepted_at=COALESCE(accepted_at,?)", [$eventAt]],
            'delivered' => ["status='delivered', delivered_at=?", [$eventAt]],
            'temporary_fail' => ["status=CASE WHEN status IN ('pending','processing','accepted','temporary_failed') THEN 'temporary_failed' ELSE status END, last_error=?", [(string)$details]],
            'permanent_fail' => ["status='permanent_failed', failed_at=?, last_error=?", [$eventAt, (string)$details]],
            default => null,
        };
        if ($updates) {
            $this->db->prepare('UPDATE email_campaign_recipients SET ' . $updates[0] . ' WHERE id=?')
                ->execute([...$updates[1], $recipient['id']]);
        }
        if ($eventType === 'unsubscribed') $this->suppress($recipient, 'unsubscribe', 'mailgun', $eventAt, (string)$details);
        if ($eventType === 'complained') $this->suppress($recipient, 'complaint', 'mailgun', $eventAt, (string)$details);
        if ($eventType === 'permanent_fail') $this->suppress($recipient, 'hard_bounce', 'mailgun', $eventAt, (string)$details);
    }

    public function unsubscribe(int $recipientId, string $token): bool
    {
        $stmt = $this->db->prepare('SELECT * FROM email_campaign_recipients WHERE id=?');
        $stmt->execute([$recipientId]);
        $recipient = $stmt->fetch();
        if (!$recipient || !hash_equals(Secrets::decrypt($recipient['unsubscribe_token']), $token)) return false;
        $this->suppress($recipient, 'unsubscribe', 'recipient', date('Y-m-d H:i:s'), null);
        return true;
    }

    public function validUnsubscribe(int $recipientId, string $token): bool
    {
        $stmt = $this->db->prepare('SELECT unsubscribe_token FROM email_campaign_recipients WHERE id=?');
        $stmt->execute([$recipientId]);
        $encrypted = $stmt->fetchColumn();
        return is_string($encrypted) && $encrypted !== '' && hash_equals(Secrets::decrypt($encrypted), $token);
    }

    private function suppress(array $recipient, string $reason, string $source, string $at, ?string $details): void
    {
        $this->db->prepare("INSERT INTO marketing_suppressions (email_norm,reason,source,contact_id,details,created_at,cleared_at)
            VALUES (?,?,?,?,?,?,NULL)
            ON CONFLICT(email_norm) DO UPDATE SET reason=excluded.reason,source=excluded.source,
                contact_id=excluded.contact_id,details=excluded.details,created_at=excluded.created_at,cleared_at=NULL")
            ->execute([$recipient['email_norm'], $reason, $source, $recipient['contact_id'], $details, $at]);
        if (!empty($recipient['contact_id'])) {
            $this->db->prepare('UPDATE marketing_contacts SET unsubscribed_at=?, updated_at=datetime(\'now\') WHERE id=?')
                ->execute([$at, $recipient['contact_id']]);
            $this->db->prepare("INSERT INTO marketing_consent_events (contact_id,event_type,basis,source,notes,occurred_at) VALUES (?,'unsubscribed',NULL,?,?,?)")
                ->execute([$recipient['contact_id'], $source, $details, $at]);
        }
    }

    private function config(): array
    {
        return $this->db->query('SELECT * FROM email_marketing_config WHERE id=1')->fetch() ?: [];
    }
}
