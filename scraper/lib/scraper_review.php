<?php
/**
 * Phase 5: review queue (translate-on-load, cached) + promote (AI write -> Article Tool).
 */

require_once __DIR__ . '/scraper_crud.php';     // scraper_get_section, scraper_get_project
require_once __DIR__ . '/news_sites_db.php';     // ns_publication_language
require_once __DIR__ . '/ScraperAI.php';         // scraper_ai_translate, scraper_ai_write_article
require_once __DIR__ . '/ImageSearch.php';       // scraper_image_search (royalty-free suggestions)

/** Resolve effective AI provider/model/prompt for a section (section overrides project). */
function scraper_effective_ai(array $section): array {
    $project = scraper_get_project((int)$section['project_id']) ?: [];
    return [
        'provider' => ($section['ai_provider'] ?? '') ?: ($project['default_ai_provider'] ?? 'anthropic'),
        'model'    => ($section['ai_model'] ?? '') ?: ($project['default_ai_model'] ?? 'claude-sonnet-5'),
        'prompt'   => ($section['prompt'] ?? '') ?: ($project['default_prompt'] ?? ''),
    ];
}

/**
 * The cheap dedicated translation model (project-level), used for bulk title/summary
 * translation — a trivial task where a Haiku/mini-class model matches the big models at
 * a fraction of the cost. Falls back to Claude Haiku. (The smart model is reserved for
 * article writing and curation ranking.)
 */
function scraper_translation_ai(array $section): array {
    $project = scraper_get_project((int)$section['project_id']) ?: [];
    return [
        'provider' => ($project['translation_provider'] ?? '') ?: 'anthropic',
        'model'    => ($project['translation_model'] ?? '') ?: 'claude-haiku-4-5',
    ];
}

/**
 * Fetch an article page at promote time and extract condensed facts (the main
 * paragraphs) for the AI writer. Best-effort: returns '' on any failure. Done
 * here (not at ingest) so only the items actually being published are fetched.
 */
function scraper_fetch_facts(string $url, int $maxChars = 5000): string {
    $url = trim($url);
    if ($url === '') return '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TENScraper/1.0; +https://theeyenewspapers.com)',
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!is_string($html) || $html === '') return '';
    // Drop non-content blocks, then pull the substantive paragraphs.
    $html = preg_replace('#<(script|style|noscript|nav|header|footer|aside|form)\b[^>]*>.*?</\1>#is', ' ', $html);
    $facts = '';
    if (preg_match_all('#<p\b[^>]*>(.*?)</p>#is', $html, $mm)) {
        $paras = [];
        foreach ($mm[1] as $p) {
            $t = trim(preg_replace('/\s+/', ' ', strip_tags($p)));
            if (mb_strlen($t) > 40) $paras[] = $t;   // real sentences, not menu items
        }
        $facts = implode("\n\n", array_slice($paras, 0, 8));
    }
    if ($facts === '') {
        $facts = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    }
    return mb_substr($facts, 0, $maxChars);
}

/* ============================ Curate tab ============================ */

/** Publications with an active section, one primary section each (News preferred). */
function scraper_curate_publications(): array {
    $conn = getDBConnection();
    $res = $conn->query("SELECT id, publication_key, ten_section, daily_count FROM ten_scraper_pub_sections WHERE is_active=1 ORDER BY publication_key ASC");
    $pick = [];
    while ($r = $res->fetch_assoc()) {
        $k = $r['publication_key'];
        if (!isset($pick[$k]) || strcasecmp($r['ten_section'], 'News') === 0) $pick[$k] = $r; // prefer News
    }
    $out = [];
    foreach ($pick as $k => $r) {
        $sid = (int)$r['id'];
        $c = $conn->query("SELECT COUNT(*) c FROM ten_scraper_items WHERE pub_section_id=$sid AND status='new'")->fetch_assoc()['c'];
        $out[] = ['publication_key' => $k, 'section_id' => $sid, 'ten_section' => $r['ten_section'], 'top_n' => (int)$r['daily_count'], 'new_count' => (int)$c];
    }
    $conn->close();
    return $out;
}

/** Human region/place name for a publication key, used to steer local ranking. */
function scraper_publication_region(string $pub): string {
    $map = [
        'tme' => 'Munich and Bavaria, Germany', 'tge' => 'Germany',
        'ten' => 'the world (international English-speaking readers)',
        'bae' => 'Buenos Aires and Argentina', 'tbrae' => 'Brazil', 'truse' => 'Russia',
        'tbare' => 'Barcelona and Catalonia, Spain', 'tte' => 'Tokyo and Japan',
        'tmae' => 'Madrid and Spain', 'tce' => 'the Canary Islands, Spain', 'tpe' => 'Paris and France',
    ];
    return $map[strtolower(trim($pub))] ?? $pub;
}

