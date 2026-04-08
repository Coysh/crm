<?php

declare(strict_types=1);

namespace CoyshCRM\Services;

use PDO;
use Throwable;

class FreeAgentSync
{
    public function __construct(private PDO $db, private FreeAgentClient $client) {}

    public function syncAll(): array
    {
        $logId = $this->logStart('full');
        $results = ['contacts' => 0, 'invoices' => 0, 'bank_transactions' => 0, 'errors' => []];

        foreach (['contacts', 'invoices', 'bank_transactions'] as $type) {
            try {
                $results[$type] = match($type) {
                    'contacts' => $this->syncContacts(),
                    'invoices' => $this->syncInvoices(),
                    'bank_transactions' => $this->syncBankTransactions(),
                };
            } catch (Throwable $e) { $results['errors'][$type] = $e->getMessage(); }
        }

        $total = $results['contacts'] + $results['invoices'] + $results['bank_transactions'];
        $error = $results['errors'] ? implode('; ', $results['errors']) : null;
        $status = $error && !$total ? 'failed' : 'completed';
        $this->logComplete($logId, $status, $total, $error);
        if ($status === 'completed' || $total > 0) $this->db->exec("UPDATE freeagent_config SET last_sync_at = datetime('now') WHERE id = 1");

        return $results;
    }

    public function syncContacts(): int
    {
        $logId = $this->logStart('contacts');
        try {
            $contacts = $this->client->getAll('contacts', 'contacts', ['view' => 'all']);
            foreach ($contacts as $contact) {
                $url = $contact['url'] ?? '';
                if (!$url) continue;
                $org = trim((string)($contact['organisation_name'] ?? ''));
                $person = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
                $name = $org ?: ($person ?: ($contact['name'] ?? 'Unknown'));
                $email = $contact['email'] ?? null;

                $existing = $this->db->prepare("SELECT id FROM freeagent_contacts WHERE freeagent_url = ?");
                $existing->execute([$url]);
                if ($existing->fetch()) {
                    $this->db->prepare("UPDATE freeagent_contacts SET name = ?, organisation_name = ?, email = ?, last_synced_at = datetime('now') WHERE freeagent_url = ?")
                        ->execute([$name, $org ?: null, $email, $url]);
                } else {
                    $this->db->prepare("INSERT INTO freeagent_contacts (freeagent_url, name, organisation_name, email, last_synced_at) VALUES (?, ?, ?, ?, datetime('now'))")
                        ->execute([$url, $name, $org ?: null, $email]);
                }
            }

            $this->hydrateInvoiceClientLinks();
            $this->logComplete($logId, 'completed', count($contacts));
            return count($contacts);
        } catch (Throwable $e) { $this->logComplete($logId, 'failed', 0, $e->getMessage()); throw $e; }
    }

    public function syncInvoices(): int
    {
        $logId = $this->logStart('invoices');
        try {
            $invoices = $this->client->getAll('invoices', 'invoices');
            foreach ($invoices as $inv) {
                $contactUrl = $inv['contact'] ?? null;
                $clientId = $contactUrl ? $this->ensureClientForContact($contactUrl) : null;
                $this->db->prepare("INSERT INTO freeagent_invoices (freeagent_url, freeagent_contact_url, client_id, reference, total_value, currency, status, dated_on, due_date, paid_on, category, last_synced_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime('now')) ON CONFLICT(freeagent_url) DO UPDATE SET freeagent_contact_url=excluded.freeagent_contact_url, client_id=excluded.client_id, reference=excluded.reference, total_value=excluded.total_value, currency=excluded.currency, status=excluded.status, dated_on=excluded.dated_on, due_date=excluded.due_date, paid_on=excluded.paid_on, category=excluded.category, last_synced_at=excluded.last_synced_at")
                    ->execute([$inv['url'] ?? '', $contactUrl, $clientId, $inv['reference'] ?? null, $inv['total_value'] ?? 0, $inv['currency'] ?? 'GBP', $inv['status'] ?? null, $inv['dated_on'] ?? null, $inv['due_on'] ?? null, $inv['paid_on'] ?? null, $inv['category'] ?? null]);
            }
            $this->logComplete($logId, 'completed', count($invoices));
            return count($invoices);
        } catch (Throwable $e) { $this->logComplete($logId, 'failed', 0, $e->getMessage()); throw $e; }
    }

