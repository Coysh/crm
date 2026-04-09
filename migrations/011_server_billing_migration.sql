-- Add server_id to recurring_costs so a cost can represent a server's hosting fee
ALTER TABLE recurring_costs ADD COLUMN server_id INTEGER REFERENCES servers(id) ON DELETE SET NULL;

-- Key/value settings table (used for dismissed suggestions etc.)
CREATE TABLE IF NOT EXISTS settings (
    key         TEXT PRIMARY KEY,
    value       TEXT,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Migrate server monthly costs into recurring_costs (idempotent — skips servers already linked)
INSERT INTO recurring_costs (name, category_id, amount, billing_cycle, provider, server_id, is_active, created_at, updated_at)
SELECT
    s.name || ' — Hosting',
    (SELECT id FROM expense_categories WHERE slug = 'hosting_costs' LIMIT 1),
    s.monthly_cost,
    'monthly',
    s.provider,
    s.id,
    1,
    datetime('now'),
    datetime('now')
FROM servers s
WHERE s.monthly_cost > 0
  AND NOT EXISTS (SELECT 1 FROM recurring_costs WHERE server_id = s.id);

-- Zero out the servers.monthly_cost for migrated servers so legacy queries don't double-count
UPDATE servers
SET monthly_cost = 0
WHERE id IN (SELECT server_id FROM recurring_costs WHERE server_id IS NOT NULL);
