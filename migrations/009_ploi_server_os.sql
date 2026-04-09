-- Add OS name and version columns to ploi_servers.

ALTER TABLE ploi_servers ADD COLUMN os_name TEXT;
ALTER TABLE ploi_servers ADD COLUMN os_version TEXT;
