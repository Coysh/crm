-- Make client_sites.client_id nullable so Ploi-imported sites can exist before
-- being assigned to a client.
PRAGMA foreign_keys = OFF;

CREATE TABLE client_sites_new (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id               INTEGER,
    domain_id               INTEGER,
    server_id               INTEGER,
    website_stack           TEXT,
    css_framework           TEXT,
    smtp_service            TEXT,
    git_repo                TEXT,
    has_deployment_pipeline INTEGER DEFAULT 0,
    notes                   TEXT,
    created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id)  REFERENCES clients(id),
    FOREIGN KEY (domain_id)  REFERENCES domains(id),
    FOREIGN KEY (server_id)  REFERENCES servers(id)
);

INSERT INTO client_sites_new
    SELECT id, client_id, domain_id, server_id, website_stack, css_framework,
           smtp_service, git_repo, has_deployment_pipeline, notes, created_at
    FROM client_sites;

DROP TABLE client_sites;
ALTER TABLE client_sites_new RENAME TO client_sites;

PRAGMA foreign_keys = ON;
