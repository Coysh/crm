<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use RuntimeException;

/**
 * Minimal Socket.IO v4 / Engine.IO v4 client, over HTTP long-polling.
 *
 * Uptime Kuma's REST surface is read-only (`/metrics`, a Prometheus exporter).
 * Everything else — the monitor list, uptime figures, and creating a monitor —
 * is Socket.IO only. No PHP Socket.IO client is bundled with this project and
 * none of the available ones handle v4 acks well, so this implements just the
 * slice Uptime Kuma needs, in the same hand-rolled style as CloudflareService.
 *
 * Polling rather than WebSocket is deliberate: the server advertises
 * `upgrades: ["websocket"]`, meaning WebSocket is an optional upgrade, not a
 * requirement. Polling keeps this to plain HTTP — no new extension, no new
 * dependency, and it works through the same proxies as the rest of the app.
 *
 * Wire format, for anyone maintaining this:
 *   - a polling response holds several packets separated by 0x1e
 *   - the first char is the Engine.IO type: 0 open, 1 close, 2 ping, 3 pong,
 *     4 message
 *   - inside a message the next char is the Socket.IO type: 0 connect,
 *     1 disconnect, 2 event, 3 ack, 4 connect_error
 *   - an event wanting a reply carries an id: `42<id>["name",args…]`, and the
 *     server answers `43<id>[result]`
 */
final class UptimeKumaSocket
{
    private const SEP = "\x1e";

    private ?string $sid = null;
    private int $ackSeq = 0;

    /**
     * Every server-pushed event, in arrival order, keyed by event name.
     *
     * A list rather than the latest value because Uptime Kuma fires `uptime`,
     * `avgPing` and `heartbeatList` once **per monitor** — keeping only the last
     * would silently reduce a whole fleet to one monitor's figures.
     */
    private array $inbox = [];

    /** Acks received but not yet claimed, keyed by ack id. */
    private array $acks = [];

