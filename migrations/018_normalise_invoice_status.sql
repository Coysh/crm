-- Normalise freeagent_invoices.status to lowercase
-- FreeAgent "Open" = sent invoice (not yet paid, not overdue)
UPDATE freeagent_invoices SET status = LOWER(status);
UPDATE freeagent_invoices SET status = 'sent' WHERE status = 'open';
