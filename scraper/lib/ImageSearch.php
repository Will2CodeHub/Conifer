<?php
/**
 * Royalty-free image search across providers, returning a normalised list.
 * Openverse + Wikimedia Commons need no key; Pexels + Unsplash use config keys.
 * Each result: provider, thumb, url, title, attribution, license, source_page.
 */

if (!function_exists('getDBConnection')) {
    require_once __DIR__ . '/../../config.php';
}

function scraper_img_http(string $url, array $headers = []): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: TENNewsBot/1.0 (+https://theeyenewspapers.com/bot)'], $headers),
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code < 200 || $code >= 300) {
        throw new RuntimeException("image provider HTTP $code");
    }
    return $resp;
}

function scraper_img_openverse(string $q, int $n): array {
    $url = 'https://api.openverse.org/v1/images/?q=' . rawurlencode($q) . '&page_size=' . $n;
    $data = json_decode(scraper_img_http($url), true);
    $out = [];
    foreach (($data['results'] ?? []) as $r) {
        $attr = trim(($r['creator'] ?? '') . (isset($r['license']) ? ' (' . strtoupper($r['license']) . ' ' . ($r['license_version'] ?? '') . ')' : ''));
        $out[] = [
            'provider' => 'openverse',
            'thumb' => $r['thumbnail'] ?? $r['url'] ?? '',
            'url' => $r['url'] ?? '',
            'title' => $r['title'] ?? '',
            'attribution' => $attr !== '' ? $attr : 'Openverse',
            'license' => strtoupper(($r['license'] ?? '') . ' ' . ($r['license_version'] ?? '')),
            'source_page' => $r['foreign_landing_url'] ?? '',
        ];
    }
    return $out;
}

function scraper_img_wikimedia(string $q, int $n): array {
    $url = 'https://commons.wikimedia.org/w/api.php?action=query&format=json&generator=search&gsrnamespace=6&gsrlimit='
        . $n . '&gsrsearch=' . rawurlencode($q) . '&prop=imageinfo&iiprop=url|extmetadata&iiurlwidth=320';
    $data = json_decode(scraper_img_http($url), true);
    $out = [];
    foreach (($data['query']['pages'] ?? []) as $p) {
        $ii = $p['imageinfo'][0] ?? null;
        if (!$ii) continue;
        $meta = $ii['extmetadata'] ?? [];
        $artist = strip_tags($meta['Artist']['value'] ?? '');
        $license = $meta['LicenseShortName']['value'] ?? '';
        $out[] = [
            'provider' => 'wikimedia',
            'thumb' => $ii['thumburl'] ?? $ii['url'] ?? '',
            'url' => $ii['url'] ?? '',
            'title' => $p['title'] ?? '',
            'attribution' => trim($artist) !== '' ? trim($artist) : 'Wikimedia Commons',
            'license' => $license,
            'source_page' => $ii['descriptionurl'] ?? '',
        ];
    }
    return $out;
}

function scraper_img_pexels(string $q, int $n): array {
    if (!defined('PEXELS_API_KEY') || PEXELS_API_KEY === '') return [];
    $url = 'https://api.pexels.com/v1/search?query=' . rawurlencode($q) . '&per_page=' . $n;
    $data = json_decode(scraper_img_http($url, ['Authorization: ' . PEXELS_API_KEY]), true);
    $out = [];
    foreach (($data['photos'] ?? []) as $r) {
        $out[] = [
            'provider' => 'pexels',
            'thumb' => $r['src']['medium'] ?? '',
            'url' => $r['src']['large'] ?? $r['src']['original'] ?? '',
            'title' => $r['alt'] ?? '',
            'attribution' => 'Photo by ' . ($r['photographer'] ?? 'Pexels') . ' on Pexels',
            'license' => 'Pexels License',
            'source_page' => $r['url'] ?? '',
        ];
    }
    return $out;
}

function scraper_img_unsplash(string $q, int $n): array {
    if (!defined('UNSPLASH_API_KEY') || UNSPLASH_API_KEY === '') return [];
    $url = 'https://api.unsplash.com/search/photos?query=' . rawurlencode($q) . '&per_page=' . $n;
    $data = json_decode(scraper_img_http($url, ['Authorization: Client-ID ' . UNSPLASH_API_KEY]), true);
    $out = [];
    foreach (($data['results'] ?? []) as $r) {
        $out[] = [
            'provider' => 'unsplash',
            'thumb' => $r['urls']['small'] ?? '',
            'url' => $r['urls']['regular'] ?? $r['urls']['full'] ?? '',
            'title' => $r['alt_description'] ?? '',
            'attribution' => 'Photo by ' . ($r['user']['name'] ?? 'Unsplash') . ' on Unsplash',
            'license' => 'Unsplash License',
            'source_page' => $r['links']['html'] ?? '',
        ];
    }
    return $out;
}

