-- Comprehensive email marketing: contacts, consent, segments, templates,
-- campaigns, delivery queue, Mailgun events, and durable suppressions.

CREATE TABLE IF NOT EXISTS email_marketing_config (
    id                    INTEGER PRIMARY KEY,
    region                TEXT NOT NULL DEFAULT 'eu',
    api_key               TEXT,
    webhook_signing_key   TEXT,
    sending_domain        TEXT,
    from_name             TEXT,
    from_email            TEXT,
    reply_to              TEXT,
    business_name         TEXT,
    business_address      TEXT,
    privacy_url           TEXT,
    brand_colour          TEXT NOT NULL DEFAULT '#4f46e5',
    tracking_opens        INTEGER NOT NULL DEFAULT 1,
    tracking_clicks       INTEGER NOT NULL DEFAULT 1,
    last_verified_at      DATETIME,
    worker_last_run_at    DATETIME,
    created_at            DATETIME DEFAULT (datetime('now')),
    updated_at            DATETIME
);

CREATE TABLE IF NOT EXISTS marketing_contacts (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    name                  TEXT,
    email                 TEXT NOT NULL,
    email_norm            TEXT NOT NULL,
    company_name          TEXT,
    status                TEXT NOT NULL DEFAULT 'active',
    eligibility_basis     TEXT NOT NULL DEFAULT 'unknown',
    eligibility_at        DATETIME,
    eligibility_source    TEXT,
    eligibility_notes     TEXT,
    unsubscribed_at       DATETIME,
    created_at            DATETIME DEFAULT (datetime('now')),
    updated_at            DATETIME,
    UNIQUE(email_norm)
);

CREATE TABLE IF NOT EXISTS marketing_contact_clients (
    contact_id            INTEGER NOT NULL REFERENCES marketing_contacts(id) ON DELETE CASCADE,
    client_id             INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    is_primary            INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (contact_id, client_id)
);
CREATE INDEX IF NOT EXISTS idx_marketing_contact_clients_client ON marketing_contact_clients(client_id);