    public function __construct(
        private string $baseUrl,
        private int $timeout = 30,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /** Engine.IO handshake followed by the Socket.IO namespace connect. */
    public function connect(): void
    {
        $open = $this->http('GET', $this->url(['transport' => 'polling', 't' => $this->stamp()]));
        $packets = $this->split($open);

        if (!$packets || $packets[0][0] !== '0') {
            throw new RuntimeException('Uptime Kuma did not complete the Socket.IO handshake.');
        }

        $handshake = json_decode(substr($packets[0], 1), true);
        $this->sid = $handshake['sid'] ?? null;
        if (!$this->sid) {
            throw new RuntimeException('Uptime Kuma handshake returned no session id.');
        }

        $this->http('POST', $this->url(), '40');
        $this->drain($this->http('GET', $this->url(['t' => $this->stamp()])));
    }

    /**
     * Reuse a JWT from an earlier password login. Returns false rather than
     * throwing when the token has been invalidated (an Uptime Kuma password
     * change does that), so callers can fall back to a password login.
     */
    public function loginByToken(string $jwt): bool
    {
        $res = $this->emit('loginByToken', [$jwt]);
        return is_array($res) && !empty($res['ok']);
    }

    /**
     * @return string The JWT to cache, so later runs can skip the password.
     * @throws RuntimeException with a message safe to show the user.
     */
    public function login(string $username, string $password, string $totp = ''): string
    {
        $res = $this->emit('login', [[
            'username' => $username,
            'password' => $password,
            'token'    => $totp,
        ]]);

        if (!is_array($res)) {
            throw new RuntimeException('Uptime Kuma gave no response to the login request.');
        }

        if (!empty($res['tokenRequired'])) {
            throw new RuntimeException('Uptime Kuma wants a 2FA code. Enter the current code from your authenticator to connect.');
        }

        if (empty($res['ok'])) {
            throw new RuntimeException(self::loginError((string)($res['msg'] ?? '')));
        }

        if (empty($res['token'])) {
            throw new RuntimeException('Uptime Kuma accepted the login but returned no token.');
        }

        return (string)$res['token'];
    }

    /** Uptime Kuma returns i18n keys, not sentences. */
    private static function loginError(string $msg): string
    {
        return match ($msg) {
            'authIncorrectCreds' => 'Uptime Kuma rejected the username or password.',
            'authInvalidToken'   => 'Uptime Kuma rejected the 2FA code.',
            'authUserInactive'   => 'That Uptime Kuma user is inactive.',
            ''                   => 'Uptime Kuma rejected the login.',
            default              => 'Uptime Kuma rejected the login (' . $msg . ').',
        };
    }

    /**
     * Emit an event and wait for its ack. Anything the server pushes while we
     * wait is kept in the inbox for collect().
     */
    public function emit(string $event, array $args = [])
    {
        $this->requireConnected();

        $id = $this->ackSeq++;
        $this->http('POST', $this->url(), '42' . $id . json_encode(array_merge([$event], $args), JSON_UNESCAPED_SLASHES));

        $deadline = time() + $this->timeout;
        while (time() <= $deadline) {
            if (array_key_exists($id, $this->acks)) {
                $ack = $this->acks[$id];
                unset($this->acks[$id]);
                // Callbacks are called with a single argument throughout Uptime Kuma.
                return is_array($ack) && count($ack) === 1 ? $ack[0] : $ack;
            }
            $this->drain($this->http('GET', $this->url(['t' => $this->stamp()])));
        }

        throw new RuntimeException("Uptime Kuma did not respond to '$event' within {$this->timeout}s.");
    }

    /**
     * Poll until every named event has arrived, or the timeout expires.
     * Uptime Kuma pushes monitorList/uptime/heartbeatList unprompted after a
     * successful login, so there is nothing to request — only to wait for.
     *
     * @param string[] $events
     */
    public function collect(array $events, int $seconds = 15): void
    {
        $this->requireConnected();
        $deadline = time() + $seconds;

        while (time() <= $deadline) {
            foreach ($events as $event) {
                if (!array_key_exists($event, $this->inbox)) {
                    $this->drain($this->http('GET', $this->url(['t' => $this->stamp()])));
                    continue 2;
                }
            }
            return;
        }
    }

    /**
     * Keep polling for a fixed window, collecting whatever arrives.
     *
     * collect() returns the moment each named event has been seen once, which
     * is right for `monitorList` but wrong for the per-monitor events: the
     * first `uptime` arrives long before the last one. Use this after collect()
     * to let the rest land, and stop early once `$until` events have been seen.
     */
    public function drainFor(int $seconds, string $until = '', int $expected = 0): void
    {
        $this->requireConnected();
        $deadline = time() + $seconds;

        while (time() <= $deadline) {
            if ($until !== '' && $expected > 0 && count($this->inbox[$until] ?? []) >= $expected) {
                return;
            }
            $this->drain($this->http('GET', $this->url(['t' => $this->stamp()])));
        }
    }

    /** Most recent payload for a server-pushed event, or null if it never arrived. */
    public function received(string $event)
    {
        $all = $this->inbox[$event] ?? [];
        return $all ? end($all) : null;
    }

    /**
     * Every occurrence of an event, in arrival order. Use this for the
     * per-monitor events (`uptime`, `avgPing`, `heartbeatList`); `received()`
     * only makes sense for the single-shot ones like `monitorList`.
     */
    public function receivedAll(string $event): array
    {
        return $this->inbox[$event] ?? [];
    }

    public function close(): void
    {
        if (!$this->sid) return;
        try {
            $this->http('POST', $this->url(), '41');
        } catch (\Throwable) {
            // Best effort — the session expires on its own anyway.
        }
        $this->sid = null;
    }

    // ── wire handling ────────────────────────────────────────────────────

    /** @return string[] */
    private function split(string $body): array
    {
        return $body === '' ? [] : explode(self::SEP, $body);
    }

    /** Interpret one polling response: route acks, stash pushed events, answer pings. */
    private function drain(string $body): void
    {
        foreach ($this->split($body) as $packet) {
            if ($packet === '') continue;

            switch ($packet[0]) {
                case '2': // Engine.IO PING — the session is dropped if we don't answer.
                    $this->http('POST', $this->url(), '3');
                    break;

                case '4':
                    $this->handleMessage(substr($packet, 1));
                    break;

                case '1':
                    $this->sid = null;
                    break;
            }
        }
    }

    private function handleMessage(string $packet): void
    {
        if ($packet === '') return;

        $type = $packet[0];
        $rest = substr($packet, 1);

        if ($type === '2') {          // event
            $payload = json_decode($this->stripAckId($rest), true);
            if (is_array($payload) && isset($payload[0]) && is_string($payload[0])) {
                $name = array_shift($payload);
                // Uptime Kuma emits both `event(payload)` and `event(a, b, c)`;
                // keep the whole argument list and let callers pick.
                $this->inbox[$name][] = count($payload) === 1 ? $payload[0] : $payload;
            }
            return;
        }

        if ($type === '3') {          // ack
            if (preg_match('/^(\d+)(.*)$/s', $rest, $m)) {
                $this->acks[(int)$m[1]] = json_decode($m[2], true);
            }
            return;
        }

        if ($type === '4') {          // connect_error
            throw new RuntimeException('Uptime Kuma refused the Socket.IO connection.');
        }
    }

    /** Server-sent events may carry an ack id we don't use; drop it. */
    private function stripAckId(string $rest): string
    {
        return preg_replace('/^\d+(?=\[)/', '', $rest) ?? $rest;
    }

    private function url(array $extra = []): string
    {
        $query = ['EIO' => '4', 'transport' => 'polling'] + $extra;
        if ($this->sid) $query['sid'] = $this->sid;
        return $this->baseUrl . '/socket.io/?' . http_build_query($query);
    }

    private function stamp(): string
    {
        return base_convert((string)random_int(100000, 999999), 10, 36);
    }

    private function requireConnected(): void
    {
        if (!$this->sid) {
            throw new RuntimeException('Not connected to Uptime Kuma.');
        }
    }

    private function http(string $method, string $url, ?string $body = null): string
    {
        $opts = [
            'http' => [
                'method'        => $method,
                'ignore_errors' => true,
                'timeout'       => $this->timeout,
                'header'        => "Content-Type: text/plain;charset=UTF-8\r\nAccept: */*",
            ],
        ];
        if ($body !== null) {
            $opts['http']['content'] = $body;
        }

        $response = @file_get_contents($url, false, stream_context_create($opts));
        if ($response === false) {
            throw new RuntimeException('Uptime Kuma Socket.IO request failed. Check the base URL is reachable.');
        }

        $code = 0;
        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? []);  // @phpstan-ignore-line
        foreach ($headers as $h) {
            if (preg_match('/HTTP\/\S+\s+(\d+)/', $h, $m)) $code = (int)$m[1];
        }

        // A stale/expired session id is the one error worth naming — it is what
        // a long-running sync hits if the server restarts mid-run.
        if ($code === 400) {
            throw new RuntimeException('Uptime Kuma closed the Socket.IO session (it may have restarted).');
        }
        if ($code >= 400) {
            throw new RuntimeException('Uptime Kuma Socket.IO error: HTTP ' . $code);
        }

        return $response;
    }
}
