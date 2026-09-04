<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use Mailgun\Mailgun;
use PDO;
use RuntimeException;

final class MailgunTransport implements EmailTransport
{
    private array $config;
    private Mailgun $client;

    public function __construct(private PDO $db)
    {
        $row = $db->query('SELECT * FROM email_marketing_config WHERE id = 1')->fetch();
        $this->config = Secrets::decryptRow($row ?: null, ['api_key', 'webhook_signing_key']) ?? [];
        if (empty($this->config['api_key'])) throw new RuntimeException('Mailgun is not configured.');
        $endpoint = ($this->config['region'] ?? 'eu') === 'eu' ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
        $this->client = Mailgun::create($this->config['api_key'], $endpoint);
    }

    public function send(array $message): array
    {
        if (empty($this->config['sending_domain'])) throw new RuntimeException('Mailgun sending domain is missing.');
        $params = [
            'from' => $message['from'], 'to' => $message['to'], 'subject' => $message['subject'],
            'html' => $message['html'], 'text' => $message['text'],
            'o:tracking-opens' => !empty($message['tracking_opens']) ? 'yes' : 'no',
            'o:tracking-clicks' => !empty($message['tracking_clicks']) ? 'yes' : 'no',
            'o:tag' => ['crm-marketing'],
            'v:crm_campaign_id' => (string)($message['campaign_id'] ?? ''),
            'v:crm_recipient_id' => (string)($message['recipient_id'] ?? ''),
            'h:List-Unsubscribe' => '<' . $message['unsubscribe_url'] . '>',
            'h:List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ];
        if (!empty($message['reply_to'])) $params['h:Reply-To'] = $message['reply_to'];
        $response = $this->client->messages()->send($this->config['sending_domain'], $params);
        return ['id' => trim($response->getId(), '<>'), 'message' => $response->getMessage()];
    }

    public function verify(): bool
    {
        if (empty($this->config['sending_domain'])) return false;
        try { $this->client->domains()->show($this->config['sending_domain']); return true; }
        catch (\Throwable) { return false; }
    }

    public function configureWebhooks(string $url): void
    {
        $events = ['accepted', 'delivered', 'temporary_fail', 'permanent_fail', 'opened', 'clicked', 'unsubscribed', 'complained'];
        foreach ($events as $event) {
            try { $this->client->webhooks()->update($this->config['sending_domain'], $event, [$url]); }
            catch (\Throwable) { $this->client->webhooks()->create($this->config['sending_domain'], $event, [$url]); }
        }
    }
}
