-- Phase 5 migration. Add-only. Run once.

-- Publication-level target language (admin_ten DB).
ALTER TABLE `publications`
  ADD COLUMN `target_language` varchar(40) NOT NULL DEFAULT 'English';

-- Cached translations on collated items (TEN_Management DB).
ALTER TABLE `ten_scraper_items`
  ADD COLUMN `title_translated` varchar(1000) DEFAULT NULL,
  ADD COLUMN `summary_translated` text DEFAULT NULL,
  ADD COLUMN `translated_lang` varchar(40) DEFAULT NULL;
