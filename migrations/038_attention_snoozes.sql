-- Snoozed / dismissed items on the Today attention list (Services\Attention).
-- item_key identifies one incident (e.g. renewal:domain:34:2026-10-01), so a
-- dismissal never hides the next occurrence. snoozed_until NULL = dismissed.
CREATE TABLE IF NOT EXISTS attention_snoozes (
    item_key      TEXT PRIMARY KEY,
    snoozed_until DATE,
    created_at    DATETIME NOT NULL DEFAULT (datetime('now'))
);
