<?php
/**
 * ScraperAI — provider abstraction for Claude (Anthropic) + ChatGPT (OpenAI),
 * via raw HTTPS (cURL). Two operations:
 *   - scraper_ai_translate(): translate item titles/summaries into a target language
 *   - scraper_ai_write_article(): write an original article from facts (JSON out)
 *
 * Keys come from config.php: ANTHROPIC_API_KEY, OPENAI_API_KEY.
 */

if (!defined('ANTHROPIC_API_KEY')) {
    require_once __DIR__ . '/../../config.php';
}

/**
 * Low-level call. Returns the model's text output (string).
 * Throws RuntimeException on transport/API errors.
 */
function scraper_ai_raw(string $provider, string $model, string $systemPrompt, string $userContent, int $maxTokens = 4096, bool $jsonMode = false): string {
    if ($provider === 'openai') {
        return scraper_ai_openai($model, $systemPrompt, $userContent, $maxTokens, $jsonMode);
    }
    return scraper_ai_anthropic($model, $systemPrompt, $userContent, $maxTokens);
}

function scraper_ai_anthropic(string $model, string $systemPrompt, string $userContent, int $maxTokens): string {
    $key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if ($key === '') {
        throw new RuntimeException('Anthropic API key not configured');
    }
    $payload = [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => $userContent],
        ],
    ];
    $resp = scraper_ai_http(
        'https://api.anthropic.com/v1/messages',
        [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        $payload
    );
    $data = json_decode($resp, true);
    if (!is_array($data) || !isset($data['content'])) {
        $err = $data['error']['message'] ?? substr($resp, 0, 500);
        throw new RuntimeException('Anthropic API error: ' . $err);
    }
    $text = '';
    foreach ($data['content'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    return $text;
}

function scraper_ai_openai(string $model, string $systemPrompt, string $userContent, int $maxTokens, bool $jsonMode): string {
    $key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    if ($key === '') {
        throw new RuntimeException('OpenAI API key not configured');
    }
    $payload = [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userContent],
        ],
    ];
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }
    $resp = scraper_ai_http(
        'https://api.openai.com/v1/chat/completions',
        [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        $payload
    );
    $data = json_decode($resp, true);
    if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
        $err = $data['error']['message'] ?? substr($resp, 0, 500);
        throw new RuntimeException('OpenAI API error: ' . $err);
    }
    return $data['choices'][0]['message']['content'];
}

function scraper_ai_http(string $url, array $headers, array $payload): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 120,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        throw new RuntimeException('HTTP transport error: ' . $cerr);
    }
    if ($code < 200 || $code >= 300) {
        $data = json_decode($resp, true);
        $msg = $data['error']['message'] ?? ('HTTP ' . $code . ': ' . substr($resp, 0, 400));
        throw new RuntimeException($msg);
    }
    return $resp;
}

