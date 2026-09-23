-- Daily digest + immediate site-down alerts to the CRM owner (Services\Notifier,
-- run every 5 min by scripts/cron.php via scripts/notify.php).
CREATE TABLE IF NOT EXISTS notification_config (
    id               INTEGER PRIMARY KEY CHECK (id = 1),
    digest_enabled   INTEGER NOT NULL DEFAULT 0,
    recipient        TEXT,
    digest_time      TEXT NOT NULL DEFAULT '07:30',  -- Europe/London
    include_low      INTEGER NOT NULL DEFAULT 0,     -- add housekeeping items to the digest
    site_down_alerts INTEGER NOT NULL DEFAULT 0,
    app_url          TEXT,                           -- captured from the browser on save; cron has no Host
    last_digest_at   DATETIME,
    updated_at       DATETIME
);
INSERT OR IGNORE INTO notification_config (id) VALUES (1);

-- Incident keys already alerted on, so each outage emails once.
CREATE TABLE IF NOT EXISTS notification_log (
    item_key TEXT PRIMARY KEY,
    sent_at  DATETIME NOT NULL DEFAULT (datetime('now'))
);
