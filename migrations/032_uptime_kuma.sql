-- Uptime Kuma integration: config, mirrored monitors, sample history, sync log
--
-- Uptime Kuma exposes no general-purpose authenticated REST API — everything
-- rich lives behind Socket.io. The only authenticated REST surface is
-- GET /metrics (Prometheus text, HTTP Basic with the API key as the password),
-- which carries no monitor id and no uptime percentage. Hence:
--   * monitor_name is the sync key (see the trap noted in CLAUDE.md)
--   * uptime is computed locally from samples this CRM takes at sync time

CREATE TABLE IF NOT EXISTS uptime_kuma_config (
    id            INTEGER PRIMARY KEY,   -- always row id 1
    base_url      TEXT,
    api_key       TEXT,                  -- encrypted at rest (Services\Secrets)
    last_sync_at  DATETIME
);

CREATE TABLE IF NOT EXISTS uptime_kuma_monitors (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_name        TEXT NOT NULL UNIQUE,  -- the only stable key /metrics exposes
    monitor_type        TEXT,                  -- http|port|ping|keyword|dns|push|...
    monitor_url         TEXT,
    monitor_hostname    TEXT,
    monitor_port        TEXT,
    status              INTEGER,               -- 0=down 1=up 2=pending 3=maintenance
    status_changed_at   DATETIME,              -- when `status` last differed (drives "down for 2h")
    response_time_ms    INTEGER,
    cert_days_remaining INTEGER,
    cert_is_valid       INTEGER,
    uptime_24h          REAL,                  -- computed locally from uptime_kuma_checks
    uptime_30d          REAL,                  -- computed locally from uptime_kuma_daily
    client_site_id      INTEGER,               -- NULL = unmatched (informational only)
    link_is_manual      INTEGER NOT NULL DEFAULT 0,  -- 1 = hand-linked, sync must not re-resolve
    missed_syncs        INTEGER NOT NULL DEFAULT 0,
    is_stale            INTEGER NOT NULL DEFAULT 0,
    first_seen_at       DATETIME,
    last_synced_at      DATETIME,
    FOREIGN KEY (client_site_id) REFERENCES client_sites(id)
);

CREATE INDEX IF NOT EXISTS idx_uk_monitors_client_site ON uptime_kuma_monitors(client_site_id);

-- Raw samples, one per monitor per sync. Pruned to 7 days by UptimeKumaSync.
CREATE TABLE IF NOT EXISTS uptime_kuma_checks (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    monitor_id       INTEGER NOT NULL,
    status           INTEGER,
    response_time_ms INTEGER,
    checked_at       DATETIME NOT NULL,
    FOREIGN KEY (monitor_id) REFERENCES uptime_kuma_monitors(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_uk_checks_monitor_time ON uptime_kuma_checks(monitor_id, checked_at);

-- Per-day rollup, kept indefinitely (~365 rows/monitor/year).
CREATE TABLE IF NOT EXISTS uptime_kuma_daily (
    monitor_id       INTEGER NOT NULL,
    day              DATE NOT NULL,
    up_checks        INTEGER NOT NULL DEFAULT 0,
    total_checks     INTEGER NOT NULL DEFAULT 0,
    response_time_ms INTEGER,               -- running average across the day
    PRIMARY KEY (monitor_id, day),
    FOREIGN KEY (monitor_id) REFERENCES uptime_kuma_monitors(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS uptime_kuma_sync_log (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    sync_type      TEXT,
    status         TEXT,
    records_synced INTEGER DEFAULT 0,
    error_message  TEXT,
    started_at     DATETIME,
    completed_at   DATETIME,
    dismissed      INTEGER NOT NULL DEFAULT 0
);
