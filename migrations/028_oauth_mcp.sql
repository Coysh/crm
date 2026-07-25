-- OAuth 2.1 authorization server (for the MCP endpoint) + MCP request log.

CREATE TABLE IF NOT EXISTS oauth_clients (
    id                         INTEGER PRIMARY KEY AUTOINCREMENT,
    client_id                  TEXT NOT NULL UNIQUE,     -- random hex identifier
    client_name                TEXT,
    redirect_uris              TEXT NOT NULL,            -- JSON array, https-only
    token_endpoint_auth_method TEXT NOT NULL DEFAULT 'none',
    created_at                 DATETIME DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS oauth_codes (
    code_hash             TEXT PRIMARY KEY,              -- sha256(code)
    client_id             TEXT NOT NULL,
    redirect_uri          TEXT NOT NULL,
    code_challenge        TEXT NOT NULL,
    code_challenge_method TEXT NOT NULL DEFAULT 'S256',
    scope                 TEXT,
    resource              TEXT,
    expires_at            DATETIME NOT NULL,
    used                  INTEGER NOT NULL DEFAULT 0,
    created_at            DATETIME DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS oauth_tokens (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash   TEXT NOT NULL UNIQUE,                   -- sha256(token)
    token_type   TEXT NOT NULL CHECK (token_type IN ('access','refresh')),
    client_id    TEXT NOT NULL,
    scope        TEXT,
    family_id    TEXT NOT NULL,                          -- refresh-rotation family
    expires_at   DATETIME NOT NULL,
    revoked      INTEGER NOT NULL DEFAULT 0,
    last_used_at DATETIME,
    created_at   DATETIME DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_oauth_tokens_family ON oauth_tokens(family_id);

CREATE TABLE IF NOT EXISTS mcp_request_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    token_id   INTEGER,
    ip         TEXT,
    method     TEXT,
    tool_name  TEXT,
    created_at DATETIME DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_mcp_log_created ON mcp_request_log(created_at);
