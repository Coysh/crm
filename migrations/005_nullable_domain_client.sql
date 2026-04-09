-- Make domains.client_id nullable so Ploi-imported sites can have domain records
-- created before the domain is assigned to a client.
PRAGMA foreign_keys = OFF;

CREATE TABLE domains_new (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id          INTEGER,
    domain             TEXT NOT NULL,
    registrar          TEXT,
    cloudflare_proxied INTEGER DEFAULT 0,
    renewal_date       DATE,
    annual_cost        DECIMAL(10,2),
    created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

INSERT INTO domains_new SELECT * FROM domains;
DROP TABLE domains;
ALTER TABLE domains_new RENAME TO domains;

PRAGMA foreign_keys = ON;
