-- Add source tracking and FreeAgent URL to expenses for synced bank transactions.
ALTER TABLE expenses ADD COLUMN source TEXT NOT NULL DEFAULT 'manual';
ALTER TABLE expenses ADD COLUMN freeagent_url TEXT;
CREATE UNIQUE INDEX idx_expenses_freeagent_url ON expenses (freeagent_url) WHERE freeagent_url IS NOT NULL;
