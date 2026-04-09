-- Add source column to freeagent_invoices (nullable, default 'freeagent')
ALTER TABLE freeagent_invoices ADD COLUMN source TEXT DEFAULT 'freeagent';

-- Add hiveage_id for duplicate prevention
ALTER TABLE freeagent_invoices ADD COLUMN hiveage_id TEXT;

-- Create unique index for hiveage_id
CREATE UNIQUE INDEX IF NOT EXISTS idx_freeagent_invoices_hiveage_id
ON freeagent_invoices (hiveage_id) WHERE hiveage_id IS NOT NULL;
