-- Structured agreements (SLAs, retainers, build agreements with bundled cover)
-- plus a lightweight work log for hours-based allowances.

CREATE TABLE IF NOT EXISTS agreements (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id           INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    title               TEXT NOT NULL,
    agreement_type      TEXT NOT NULL DEFAULT 'support'
                        CHECK (agreement_type IN ('support','build_bundled','consultancy','other')),
    status              TEXT NOT NULL DEFAULT 'active'
                        CHECK (status IN ('active','expired','cancelled')),
    covers_hosting      INTEGER NOT NULL DEFAULT 0,
    covers_support      INTEGER NOT NULL DEFAULT 0,
    covers_maintenance  INTEGER NOT NULL DEFAULT 0,
    included_hours      DECIMAL(6,2),          -- NULL = no hours allowance (e.g. build-bundled cover)
    hours_period        TEXT CHECK (hours_period IN ('monthly','quarterly','annually')),
    fee_amount          DECIMAL(10,2),
    fee_currency        TEXT DEFAULT 'GBP',
    fee_billing_cycle   TEXT CHECK (fee_billing_cycle IN ('monthly','quarterly','annually','one_off')),
    freeagent_recurring_invoice_id INTEGER REFERENCES freeagent_recurring_invoices(id) ON DELETE SET NULL,
    start_date          DATE,
    renewal_date        DATE,                  -- next renewal / review date
    response_terms      TEXT,                  -- response-time commitments, free text
    notes               TEXT,
    created_at          DATETIME DEFAULT (datetime('now')),
    updated_at          DATETIME
);
CREATE INDEX IF NOT EXISTS idx_agreements_client ON agreements(client_id);

CREATE TABLE IF NOT EXISTS agreement_work_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    agreement_id INTEGER NOT NULL REFERENCES agreements(id) ON DELETE CASCADE,
    work_date    DATE NOT NULL,
    hours        DECIMAL(5,2) NOT NULL,
    description  TEXT,
    created_at   DATETIME DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_awl_agreement ON agreement_work_log(agreement_id, work_date);

ALTER TABLE client_attachments ADD COLUMN agreement_id INTEGER REFERENCES agreements(id) ON DELETE SET NULL;
