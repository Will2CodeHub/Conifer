<?php
require_once 'config.php';
require_once 'config_ten_admin.php';
requireLogin();

// Load the live publications — the canonical site list the rest of the app uses
// (same source as the Site Menus tool). Each live site exposes /generate_index_page.php
// which rebuilds its static index.html (the cached front page).
$sites = [];
try {
    $connAdmin = getDBConnection_TENAdmin();
    if ($connAdmin) {
        $res = $connAdmin->query("SELECT publication, title, url FROM publications WHERE pub_live = '1' ORDER BY title");
        while ($res && ($r = $res->fetch_assoc())) {
            $r['host'] = fpc_site_host($r);
            $sites[] = $r;
        }
        $connAdmin->close();
    }
} catch (Throwable $e) {
    error_log('module-front-page-cache: failed loading publications: ' . $e->getMessage());
}

/** Resolve a publication row to its front-page host: prefer the stored url, else derive
 *  it from the title (every Eye title maps to "<title without spaces>.com"). */
function fpc_site_host($row)
{
    $host = fpc_host($row['url'] ?? '');
    if ($host === '' && !empty($row['title'])) {
        $host = strtolower(preg_replace('/[^a-z0-9]/i', '', $row['title'])) . '.com';
    }
    return $host;
}

/** Normalise a stored URL/domain into a bare lowercase host. */
function fpc_host($url)
{
    $url = trim((string) $url);
    if ($url === '') { return ''; }
    if (!preg_match('~^https?://~i', $url)) { $url = 'https://' . $url; }
    $h = parse_url($url, PHP_URL_HOST);
    if (!$h) { return ''; }
    return strtolower(preg_replace('~^www\.~i', '', $h));
}

