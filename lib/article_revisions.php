<?php
/**
 * Article write + edit-history (revision) helpers.
 *
 * This file is the single source of truth for the small helpers that write an
 * article (slug, schedule normalisation, per-publication flags, front-page cache
 * regeneration) AND for the edit-history versioning added 2026-10-10:
 *
 *   - Every successful save in ajax/save_article.php logs a full JSON snapshot of
 *     the article to `article_revisions` (ten_write_revision).
 *   - ajax/article_revisions.php lists/views/diffs/restores those snapshots.
 *
 * Snapshots are a JSON blob (Approach B) so the stored shape stays stable as the
 * editable field set evolves — we only ever read a whole version back (view /
 * compare / restore), never filter by an individual field.
 *
 * Retention: keep every 'published'-state snapshot, plus the newest
 * TEN_REVISION_KEEP_N non-published snapshots per article.
 */

if (!defined('TEN_REVISION_KEEP_N')) {
    define('TEN_REVISION_KEEP_N', 30);
}

/**
 * The editable article fields captured in a snapshot, in a fixed order. Keeping
 * this list in one place means capture (ten_build_snapshot) and restore
 * (ten_apply_article_fields) can never drift apart. `pub_flags` is stored
 * alongside these as a nested map and handled separately.
 */
if (!defined('TEN_REVISION_SNAPSHOT_FIELDS')) {
    define('TEN_REVISION_SNAPSHOT_FIELDS', serialize([
        'title', 'article_text', 'alias', 'tags', 'section', 'section_subcat',
        'journalist_id', 'publications', 'canonical', 'meta_title',
        'meta_description', 'meta_keywords', 'evergreen', 'featured', 'sponsored',
        'frontpage_temp', 'note', 'created_by', 'publish_now', 'publish_from',
        'publish_to', 'state',
    ]));
}

/** @return string[] the snapshot field list (unserialised const). */
function ten_revision_fields(): array {
    return unserialize(TEN_REVISION_SNAPSHOT_FIELDS);
}

// ---------------------------------------------------------------------------
// Article write helpers (moved here from save_article.php so restore can reuse
// them). Guarded with function_exists so including this file alongside any
// legacy copy can never fatal on a redeclare.
// ---------------------------------------------------------------------------

if (!function_exists('ten_article_slug')) {
    /**
     * URL slug from a title: lowercase, non-alphanumerics to hyphens. Mirrors the
     * scraper's scraper_slugify() so manually-created and scraped articles build
     * the same <slug>-<id> site URL.
     */
    function ten_article_slug(string $text): string {
        $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', trim($text));
        $text = trim(preg_replace('/-+/', '-', $text), '-');
        $text = function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
        if ($text === '') {
            return 'article-' . time();
        }
        return function_exists('mb_substr') ? mb_substr($text, 0, 190) : substr($text, 0, 190);
    }
}

if (!function_exists('ten_normalize_datetime')) {
    /**
     * Normalise a scheduling value to a MySQL 'Y-m-d H:i:s' string, or null when empty.
     * Accepts the flatpickr calendar output ('Y-m-d H:i'), the legacy 'd-m-Y[ H:i]' text,
     * and a plain date; anything unparseable becomes null.
     */
    function ten_normalize_datetime($v): ?string {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d', '!d-m-Y H:i:s', '!d-m-Y H:i', '!d-m-Y', '!d/m/Y H:i', '!d/m/Y'] as $f) {
            $dt = DateTime::createFromFormat($f, $v);
            if ($dt !== false) {
                $errs = DateTime::getLastErrors();
                if ($errs === false || ($errs['warning_count'] === 0 && $errs['error_count'] === 0)) {
                    return $dt->format('Y-m-d H:i:s');
                }
            }
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}

if (!function_exists('ten_ensure_pub_flags_table')) {
    /** Create the per-publication flags table on first use (idempotent). */
    function ten_ensure_pub_flags_table($conn) {
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
    }
}

if (!function_exists('ten_write_pub_flags')) {
    /**
     * Replace an article's per-publication flag rows. Writes one row per selected publication
     * (even all-zero) so the table fully represents the article's pubs for the site generators.
     */
    function ten_write_pub_flags($conn, $articleId, array $selPubs, array $pubFlags) {
        ten_ensure_pub_flags_table($conn);
        $articleId = (int) $articleId;
        $conn->query("DELETE FROM article_pub_flags WHERE article_id = $articleId");
        if (!$selPubs) { return; }
        $ins = $conn->prepare("INSERT INTO article_pub_flags (article_id, publication, frontpage, section_head, sponsored) VALUES (?,?,?,?,?)");
        foreach ($selPubs as $pub) {
            $pf = $pubFlags[$pub] ?? ['frontpage' => 0, 'section_head' => 0, 'sponsored' => 0];
            $ins->bind_param('isiii', $articleId, $pub, $pf['frontpage'], $pf['section_head'], $pf['sponsored']);
            $ins->execute();
        }
        $ins->close();
    }
}

if (!function_exists('ten_regenerate_publication_caches')) {
    /**
     * After an article is published, rebuild the front page (index.php) of each
     * publication it appears on by calling that site's generate_index_page.php.
     * Best-effort: a cache failure must never affect the save.
     */
    function ten_regenerate_publication_caches($publicationsCsv) {
        try {
            $acr = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$publicationsCsv)))));
            if (!$acr) return [];
            $conn = getDBConnection_TENAdmin();
            $in = implode(',', array_fill(0, count($acr), '?'));
            $stmt = $conn->prepare("SELECT url FROM publications WHERE pub_live = 1 AND publication IN ($in)");
            $stmt->bind_param(str_repeat('s', count($acr)), ...$acr);
            $stmt->execute();
            $res = $stmt->get_result();
            $urls = [];
            while ($row = $res->fetch_assoc()) {
                $u = rtrim((string)$row['url'], '/');
                if ($u !== '') $urls[] = $u . '/generate_index_page.php';
            }
            $stmt->close();
            $conn->close();
            foreach ($urls as $u) {
                $ch = curl_init($u);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
            return $urls;
        } catch (Throwable $e) {
            error_log('cache regen failed: ' . $e->getMessage());
            return [];
        }
    }
}

