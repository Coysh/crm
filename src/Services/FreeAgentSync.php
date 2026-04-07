<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use Throwable;

class FreeAgentSync
{
    public function __construct(
        private PDO $db,
        private FreeAgentClient $client
    ) {}

    // ── Public sync methods ───────────────────────────────────────────────

    public function syncAll(): array
    {
        $logId = $this->logStart('full');
        $results = ['contacts' => 0, 'invoices' => 0, 'bank_transactions' => 0, 'errors' => []];

        foreach (['contacts', 'invoices', 'bank_transactions'] as $type) {
            try {
                $count = match($type) {
                    'contacts'          => $this->syncContacts(),
                    'invoices'          => $this->syncInvoices(),
                    'bank_transactions' => $this->syncBankTransactions(),
                };
                $results[$type] = $count;
            } catch (Throwable $e) {
                $results['errors'][$type] = $e->getMessage();
            }
        }

        $total = $results['contacts'] + $results['invoices'] + $results['bank_transactions'];
        $error = $results['errors'] ? implode('; ', $results['errors']) : null;
        $status = $error && !$total ? 'failed' : 'completed';
        $this->logComplete($logId, $status, $total, $error);

        // Update last_sync_at
        if ($status === 'completed' || $total > 0) {
            $this->db->exec("UPDATE freeagent_config SET last_sync_at = datetime('now') WHERE id = 1");
        }

        return $results;
    }

    public function syncContacts(): int
    {
        $logId = $this->logStart('contacts');
        try {
            // Docs: view=all includes hidden/archived contacts; updated_since for incremental sync
            $params = ['view' => 'all'];
            $lastSync = $this->getLastSyncDate('contacts');
            if ($lastSync) $params['updated_since'] = $lastSync;

            $contacts = $this->client->getAll('contacts', 'contacts', $params);
            $count    = 0;

            foreach ($contacts as $contact) {
                $url  = $contact['url'] ?? '';
                $name = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
                if (!$name) $name = $contact['organisation_name'] ?? $contact['name'] ?? 'Unknown';
                $email = $contact['email'] ?? null;

                $stmt = $this->db->prepare(
                    "SELECT id, client_id, auto_matched FROM freeagent_contacts WHERE freeagent_url = ?"
                );
                $stmt->execute([$url]);
                $existing = $stmt->fetch() ?: null;

                if ($existing) {
                    // Update name/email but don't touch client_id if manually matched
                    $this->db->prepare("
                        UPDATE freeagent_contacts
                        SET name = ?, email = ?, last_synced_at = datetime('now')
                        WHERE freeagent_url = ?
                    ")->execute([$name, $email, $url]);
                } else {
                    // New contact — attempt auto-match
                    $clientId    = $this->autoMatchClient($name, $email);
                    $autoMatched = $clientId ? 1 : 0;

                    $this->db->prepare("
                        INSERT INTO freeagent_contacts (freeagent_url, name, email, client_id, auto_matched, last_synced_at)
                        VALUES (?, ?, ?, ?, ?, datetime('now'))
                    ")->execute([$url, $name, $email, $clientId, $autoMatched]);
                    $count++;
                }
            }

            $this->logComplete($logId, 'completed', count($contacts));
            return count($contacts);
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', 0, $e->getMessage());
            throw $e;
        }
    }

    public function syncInvoices(): int
    {
        $logId = $this->logStart('invoices');
        try {
            $params = [];
            $lastSync = $this->getLastSyncDate('invoices');
            if ($lastSync) $params['updated_since'] = $lastSync;

            $invoices = $this->client->getAll('invoices', 'invoices', $params);

            foreach ($invoices as $inv) {
                $url        = $inv['url'] ?? '';
                $contactUrl = $inv['contact'] ?? null;
                $clientId   = $contactUrl ? $this->resolveClientFromContact($contactUrl) : null;

                $this->db->prepare("
                    INSERT INTO freeagent_invoices
                        (freeagent_url, freeagent_contact_url, client_id, reference, total_value,
                         currency, status, dated_on, due_date, paid_on, last_synced_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,datetime('now'))
                    ON CONFLICT(freeagent_url) DO UPDATE SET
                        freeagent_contact_url = excluded.freeagent_contact_url,
                        client_id             = excluded.client_id,
                        reference             = excluded.reference,
                        total_value           = excluded.total_value,
                        currency              = excluded.currency,
                        status                = excluded.status,
                        dated_on              = excluded.dated_on,
                        due_date              = excluded.due_date,
                        paid_on               = excluded.paid_on,
                        last_synced_at        = excluded.last_synced_at
                ")->execute([
                    $url, $contactUrl, $clientId,
                    $inv['reference'] ?? null,
                    $inv['total_value'] ?? 0,
                    $inv['currency'] ?? 'GBP',
                    $inv['status'] ?? null,
                    $inv['dated_on'] ?? null,
                    $inv['due_on'] ?? null,
                    $inv['paid_on'] ?? null,
                ]);
            }

            $this->logComplete($logId, 'completed', count($invoices));
            return count($invoices);
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', 0, $e->getMessage());
            throw $e;
        }
    }

    public function syncBankTransactions(): int
    {
        $logId = $this->logStart('bank_transactions');
        try {
            // Get all bank accounts first
            $accountsResp = $this->client->get('bank_accounts');
            $accounts     = $accountsResp['bank_accounts'] ?? [];
            $total        = 0;

            $params = [];
            $lastSync = $this->getLastSyncDate('bank_transactions');
            if ($lastSync) $params['updated_since'] = $lastSync;

            foreach ($accounts as $account) {
                $accountUrl = $account['url'] ?? null;
                if (!$accountUrl) continue;

                $txParams = array_merge($params, ['bank_account' => $accountUrl, 'per_page' => 100]);
                $txns = $this->client->getAll(
                    'bank_transaction_explanations',
                    'bank_transaction_explanations',
                    $txParams
                );

                foreach ($txns as $tx) {
                    $url      = $tx['url'] ?? '';
                    $faCat    = $tx['category'] ?? null;
                    $crmCat   = $faCat ? $this->mapFaCategory($faCat, 'expense') : null;

                    $this->db->prepare("
                        INSERT INTO freeagent_bank_transactions
                            (freeagent_url, description, gross_value, currency,
                             freeagent_category, crm_category, dated_on, bank_account_url, last_synced_at)
                        VALUES (?,?,?,?,?,?,?,?,datetime('now'))
                        ON CONFLICT(freeagent_url) DO UPDATE SET
                            description       = excluded.description,
                            gross_value       = excluded.gross_value,
                            currency          = excluded.currency,
                            freeagent_category= excluded.freeagent_category,
                            crm_category      = excluded.crm_category,
                            dated_on          = excluded.dated_on,
                            bank_account_url  = excluded.bank_account_url,
                            last_synced_at    = excluded.last_synced_at
                    ")->execute([
                        $url,
                        $tx['description'] ?? null,
                        $tx['gross_value'] ?? 0,
                        $tx['currency'] ?? 'GBP',
                        $faCat,
                        $crmCat,
                        $tx['dated_on'] ?? null,
                        $accountUrl,
                    ]);
                }
                $total += count($txns);
            }

            $this->logComplete($logId, 'completed', $total);
            return $total;
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', 0, $e->getMessage());
            throw $e;
        }
    }

