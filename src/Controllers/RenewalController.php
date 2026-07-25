<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\Renewals;
use PDO;

class RenewalController
{
    public function __construct(private PDO $db) {}

    public function index(): void
    {
        $timeframe = (int)($_GET['timeframe'] ?? 90);
        $timeframe = in_array($timeframe, [30, 60, 90, 180, 365]) ? $timeframe : 90;

        $type = $_GET['type'] ?? 'all';
        $type = in_array($type, Renewals::TYPES) ? $type : 'all';

        $clientId = ($_GET['client'] ?? '') !== '' ? (int)$_GET['client'] : null;

        $renewals = (new Renewals($this->db))->fetch($timeframe, $type, $clientId);

        // Group by calendar month for display
        $grouped = [];
        foreach ($renewals as $r) {
            $key = $r['days_diff'] < 0 ? 'overdue' : substr($r['due_date'], 0, 7);
            $grouped[$key][] = $r;
        }

        $clients = $this->db->query("SELECT id, name FROM clients WHERE status = 'active' ORDER BY name")->fetchAll();

        $breadcrumbs = [['Renewals', null]];
        render('renewals.index', compact('renewals', 'grouped', 'timeframe', 'type', 'clientId', 'clients', 'breadcrumbs'), 'Renewals');
    }
}
