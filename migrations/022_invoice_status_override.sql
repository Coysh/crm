-- Manual status override for invoices (survives FreeAgent/Hiveage re-syncs)
ALTER TABLE freeagent_invoices ADD COLUMN status_override TEXT;
ALTER TABLE freeagent_invoices ADD COLUMN status_override_note TEXT;