$currentPage = 'front_page_cache';
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Front Page Cache - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .page-header { margin-bottom: 20px; }
        .page-header h1 { font-size: 30px; font-weight: 700; color: #111827; margin-bottom: 6px; }
        .page-header p { color: #6b7280; font-size: 14px; max-width: 820px; line-height: 1.5; }
        .fpc-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; margin-bottom: 22px; }
        .btn-primary { background: #4f46e5; color: #fff; border: none; padding: 11px 18px; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary:hover { background: #4338ca; }
        .btn-primary:disabled { opacity: .6; cursor: not-allowed; }
        .fpc-note { color: #6b7280; font-size: 13px; }
        .fpc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }
        .fpc-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 18px; display: flex; flex-direction: column; gap: 12px; }
        .fpc-card h3 { font-size: 16px; font-weight: 700; color: #111827; margin: 0; display: flex; align-items: center; gap: 8px; }
        .fpc-host { color: #6b7280; font-size: 12.5px; word-break: break-all; }
        .fpc-host a { color: #4f46e5; text-decoration: none; }
        .fpc-host a:hover { text-decoration: underline; }
        .fpc-status { font-size: 12.5px; min-height: 18px; display: flex; align-items: center; gap: 6px; color: #9ca3af; }
        .fpc-status.ok { color: #059669; }
        .fpc-status.err { color: #dc2626; }
        .fpc-status.busy { color: #4f46e5; }
        .fpc-actions { display: flex; gap: 8px; margin-top: auto; align-items: center; }
        .btn-regen { background: #eef2ff; color: #4338ca; border: 1px solid #c7d2fe; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; }
        .btn-regen:hover { background: #e0e7ff; }
        .btn-regen:disabled { opacity: .55; cursor: not-allowed; }
        .fpc-open { color: #6b7280; font-size: 12.5px; text-decoration: none; margin-left: auto; }
        .fpc-open:hover { color: #111827; }
        .fpc-empty { padding: 40px; text-align: center; color: #9ca3af; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; }
        .spin { animation: fpcspin 0.8s linear infinite; }
        @keyframes fpcspin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <?php include 'includes/header.php'; ?>

        <div class="content-area">
            <div class="page-header">
                <h1><i class="fas fa-arrows-rotate"></i> Front Page Cache</h1>
                <p>Each live site serves a static <code>index.html</code> for its front page. When you publish or change articles, regenerate a site's front page here to refresh that cache. Regenerating calls the site's <code>generate_index_page.php</code> and reports whether it succeeded.</p>
            </div>

            <div class="fpc-toolbar">
                <button class="btn-primary" id="regenAll"><i class="fas fa-arrows-rotate"></i> Regenerate all front pages</button>
                <span class="fpc-note" id="regenAllNote"><?php echo count($sites); ?> live site<?php echo count($sites) === 1 ? '' : 's'; ?></span>
            </div>

            <?php if (empty($sites)): ?>
                <div class="fpc-empty">No live publications found. Check the <code>publications</code> table (sites with <code>pub_live = 1</code>).</div>
            <?php else: ?>
                <div class="fpc-grid">
                    <?php foreach ($sites as $s): ?>
                        <?php $host = $s['host']; $gen = $host ? ('https://' . $host . '/generate_index_page.php') : ''; ?>
                        <div class="fpc-card" data-host="<?php echo htmlspecialchars($host); ?>">
                            <h3><i class="fas fa-newspaper" style="color:#4f46e5"></i> <?php echo htmlspecialchars($s['title'] ?: $s['publication']); ?></h3>
                            <div class="fpc-host">
                                <?php if ($host): ?>
                                    <a href="https://<?php echo htmlspecialchars($host); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($host); ?></a>
                                <?php else: ?>
                                    <span style="color:#dc2626;">No URL set for this publication</span>
                                <?php endif; ?>
                            </div>
                            <div class="fpc-status" data-status>Ready</div>
                            <div class="fpc-actions">
                                <button class="btn-regen" <?php echo $host ? '' : 'disabled'; ?>>
                                    <i class="fas fa-arrows-rotate"></i> Regenerate
                                </button>
                                <?php if ($gen): ?>
                                    <a class="fpc-open" href="<?php echo htmlspecialchars($gen); ?>" target="_blank" rel="noopener" title="Open generate_index_page.php directly">Direct link ↗</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    (function () {
        function setStatus(card, cls, html) {
            var el = card.querySelector('[data-status]');
            el.className = 'fpc-status ' + cls;
            el.innerHTML = html;
        }

        function regenerate(card) {
            var host = card.getAttribute('data-host');
            if (!host) return Promise.resolve(false);
            var btn = card.querySelector('.btn-regen');
            if (btn) btn.disabled = true;
            setStatus(card, 'busy', '<i class="fas fa-arrows-rotate spin"></i> Regenerating…');

            return fetch('ajax/regenerate_front_page.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'url=' + encodeURIComponent(host)
            })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.success) {
                    var secs = d.ms ? ' · ' + (d.ms / 1000).toFixed(1) + 's' : '';
                    setStatus(card, 'ok', '<i class="fas fa-circle-check"></i> Regenerated' + secs);
                    return true;
                }
                setStatus(card, 'err', '<i class="fas fa-triangle-exclamation"></i> ' + ((d && d.message) || 'Failed'));
                return false;
            })
            .catch(function () {
                setStatus(card, 'err', '<i class="fas fa-triangle-exclamation"></i> Request failed');
                return false;
            })
            .finally(function () { if (btn) btn.disabled = false; });
        }

        document.querySelectorAll('.fpc-card .btn-regen').forEach(function (btn) {
            btn.addEventListener('click', function () { regenerate(btn.closest('.fpc-card')); });
        });

        var allBtn = document.getElementById('regenAll');
        if (allBtn) {
            allBtn.addEventListener('click', async function () {
                var cards = Array.prototype.slice.call(document.querySelectorAll('.fpc-card[data-host]'))
                    .filter(function (c) { return c.getAttribute('data-host'); });
                if (!cards.length) return;
                allBtn.disabled = true;
                var original = allBtn.innerHTML;
                var ok = 0, fail = 0;
                for (var i = 0; i < cards.length; i++) {
                    allBtn.innerHTML = '<i class="fas fa-arrows-rotate spin"></i> Regenerating ' + (i + 1) + ' / ' + cards.length + '…';
                    var res = await regenerate(cards[i]);
                    res ? ok++ : fail++;
                }
                allBtn.disabled = false;
                allBtn.innerHTML = original;
                Swal.fire({
                    icon: fail ? (ok ? 'warning' : 'error') : 'success',
                    title: 'Done',
                    text: ok + ' regenerated' + (fail ? ', ' + fail + ' failed' : ''),
                    timer: fail ? undefined : 2200,
                    showConfirmButton: !!fail
                });
            });
        }
    })();
    </script>
</body>
</html>
