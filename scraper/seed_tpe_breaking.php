<?php
/**
 * One-off seed: create The Paris Eye "Breaking News" scraper section by cloning
 * The Munich Eye's Breaking News section (its sources + feeds), so Paris draws
 * from the same global/world-news feeds. Breaking news is global headlines and
 * the AI rewrite is deliberately non-localised, so the same sources fit.
 *
 * Idempotent — if a 'tpe' Breaking News section already exists it does nothing
 * (it will NOT duplicate sources/feeds on a re-run). Token-guarded like the crons.
 *   CLI : php /path/to/management/scraper/seed_tpe_breaking.php
 *   HTTP: curl -s "https://theeyenewspapers.com/management/scraper/seed_tpe_breaking.php?t=seedtpe_9x2k7Q"
 */
ini_set('display_errors', '0');
@set_time_limit(0);

$SEED_TOKEN = 'seedtpe_9x2k7Q';
$isCli = (php_sapi_name() === 'cli');
if (!$isCli && (($_GET['t'] ?? '') !== $SEED_TOKEN)) { http_response_code(404); exit('not found'); }

require_once __DIR__ . '/../config.php';

$SRC_PUB = 'tme';   // clone FROM
$DST_PUB = preg_replace('/[^a-z]/', '', strtolower($_GET['to'] ?? 'tpe')); // clone TO (?to=tbere)
if ($DST_PUB === '' || $DST_PUB === $SRC_PUB) { http_response_code(400); exit('bad ?to'); }
$SECTION = 'Breaking News';

$out = ['from' => $SRC_PUB, 'to' => $DST_PUB, 'section' => $SECTION];
$c = getDBConnection();

try {
    // Source section (Munich Breaking News).
    $st = $c->prepare("SELECT * FROM ten_scraper_pub_sections WHERE publication_key=? AND ten_section=? LIMIT 1");
    $st->bind_param('ss', $SRC_PUB, $SECTION);
    $st->execute();
    $srcSection = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$srcSection) { throw new Exception("Source section {$SRC_PUB}/{$SECTION} not found — nothing to clone"); }

    // Already seeded? (idempotent)
    $st = $c->prepare("SELECT id FROM ten_scraper_pub_sections WHERE publication_key=? AND ten_section=? LIMIT 1");
    $st->bind_param('ss', $DST_PUB, $SECTION);
    $st->execute();
    $existing = $st->get_result()->fetch_assoc();
    $st->close();
    if ($existing) {
        $out['status'] = 'already exists';
        $out['dst_section_id'] = (int)$existing['id'];
        echo_result($out, $isCli); $c->close(); return;
    }

    // Create the Paris section from Munich's settings. journalist_id is left NULL so
    // the Paris in-house author is used; auto_publish/is_active mirror Munich.
    $ins = $c->prepare(
        "INSERT INTO ten_scraper_pub_sections
           (project_id, publication_key, ten_section, daily_count, cron_schedule,
            vpn_profile_id, journalist_id, ai_provider, ai_model, prompt, auto_publish, is_active)
         VALUES (?,?,?,?,?,?,NULL,?,?,?,?,?)"
    );
    $projectId    = (int)$srcSection['project_id'];
    $dailyCount   = (int)$srcSection['daily_count'];
    $cronSchedule = (string)$srcSection['cron_schedule'];
    $vpnProfileId = $srcSection['vpn_profile_id'] !== null ? (int)$srcSection['vpn_profile_id'] : null;
    $aiProvider   = $srcSection['ai_provider'];
    $aiModel      = $srcSection['ai_model'];
    $prompt       = $srcSection['prompt'];
    $autoPublish  = (int)$srcSection['auto_publish'];
    $isActive     = (int)$srcSection['is_active'];
    $ins->bind_param(
        'issisisssii',
        $projectId, $DST_PUB, $SECTION, $dailyCount, $cronSchedule,
        $vpnProfileId, $aiProvider, $aiModel, $prompt, $autoPublish, $isActive
    );
    $ins->execute();
    $dstSectionId = (int)$ins->insert_id;
    $ins->close();
    $out['dst_section_id'] = $dstSectionId;

    // Clone sources + their feeds.
    $srcSectionId = (int)$srcSection['id'];
    $sources = $c->query("SELECT * FROM ten_scraper_sources WHERE pub_section_id={$srcSectionId} ORDER BY id");
    $srcCount = 0; $feedCount = 0;
    while ($s = $sources->fetch_assoc()) {
        $si = $c->prepare("INSERT INTO ten_scraper_sources (pub_section_id, name, homepage_url, is_active) VALUES (?,?,?,?)");
        $sName = $s['name']; $sHome = $s['homepage_url']; $sActive = (int)$s['is_active'];
        $si->bind_param('issi', $dstSectionId, $sName, $sHome, $sActive);
        $si->execute();
        $newSourceId = (int)$si->insert_id;
        $si->close();
        $srcCount++;

        $oldSourceId = (int)$s['id'];
        $feeds = $c->query("SELECT * FROM ten_scraper_feeds WHERE source_id={$oldSourceId} ORDER BY id");
        while ($f = $feeds->fetch_assoc()) {
            $fi = $c->prepare(
                "INSERT INTO ten_scraper_feeds
                   (source_id, feed_url, feed_type, source_category_label, html_selectors,
                    respect_robots, robots_override_reason, rate_limit_seconds, is_active)
                 VALUES (?,?,?,?,?,?,?,?,?)"
            );
            $fUrl   = $f['feed_url'];
            $fType  = $f['feed_type'];
            $fLabel = $f['source_category_label'];
            $fSel   = $f['html_selectors'];
            $fRob   = (int)$f['respect_robots'];
            $fReason= $f['robots_override_reason'];
            $fRate  = $f['rate_limit_seconds'] !== null ? (int)$f['rate_limit_seconds'] : null;
            $fActive= (int)$f['is_active'];
            $fi->bind_param('issssisii', $newSourceId, $fUrl, $fType, $fLabel, $fSel, $fRob, $fReason, $fRate, $fActive);
            $fi->execute();
            $fi->close();
            $feedCount++;
        }
    }

    $out['status']       = 'created';
    $out['sources_copied'] = $srcCount;
    $out['feeds_copied']   = $feedCount;
} catch (Throwable $e) {
    $out['status'] = 'error';
    $out['error']  = $e->getMessage();
}

$c->close();
echo_result($out, $isCli);

function echo_result(array $out, bool $isCli): void {
    if (!$isCli) { header('Content-Type: application/json'); }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
