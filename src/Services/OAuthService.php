<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;

/**
 * Minimal OAuth 2.1 authorization server for the MCP endpoint.
 *
 * Public clients only (PKCE S256 mandatory, token_endpoint_auth_method
 * 'none'), dynamic client registration per RFC 7591, refresh-token rotation
 * with family-wide revocation on reuse. Tokens are stored as sha256 hashes.
 */
class OAuthService
{
    public const ACCESS_TTL  = 3600;          // 1 hour
    public const REFRESH_TTL = 30 * 86400;    // 30 days
    public const CODE_TTL    = 300;           // 5 minutes

    public function __construct(private PDO $db) {}

    // ── Clients ──────────────────────────────────────────────────────────

    /** @return array{client_id:string,client_name:?string,redirect_uris:array} */
    public function registerClient(?string $name, array $redirectUris): array
    {
        $valid = [];
        foreach ($redirectUris as $uri) {
            if (!is_string($uri)) continue;
            $parts = parse_url($uri);
            if (!$parts || empty($parts['scheme']) || empty($parts['host']) || isset($parts['fragment'])) continue;
            // https required; allow http only for localhost (native-client loopback)
            $isLoopback = in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
            if ($parts['scheme'] !== 'https' && !($parts['scheme'] === 'http' && $isLoopback)) continue;
            $valid[] = $uri;
        }
        if (!$valid) {
            throw new \InvalidArgumentException('redirect_uris must contain at least one valid https URI');
        }

        $clientId = bin2hex(random_bytes(16));
        $this->db->prepare(
            "INSERT INTO oauth_clients (client_id, client_name, redirect_uris) VALUES (?, ?, ?)"
        )->execute([$clientId, $name !== null ? mb_substr($name, 0, 120) : null, json_encode($valid)]);

        return ['client_id' => $clientId, 'client_name' => $name, 'redirect_uris' => $valid];
    }

