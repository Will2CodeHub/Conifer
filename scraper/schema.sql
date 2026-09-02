-- TEN Scraper schema (Phase 1). Add-only. Apply to TEN_Management DB.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ten_scraper_projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `type` enum('news_collation','email_collection') NOT NULL DEFAULT 'news_collation',
  `default_ai_provider` enum('anthropic','openai') NOT NULL DEFAULT 'anthropic',
  `default_ai_model` varchar(100) NOT NULL DEFAULT 'claude-sonnet-5',
  `default_prompt` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_project_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_pub_sections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `publication_key` varchar(50) NOT NULL,
  `ten_section` varchar(50) NOT NULL,
  `daily_count` int(11) NOT NULL DEFAULT 10,
  `cron_schedule` varchar(100) NOT NULL DEFAULT '0 6 * * *',
  `vpn_profile_id` int(11) DEFAULT NULL,
  `journalist_id` int(11) DEFAULT NULL,
  `ai_provider` enum('anthropic','openai') DEFAULT NULL,
  `ai_model` varchar(100) DEFAULT NULL,
  `prompt` text DEFAULT NULL,
  `auto_publish` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_project` (`project_id`),
  UNIQUE KEY `uq_pub_section` (`project_id`,`publication_key`,`ten_section`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_sources` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `pub_section_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `homepage_url` varchar(500) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pub_section` (`pub_section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_feeds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_id` int(11) NOT NULL,
  `feed_url` varchar(500) NOT NULL,
  `feed_type` enum('rss','html') NOT NULL DEFAULT 'rss',
  `source_category_label` varchar(100) DEFAULT NULL,
  `html_selectors` text DEFAULT NULL,
  `respect_robots` tinyint(1) NOT NULL DEFAULT 1,
  `robots_override_reason` varchar(500) DEFAULT NULL,
  `rate_limit_seconds` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_source` (`source_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_items` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `feed_id` int(11) NOT NULL,
  `pub_section_id` int(11) NOT NULL,
  `source_url` varchar(1000) NOT NULL,
  `source_url_hash` varchar(64) NOT NULL,
  `title` varchar(1000) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `facts` mediumtext DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `cluster_id` varchar(64) DEFAULT NULL,
  `image_suggestions` text DEFAULT NULL,
  `status` enum('new','selected','promoting','promoted','discarded') NOT NULL DEFAULT 'new',
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_source_hash` (`pub_section_id`,`source_url_hash`),
  KEY `idx_status` (`pub_section_id`,`status`),
  KEY `idx_cluster` (`cluster_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_drafts` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) NOT NULL,
  `generated_title` varchar(1000) DEFAULT NULL,
  `generated_body` longtext DEFAULT NULL,
  `meta_title` varchar(200) DEFAULT NULL,
  `meta_description` varchar(500) DEFAULT NULL,
  `meta_keywords` varchar(500) DEFAULT NULL,
  `ai_provider` varchar(50) DEFAULT NULL,
  `ai_model` varchar(100) DEFAULT NULL,
  `prompt_used` text DEFAULT NULL,
  `journalist_id` int(11) DEFAULT NULL,
  `article_id` int(10) UNSIGNED DEFAULT NULL,
  `status` enum('draft','promoted','error') NOT NULL DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_vpn_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `provider` enum('protonvpn','wireguard','openvpn') NOT NULL DEFAULT 'protonvpn',
  `name` varchar(150) NOT NULL,
  `country` varchar(80) DEFAULT NULL,
  `config_ref` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ten_scraper_runs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `pub_section_id` int(11) DEFAULT NULL,
  `started` timestamp NOT NULL DEFAULT current_timestamp(),
  `finished` datetime DEFAULT NULL,
  `items_found` int(11) NOT NULL DEFAULT 0,
  `items_new` int(11) NOT NULL DEFAULT 0,
  `status` enum('running','ok','error') NOT NULL DEFAULT 'running',
  `log` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pub_section` (`pub_section_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the two projects. The default News Collation prompt encodes the safety posture (spec section 2).
INSERT INTO `ten_scraper_projects` (`name`,`type`,`default_ai_provider`,`default_ai_model`,`default_prompt`,`is_active`)
VALUES
('News Collation','news_collation','anthropic','claude-sonnet-5',
'You are an experienced staff journalist for {publication}, writing an ORIGINAL news article for the {section} section in {target_language}.\n\nYou are given the verified facts of a news event and a link to the source that reported it:\nTitle: {source_title}\nSummary: {source_summary}\nFacts: {source_facts}\nSource: {source_url}\n\nRules:\n1. Write your OWN original article from these facts. Do NOT paraphrase, translate, or track the structure of the source article - report the underlying news in your own words and structure.\n2. Facts are not owned; the source''s wording is. Never reproduce sentences or distinctive phrasing from the source.\n3. Attribute where appropriate (e.g. "according to {source_url}") and keep claims to what the facts support. If a fact is uncertain, say so rather than inventing detail. Never fabricate quotes, names, numbers, or events.\n4. Neutral, factual news register. No opinion, no editorialising, no first person.\n5. SEO: choose one clear target keyword from the topic. Produce an SEO title (<=60 chars), a meta description (<=155 chars), 3-6 comma-separated meta keywords, and use sensible H2/H3 subheadings in the body.\n6. Do NOT reference or embed any source image.\n\nReturn ONLY valid JSON with keys: title, body_html, meta_title, meta_description, meta_keywords.',
1),
('Cafe/Restaurant Email Collection','email_collection','anthropic','claude-sonnet-5', NULL, 1)
ON DUPLICATE KEY UPDATE `type`=VALUES(`type`), `is_active`=VALUES(`is_active`);
