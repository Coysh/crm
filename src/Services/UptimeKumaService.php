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
        return Secrets::decryptRow($row, ['api_key', 'password', 'jwt']);
    }

    /**
     * Connected for reading. Either credential set will do: Socket.IO is the
     * richer source, but an existing /metrics-only setup keeps working.
     */
    public function isConnected(): bool
    {
        $cfg = $this->getConfig();
        if (empty($cfg['base_url'])) return false;
        return !empty($cfg['api_key']) || !empty($cfg['password']) || !empty($cfg['jwt']);
    }

    /** Socket.IO configured — the prerequisite for creating monitors. */
    public function canWrite(): bool
    {
        $cfg = $this->getConfig();
        return !empty($cfg['base_url'])
            && !empty($cfg['username'])
            && (!empty($cfg['password']) || !empty($cfg['jwt']));
    }

    public function saveCredentials(string $baseUrl, string $username, string $password): void
    {
        $baseUrl = self::normaliseBaseUrl($baseUrl);
        $this->ensureRow();
        $this->db->prepare(
            "UPDATE uptime_kuma_config SET base_url = ?, username = ?, password = ?, jwt = NULL WHERE id = 1"
        )->execute([$baseUrl, $username, Secrets::encrypt($password)]);
    }

    /** Cache the JWT a successful login returned, so later runs can skip 2FA. */
    public function storeJwt(string $jwt): void
    {
        $this->ensureRow();
        $this->db->prepare("UPDATE uptime_kuma_config SET jwt = ? WHERE id = 1")
            ->execute([Secrets::encrypt($jwt)]);
    }

    public function clearJwt(): void
    {
        $this->db->exec("UPDATE uptime_kuma_config SET jwt = NULL WHERE id = 1");
    }

    public function setTemplateMonitorId(?int $kumaMonitorId): void
    {
        $this->ensureRow();
        $this->db->prepare("UPDATE uptime_kuma_config SET template_monitor_id = ? WHERE id = 1")
            ->execute([$kumaMonitorId]);
    }

    /**
     * An authenticated Socket.IO session.
     *
     * Tries the cached JWT first — it is cheaper and, on an instance with 2FA
     * enabled, the only thing that works unattended, since a password login
     * would demand a fresh code every run. Falls back to the password when the
     * JWT has been invalidated (changing the Uptime Kuma password does that).
     *
     * Callers must close() the returned socket.
     */
    public function openSession(): UptimeKumaSocket
    {
        $cfg = $this->getConfig();
        if (empty($cfg['base_url'])) {
            throw new RuntimeException('Uptime Kuma is not configured.');
        }

        $socket = new UptimeKumaSocket($cfg['base_url']);
        $socket->connect();

        if (!empty($cfg['jwt']) && $socket->loginByToken($cfg['jwt'])) {
            return $socket;
        }

        if (empty($cfg['username']) || empty($cfg['password'])) {
            $socket->close();
            throw new RuntimeException('Uptime Kuma session expired and no password is stored — reconnect on /settings/uptime-kuma.');
        }

        try {
            $jwt = $socket->login($cfg['username'], $cfg['password']);
        } catch (RuntimeException $e) {
            $socket->close();
            throw $e;
        }

        $this->storeJwt($jwt);
        return $socket;
    }

    private function ensureRow(): void
    {
        if (!$this->db->query("SELECT id FROM uptime_kuma_config WHERE id = 1")->fetch()) {
            $this->db->exec("INSERT INTO uptime_kuma_config (id) VALUES (1)");
        }
    }

    private static function normaliseBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
            $baseUrl = 'https://' . $baseUrl;
        }
        return $baseUrl;
    }

    public function saveConfig(string $baseUrl, string $apiKey): void
    {
        $baseUrl = self::normaliseBaseUrl($baseUrl);
        $this->ensureRow();
        $this->db->prepare("UPDATE uptime_kuma_config SET base_url = ?, api_key = ? WHERE id = 1")
            ->execute([$baseUrl, Secrets::encrypt($apiKey)]);
    }

    public function disconnect(): void
    {
        $this->db->exec(
            "UPDATE uptime_kuma_config
             SET api_key = NULL, username = NULL, password = NULL, jwt = NULL,
                 template_monitor_id = NULL, last_sync_at = NULL
             WHERE id = 1"
        );
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
        // Paused monitors are excluded: a paused monitor is not monitoring, so
        // counting it would make an unwatched site look covered. It still shows
        // on the site detail page, labelled Paused.
        return "SELECT client_site_id,
                       COUNT(*)        AS monitor_count,
                       MIN(status)     AS status,
                       MIN(uptime_30d) AS uptime_30d
                FROM uptime_kuma_monitors
                WHERE is_stale = 0 AND client_site_id IS NOT NULL AND COALESCE(active, 1) = 1
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
