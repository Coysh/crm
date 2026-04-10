-- Client charge is always in GBP by default, independent of cost currency
ALTER TABLE domains ADD COLUMN client_charge_currency TEXT NOT NULL DEFAULT 'GBP';
