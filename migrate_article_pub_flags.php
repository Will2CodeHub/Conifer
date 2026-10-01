<?php
/**
 * One-shot migration for per-publication article flags.
 *
 *   https://theeyenewspapers.com/management/migrate_article_pub_flags.php?token=apf_migrate_7q2
 *       -> creates admin_ten.article_pub_flags (idempotent)
 *   ...&backfill=1
 *       -> also backfills one row per (article, publication) from the article's current
 *          global flags (frontpage_temp/featured/sponsored). Safe to re-run (ON DUPLICATE).
 *
 * DELETE THIS FILE after running.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_ten_admin.php';
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['token'] ?? '') !== 'apf_migrate_7q2') { http_response_code(403); echo "forbidden\n"; exit; }

$conn = getDBConnection_TENAdmin();

$conn->query("CREATE TABLE IF NOT EXISTS article_pub_flags (
    article_id   INT NOT NULL,
    publication  VARCHAR(32) NOT NULL,
    frontpage    TINYINT(1) NOT NULL DEFAULT 0,
    section_head TINYINT(1) NOT NULL DEFAULT 0,
    sponsored    TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (article_id, publication),
    KEY idx_pub_front (publication, frontpage),
    KEY idx_pub_section (publication, section_head)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
echo "table article_pub_flags ready\n";

if (($_GET['backfill'] ?? '') !== '1') {
    echo "\nTable created. Add &backfill=1 to the URL to backfill from existing global flags.\n";
    echo "Then DELETE this file.\n";
    $conn->close();
    exit;
}

$res = $conn->query("SELECT id, publications, frontpage_temp, featured, sponsored
                     FROM articles WHERE publications IS NOT NULL AND publications <> ''");
$ins = $conn->prepare("INSERT INTO article_pub_flags (article_id, publication, frontpage, section_head, sponsored)
                       VALUES (?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE frontpage=VALUES(frontpage), section_head=VALUES(section_head), sponsored=VALUES(sponsored)");

$articles = 0; $rows = 0;
$conn->begin_transaction();
while ($res && ($a = $res->fetch_assoc())) {
    $aid = (int) $a['id'];
    $fp  = (int) $a['frontpage_temp'];
    $sh  = (int) $a['featured'];
    $sp  = (int) $a['sponsored'];
    $pubs = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $a['publications'])))));
    foreach ($pubs as $pub) {
        if ($pub === '' || mb_strlen($pub) > 32) { continue; }
        $ins->bind_param('isiii', $aid, $pub, $fp, $sh, $sp);
        $ins->execute();
        $rows++;
    }
    $articles++;
    if ($articles % 2000 === 0) { $conn->commit(); $conn->begin_transaction(); }
}
$conn->commit();
$ins->close();

echo "backfilled $rows pub-rows across $articles articles\n";
echo "\nDONE. DELETE this file now.\n";
$conn->close();
