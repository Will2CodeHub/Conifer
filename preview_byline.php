<?php
require_once 'config.php';
requireLogin();
if (!isAdmin() && !hasPermission('users.view')) {
    header('Location: dashboard.php?error=unauthorized');
    exit();
}

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, full_name, username, email, byline, bio, profile_image FROM ten_users WHERE id = ?");
$stmt->bind_param('i', $userId);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$u) { http_response_code(404); echo 'User not found'; exit(); }

$displayByline = trim($u['byline']) !== '' ? $u['byline'] : $u['full_name'];
$photo = trim($u['profile_image'] ?? '');
$photoUrl = $photo !== '' ? ((strpos($photo, '/') === 0 ? '' : '/') . ltrim($photo, '/')) : '';
$bio = trim($u['bio'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Byline preview — <?php echo htmlspecialchars($displayByline); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Merriweather:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', system-ui, sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#1f2937; }
        .note { max-width:760px; margin:0 auto 16px; background:#fef3c7; border:1px solid #fde68a; color:#92400e; padding:10px 14px; border-radius:8px; font-size:13px; }
        .paper { max-width:760px; margin:0 auto; background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.08); padding:40px; }
        .kicker { color:#b45309; font-weight:700; text-transform:uppercase; letter-spacing:.06em; font-size:12px; }
        h1 { font-family:'Merriweather', Georgia, serif; font-size:32px; line-height:1.2; margin:8px 0 16px; color:#111827; }
        .lede { font-family:'Merriweather', Georgia, serif; color:#374151; font-size:18px; line-height:1.7; }
        .body p { color:#374151; font-size:16px; line-height:1.8; margin:0 0 1em; }
        .author-box { display:flex; gap:16px; align-items:flex-start; margin-top:32px; padding-top:24px; border-top:2px solid #e5e7eb; }
        .author-photo { width:72px; height:72px; border-radius:50%; object-fit:cover; background:#e5e7eb; flex-shrink:0; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:28px; }
        .author-name { font-weight:700; color:#111827; font-size:16px; }
        .author-role { color:#6b7280; font-size:13px; margin-bottom:6px; }
        .author-bio { color:#4b5563; font-size:14px; line-height:1.6; }
        .byline-line { color:#6b7280; font-size:14px; margin:-6px 0 22px; }
        .missing { color:#b91c1c; font-style:italic; }
    </style>
</head>
<body>
    <div class="note"><strong>Preview only.</strong> This shows how <?php echo htmlspecialchars($u['full_name']); ?>'s byline, photo and bio appear on a published article. It is not a real article.</div>
    <div class="paper">
        <div class="kicker">Breaking News</div>
        <h1>Sample Article Headline for Byline Preview</h1>
        <div class="byline-line">By <strong><?php echo htmlspecialchars($displayByline); ?></strong> · Mon 7th Sep, 2026</div>
        <p class="lede">This is a sample lede paragraph. It exists only to show how this journalist's byline, photo and biography render on a live article page for the newspaper.</p>
        <div class="body">
            <p>The body of the article would continue here with the reporting. The byline above shows the name as readers will see it, and the author box below shows the photo and biography that appear at the foot of the piece.</p>
            <p>If the byline, photo or bio look wrong, edit them in User Management and reload this preview.</p>
        </div>
        <div class="author-box">
            <?php if ($photoUrl !== ''): ?>
                <img class="author-photo" src="<?php echo htmlspecialchars($photoUrl); ?>?v=<?php echo time(); ?>" alt="<?php echo htmlspecialchars($displayByline); ?>">
            <?php else: ?>
                <div class="author-photo"><i>👤</i></div>
            <?php endif; ?>
            <div>
                <div class="author-name"><?php echo htmlspecialchars($displayByline); ?></div>
                <div class="author-role"><?php echo htmlspecialchars($u['full_name']); ?> · <?php echo htmlspecialchars($u['email']); ?></div>
                <div class="author-bio">
                    <?php if ($bio !== ''): ?>
                        <?php echo nl2br(htmlspecialchars($bio)); ?>
                    <?php else: ?>
                        <span class="missing">No biography set for this user yet.</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
