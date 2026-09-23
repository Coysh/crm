-- One row per scheduled job execution, written by scripts/cron.php.
-- Drives the Scheduled Jobs panel on /settings and stale-sync detection.
CREATE TABLE IF NOT EXISTS job_runs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    job         TEXT NOT NULL,
    started_at  DATETIME NOT NULL DEFAULT (datetime('now')),
    finished_at DATETIME,
    status      TEXT NOT NULL DEFAULT 'running', -- running | ok | skipped | failed
    exit_code   INTEGER,
    output      TEXT
);

CREATE INDEX IF NOT EXISTS idx_job_runs_job_started ON job_runs(job, started_at);
