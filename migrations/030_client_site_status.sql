-- Site archiving: add status column
ALTER TABLE client_sites ADD COLUMN status TEXT NOT NULL DEFAULT 'active';
