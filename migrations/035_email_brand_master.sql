-- Global email branding and editable master wrapper.

ALTER TABLE email_marketing_config ADD COLUMN logo_url TEXT;
ALTER TABLE email_marketing_config ADD COLUMN master_html TEXT;

UPDATE email_marketing_config
SET brand_colour = '#a1c63e'
WHERE brand_colour IS NULL OR lower(brand_colour) = '#4f46e5';
