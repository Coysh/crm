-- Drop the service_packages table entirely.
-- FreeAgent recurring invoices (freeagent_recurring_invoices) are now the
-- source of truth for expected recurring income per client.
-- Manual service package data is no longer needed.

DROP TABLE IF EXISTS service_packages;
