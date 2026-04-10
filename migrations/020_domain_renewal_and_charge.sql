-- Renewal period in years (default 1)
ALTER TABLE domains ADD COLUMN renewal_years INTEGER NOT NULL DEFAULT 1;

-- What you charge the client for the domain renewal
ALTER TABLE domains ADD COLUMN client_charge REAL;
