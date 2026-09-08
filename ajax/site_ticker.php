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

try {
    $pubs = ticker_publications($conn);

    switch ($action) {
        case 'list': {
            $edition = $_POST['edition'] ?? $_GET['edition'] ?? '';
            if (!isset($pubs[$edition])) throw new Exception('Unknown publication: ' . $edition);
            $stmt = $conn->prepare("SELECT id, breaking_news, date FROM news_ticker WHERE edition = ? ORDER BY date DESC, id DESC");
            $stmt->bind_param('s', $edition);
            $stmt->execute();
            $res = $stmt->get_result();
            $rows = [];
            $i = 0;
            while ($row = $res->fetch_assoc()) {
                $row['id'] = (int)$row['id'];
                $row['live'] = ($i < 6);   // newest 6 are what the site shows
                $rows[] = $row;
                $i++;
            }
            $stmt->close();
            $response = ['success' => true, 'edition' => $edition, 'rows' => $rows];
            break;
        }

        case 'create': {
            $edition = $_POST['edition'] ?? '';
            $text    = trim($_POST['breaking_news'] ?? '');
            $date    = ticker_datetime($_POST['date'] ?? '');
            if (!isset($pubs[$edition])) throw new Exception('Choose a valid publication');
            if ($text === '') throw new Exception('Ticker text is required');
            if (mb_strlen($text) > 500) throw new Exception('Ticker text must be 500 characters or fewer');
            $stmt = $conn->prepare("INSERT INTO news_ticker (breaking_news, date, edition) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $text, $date, $edition);
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
            if ($id <= 0) throw new Exception('Invalid id');
            if ($text === '') throw new Exception('Ticker text is required');
            if (mb_strlen($text) > 500) throw new Exception('Ticker text must be 500 characters or fewer');
            $stmt = $conn->prepare("UPDATE news_ticker SET breaking_news = ?, date = ? WHERE id = ?");
            $stmt->bind_param('ssi', $text, $date, $id);
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