    // ── Contact matching ─────────────────────────────────────────────────

    /**
     * Re-run auto-match on unmatched contacts only.
     * Never overwrites manual matches (auto_matched = 0 AND client_id IS NOT NULL).
     */
    public function rematchContacts(): int
    {
        $unmatched = $this->db->query("
            SELECT id, name, email FROM freeagent_contacts
            WHERE client_id IS NULL OR (auto_matched = 1)
        ")->fetchAll();

        $matched = 0;
        foreach ($unmatched as $contact) {
            $clientId = $this->autoMatchClient($contact['name'], $contact['email']);
            if ($clientId) {
                $this->db->prepare("
                    UPDATE freeagent_contacts SET client_id = ?, auto_matched = 1 WHERE id = ?
                ")->execute([$clientId, $contact['id']]);
                $matched++;
            }
        }
        return $matched;
    }

    private function autoMatchClient(string $name, ?string $email): ?int
    {
        // 1. Email match
        if ($email) {
            $row = $this->db->prepare(
                "SELECT id FROM clients WHERE LOWER(contact_email) = LOWER(?) LIMIT 1"
            );
            $row->execute([$email]);
            if ($r = $row->fetch()) return (int)$r['id'];
        }

        // 2. Exact name match (case-insensitive)
        $row = $this->db->prepare(
            "SELECT id FROM clients WHERE LOWER(name) = LOWER(?) LIMIT 1"
        );
        $row->execute([$name]);
        if ($r = $row->fetch()) return (int)$r['id'];

        // 3. Partial name match — FA name contains CRM name or vice versa
        $clients = $this->db->query("SELECT id, name FROM clients WHERE status = 'active'")->fetchAll();
        $nameLower = strtolower($name);
        foreach ($clients as $client) {
            $clientLower = strtolower($client['name']);
            if (str_contains($nameLower, $clientLower) || str_contains($clientLower, $nameLower)) {
                return (int)$client['id'];
            }
        }

        return null;
    }

    private function resolveClientFromContact(string $contactUrl): ?int
    {
        $row = $this->db->prepare(
            "SELECT client_id FROM freeagent_contacts WHERE freeagent_url = ? LIMIT 1"
        );
        $row->execute([$contactUrl]);
        $r = $row->fetch();
        return $r && $r['client_id'] ? (int)$r['client_id'] : null;
    }

    // ── Category mapping ──────────────────────────────────────────────────

    private function mapFaCategory(string $faCategory, string $type): ?string
    {
        $row = $this->db->prepare("
            SELECT local_category FROM freeagent_category_mappings
            WHERE freeagent_category = ? AND type = ?
            LIMIT 1
        ");
        $row->execute([$faCategory, $type]);
        $r = $row->fetch();
        return $r ? $r['local_category'] : null;
    }

    // ── Sync log helpers ──────────────────────────────────────────────────

    private function logStart(string $type): int
    {
        $this->db->prepare("
            INSERT INTO freeagent_sync_log (sync_type, status, started_at)
            VALUES (?, 'running', datetime('now'))
        ")->execute([$type]);
        return (int)$this->db->lastInsertId();
    }

    private function logComplete(int $id, string $status, int $count, ?string $error = null): void
    {
        $this->db->prepare("
            UPDATE freeagent_sync_log
            SET status = ?, records_synced = ?, error_message = ?, completed_at = datetime('now')
            WHERE id = ?
        ")->execute([$status, $count, $error, $id]);
    }

    private function getLastSyncDate(string $type): ?string
    {
        $row = $this->db->prepare("
            SELECT completed_at FROM freeagent_sync_log
            WHERE sync_type = ? AND status = 'completed'
            ORDER BY completed_at DESC LIMIT 1
        ");
        $row->execute([$type]);
        $r = $row->fetch();
        return $r ? $r['completed_at'] : null;
    }
}
