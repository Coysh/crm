<?php

declare(strict_types=1);

/**
 * One-off, idempotent migration: encrypt any plaintext API secrets currently
 * stored in the config tables. Safe to re-run — already-encrypted values
 * (prefixed "enc:v1:") are skipped.
 *
 *   php scripts/encrypt-existing-secrets.php
 */

define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');
require BASE_PATH . '/vendor/autoload.php';

use CoyshCRM\Services\Secrets;

$db = new PDO('sqlite:' . DATA_PATH . '/crm.db', null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/** @var array<string, string[]> $targets table => secret columns */
$targets = [
    'ploi_config'       => ['api_token'],
    'cloudflare_config' => ['api_token'],
    'freeagent_config'  => ['access_token', 'refresh_token', 'client_secret'],
    'users'             => ['totp_secret'],
];

$updated = 0;
$skipped = 0;

foreach ($targets as $table => $columns) {
    // Skip tables that don't exist yet.
    try {
        $rows = $db->query("SELECT * FROM $table")->fetchAll();
    } catch (\Throwable) {
        echo "  [skip] table $table not found\n";
        continue;
    }

    foreach ($rows as $row) {
        $set = [];
        $params = [];
        foreach ($columns as $col) {
            if (!array_key_exists($col, $row)) continue;
            $val = $row[$col];
            if ($val === null || $val === '') continue;
            if (Secrets::isEncrypted($val)) { $skipped++; continue; }
            $set[] = "$col = ?";
            $params[] = Secrets::encrypt($val);
        }
        if (!$set) continue;
        $params[] = $row['id'];
        $db->prepare("UPDATE $table SET " . implode(', ', $set) . " WHERE id = ?")->execute($params);
        $updated += count($set);
        echo "  [enc]  $table#{$row['id']} (" . count($set) . " column(s))\n";
    }
}

echo "\nEncrypted $updated value(s); $skipped already encrypted.\n";