CREATE TABLE IF NOT EXISTS marketing_consent_events (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    contact_id            INTEGER NOT NULL REFERENCES marketing_contacts(id) ON DELETE CASCADE,
    event_type            TEXT NOT NULL,
    basis                 TEXT,
    source                TEXT,
    notes                 TEXT,
    user_id               INTEGER REFERENCES users(id) ON DELETE SET NULL,
    occurred_at           DATETIME NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_marketing_consent_contact ON marketing_consent_events(contact_id, occurred_at);

CREATE TABLE IF NOT EXISTS marketing_suppressions (
    email_norm            TEXT PRIMARY KEY,
    reason                TEXT NOT NULL,
    source                TEXT NOT NULL,
    contact_id            INTEGER REFERENCES marketing_contacts(id) ON DELETE SET NULL,
    details               TEXT,
    created_at            DATETIME NOT NULL DEFAULT (datetime('now')),
    cleared_at            DATETIME,
    cleared_by            INTEGER REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS marketing_segments (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    name                  TEXT NOT NULL,
    description           TEXT,
    segment_type          TEXT NOT NULL DEFAULT 'manual',
    match_type            TEXT NOT NULL DEFAULT 'all',
    rules_json            TEXT NOT NULL DEFAULT '[]',
    created_at            DATETIME DEFAULT (datetime('now')),
    updated_at            DATETIME
);

CREATE TABLE IF NOT EXISTS marketing_segment_members (
    segment_id            INTEGER NOT NULL REFERENCES marketing_segments(id) ON DELETE CASCADE,
    contact_id            INTEGER NOT NULL REFERENCES marketing_contacts(id) ON DELETE CASCADE,
    action                TEXT NOT NULL DEFAULT 'include',
    created_at            DATETIME DEFAULT (datetime('now')),
    PRIMARY KEY (segment_id, contact_id)
);
CREATE INDEX IF NOT EXISTS idx_marketing_segment_members_contact ON marketing_segment_members(contact_id);

CREATE TABLE IF NOT EXISTS email_assets (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    public_token          TEXT NOT NULL UNIQUE,
    original_name         TEXT NOT NULL,
    file_path             TEXT NOT NULL,
    mime_type             TEXT NOT NULL,
    file_size             INTEGER NOT NULL,
    alt_text              TEXT,
    created_at            DATETIME DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS email_templates (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    name                  TEXT NOT NULL,
    description           TEXT,
    subject               TEXT,
    preheader             TEXT,
    content_json          TEXT NOT NULL,
    status                TEXT NOT NULL DEFAULT 'active',
    created_at            DATETIME DEFAULT (datetime('now')),
    updated_at            DATETIME
);

CREATE TABLE IF NOT EXISTS email_campaigns (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    name                  TEXT NOT NULL,
    segment_id            INTEGER REFERENCES marketing_segments(id) ON DELETE SET NULL,
    template_id           INTEGER REFERENCES email_templates(id) ON DELETE SET NULL,
    status                TEXT NOT NULL DEFAULT 'draft',
    subject               TEXT NOT NULL DEFAULT '',
    preheader             TEXT,
    content_json          TEXT NOT NULL,
    rendered_html         TEXT,
    rendered_text         TEXT,
    from_name             TEXT,
    from_email            TEXT,
    reply_to              TEXT,
    tracking_opens        INTEGER NOT NULL DEFAULT 1,
    tracking_clicks       INTEGER NOT NULL DEFAULT 1,
    scheduled_at          DATETIME,
    started_at            DATETIME,
    completed_at          DATETIME,
    cancelled_at          DATETIME,
    total_recipients      INTEGER NOT NULL DEFAULT 0,
    excluded_recipients   INTEGER NOT NULL DEFAULT 0,
    created_at            DATETIME DEFAULT (datetime('now')),
    updated_at            DATETIME
);

CREATE TABLE IF NOT EXISTS email_campaign_recipients (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id           INTEGER NOT NULL REFERENCES email_campaigns(id) ON DELETE CASCADE,
    contact_id            INTEGER REFERENCES marketing_contacts(id) ON DELETE SET NULL,
    name                  TEXT,
    company_name          TEXT,
    email                 TEXT NOT NULL,
    email_norm            TEXT NOT NULL,
    status                TEXT NOT NULL DEFAULT 'pending',
    mailgun_message_id    TEXT,
    unsubscribe_token      TEXT NOT NULL UNIQUE, -- encrypted via Services\Secrets
    attempts              INTEGER NOT NULL DEFAULT 0,
    next_attempt_at       DATETIME,
    last_error            TEXT,
    processing_started_at DATETIME,
    accepted_at           DATETIME,
    delivered_at          DATETIME,
    failed_at             DATETIME,
    created_at            DATETIME DEFAULT (datetime('now')),
    UNIQUE(campaign_id, email_norm)
);
CREATE INDEX IF NOT EXISTS idx_email_recipients_queue ON email_campaign_recipients(campaign_id, status, next_attempt_at);
CREATE INDEX IF NOT EXISTS idx_email_recipients_message ON email_campaign_recipients(mailgun_message_id);

CREATE TABLE IF NOT EXISTS email_send_attempts (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient_id          INTEGER NOT NULL REFERENCES email_campaign_recipients(id) ON DELETE CASCADE,
    attempt_number        INTEGER NOT NULL,
    result                TEXT NOT NULL,
    provider_message_id   TEXT,
    error_message         TEXT,
    attempted_at          DATETIME NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS email_events (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    recipient_id          INTEGER REFERENCES email_campaign_recipients(id) ON DELETE SET NULL,
    campaign_id           INTEGER REFERENCES email_campaigns(id) ON DELETE SET NULL,
    contact_id            INTEGER REFERENCES marketing_contacts(id) ON DELETE SET NULL,
    provider_event_id     TEXT,
    event_type            TEXT NOT NULL,
    event_at              DATETIME NOT NULL,
    url                   TEXT,
    is_bot                INTEGER NOT NULL DEFAULT 0,
    severity              TEXT,
    details               TEXT,
    created_at            DATETIME DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_email_events_provider ON email_events(provider_event_id, event_type, event_at);
CREATE INDEX IF NOT EXISTS idx_email_events_campaign ON email_events(campaign_id, event_type);

CREATE TABLE IF NOT EXISTS email_webhook_receipts (
    token                 TEXT PRIMARY KEY,
    received_at           DATETIME NOT NULL DEFAULT (datetime('now'))
);

-- Safe initial import: every CRM email becomes a contact, but remains blocked
-- from campaigns until its legal basis is explicitly reviewed.
INSERT OR IGNORE INTO marketing_contacts
    (name, email, email_norm, company_name, status, eligibility_basis)
SELECT contact_name, trim(contact_email), lower(trim(contact_email)), name, 'active', 'unknown'
FROM clients
WHERE trim(COALESCE(contact_email, '')) <> '';

INSERT OR IGNORE INTO marketing_contact_clients (contact_id, client_id, is_primary)
SELECT mc.id, c.id, 1
FROM clients c
JOIN marketing_contacts mc ON mc.email_norm = lower(trim(c.contact_email))
WHERE trim(COALESCE(c.contact_email, '')) <> '';

INSERT OR IGNORE INTO marketing_segments
    (id, name, description, segment_type, match_type, rules_json)
VALUES
    (1, 'Active WordPress clients', 'Contacts linked to active clients with an active WordPress site.', 'dynamic', 'all',
     '[{"field":"client.status","operator":"equals","value":"active"},{"field":"site.website_stack","operator":"equals","value":"wordpress"},{"field":"site.status","operator":"equals","value":"active"}]');