// ---------------------------------------------------------------------------
// Revision (edit-history) helpers
// ---------------------------------------------------------------------------

/** Create the article_revisions table on first use (idempotent). */
function ten_ensure_revisions_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS article_revisions (
        id             INT NOT NULL AUTO_INCREMENT,
        article_id     INT NOT NULL,
        edited_by      INT NOT NULL DEFAULT 0,
        edited_by_name VARCHAR(190) NOT NULL DEFAULT '',
        edited_at      DATETIME NOT NULL,
        state          VARCHAR(20) NOT NULL DEFAULT '',
        action         VARCHAR(10) NOT NULL DEFAULT 'save',
        snapshot       LONGTEXT,
        PRIMARY KEY (id),
        KEY idx_article (article_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Build a snapshot JSON string from a field map + a per-publication flags map.
 * Only the known snapshot fields are kept (missing ones default to '').
 */
function ten_build_snapshot(array $fields, array $pubFlags): string {
    $snap = [];
    foreach (ten_revision_fields() as $k) {
        $snap[$k] = array_key_exists($k, $fields) ? $fields[$k] : '';
    }
    $snap['pub_flags'] = $pubFlags;
    return json_encode($snap, JSON_UNESCAPED_UNICODE);
}

/**
 * PURE. Given the article's revision rows newest-first (each ['id','state',...])
 * and how many non-published snapshots to keep, return the ids to delete.
 * Every 'published'-state snapshot is always kept and does not count toward N.
 */
function ten_revisions_to_prune(array $rowsNewestFirst, int $keepN): array {
    $deleted = [];
    $keptNonPublished = 0;
    foreach ($rowsNewestFirst as $r) {
        if (($r['state'] ?? '') === 'published') {
            continue; // always keep
        }
        $keptNonPublished++;
        if ($keptNonPublished > $keepN) {
            $deleted[] = (int) $r['id'];
        }
    }
    return $deleted;
}

/**
 * Insert one revision row for an article, then prune per the retention policy.
 * Best-effort: never throws into the caller (a history failure must not break a save).
 */
function ten_write_revision($conn, int $articleId, int $editedBy, string $editedByName, string $state, string $action, string $snapshotJson): void {
    try {
        ten_ensure_revisions_table($conn);
        $ins = $conn->prepare("INSERT INTO article_revisions
            (article_id, edited_by, edited_by_name, edited_at, state, action, snapshot)
            VALUES (?,?,?,NOW(),?,?,?)");
        $ins->bind_param('iissss', $articleId, $editedBy, $editedByName, $state, $action, $snapshotJson);
        $ins->execute();
        $ins->close();

        // Prune: load this article's rows newest-first, decide what to drop, delete.
        $sel = $conn->prepare("SELECT id, state FROM article_revisions WHERE article_id = ? ORDER BY id DESC");
        $sel->bind_param('i', $articleId);
        $sel->execute();
        $res = $sel->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }
        $sel->close();

        $toDelete = ten_revisions_to_prune($rows, TEN_REVISION_KEEP_N);
        if ($toDelete) {
            $in = implode(',', array_fill(0, count($toDelete), '?'));
            $del = $conn->prepare("DELETE FROM article_revisions WHERE id IN ($in)");
            $del->bind_param(str_repeat('i', count($toDelete)), ...$toDelete);
            $del->execute();
            $del->close();
        }
    } catch (Throwable $e) {
        error_log('ten_write_revision failed: ' . $e->getMessage());
    }
}

/**
 * Overwrite an article's editable fields from a snapshot field map, then rebuild
 * the derived bits (url slug, featured image, imageless flag, per-pub flags) the
 * same way a normal save does. Used by the restore action.
 */
function ten_apply_article_fields($conn, int $articleId, array $f): void {
    $get = function (string $k, $default = '') use ($f) {
        return array_key_exists($k, $f) ? $f[$k] : $default;
    };

    $title          = (string) $get('title');
    $articleText    = (string) $get('article_text');
    $alias          = (string) $get('alias');
    $tags           = (string) $get('tags');
    $section        = (string) $get('section');
    $sectionSubcat  = (string) $get('section_subcat');
    $journalistId   = (int) $get('journalist_id', 0);
    $publications   = (string) $get('publications');
    $canonical      = (string) $get('canonical');
    $metaTitle      = (string) $get('meta_title');
    $metaDescription= (string) $get('meta_description');
    $metaKeywords   = (string) $get('meta_keywords');
    $evergreen      = (int) $get('evergreen', 0);
    $featured       = (int) $get('featured', 0);
    $sponsored      = (int) $get('sponsored', 0);
    $headline       = (int) $get('frontpage_temp', 0);
    $note           = (string) $get('note');
    $createdBy      = (string) $get('created_by');
    $publishNow     = (int) $get('publish_now', 0);
    $publishFrom    = $get('publish_from', null);
    $publishTo      = $get('publish_to', null);
    $state          = (string) $get('state', 'draft');
    if ($publishFrom === '') { $publishFrom = null; }
    if ($publishTo === '')   { $publishTo = null; }

    $query = "UPDATE articles SET
        title = ?, article_text = ?, alias = ?, tags = ?, section = ?, section_subcat = ?,
        journalist_id = ?, publications = ?, canonical = ?, meta_title = ?, meta_description = ?,
        meta_keywords = ?, evergreen = ?, featured = ?, sponsored = ?, frontpage_temp = ?,
        note = ?, created_by = ?, publish_now = ?, publish_from = ?, publish_to = ?, state = ?,
        modified_date = NOW()
        WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('ssssssisssssiiiississsi',
        $title, $articleText, $alias, $tags, $section, $sectionSubcat, $journalistId,
        $publications, $canonical, $metaTitle, $metaDescription, $metaKeywords, $evergreen,
        $featured, $sponsored, $headline, $note, $createdBy, $publishNow, $publishFrom,
        $publishTo, $state, $articleId);
    $stmt->execute();
    $stmt->close();

    // Rebuild the site URL from the restored title (keeps -<id> suffix unique).
    $slug = substr(ten_article_slug($title) . '-' . $articleId, 0, 200);
    $su = $conn->prepare("UPDATE articles SET url = ? WHERE id = ?");
    $su->bind_param('si', $slug, $articleId); $su->execute(); $su->close();

    // Featured image from first body image (only if not already set).
    if (preg_match('#<img[^>]+src=[\'"]([^\'"]+)[\'"]#i', $articleText, $mm)) {
        $mainImage = basename($mm[1]);
        $u2 = $conn->prepare("UPDATE articles SET image_url = ? WHERE id = ? AND (image_url IS NULL OR image_url = '')");
        $u2->bind_param('si', $mainImage, $articleId); $u2->execute(); $u2->close();
    }

    // Imageless flag.
    $imageless = preg_match('#<img[^>]+src=#i', $articleText) ? 0 : 1;
    $iu = $conn->prepare("UPDATE articles SET imageless = ? WHERE id = ?");
    $iu->bind_param('ii', $imageless, $articleId); $iu->execute(); $iu->close();

    // Per-publication flags, when the snapshot carried them.
    $pubFlags = $get('pub_flags', []);
    if (is_array($pubFlags) && $pubFlags) {
        ten_write_pub_flags($conn, $articleId, array_keys($pubFlags), $pubFlags);
    }
}

/**
 * PURE. Field-by-field diff of two snapshot maps (old vs new). Returns
 * [field => ['changed'=>bool, 'old'=>mixed, 'new'=>mixed]] over the union of
 * snapshot fields (pub_flags compared as JSON).
 */
function ten_diff_snapshots(array $old, array $new): array {
    $fields = ten_revision_fields();
    $fields[] = 'pub_flags';
    $diff = [];
    foreach ($fields as $k) {
        $o = $old[$k] ?? null;
        $n = $new[$k] ?? null;
        $oc = is_array($o) ? json_encode($o) : (string) $o;
        $nc = is_array($n) ? json_encode($n) : (string) $n;
        $diff[$k] = ['changed' => ($oc !== $nc), 'old' => $o, 'new' => $n];
    }
    return $diff;
}
