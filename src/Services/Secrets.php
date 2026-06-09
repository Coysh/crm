<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use RuntimeException;

/**
 * Symmetric encryption for secrets at rest (API tokens, TOTP seeds).
 *
 * Uses libsodium's secretbox (XSalsa20-Poly1305). Encrypted values are stored
 * as "enc:v1:" + base64(nonce ‖ ciphertext). Plaintext values (no marker) are
 * passed through unchanged on decrypt, so this is backward-compatible with
 * existing rows and the data migration is idempotent.
 *
 * The 32-byte key is read from the APP_KEY environment variable (base64), or
 * falls back to data/app.key (auto-created, chmod 0600). Key loading is
 * self-contained so CLI scripts work without bootstrap.
 */
final class Secrets
{
    private const MARKER = 'enc:v1:';

    private static ?string $key = null;

    public static function isEncrypted(?string $value): bool
    {
        return is_string($value) && str_starts_with($value, self::MARKER);
    }

    public static function encrypt(string $plain): string
    {
        $key   = self::key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
        return self::MARKER . base64_encode($nonce . $cipher);
    }

    public static function decrypt(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!self::isEncrypted($value)) {
            // Legacy plaintext — return as-is.
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::MARKER)), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Secrets::decrypt — malformed ciphertext.');
        }

        $nonce  = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain  = sodium_crypto_secretbox_open($cipher, $nonce, self::key());

        if ($plain === false) {
            throw new RuntimeException('Secrets::decrypt — decryption failed (wrong key or corrupt data).');
        }
        return $plain;
    }

    /**
     * Decrypt the named columns of a config row in place. Tolerates missing keys
     * and plaintext values. Returns the row (or null untouched).
     */
    public static function decryptRow(?array $row, array $columns): ?array
    {
        if ($row === null) {
            return null;
        }
        foreach ($columns as $col) {
            if (isset($row[$col]) && $row[$col] !== '') {
                $row[$col] = self::decrypt($row[$col]);
            }
        }
        return $row;
    }

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $env = $_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '';
        if (is_string($env) && $env !== '') {
            $decoded = base64_decode($env, true);
            if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException('APP_KEY must be base64 of ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes.');
            }
            return self::$key = $decoded;
        }

        return self::$key = self::loadOrCreateKeyFile();
    }

    private static function loadOrCreateKeyFile(): string
    {
        $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2);
        $path = $base . '/data/app.key';

        if (is_file($path)) {
            $decoded = base64_decode(trim((string)file_get_contents($path)), true);
            if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new RuntimeException("Key file $path is invalid. Delete it only if no secrets are encrypted yet.");
            }
            return $decoded;
        }

        // First run: generate and persist a key.
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create data directory at $dir.");
        }
        $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        if (file_put_contents($path, base64_encode($key), LOCK_EX) === false) {
            throw new RuntimeException("Cannot write key file at $path. Set APP_KEY env instead.");
        }
        @chmod($path, 0600);
        return $key;
    }
}
