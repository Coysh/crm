-- expense_categories
CREATE TABLE IF NOT EXISTS expense_categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    slug TEXT NOT NULL UNIQUE,
    is_default INTEGER DEFAULT 0,
    sort_order INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT OR IGNORE INTO expense_categories (name, slug, is_default, sort_order) VALUES
    ('Plugin Licenses',       'plugin_licenses',     1, 1),
    ('SaaS & Subscriptions',  'saas_subscriptions',  1, 2),
    ('Hosting Costs',         'hosting_costs',        1, 3),
    ('Domain Registration',   'domain_registration', 1, 4),
    ('Email Hosting',         'email_hosting',       1, 5),
    ('Development Tools',     'development_tools',   1, 6),
    ('Security & Monitoring', 'security_monitoring', 1, 7);

-- recurring_costs
CREATE TABLE IF NOT EXISTS recurring_costs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category_id INTEGER NOT NULL REFERENCES expense_categories(id),
    amount DECIMAL(10,2) NOT NULL,
    billing_cycle TEXT NOT NULL DEFAULT 'monthly',
    renewal_date DATE,
    provider TEXT,
    url TEXT,
    notes TEXT,
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- recurring_cost_clients junction
CREATE TABLE IF NOT EXISTS recurring_cost_clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    recurring_cost_id INTEGER NOT NULL REFERENCES recurring_costs(id) ON DELETE CASCADE,
    client_id INTEGER REFERENCES clients(id) ON DELETE CASCADE,
    client_site_id INTEGER REFERENCES client_sites(id) ON DELETE CASCADE
);

-- Add category_id to expenses (nullable, for gradual migration)
ALTER TABLE expenses ADD COLUMN category_id INTEGER REFERENCES expense_categories(id);

-- Migrate existing text categories to category_id
UPDATE expenses SET category_id = (
    SELECT id FROM expense_categories WHERE slug = expenses.category
) WHERE expenses.category IS NOT NULL AND expenses.category != '';