/** AI-rank a section's 'new' items and return the top N (N from daily_count). */
function scraper_curate_rank(int $pubSectionId, int $topN): array {
    $section = scraper_get_section($pubSectionId);
    if (!$section || $topN < 1) return ['items' => []];
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT id, COALESCE(NULLIF(title_translated,''),title) AS t, COALESCE(NULLIF(summary_translated,''),summary) AS s
                            FROM ten_scraper_items WHERE pub_section_id=? AND status='new'
                            ORDER BY published_at DESC, id DESC LIMIT 80");
    $stmt->bind_param('i', $pubSectionId); $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close(); $conn->close();
    if (!$rows) return ['items' => []];

    $byId = []; $lines = '';
    foreach ($rows as $r) { $byId[(int)$r['id']] = $r; $lines .= (int)$r['id'] . ': ' . preg_replace('/\s+/', ' ', $r['t']) . "\n"; }

    // Always ask the AI (it both ranks AND translates the chosen headlines into
    // English — the source feeds are in many languages).
    $ai = scraper_effective_ai($section);
    $pub = $section['publication_key'];
    $region = scraper_publication_region($pub);
    $isWorld = (strtolower($pub) === 'ten');
    $focus = $isWorld
        ? "Choose the biggest, most significant international news stories of broad interest to a global English-speaking audience."
        : "STRONGLY prioritise LOCAL and national news for {$region} and its readers — politics, economy, society, culture, sport and events happening in or directly affecting {$region}. Only include an international/world story if it is genuinely major AND clearly relevant to {$region}; prefer a local story over a generic world story.";
    $system = "You are the news editor of a local English-language newspaper covering {$region}. {$focus} Drop trivia, near-duplicates and clickbait. Return ONLY JSON.";
    $user = "From these " . count($rows) . " collated headlines (id: headline), choose the TOP " . min($topN, count($rows)) . " for this publication's readers. "
          . "The headlines may be in ANY language — translate each chosen one into natural English. "
          . "Return JSON {\"top\":[{\"id\":<id>,\"title\":\"<the headline in ENGLISH>\",\"reason\":\"<max 12 words why>\"}]}, best first.\n\n" . $lines;
    $decoded = null;
    try {
        $text = scraper_ai_raw($ai['provider'], $ai['model'], $system, $user, 2000, $ai['provider'] === 'openai');
        $decoded = scraper_ai_decode_json($text);
    } catch (Throwable $e) { $decoded = null; }

    $items = [];
    if ($decoded && !empty($decoded['top']) && is_array($decoded['top'])) {
        foreach ($decoded['top'] as $t) {
            $id = (int)($t['id'] ?? 0);
            if (isset($byId[$id])) {
                $en = trim((string)($t['title'] ?? ''));
                $items[] = ['id' => $id, 'title' => $en !== '' ? $en : $byId[$id]['t'], 'summary' => $byId[$id]['s'], 'reason' => mb_substr((string)($t['reason'] ?? ''), 0, 120)];
                unset($byId[$id]);
            }
        }
    }
    if (!$items) { // fallback to recency if the model failed
        foreach (array_slice($rows, 0, $topN) as $r) $items[] = ['id' => (int)$r['id'], 'title' => $r['t'], 'summary' => $r['s'], 'reason' => ''];
    }
    return ['items' => array_slice($items, 0, $topN)];
}

/**
 * CRON precompute for one section: translate every 'new' item into English (cached on
 * the row) and AI-rank the top N (daily_count) into curate_rank/curate_reason. Run by
 * scraper/cron/precompute.php so the Curate screen only ever reads pre-computed rows.
 */
