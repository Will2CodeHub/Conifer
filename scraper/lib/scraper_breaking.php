<?php
/**
 * TME "Breaking News" auto-publisher.
 *
 * Reuses the scraper pipeline (feeds → items → facts → AI original write) but
 * outputs to admin_ten.articles_breaking_news (the parallel table the site's
 * /breaking-news section renders from), publishing up to N per day for TME.
 */

require_once __DIR__ . '/scraper_review.php';

/** Mark a scraper item discarded so it isn't retried every run. */
function scraper_breaking_discard_item(int $itemId): void {
    $d = getDBConnection();
    $ds = $d->prepare("UPDATE ten_scraper_items SET status='discarded' WHERE id=?");
    $ds->bind_param('i', $itemId); $ds->execute(); $ds->close();
    $d->close();
}

/**
 * Faithfully REWRITE (and translate to English if needed) a real news article
 * for republication as a short breaking-news item. Grounded strictly on the
 * supplied source text — no word minimum, no invented detail. Returns the
 * article array or null on failure.
 */
function scraper_ai_rewrite_breaking(string $provider, string $model, string $sourceTitle, string $sourceText): ?array {
    $system = "You are a news sub-editor for The Munich Eye. You are given a REAL news article. REWRITE it faithfully in clear, concise English (translate to English if it is in another language) as a short breaking-news item.\n"
        . "ABSOLUTE RULES:\n"
        . "1. Use ONLY information explicitly present in the article below. Do NOT add, infer, assume, or invent ANY fact, name, number, date, time, place, quote, cause, or consequence not in the text.\n"
        . "2. Do NOT localise to Munich and do NOT add any local angle, background, or context the source did not state.\n"
        . "3. Keep every proper noun, figure and quotation exactly as in the source. Rewrite the wording in your own words (do not copy sentences verbatim), but never change or invent the facts.\n"
        . "4. If the source is short, your rewrite MUST be short too — never pad. Target 120-350 words and never longer than the source.\n"
        . "5. Neutral and factual, no opinion. The body must OPEN with a paragraph, never a heading.\n"
        . "Return ONLY a JSON object: {\"title\":\"...\",\"body_html\":\"<p>...</p><p>...</p>\",\"meta_title\":\"...\",\"meta_description\":\"...\",\"meta_keywords\":\"...\"}. No commentary, no code fences.";
    $user = "SOURCE HEADLINE: " . $sourceTitle . "\n\nSOURCE ARTICLE:\n" . mb_substr($sourceText, 0, 9000);
    $text = scraper_ai_raw($provider, $model, $system, $user, 2000, $provider === 'openai');
    $decoded = scraper_ai_decode_json($text);
    if (!$decoded || empty($decoded['body_html'])) return null;

    $body = (string)$decoded['body_html'];
    $body = preg_replace('/^\s*<h[1-6][^>]*>(.*?)<\/h[1-6]>/is', '<p>$1</p>', $body, 1); // never open with a heading
    $body = preg_replace('#<a\b[^>]*>(.*?)</a>#is', '$1', $body);                          // no inline links
    $body = preg_replace('/<p(?![^>]*style=)(\s[^>]*)?>/i', '<p style="margin:0 0 1em;"$1>', $body);
    return [
        'title'            => trim((string)($decoded['title'] ?? $sourceTitle)),
        'body_html'        => $body,
        'meta_title'       => substr(trim((string)($decoded['meta_title'] ?? $decoded['title'] ?? '')), 0, 200),
        'meta_description' => substr(trim((string)($decoded['meta_description'] ?? '')), 0, 500),
        'meta_keywords'    => substr(trim((string)($decoded['meta_keywords'] ?? '')), 0, 500),
    ];
}

/**
 * True if the rewrite is grounded in the source: the distinctive tokens it uses
 * (capitalised names, acronyms, multi-digit numbers) mostly appear in the source
 * text. Catches the hallucination signature — invented names/figures/places.
 */
