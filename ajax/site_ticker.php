<?php
/**
 * TEN Site Ticker — manage admin_ten.news_ticker (the "Latest" ticker under a
 * publication's title). The live sites read the newest 6 items by date for
 * their edition (backend/get_ticker_data.php). We only INSERT/UPDATE/DELETE
 * rows here; the schema is unchanged.
 *
 * Columns: breaking_news varchar(500) (text, may contain an <a> link),
 *          date datetime (ordering: newest 6 shown live), edition ENUM.
 *
 * All actions require ticker.manage (admins bypass).
 */
require_once '../config.php';
require_once '../config_ten_admin.php';
requireLogin();

header('Content-Type: application/json');
$response = ['success' => false, 'message' => ''];

if (!isAdmin() && !hasPermission('ticker.manage')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized — requires ticker.manage']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$conn = getDBConnection_TENAdmin();
if (!$conn) { echo json_encode(['success' => false, 'message' => 'DB connection failed']); exit(); }

/** pub_live publications keyed by acronym. */
function ticker_publications($conn): array {
    $out = [];
    $res = $conn->query("SELECT publication, title FROM publications WHERE pub_live = '1' ORDER BY title");
    while ($res && ($r = $res->fetch_assoc())) { $out[$r['publication']] = $r['title']; }
    return $out;
}

/** Normalise a datetime-local value to MySQL datetime; blank => now. */
function ticker_datetime(string $v): string {
    $v = trim($v);
    if ($v === '') return date('Y-m-d H:i:s');
    $ts = strtotime(str_replace('T', ' ', $v));
    return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

/** Datetime-local value to MySQL datetime, or NULL when blank/unparseable.
 *  Used for the optional publish-from / publish-to window. */
function ticker_datetime_or_null($v): ?string {
    $v = trim((string)$v);
    if ($v === '') return null;
    $ts = strtotime(str_replace('T', ' ', $v));
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

try {
    $pubs = ticker_publications($conn);

    switch ($action) {
        case 'list': {
            $edition = $_POST['edition'] ?? $_GET['edition'] ?? '';
            if (!isset($pubs[$edition])) throw new Exception('Unknown publication: ' . $edition);
            $stmt = $conn->prepare("SELECT id, breaking_news, date, publish_from, publish_to FROM news_ticker WHERE edition = ? ORDER BY date DESC, id DESC");
            $stmt->bind_param('s', $edition);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            $now = time();
            $liveShown = 0;   // eligible + within the newest-6 the site displays
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $from = $row['publish_from'] ? strtotime($row['publish_from']) : null;
                $to   = $row['publish_to'] ? strtotime($row['publish_to']) : null;
                // status: expired > scheduled > live (eligible & in top 6) > off
                if ($to !== null && $to < $now) {
                    $row['status'] = 'expired';
                } elseif ($from !== null && $from > $now) {
                    $row['status'] = 'scheduled';
                } elseif ($liveShown < 6) {
                    $row['status'] = 'live';
                    $liveShown++;
                } else {
                    $row['status'] = 'off';   // eligible but pushed off by newer items
                }
                $row['live'] = ($row['status'] === 'live');
                $rows[] = $row;
            }
            $stmt->close();
            $response = ['success' => true, 'edition' => $edition, 'rows' => $rows];
            break;
        }

        case 'create': {
            $edition = $_POST['edition'] ?? '';
            $text    = trim($_POST['breaking_news'] ?? '');
            $date    = ticker_datetime($_POST['date'] ?? '');
            $pfrom   = ticker_datetime_or_null($_POST['publish_from'] ?? '');
            $pto     = ticker_datetime_or_null($_POST['publish_to'] ?? '');
            if (!isset($pubs[$edition])) throw new Exception('Choose a valid publication');
            if ($text === '') throw new Exception('Ticker text is required');
            if (mb_strlen($text) > 500) throw new Exception('Ticker text must be 500 characters or fewer');
            if ($pfrom !== null && $pto !== null && strtotime($pto) < strtotime($pfrom)) throw new Exception('"Show until" must be after "Show from"');
            $stmt = $conn->prepare("INSERT INTO news_ticker (breaking_news, date, publish_from, publish_to, edition) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('sssss', $text, $date, $pfrom, $pto, $edition);
            $stmt->execute();
            $id = (int)$conn->insert_id;
            $stmt->close();
            $response = ['success' => true, 'id' => $id];
            break;
        }

        case 'update': {
            $id   = (int)($_POST['id'] ?? 0);
            $text = trim($_POST['breaking_news'] ?? '');
            $date = ticker_datetime($_POST['date'] ?? '');
            $pfrom = ticker_datetime_or_null($_POST['publish_from'] ?? '');
            $pto   = ticker_datetime_or_null($_POST['publish_to'] ?? '');
            if ($id <= 0) throw new Exception('Invalid id');
            if ($text === '') throw new Exception('Ticker text is required');
            if (mb_strlen($text) > 500) throw new Exception('Ticker text must be 500 characters or fewer');
            if ($pfrom !== null && $pto !== null && strtotime($pto) < strtotime($pfrom)) throw new Exception('"Show until" must be after "Show from"');
            $stmt = $conn->prepare("UPDATE news_ticker SET breaking_news = ?, date = ?, publish_from = ?, publish_to = ? WHERE id = ?");
            $stmt->bind_param('ssssi', $text, $date, $pfrom, $pto, $id);
            $stmt->execute();
            $stmt->close();
            $response = ['success' => true, 'id' => $id];
            break;
        }

        case 'delete': {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new Exception('Invalid id');
            $stmt = $conn->prepare("DELETE FROM news_ticker WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            $response = ['success' => true, 'id' => $id];
            break;
        }

        default:
            throw new Exception('Unknown action: ' . $action);
    }
} catch (Throwable $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

$conn->close();
echo json_encode($response);
