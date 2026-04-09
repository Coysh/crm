<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;

class FreeAgentClient
{
    // Docs: authorization endpoint is /v2/approve_app, not /v2/approve_access
    const PROD_BASE  = 'https://api.freeagent.com/v2/';
    const SAND_BASE  = 'https://api.sandbox.freeagent.com/v2/';
    const PROD_AUTH  = 'https://api.freeagent.com/v2/approve_app';
    const SAND_AUTH  = 'https://api.sandbox.freeagent.com/v2/approve_app';
    const TOKEN_PATH = 'token_endpoint';

    private ?array $config = null;

    /** Pages fetched in the last getAll() call — readable by sync methods for logging. */
    public int $lastPageCount = 0;
    /** X-Total-Count from the last page of the last getAll() call (null if header absent). */
    public ?int $lastTotalCount = null;

    public function __construct(private PDO $db) {}

    // ── Config helpers ────────────────────────────────────────────────────

    public function getConfig(): ?array
    {
        if ($this->config !== null) return $this->config;
        $this->config = $this->db->query("SELECT * FROM freeagent_config WHERE id = 1")->fetch() ?: null;
        return $this->config;
    }

    public function isConnected(): bool
    {
        $cfg = $this->getConfig();
        return $cfg && !empty($cfg['access_token']) && !empty($cfg['refresh_token']);
    }

    public function useSandbox(): bool
    {
        $cfg = $this->getConfig();
        return (bool)($cfg['use_sandbox'] ?? false);
    }

    public function getBaseUrl(): string
    {
        return $this->useSandbox() ? self::SAND_BASE : self::PROD_BASE;
    }

    public function getAuthUrl(): string
    {
        return $this->useSandbox() ? self::SAND_AUTH : self::PROD_AUTH;
    }

    public function getTokenUrl(): string
    {
        return $this->getBaseUrl() . self::TOKEN_PATH;
    }