function scraper_precompute_section(int $pubSectionId): array {
    $section = scraper_get_section($pubSectionId);
    if (!$section || (int)$section['is_active'] !== 1) return ['skipped' => 'inactive', 'more' => false];
    $ai = scraper_translation_ai($section); // cheap model for bulk title/summary translation

    // 1) Translate a bounded batch of untranslated 'new' items to English. Capped so a
    //    single run always finishes well within the HTTP/CLI budget; remaining items are
    //    picked up on the next run (the section keeps matching until fully translated).
    $CAP = 60;
    $conn = getDBConnection();
    $rows = $conn->query("SELECT id, title, summary FROM ten_scraper_items
                          WHERE pub_section_id=$pubSectionId AND status='new'
                            AND (title_translated IS NULL OR title_translated=''
                                 OR translated_lang IS NULL OR translated_lang<>'English')
                          ORDER BY id DESC LIMIT $CAP")->fetch_all(MYSQLI_ASSOC);
    $translated = 0;
    if ($rows) {
        $payload = array_map(function ($r) { return ['id' => (int)$r['id'], 'title' => $r['title'], 'summary' => (string)$r['summary']]; }, $rows);
        try {
            $tr = scraper_ai_translate($ai['provider'], $ai['model'], 'English', $payload);
        } catch (Throwable $e) { $tr = []; }
        if ($tr) {
            $up = $conn->prepare("UPDATE ten_scraper_items SET title_translated=?, summary_translated=?, translated_lang='English', translated_at=NOW() WHERE id=?");
            foreach ($tr as $id => $t) {
                $ti = (string)$t['title']; $su = (string)$t['summary']; $iid = (int)$id;
                $up->bind_param('ssi', $ti, $su, $iid); $up->execute(); $translated++;
            }
            $up->close();
        }
    }
    // Any untranslated left? If so, defer ranking until the section is fully translated.
    $remain = (int)$conn->query("SELECT COUNT(*) c FROM ten_scraper_items
                                 WHERE pub_section_id=$pubSectionId AND status='new'
                                   AND (title_translated IS NULL OR title_translated=''
                                        OR translated_lang IS NULL OR translated_lang<>'English')")->fetch_assoc()['c'];
    $conn->close();
    if ($remain > 0) return ['translated' => $translated, 'ranked' => 0, 'more' => true, 'remaining' => $remain];

    // 2) Rank the current 'new' pool and store the selection (clear the old one first).
    $topN = (int)$section['daily_count']; if ($topN < 1) $topN = 5;
    $res = scraper_curate_rank($pubSectionId, $topN); // uses the freshly-cached English titles
    $conn = getDBConnection();
    $conn->query("UPDATE ten_scraper_items SET curate_rank=NULL, curate_reason=NULL WHERE pub_section_id=$pubSectionId AND status='new'");
    $ranked = 0; $rank = 0;
    $up = $conn->prepare("UPDATE ten_scraper_items SET curate_rank=?, curate_reason=?, curated_at=NOW() WHERE id=? AND status='new'");
    foreach ($res['items'] as $it) {
        $rank++; $rk = $rank; $rs = (string)$it['reason']; $iid = (int)$it['id'];
        $up->bind_param('isi', $rk, $rs, $iid); $up->execute();
        if ($conn->affected_rows > 0) $ranked++;
    }
    $up->close(); $conn->close();
    return ['translated' => $translated, 'ranked' => $ranked, 'more' => false];
}

/**
 * Sections that need precompute: they have 'new' items still missing an English
 * translation (fresh content arrived), OR they have 'new' items but nothing has been
 * ranked today yet (daily re-rank). Once translated + ranked today, a section stops
 * matching until new untranslated items arrive — so cost tracks real new content.
 */
function scraper_sections_needing_precompute(): array {
    $conn = getDBConnection();
    $res = $conn->query("SELECT ps.id
                         FROM ten_scraper_pub_sections ps
                         WHERE ps.is_active=1 AND (
                             EXISTS (
                                 SELECT 1 FROM ten_scraper_items i
                                 WHERE i.pub_section_id=ps.id AND i.status='new'
                                   AND (i.title_translated IS NULL OR i.title_translated=''
                                        OR i.translated_lang IS NULL OR i.translated_lang<>'English')
                             )
                             OR (
                                 EXISTS (SELECT 1 FROM ten_scraper_items n WHERE n.pub_section_id=ps.id AND n.status='new')
                                 AND NOT EXISTS (SELECT 1 FROM ten_scraper_items c
                                                 WHERE c.pub_section_id=ps.id AND c.curate_rank IS NOT NULL AND DATE(c.curated_at)=CURDATE())
                             )
                         )
                         ORDER BY ps.id");
    $ids = [];
    while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
    $conn->close();
    return $ids;
}

/**
 * Attach the promoted article's state + jump links to a set of item rows.
 * Each row must carry: article_id (nullable), publication_key, status.
 * Adds: article_id (int|null), article_state, row_state, editor_url, live_url.
 */
function scraper_attach_article_links(array $rows): array {
    $ids = array_values(array_unique(array_filter(array_map(function ($r) { return (int)($r['article_id'] ?? 0); }, $rows))));
    $states = []; $artUrl = []; $pubBase = [];
    if ($ids) {
        $a = getDBConnection_TENAdmin();
        $res = $a->query("SELECT id, state, url FROM articles WHERE id IN (" . implode(',', $ids) . ")");
        while ($x = $res->fetch_assoc()) { $states[(int)$x['id']] = $x['state']; $artUrl[(int)$x['id']] = $x['url']; }
        $pubKeys = array_values(array_unique(array_filter(array_map(function ($r) { return $r['publication_key'] ?? ''; }, $rows))));
        if ($pubKeys) {
            $inq = implode(',', array_fill(0, count($pubKeys), '?'));
            $ps = $a->prepare("SELECT publication, url FROM publications WHERE publication IN ($inq)");
            $ps->bind_param(str_repeat('s', count($pubKeys)), ...$pubKeys);
            $ps->execute();
            $pr = $ps->get_result();
            while ($x = $pr->fetch_assoc()) $pubBase[$x['publication']] = $x['url'];
            $ps->close();
        }
        $a->close();
    }
    foreach ($rows as &$r) {
        $aid = (int)($r['article_id'] ?? 0);
        $state = $aid ? ($states[$aid] ?? null) : null;
        $r['article_id'] = $aid ?: null;
        $r['article_state'] = $state;
        $r['editor_url'] = $aid ? ('module-articles.php?open=' . $aid) : null;
        $r['live_url'] = null;
        if ($aid && $state === 'published' && !empty($artUrl[$aid]) && !empty($pubBase[$r['publication_key'] ?? ''])) {
            $r['live_url'] = rtrim($pubBase[$r['publication_key']], '/') . '/' . ltrim($artUrl[$aid], '/');
        }
        if ($aid) {
            $r['row_state'] = $state === 'published' ? 'published' : ($state === 'deleted' ? 'ignored' : 'draft');
        } else {
            $r['row_state'] = ((string)($r['status'] ?? '') === 'discarded') ? 'ignored' : 'collated';
        }
    }
    unset($r);
    return $rows;
}

/** Publications (with saved per-user tab order) for the Curate screen. */
function scraper_curate_publications_ordered(int $userId): array {
    $pubs = scraper_curate_publications(); // [{publication_key, section_id (News-preferred), ...}]
    $order = [];
    $saved = scraper_get_pref($userId, 'curate_pub_order');
    if ($saved) { $dec = json_decode($saved, true); if (is_array($dec)) $order = array_map('strval', $dec); }
    if ($order) {
        $pos = array_flip($order);
        usort($pubs, function ($a, $b) use ($pos) {
            $pa = $pos[$a['publication_key']] ?? 999; $pb = $pos[$b['publication_key']] ?? 999;
            if ($pa === $pb) return strcmp($a['publication_key'], $b['publication_key']);
            return $pa <=> $pb;
        });
    }
    return $pubs;
}

/** Active sections under a publication, with today's item + curated counts. */
function scraper_curate_sections(string $pub): array {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT id, ten_section, daily_count FROM ten_scraper_pub_sections
                            WHERE publication_key=? AND is_active=1
                            ORDER BY (ten_section='News') DESC, ten_section ASC");
    $stmt->bind_param('s', $pub); $stmt->execute();
    $secs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    $out = [];
    foreach ($secs as $s) {
        $sid = (int)$s['id'];
        $today = $conn->query("SELECT COUNT(*) c FROM ten_scraper_items WHERE pub_section_id=$sid AND DATE(fetched_at)=CURDATE()")->fetch_assoc()['c'];
        $cur = $conn->query("SELECT COUNT(*) c FROM ten_scraper_items WHERE pub_section_id=$sid AND curate_rank IS NOT NULL AND DATE(curated_at)=CURDATE()")->fetch_assoc()['c'];
        $out[] = ['section_id' => $sid, 'ten_section' => $s['ten_section'], 'daily_count' => (int)$s['daily_count'],
                  'today_count' => (int)$today, 'curated_count' => (int)$cur];
    }
    $conn->close();
    return $out;
}

/** Rows for the Curate screen: mode 'all' (today's feed) or 'curated' (AI selection). */
function scraper_curate_items(int $pubSectionId, string $mode = 'all', string $date = ''): array {
    $conn = getDBConnection();
    $hasDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    // Evaluate "today" in MySQL (CURDATE()) so it matches how fetched_at/curated_at are stored.
    $dayCol = $hasDate ? '?' : 'CURDATE()';
    $sel = "SELECT i.id, i.status, i.curate_rank, i.curate_reason,
                   COALESCE(NULLIF(i.title_translated,''), i.title) AS title,
                   COALESCE(NULLIF(i.summary_translated,''), i.summary) AS summary,
                   i.title AS title_original, i.source_url, i.published_at, i.fetched_at,
                   src.name AS source_name, d.article_id, ps.publication_key, ps.ten_section
            FROM ten_scraper_items i
            JOIN ten_scraper_pub_sections ps ON ps.id = i.pub_section_id
            LEFT JOIN ten_scraper_feeds f ON f.id = i.feed_id
            LEFT JOIN ten_scraper_sources src ON src.id = f.source_id
            LEFT JOIN ten_scraper_drafts d ON d.item_id = i.id
            WHERE i.pub_section_id=? ";
    if ($mode === 'curated') {
        $sql = $sel . "AND i.curate_rank IS NOT NULL AND DATE(i.curated_at)=$dayCol ORDER BY i.curate_rank ASC, i.id DESC";
    } else {
        $sql = $sel . "AND DATE(i.fetched_at)=$dayCol ORDER BY i.published_at DESC, i.id DESC LIMIT 400";
    }
    $stmt = $conn->prepare($sql);
    if ($hasDate) { $stmt->bind_param('is', $pubSectionId, $date); }
    else { $stmt->bind_param('i', $pubSectionId); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close(); $conn->close();
    return scraper_attach_article_links($rows);
}

/** Per-user preference get/set (tab order etc.). */
function scraper_get_pref(int $userId, string $key): ?string {
    if ($userId < 1) return null;
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT pref_value FROM ten_scraper_user_prefs WHERE user_id=? AND pref_key=?");
    $stmt->bind_param('is', $userId, $key); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); $conn->close();
    return $row ? $row['pref_value'] : null;
}
function scraper_set_pref(int $userId, string $key, string $value): void {
    if ($userId < 1) return;
    $conn = getDBConnection();
    $stmt = $conn->prepare("INSERT INTO ten_scraper_user_prefs (user_id, pref_key, pref_value) VALUES (?,?,?)
                            ON DUPLICATE KEY UPDATE pref_value=VALUES(pref_value)");
    $stmt->bind_param('iss', $userId, $key, $value); $stmt->execute(); $stmt->close(); $conn->close();
}

/** Filterable history across all sections of a project (for the History table). */
function scraper_history_query(array $f): array {
    $conn = getDBConnection();
    $sql = "SELECT i.id, i.status,
                   COALESCE(NULLIF(i.title_translated,''), i.title) AS title,
                   i.source_url, i.published_at, i.fetched_at,
                   src.name AS source_name, d.article_id,
                   ps.publication_key, ps.ten_section
            FROM ten_scraper_items i
            JOIN ten_scraper_pub_sections ps ON ps.id = i.pub_section_id
            LEFT JOIN ten_scraper_feeds f ON f.id = i.feed_id
            LEFT JOIN ten_scraper_sources src ON src.id = f.source_id
            LEFT JOIN ten_scraper_drafts d ON d.item_id = i.id
            WHERE 1=1";
    $params = []; $types = '';
    if (!empty($f['project_id'])) { $sql .= " AND ps.project_id=?"; $params[] = (int)$f['project_id']; $types .= 'i'; }
    if (($f['publication'] ?? '') !== '') { $sql .= " AND ps.publication_key=?"; $params[] = $f['publication']; $types .= 's'; }
    if (($f['section'] ?? '') !== '') { $sql .= " AND ps.ten_section=?"; $params[] = $f['section']; $types .= 's'; }
    if (($f['from'] ?? '') !== '') { $sql .= " AND i.fetched_at >= ?"; $params[] = $f['from'] . ' 00:00:00'; $types .= 's'; }
    if (($f['to'] ?? '') !== '') { $sql .= " AND i.fetched_at <= ?"; $params[] = $f['to'] . ' 23:59:59'; $types .= 's'; }
    if (($f['search'] ?? '') !== '') {
        $sql .= " AND (COALESCE(NULLIF(i.title_translated,''),i.title) LIKE ? OR src.name LIKE ?)";
        $s = '%' . $f['search'] . '%'; $params[] = $s; $params[] = $s; $types .= 'ss';
    }
    $sql .= " ORDER BY i.fetched_at DESC LIMIT 2000";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close(); $conn->close();

    // Attach the promoted article's state + jump links (editor for drafts, live URL for published).
    return scraper_attach_article_links($rows);
}

/** Distinct publications + sections in a project, for the History filter dropdowns. */
function scraper_history_filters(int $projectId): array {
    $conn = getDBConnection();
    $pubs = []; $secs = [];
    $r = $conn->query("SELECT DISTINCT publication_key FROM ten_scraper_pub_sections WHERE project_id=$projectId ORDER BY publication_key");
    while ($x = $r->fetch_assoc()) $pubs[] = $x['publication_key'];
    $r = $conn->query("SELECT DISTINCT ten_section FROM ten_scraper_pub_sections WHERE project_id=$projectId ORDER BY ten_section");
    while ($x = $r->fetch_assoc()) $secs[] = $x['ten_section'];
    $conn->close();
    return ['publications' => $pubs, 'sections' => $secs];
}

/** Rebuild a publication's front page by calling its generate_index_page.php. */
function scraper_trigger_publication_cache(string $pubKey): void {
    try {
        $a = getDBConnection_TENAdmin();
        $st = $a->prepare("SELECT url FROM publications WHERE publication=? AND pub_live=1 LIMIT 1");
        $st->bind_param('s', $pubKey); $st->execute();
        $row = $st->get_result()->fetch_assoc(); $st->close(); $a->close();
        if ($row && !empty($row['url'])) {
            $ch = curl_init(rtrim($row['url'], '/') . '/generate_index_page.php');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false]);
            curl_exec($ch); curl_close($ch);
        }
    } catch (Throwable $e) { /* best effort */ }
}

/**
 * Return up to $limit new items for a section, each with translated title/summary
 * (translated once into the publication's language and cached on the row).
 */
function scraper_review_items(int $pubSectionId, int $limit = 100): array {
    $section = scraper_get_section($pubSectionId);
    if (!$section) return ['items' => [], 'language' => 'English', 'translate_error' => null];

    $lang = ns_publication_language($section['publication_key']);
    $project = scraper_get_project((int)$section['project_id']) ?: [];
    $tProvider = ($project['translation_provider'] ?? '') ?: 'anthropic';
    $tModel = ($project['translation_model'] ?? '') ?: 'claude-haiku-4-5';
    $translateError = null;
    $capNote = null;

    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT id, title, summary, source_url, published_at, cluster_id,
                title_translated, summary_translated, translated_lang
         FROM ten_scraper_items
         WHERE pub_section_id = ? AND status = 'new'
         ORDER BY published_at DESC, id DESC
         LIMIT ?"
    );
    $stmt->bind_param('ii', $pubSectionId, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Collapse duplicate stories (same cluster_id) before translating — keep the newest.
    $seenClusters = [];
    $deduped = [];
    foreach ($rows as $r) {
        $cid = (string)($r['cluster_id'] ?? '');
        if ($cid !== '' && isset($seenClusters[$cid])) {
            continue;
        }
        if ($cid !== '') {
            $seenClusters[$cid] = true;
        }
        $deduped[] = $r;
    }
    $rows = $deduped;

    // Items needing translation (missing/other language).
    $toTranslate = [];
    foreach ($rows as $r) {
        if (($r['translated_lang'] ?? '') !== $lang) {
            $toTranslate[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'summary' => $r['summary']];
        }
    }

    // Enforce per-publication daily translation cap (0 = unlimited).
    if (!empty($toTranslate)) {
        $cap = ns_publication_daily_cap($section['publication_key']);
        if ($cap > 0) {
            $usedToday = scraper_translations_today($conn, $section['publication_key']);
            $remaining = $cap - $usedToday;
            if ($remaining <= 0) {
                $capNote = "Daily translation cap ({$cap}) reached for this publication — remaining items show original text; more can translate tomorrow.";
                $toTranslate = [];
            } elseif (count($toTranslate) > $remaining) {
                $capNote = "Daily translation cap ({$cap}) — translated {$remaining} more today; the rest show original text.";
                $toTranslate = array_slice($toTranslate, 0, $remaining);
            }
        }
    }

    if (!empty($toTranslate)) {
        try {
            $translations = scraper_ai_translate($tProvider, $tModel, $lang, $toTranslate);
        } catch (Throwable $e) {
            $translations = [];
            $translateError = $e->getMessage();
        }
        if (!empty($translations)) {
            $up = $conn->prepare("UPDATE ten_scraper_items SET title_translated=?, summary_translated=?, translated_lang=?, translated_at=NOW() WHERE id=?");
            foreach ($translations as $id => $t) {
                $tt = $t['title']; $ts = $t['summary'];
                $up->bind_param('sssi', $tt, $ts, $lang, $id);
                $up->execute();
            }
            $up->close();
            foreach ($rows as &$r) {
                $id = (int)$r['id'];
                if (isset($translations[$id])) {
                    $r['title_translated'] = $translations[$id]['title'];
                    $r['summary_translated'] = $translations[$id]['summary'];
                    $r['translated_lang'] = $lang;
                }
            }
            unset($r);
        }
    }
    $conn->close();

    $items = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'title_original' => $r['title'],
            'summary_original' => $r['summary'],
            'title' => ($r['title_translated'] ?? '') !== '' ? $r['title_translated'] : $r['title'],
            'summary' => ($r['summary_translated'] ?? '') !== '' ? $r['summary_translated'] : $r['summary'],
            'source_url' => $r['source_url'],
            'published_at' => $r['published_at'],
            'cluster_id' => $r['cluster_id'],
        ];
    }, $rows);

    return ['items' => $items, 'language' => $lang, 'translate_error' => $translateError, 'cap_note' => $capNote];
}

