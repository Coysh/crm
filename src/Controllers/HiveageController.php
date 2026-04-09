<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use PDO;

class HiveageController
{
    public function __construct(private PDO $db) {}

    public function index(): void
    {
        $breadcrumbs = [['Settings', '/settings'], ['Hiveage Import', null]];
        render('settings.hiveage_import', ['step' => 1, 'breadcrumbs' => $breadcrumbs], 'Hiveage Import');
    }

    public function upload(): void
    {
        $breadcrumbs = [['Settings', '/settings'], ['Hiveage Import', '/settings/import/hiveage'], ['Preview', null]];

        $file = $_FILES['csv'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            flash('error', 'Upload failed — no file received.');
            redirect('/settings/import/hiveage');
        }

        $tmpPath = $file['tmp_name'];
        $handle  = fopen($tmpPath, 'r');
        if (!$handle) {
            flash('error', 'Could not read uploaded file.');
            redirect('/settings/import/hiveage');
        }

        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) < 2) {
            flash('error', 'CSV appears empty.');
            redirect('/settings/import/hiveage');
        }

        $headers = array_map('trim', array_shift($rows));

        $fieldMap = [
            'reference'   => $this->findColumn($headers, ['statement no', 'invoice number', 'invoice #', 'statement', 'number', 'ref']),
            'client_name' => $this->findColumn($headers, ['client name', 'client', 'company', 'customer']),
            'dated_on'    => $this->findColumn($headers, ['date', 'invoice date', 'created']),
            'due_date'    => $this->findColumn($headers, ['due date', 'due', 'payment due']),
            'total_value' => $this->findColumn($headers, ['billed total', 'total', 'amount', 'invoice total', 'grand total']),
            'status'      => $this->findColumn($headers, ['state', 'status', 'payment status']),
            'currency'    => $this->findColumn($headers, ['currency']),
            'paid_on'     => $this->findColumn($headers, ['paid date', 'paid on', 'last paid on', 'payment date']),
        ];

        // Unique client names
        $clientNames = [];
        if ($fieldMap['client_name'] !== null) {
            foreach ($rows as $row) {
                $name = trim($row[$fieldMap['client_name']] ?? '');
                if ($name) $clientNames[$name] = true;
            }
            $clientNames = array_keys($clientNames);
        }

        // Fuzzy match against CRM clients
        $crmClients = $this->db->query("SELECT id, name FROM clients ORDER BY name")->fetchAll();
        $matches = [];
        foreach ($clientNames as $hiveName) {
            $best = ['client_id' => null, 'client_name' => null, 'score' => 0];
            foreach ($crmClients as $c) {
                $score = $this->fuzzyScore($hiveName, $c['name']);
                if ($score > $best['score']) {
                    $best = ['client_id' => $c['id'], 'client_name' => $c['name'], 'score' => $score];
                }
            }
            $matches[$hiveName] = $best;
        }

        $_SESSION['hiveage_import'] = [
            'headers'  => $headers,
            'rows'     => $rows,
            'fieldMap' => $fieldMap,
            'matches'  => $matches,
        ];

        $preview = array_slice($rows, 0, 10);

        render('settings.hiveage_import', [
            'step'       => 2,
            'breadcrumbs' => $breadcrumbs,
            'headers'    => $headers,
            'fieldMap'   => $fieldMap,
            'preview'    => $preview,
            'matches'    => $matches,
            'crmClients' => $crmClients,
        ], 'Hiveage Import — Preview');
    }

    public function confirm(): void
    {
        $session = $_SESSION['hiveage_import'] ?? null;
        if (!$session) {
            flash('error', 'No import session found. Please restart.');
            redirect('/settings/import/hiveage');
        }

        $rows     = $session['rows'];
        $fieldMap = $session['fieldMap'];

        // Merge user-overridden client matches from POST
        $clientMatches = [];
        foreach ($session['matches'] as $hiveName => $match) {
            $postKey   = 'client_' . md5($hiveName);
            $clientId  = isset($_POST[$postKey]) && $_POST[$postKey] !== '' ? (int)$_POST[$postKey] : ($match['score'] >= 70 ? $match['client_id'] : null);
            $clientMatches[$hiveName] = $clientId;
        }

        $imported = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($rows as $row) {
            try {
                $hiveageId  = $fieldMap['reference'] !== null ? trim($row[$fieldMap['reference']] ?? '') : null;
                if (!$hiveageId) { $skipped++; continue; }

                // Check for existing
                $existStmt = $this->db->prepare("SELECT id FROM freeagent_invoices WHERE hiveage_id = ? LIMIT 1");
                $existStmt->execute([$hiveageId]);
                if ($existStmt->fetch()) { $skipped++; continue; }

                $clientName = $fieldMap['client_name'] !== null ? trim($row[$fieldMap['client_name']] ?? '') : '';
                $clientId   = $clientMatches[$clientName] ?? null;

                $rawAmount = $fieldMap['total_value'] !== null ? ($row[$fieldMap['total_value']] ?? '0') : '0';
                $total     = (float)str_replace(['£', '$', '€', ',', ' '], '', $rawAmount);

                $rawStatus = $fieldMap['status'] !== null ? ($row[$fieldMap['status']] ?? '') : '';
                $status    = $this->mapStatus($rawStatus);

                $datedOn  = $fieldMap['dated_on'] !== null ? $this->parseDate($row[$fieldMap['dated_on']] ?? '') : null;
                $dueDate  = $fieldMap['due_date'] !== null ? $this->parseDate($row[$fieldMap['due_date']] ?? '') : null;
                $paidOn   = $fieldMap['paid_on'] !== null ? $this->parseDate($row[$fieldMap['paid_on']] ?? '') : null;
                $currency = $fieldMap['currency'] !== null ? (strtoupper(trim($row[$fieldMap['currency']] ?? 'GBP')) ?: 'GBP') : 'GBP';

                $faUrl = 'hiveage://invoices/' . $hiveageId;

                $stmt = $this->db->prepare("
                    INSERT INTO freeagent_invoices
                        (freeagent_url, hiveage_id, source, client_id, reference, total_value, currency,
                         status, dated_on, due_date, paid_on, last_synced_at)
                    VALUES (?, ?, 'hiveage', ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
                ");
                $stmt->execute([$faUrl, $hiveageId, $clientId, $hiveageId, $total, $currency, $status, $datedOn, $dueDate, $paidOn]);
                $imported++;
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        unset($_SESSION['hiveage_import']);

        flash('success', "Import complete: {$imported} imported, {$skipped} skipped, {$errors} errors.");
        redirect('/freeagent');
    }

    public function clear(): void
    {
        $this->db->exec("DELETE FROM freeagent_invoices WHERE source = 'hiveage'");
        flash('success', 'All Hiveage invoices removed.');
        redirect('/settings/import/hiveage');
    }

    private function findColumn(array $headers, array $candidates): ?int
    {
        foreach ($headers as $i => $h) {
            $h = strtolower(trim($h));
            foreach ($candidates as $c) {
                if ($h === strtolower($c)) return $i;
            }
        }
        // Partial match fallback
        foreach ($headers as $i => $h) {
            $h = strtolower(trim($h));
            foreach ($candidates as $c) {
                if (str_contains($h, strtolower($c)) || str_contains(strtolower($c), $h)) return $i;
            }
        }
        return null;
    }

    private function fuzzyScore(string $a, string $b): int
    {
        $a = strtolower(trim($a));
        $b = strtolower(trim($b));
        if ($a === $b) return 100;
        similar_text($a, $b, $pct);
        return (int)round($pct);
    }

    private function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (!$raw) return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
        if (preg_match('|^(\d{1,2})/(\d{1,2})/(\d{4})$|', $raw, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function mapStatus(string $raw): string
    {
        return match(strtolower(trim($raw))) {
            'paid'               => 'paid',
            'sent', 'viewed'     => 'sent',
            'overdue', 'past due' => 'overdue',
            default              => 'draft',
        };
    }
}
