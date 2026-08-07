-- Uptime Kuma, part 2: move from the read-only /metrics endpoint to the
-- Socket.IO API, which is the only one that can create monitors.
--
-- Two consequences for the schema:
--   * monitors get a real id from Uptime Kuma, so monitor_name stops being the
--     key. The table is rebuilt because SQLite cannot drop the UNIQUE on
--     monitor_name any other way, and two monitors may legitimately share a
--     name once they are keyed properly.
--   * Socket.IO authenticates with username + password, not the API key, so
--     the config row gains credentials plus a cached JWT and the id of the
--     monitor new ones are cloned from.
--
-- The rebuild below relies on scripts/migrate.php opening its own PDO WITHOUT
-- `PRAGMA foreign_keys = ON` (SQLite defaults it off). uptime_kuma_checks
-- references uptime_kuma_monitors ON DELETE CASCADE, so with foreign keys
-- enabled the DROP TABLE would take every sample row with it. If the runner
-- ever starts enabling them, this migration must disable them around the swap.

ALTER TABLE uptime_kuma_config ADD COLUMN username           TEXT;
ALTER TABLE uptime_kuma_config ADD COLUMN password           TEXT;  -- encrypted (Services\Secrets)
ALTER TABLE uptime_kuma_config ADD COLUMN jwt                TEXT;  -- encrypted; cached from login, lets 2FA setups run unattended
ALTER TABLE uptime_kuma_config ADD COLUMN template_monitor_id INTEGER;  -- Uptime Kuma's own id, not a local one

CREATE TABLE uptime_kuma_monitors_new (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    kuma_id             INTEGER,               -- Uptime Kuma's own monitor id; NULL until the first Socket.IO sync adopts the row
    monitor_name        TEXT NOT NULL,         -- deliberately NOT unique any more
    monitor_type        TEXT,
    monitor_url         TEXT,
    monitor_hostname    TEXT,
    monitor_port        TEXT,
    status              INTEGER,               -- 0=down 1=up 2=pending 3=maintenance
    status_changed_at   DATETIME,
    response_time_ms    INTEGER,
    cert_days_remaining INTEGER,
    cert_is_valid       INTEGER,
    uptime_24h          REAL,                  -- from Uptime Kuma when available, else computed locally
    uptime_30d          REAL,
    uptime_is_local     INTEGER NOT NULL DEFAULT 0,  -- 1 = fallback figure computed from our own samples
    active              INTEGER,               -- Uptime Kuma's paused/active flag
    client_site_id      INTEGER,
    link_is_manual      INTEGER NOT NULL DEFAULT 0,
    created_by_crm      INTEGER NOT NULL DEFAULT 0,  -- monitor was created from the CRM
    missed_syncs        INTEGER NOT NULL DEFAULT 0,
    is_stale            INTEGER NOT NULL DEFAULT 0,
    first_seen_at       DATETIME,
    last_synced_at      DATETIME,
    FOREIGN KEY (client_site_id) REFERENCES client_sites(id)
);

-- Preserve ids: uptime_kuma_checks and uptime_kuma_daily reference them.
INSERT INTO uptime_kuma_monitors_new
    (id, monitor_name, monitor_type, monitor_url, monitor_hostname, monitor_port,
     status, status_changed_at, response_time_ms, cert_days_remaining, cert_is_valid,
     uptime_24h, uptime_30d, uptime_is_local, client_site_id, link_is_manual,
     missed_syncs, is_stale, first_seen_at, last_synced_at)
SELECT
     id, monitor_name, monitor_type, monitor_url, monitor_hostname, monitor_port,
     status, status_changed_at, response_time_ms, cert_days_remaining, cert_is_valid,
     uptime_24h, uptime_30d, 1, client_site_id, link_is_manual,
     missed_syncs, is_stale, first_seen_at, last_synced_at
FROM uptime_kuma_monitors;

DROP TABLE uptime_kuma_monitors;
ALTER TABLE uptime_kuma_monitors_new RENAME TO uptime_kuma_monitors;

CREATE UNIQUE INDEX IF NOT EXISTS idx_uk_monitors_kuma_id     ON uptime_kuma_monitors(kuma_id);
CREATE INDEX        IF NOT EXISTS idx_uk_monitors_client_site ON uptime_kuma_monitors(client_site_id);
CREATE INDEX        IF NOT EXISTS idx_uk_monitors_name        ON uptime_kuma_monitors(monitor_name);