function scraper_img_pixabay(string $q, int $n): array {
    if (!defined('PIXABAY_API_KEY') || PIXABAY_API_KEY === '') return [];
    $url = 'https://pixabay.com/api/?key=' . rawurlencode(PIXABAY_API_KEY) . '&q=' . rawurlencode($q)
        . '&per_page=' . max(3, $n) . '&image_type=photo&safesearch=true';
    $data = json_decode(scraper_img_http($url), true);
    $out = [];
    foreach (($data['hits'] ?? []) as $r) {
        $out[] = [
            'provider' => 'pixabay',
            'thumb' => $r['webformatURL'] ?? $r['previewURL'] ?? '',
            'url' => $r['largeImageURL'] ?? $r['webformatURL'] ?? '',
            'title' => $r['tags'] ?? '',
            'attribution' => 'Image by ' . ($r['user'] ?? 'Pixabay') . ' from Pixabay',
            'license' => 'Pixabay License',
            'source_page' => $r['pageURL'] ?? '',
        ];
    }
    return $out;
}

/** Registry of image providers: key => [label, search fn, always-on|key constant]. */
function scraper_image_provider_registry(): array {
    return [
        'openverse' => ['label' => 'Openverse',         'fn' => 'scraper_img_openverse', 'key' => null],
        'wikimedia' => ['label' => 'Wikimedia Commons', 'fn' => 'scraper_img_wikimedia', 'key' => null],
        'pexels'    => ['label' => 'Pexels',            'fn' => 'scraper_img_pexels',    'key' => 'PEXELS_API_KEY'],
        'unsplash'  => ['label' => 'Unsplash',          'fn' => 'scraper_img_unsplash',  'key' => 'UNSPLASH_API_KEY'],
        'pixabay'   => ['label' => 'Pixabay',           'fn' => 'scraper_img_pixabay',   'key' => 'PIXABAY_API_KEY'],
    ];
}

/** Providers usable right now: keyless ones always, keyed ones only if configured. */
function scraper_image_providers_available(): array {
    $out = [];
    foreach (scraper_image_provider_registry() as $k => $p) {
        $ok = $p['key'] === null || (defined($p['key']) && constant($p['key']) !== '');
        if ($ok) $out[$k] = $p['label'];
    }
    return $out;
}

/** Host allowlist (provider domains + their image CDNs) for safe server-side download. */
function scraper_image_host_allowed(string $url): bool {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') return false;
    $suffixes = [
        'openverse.org', 'wikimedia.org', 'wikipedia.org', 'wmcloud.org',
        'pexels.com', 'unsplash.com', 'pixabay.com', 'pixabaycdn.com', 'pixabay.org',
    ];
    foreach ($suffixes as $s) {
        if ($host === $s || substr($host, -(strlen($s) + 1)) === '.' . $s) return true;
    }
    return false;
}

/**
 * Combined search across the given providers (default: all available).
 * Never throws — provider failures are skipped.
 */
function scraper_image_search(string $query, int $perProvider = 4, ?array $providers = null): array {
    $query = trim($query);
    if ($query === '') return [];
    $reg = scraper_image_provider_registry();
    $avail = scraper_image_providers_available();
    $keys = $providers !== null
        ? array_values(array_intersect($providers, array_keys($avail)))
        : array_keys($avail);
    if (!$keys) return [];
    $out = [];
    foreach ($keys as $k) {
        try {
            $out = array_merge($out, ($reg[$k]['fn'])($query, $perProvider));
        } catch (Throwable $e) {
            // skip provider
        }
    }
    // keep only entries with a usable thumbnail + url
    return array_values(array_filter($out, function ($r) {
        return !empty($r['thumb']) && !empty($r['url']);
    }));
}

/** Build a concise image query from an article's keywords/title. */
function scraper_image_query_from_article(array $article): string {
    $kw = trim((string)($article['meta_keywords'] ?? ''));
    if ($kw !== '') {
        $parts = array_map('trim', explode(',', $kw));
        return implode(' ', array_slice($parts, 0, 3));
    }
    $title = (string)($article['title'] ?? '');
    return implode(' ', array_slice(preg_split('/\s+/', $title), 0, 6));
}
