-- Migration 015: Deletion log, Ploi sync exclusions, and Cloudflare integration

-- Deletion audit log
CREATE TABLE IF NOT EXISTS deletion_log (
    id INTEGER PRIMARY KEY,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NOT NULL,
    entity_name TEXT NOT NULL,
    related_data TEXT, -- JSON
    deleted_at DATETIME DEFAULT (datetime('now'))
);

-- Ploi sync exclusion list (sites to skip during Ploi sync)
CREATE TABLE IF NOT EXISTS ploi_sync_exclusions (
    id INTEGER PRIMARY KEY,
    ploi_site_id INTEGER NOT NULL UNIQUE,
    ploi_server_id INTEGER,
    domain TEXT,
    reason TEXT,
    created_at DATETIME DEFAULT (datetime('now'))
);

-- Cloudflare API configuration
CREATE TABLE IF NOT EXISTS cloudflare_config (
    id INTEGER PRIMARY KEY,
    api_token TEXT,
    last_sync_at DATETIME
);

-- Cloudflare zones (one per domain/account zone)
CREATE TABLE IF NOT EXISTS cloudflare_zones (
    id INTEGER PRIMARY KEY,
    zone_id TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    status TEXT,
    name_servers TEXT, -- JSON array
    plan TEXT,
    ssl_status TEXT,
    always_use_https INTEGER DEFAULT 0,
    domain_id INTEGER REFERENCES domains(id) ON DELETE SET NULL,
    last_synced_at DATETIME
);

-- Cloudflare DNS records
CREATE TABLE IF NOT EXISTS cloudflare_dns_records (
    id INTEGER PRIMARY KEY,
    record_id TEXT NOT NULL UNIQUE,
    zone_id TEXT NOT NULL,
    type TEXT NOT NULL,
    name TEXT NOT NULL,
    content TEXT NOT NULL,
    ttl INTEGER DEFAULT 1,
    proxied INTEGER DEFAULT 0,
    priority INTEGER,
    last_synced_at DATETIME
);
