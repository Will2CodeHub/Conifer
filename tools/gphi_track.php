<?php
/**
 * Standalone TEN visitor tracker for germanprivatehealthinsurance.com (GPHI).
 *
 * GPHI is a SEPARATE codebase with no TEN env vars, so the log path + site key are
 * hard-coded here. It logs one line per human page view, in the exact format the
 * stats collector (cron_collect_stats.php / LogAnalyzer.php) parses.
 *
 * Two ways to use it (pick one; see the install notes handed over):
 *   1. PHP site: set it as auto_prepend_file so it runs before every PHP request.
 *   2. Static/HTML site: reference it as a 1x1 beacon  <img src="/ten_track.php">
 *      on each page — when hit directly it logs the visit AND returns a tiny GIF.
 *
 * It never emits errors or breaks the page.
 */
date_default_timezone_set('Europe/Berlin');

$TEN_TRACK_LOG  = '/home/gphiuser/private/unique_visitors_count.txt';
$TEN_TRACK_SITE = 'gphi';

(function ($LOG, $SITE) {
    try {
        if (PHP_SAPI === 'cli') { return; }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isBeacon = (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__));

        $emitGif = function () use ($isBeacon) {
            if (!$isBeacon || headers_sent()) { return; }
            header('Content-Type: image/gif');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            // 1x1 transparent GIF
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        };

        if ($ua === '') { $emitGif(); return; }
        $l = strtolower($ua);
        $bots = ['googlebot','bingbot','slurp','duckduckbot','baiduspider','yandexbot',
                 'facebookexternalhit','twitterbot','linkedinbot','whatsapp','telegrambot',
                 'slackbot','applebot','amazonbot','semrushbot','ahrefsbot','mj12bot','dotbot',
                 'rogerbot','screaming frog','uptimerobot','pingdom','lighthouse'];
        foreach ($bots as $b) { if (strpos($l, $b) !== false) { $emitGif(); return; } }
        if (strpos($l,'mozilla')===false && strpos($l,'chrome')===false && strpos($l,'safari')===false
            && strpos($l,'firefox')===false && strpos($l,'edge')===false) {
            if (strpos($l,'bot')!==false || strpos($l,'crawl')!==false || strpos($l,'spider')!==false) { $emitGif(); return; }
        }

        $ip = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (strpos($ip, ',') !== false) { $ip = trim(explode(',', $ip)[0]); }
        $ref = $_SERVER['HTTP_REFERER'] ?? '-';
        foreach (['semalt.com','buttons-for-website','free-share-buttons','viagra','casino','poker','xxx','porn'] as $s) {
            if (stripos($ref, $s) !== false) { $emitGif(); return; }
        }
        // For a beacon the real page is the referrer; for auto-prepend it's this request.
        $uri = $isBeacon ? ($ref !== '-' ? $ref : '/') : ($_SERVER['REQUEST_URI'] ?? '/');

        $cookie = 'visitor_id_' . $SITE;
        if (!empty($_COOKIE[$cookie])) { $vid = $_COOKIE[$cookie]; }
        else { $vid = substr(hash('sha256', $ip . '|' . $ua), 0, 16); @setcookie($cookie, $vid, time() + 31536000, '/', '', true, true); }

        $line = sprintf("[%s] %s %s %s %s %s\n", date('d/M/Y:H:i:s O'), $vid, $ip, addslashes($ua), addslashes($ref), addslashes($uri));
        $fp = @fopen($LOG, 'a');
        if ($fp) { if (flock($fp, LOCK_EX)) { fwrite($fp, $line); flock($fp, LOCK_UN); } fclose($fp); }
        $emitGif();
    } catch (Throwable $e) { /* never break the page */ }
})($TEN_TRACK_LOG, $TEN_TRACK_SITE);
