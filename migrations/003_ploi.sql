CREATE TABLE IF NOT EXISTS ploi_config (
    id INTEGER PRIMARY KEY,
    api_token TEXT,
    last_sync_at DATETIME
);

CREATE TABLE IF NOT EXISTS ploi_servers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ploi_id INTEGER NOT NULL UNIQUE,
    name TEXT NOT NULL,
    ip_address TEXT,
    provider TEXT,
    region TEXT,
    status TEXT,
    php_versions TEXT,
    php_cli_version TEXT,
    is_stale INTEGER DEFAULT 0,
    server_id INTEGER,
    last_synced_at DATETIME,
    FOREIGN KEY (server_id) REFERENCES servers(id)
);

CREATE TABLE IF NOT EXISTS ploi_sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ploi_id INTEGER NOT NULL UNIQUE,
    ploi_server_id INTEGER NOT NULL,
    domain TEXT NOT NULL,
    project_type TEXT,
    php_version TEXT,
    web_directory TEXT,
    project_root TEXT,
    repository TEXT,
    branch TEXT,
    has_ssl INTEGER DEFAULT 0,
    test_domain TEXT,
    status TEXT,
    is_stale INTEGER DEFAULT 0,
    client_site_id INTEGER,
    last_synced_at DATETIME,
    FOREIGN KEY (ploi_server_id) REFERENCES ploi_servers(id),
    FOREIGN KEY (client_site_id) REFERENCES client_sites(id)
);

CREATE TABLE IF NOT EXISTS ploi_sync_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sync_type TEXT,
    status TEXT,
    records_synced INTEGER DEFAULT 0,
    error_message TEXT,
    started_at DATETIME,
    completed_at DATETIME
);

CREATE TABLE IF NOT EXISTS client_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    original_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id)
);

ALTER TABLE freeagent_contacts ADD COLUMN organisation_name TEXT;
ALTER TABLE freeagent_bank_transactions ADD COLUMN freeagent_category_display TEXT;
