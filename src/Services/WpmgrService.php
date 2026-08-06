<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;

/**
 * Raw REST client for a self-hosted WPMGR instance (WordPress fleet manager,
 * https://wpmgr.app). No PHP SDK exists, so this mirrors CloudflareService's
 * file_get_contents()/stream_context_create() pattern rather than Ploi's SDK
 * wrapper. Auth is `Authorization: Bearer wpmgr_<prefix>_<secret>`.
 */
class WpmgrService
{
    public function __construct(private PDO $db) {}

    public function getConfig(): ?array
    {
        $row = $this->db->query("SELECT * FROM wpmgr_config WHERE id = 1")->fetch() ?: null;
        return Secrets::decryptRow($row, ['api_key']);
    }

    public function isConnected(): bool
    {
        $cfg = $this->getConfig();
        return !empty($cfg['api_key']) && !empty($cfg['base_url']);
    }

    public function saveConfig(string $baseUrl, string $apiKey): void
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }
        $encrypted = Secrets::encrypt($apiKey);

        $exists = $this->db->query("SELECT id FROM wpmgr_config WHERE id = 1")->fetch();
        if ($exists) {
            $this->db->prepare("UPDATE wpmgr_config SET base_url = ?, api_key = ? WHERE id = 1")
                ->execute([$baseUrl, $encrypted]);
            return;
        }
        $this->db->prepare("INSERT INTO wpmgr_config (id, base_url, api_key) VALUES (1, ?, ?)")
            ->execute([$baseUrl, $encrypted]);
    }

    public function disconnect(): void
    {
        $this->db->exec("UPDATE wpmgr_config SET api_key = NULL, last_sync_at = NULL WHERE id = 1");
    }

    public function validateConnection(): array
    {
        $this->request('GET', '/api/v1/sites', ['limit' => 1]);
        return ['ok' => true];
    }

    /** One page of sites: GET /api/v1/sites?limit=&offset=. */
    public function listSites(int $limit, int $offset): array
    {
        $result = $this->request('GET', '/api/v1/sites', ['limit' => $limit, 'offset' => $offset]);
        return $result['items'] ?? [];
    }

    private function request(string $method, string $path, array $query = []): array
    {
        $config = $this->getConfig();
        if (empty($config['api_key']) || empty($config['base_url'])) {
            throw new RuntimeException('WPMGR is not configured.');
        }

        $url = $config['base_url'] . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $config['api_key'],
            'Accept: application/json',
        ];

        $opts = [
            'http' => [
                'method'        => $method,
                'header'        => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout'       => 15,
            ],
        ];

        $context  = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('WPMGR API request failed: ' . $path);
        }

        $httpCode = 0;
        $responseHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? []);  // @phpstan-ignore-line
        foreach ($responseHeaders as $h) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $m)) {
                $httpCode = (int)$m[1];
            }
        }

        $decoded = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = is_array($decoded) ? ($decoded['message'] ?? $decoded['code'] ?? 'Unknown error') : ('HTTP ' . $httpCode);
            throw new RuntimeException('WPMGR API error: ' . $msg);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from WPMGR API.');
        }

        return $decoded;
    }
}