    public function syncBankTransactions(): int
    {
        $logId = $this->logStart('bank_transactions');
        try {
            $accounts = $this->client->get('bank_accounts')['bank_accounts'] ?? [];
            $total = 0;
            foreach ($accounts as $account) {
                $accountUrl = $account['url'] ?? null; if (!$accountUrl) continue;
                $txns = $this->client->getAll('bank_transaction_explanations', 'bank_transaction_explanations', ['bank_account' => $accountUrl]);
                foreach ($txns as $tx) {
                    $rawCat = $tx['category'] ?? null;
                    $display = is_string($rawCat) ? $rawCat : ($rawCat['description'] ?? ($rawCat['url'] ?? null));
                    $crmCat = $display ? $this->mapFaCategory($display, 'expense') : null;
                    if (!$crmCat && (float)($tx['gross_value'] ?? 0) < 0) $crmCat = 'software';

                    $this->db->prepare("INSERT INTO freeagent_bank_transactions (freeagent_url, description, gross_value, currency, freeagent_category, freeagent_category_display, crm_category, dated_on, bank_account_url, last_synced_at) VALUES (?,?,?,?,?,?,?,?,?,datetime('now')) ON CONFLICT(freeagent_url) DO UPDATE SET description=excluded.description, gross_value=excluded.gross_value, currency=excluded.currency, freeagent_category=excluded.freeagent_category, freeagent_category_display=excluded.freeagent_category_display, crm_category=excluded.crm_category, dated_on=excluded.dated_on, bank_account_url=excluded.bank_account_url, last_synced_at=excluded.last_synced_at")
                        ->execute([$tx['url'] ?? '', $tx['description'] ?? null, $tx['gross_value'] ?? 0, $tx['currency'] ?? 'GBP', is_string($rawCat)?$rawCat:($rawCat['url']??null), $display, $crmCat, $tx['dated_on'] ?? null, $accountUrl]);
                }
                $total += count($txns);
            }
            $this->logComplete($logId, 'completed', $total);
            return $total;
        } catch (Throwable $e) { $this->logComplete($logId, 'failed', 0, $e->getMessage()); throw $e; }
    }

    public function rematchContacts(): int { $contacts = $this->db->query("SELECT id, name, organisation_name, email FROM freeagent_contacts")->fetchAll(); $m=0; foreach($contacts as $c){$clientId=$this->autoMatchClient($c['organisation_name'] ?: $c['name'], $c['email']); if($clientId){$this->db->prepare("UPDATE freeagent_contacts SET client_id = ?, auto_matched = 1 WHERE id = ?")->execute([$clientId,$c['id']]);$m++;}} return $m; }

    private function ensureClientForContact(string $contactUrl): ?int
    {
        $stmt = $this->db->prepare("SELECT id, name, organisation_name, email, client_id FROM freeagent_contacts WHERE freeagent_url = ? LIMIT 1");
        $stmt->execute([$contactUrl]); $c = $stmt->fetch();
        if (!$c) return null;
        if (!empty($c['client_id'])) return (int)$c['client_id'];

        $baseName = trim((string)($c['organisation_name'] ?: $c['name'] ?: 'Unknown'));
        $clientId = $this->autoMatchClient($baseName, $c['email'] ?? null);
        if (!$clientId) {
            $this->db->prepare("INSERT INTO clients (name, contact_name, contact_email, status, created_at, updated_at) VALUES (?, ?, ?, 'active', datetime('now'), datetime('now'))")
                ->execute([$baseName, $c['name'] ?? null, $c['email'] ?? null]);
            $clientId = (int)$this->db->lastInsertId();
        }

        $this->db->prepare("UPDATE freeagent_contacts SET client_id = ?, auto_matched = 1 WHERE id = ?")->execute([$clientId, $c['id']]);
        return $clientId;
    }

    private function hydrateInvoiceClientLinks(): void
    {
        $rows = $this->db->query("SELECT DISTINCT freeagent_contact_url FROM freeagent_invoices WHERE freeagent_contact_url IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $url) {
            $id = $this->ensureClientForContact((string)$url);
            if ($id) $this->db->prepare("UPDATE freeagent_invoices SET client_id = ? WHERE freeagent_contact_url = ?")->execute([$id, $url]);
        }
    }

    private function autoMatchClient(string $name, ?string $email): ?int
    {
        if ($email) { $row = $this->db->prepare("SELECT id FROM clients WHERE LOWER(contact_email) = LOWER(?) LIMIT 1"); $row->execute([$email]); if ($r=$row->fetch()) return (int)$r['id']; }
        $row = $this->db->prepare("SELECT id FROM clients WHERE LOWER(name) = LOWER(?) LIMIT 1"); $row->execute([$name]); if ($r=$row->fetch()) return (int)$r['id'];
        return null;
    }

    private function mapFaCategory(string $faCategory, string $type): ?string { $row = $this->db->prepare("SELECT local_category FROM freeagent_category_mappings WHERE freeagent_category = ? AND type = ? LIMIT 1"); $row->execute([$faCategory,$type]); $r=$row->fetch(); return $r?$r['local_category']:null; }
    private function logStart(string $type): int { $this->db->prepare("INSERT INTO freeagent_sync_log (sync_type, status, started_at) VALUES (?, 'running', datetime('now'))")->execute([$type]); return (int)$this->db->lastInsertId(); }
    private function logComplete(int $id, string $status, int $count, ?string $error = null): void { $this->db->prepare("UPDATE freeagent_sync_log SET status = ?, records_synced = ?, error_message = ?, completed_at = datetime('now') WHERE id = ?")->execute([$status,$count,$error,$id]); }
}