function scraper_breaking_is_grounded(array $article, string $sourceText, string $sourceTitle): bool {
    $hay = mb_strtolower($sourceText . ' ' . $sourceTitle);
    $body = strip_tags(($article['title'] ?? '') . '. ' . ($article['body_html'] ?? ''));
    preg_match_all('/\b[A-Z][A-Za-z]{3,}\b|\b[A-Z]{2,}\b|\b\d{2,}\b/u', $body, $mm);
    $stop = array_flip(['Munich','Eye','MunichEye','TEN','News','English','The','This','That','These','Those','After','Before','While','When','Where','Which','What','With','From','Into','About','Over','Under','Their','They','There','Then','Than','Have','Been','Will','Would','Could','Should','According','Officials','Authorities','However','Meanwhile','Also','Amid','Following','During','Since','Between','Among','January','February','March','April','June','July','August','September','October','November','December','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday']);
    $distinct = [];
    foreach ($mm[0] as $w) {
        if (isset($stop[$w])) continue;
        $distinct[$w] = true;
    }
    $distinct = array_keys($distinct);
    if (count($distinct) < 5) return true; // too little to judge; other guards apply

    $absent = 0; $absentNums = 0;
    foreach ($distinct as $w) {
        if (mb_strpos($hay, mb_strtolower($w)) === false) {
            $absent++;
            if (ctype_digit($w)) $absentNums++;
        }
    }
    $frac = $absent / count($distinct);
    if ($absentNums >= 3) return false;   // several invented figures
    if ($frac > 0.35) return false;       // too many invented names/places
    return true;
}

/** The pub_section id for TME Breaking News, or null if not configured. */
function scraper_breaking_section_id(): ?int {
    $c = getDBConnection();
    $r = $c->query("SELECT id FROM ten_scraper_pub_sections WHERE publication_key='tme' AND ten_section='Breaking News' AND is_active=1 LIMIT 1")->fetch_assoc();
    $c->close();
    return $r ? (int)$r['id'] : null;
}

/** How many breaking-news articles TME has already published today. */
function scraper_breaking_published_today(): int {
    $a = getDBConnection_TENAdmin();
    $n = $a->query("SELECT COUNT(*) c FROM articles_breaking_news WHERE state='published' AND DATE(submission_date)=CURDATE() AND publications LIKE '%tme%'")->fetch_assoc()['c'];
    $a->close();
    return (int)$n;
}

/** Insert a generated article into admin_ten.articles_breaking_news (published). Returns new id. */
function scraper_insert_breaking_article(array $article, array $item, ?int $journalistId, string $createdBy): int {
    $a = getDBConnection_TENAdmin();
    $stmt = $a->prepare(
        "INSERT INTO articles_breaking_news
         (title, meta_title, meta_description, meta_keywords, article_text, state, section,
          submission_date, created_by, modified_date, publish_now, journalist_id,
          publications, canonical, news_scrape_url, news_scrape_url_hash, imageless)
         VALUES (?,?,?,?,?, 'published', 'breaking-news', NOW(), ?, NOW(), 1, ?, ?, ?, ?, ?, 1)"
    );
    $title = $article['title'];
    $mt = substr($article['meta_title'], 0, 200);
    $md = substr($article['meta_description'], 0, 500);
    $mk = substr($article['meta_keywords'], 0, 500);
    $body = $article['body_html'];
    $cb = substr($createdBy, 0, 100);
    $jid = $journalistId;
    $pub = 'tme';
    $canonical = 'tme';
    $srcUrl = substr($item['source_url'], 0, 200);
    $srcHash = $item['source_url_hash'];
    // types: title,mt,md,mk,body(5 s), created_by(s)=6, journalist_id(i), publications,canonical,news_scrape_url,hash(4 s)
    $stmt->bind_param('ssssssissss', $title, $mt, $md, $mk, $body, $cb, $jid, $pub, $canonical, $srcUrl, $srcHash);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();

    $url = substr(scraper_slugify($article['title']) . '-' . $id, 0, 200);
    $up = $a->prepare("UPDATE articles_breaking_news SET url=? WHERE id=?");
    $up->bind_param('si', $url, $id);
    $up->execute();
    $up->close();
    $a->close();
    return $id;
}

/**
 * Publish up to $perDay TME breaking-news articles today (self-limiting).
 * Picks top-ranked 'new' items from the Breaking News section, skips topics
 * already covered today (or duplicated within this run), AI-writes each as
 * original coverage, inserts as published, and rebuilds the TME front page.
 */
