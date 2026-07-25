-- Net (ex-VAT) invoice values, captured from FreeAgent on sync.
-- Revenue aggregates use COALESCE(net_value, total_value) so historical rows
-- keep working until a full FreeAgent resync backfills them.

ALTER TABLE freeagent_invoices ADD COLUMN net_value DECIMAL(10,2);
ALTER TABLE freeagent_invoices ADD COLUMN sales_tax_value DECIMAL(10,2);
