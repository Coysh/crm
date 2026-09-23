<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;

/**
 * Consistent online snapshot via `VACUUM INTO` (safe while web/cron/MCP are
 * writing — it reads inside a single transaction), plus a copy of
 * data/app.key so encrypted secrets in the snapshot stay decryptable.
 *
 * Snapshots live in data/backups/ (gitignored) as crm-YYYYMMDD-HHMMSS.db
 * with a matching .key file.
 */
class Backup
{
    private string $dir;

    public function __construct(private PDO $db, ?string $dir = null)
    {
        $this->dir = rtrim($dir ?? DATA_PATH . '/backups', '/');
    }

    /** @return array{path:string, bytes:int, key_copied:bool, pruned:int} */
    public function run(int $keep = 14): array
    {
        if (!is_dir($this->dir) && !mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new RuntimeException("Cannot create backup directory {$this->dir}");
        }

        $stamp = date('Ymd-His');
        $path  = "{$this->dir}/crm-{$stamp}.db";
        if (file_exists($path)) {
            throw new RuntimeException("Backup {$path} already exists");
        }

        $this->db->prepare('VACUUM INTO ?')->execute([$path]);
        @chmod($path, 0600);

        $check = (new PDO('sqlite:' . $path))->query('PRAGMA quick_check')->fetchColumn();
        if ($check !== 'ok') {
            @unlink($path);
            throw new RuntimeException("Snapshot failed integrity check: {$check}");
        }

        $keyCopied = false;
        $keyFile   = DATA_PATH . '/app.key';
        if (is_file($keyFile)) {
            $keyCopied = copy($keyFile, "{$this->dir}/crm-{$stamp}.key");
            @chmod("{$this->dir}/crm-{$stamp}.key", 0600);
        }

        return [
            'path'       => $path,
            'bytes'      => (int)filesize($path),
            'key_copied' => $keyCopied,
            'pruned'     => $this->prune($keep),
        ];
    }

    /** Newest snapshot's modification time, or null if none exist. */
    public function latest(): ?int
    {
        $files = glob($this->dir . '/crm-*.db') ?: [];
        return $files ? max(array_map('filemtime', $files)) : null;
    }

    private function prune(int $keep): int
    {
        $files = glob($this->dir . '/crm-*.db') ?: [];
        rsort($files); // timestamped names sort chronologically
        $pruned = 0;
        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            @unlink(preg_replace('/\.db$/', '.key', $old));
            $pruned++;
        }
        return $pruned;
    }
}
