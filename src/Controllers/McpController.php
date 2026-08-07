<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\McpTools;
use CoyshCRM\Services\OAuthService;
use PDO;

/**
 * MCP endpoint: Streamable HTTP transport, POST-only JSON responses
 * (SSE intentionally not offered — permitted by the spec), stateless.
 * Bearer-token auth against OAuthService; 401 responses carry the
 * WWW-Authenticate header that triggers claude.ai's OAuth discovery.
 */
class McpController
{
    private const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
    private const SERVER_INFO = ['name' => 'coysh-crm', 'version' => '1.0.0'];

    private OAuthService $oauth;
    private McpTools $tools;

    public function __construct(private PDO $db)
    {
        $this->oauth = new OAuthService($db);
        $this->tools = new McpTools($db);
    }

    public function options(): void
    {
        http_response_code(204);
        $this->corsHeaders();
        exit;
    }

    public function get(): void
    {
        // No server-initiated SSE stream offered.
        http_response_code(405);
        header('Allow: POST, OPTIONS');
        $this->corsHeaders();
        exit;
    }

    public function post(): void
    {
        $this->corsHeaders();

        // ── Bearer auth ──────────────────────────────────────────────────
        $token = $this->bearerToken();
        $tokenRow = $token !== null ? $this->oauth->validateAccessToken($token) : null;
        if (!$tokenRow) {
            http_response_code(401);
            header('Content-Type: application/json');
            header('WWW-Authenticate: Bearer resource_metadata="' . appUrl() . '/.well-known/oauth-protected-resource", error="invalid_token"');
            echo json_encode(['error' => 'invalid_token', 'error_description' => 'A valid bearer token is required']);
            exit;
        }

        // ── Rate limit (per token) ───────────────────────────────────────
        if ($this->oauth->isRateLimited('token_id', (int)$tokenRow['id'], 120, 300)) {
            http_response_code(429);
            header('Content-Type: application/json');
            header('Retry-After: 60');
            echo json_encode(['error' => 'rate_limited']);
            exit;
        }

        $raw  = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if ($body === null) {
            $this->respond(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }

        // Batch (array) or single message
        $isBatch  = array_is_list($body) && $body !== [];
        $messages = $isBatch ? $body : [$body];

        $responses = [];
        foreach ($messages as $msg) {
            if (!is_array($msg)) continue;
            $this->oauth->logRequest((int)$tokenRow['id'], $_SERVER['REMOTE_ADDR'] ?? '', (string)($msg['method'] ?? ''), $msg['params']['name'] ?? null);
            $resp = $this->handle($msg);
            if ($resp !== null) $responses[] = $resp;
        }

        if (!$responses) {
            // Notifications only — accepted, no body.
            http_response_code(202);
            exit;
        }
        $this->respond($isBatch ? $responses : $responses[0]);
    }

    // ── JSON-RPC dispatch ────────────────────────────────────────────────

    private function handle(array $msg): ?array
    {
        $method = $msg['method'] ?? null;
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        $id     = $msg['id'] ?? null;
        $isNotification = !array_key_exists('id', $msg);

        if (!is_string($method)) {
            return $isNotification ? null : $this->error($id, -32600, 'Invalid request: method missing');
        }

        // Notifications need no response.
        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => in_array($params['protocolVersion'] ?? '', self::PROTOCOL_VERSIONS, true)
                        ? $params['protocolVersion']
                        : self::PROTOCOL_VERSIONS[0],
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo'   => self::SERVER_INFO,
                    'instructions' => 'Coysh Digital CRM: query clients, P&L, agreements/SLAs (with remaining support hours), domains, renewals, and site uptime/TLS monitoring. Write tools can log SLA work and append client notes.',
                ],
                'ping'       => new \stdClass(),
                'tools/list' => ['tools' => $this->tools->list()],
                'tools/call' => $this->callTool($params),
                default      => throw new McpMethodNotFound("Method not found: $method"),
            };
            return $isNotification ? null : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (McpMethodNotFound $e) {
            return $isNotification ? null : $this->error($id, -32601, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $isNotification ? null : $this->error($id, -32602, $e->getMessage());
        } catch (\Throwable $e) {
            return $isNotification ? null : $this->error($id, -32603, 'Internal error: ' . $e->getMessage());
        }
    }

    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Tool name is required');
        }

        try {
            $data = $this->tools->call($name, $args);
            return [
                'content' => [['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]],
                'isError' => false,
            ];
        } catch (\InvalidArgumentException $e) {
            // Tool-level errors go back as isError results, not JSON-RPC errors.
            return [
                'content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]],
                'isError' => true,
            ];
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $header = $v; break; }
            }
        }
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function corsHeaders(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, Mcp-Protocol-Version');
        header('Access-Control-Max-Age: 86400');
    }

    private function respond(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}

class McpMethodNotFound extends \RuntimeException {}
