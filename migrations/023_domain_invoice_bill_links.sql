-- Manual many-to-many links between domains and FreeAgent invoices / bills.
-- Needed because many invoices aren't recurring and the reference text
-- doesn't always mention the domain, so auto-matching can't find them.

CREATE TABLE IF NOT EXISTS domain_invoice_links (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id             INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
    freeagent_invoice_id  INTEGER NOT NULL REFERENCES freeagent_invoices(id) ON DELETE CASCADE,
    created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (domain_id, freeagent_invoice_id)
);

CREATE TABLE IF NOT EXISTS domain_bill_links (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id          INTEGER NOT NULL REFERENCES domains(id) ON DELETE CASCADE,
    freeagent_bill_id  INTEGER NOT NULL REFERENCES freeagent_bills(id) ON DELETE CASCADE,
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (domain_id, freeagent_bill_id)
);

CREATE INDEX IF NOT EXISTS idx_domain_invoice_links_domain ON domain_invoice_links(domain_id);
CREATE INDEX IF NOT EXISTS idx_domain_bill_links_domain    ON domain_bill_links(domain_id);
