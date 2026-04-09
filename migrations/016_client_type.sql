-- Add client type and agreement notes to clients table
ALTER TABLE clients ADD COLUMN client_type TEXT NOT NULL DEFAULT 'managed';
ALTER TABLE clients ADD COLUMN agreement_notes TEXT;
