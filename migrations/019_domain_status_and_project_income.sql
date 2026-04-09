-- Domain archiving: add status column
ALTER TABLE domains ADD COLUMN status TEXT NOT NULL DEFAULT 'active';

-- Project income tracking: add income_target and income_invoiced columns
ALTER TABLE projects ADD COLUMN income_target REAL DEFAULT 0;
ALTER TABLE projects ADD COLUMN income_invoiced REAL DEFAULT 0;

-- Migrate existing income values to income_invoiced
UPDATE projects SET income_invoiced = income WHERE income > 0;
