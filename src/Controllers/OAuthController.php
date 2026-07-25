<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\OAuthException;
use CoyshCRM\Services\OAuthService;
use PDO;

/**
 * OAuth 2.1 endpoints backing the MCP connector.
 *
 * /.well-known/* , /oauth/register and /oauth/token are public (exempted in
 * routes.php); /oauth/authorize and /oauth/approve sit behind the normal
 * session guard so consent requires the CRM login + TOTP.
 */
class OAuthController
{
    private OAuthService $oauth;

    public function __construct(private PDO $db)
    {
        $this->oauth = new OAuthService($db);
    }

    // ── Discovery metadata ───────────────────────────────────────────────

    public function authServerMetadata(): void
    {
        $base = appUrl();
        $this->json([
            'issuer'                                => $base,
            'authorization_endpoint'                => "$base/oauth/authorize",
            'token_endpoint'                        => "$base/oauth/token",
            'registration_endpoint'                 => "$base/oauth/register",
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported'                      => ['crm'],
        ], cors: true);
    }

    public function protectedResourceMetadata(): void
    {
        $base = appUrl();
        $this->json([
            'resource'              => "$base/mcp",
            'authorization_servers' => [$base],
            'bearer_methods_supported' => ['header'],
            'scopes_supported'      => ['crm'],
        ], cors: true);
    }

    // ── Dynamic client registration (RFC 7591, anonymous) ────────────────

