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
        $results = ['contacts' => 0, 'recurring_invoices' => 0, 'invoices' => 0, 'bills' => 0, 'bank_transactions' => 0, 'expenses' => 0, 'errors' => []];

        foreach (['contacts', 'recurring_invoices', 'invoices', 'bills', 'bank_transactions', 'expenses'] as $type) {
            try {
                $results[$type] = match($type) {
                    'contacts'           => $this->syncContacts(),
                    'recurring_invoices' => $this->syncRecurringInvoices(),
                    'invoices'           => $this->syncInvoices(),
                    'bills'              => $this->syncBills(),
                    'bank_transactions'  => $this->syncBankTransactions(),
                    'expenses'           => $this->syncExpenses(),
                };
            } catch (Throwable $e) { $results['errors'][$type] = $e->getMessage(); }
        }

        $total = $results['contacts'] + $results['recurring_invoices'] + $results['invoices'] + $results['bills'] + $results['bank_transactions'] + $results['expenses'];
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

            // Auto-create CRM clients for any contact still unmatched after upsert
            $pages   = $this->client->lastPageCount;
            $total   = $this->client->lastTotalCount;
            $created = $this->createClientsForUnmatched();
            $this->hydrateInvoiceClientLinks();

            $notes = [];
            $notes[] = "{$pages} page(s) fetched";
            if ($total !== null) $notes[] = "FA reports {$total} total";
            if ($created > 0) $notes[] = "auto-created {$created} client(s)";
            $this->logComplete($logId, 'completed', count($contacts), implode('; ', $notes));
            return count($contacts);
        } catch (Throwable $e) { $this->logComplete($logId, 'failed', 0, $e->getMessage()); throw $e; }
    }

    /**
     * Create CRM clients for every FA contact that has no client_id.
     * Safe to call multiple times — idempotent via name deduplication in ensureClientForContact().
     */
    public function createClientsForUnmatched(): int
    {
        $urls = $this->db->query(
            "SELECT freeagent_url FROM freeagent_contacts WHERE client_id IS NULL"
        )->fetchAll(PDO::FETCH_COLUMN);

        $created = 0;
        foreach ($urls as $url) {
            // Re-check client_id hasn't been set by a concurrent call
            $stmt = $this->db->prepare("SELECT client_id FROM freeagent_contacts WHERE freeagent_url = ? LIMIT 1");
            $stmt->execute([$url]);
            if ($stmt->fetchColumn()) continue;

            $this->ensureClientForContact($url);
            $created++;
        }
        return $created;
    }

    public function syncRecurringInvoices(): int
    {
        $logId = $this->logStart('recurring_invoices');
        try {
            $seen = [];

            $pageNotes = [];
            foreach (['active', 'draft'] as $view) {
                $items = $this->client->getAll('recurring_invoices', 'recurring_invoices', ['view' => $view]);
                $pageNotes[] = ucfirst($view) . ': ' . count($items) . ' (' . $this->client->lastPageCount . 'p)';
                foreach ($items as $ri) {
                    $url = $ri['url'] ?? '';
                    if (!$url) continue;
                    $seen[] = $url;

                    $contactUrl  = $ri['contact'] ?? null;
                    $clientId    = $contactUrl ? $this->resolveClientFromContact($contactUrl) : null;
                    $contactName = null;
                    if ($contactUrl) {
                        $stmt = $this->db->prepare("SELECT name FROM freeagent_contacts WHERE freeagent_url = ? LIMIT 1");
                        $stmt->execute([$contactUrl]);
                        $contactName = $stmt->fetchColumn() ?: null;
                    }

                    // Preserve manually-set client_id — only update if currently null
                    $existingClientId = null;
                    if ($clientId === null) {
                        $ecStmt = $this->db->prepare("SELECT client_id FROM freeagent_recurring_invoices WHERE freeagent_url = ? LIMIT 1");
                        $ecStmt->execute([$url]);
                        $existingClientId = $ecStmt->fetchColumn() ?: null;
                    }
                    $resolvedClientId = $clientId ?? $existingClientId;

                    // FreeAgent returns 'recurring_status' field; fall back to deriving from view name
                    $recurringStatus = $ri['recurring_status']
                        ?? ($ri['status'] ?? null);
                    if (!$recurringStatus) {
                        $recurringStatus = ($view === 'active') ? 'Active' : 'Draft';
                    }
                    // Normalise to ucfirst (API returns 'Active' or 'Draft')
                    $recurringStatus = ucfirst(strtolower($recurringStatus));

                    $this->db->prepare("
                        INSERT INTO freeagent_recurring_invoices
                            (freeagent_url, freeagent_contact_url, client_id, reference, frequency,
                             recurring_status, net_value, sales_tax_value, total_value, currency,
                             dated_on, next_recurs_on, recurring_end_date, contact_name,
                             payment_terms_in_days, last_synced_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))
                        ON CONFLICT(freeagent_url) DO UPDATE SET
                            freeagent_contact_url  = excluded.freeagent_contact_url,
                            client_id              = CASE WHEN freeagent_recurring_invoices.client_id IS NOT NULL THEN freeagent_recurring_invoices.client_id ELSE excluded.client_id END,
                            reference              = excluded.reference,
                            frequency              = excluded.frequency,
                            recurring_status       = excluded.recurring_status,
                            net_value              = excluded.net_value,
                            sales_tax_value        = excluded.sales_tax_value,
                            total_value            = excluded.total_value,
                            currency               = excluded.currency,
                            dated_on               = excluded.dated_on,
                            next_recurs_on         = excluded.next_recurs_on,
                            recurring_end_date     = excluded.recurring_end_date,
                            contact_name           = excluded.contact_name,
                            payment_terms_in_days  = excluded.payment_terms_in_days,
                            last_synced_at         = excluded.last_synced_at
                    ")->execute([
                        $url,
                        $contactUrl,
                        $resolvedClientId,
                        $ri['reference'] ?? null,
                        $ri['frequency'] ?? 'Monthly',
                        $recurringStatus,
                        $ri['net_value'] ?? null,
                        $ri['sales_tax_value'] ?? null,
                        $ri['total_value'] ?? null,
                        $ri['currency'] ?? 'GBP',
                        $ri['dated_on'] ?? null,
                        $ri['next_recurs_on'] ?? null,
                        $ri['recurring_end_date'] ?? null,
                        $contactName,
                        $ri['payment_terms_in_days'] ?? null,
                    ]);
                }
            }

            // Delete stale records (no longer in API response)
            if (!empty($seen)) {
                $placeholders = implode(',', array_fill(0, count($seen), '?'));
                $this->db->prepare("
                    DELETE FROM freeagent_recurring_invoices
                    WHERE freeagent_url NOT IN ($placeholders)
                ")->execute($seen);
            }

            $total     = count($seen);
            $pagesNote = implode('; ', $pageNotes);
            $this->logComplete($logId, 'completed', $total, $pagesNote);
            return $total;
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', 0, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Look up a client_id for a FreeAgent contact URL without side-effects.
     * Unlike ensureClientForContact(), this does NOT create clients.
     */
    private function resolveClientFromContact(string $contactUrl): ?int
    {
        $stmt = $this->db->prepare("SELECT client_id FROM freeagent_contacts WHERE freeagent_url = ? LIMIT 1");
        $stmt->execute([$contactUrl]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    public function syncInvoices(): int
    {
        $logId = $this->logStart('invoices');
        try {
            $invoices = $this->client->getAll('invoices', 'invoices');
            foreach ($invoices as $inv) {
                $contactUrl = $inv['contact'] ?? null;
                $clientId = $contactUrl ? $this->ensureClientForContact($contactUrl) : null;
                $this->db->prepare("
                    INSERT INTO freeagent_invoices
                        (freeagent_url, freeagent_contact_url, client_id, reference, total_value, currency, status, dated_on, due_date, paid_on, category, last_synced_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,datetime('now'))
                    ON CONFLICT(freeagent_url) DO UPDATE SET
                        freeagent_contact_url = excluded.freeagent_contact_url,
                        client_id             = CASE WHEN freeagent_invoices.client_id IS NOT NULL THEN freeagent_invoices.client_id ELSE excluded.client_id END,
                        reference             = excluded.reference,
                        total_value           = excluded.total_value,
                        currency              = excluded.currency,
                        status                = excluded.status,
                        dated_on              = excluded.dated_on,
                        due_date              = excluded.due_date,
                        paid_on               = excluded.paid_on,
                        category              = excluded.category,
                        last_synced_at        = excluded.last_synced_at
                ")->execute([$inv['url'] ?? '', $contactUrl, $clientId, $inv['reference'] ?? null, $inv['total_value'] ?? 0, $inv['currency'] ?? 'GBP', isset($inv['status']) ? strtolower($inv['status']) : null, $inv['dated_on'] ?? null, $inv['due_on'] ?? null, $inv['paid_on'] ?? null, $inv['category'] ?? null]);
            }
            $pages = $this->client->lastPageCount;
            $note  = $pages > 1 ? "{$pages} pages fetched" : null;
            $this->logComplete($logId, 'completed', count($invoices), $note);
            return count($invoices);
        } catch (Throwable $e) { $this->logComplete($logId, 'failed', 0, $e->getMessage()); throw $e; }
    }

    public function syncBills(): int
    {
        $logId = $this->logStart('bills');
        try {
            $bills = $this->client->getAll('bills', 'bills');
            foreach ($bills as $bill) {
                $url = $bill['url'] ?? '';
                if (!$url) continue;

                $contactUrl  = $bill['contact'] ?? null;
                $contactName = null;
                if ($contactUrl) {
                    $stmt = $this->db->prepare("SELECT name FROM freeagent_contacts WHERE freeagent_url = ? LIMIT 1");
                    $stmt->execute([$contactUrl]);
                    $contactName = $stmt->fetchColumn() ?: null;
                }

                $recurringProfileUrl = $bill['recurring_profile'] ?? null;
                $isRecurring         = $recurringProfileUrl ? 1 : 0;

                // Normalise category: can be a URL string or array
                $rawCat = $bill['category'] ?? null;
                $catStr = is_string($rawCat) ? $rawCat : ($rawCat['url'] ?? null);

                $this->db->prepare("
                    INSERT INTO freeagent_bills
                        (freeagent_url, contact_url, contact_name, reference, total_value, currency,
                         status, freeagent_category, dated_on, due_date, recurring_profile_url, is_recurring, last_synced_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,datetime('now'))
                    ON CONFLICT(freeagent_url) DO UPDATE SET
                        contact_url           = excluded.contact_url,
                        contact_name          = excluded.contact_name,
                        reference             = excluded.reference,
                        total_value           = excluded.total_value,
                        currency              = excluded.currency,
                        status                = excluded.status,
                        freeagent_category    = excluded.freeagent_category,
                        dated_on              = excluded.dated_on,
                        due_date              = excluded.due_date,
                        recurring_profile_url = excluded.recurring_profile_url,
                        is_recurring          = MAX(freeagent_bills.is_recurring, excluded.is_recurring),
                        last_synced_at        = excluded.last_synced_at
                ")->execute([
                    $url, $contactUrl, $contactName,
                    $bill['reference'] ?? null,
                    $bill['total_value'] ?? 0,
                    $bill['currency'] ?? 'GBP',
                    $bill['status'] ?? null,
                    $catStr,
                    $bill['dated_on'] ?? null,
                    $bill['due_on'] ?? ($bill['due_date'] ?? null),
                    $recurringProfileUrl,
                    $isRecurring,
                ]);
            }

            // Auto-flag bills where same contact+amount appears 2+ times as likely recurring
            $this->db->exec("
                UPDATE freeagent_bills SET is_recurring = 1
                WHERE id IN (
                    SELECT fb.id FROM freeagent_bills fb
                    WHERE fb.contact_url IS NOT NULL
                      AND (SELECT COUNT(*) FROM freeagent_bills fb2
                           WHERE fb2.contact_url = fb.contact_url
                             AND fb2.total_value = fb.total_value) >= 2
                )
            ");

            // Flag potential duplicates: same total_value + dated_on within 3 days of a bank transaction
            $this->db->exec("
                UPDATE freeagent_bills SET potential_duplicate = 1
                WHERE id IN (
                    SELECT fb.id FROM freeagent_bills fb
                    WHERE EXISTS (
                        SELECT 1 FROM freeagent_bank_transactions bt
                        WHERE ABS(bt.gross_value) = fb.total_value
                          AND ABS(julianday(bt.dated_on) - julianday(fb.dated_on)) <= 3
                    )
                )
            ");

            $pages = $this->client->lastPageCount;
            $note  = $pages > 1 ? "{$pages} pages fetched" : null;
            $this->logComplete($logId, 'completed', count($bills), $note);
            return count($bills);
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', 0, $e->getMessage());
            throw $e;
        }
    }

    public function syncBankTransactions(): int
    {
        $logId = $this->logStart('bank_transactions');
        try {
            $accounts   = $this->client->get('bank_accounts')['bank_accounts'] ?? [];
            $total      = 0;
            $pageNotes  = [];
            foreach ($accounts as $account) {
                $accountUrl = $account['url'] ?? null; if (!$accountUrl) continue;
                $txns      = $this->client->getAll('bank_transaction_explanations', 'bank_transaction_explanations', ['bank_account' => $accountUrl]);
                $acctPages = $this->client->lastPageCount;
                foreach ($txns as $tx) {
                    $rawCat = $tx['category'] ?? null;
                    $display = is_string($rawCat) ? $rawCat : ($rawCat['description'] ?? ($rawCat['url'] ?? null));
                    $crmCat = $display ? $this->mapFaCategory($display, 'expense') : null;
                    if (!$crmCat && (float)($tx['gross_value'] ?? 0) < 0) $crmCat = 'software';

                    $this->db->prepare("INSERT INTO freeagent_bank_transactions (freeagent_url, description, gross_value, currency, freeagent_category, freeagent_category_display, crm_category, dated_on, bank_account_url, last_synced_at) VALUES (?,?,?,?,?,?,?,?,?,datetime('now')) ON CONFLICT(freeagent_url) DO UPDATE SET description=excluded.description, gross_value=excluded.gross_value, currency=excluded.currency, freeagent_category=excluded.freeagent_category, freeagent_category_display=excluded.freeagent_category_display, crm_category=excluded.crm_category, dated_on=excluded.dated_on, bank_account_url=excluded.bank_account_url, last_synced_at=excluded.last_synced_at")
                        ->execute([$tx['url'] ?? '', $tx['description'] ?? null, $tx['gross_value'] ?? 0, $tx['currency'] ?? 'GBP', is_string($rawCat)?$rawCat:($rawCat['url']??null), $display, $crmCat, $tx['dated_on'] ?? null, $accountUrl]);
                }
                $acctName    = $account['name'] ?? ($account['url'] ?? 'account');
                $pageNotes[] = $acctName . ': ' . count($txns) . " ({$acctPages}p)";
                $total += count($txns);
            }
            $note = !empty($pageNotes) ? implode('; ', $pageNotes) : null;
            $this->logComplete($logId, 'completed', $total, $note);
            return $total;
        } catch (Throwable $e) { $this->logComplete($logId, 'failed', 0, $e->getMessage()); throw $e; }
    }

    /**
     * Bridge qualifying FreeAgent bank transactions into the CRM expenses table.
     * Qualifying = negative gross_value (money out) AND crm_category is mapped.
     * Also deletes expense rows whose transaction has since been unmapped.
     */
    public function syncExpenses(): int
    {
        $logId = $this->logStart('expenses');
        $upserted = 0;
        $deleted  = 0;

        try {
            // 1. Upsert qualifying transactions
            $qualifying = $this->db->query("
                SELECT bt.*,
                       fc.client_id
                FROM freeagent_bank_transactions bt
                LEFT JOIN freeagent_contacts fc
                    ON fc.freeagent_url = bt.bank_account_url  -- not used for client; see below
                WHERE bt.gross_value < 0
                  AND bt.crm_category IS NOT NULL
                  AND bt.crm_category != ''
            ")->fetchAll();

            $checkStmt  = $this->db->prepare("SELECT id, source FROM expenses WHERE freeagent_url = ? LIMIT 1");
            $insertStmt = $this->db->prepare("
                INSERT INTO expenses
                    (name, category, amount, billing_cycle, client_id, server_id, project_id,
                     date, notes, source, freeagent_url, created_at)
                VALUES (?, ?, ?, 'one_off', NULL, NULL, NULL, ?, 'Synced from FreeAgent', 'freeagent', ?, datetime('now'))
            ");
            $updateStmt = $this->db->prepare("
                UPDATE expenses
                SET name = ?, category = ?, amount = ?, date = ?, notes = 'Synced from FreeAgent'
                WHERE freeagent_url = ? AND source != 'manual'
            ");
            // client_id is intentionally NOT updated — preserve manual assignments

            foreach ($qualifying as $tx) {
                $name   = mb_substr($tx['description'] ?? 'FreeAgent transaction', 0, 200);
                $amount = abs((float)$tx['gross_value']);
                $date   = $tx['dated_on'] ?? null;

                $checkStmt->execute([$tx['freeagent_url']]);
                $existing = $checkStmt->fetch();

                if ($existing) {
                    $updateStmt->execute([$name, $tx['crm_category'], $amount, $date, $tx['freeagent_url']]);
                } else {
                    $insertStmt->execute([$name, $tx['crm_category'], $amount, $date, $tx['freeagent_url']]);
                }

                $upserted++;
            }

            // 2. Delete expense rows whose bank transaction no longer qualifies
            //    (unmapped category, or gross_value changed to non-negative)
            $this->db->prepare("
                DELETE FROM expenses
                WHERE source = 'freeagent'
                  AND freeagent_url IS NOT NULL
                  AND freeagent_url NOT IN (
                      SELECT freeagent_url FROM freeagent_bank_transactions
                      WHERE gross_value < 0
                        AND crm_category IS NOT NULL
                        AND crm_category != ''
                  )
            ")->execute([]);

            // Count deletions via changes()
            $deleted = (int)$this->db->query("SELECT changes()")->fetchColumn();

            $note = $deleted > 0 ? "Removed $deleted unmapped expense(s)" : null;
            $this->logComplete($logId, 'completed', $upserted, $note);
            return $upserted;
        } catch (Throwable $e) {
            $this->logComplete($logId, 'failed', $upserted, $e->getMessage());
            throw $e;
        }
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
            $this->db->prepare("INSERT INTO clients (name, contact_name, contact_email, status, notes, created_at, updated_at) VALUES (?, ?, ?, 'active', 'Auto-created from FreeAgent sync', datetime('now'), datetime('now'))")
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
