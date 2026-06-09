<?php

declare(strict_types=1);

/**
 * Generate a 32-byte application encryption key (base64).
 *
 *   php scripts/gen-key.php          # print a key to paste into APP_KEY
 *   php scripts/gen-key.php --write  # create data/app.key if absent
 *
 * Secrets::* will auto-create data/app.key on first use anyway; this script is
 * for operators who prefer to manage the key explicitly (e.g. via APP_KEY env).
 */

$key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

if (in_array('--write', $argv, true)) {
    $path = dirname(__DIR__) . '/data/app.key';
    if (is_file($path)) {
        fwrite(STDERR, "Refusing to overwrite existing key at $path\n");
        exit(1);
    }
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, $key, LOCK_EX);
    chmod($path, 0600);
    echo "Wrote new key to $path\n";
    exit(0);
}

echo "APP_KEY=$key\n";
