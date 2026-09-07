<?php
/**
 * TME "Breaking News" auto-publisher.
 *
 * Reuses the scraper pipeline (feeds → items → facts → AI original write) but
 * outputs to admin_ten.articles_breaking_news (the parallel table the site's
 * /breaking-news section renders from), publishing up to N per day for TME.
 */

require_once __DIR__ . '/scraper_review.php';

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
            $facts = trim((string)$it['facts']);
            if ($facts === '') { $facts = scraper_fetch_facts((string)$it['source_url']); }

            // Need enough source material to write real coverage. Too little and the
            // model (correctly) refuses to invent detail and returns a stub — never
            // publish that. Skip and discard so it isn't retried on every run.
            if (mb_strlen(trim($facts)) < 400) {
                $log['skipped'][] = 'insufficient facts: ' . $it['title'];
                $d = getDBConnection();
                $ds = $d->prepare("UPDATE ten_scraper_items SET status='discarded' WHERE id=?");
                $ds->bind_param('i', $it['id']); $ds->execute(); $ds->close(); $d->close();
                continue;
            }

            $article = scraper_ai_write_article($ai['provider'], $ai['model'], $ai['prompt'], [
                'source_title'    => $it['title'],
                'source_summary'  => $it['summary'],
                'source_facts'    => $facts,
                'source_url'      => $it['source_url'],
                'section'         => 'Breaking News',
                'publication'     => 'tme',
                'target_language' => 'English',
            ]);

            // Guard against placeholder/stub output (thin body or "awaiting details"
            // language) — never let that reach the live site.
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags($article['body_html'] ?? '')));
            $blurbLc = mb_strtolower($article['title'] . ' ' . $plain);
            $stub = false;
            foreach (['awaiting', 'no details', 'details are not', 'placeholder', 'insufficient', 'no verified', 'unable to', 'not enough information', 'no further information', 'details remain', 'to be confirmed'] as $mrk) {
                if (mb_strpos($blurbLc, $mrk) !== false) { $stub = true; break; }
            }
            if ($stub || mb_strlen($plain) < 600) {
                $log['skipped'][] = 'stub/thin output rejected: ' . $it['title'];
                $d = getDBConnection();
                $ds = $d->prepare("UPDATE ten_scraper_items SET status='discarded' WHERE id=?");
                $ds->bind_param('i', $it['id']); $ds->execute(); $ds->close(); $d->close();
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
