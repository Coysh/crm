<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

/**
 * RFC 6238 Time-based One-Time Passwords (HMAC-SHA1, 6 digits, 30s step).
 * Pure PHP — no external dependency.
 */
final class Totp
{
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const B32     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Generate a new base32 secret (default 160-bit, the RFC-recommended size). */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Verify a code against the secret, allowing ±$window steps for clock drift.
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $counter = (int) floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeForCounter($secret, $counter + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** Build the otpauth:// URI used to render an enrolment QR code. */
    public static function provisioningUri(string $secret, string $label, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $label) . '?' . http_build_query([
            'secret'  => $secret,
            'issuer'  => $issuer,
            'digits'  => self::DIGITS,
            'period'  => self::PERIOD,
            'algorithm' => 'SHA1',
        ]);
    }

    private static function codeForCounter(string $secret, int $counter): string
    {
        $key  = self::base32Decode($secret);
        $bin  = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter
        $hash = hash_hmac('sha1', $bin, $key, true);
        $off  = ord($hash[strlen($hash) - 1]) & 0x0F;
        $part = (
            ((ord($hash[$off]) & 0x7F) << 24) |
            ((ord($hash[$off + 1]) & 0xFF) << 16) |
            ((ord($hash[$off + 2]) & 0xFF) << 8) |
            (ord($hash[$off + 3]) & 0xFF)
        );
        return str_pad((string)($part % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($data) as $ch) {
            $value = ($value << 8) | ord($ch);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::B32[($value >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $out .= self::B32[($value << (5 - $bits)) & 0x1F];
        }
        return $out;
    }

    private static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
        $out = '';
        $bits = 0;
        $value = 0;
        foreach (str_split($b32) as $ch) {
            $value = ($value << 5) | strpos(self::B32, $ch);
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($value >> $bits) & 0xFF);
            }
        }
        return $out;
    }
}
