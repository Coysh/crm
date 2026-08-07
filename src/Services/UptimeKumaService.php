<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;

/**
 * Read-only client for a self-hosted Uptime Kuma instance.
 *
 * Uptime Kuma has no general-purpose authenticated REST API — the monitor list,
 * uptime percentages and heartbeat history all live behind Socket.io, for which
 * no PHP client exists here. The one authenticated REST surface is GET /metrics
 * (Prometheus text), authenticated with HTTP Basic where the username is ignored
 * and the API key is the password. That endpoint returns every monitor in a
 * single cheap request, which is what makes a 5-minute cron viable.
 *
 * Follows CloudflareService/WpmgrService's file_get_contents() pattern rather
 * than pulling in an HTTP library.
 */
class UptimeKumaService
{
    public function __construct(private PDO $db) {}

    public function getConfig(): ?array
    {
        $row = $this->db->query("SELECT * FROM uptime_kuma_config WHERE id = 1")->fetch() ?: null;
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

        $exists = $this->db->query("SELECT id FROM uptime_kuma_config WHERE id = 1")->fetch();
        if ($exists) {
            $this->db->prepare("UPDATE uptime_kuma_config SET base_url = ?, api_key = ? WHERE id = 1")
                ->execute([$baseUrl, $encrypted]);
            return;
        }
        $this->db->prepare("INSERT INTO uptime_kuma_config (id, base_url, api_key) VALUES (1, ?, ?)")
            ->execute([$baseUrl, $encrypted]);
    }

    public function disconnect(): void
    {
        $this->db->exec("UPDATE uptime_kuma_config SET api_key = NULL, last_sync_at = NULL WHERE id = 1");
    }

    /** Fetches /metrics and reports how many monitors it described. */
    public function validateConnection(): array
    {
        $samples = PrometheusParser::parse($this->fetchMetrics());

        $names = [];
        foreach ($samples as $s) {
            if ($s['metric'] === 'monitor_status' && !empty($s['labels']['monitor_name'])) {
                $names[$s['labels']['monitor_name']] = true;
            }
        }

        return ['ok' => true, 'monitors' => count($names)];
    }

    /**
     * Sub-select giving one row per client_site, for LEFT JOINing onto site
     * lists. A site can carry several monitors (the website, its mail host, a
     * port check), so a plain join would duplicate the site row.
     *
     * MIN(status) is deliberate: the codes run 0=down, 1=up, 2=pending,
     * 3=maintenance, so the worst state wins and one dead monitor is never
     * hidden behind a healthy sibling.
     */
    public static function siteRollupSql(): string
    {
        return "SELECT client_site_id,
                       COUNT(*)        AS monitor_count,
                       MIN(status)     AS status,
                       MIN(uptime_30d) AS uptime_30d
                FROM uptime_kuma_monitors
                WHERE is_stale = 0 AND client_site_id IS NOT NULL
                GROUP BY client_site_id";
    }

    /** Raw Prometheus exposition text for every active monitor. */
    public function fetchMetrics(): string
    {
        return $this->request('GET', '/metrics');
    }

    /** Returns the raw response body — /metrics is text, not JSON. */
    private function request(string $method, string $path): string
    {
        $config = $this->getConfig();
        if (empty($config['api_key']) || empty($config['base_url'])) {
            throw new RuntimeException('Uptime Kuma is not configured.');
        }

        $url = $config['base_url'] . $path;

        // Basic auth with an empty username — Uptime Kuma ignores it and treats
        // the password as the API key.
        $headers = [
            'Authorization: Basic ' . base64_encode(':' . $config['api_key']),
            'Accept: text/plain',
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
            throw new RuntimeException('Uptime Kuma request failed: ' . $path);
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

        if ($httpCode === 401) {
            throw new RuntimeException('Uptime Kuma rejected the API key (401). Check the key and that API keys are enabled under Settings → API Keys.');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Uptime Kuma API error: HTTP ' . $httpCode);
        }

        return $response;
    }
}
