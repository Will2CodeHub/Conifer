-- Feature batch migration. Add-only. Run each block against the noted database.

-- 1. Separate translation model (project-level) — TEN_Management
ALTER TABLE TEN_Management.ten_scraper_projects
  ADD COLUMN translation_provider enum('anthropic','openai') NOT NULL DEFAULT 'anthropic',
  ADD COLUMN translation_model varchar(100) NOT NULL DEFAULT 'claude-haiku-4-5';

-- 2. Per-feed article limit — TEN_Management
ALTER TABLE TEN_Management.ten_scraper_feeds
  ADD COLUMN max_items int(11) NOT NULL DEFAULT 20;

-- 3. Per-publication daily translation cap (0 = unlimited) — admin_ten
ALTER TABLE admin_ten.publications
  ADD COLUMN max_daily_translations int(11) NOT NULL DEFAULT 0;

-- 3b. When an item was translated (for the daily cap) — TEN_Management
ALTER TABLE TEN_Management.ten_scraper_items
  ADD COLUMN translated_at datetime DEFAULT NULL;
