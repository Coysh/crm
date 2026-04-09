<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;

class CloudflareService
{
    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function __construct(private PDO $db) {}

    public function getConfig(): ?array
    {
        try {
            $row = $this->db->query("SELECT * FROM cloudflare_config WHERE id = 1")->fetch();
            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function isConnected(): bool
    {
        $config = $this->getConfig();
        return !empty($config['api_token']);
    }

    public function verifyToken(): bool
    {
        try {
            $result = $this->request('GET', '/user/tokens/verify');
            return ($result['success'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function listZones(): array
    {
        $zones = [];
        $page  = 1;
        do {
            $result = $this->request('GET', '/zones?per_page=50&page=' . $page);
            $batch  = $result['result'] ?? [];
            $zones  = array_merge($zones, $batch);
            $total  = $result['result_info']['total_pages'] ?? 1;
            $page++;
        } while ($page <= $total && count($batch) > 0);
        return $zones;
    }

    public function listDnsRecords(string $zoneId): array
    {
        $records = [];
        $page    = 1;
        do {
            $result  = $this->request('GET', '/zones/' . $zoneId . '/dns_records?per_page=100&page=' . $page);
            $batch   = $result['result'] ?? [];
            $records = array_merge($records, $batch);
            $total   = $result['result_info']['total_pages'] ?? 1;
            $page++;
        } while ($page <= $total && count($batch) > 0);
        return $records;
    }

    public function createDnsRecord(string $zoneId, array $data): array
    {
        return $this->request('POST', '/zones/' . $zoneId . '/dns_records', $data);
    }

    public function updateDnsRecord(string $zoneId, string $recordId, array $data): array
    {
        return $this->request('PATCH', '/zones/' . $zoneId . '/dns_records/' . $recordId, $data);
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $config = $this->getConfig();
        if (empty($config['api_token'])) {
            throw new RuntimeException('Cloudflare API token not configured.');
        }

        $url     = self::API_BASE . $path;
        $headers = [
            'Authorization: Bearer ' . $config['api_token'],
            'Content-Type: application/json',
        ];

        $opts = [
            'http' => [
                'method'  => $method,
                'header'  => implode("\r\n", $headers),
                'ignore_errors' => true,
            ],
        ];

        if ($data && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $opts['http']['content'] = json_encode($data);
        }

        $context  = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        // Handle 429 rate limit with one retry
        if ($response === false) {
            $httpCode = 0;
            $responseHeaders = function_exists('http_get_last_response_headers')
                ? http_get_last_response_headers()
                : ($http_response_header ?? []);  // @phpstan-ignore-line
            foreach ($responseHeaders as $h) {
                if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $m)) {
                    $httpCode = (int)$m[1];
                }
            }
            if ($httpCode === 429) {
                sleep(1);
                $response = @file_get_contents($url, false, $context);
            }
        }

        if ($response === false) {
            throw new RuntimeException('Cloudflare API request failed: ' . $path);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON response from Cloudflare API.');
        }

        if (isset($decoded['success']) && $decoded['success'] === false) {
            $errors = $decoded['errors'] ?? [];
            $msg    = !empty($errors) ? ($errors[0]['message'] ?? 'Unknown error') : 'Unknown Cloudflare error';
            throw new RuntimeException('Cloudflare API error: ' . $msg);
        }

        return $decoded;
    }
}