    public function findClient(string $clientId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM oauth_clients WHERE client_id = ? LIMIT 1");
        $stmt->execute([$clientId]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['redirect_uris'] = json_decode($row['redirect_uris'], true) ?: [];
        return $row;
    }

    public function isRegisteredRedirect(array $client, string $redirectUri): bool
    {
        return in_array($redirectUri, $client['redirect_uris'], true);
    }

    // ── Authorization codes ──────────────────────────────────────────────

    public function issueCode(string $clientId, string $redirectUri, string $codeChallenge, ?string $scope, ?string $resource): string
    {
        $code = bin2hex(random_bytes(32));
        $this->db->prepare(
            "INSERT INTO oauth_codes (code_hash, client_id, redirect_uri, code_challenge, code_challenge_method, scope, resource, expires_at)
             VALUES (?, ?, ?, ?, 'S256', ?, ?, datetime('now', '+" . self::CODE_TTL . " seconds'))"
        )->execute([hash('sha256', $code), $clientId, $redirectUri, $codeChallenge, $scope, $resource]);
        return $code;
    }

    /**
     * Exchange an authorization code (single-use, PKCE-verified) for tokens.
     * @return array token response payload
     * @throws OAuthException with an RFC 6749 error code
     */
    public function exchangeCode(string $code, string $clientId, string $redirectUri, string $codeVerifier): array
    {
        $stmt = $this->db->prepare("SELECT * FROM oauth_codes WHERE code_hash = ? LIMIT 1");
        $stmt->execute([hash('sha256', $code)]);
        $row = $stmt->fetch();

        if (!$row || $row['used'] || $row['expires_at'] < gmdate('Y-m-d H:i:s')) {
            throw new OAuthException('invalid_grant', 'Authorization code is invalid or expired');
        }
        if (!hash_equals($row['client_id'], $clientId) || !hash_equals($row['redirect_uri'], $redirectUri)) {
            throw new OAuthException('invalid_grant', 'Code was issued to a different client or redirect URI');
        }

        // PKCE S256
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        if (!hash_equals($row['code_challenge'], $computed)) {
            throw new OAuthException('invalid_grant', 'PKCE verification failed');
        }

        $this->db->prepare("UPDATE oauth_codes SET used = 1 WHERE code_hash = ?")->execute([$row['code_hash']]);

        return $this->issueTokenPair($clientId, $row['scope'], bin2hex(random_bytes(16)));
    }

    // ── Tokens ───────────────────────────────────────────────────────────

    private function issueTokenPair(string $clientId, ?string $scope, string $familyId): array
    {
        $access  = 'crm_at_' . bin2hex(random_bytes(32));
        $refresh = 'crm_rt_' . bin2hex(random_bytes(32));

        $ins = $this->db->prepare(
            "INSERT INTO oauth_tokens (token_hash, token_type, client_id, scope, family_id, expires_at)
             VALUES (?, ?, ?, ?, ?, datetime('now', ?))"
        );
        $ins->execute([hash('sha256', $access), 'access', $clientId, $scope, $familyId, '+' . self::ACCESS_TTL . ' seconds']);
        $ins->execute([hash('sha256', $refresh), 'refresh', $clientId, $scope, $familyId, '+' . self::REFRESH_TTL . ' seconds']);

        return [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope'         => $scope ?? '',
        ];
    }

    /**
     * Refresh grant with rotation. Presenting an already-rotated (revoked)
     * refresh token revokes the whole family — stolen-token defence.
     */
    public function refresh(string $refreshToken, string $clientId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM oauth_tokens WHERE token_hash = ? AND token_type = 'refresh' LIMIT 1");
        $stmt->execute([hash('sha256', $refreshToken)]);
        $row = $stmt->fetch();

        if (!$row || !hash_equals($row['client_id'], $clientId)) {
            throw new OAuthException('invalid_grant', 'Refresh token not recognised');
        }
        if ($row['revoked']) {
            // Reuse detected — kill the whole family.
            $this->revokeFamily($row['family_id']);
            throw new OAuthException('invalid_grant', 'Refresh token reuse detected; all tokens revoked');
        }
        if ($row['expires_at'] < gmdate('Y-m-d H:i:s')) {
            throw new OAuthException('invalid_grant', 'Refresh token expired');
        }

        // Rotate: revoke old refresh + any live access tokens in the family,
        // then issue a fresh pair in the same family.
        $this->db->prepare("UPDATE oauth_tokens SET revoked = 1 WHERE id = ?")->execute([$row['id']]);
        $this->db->prepare("UPDATE oauth_tokens SET revoked = 1 WHERE family_id = ? AND token_type = 'access'")->execute([$row['family_id']]);

        return $this->issueTokenPair($clientId, $row['scope'], $row['family_id']);
    }

    /** Validate a bearer access token; returns the token row or null. */
    public function validateAccessToken(string $token): ?array
    {
        if (!str_starts_with($token, 'crm_at_')) return null;
        $stmt = $this->db->prepare(
            "SELECT * FROM oauth_tokens
             WHERE token_hash = ? AND token_type = 'access' AND revoked = 0 AND expires_at > datetime('now')
             LIMIT 1"
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch() ?: null;
        if ($row) {
            $this->db->prepare("UPDATE oauth_tokens SET last_used_at = datetime('now') WHERE id = ?")->execute([$row['id']]);
        }
        return $row;
    }

    public function revokeFamily(string $familyId): void
    {
        $this->db->prepare("UPDATE oauth_tokens SET revoked = 1 WHERE family_id = ?")->execute([$familyId]);
    }

    public function revokeClient(string $clientId): void
    {
        $this->db->prepare("UPDATE oauth_tokens SET revoked = 1 WHERE client_id = ?")->execute([$clientId]);
        $this->db->prepare("DELETE FROM oauth_codes WHERE client_id = ?")->execute([$clientId]);
        $this->db->prepare("DELETE FROM oauth_clients WHERE client_id = ?")->execute([$clientId]);
    }

    /** Opportunistic cleanup of long-expired rows; called from the token endpoint. */
    public function cleanup(): void
    {
        try {
            $this->db->exec("DELETE FROM oauth_codes WHERE expires_at < datetime('now', '-1 day')");
            $this->db->exec("DELETE FROM oauth_tokens WHERE expires_at < datetime('now', '-30 days')");
            $this->db->exec("DELETE FROM mcp_request_log WHERE created_at < datetime('now', '-90 days')");
        } catch (\Throwable) {}
    }

    // ── Rate limiting (fixed window over mcp_request_log) ────────────────

    public function logRequest(?int $tokenId, string $ip, string $method, ?string $toolName = null): void
    {
        try {
            $this->db->prepare("INSERT INTO mcp_request_log (token_id, ip, method, tool_name) VALUES (?, ?, ?, ?)")
                ->execute([$tokenId, $ip, $method, $toolName]);
        } catch (\Throwable) {}
    }

    public function isRateLimited(string $column, string|int $value, int $limit, int $windowSeconds): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM mcp_request_log WHERE $column = ? AND created_at > datetime('now', '-' || ? || ' seconds')"
            );
            $stmt->execute([$value, $windowSeconds]);
            return (int)$stmt->fetchColumn() >= $limit;
        } catch (\Throwable) {
            return false;
        }
    }
}

class OAuthException extends \RuntimeException
{
    public function __construct(public readonly string $error, string $description)
    {
        parent::__construct($description);
    }
}
