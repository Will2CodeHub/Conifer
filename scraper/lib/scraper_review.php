<?php
/**
 * Phase 5: review queue (translate-on-load, cached) + promote (AI write -> Article Tool).
 */

require_once __DIR__ . '/scraper_crud.php';     // scraper_get_section, scraper_get_project
require_once __DIR__ . '/news_sites_db.php';     // ns_publication_language
require_once __DIR__ . '/ScraperAI.php';         // scraper_ai_translate, scraper_ai_write_article
require_once __DIR__ . '/ImageSearch.php';       // scraper_image_search (royalty-free suggestions)

/**
 * The current user's editorial scope for the scraper, from their session.
 * See-all roles (Admin/Super/Manager) get everything; editorial roles are
 * limited to their assigned publication(s), and Section Editors additionally to
 * their assigned section(s).
 */
function scraper_user_scope(): array {
    $position = $_SESSION['ten_position'] ?? '';
    $isAdminUser = function_exists('isAdmin') ? isAdmin() : false;
    $seeAllRoles = ['Admin', 'Super Admin', 'Super User', 'Administrator', 'Manager'];
    $seeAll = $isAdminUser || in_array($position, $seeAllRoles, true);
    $pubs = array_values(array_filter(array_map('trim', explode(',', (string)($_SESSION['ten_publication'] ?? ''))), fn($x) => $x !== ''));
    $sections = array_values(array_filter(array_map('trim', explode(',', (string)($_SESSION['ten_section'] ?? ''))), fn($x) => $x !== ''));
    return ['seeAll' => $seeAll, 'position' => $position, 'pubs' => $pubs, 'sections' => $sections];
}

/** True if the current user may work the given publication in the scraper. */
function scraper_user_can_access_pub(string $pubKey): bool {
    $s = scraper_user_scope();
    if ($s['seeAll']) return true;
    return $pubKey !== '' && in_array($pubKey, $s['pubs'], true);
}

/** True if the current user may work the given pub_section (publication + section scope). */
function scraper_user_can_access_section(int $pubSectionId): bool {
    $s = scraper_user_scope();
    if ($s['seeAll']) return true;
    if ($pubSectionId <= 0) return false;
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT publication_key, ten_section FROM ten_scraper_pub_sections WHERE id=?");
    $stmt->bind_param('i', $pubSectionId); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close(); $conn->close();
    if (!$row) return false;
    if (!in_array($row['publication_key'], $s['pubs'], true)) return false;
    if ($s['position'] === 'Section Editor' && $s['sections']) {
        $allow = array_map('strtolower', $s['sections']);
        if (!in_array(strtolower($row['ten_section']), $allow, true)) return false;
    }
    return true;
}

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

    // Enrich with each publication's display name + front-page URL (admin_ten.publications)
    // for the tab tooltips and front-page links.
    $keys = array_map(function ($p) { return $p['publication_key']; }, $out);
    if ($keys) {
        $a = getDBConnection_TENAdmin();
        $inq = implode(',', array_fill(0, count($keys), '?'));
        $ps = $a->prepare("SELECT publication, title, url FROM publications WHERE publication IN ($inq)");
        $ps->bind_param(str_repeat('s', count($keys)), ...$keys);
        $ps->execute();
        $meta = [];
        $pr = $ps->get_result();
        while ($x = $pr->fetch_assoc()) $meta[$x['publication']] = $x;
        $ps->close(); $a->close();
        foreach ($out as &$p) {
            $m = $meta[$p['publication_key']] ?? null;
            $p['name'] = ($m && !empty($m['title'])) ? $m['title'] : strtoupper($p['publication_key']);
            $p['front_page_url'] = ($m && !empty($m['url'])) ? rtrim($m['url'], '/') . '/' : null;
        }
        unset($p);
    }
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
    $system = "You are the news editor of a local English-language newspaper covering {$region}. {$focus} "
        . "Drop trivia and clickbait. CRUCIAL: pick DISTINCT stories only — never choose two headlines about the same "
        . "event, incident or topic; if several cover the same story, keep the single best one and drop the rest. "
        . "Return ONLY JSON.";
    $user = "From these " . count($rows) . " collated headlines (id: headline), choose the TOP " . min($topN, count($rows)) . " for this publication's readers, each about a DIFFERENT story (no two on the same topic). "
          . "The headlines may be in ANY language: translate each chosen one into a natural English news headline. "
          . "Headline style (strict): sentence case, NO colon-and-label prefix (no \"Word:\"), no colons, no em/en dashes (— –), no plus signs (+), no ALL-CAPS, no clickbait. "
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

    // The cron and the Curate screen can both drive this; a per-section lock stops them
    // paying to translate the same rows twice. Held on its own connection for the
    // whole pass (MySQL drops it automatically if the request dies).
    $lockConn = getDBConnection();
    $got = $lockConn->query("SELECT GET_LOCK('scraper_precompute_" . $pubSectionId . "', 0) g")->fetch_assoc();
    if ((int)($got['g'] ?? 0) !== 1) { $lockConn->close(); return ['busy' => true, 'translated' => 0, 'ranked' => 0, 'more' => true]; }
    try {
        return scraper_precompute_section_locked($pubSectionId, $section, $ai);
    } finally {
        $lockConn->close();
    }
}

