<?php
/**
 * Front Page Cache — trigger a publication's front-page (index.html) regeneration.
 *
 * Each live site exposes /generate_index_page.php, which rebuilds its static index.html.
 * We call it server-side (rather than from the browser) so we get a real success/failure
 * status back instead of a cross-origin request whose response the browser cannot read.
 *
 * Only the live publications listed in the shared `publications` table may be regenerated
 * (a whitelist that both keeps this tool in sync with the real site list and blocks SSRF).
 */
require_once '../config.php';
require_once '../config_ten_admin.php';
requireLogin();
header('Content-Type: application/json');

$requestedUrl = trim($_POST['url'] ?? $_GET['url'] ?? '');
if ($requestedUrl === '') {
    echo json_encode(['success' => false, 'message' => 'No site specified']);
    exit;
}

// Build the whitelist of allowed hosts from the live publications.
$allowed = [];
try {
    $connAdmin = getDBConnection_TENAdmin();
    if ($connAdmin) {
        $res = $connAdmin->query("SELECT title, url FROM publications WHERE pub_live = '1'");
        while ($res && ($r = $res->fetch_assoc())) {
            $h = fpc_host($r['url']);
            if ($h === '' && !empty($r['title'])) {
                // Match the page's fallback: derive the host from the title when no url is stored.
                $h = strtolower(preg_replace('/[^a-z0-9]/i', '', $r['title'])) . '.com';
            }
            if ($h !== '') { $allowed[$h] = true; }
        }
        $connAdmin->close();
    }
} catch (Throwable $e) {
    error_log('regenerate_front_page: could not load publications: ' . $e->getMessage());
}

$host = fpc_host($requestedUrl);
if ($host === '' || empty($allowed[$host])) {
    echo json_encode(['success' => false, 'message' => 'Unknown or inactive site']);
    exit;
}

$target = 'https://' . $host . '/generate_index_page.php';
$start  = microtime(true);
list($code, $err) = fpc_fetch($target, $host);
$ms = (int) round((microtime(true) - $start) * 1000);

if ($err !== '') {
    $resp = ['success' => false, 'message' => $err, 'ms' => $ms, 'url' => $target];
} elseif ($code >= 200 && $code < 400) {
    $resp = ['success' => true, 'message' => 'Front page regenerated', 'http_code' => $code, 'ms' => $ms, 'url' => $target];
} else {
    $resp = ['success' => false, 'message' => 'Site returned HTTP ' . $code, 'http_code' => $code, 'ms' => $ms, 'url' => $target];
}

echo json_encode($resp);

/** Normalise any stored URL/domain into a bare lowercase host (no scheme, no www, no path). */
function fpc_host($url)
{
    $url = trim((string) $url);
    if ($url === '') { return ''; }
    if (!preg_match('~^https?://~i', $url)) { $url = 'https://' . $url; }
    $h = parse_url($url, PHP_URL_HOST);
    if (!$h) { return ''; }
    return strtolower(preg_replace('~^www\.~i', '', $h));
}

/** GET the target URL and return [http_code, error_string]. Prefers cURL, falls back to streams.
 *  When the target is THIS server (the management app runs on the network hub, which is also a
 *  live news site), connecting to its own public HTTPS hostname can hairpin and trigger a
 *  "tlsv1 unrecognized name" TLS alert. We detect that case and resolve the host to loopback so
 *  the request is served locally with the correct SNI/Host. */
function fpc_fetch($target, $host)
{
    if (function_exists('curl_init')) {
        // $pinIp: '' = normal DNS resolution. A value pins the host to that IP. It is used
        // ONLY as a fallback for the same-server "unrecognized name" TLS hairpin, so it can
        // never affect sites that already connect fine on the first, normal attempt.
        $run = function ($pinIp) use ($target, $host) {
            $ch = curl_init($target);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 90,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'TEN-Management-FrontPageCache/1.0',
            ];
            if ($pinIp !== '' && $host !== '') {
                $opts[CURLOPT_RESOLVE] = [$host . ':443:' . $pinIp];
            }
            curl_setopt_array($ch, $opts);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_errno($ch) ? curl_error($ch) : '';
            curl_close($ch);
            return [$code, $err];
        };

        // Normal request first — this is what works for every site over the network.
        list($code, $err) = $run('');
        // Only when the server hit the hairpin TLS alert reaching one of its OWN hostnames,
        // retry once pinned to this server's own IP (bypasses the NAT hairpin). External
        // sites succeed on the first attempt and never reach this.
        if ($err !== '' && stripos($err, 'unrecognized name') !== false) {
            $ip = $_SERVER['SERVER_ADDR'] ?? '';
            if ($ip !== '') {
                list($code2, $err2) = $run($ip);
                if ($err2 === '') { return [$code2, '']; }
            }
        }
        return [$code, $err];
    }

    $ctx = stream_context_create([
        'http' => ['timeout' => 90, 'ignore_errors' => true, 'user_agent' => 'TEN-Management-FrontPageCache/1.0'],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($target, false, $ctx);
    $code = 0;
    if (!empty($http_response_header) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) {
        $code = (int) $m[1];
    }
    if ($body === false && $code === 0) {
        return [0, 'Could not reach site'];
    }
    return [$code ?: 200, ''];
}