/** Strip ```json ... ``` fences and decode. Returns array or null. */
function scraper_ai_decode_json(string $text) {
    $t = trim($text);
    if (strpos($t, '```') !== false) {
        $t = preg_replace('/^```[a-zA-Z]*\s*/', '', $t);
        $t = preg_replace('/\s*```$/', '', $t);
        $t = trim($t);
    }
    $decoded = json_decode($t, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Translate a list of items into $targetLanguage.
 * @param array $items list of ['id'=>int,'title'=>string,'summary'=>string]
 * @return array map of id => ['title'=>..., 'summary'=>...]
 */
function scraper_ai_translate(string $provider, string $model, string $targetLanguage, array $items): array {
    if (empty($items)) {
        return [];
    }
    $lang = $targetLanguage !== '' ? $targetLanguage : 'English';
    $system = "You are a professional news translator. Translate each item's title and summary into {$lang}. "
        . "Preserve meaning and proper nouns; do not editorialise or add content. "
        . "Return ONLY a JSON object with key \"items\": an array in the SAME order, each element "
        . "{\"id\": <same id>, \"title\": \"...\", \"summary\": \"...\"}. No commentary.";

    $out = [];
    // Translate in small batches so each response fits well within the token limit.
    foreach (array_chunk($items, 20) as $chunk) {
        $input = [];
        foreach ($chunk as $it) {
            $input[] = ['id' => (int)$it['id'], 'title' => (string)$it['title'], 'summary' => (string)$it['summary']];
        }
        $user = json_encode(['items' => $input], JSON_UNESCAPED_UNICODE);
        $text = scraper_ai_raw($provider, $model, $system, $user, 8000, true);
        $decoded = scraper_ai_decode_json($text);
        if (!$decoded || !isset($decoded['items']) || !is_array($decoded['items'])) {
            throw new RuntimeException('Translator returned unexpected output: ' . substr($text, 0, 200));
        }
        foreach ($decoded['items'] as $row) {
            if (isset($row['id'])) {
                $out[(int)$row['id']] = [
                    'title' => (string)($row['title'] ?? ''),
                    'summary' => (string)($row['summary'] ?? ''),
                ];
            }
        }
    }
    return $out;
}

/**
 * Write an original article from facts using the section prompt template.
 * @param array $vars placeholders: source_title, source_summary, source_facts,
 *                    source_url, section, publication, target_language
 * @return array ['title','body_html','meta_title','meta_description','meta_keywords']
 */
function scraper_ai_write_article(string $provider, string $model, string $promptTemplate, array $vars): array {
    $prompt = $promptTemplate;
    foreach ($vars as $k => $v) {
        $prompt = str_replace('{' . $k . '}', (string)$v, $prompt);
    }
    $lang = (string)($vars['target_language'] ?? 'English');
    // The template both instructs the task and asks for JSON output.
    $system = "You are an experienced staff news journalist. Write the ENTIRE article (title, body_html and all meta "
        . "fields) in {$lang}, regardless of the source language. Write a factual news report of AT LEAST 700 words in "
        . "flowing paragraphs — no opinion, analysis or editorialising, and avoid libel (report any allegation only as "
        . "an attributed claim, never as established fact). Do NOT litter the piece with subheadings; over-use of "
        . "headings reads as machine-generated. Use a subheading only if genuinely warranted, and even then rarely — "
        . "most articles need none. NEVER begin the article with a heading: it MUST open with a body paragraph. Follow "
        . "the instructions exactly and return ONLY the requested JSON object — no markdown fences, no commentary.";
    $text = scraper_ai_raw($provider, $model, $system, $prompt, 8000, $provider === 'openai');
    $decoded = scraper_ai_decode_json($text);
    if (!$decoded) {
        throw new RuntimeException('AI did not return valid JSON article. Raw start: ' . substr($text, 0, 200));
    }
    $body = (string)($decoded['body_html'] ?? $decoded['body'] ?? '');
    // Enforce "never start with a heading": the featured image is inserted above
    // the body, so the first block must be real text. Demote a leading h1/h2/h3
    // to a paragraph if the model slipped.
    $body = preg_replace('/^\s*<h[1-3][^>]*>(.*?)<\/h[1-3]>/is', '<p>$1</p>', $body, 1);
    // Any remaining (sparse) <h2> subheadings: the site styles h2 near h1 size,
    // so give them an explicit smaller size inline (scoped to scraped articles).
    $body = preg_replace('/<h2(\s[^>]*)?>/i', '<h2 style="font-size:1.4rem;line-height:1.3;margin:1.1em 0 .45em;font-weight:700;">', $body);
    // Space paragraphs (a blank line between them) wherever the article renders —
    // the editor and each publication's article page. Only add to bare <p>.
    $body = preg_replace('/<p(?![^>]*style=)(\s[^>]*)?>/i', '<p style="margin:0 0 1em;"$1>', $body);
    // News articles from facts carry NO inline hyperlinks; the source is credited
    // on its own line at the foot of the article, not woven into the prose. Unwrap
    // any links the model added (keep the visible text).
    $body = preg_replace('#<a\b[^>]*>(.*?)</a>#is', '$1', $body);
    // Append the source reference as its own line at the very bottom.
    $srcUrl = trim((string)($vars['source_url'] ?? ''));
    if ($srcUrl !== '') {
        $host = parse_url($srcUrl, PHP_URL_HOST);
        $host = $host ? preg_replace('#^www\.#i', '', $host) : 'original report';
        $body .= '<p class="article-source" style="margin:1.6em 0 0;font-size:0.9em;color:#555;">Source: <a href="'
            . htmlspecialchars($srcUrl, ENT_QUOTES) . '" target="_blank" rel="nofollow noopener">'
            . htmlspecialchars($host, ENT_QUOTES) . '</a></p>';
    }
    return [
        'title' => (string)($decoded['title'] ?? ''),
        'body_html' => $body,
        'meta_title' => (string)($decoded['meta_title'] ?? ''),
        'meta_description' => (string)($decoded['meta_description'] ?? ''),
        'meta_keywords' => (string)($decoded['meta_keywords'] ?? ''),
    ];
}
