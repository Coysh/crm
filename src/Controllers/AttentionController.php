<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\Attention;
use CoyshCRM\Services\Renewals;
use PDO;

/**
 * /today — the prioritised attention list — plus the JSON endpoints behind
 * its Snooze / Dismiss / Mark renewed buttons (public/js/attention.js).
 */
class AttentionController
{
    private const SNOOZE_DAYS = [7, 30, 90];

    public function __construct(private PDO $db) {}

    public function today(): void
    {
        $showSnoozed = ($_GET['snoozed'] ?? '') === '1';
        $items       = (new Attention($this->db))->items(true, $showSnoozed);
        if ($showSnoozed) {
            $items = array_values(array_filter($items, fn($i) => $i['snoozed']));
        }

        $counts = array_fill_keys(array_keys(Attention::SEVERITIES), 0);
        foreach ($items as $i) $counts[$i['severity']]++;

        $breadcrumbs = [['Today', null]];
        render('attention.today', compact('items', 'counts', 'showSnoozed', 'breadcrumbs'), 'Today');
    }

    public function snooze(): void
    {
        if (!csrfCheck()) $this->json(['error' => 'Invalid CSRF token'], 419);

        $key  = trim((string)($_POST['key'] ?? ''));
        $days = $_POST['days'] ?? '';
        if ($key === '' || strlen($key) > 255) $this->json(['error' => 'Missing key'], 422);

        if ($days === 'dismiss') {
            $days = null;
        } elseif (in_array((int)$days, self::SNOOZE_DAYS, true)) {
            $days = (int)$days;
        } else {
            $this->json(['error' => 'Invalid snooze period'], 422);
        }

        (new Attention($this->db))->snooze($key, $days);
        $this->json(['ok' => true, 'message' => $days === null ? 'Dismissed' : "Snoozed for {$days} days"]);
    }

    public function unsnooze(): void
    {
        if (!csrfCheck()) $this->json(['error' => 'Invalid CSRF token'], 419);
        $key = trim((string)($_POST['key'] ?? ''));
        if ($key === '') $this->json(['error' => 'Missing key'], 422);

        (new Attention($this->db))->unsnooze($key);
        $this->json(['ok' => true, 'message' => 'Restored']);
    }

    public function renew(): void
    {
        if (!csrfCheck()) $this->json(['error' => 'Invalid CSRF token'], 419);

        $type = (string)($_POST['type'] ?? '');
        $id   = (int)($_POST['id'] ?? 0);
        if (!in_array($type, Renewals::RENEWABLE, true) || $id <= 0) {
            $this->json(['error' => 'Invalid renewal'], 422);
        }

        try {
            $r = (new Renewals($this->db))->markRenewed($type, $id);
        } catch (\Throwable $e) {
            $this->json(['error' => $e->getMessage()], 422);
        }
        Attention::forgetBadge();
        $this->json(['ok' => true, 'message' => "{$r['name']} renewed — next due " . formatDate($r['new']), 'new_date' => $r['new']]);
    }

    private function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
