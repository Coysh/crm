<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use RuntimeException;
use Throwable;

class PloiService
{
    public function __construct(private PDO $db) {}

    public function getConfig(): ?array
    {
        return $this->db->query("SELECT * FROM ploi_config WHERE id = 1")->fetch() ?: null;
    }

    public function saveToken(string $token): void
    {
        $exists = $this->db->query("SELECT id FROM ploi_config WHERE id = 1")->fetch();
        if ($exists) {
            $this->db->prepare("UPDATE ploi_config SET api_token = ? WHERE id = 1")->execute([$token]);
            return;
        }

        $this->db->prepare("INSERT INTO ploi_config (id, api_token) VALUES (1, ?)")->execute([$token]);
    }

    public function disconnect(): void
    {
        $this->db->exec("UPDATE ploi_config SET api_token = NULL, last_sync_at = NULL WHERE id = 1");
    }

    public function isConnected(): bool
    {
        $cfg = $this->getConfig();
        return !empty($cfg['api_token']);
    }

    public function validateConnection(): array
    {
        $user = $this->getAuthenticatedUser();
        return [
            'ok' => true,
            'name' => $user['name'] ?? ($user['full_name'] ?? 'Unknown'),
            'email' => $user['email'] ?? null,
        ];
    }

    public function getAuthenticatedUser(): array
    {
        $ploi = $this->sdk();
        try {
            $response = $ploi->user()->get();
            return $response['data'] ?? $response;
        } catch (Throwable $e) {
            throw new RuntimeException('Unable to validate Ploi token: ' . $e->getMessage());
        }
    }

    public function sdk(): \Ploi\Ploi
    {
        $cfg = $this->getConfig();
        $token = $cfg['api_token'] ?? null;
        if (!$token) {
            throw new RuntimeException('Ploi is not connected.');
        }
        if (!class_exists(\Ploi\Ploi::class)) {
            throw new RuntimeException('Ploi SDK not installed. Run composer install/require ploi/ploi-php-sdk.');
        }
        return new \Ploi\Ploi($token);
    }
}