function scraper_breaking_auto_publish(int $perDay = 2): array {
    $log = ['already_today' => 0, 'published' => [], 'skipped' => [], 'errors' => []];

    $sid = scraper_breaking_section_id();
    if (!$sid) { $log['error'] = 'TME Breaking News section not configured'; return $log; }

    $already = scraper_breaking_published_today();
    $log['already_today'] = $already;
    $need = $perDay - $already;
    if ($need <= 0) { $log['action'] = 'quota already met for today'; return $log; }

    $section = scraper_get_section($sid);
    $ai = scraper_effective_ai($section);
    list($inhouseId, $inhouseName) = scraper_inhouse_author('tme');
    $journalistId = ($section['journalist_id'] !== null && $section['journalist_id'] !== '')
        ? (int)$section['journalist_id'] : $inhouseId;

    // Candidates: ranked 'new' items first, then most recent.
    $c = getDBConnection();
    $stmt = $c->prepare("SELECT id, title, summary, facts, source_url, source_url_hash, cluster_id
                         FROM ten_scraper_items
                         WHERE pub_section_id=? AND status='new'
                         ORDER BY (curate_rank IS NULL), curate_rank ASC, published_at DESC, id DESC
                         LIMIT 25");
    $stmt->bind_param('i', $sid);
    $stmt->execute();
    $cands = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $c->close();

    // Dedup against today's already-published breaking topics + within this run.
    $usedTokens = scraper_covered_today_titles('tme', 'breaking-news', 'articles_breaking_news');

    foreach ($cands as $it) {
        if ($need <= 0) break;
        $tok = scraper_title_tokens((string)$it['title']);
        $dup = false;
        foreach ($usedTokens as $c2) {
            if (scraper_titles_similar($tok, $c2) >= 0.5) { $dup = true; break; }
        }
        if ($dup) { $log['skipped'][] = 'duplicate topic: ' . $it['title']; continue; }

        try {
            // Get the REAL article text — the rewrite is grounded strictly on this.
            $src = trim((string)$it['facts']);
            if (mb_strlen($src) < 800) { $src = scraper_fetch_facts((string)$it['source_url'], 9000); }

            // Require substantial real source text. Thin sources are the cause of
            // hallucination (the model pads to fill), so skip rather than risk it.
            if (mb_strlen(trim($src)) < 1000) {
                $log['skipped'][] = 'source too thin (' . mb_strlen(trim($src)) . ' chars): ' . $it['title'];
                scraper_breaking_discard_item((int)$it['id']);
                continue;
            }

            // Faithful rewrite/translate — source-only, no word minimum.
            $article = scraper_ai_rewrite_breaking($ai['provider'], $ai['model'], (string)$it['title'], $src);
            if (!$article) {
                $log['skipped'][] = 'rewrite produced no article: ' . $it['title'];
                scraper_breaking_discard_item((int)$it['id']);
                continue;
            }

            $plain = trim(preg_replace('/\s+/', ' ', strip_tags($article['body_html'] ?? '')));
            if (mb_strlen($plain) < 350) {
                $log['skipped'][] = 'output too short: ' . $it['title'];
                scraper_breaking_discard_item((int)$it['id']);
                continue;
            }

            // Grounding guard: reject if the rewrite introduced names/numbers that
            // are not in the source (the hallucination signature).
            if (!scraper_breaking_is_grounded($article, $src, (string)$it['title'])) {
                $log['skipped'][] = 'ungrounded output rejected (possible hallucination): ' . $it['title'];
                scraper_breaking_discard_item((int)$it['id']);
                continue;
            }

            $artId = scraper_insert_breaking_article($article, $it, $journalistId, $inhouseName);

            $cc = getDBConnection();
            $u = $cc->prepare("UPDATE ten_scraper_items SET status='promoted' WHERE id=?");
            $u->bind_param('i', $it['id']); $u->execute(); $u->close();
            scraper_record_draft($cc, (int)$it['id'], $article, $ai, $journalistId, $artId);
            $cc->close();

            $log['published'][] = ['article_id' => $artId, 'title' => $article['title']];
            $usedTokens[] = $tok;
            $need--;
        } catch (Throwable $e) {
            $log['errors'][] = $it['title'] . ': ' . $e->getMessage();
        }
    }

    if (!empty($log['published'])) {
        // Rebuild TME's cached front page so the new breaking items appear.
        try { scraper_trigger_publication_cache('tme'); } catch (Throwable $e) { $log['cache_error'] = $e->getMessage(); }
    }
    return $log;
}