/** One precompute pass for a section; caller holds the section's precompute lock. */
function scraper_precompute_section_locked(int $pubSectionId, array $section, array $ai): array {
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

/** How many 'new' items in a section still lack an English translation. */
function scraper_untranslated_count(int $pubSectionId): int {
    $conn = getDBConnection();
    $n = (int)$conn->query("SELECT COUNT(*) c FROM ten_scraper_items
                            WHERE pub_section_id=" . (int)$pubSectionId . " AND status='new'
                              AND (title_translated IS NULL OR title_translated=''
                                   OR translated_lang IS NULL OR translated_lang<>'English')")->fetch_assoc()['c'];
    $conn->close();
    return $n;
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

    // Scope to the user's assigned publications (editorial roles); admins/managers see all.
    $scope = scraper_user_scope();
    if (!$scope['seeAll']) {
        $allow = array_flip($scope['pubs']);
        $pubs = array_values(array_filter($pubs, fn($p) => isset($allow[$p['publication_key']])));
    }

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

    // Section Editors only see their assigned section(s) within the publication.
    $scope = scraper_user_scope();
    if ($scope['position'] === 'Section Editor' && $scope['sections']) {
        $allow = array_map('strtolower', $scope['sections']);
        $secs = array_values(array_filter($secs, fn($s) => in_array(strtolower($s['ten_section']), $allow, true)));
    }

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

/** Significant lowercase word tokens of a headline (stopwords + short words dropped). */
function scraper_title_tokens(string $t): array {
    $t = function_exists('mb_strtolower') ? mb_strtolower($t) : strtolower($t);
    $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t);
    $stop = array_flip(['the','a','an','and','or','of','to','in','on','for','at','by','with','from','as','is','are','was','were','be','been','after','amid','over','into','new','say','says','said','it','its','his','her','their','they','he','she','will','has','have','had','that','this','than','but','not','you','your','who','what','how','why','when','where','amid','out','up','down','off']);
    $out = [];
    foreach (preg_split('/\s+/', trim($t)) as $w) {
        if ($w !== '' && mb_strlen($w) > 2 && !isset($stop[$w])) $out[$w] = true;
    }
    return array_keys($out);
}

/** Jaccard overlap of two token sets (0..1). */
function scraper_titles_similar(array $a, array $b): float {
    if (!$a || !$b) return 0.0;
    $inter = count(array_intersect($a, $b));
    $union = count(array_unique(array_merge($a, $b)));
    return $union ? $inter / $union : 0.0;
}

/**
 * Token sets of headlines already covered TODAY for a publication+section
 * (state-agnostic; a topic promoted today counts as covered). $table is a fixed
 * internal value ('articles' or 'articles_breaking_news'), never user input.
 */
function scraper_covered_today_titles(string $pubKey, string $sectionName, string $table = 'articles'): array {
    if (!in_array($table, ['articles', 'articles_breaking_news'], true)) $table = 'articles';
    $a = getDBConnection_TENAdmin();
    $sec = function_exists('mb_strtolower') ? mb_strtolower($sectionName) : strtolower($sectionName);
    $like = '%' . $pubKey . '%';
    $stmt = $a->prepare("SELECT title FROM `$table` WHERE DATE(submission_date)=CURDATE() AND publications LIKE ? AND LOWER(section)=?");
    $stmt->bind_param('ss', $like, $sec);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = scraper_title_tokens((string)$r['title']);
    $stmt->close();
    $a->close();
    return $out;
}

/**
 * Drop candidate rows whose topic was already covered today (same pub+section)
 * or is a near-duplicate of a higher-ranked row already kept. Promoted rows are
 * always kept (their badge must show) and also suppress later new duplicates.
 */
function scraper_dedup_curate_rows(array $rows): array {
    if (!$rows) return $rows;
    $pk = $rows[0]['publication_key'] ?? '';
    $sn = $rows[0]['ten_section'] ?? '';
    $covered = ($pk && $sn) ? scraper_covered_today_titles($pk, $sn, 'articles') : [];
    $kept = [];
    $keptTokens = $covered; // new rows dedup against today's covered topics too
    foreach ($rows as $r) {
        // Dedup on the ENGLISH (translated) headline so the same story reported by two
        // outlets — often in different source languages or wordings — still collapses.
        // Fall back to the original title only when no translation exists yet.
        $tok = scraper_title_tokens((string)($r['title'] ?? $r['title_original'] ?? ''));
        if (($r['status'] ?? '') === 'new') {
            $dup = false;
            foreach ($keptTokens as $c) {
                if (scraper_titles_similar($tok, $c) >= 0.4) { $dup = true; break; }
            }
            if ($dup) continue;
        }
        $kept[] = $r;
        $keptTokens[] = $tok;
    }
    return $kept;
}

/** Rows for the Curate screen: mode 'all' (today's feed) or 'curated' (AI selection). */
function scraper_curate_items(int $pubSectionId, string $mode = 'all', string $date = ''): array {
    $conn = getDBConnection();
    $hasDate = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    // Evaluate "today" in MySQL (CURDATE()) so it matches how fetched_at/curated_at are stored.
    $dayCol = $hasDate ? '?' : 'CURDATE()';
    // Aggregate the drafts link so multiple drafts for one item can't duplicate the
    // row or hide the article id (pick the highest article_id any draft recorded).
    $sel = "SELECT i.id, i.status, i.curate_rank, i.curate_reason, i.source_url_hash,
                   COALESCE(NULLIF(i.title_translated,''), i.title) AS title,
                   COALESCE(NULLIF(i.summary_translated,''), i.summary) AS summary,
                   i.title AS title_original, i.source_url, i.published_at, i.fetched_at,
                   src.name AS source_name, d.article_id, ps.publication_key, ps.ten_section
            FROM ten_scraper_items i
            JOIN ten_scraper_pub_sections ps ON ps.id = i.pub_section_id
            LEFT JOIN ten_scraper_feeds f ON f.id = i.feed_id
            LEFT JOIN ten_scraper_sources src ON src.id = f.source_id
            LEFT JOIN (SELECT item_id, MAX(article_id) AS article_id FROM ten_scraper_drafts GROUP BY item_id) d
                   ON d.item_id = i.id
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

    // Topic dedup: hide new items about a topic already covered today, and collapse
    // near-duplicate new items to the highest-ranked one.
    $rows = scraper_dedup_curate_rows($rows);

    // Fallback: some promoted items have no draft link (article id missing) — resolve
    // it from the article the scraper wrote, matched on its stored source-URL hash.
    scraper_fill_article_ids_by_hash($rows);
    return scraper_attach_article_links($rows);
}

/**
 * Fill a missing article_id on any row by matching the item's source_url_hash to
 * articles.news_scrape_url_hash (the hash the scraper stamps on the article it
 * writes). Mutates $rows in place. Highest article id wins on collisions.
 */
function scraper_fill_article_ids_by_hash(array &$rows): void {
    $hashes = [];
    foreach ($rows as $r) {
        if (empty($r['article_id']) && !empty($r['source_url_hash'])) $hashes[$r['source_url_hash']] = true;
    }
    if (!$hashes) return;
    $hashes = array_keys($hashes);
    $a = getDBConnection_TENAdmin();
    $inq = implode(',', array_fill(0, count($hashes), '?'));
    $ps = $a->prepare("SELECT id, news_scrape_url_hash FROM articles WHERE news_scrape_url_hash IN ($inq) ORDER BY id ASC");
    $ps->bind_param(str_repeat('s', count($hashes)), ...$hashes);
    $ps->execute();
    $map = [];
    $rs = $ps->get_result();
    while ($x = $rs->fetch_assoc()) { $map[$x['news_scrape_url_hash']] = (int)$x['id']; } // ASC so last wins = max id
    $ps->close(); $a->close();
    foreach ($rows as &$r) {
        if (empty($r['article_id']) && !empty($r['source_url_hash']) && isset($map[$r['source_url_hash']])) {
            $r['article_id'] = $map[$r['source_url_hash']];
        }
    }
    unset($r);
}

/**
 * Articles the scraper published today from one section: promoted today
 * (ten_scraper_drafts.created_at = today) and currently live (article state
 * 'published'). Each row carries a live link, whether it's the front-page
 * headline (articles.frontpage_temp = 1), and the publication's front-page URL.
 */
function scraper_published_today(int $pubSectionId): array {
    $section = scraper_get_section($pubSectionId);
    if (!$section) return ['items' => [], 'front_page_url' => null];
    $pubKey = $section['publication_key'];

    // 1) Scraper DB: article ids promoted from this section today.
    $conn = getDBConnection();
    $stmt = $conn->prepare(
        "SELECT d.article_id, MAX(d.created_at) AS promoted_at
         FROM ten_scraper_drafts d
         JOIN ten_scraper_items i ON i.id = d.item_id
         WHERE i.pub_section_id = ? AND d.article_id IS NOT NULL
           AND DATE(d.created_at) = CURDATE()
         GROUP BY d.article_id
         ORDER BY promoted_at DESC"
    );
    $stmt->bind_param('i', $pubSectionId);
    $stmt->execute();
    $drafts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close(); $conn->close();

    $ids = array_values(array_unique(array_filter(array_map(function ($r) { return (int)$r['article_id']; }, $drafts))));
    if (!$ids) return ['items' => [], 'front_page_url' => null];
    $promotedAt = [];
    foreach ($drafts as $r) { $promotedAt[(int)$r['article_id']] = $r['promoted_at']; }

    // 2) admin_ten: article details + publication front-page base URL.
    $a = getDBConnection_TENAdmin();
    $res = $a->query("SELECT id, title, url, state, frontpage_temp FROM articles WHERE id IN (" . implode(',', $ids) . ")");
    $arts = [];
    while ($x = $res->fetch_assoc()) { $arts[(int)$x['id']] = $x; }
    $base = null;
    $ps = $a->prepare("SELECT url FROM publications WHERE publication=? LIMIT 1");
    $ps->bind_param('s', $pubKey); $ps->execute();
    $pr = $ps->get_result()->fetch_assoc(); $ps->close();
    if ($pr && !empty($pr['url'])) $base = rtrim($pr['url'], '/');
    $a->close();

    $frontPage = $base ? $base . '/' : null;
    $items = [];
    foreach ($ids as $aid) {
        $art = $arts[$aid] ?? null;
        if (!$art || $art['state'] !== 'published') continue;   // only currently-live articles
        $isHeadline = (int)$art['frontpage_temp'] === 1;
        $items[] = [
            'article_id'     => $aid,
            'title'          => $art['title'],
            'live_url'       => ($base && !empty($art['url'])) ? $base . '/' . ltrim($art['url'], '/') : null,
            'is_headline'    => $isHeadline,
            'front_page_url' => $isHeadline ? $frontPage : null,
            'promoted_at'    => $promotedAt[$aid] ?? null,
        ];
    }
    return ['items' => $items, 'front_page_url' => $frontPage];
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

/**
 * History = articles the scraper PUBLISHED in the last 30 days. Each row carries
 * the publish date and a link to the article on its canonical publication
 * (articles.canonical -> that site's URL + the article slug; falls back to the
 * first listed publication when canonical is unset). Filterable by publication,
 * section and text.
 */
function scraper_history_query(array $f): array {
    // 1) Scraper side: promoted items (candidate articles) matching the filters.
    $conn = getDBConnection();
    $sql = "SELECT d.article_id,
                   COALESCE(NULLIF(i.title_translated,''), i.title) AS title,
                   src.name AS source_name,
                   ps.publication_key, ps.ten_section
            FROM ten_scraper_items i
            JOIN ten_scraper_pub_sections ps ON ps.id = i.pub_section_id
            LEFT JOIN ten_scraper_feeds f ON f.id = i.feed_id
            LEFT JOIN ten_scraper_sources src ON src.id = f.source_id
            JOIN ten_scraper_drafts d ON d.item_id = i.id AND d.article_id IS NOT NULL
            WHERE 1=1";
    $params = []; $types = '';
    if (!empty($f['project_id'])) { $sql .= " AND ps.project_id=?"; $params[] = (int)$f['project_id']; $types .= 'i'; }
    if (($f['publication'] ?? '') !== '') { $sql .= " AND ps.publication_key=?"; $params[] = $f['publication']; $types .= 's'; }
    if (($f['section'] ?? '') !== '') { $sql .= " AND ps.ten_section=?"; $params[] = $f['section']; $types .= 's'; }
    if (($f['search'] ?? '') !== '') {
        $sql .= " AND (COALESCE(NULLIF(i.title_translated,''),i.title) LIKE ? OR src.name LIKE ?)";
        $s = '%' . $f['search'] . '%'; $params[] = $s; $params[] = $s; $types .= 'ss';
    }
    $sql .= " ORDER BY d.id DESC LIMIT 5000";
    $stmt = $conn->prepare($sql);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $cand = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close(); $conn->close();

    // One candidate row per article (keep the first — scraper title/source/section).
    $byArt = [];
    foreach ($cand as $c) { $aid = (int)$c['article_id']; if ($aid && !isset($byArt[$aid])) $byArt[$aid] = $c; }
    $ids = array_keys($byArt);
    if (!$ids) return [];

    // 2) admin_ten: filter the articles by state + publish-date range.
    $a = getDBConnection_TENAdmin();
    $conds = ["id IN (" . implode(',', $ids) . ")"];
    // State filter (default: any state except deleted). "when it was published" uses submission_date.
    $state = trim((string)($f['state'] ?? ''));
    $allowedStates = ['published', 'draft', 'under review', 'expired', 'deleted'];
    if ($state !== '' && in_array($state, $allowedStates, true)) {
        $conds[] = "state = '" . $a->real_escape_string($state) . "'";
    } else {
        $conds[] = "state <> 'deleted'";
    }
    // Date range on submission_date; defaults to the last 30 days when no From is given.
    $from = trim((string)($f['from'] ?? ''));
    $to   = trim((string)($f['to'] ?? ''));
    if ($from !== '') { $conds[] = "submission_date >= '" . $a->real_escape_string($from) . " 00:00:00'"; }
    else              { $conds[] = "submission_date >= (NOW() - INTERVAL 30 DAY)"; }
    if ($to !== '')   { $conds[] = "submission_date <= '" . $a->real_escape_string($to) . " 23:59:59'"; }
    $res = $a->query("SELECT id, title, url, canonical, publications, submission_date, state
                      FROM articles WHERE " . implode(' AND ', $conds));
    $arts = [];
    while ($x = $res->fetch_assoc()) { $arts[(int)$x['id']] = $x; }
    // Publication base URLs for building canonical links.
    $pubMap = [];
    $pr = $a->query("SELECT publication, url FROM publications WHERE pub_live=1");
    while ($x = $pr->fetch_assoc()) { if (!empty($x['url'])) $pubMap[$x['publication']] = rtrim($x['url'], '/'); }
    $a->close();

    $rows = [];
    foreach ($arts as $aid => $art) {
        $c = $byArt[$aid];
        // Canonical publication: articles.canonical if valid, else the first listed publication.
        $canon = trim((string)($art['canonical'] ?? ''));
        if ($canon === '' || !isset($pubMap[$canon])) {
            $parts = array_filter(array_map('trim', explode(',', (string)$art['publications'])));
            foreach ($parts as $p) { if (isset($pubMap[$p])) { $canon = $p; break; } }
        }
        // A live link only makes sense for published articles.
        $canonUrl = ($art['state'] === 'published' && isset($pubMap[$canon]) && !empty($art['url']))
            ? $pubMap[$canon] . '/' . ltrim($art['url'], '/') : null;
        $rows[] = [
            'article_id'     => $aid,
            'title'          => ($art['title'] !== '' ? $art['title'] : $c['title']),
            'published_at'   => $art['submission_date'],
            'state'          => $art['state'],
            'editor_url'     => 'module-articles.php?open=' . $aid,
            'publication_key'=> $c['publication_key'],
            'ten_section'    => $c['ten_section'],
            'source_name'    => $c['source_name'],
            'canonical_pub'  => $canon !== '' ? $canon : null,
            'canonical_url'  => $canonUrl,
        ];
    }
    // Newest published first.
    usort($rows, function ($x, $y) { return strcmp((string)$y['published_at'], (string)$x['published_at']); });
    return $rows;
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
