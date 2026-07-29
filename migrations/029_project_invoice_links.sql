-- Manual many-to-many links between projects and FreeAgent invoices.
-- Mirrors domain_invoice_links (023): invoice references rarely name the
-- project, so auto-matching can't find them — the user picks them by hand.
-- projects.income_invoiced is recomputed from these links on every change.

CREATE TABLE IF NOT EXISTS project_invoice_links (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id           INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    freeagent_invoice_id INTEGER NOT NULL REFERENCES freeagent_invoices(id) ON DELETE CASCADE,
    created_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (project_id, freeagent_invoice_id)
);

CREATE INDEX IF NOT EXISTS idx_project_invoice_links_project ON project_invoice_links(project_id);
CREATE INDEX IF NOT EXISTS idx_project_invoice_links_invoice ON project_invoice_links(freeagent_invoice_id);
