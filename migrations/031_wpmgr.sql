-- WPMGR integration: config, mirrored sites, sync log
CREATE TABLE IF NOT EXISTS wpmgr_config (
    id            INTEGER PRIMARY KEY,   -- always row id 1
    base_url      TEXT,
    api_key       TEXT,                  -- encrypted at rest (Services\Secrets)
    last_sync_at  DATETIME
);

CREATE TABLE IF NOT EXISTS wpmgr_sites (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    wpmgr_id              TEXT NOT NULL UNIQUE,  -- WPMGR's UUID (TEXT, not INTEGER like Ploi's remote id)
    url                   TEXT NOT NULL,
    name                  TEXT,
    status                TEXT,                  -- pending|active|error|disabled
    wp_version            TEXT,
    php_version           TEXT,
    health_status         TEXT,                  -- unknown|healthy|unreachable
    connection_state      TEXT,                  -- pending_enrollment|connected|degraded|disconnected|revoked|archived
    multisite             INTEGER DEFAULT 0,
    active_theme          TEXT,
    tags                  TEXT,                  -- JSON-encoded array
    agent_version         TEXT,
    host_provider         TEXT,
    updates_available     INTEGER DEFAULT 0,
    last_backup_at        DATETIME,
    last_backup_status    TEXT,                  -- success|running|failed
    up                    INTEGER,
    uptime_pct            REAL,
    avg_latency_ms        INTEGER,
    tls_expires_at        DATETIME,
    page_cache_enabled    INTEGER DEFAULT 0,
    object_cache_enabled  INTEGER DEFAULT 0,
    wpmgr_client_id       TEXT,                  -- WPMGR's own client grouping, display only
    wpmgr_client_name     TEXT,
    enrolled_at           DATETIME,
    last_seen_at          DATETIME,
    client_site_id        INTEGER,               -- link into this CRM's client_sites; NULL = unmatched
    is_stale              INTEGER DEFAULT 0,
    last_synced_at        DATETIME,
    FOREIGN KEY (client_site_id) REFERENCES client_sites(id)
);

CREATE TABLE IF NOT EXISTS wpmgr_sync_log (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    sync_type      TEXT,
    status         TEXT,
    records_synced INTEGER DEFAULT 0,
    error_message  TEXT,
    started_at     DATETIME,
    completed_at   DATETIME,
    dismissed      INTEGER NOT NULL DEFAULT 0
);