/** Count items translated today for a publication (across its sections). */
function scraper_translations_today($conn, string $publicationKey): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM ten_scraper_items i
         JOIN ten_scraper_pub_sections ps ON ps.id = i.pub_section_id
         WHERE ps.publication_key = ? AND i.translated_at >= CURDATE()"
    );
    $stmt->bind_param('s', $publicationKey);
    $stmt->execute();
    $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $c;
}

/** Last-N-days history for a section: what was collated, from where, and its status. */
function scraper_history(int $pubSectionId, int $days = 30): array {
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT i.id, i.status,
                COALESCE(NULLIF(i.title_translated,''), i.title) AS title,
                i.source_url, i.published_at, i.fetched_at,
                src.name AS source_name, d.article_id
         FROM ten_scraper_items i
         LEFT JOIN ten_scraper_feeds f ON f.id = i.feed_id
         LEFT JOIN ten_scraper_sources src ON src.id = f.source_id
         LEFT JOIN ten_scraper_drafts d ON d.item_id = i.id
         WHERE i.pub_section_id = ? AND i.fetched_at >= (NOW() - INTERVAL ? DAY)
         ORDER BY i.fetched_at DESC
         LIMIT 500"
    );
    $stmt->bind_param('ii', $pubSectionId, $days);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $conn->close();
    return $rows;
}