    public function buildAuthorizationUrl(string $redirectUri): string
    {
        $cfg = $this->getConfig();
        if (!$cfg || empty($cfg['client_id'])) {
            throw new RuntimeException('FreeAgent client ID not configured.');
        }
        return $this->getAuthUrl() . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $cfg['client_id'],
            'redirect_uri'  => $redirectUri,
        ]);
    }

    // ── Token management ──────────────────────────────────────────────────

    public function exchangeCodeForTokens(string $code, string $redirectUri): void
    {
        $cfg = $this->getConfig();
        if (!$cfg) throw new RuntimeException('FreeAgent not configured.');

        // Docs: token endpoint requires HTTP Basic Auth (client_id:client_secret),
        // not client credentials in the POST body.
        $response = $this->curlPost($this->getTokenUrl(), [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirectUri,
        ], $cfg['client_id'], $cfg['client_secret']);

        $this->storeTokens($response);
    }

    private function refreshAccessToken(): void
    {
        $cfg = $this->getConfig();
        if (!$cfg || empty($cfg['refresh_token'])) {
            throw new RuntimeException('No refresh token available.');
        }

        $response = $this->curlPost($this->getTokenUrl(), [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $cfg['refresh_token'],
        ], $cfg['client_id'], $cfg['client_secret']);

        $this->storeTokens($response);
    }

    private function storeTokens(array $data): void
    {
        if (empty($data['access_token'])) {
            throw new RuntimeException('Token response missing access_token: ' . json_encode($data));
        }

        $expiresAt = date('Y-m-d H:i:s', time() + (int)($data['expires_in'] ?? 3600));

        $this->db->prepare("
            UPDATE freeagent_config
            SET access_token      = ?,
                refresh_token     = COALESCE(?, refresh_token),
                token_expires_at  = ?
            WHERE id = 1
        ")->execute([
            $data['access_token'],
            $data['refresh_token'] ?? null,
            $expiresAt,
        ]);

        $this->config = null;
    }

    private function ensureFreshToken(): void
    {
        $cfg = $this->getConfig();
        if (!$cfg) throw new RuntimeException('FreeAgent not configured.');

        if (empty($cfg['token_expires_at'])) {
            $this->refreshAccessToken();
            return;
        }

        if (strtotime($cfg['token_expires_at']) - time() < 60) {
            $this->refreshAccessToken();
        }
    }

    // ── HTTP ──────────────────────────────────────────────────────────────

    /**
     * Make an authenticated GET request. Returns decoded JSON body.
     */
    public function get(string $urlOrPath, array $params = []): array
    {
        $this->ensureFreshToken();
        $cfg = $this->getConfig();

        $url = str_starts_with($urlOrPath, 'https://') ? $urlOrPath : $this->getBaseUrl() . ltrim($urlOrPath, '/');
        if ($params) $url .= '?' . http_build_query($params);

        return $this->curlGet($url, $cfg['access_token'])['body'];
    }

    /**
     * Paginate through all pages, collecting items under $key.
     *
     * Primary strategy: follow the Link response header rel="next" (per FreeAgent docs).
     * Fallback strategy: if a full page (per_page items) is returned but no Link header
     * was found, increment the page param explicitly. This guards against endpoints that
     * omit the Link header. Stops when a page returns fewer than per_page items.
     */
    public function getAll(string $path, string $key, array $params = []): array
    {
        $this->ensureFreshToken();
        $cfg     = $this->getConfig();
        $perPage = 100;

        $baseUrl            = $this->getBaseUrl() . ltrim($path, '/');
        $params['per_page'] = $perPage;
        $firstRequest       = true;
        $items              = [];
        $page               = 0;
        $url                = $baseUrl;

        while ($url) {
            $page++;
            $result       = $this->curlGet(
                $firstRequest ? $url . '?' . http_build_query($params) : $url,
                $cfg['access_token']
            );
            $firstRequest = false;
            $pageItems    = $result['body'][$key] ?? [];
            $items        = array_merge($items, $pageItems);

            if ($result['next']) {
                // Explicit next-page URL from Link header
                $url = $result['next'];
            } elseif (count($pageItems) === $perPage) {
                // Full page returned but no Link header — try next page explicitly
                $fallbackParams         = $params;
                $fallbackParams['page'] = $page + 1;
                $url = $baseUrl . '?' . http_build_query($fallbackParams);
            } else {
                $url = null;  // Partial page — this is the last page
            }
        }

        $this->lastPageCount  = $page;
        $this->lastTotalCount = $result['total'] ?? null;  // X-Total-Count from last page
        return $items;
    }

    // ── cURL internals ────────────────────────────────────────────────────

    /**
     * Execute an authenticated GET, returning ['body' => array, 'next' => ?string].
     * Retries on 429 using the Retry-After header value.
     */
    private function curlGet(string $url, string $token, int $attempt = 0): array
    {
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'User-Agent: CoyshCRM/1.0',  // required by FreeAgent docs
            ],
            // Capture response headers via callback so they survive FOLLOWLOCATION
            CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$responseHeaders) {
                $line = trim($header);
                if (str_contains($line, ':')) {
                    [$name, $value]                              = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))]   = trim($value);
                }
                return strlen($header);
            },
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if ($err) throw new RuntimeException("cURL error: $err");

        if ($code === 429) {
            if ($attempt >= 4) throw new RuntimeException("Rate limited after 4 retries on $url");
            // Docs: use Retry-After header; fall back to exponential backoff
            $wait = isset($responseHeaders['retry-after'])
                ? (int)$responseHeaders['retry-after']
                : (int)pow(2, $attempt + 1);
            sleep($wait);
            return $this->curlGet($url, $token, $attempt + 1);
        }

        if ($code >= 400) {
            throw new RuntimeException("API error $code for $url: " . substr((string)$body, 0, 300));
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Invalid JSON response from $url");
        }

        // Docs: pagination is via Link response header, e.g.:
        // Link: <https://...?page=2>; rel="next", <...>; rel="last"
        $nextUrl    = isset($responseHeaders['link'])
            ? $this->parseLinkNext($responseHeaders['link'])
            : null;
        $totalCount = isset($responseHeaders['x-total-count'])
            ? (int)$responseHeaders['x-total-count']
            : null;

        return ['body' => $decoded, 'next' => $nextUrl, 'total' => $totalCount];
    }

    /**
     * POST to token endpoint with HTTP Basic Auth.
     * Docs: "Token endpoint requires HTTP Basic Auth (Client ID as username, Client Secret as password)"
     */
    private function curlPost(string $url, array $fields, string $clientId, string $clientSecret): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => $clientId . ':' . $clientSecret,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: CoyshCRM/1.0',
            ],
        ]);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        if ($err) throw new RuntimeException("cURL error: $err");
        if ($code >= 400) throw new RuntimeException("Token request error $code: " . substr((string)$body, 0, 300));

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) throw new RuntimeException("Invalid token response");

        return $decoded;
    }

    /**
     * Parse the rel="next" URL from an HTTP Link header.
     * Format: <url1>; rel="next", <url2>; rel="prev"
     */
    private function parseLinkNext(string $linkHeader): ?string
    {
        foreach (explode(',', $linkHeader) as $part) {
            if (preg_match('/<([^>]+)>;\s*rel="next"/', trim($part), $m)) {
                return $m[1];
            }
        }
        return null;
    }

    // ── Disconnect ────────────────────────────────────────────────────────

    public function disconnect(): void
    {
        $this->db->exec("
            UPDATE freeagent_config
            SET access_token = NULL, refresh_token = NULL, token_expires_at = NULL, last_sync_at = NULL
            WHERE id = 1
        ");
        $this->config = null;
    }
}
