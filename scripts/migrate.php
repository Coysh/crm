<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$dbPath = $basePath . '/data/crm.db';
$migrationsPath = $basePath . '/migrations';

$db = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
// Wait out concurrent writers (cron, MCP). Foreign keys stay OFF deliberately:
// table-rebuild migrations (e.g. 033) would otherwise cascade-delete child rows.
$db->exec('PRAGMA busy_timeout = 5000');

$db->exec("CREATE TABLE IF NOT EXISTS _migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL UNIQUE,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$applied = $db->query("SELECT filename FROM _migrations")->fetchAll(PDO::FETCH_COLUMN);

$files = glob($migrationsPath . '/*.sql');
sort($files);

$ran = 0;
foreach ($files as $file) {
    $filename = basename($file);
    if (in_array($filename, $applied)) {
        echo "  [skip] $filename\n";
        continue;
    }

    $sql = file_get_contents($file);
    $db->beginTransaction();
    try {
        $db->exec($sql);
        $stmt = $db->prepare("INSERT INTO _migrations (filename) VALUES (?)");
        $stmt->execute([$filename]);
        $db->commit();
        echo "  [ok]   $filename\n";
        $ran++;
    } catch (Exception $e) {
        $db->rollBack();
        echo "  [FAIL] $filename: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo $ran > 0 ? "\n$ran migration(s) applied.\n" : "\nAll migrations already applied.\n";
