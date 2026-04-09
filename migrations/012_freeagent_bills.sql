CREATE TABLE IF NOT EXISTS freeagent_bills (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    freeagent_url        TEXT NOT NULL UNIQUE,
    contact_url          TEXT,
    contact_name         TEXT,
    reference            TEXT,
    total_value          DECIMAL(10,2),
    currency             TEXT DEFAULT 'GBP',
    status               TEXT,
    freeagent_category   TEXT,
    dated_on             DATE,
    due_date             DATE,
    recurring_profile_url TEXT,
    is_recurring         INTEGER DEFAULT 0,
    recurring_cost_id    INTEGER REFERENCES recurring_costs(id) ON DELETE SET NULL,
    reviewed             INTEGER DEFAULT 0,
    potential_duplicate  INTEGER DEFAULT 0,
    last_synced_at       DATETIME
);
