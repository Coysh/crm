-- Ploi sync fixes:
--  * dismissible sync errors
--  * server-level sync exclusions (site-level table already exists from 015)

ALTER TABLE ploi_sync_log ADD COLUMN dismissed INTEGER NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS ploi_server_exclusions (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    ploi_server_id INTEGER NOT NULL UNIQUE,   -- Ploi's numeric server ID
    name           TEXT,
    reason         TEXT,
    created_at     DATETIME DEFAULT (datetime('now'))
);