/**
 * Promote selected items: write an original article from each and insert into
 * admin_ten.articles (draft, or published if the section auto-publishes).
 * @return array list of per-item results
 */
function scraper_promote_items(int $pubSectionId, array $itemIds, ?bool $forcePublish = null): array {
    $section = scraper_get_section($pubSectionId);
    if (!$section) return [['ok' => false, 'error' => 'Section not found']];

    $ai = scraper_effective_ai($section);
    $lang = ns_publication_language($section['publication_key']);
    list($inhouseId, $inhouseName) = scraper_inhouse_author($section['publication_key']);
    $journalistId = ($section['journalist_id'] !== null && $section['journalist_id'] !== '')
        ? (int)$section['journalist_id']
        : $inhouseId;
    // $forcePublish overrides the section's auto_publish (used by Curate's
    // "publish direct" bulk action, which publishes straight away).
    $autoPublish = $forcePublish !== null ? $forcePublish : ((int)($section['auto_publish'] ?? 0) === 1);

    $conn = getDBConnection();
    $results = [];

    foreach ($itemIds as $rawId) {
        $itemId = (int)$rawId;
        $stmt = $conn->prepare("SELECT id, title, summary, facts, source_url, source_url_hash FROM ten_scraper_items WHERE id = ? AND pub_section_id = ?");
        $stmt->bind_param('ii', $itemId, $pubSectionId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) {
            $results[] = ['id' => $itemId, 'ok' => false, 'error' => 'Item not found'];
            continue;
        }

        try {
            // Facts are fetched at promote time (not ingest), only for the items
            // actually being published. Fall back to the stored facts if present.
            $facts = trim((string)$item['facts']);
            if ($facts === '') {
                $facts = scraper_fetch_facts((string)$item['source_url']);
            }
            $article = scraper_ai_write_article($ai['provider'], $ai['model'], $ai['prompt'], [
                'source_title' => $item['title'],
                'source_summary' => $item['summary'],
                'source_facts' => $facts,
                'source_url' => $item['source_url'],
                'section' => $section['ten_section'],
                'publication' => $section['publication_key'],
                'target_language' => $lang,
            ]);

            $articleId = scraper_insert_article($section, $article, $item, $journalistId, $autoPublish, $inhouseName);
            scraper_record_draft($conn, $itemId, $article, $ai, $journalistId, $articleId);

            // Best-effort royalty-free image suggestions for the editor sidebar.
            try {
                $suggestions = scraper_image_search(scraper_image_query_from_article($article), 4);
                if (!empty($suggestions)) {
                    $sjson = json_encode($suggestions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $ui = $conn->prepare("UPDATE ten_scraper_items SET image_suggestions=? WHERE id=?");
                    $ui->bind_param('si', $sjson, $itemId);
                    $ui->execute();
                    $ui->close();
                }
            } catch (Throwable $imgEx) {
                // ignore — suggestions are optional
            }

            $up = $conn->prepare("UPDATE ten_scraper_items SET status='promoted' WHERE id=?");
            $up->bind_param('i', $itemId); $up->execute(); $up->close();

            $results[] = ['id' => $itemId, 'ok' => true, 'article_id' => $articleId, 'title' => $article['title'], 'state' => $autoPublish ? 'published' : 'draft'];
        } catch (Throwable $e) {
            $results[] = ['id' => $itemId, 'ok' => false, 'error' => $e->getMessage()];
        }
    }
    $conn->close();
    return $results;
}

/**
 * The in-house "house" author for scraped articles, per publication, as
 * [journalist_id, display_name]. These ids are OLD admin_ten.users ids that the
 * public site's get_author_byline() already renders as the in-house line
 * ("Article collated/edited/curated, or written in-house, by The Munich Eye")
 * — currently 112 and 186 for TME. journalist_id on articles resolves against
 * that table on the site, so the default byline must use one of those ids (NOT
 * a low ten_users id like ten_news=9, which the site reads as a real person's
 * row — e.g. id 9 = Karl Gruber). A mirror ten_users row with the SAME id lets
 * the editor's author dropdown show/select the house account too.
 */
function scraper_inhouse_author(string $publicationKey): array {
    // The generic house account for scraped articles is ten_users 'ten_news'
    // (full name "TEN News"). Look it up dynamically so an id change — the users
    // migration moved it to 9001 — never breaks author selection or the byline.
    $conn = getDBConnection();
    $res = $conn->query("SELECT id, full_name FROM ten_users WHERE username='ten_news' AND status='active' LIMIT 1");
    $row = $res ? $res->fetch_assoc() : null;
    $conn->close();
    if ($row && (int)$row['id'] > 0) {
        return [(int)$row['id'], (string)($row['full_name'] ?: 'TEN News')];
    }
    return [9001, 'TEN News'];
}

/** Default byline journalist for scraped articles (kept for back-compat). */
function scraper_default_journalist_id(): ?int {
    list($id, ) = scraper_inhouse_author('');
    return $id;
}

/** URL slug from a title: lowercase, non-alphanumerics to hyphens. */
function scraper_slugify(string $text): string {
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', trim($text));
    $text = trim(preg_replace('/-+/', '-', $text), '-');
    $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    if ($text === '') {
        return 'article-' . time();
    }
    return function_exists('mb_substr') ? mb_substr($text, 0, 190) : substr($text, 0, 190);
}

/** Insert the generated article into admin_ten.articles. Returns new article id. */
function scraper_insert_article(array $section, array $article, array $item, ?int $journalistId, bool $autoPublish, string $createdBy = 'TME News'): int {
    $conn = getDBConnection_TENAdmin();
    $state = $autoPublish ? 'published' : 'draft';
    $pub = $section['publication_key'];
    $sectionName = substr($section['ten_section'], 0, 20);
    $stmt = $conn->prepare(
        "INSERT INTO articles
         (title, meta_title, meta_description, meta_keywords, article_text, state, section,
          submission_date, created_by, modified_date, publish_now, journalist_id,
          publications, canonical, news_scrape_url, news_scrape_url_hash, imageless)
         VALUES (?,?,?,?,?,?,?, NOW(), ?, NOW(), 1, ?, ?, ?, ?, ?, 1)"
    );
    $title = $article['title'];
    $mt = substr($article['meta_title'], 0, 200);
    $md = substr($article['meta_description'], 0, 500);
    $mk = substr($article['meta_keywords'], 0, 500);
    $body = $article['body_html'];
    $srcUrl = substr($item['source_url'], 0, 200);
    $srcHash = $item['source_url_hash'];
    $canonical = substr($pub, 0, 10);
    $jid = $journalistId;
    $cb = substr($createdBy, 0, 100);

    // 8 strings, journalist_id (i), then 4 strings = 13 params
    $stmt->bind_param(
        'ssssssssissss',
        $title, $mt, $md, $mk, $body, $state, $sectionName,
        $cb, $jid, $pub, $canonical, $srcUrl, $srcHash
    );
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();

    // The site URL is <slug>-<id>, built from the title; set it now that we have the id.
    $url = substr(scraper_slugify($article['title']) . '-' . $id, 0, 200);
    $up = $conn->prepare("UPDATE articles SET url=? WHERE id=?");
    $up->bind_param('si', $url, $id);
    $up->execute();
    $up->close();

    $conn->close();
    return $id;
}

/** Record provenance in ten_scraper_drafts. */
function scraper_record_draft($conn, int $itemId, array $article, array $ai, ?int $journalistId, int $articleId): void {
    $stmt = $conn->prepare(
        "INSERT INTO ten_scraper_drafts
         (item_id, generated_title, generated_body, meta_title, meta_description, meta_keywords,
          ai_provider, ai_model, prompt_used, journalist_id, article_id, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?, 'promoted')"
    );
    $gt = $article['title']; $gb = $article['body_html'];
    $mt = $article['meta_title']; $md = $article['meta_description']; $mk = $article['meta_keywords'];
    $prov = $ai['provider']; $model = $ai['model']; $prompt = $ai['prompt'];
    $jid = $journalistId;
    $stmt->bind_param('issssssssii', $itemId, $gt, $gb, $mt, $md, $mk, $prov, $model, $prompt, $jid, $articleId);
    $stmt->execute();
    $stmt->close();
}
