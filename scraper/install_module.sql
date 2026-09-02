-- Register the scraper module in TEN_Management nav + permission system. Add-only, idempotent.

INSERT INTO `ten_modules`
  (`module_key`,`module_name`,`module_description`,`module_icon`,`module_url`,`module_group`,`is_enabled`,`is_system`,`display_order`,`required_permission`)
VALUES
  ('scraper','News Scraper','Collate sources and generate original articles','fa-newspaper','/management/module-scraper.php','content',1,0,50,'scraper.use')
ON DUPLICATE KEY UPDATE
  `module_name`=VALUES(`module_name`),
  `module_description`=VALUES(`module_description`),
  `module_icon`=VALUES(`module_icon`),
  `module_url`=VALUES(`module_url`),
  `module_group`=VALUES(`module_group`),
  `required_permission`=VALUES(`required_permission`);

INSERT INTO `ten_permissions`
  (`permission_key`,`permission_name`,`permission_description`,`module`,`permission_type`,`is_system`)
VALUES
  ('scraper.use','Use Scraper','Select and promote scraped articles','scraper','execute',0),
  ('scraper.manage','Manage Scraper','Configure projects, sources, prompts, models and VPN','scraper','manage',0)
ON DUPLICATE KEY UPDATE
  `permission_name`=VALUES(`permission_name`),
  `permission_description`=VALUES(`permission_description`);

-- Link permissions to the module id.
UPDATE `ten_permissions` p
  JOIN `ten_modules` m ON m.module_key = 'scraper'
  SET p.module_id = m.id
  WHERE p.permission_key IN ('scraper.use','scraper.manage');

-- Grant both permissions to super_user and admin roles (no-dupe insert).
INSERT INTO `ten_role_permissions` (`role_id`,`permission_id`)
SELECT r.id, p.id
FROM `ten_roles` r
JOIN `ten_permissions` p ON p.permission_key IN ('scraper.use','scraper.manage')
WHERE r.role_key IN ('super_user','admin')
  AND NOT EXISTS (
    SELECT 1 FROM `ten_role_permissions` rp
    WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
