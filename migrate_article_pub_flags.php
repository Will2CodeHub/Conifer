<?php
/**
 * One-shot migration for per-publication article flags.
 *
 *   ?token=apf_migrate_7q2
 *       -> creates admin_ten.article_pub_flags (idempotent)
 *   ?token=apf_migrate_7q2&backfill=1[&after=<id>&batch=<n>]
 *       -> backfills one row per (article, publication) from the article's current global
 *          flags (frontpage_temp/featured/sponsored), in id-ascending chunks. Prints either
 *          "NEXT=<lastId>" (call again with after=<lastId>) or "ALL DONE". Safe to re-run
 *          (ON DUPLICATE KEY UPDATE).
 *
 * DELETE THIS FILE after running.
 */
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_ten_admin.php';
header('Content-Type: text/plain; charset=utf-8');

if (($_GET['token'] ?? '') !== 'apf_migrate_7q2') { http_response_code(403); echo "forbidden\n"; exit; }

try {
    $conn = getDBConnection_TENAdmin();

    if (($_GET['cols'] ?? '') === '1') {
        foreach (['articles', 'article_pub_flags'] as $t) {
            $r = @$conn->query("SHOW COLUMNS FROM `$t`");
            $cols = [];
            while ($r && ($row = $r->fetch_assoc())) { $cols[] = $row['Field']; }
            echo "$t columns:\n" . ($cols ? implode(", ", $cols) : '(table missing)') . "\n\n";
        }
        $conn->close();
        exit;
    }
    if (($_GET['recreate'] ?? '') === '1') {
        $conn->query("DROP TABLE IF EXISTS article_pub_flags");
        echo "dropped article_pub_flags\n";
    }

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
        echo "\nTable created. Add &backfill=1 to backfill. Then DELETE this file.\n";
        $conn->close();
        exit;
    }

    $after = (int) ($_GET['after'] ?? 0);
    $batch = (int) ($_GET['batch'] ?? 3000);
    if ($batch < 1 || $batch > 10000) { $batch = 3000; }

    $sel = $conn->prepare("SELECT id, publications, frontpage_temp, featured, sponsored
                           FROM articles
                           WHERE id > ? AND publications IS NOT NULL AND publications <> ''
                           ORDER BY id ASC LIMIT ?");
    $sel->bind_param('ii', $after, $batch);
    $sel->execute();
    $res = $sel->get_result();

    $ins = $conn->prepare("INSERT INTO article_pub_flags (article_id, publication, frontpage, section_head, sponsored)
                           VALUES (?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE frontpage=VALUES(frontpage), section_head=VALUES(section_head), sponsored=VALUES(sponsored)");

    $articles = 0; $rows = 0; $lastId = $after;
    $conn->begin_transaction();
    while ($a = $res->fetch_assoc()) {
        $lastId = (int) $a['id'];
        $fp = (int) $a['frontpage_temp'];
        $sh = (int) $a['featured'];
        $sp = (int) $a['sponsored'];
        $pubs = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $a['publications'])))));
        foreach ($pubs as $pub) {
            if ($pub === '' || mb_strlen($pub) > 32) { continue; }
            $ins->bind_param('isiii', $lastId, $pub, $fp, $sh, $sp);
            $ins->execute();
            $rows++;
        }
        $articles++;
    }
    $conn->commit();
    $sel->close();
    $ins->close();

    echo "chunk: $articles articles, $rows pub-rows (after=$after, lastId=$lastId)\n";
    if ($articles < $batch) {
        echo "ALL DONE. DELETE this file now.\n";
    } else {
        echo "NEXT=$lastId\n";
    }
    $conn->close();
} catch (Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
