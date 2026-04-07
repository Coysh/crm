CREATE TABLE IF NOT EXISTS freeagent_contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    freeagent_url TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    email TEXT,
    client_id INTEGER,
    auto_matched INTEGER DEFAULT 0,
    last_synced_at DATETIME,
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

CREATE TABLE IF NOT EXISTS freeagent_invoices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    freeagent_url TEXT NOT NULL UNIQUE,
    freeagent_contact_url TEXT,
    client_id INTEGER,
    reference TEXT,
    total_value DECIMAL(10,2),
    currency TEXT DEFAULT 'GBP',
    status TEXT,
    dated_on DATE,
    due_date DATE,
    paid_on DATE,
    category TEXT,
    last_synced_at DATETIME,
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

CREATE TABLE IF NOT EXISTS freeagent_bank_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    freeagent_url TEXT NOT NULL UNIQUE,
    description TEXT,
    gross_value DECIMAL(10,2),
    currency TEXT DEFAULT 'GBP',
    freeagent_category TEXT,
    crm_category TEXT,
    client_id INTEGER,
    dated_on DATE,
    bank_account_url TEXT,
    last_synced_at DATETIME,
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

CREATE TABLE IF NOT EXISTS freeagent_sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sync_type TEXT,
    status TEXT,
    records_synced INTEGER DEFAULT 0,
    error_message TEXT,
    started_at DATETIME,
    completed_at DATETIME
);

-- Add sandbox toggle and base_url override to freeagent_config
ALTER TABLE freeagent_config ADD COLUMN use_sandbox INTEGER DEFAULT 0;
