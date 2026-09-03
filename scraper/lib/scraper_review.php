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
function scraper_promote_items(int $pubSectionId, array $itemIds): array {
    $section = scraper_get_section($pubSectionId);
    if (!$section) return [['ok' => false, 'error' => 'Section not found']];

    $ai = scraper_effective_ai($section);
    $lang = ns_publication_language($section['publication_key']);
    list($inhouseId, $inhouseName) = scraper_inhouse_author($section['publication_key']);
    $journalistId = ($section['journalist_id'] !== null && $section['journalist_id'] !== '')
        ? (int)$section['journalist_id']
        : $inhouseId;
    $autoPublish = (int)($section['auto_publish'] ?? 0) === 1;

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
            $article = scraper_ai_write_article($ai['provider'], $ai['model'], $ai['prompt'], [
                'source_title' => $item['title'],
                'source_summary' => $item['summary'],
                'source_facts' => $item['facts'],
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
          publications, canonical, news_scrape_url, news_scrape_url_hash)
         VALUES (?,?,?,?,?,?,?, NOW(), ?, NOW(), 1, ?, ?, ?, ?, ?)"
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
