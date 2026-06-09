-- Migration 024: Application-level authentication (single user + TOTP 2FA)

CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,            -- password_hash(PASSWORD_DEFAULT)
    totp_secret     TEXT,                     -- base32 seed, stored via Secrets::encrypt
    totp_enabled    INTEGER NOT NULL DEFAULT 0,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    DATETIME,
    last_login_at   DATETIME,
    created_at      DATETIME DEFAULT (datetime('now'))
);
