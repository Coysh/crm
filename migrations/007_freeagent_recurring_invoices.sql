-- FreeAgent recurring invoices table.
-- These replace the manual service_packages system as the source of truth
-- for expected recurring income per client.

CREATE TABLE IF NOT EXISTS freeagent_recurring_invoices (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    freeagent_url         TEXT    NOT NULL UNIQUE,
    freeagent_contact_url TEXT,
    client_id             INTEGER REFERENCES clients(id) ON DELETE SET NULL,
    reference             TEXT,
    frequency             TEXT    NOT NULL,
    recurring_status      TEXT    NOT NULL,
    net_value             DECIMAL(10,2),
    sales_tax_value       DECIMAL(10,2),
    total_value           DECIMAL(10,2),
    currency              TEXT    DEFAULT 'GBP',
    dated_on              DATE,
    next_recurs_on        DATE,
    recurring_end_date    DATE,
    contact_name          TEXT,
    payment_terms_in_days INTEGER,
    last_synced_at        DATETIME
);
