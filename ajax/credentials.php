<?php
/**
 * Password Manager (credential vault) AJAX — ADMIN ONLY.
 *   action=list                     — all credentials (NO secrets), grouped client-side
 *   action=reveal   id=<id>         — decrypt + return one secret (logged)
 *   action=save     [id] label ...  — create/update (secret encrypted at rest)
 *   action=delete   id=<id>
 * Secrets are AES-256-GCM encrypted via lib/vault.php; login passwords are NOT here.
 */
ini_set('display_errors', '0');
require_once '../config.php';
require_once __DIR__ . '/../lib/vault.php';
requireLogin();
header('Content-Type: application/json');

if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'Administrators only']); exit; }

$conn = getDBConnection();
ten_credentials_ensure_schema($conn);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$me = (int) ($_SESSION['ten_user_id'] ?? 0);
$cats = ten_credential_categories();

try {
    switch ($action) {
        case 'list': {
            $res = $conn->query("SELECT id, category, label, host, username, url, notes,
                                        (secret_enc IS NOT NULL AND secret_enc <> '') AS has_secret, updated_at
                                 FROM ten_credentials ORDER BY category, label");
            $rows = [];
            while ($r = $res->fetch_assoc()) {
                $r['id'] = (int) $r['id'];
                $r['has_secret'] = (bool) $r['has_secret'];
                $rows[] = $r;
            }
            echo json_encode(['success' => true, 'items' => $rows, 'categories' => $cats]);
            break;
        }
        case 'reveal': {
            $id = (int) ($_POST['id'] ?? 0);
            $st = $conn->prepare("SELECT label, secret_enc FROM ten_credentials WHERE id = ?");
            $st->bind_param('i', $id); $st->execute();
            $row = $st->get_result()->fetch_assoc(); $st->close();
            if (!$row) { echo json_encode(['success' => false, 'message' => 'Not found']); break; }
            $secret = ten_vault_decrypt((string) $row['secret_enc']);
            if ($secret === null) { echo json_encode(['success' => false, 'message' => 'Could not decrypt (key missing or data tampered)']); break; }
            logActivity('credential_reveal', 'credential', $id, 'Revealed secret for "' . $row['label'] . '"');
            echo json_encode(['success' => true, 'secret' => $secret]);
            break;
        }
        case 'save': {
            $id       = (int) ($_POST['id'] ?? 0);
            $category = (string) ($_POST['category'] ?? 'email');
            if (!isset($cats[$category])) { $category = 'social'; }
            $label    = trim((string) ($_POST['label'] ?? ''));
            $host     = trim((string) ($_POST['host'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $url      = trim((string) ($_POST['url'] ?? ''));
            $notes    = trim((string) ($_POST['notes'] ?? ''));
            $secret   = (string) ($_POST['secret'] ?? '');
            $secretProvided = array_key_exists('secret', $_POST);
            if ($label === '') { echo json_encode(['success' => false, 'message' => 'A label is required']); break; }

            if ($id > 0) {
                // Update; only rewrite the secret when a new one was supplied (blank keeps existing).
                if ($secretProvided && $secret !== '') {
                    $enc = ten_vault_encrypt($secret);
                    $st = $conn->prepare("UPDATE ten_credentials SET category=?, label=?, host=?, username=?, url=?, notes=?, secret_enc=?, updated_by=? WHERE id=?");
                    $st->bind_param('sssssssii', $category, $label, $host, $username, $url, $notes, $enc, $me, $id);
                } else {
                    $st = $conn->prepare("UPDATE ten_credentials SET category=?, label=?, host=?, username=?, url=?, notes=?, updated_by=? WHERE id=?");
                    $st->bind_param('ssssssii', $category, $label, $host, $username, $url, $notes, $me, $id);
                }
                $st->execute(); $st->close();
                logActivity('credential_update', 'credential', $id, 'Updated "' . $label . '"');
                echo json_encode(['success' => true, 'id' => $id]);
            } else {
                $enc = ten_vault_encrypt($secret);
                $st = $conn->prepare("INSERT INTO ten_credentials (category, label, host, username, url, notes, secret_enc, created_by, updated_by)
                                      VALUES (?,?,?,?,?,?,?,?,?)");
                $st->bind_param('sssssssii', $category, $label, $host, $username, $url, $notes, $enc, $me, $me);
                $st->execute(); $newId = (int) $conn->insert_id; $st->close();
                logActivity('credential_create', 'credential', $newId, 'Added "' . $label . '"');
                echo json_encode(['success' => true, 'id' => $newId]);
            }
            break;
        }
        case 'delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $st = $conn->prepare("DELETE FROM ten_credentials WHERE id = ?");
            $st->bind_param('i', $id); $st->execute(); $st->close();
            logActivity('credential_delete', 'credential', $id, 'Deleted credential #' . $id);
            echo json_encode(['success' => true]);
            break;
        }
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }
} catch (Throwable $e) {
    error_log('credentials: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
$conn->close();