    public function register(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if ($this->oauth->isRateLimited('ip', $ip, 10, 300)) {
            $this->json(['error' => 'too_many_requests'], 429, cors: true, extraHeaders: ['Retry-After: 300']);
        }
        $this->oauth->logRequest(null, $ip, 'oauth/register');

        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body) || empty($body['redirect_uris']) || !is_array($body['redirect_uris'])) {
            $this->json(['error' => 'invalid_client_metadata', 'error_description' => 'redirect_uris (array) is required'], 400, cors: true);
        }

        try {
            $client = $this->oauth->registerClient(
                isset($body['client_name']) && is_string($body['client_name']) ? $body['client_name'] : null,
                $body['redirect_uris']
            );
        } catch (\InvalidArgumentException $e) {
            $this->json(['error' => 'invalid_redirect_uri', 'error_description' => $e->getMessage()], 400, cors: true);
        }

        $this->json([
            'client_id'                  => $client['client_id'],
            'client_name'                => $client['client_name'],
            'redirect_uris'              => $client['redirect_uris'],
            'token_endpoint_auth_method' => 'none',
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
        ], 201, cors: true);
    }

    // ── Authorization + consent (behind session guard) ───────────────────

    public function authorize(): void
    {
        $clientId      = (string)($_GET['client_id'] ?? '');
        $redirectUri   = (string)($_GET['redirect_uri'] ?? '');
        $responseType  = (string)($_GET['response_type'] ?? '');
        $state         = (string)($_GET['state'] ?? '');
        $codeChallenge = (string)($_GET['code_challenge'] ?? '');
        $method        = (string)($_GET['code_challenge_method'] ?? '');
        $scope         = (string)($_GET['scope'] ?? 'crm');
        $resource      = (string)($_GET['resource'] ?? '');

        $client = $clientId !== '' ? $this->oauth->findClient($clientId) : null;

        // Never redirect to an unregistered URI — render the error instead.
        if (!$client || !$this->oauth->isRegisteredRedirect($client, $redirectUri)) {
            http_response_code(400);
            render('oauth.error', ['message' => 'Unknown client or unregistered redirect URI.'], 'Authorisation Error', 'layouts/auth');
            return;
        }

        $deny = function (string $error, string $desc) use ($redirectUri, $state): never {
            $sep = str_contains($redirectUri, '?') ? '&' : '?';
            $qs  = http_build_query(array_filter(['error' => $error, 'error_description' => $desc, 'state' => $state]));
            redirect($redirectUri . $sep . $qs);
        };

        if ($responseType !== 'code')                     $deny('unsupported_response_type', 'Only response_type=code is supported');
        if ($codeChallenge === '' || $method !== 'S256')  $deny('invalid_request', 'PKCE with S256 is required');

        // Stash the validated request server-side; /oauth/approve reads it back.
        $_SESSION['oauth_request'] = [
            'client_id'      => $clientId,
            'client_name'    => $client['client_name'] ?: 'Unnamed application',
            'redirect_uri'   => $redirectUri,
            'state'          => $state,
            'code_challenge' => $codeChallenge,
            'scope'          => $scope,
            'resource'       => $resource !== '' ? $resource : null,
            'created'        => time(),
        ];

        render('oauth.authorize', ['request' => $_SESSION['oauth_request']], 'Authorise Access', 'layouts/auth');
    }

    public function approve(): void
    {
        $req = $_SESSION['oauth_request'] ?? null;
        if (!$req || (time() - ($req['created'] ?? 0)) > 600) {
            flash('error', 'Authorisation request expired — please retry from the connecting app.');
            redirect('/');
        }
        if (!csrfCheck()) {
            flash('error', 'Invalid form token — please try again.');
            redirect('/');
        }
        unset($_SESSION['oauth_request']);

        $sep = str_contains($req['redirect_uri'], '?') ? '&' : '?';

        if (($_POST['decision'] ?? '') !== 'approve') {
            $qs = http_build_query(array_filter(['error' => 'access_denied', 'state' => $req['state']]));
            redirect($req['redirect_uri'] . $sep . $qs);
        }

        $code = $this->oauth->issueCode(
            $req['client_id'], $req['redirect_uri'], $req['code_challenge'], $req['scope'], $req['resource']
        );
        $qs = http_build_query(array_filter(['code' => $code, 'state' => $req['state']]));
        redirect($req['redirect_uri'] . $sep . $qs);
    }

    // ── Token endpoint (public) ──────────────────────────────────────────

    public function token(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if ($this->oauth->isRateLimited('ip', $ip, 20, 300)) {
            $this->json(['error' => 'too_many_requests'], 429, cors: true, extraHeaders: ['Retry-After: 300']);
        }
        $this->oauth->logRequest(null, $ip, 'oauth/token');
        $this->oauth->cleanup();

        $grant    = (string)($_POST['grant_type'] ?? '');
        $clientId = (string)($_POST['client_id'] ?? '');

        if ($clientId === '' || !$this->oauth->findClient($clientId)) {
            $this->json(['error' => 'invalid_client', 'error_description' => 'Unknown client_id'], 401, cors: true);
        }

        try {
            if ($grant === 'authorization_code') {
                $code     = (string)($_POST['code'] ?? '');
                $redirect = (string)($_POST['redirect_uri'] ?? '');
                $verifier = (string)($_POST['code_verifier'] ?? '');
                if ($code === '' || $verifier === '') {
                    throw new OAuthException('invalid_request', 'code and code_verifier are required');
                }
                $this->json($this->oauth->exchangeCode($code, $clientId, $redirect, $verifier), cors: true);
            }

            if ($grant === 'refresh_token') {
                $refresh = (string)($_POST['refresh_token'] ?? '');
                if ($refresh === '') {
                    throw new OAuthException('invalid_request', 'refresh_token is required');
                }
                $this->json($this->oauth->refresh($refresh, $clientId), cors: true);
            }

            throw new OAuthException('unsupported_grant_type', 'Use authorization_code or refresh_token');
        } catch (OAuthException $e) {
            $status = $e->error === 'invalid_client' ? 401 : 400;
            $this->json(['error' => $e->error, 'error_description' => $e->getMessage()], $status, cors: true);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function json(array $payload, int $status = 200, bool $cors = false, array $extraHeaders = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if ($cors) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Protocol-Version');
        }
        foreach ($extraHeaders as $h) header($h);
        echo json_encode($payload);
        exit;
    }
}
