<?php
session_start();
require_once '../config.php';
require_once '../config_ten_admin.php';

header('Content-Type: application/json');

/**
 * URL slug from a title: lowercase, non-alphanumerics to hyphens. Mirrors the
 * scraper's scraper_slugify() so manually-created and scraped articles build
 * the same <slug>-<id> site URL (an empty url makes the site link to the front
 * page instead of the article).
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

/**
 * Normalise a scheduling value to a MySQL 'Y-m-d H:i:s' string, or null when empty.
 * Accepts the flatpickr calendar output ('Y-m-d H:i'), the legacy 'd-m-Y[ H:i]' text,
 * and a plain date; anything unparseable becomes null (= no schedule). The leading '!'
 * resets unspecified parts (so a date-only value gets 00:00:00, not the current time).
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

/**
 * After an article is published, rebuild the front page (index.php) of
 * each publication it appears on by calling that site's generate_index_page.php.
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

// Check login
if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated']);
    exit;
}

// Get POST data
$articleId = isset($_POST['id']) ? intval($_POST['id']) : 0;
$title = isset($_POST['title']) ? trim($_POST['title']) : '';
$articleText = isset($_POST['article_text']) ? $_POST['article_text'] : '';
$alias = isset($_POST['alias']) ? trim($_POST['alias']) : '';
$tags = isset($_POST['tags']) ? trim($_POST['tags']) : '';
$section = isset($_POST['section']) ? trim($_POST['section']) : '';
$sectionSubcat = isset($_POST['section_subcat']) ? trim($_POST['section_subcat']) : '';
$author = isset($_POST['author']) ? intval($_POST['author']) : 0;
$publications = isset($_POST['publications']) ? trim($_POST['publications']) : '';
$canonical = isset($_POST['canonical']) ? trim($_POST['canonical']) : '';
$metaTitle = isset($_POST['meta_title']) ? trim($_POST['meta_title']) : '';
$metaDescription = isset($_POST['meta_description']) ? trim($_POST['meta_description']) : '';
$metaKeywords = isset($_POST['meta_keywords']) ? trim($_POST['meta_keywords']) : '';
$evergreen = isset($_POST['evergreen']) ? intval($_POST['evergreen']) : 0;
$featured = isset($_POST['featured']) ? intval($_POST['featured']) : 0;
$sponsored = isset($_POST['sponsored']) ? intval($_POST['sponsored']) : 0;
$headline = isset($_POST['frontpage_temp']) ? intval($_POST['frontpage_temp']) : 0;
$note = isset($_POST['note']) ? trim($_POST['note']) : '';
$publishNow = isset($_POST['publish_now']) ? intval($_POST['publish_now']) : 0;
$publishFrom = isset($_POST['publish_from']) ? trim($_POST['publish_from']) : null;
$publishTo = isset($_POST['publish_to']) ? trim($_POST['publish_to']) : null;
$action = isset($_POST['action']) ? $_POST['action'] : 'save'; // 'save', 'submit', 'publish'

// Per-publication flags: {pub: {frontpage, section_head, sponsored}}. When present they are
// authoritative — the legacy single columns (frontpage_temp/featured/sponsored) are derived
// as the OR across the article's publications so sites still reading the old columns keep
// working until they read article_pub_flags. Absent (an older caller) => keep the posted
// legacy flags and don't touch the join table.
$hasPubFlags = isset($_POST['pub_flags']);
$pubFlags = [];
if ($hasPubFlags) {
    $selPubs = array_values(array_filter(array_map('trim', explode(',', $publications))));
    $pfRaw = json_decode((string) $_POST['pub_flags'], true);
    if (is_array($pfRaw)) {
        foreach ($selPubs as $pub) {
            $pv = $pfRaw[$pub] ?? [];
            $pubFlags[$pub] = [
                'frontpage'    => !empty($pv['frontpage']) ? 1 : 0,
                'section_head' => !empty($pv['section_head']) ? 1 : 0,
                'sponsored'    => !empty($pv['sponsored']) ? 1 : 0,
            ];
        }
    }
    // Derive the legacy columns from the per-pub flags.
    $headline = 0; $featured = 0; $sponsored = 0;
    foreach ($pubFlags as $pf) {
        if ($pf['frontpage'])    { $headline = 1; }
        if ($pf['section_head']) { $featured = 1; }
        if ($pf['sponsored'])    { $sponsored = 1; }
    }
}

// Validation
if (empty($title)) {
    echo json_encode(['status' => 'error', 'message' => 'Title is required']);
    exit;
}

if (empty($articleText)) {
    echo json_encode(['status' => 'error', 'message' => 'Article content is required']);
    exit;
}

if (empty($publications)) {
    echo json_encode(['status' => 'error', 'message' => 'At least one publication must be selected']);
    exit;
}

try {
    // Get user info
    $userId = $_SESSION['ten_user_id'] ?? 0;
    $position = $_SESSION['ten_position'] ?? '';
    $isUserAdmin = isAdmin();
    
    // Check publish permission — keep role list in sync with get_article_data.php
    $canPublish = $isUserAdmin || in_array($position, ['Admin', 'Super Admin', 'Editor-in-Chief', 'Managing Editor', 'General Editor', 'Edition Editor-in-Chief', 'Section Editor', 'Administrator', 'Manager', 'Editor', 'Super User']);
    
    // Determine state based on action
    $state = 'draft';
    switch ($action) {
        case 'submit':
            $state = 'under review';
            break;
        case 'publish':
            if (!$canPublish) {
                echo json_encode(['status' => 'error', 'message' => 'You do not have permission to publish articles']);
                exit;
            }
            $state = 'published';
            break;
        case 'save':
        default:
            $state = 'draft';
            break;
    }
    
    // Connect to admin_ten database
    $conn = getDBConnection_TENAdmin();
    
    // Check if article exists and user has permission
    if ($articleId > 0) {
        $checkStmt = $conn->prepare("SELECT journalist_id, state, section, publications FROM articles WHERE id = ?");
        $checkStmt->bind_param('i', $articleId);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $existingArticle = $result->fetch_assoc();
        $checkStmt->close();
        
        if (!$existingArticle) {
            echo json_encode(['status' => 'error', 'message' => 'Article not found']);
            $conn->close();
            exit;
        }
        
        // Permission check — keep role tiers in sync with get_article_data.php.
        // journalist_id in articles references TEN_Management.ten_users.id, same as $userId.
        // Editors are scoped to their publication(s); Section Editors also to their
        // section(s); a user can always edit their own article.
        $userPubs = array_values(array_filter(array_map('trim', explode(',', (string)($_SESSION['ten_publication'] ?? '')))));
        $userSecs = array_values(array_filter(array_map('trim', explode(',', (string)($_SESSION['ten_section'] ?? '')))));
        $artPubs  = array_values(array_filter(array_map('trim', explode(',', (string)($existingArticle['publications'] ?? '')))));
        $inUserPub = (bool) array_intersect($userPubs, $artPubs);

        $seeAll = $isUserAdmin || in_array($position, ['Admin', 'Super Admin', 'Super User', 'Administrator', 'Manager'], true);
        $pubScopedEditor = in_array($position, ['Editor', 'Managing Editor', 'General Editor', 'Editor-in-Chief', 'Edition Editor-in-Chief'], true);

        $canEdit = $seeAll
                   || ($pubScopedEditor && $inUserPub)
                   || ($position === 'Section Editor' && $inUserPub && in_array($existingArticle['section'], $userSecs, true))
                   || ($existingArticle['journalist_id'] == $userId);
        
        if (!$canEdit) {
            echo json_encode(['status' => 'error', 'message' => 'You do not have permission to edit this article']);
            $conn->close();
            exit;
        }
    }
    
    // Normalise the schedule inputs to a MySQL datetime. Accepts the calendar's
    // 'Y-m-d H:i' as well as the legacy 'dd-mm-yyyy' / 'dd-mm-yyyy HH:MM' text.
    $publishFrom = ten_normalize_datetime($publishFrom);
    $publishTo   = ten_normalize_datetime($publishTo);
    
    // Set default author if not provided
    if ($author == 0) {
        $author = $userId;
    }
    // Journalists can only ever author as themselves — ignore any posted author.
    if ($position === 'Journalist') {
        $author = $userId;
    }

    // Resolve the author's display name so created_by always tracks journalist_id.
    // Without this, reassigning the author on an existing article left created_by as
    // the original writer, so the live article showed two different authors.
    $createdBy = '';
    try {
        $cn = getDBConnection();
        $cs = $cn->prepare("SELECT full_name FROM ten_users WHERE id = ?");
        $cs->bind_param('i', $author); $cs->execute();
        $cr = $cs->get_result()->fetch_assoc(); $cs->close(); $cn->close();
        $createdBy = $cr['full_name'] ?? '';
    } catch (Throwable $e) { $createdBy = ''; }
    if ($createdBy === '') { $createdBy = $_SESSION['ten_full_name'] ?? ($_SESSION['ten_username'] ?? ''); }

    // Update or insert
    if ($articleId > 0) {
        // UPDATE
        $query = "UPDATE articles SET
            title = ?,
            article_text = ?,
            alias = ?,
            tags = ?,
            section = ?,
            section_subcat = ?,
            journalist_id = ?,
            publications = ?,
            canonical = ?,
            meta_title = ?,
            meta_description = ?,
            meta_keywords = ?,
            evergreen = ?,
            featured = ?,
            sponsored = ?,
            frontpage_temp = ?,
            note = ?,
            created_by = ?,
            publish_now = ?,
            publish_from = ?,
            publish_to = ?,
            state = ?
            WHERE id = ?";

        $stmt = $conn->prepare($query);
        $stmt->bind_param('ssssssisssssiiiississsi',
            $title,
            $articleText,
            $alias,
            $tags,
            $section,
            $sectionSubcat,
            $author,
            $publications,
            $canonical,
            $metaTitle,
            $metaDescription,
            $metaKeywords,
            $evergreen,
            $featured,
            $sponsored,
            $headline,
            $note,
            $createdBy,
            $publishNow,
            $publishFrom,
            $publishTo,
            $state,
            $articleId
        );
        
        if ($stmt->execute()) {
            $stmt->close();

            // Rebuild the site URL (<slug>-<id>) from the CURRENT title on every save,
            // so retitling an article updates its URL too (William, 2026-10-01). The
            // trailing -<id> keeps it unique; note this changes the link for already-
            // published articles, so old URLs to this article will 404.
            $slug = substr(ten_article_slug($title) . '-' . $articleId, 0, 200);
            $su = $conn->prepare("UPDATE articles SET url = ? WHERE id = ?");
            $su->bind_param('si', $slug, $articleId); $su->execute(); $su->close();

            // Auto-populate the featured image (filename only) from the first body
            // image, but only if image_url is not already set.
            if (preg_match('#<img[^>]+src=[\'"]([^\'"]+)[\'"]#i', $articleText, $mm)) {
                $mainImage = basename($mm[1]);
                $u2 = $conn->prepare("UPDATE articles SET image_url = ? WHERE id = ? AND (image_url IS NULL OR image_url = '')");
                $u2->bind_param('si', $mainImage, $articleId);
                $u2->execute();
                $u2->close();
            }

            // Flag the article imageless (1) when its body has no <img>, else 0 —
            // this drives the site's imageless article sections.
            $imageless = preg_match('#<img[^>]+src=#i', $articleText) ? 0 : 1;
            $iu = $conn->prepare("UPDATE articles SET imageless = ? WHERE id = ?");
            $iu->bind_param('ii', $imageless, $articleId);
            $iu->execute();
            $iu->close();

            // Per-publication flags (frontpage / section headline / sponsored).
            if ($hasPubFlags) {
                ten_write_pub_flags($conn, $articleId, array_keys($pubFlags), $pubFlags);
            }

            $conn->close();

            echo json_encode([
                'status' => 'success',
                'message' => 'Article saved successfully',
                'article_id' => $articleId,
                'state' => $state
            ]);

            // On publish, rebuild each publication's cached front page. Do it
            // AFTER sending the response so the editor isn't kept waiting.
            if ($state === 'published') {
                if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                ten_regenerate_publication_caches($publications);
            }
        } else {
            throw new Exception('Failed to update article: ' . $stmt->error);
        }
    } else {
        // INSERT NEW (create). Inserts the same fields the editor modal edits, so a
        // created article is immediately fully editable through that same modal.
        // $createdBy was resolved from the author above (shared with the UPDATE path).
        $insert = "INSERT INTO articles
            (title, article_text, alias, tags, section, section_subcat, journalist_id, publications, canonical,
             meta_title, meta_description, meta_keywords, evergreen, featured, sponsored, frontpage_temp, note,
             publish_now, publish_from, publish_to, state, created_by, submission_date, modified_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, NOW(), NOW())";
        $stmt = $conn->prepare($insert);
        $stmt->bind_param('ssssssisssssiiiisissss',
            $title, $articleText, $alias, $tags, $section, $sectionSubcat, $author, $publications, $canonical,
            $metaTitle, $metaDescription, $metaKeywords, $evergreen, $featured, $sponsored, $headline, $note,
            $publishNow, $publishFrom, $publishTo, $state, $createdBy
        );
        if ($stmt->execute()) {
            $newId = $stmt->insert_id;
            $stmt->close();

            // Build the site URL (<slug>-<id>) now that we have the id — without
            // this the published article link resolves to the front page.
            $slug = substr(ten_article_slug($title) . '-' . $newId, 0, 200);
            $su = $conn->prepare("UPDATE articles SET url = ? WHERE id = ?");
            $su->bind_param('si', $slug, $newId); $su->execute(); $su->close();

            // Featured image (filename only) from the first body image, if any.
            if (preg_match('#<img[^>]+src=[\'"]([^\'"]+)[\'"]#i', $articleText, $mm)) {
                $mainImage = basename($mm[1]);
                $u2 = $conn->prepare("UPDATE articles SET image_url = ? WHERE id = ? AND (image_url IS NULL OR image_url = '')");
                $u2->bind_param('si', $mainImage, $newId); $u2->execute(); $u2->close();
            }
            // Imageless flag drives the site's imageless sections.
            $imageless = preg_match('#<img[^>]+src=#i', $articleText) ? 0 : 1;
            $iu = $conn->prepare("UPDATE articles SET imageless = ? WHERE id = ?");
            $iu->bind_param('ii', $imageless, $newId); $iu->execute(); $iu->close();

            // Per-publication flags (frontpage / section headline / sponsored).
            if ($hasPubFlags) {
                ten_write_pub_flags($conn, $newId, array_keys($pubFlags), $pubFlags);
            }

            $conn->close();

            echo json_encode([
                'status' => 'success',
                'message' => 'Article created successfully',
                'article_id' => $newId,
                'state' => $state
            ]);

            if ($state === 'published') {
                if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                ten_regenerate_publication_caches($publications);
            }
        } else {
            throw new Exception('Failed to create article: ' . $stmt->error);
        }
    }
    
} catch (Exception $e) {
    error_log("Error saving article: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Error: ' . $e->getMessage()]);
}
