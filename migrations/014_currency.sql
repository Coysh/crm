-- Exchange rates cache table
CREATE TABLE IF NOT EXISTS exchange_rates (
    id              INTEGER PRIMARY KEY,
    date            DATE NOT NULL,
    base_currency   TEXT NOT NULL DEFAULT 'GBP',
    target_currency TEXT NOT NULL,
    rate            DECIMAL(10,6) NOT NULL,
    created_at      DATETIME,
    UNIQUE(date, base_currency, target_currency)
);

-- Currency on recurring costs (default GBP — existing rows unaffected)
ALTER TABLE recurring_costs ADD COLUMN currency TEXT NOT NULL DEFAULT 'GBP';

-- Currency on domains (default GBP — existing rows unaffected)
ALTER TABLE domains ADD COLUMN currency TEXT NOT NULL DEFAULT 'GBP';
