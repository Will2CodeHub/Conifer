-- Editable curation (ranking) prompt: per-section override + project-wide default.
-- Add-only migration; run once against the live DB. No backfill needed — a NULL/empty
-- value falls back to the project default, then to the built-in SCRAPER_DEFAULT_CURATE_BRIEF
-- constant in scraper/lib/scraper_review.php, so curation behaves identically until edited.

ALTER TABLE ten_scraper_pub_sections
  ADD COLUMN curate_prompt TEXT NULL AFTER prompt;

ALTER TABLE ten_scraper_projects
  ADD COLUMN default_curate_prompt TEXT NULL AFTER default_prompt;
