<?php
/**
 * Phase 5: review queue (translate-on-load, cached) + promote (AI write -> Article Tool).
 */

require_once __DIR__ . '/scraper_crud.php';     // scraper_get_section, scraper_get_project
require_once __DIR__ . '/news_sites_db.php';     // ns_publication_language
require_once __DIR__ . '/ScraperAI.php';         // scraper_ai_translate, scraper_ai_write_article

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
    $ai = scraper_effective_ai($section);
    $translateError = null;

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

    // Translate any item whose cached translation is missing or in a different language.
    $toTranslate = [];
    foreach ($rows as $r) {
        if (($r['translated_lang'] ?? '') !== $lang) {
            $toTranslate[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'summary' => $r['summary']];
        }
    }
    if (!empty($toTranslate)) {
        try {
            $translations = scraper_ai_translate($ai['provider'], $ai['model'], $lang, $toTranslate);
        } catch (Throwable $e) {
            $translations = [];
            $translateError = $e->getMessage();
        }
        if (!empty($translations)) {
            $up = $conn->prepare("UPDATE ten_scraper_items SET title_translated=?, summary_translated=?, translated_lang=? WHERE id=?");
            foreach ($translations as $id => $t) {
                $tt = $t['title']; $ts = $t['summary'];
                $up->bind_param('sssi', $tt, $ts, $lang, $id);
                $up->execute();
            }
            $up->close();
            // merge into rows for this response
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

    return ['items' => $items, 'language' => $lang, 'translate_error' => $translateError];
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
    $journalistId = $section['journalist_id'] !== null ? (int)$section['journalist_id'] : null;
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

            $articleId = scraper_insert_article($section, $article, $item, $journalistId, $autoPublish);
            scraper_record_draft($conn, $itemId, $article, $ai, $journalistId, $articleId);

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

/** Insert the generated article into admin_ten.articles. Returns new article id. */
function scraper_insert_article(array $section, array $article, array $item, ?int $journalistId, bool $autoPublish): int {
    $conn = getDBConnection_TENAdmin();
    $state = $autoPublish ? 'published' : 'draft';
    $pub = $section['publication_key'];
    $sectionName = substr($section['ten_section'], 0, 20);

    $stmt = $conn->prepare(
        "INSERT INTO articles
         (title, meta_title, meta_description, meta_keywords, article_text, state, section,
          submission_date, created_by, modified_date, publish_now, journalist_id,
          publications, canonical, news_scrape_url, news_scrape_url_hash)
         VALUES (?,?,?,?,?,?,?, NOW(), 'scraper', NOW(), 1, ?, ?, ?, ?, ?)"
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

    // 7 strings, journalist_id (i), then 4 strings = 12 params
    $stmt->bind_param(
        'sssssssissss',
        $title, $mt, $md, $mk, $body, $state, $sectionName,
        $jid, $pub, $canonical, $srcUrl, $srcHash
    );
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
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
