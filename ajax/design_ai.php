<?php
/**
 * TEN Design System — AI edit endpoint (Claude API).
 * POST actions:
 *   restyle       — input: current theme tokens + instruction.
 *                   output: {theme:{token:value,…}, custom_css?:string}.
 *   edit_element  — input: block_type + current template + instruction.
 *                   output: {template:string} (ALL {{…}} tokens preserved).
 * Admin only; design must be enabled for the publication. The endpoint does NOT
 * write the DB — it returns validated results the editor applies to the draft
 * via the existing save path. The API key never leaves the server.
 */
ini_set('display_errors', '0');
require_once '../config.php';
require_once __DIR__ . '/../config_ten_admin.php';
requireLogin();
header('Content-Type: application/json');

function dai_fail(string $msg) { echo json_encode(['success' => false, 'message' => $msg]); exit; }

if (!isAdmin()) { dai_fail('Admin permission required'); }

$pub = trim((string)($_POST['pub'] ?? ''));
if ($pub === '') { dai_fail('Missing publication'); }
// Confirm design is enabled for this pub (same guard as design_layout.php).
$conn = getDBConnection_TENAdmin();
if (!$conn) { dai_fail('Database unavailable'); }
$st = $conn->prepare("SELECT design_enabled FROM publications WHERE publication=? LIMIT 1");
$st->bind_param('s', $pub); $st->execute();
$enabled = (int)($st->get_result()->fetch_assoc()['design_enabled'] ?? 0) === 1;
$st->close(); $conn->close();
if (!$enabled) { dai_fail('Design not enabled for this publication'); }

$action      = (string)($_POST['action'] ?? '');
$instruction = trim((string)($_POST['instruction'] ?? ''));
if ($instruction === '') { dai_fail('Please describe the change you want.'); }
if (mb_strlen($instruction) > 2000) { $instruction = mb_substr($instruction, 0, 2000); }

// The fixed design-system brief (William's typography prompt). Prepended to
// every request so the AI always works to the house editorial system.
$DESIGN_BRIEF = <<<'TXT'
Act as a Lead UI/UX Designer and Typography Expert for elite, high-authority digital publications. You maintain an elegant, prestigious typography and spacing system built on a strict 3-font rule:
- Headlines & key titles: Lora (serif)
- Article narrative body: Merriweather (serif) — reserved only for main article paragraphs
- UI, metadata, category tags & captions: Inter (sans-serif)
Principles: a clear typographic scale with deliberate sizes, weights (Regular/Medium/SemiBold/Bold), line-heights and letter-spacing; an optimal reading measure for body text; generous whitespace and vertical rhythm; and a STRICT zero-border-radius rule across all containers and images. Keep the result tasteful, restrained and editorial.
TXT;

// Known theme token names (mirror dl_default_theme()); the only keys restyle may set.
$TOKENS = [
    'font_display','font_body','font_ui',
    'color_ink','color_body','color_muted','color_accent','color_bg','color_button',
    'h1_size','h1_weight','h1_line','h1_spacing','h2_size','h2_weight','h2_line',
    'h3_size','h3_weight','h3_line','body_size','body_line','body_measure',
    'ui_size','ui_weight','ui_spacing','caption_size',
    'radius','container_max','gutter','section_gap','block_gap',
];

/** Sanitise a theme token value: plain CSS values only, no rule-breaking chars. */
function dai_clean_value($v): string {
    $v = trim((string)$v);
    if ($v === '') { return ''; }
    // Block anything that could break out of a declaration or inject.
    if (preg_match('/[{}<>;]|url\s*\(|@import|expression\s*\(|javascript:/i', $v)) { return ''; }
    return mb_substr($v, 0, 200);
}

/** Pull all {{…}} / {{{…}}} / {{#each}} / {{/if}} tokens as a sorted multiset. */
function dai_tokens(string $html): array {
    preg_match_all('/\{\{\{[^{}]*\}\}\}|\{\{[^{}]*\}\}/', $html, $m);
    $t = $m[0];
    sort($t);
    return $t;
}

/** Strip a ```lang … ``` fence if the model wrapped its answer in one. */
function dai_unfence(string $s): string {
    $s = trim($s);
    if (strpos($s, '```') === 0) {
        $s = preg_replace('/^```[a-zA-Z]*\s*/', '', $s);
        $s = preg_replace('/\s*```$/', '', $s);
    }
    return trim($s);
}

// ---- build the request ----
if ($action === 'restyle') {
    $currentTheme = json_decode((string)($_POST['current_theme'] ?? '{}'), true);
    if (!is_array($currentTheme)) { $currentTheme = []; }
    $system = $DESIGN_BRIEF . "\n\n" .
        "TASK: Update the design's theme tokens to satisfy the editor's request, staying within the system above.\n" .
        "Output ONLY a JSON object. Allowed keys (set any subset): " . implode(', ', $TOKENS) . ".\n" .
        "Values are plain CSS values only — e.g. a font stack, a hex colour, a weight like 700, a size like \"1.15rem\" or \"clamp(2.4rem,4vw,3.4rem)\". " .
        "radius must stay \"0\" unless explicitly asked otherwise. " .
        "Never include selectors, braces, semicolons, url(), @import or scripts. " .
        "You MAY add a \"custom_css\" string for small extra rules targeting existing .dl-* classes. " .
        "Return only the JSON object, no markdown, no commentary.";
    $userMsg = "Current theme tokens:\n" . json_encode($currentTheme, JSON_PRETTY_PRINT) .
               "\n\nChange request: " . $instruction;
} elseif ($action === 'edit_element') {
    $blockType = preg_replace('/[^a-z_]/', '', (string)($_POST['block_type'] ?? ''));
    $template  = (string)($_POST['template'] ?? '');
    if ($template === '') { dai_fail('No block template supplied.'); }
    if (mb_strlen($template) > 20000) { dai_fail('Block template too large.'); }
    $system = $DESIGN_BRIEF . "\n\n" .
        "TASK: Change ONE block to satisfy the editor's request, keeping the house system above.\n" .
        "PREFER CSS for any visual/styling change — sizing, font weight, spacing, colour, alignment, keeping text on one line or letting it wrap, borders, showing/hiding. Rewrite the HTML template only when the content or structure itself must change.\n" .
        "Return ONLY a JSON object with these optional string fields: {\"css\": \"\", \"template\": \"\"}. Include just what you need and leave the other empty.\n" .
        "CSS: target the block's own elements using the classes you can see in the template (e.g. .dl-wordmark, .dl-nav-list, .dl-subscribe, .dl-article-title). Your CSS is scoped to THIS block automatically, so write bare selectors — do NOT prefix them with body or a page selector. No <style> tags, no @import, no url(), no scripts. @media queries are allowed.\n" .
        "Template: if you include one, keep EVERY placeholder and control tag ({{…}}, {{{…}}}, {{#each}}, {{#if}}, {{/each}}, {{/if}}) EXACTLY — same names and count. Keep data-dl-edit attributes. No markdown fences.\n" .
        "Return only the JSON object.";
    $userMsg = "Block type: " . $blockType . "\n\nCurrent template (shows the element classes and placeholders you can target):\n" . $template .
               "\n\nChange request: " . $instruction;
} else {
    dai_fail('Unknown action');
}

// ---- call Claude ----
$apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
if (!$apiKey) { dai_fail('AI is not configured (no API key).'); }

$req = [
    'model'      => 'claude-sonnet-5',
    'max_tokens' => 4096,
    'system'     => $system,
    'messages'   => [['role' => 'user', 'content' => $userMsg]],
];
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'x-api-key: ' . $apiKey,
    'anthropic-version: 2023-06-01',
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) { dai_fail('Could not reach the AI service: ' . $curlErr); }
if ($httpCode !== 200)   { dai_fail('AI request failed (HTTP ' . $httpCode . ').'); }

$data = json_decode($response, true);
if (!is_array($data)) { dai_fail('The AI returned an unreadable response.'); }
if (isset($data['error']['message'])) { dai_fail('AI error: ' . (string)$data['error']['message']); }

// Concatenate every text block (the model may emit more than one, or precede the
// answer with a non-text block). Never assume content[0] is the text.
$text = '';
if (isset($data['content']) && is_array($data['content'])) {
    foreach ($data['content'] as $block) {
        if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
            $text .= (string)$block['text'];
        }
    }
}
$text = trim($text);
if ($text === '') {
    $reason = (string)($data['stop_reason'] ?? $data['type'] ?? 'no text returned');
    dai_fail('The AI returned no usable text (' . $reason . '). Please try again or rephrase.');
}
$text = dai_unfence($text);

// ---- validate + return ----
if ($action === 'restyle') {
    $obj = json_decode($text, true);
    if (!is_array($obj)) {
        // Be forgiving: pull the first {...} block.
        if (preg_match('/\{[\s\S]*\}/', $text, $mm)) { $obj = json_decode($mm[0], true); }
    }
    if (!is_array($obj)) { dai_fail('The AI did not return usable design tokens.'); }

    $theme = [];
    foreach ($TOKENS as $k) {
        if (array_key_exists($k, $obj)) {
            $val = dai_clean_value($obj[$k]);
            if ($val !== '') { $theme[$k] = $val; }
        }
    }
    $customCss = '';
    if (isset($obj['custom_css']) && is_string($obj['custom_css'])) {
        // Allow CSS text but strip tags / @import / script-ish content.
        $c = $obj['custom_css'];
        if (!preg_match('/<|@import|javascript:|expression\s*\(/i', $c)) { $customCss = mb_substr($c, 0, 4000); }
    }
    if (!$theme && $customCss === '') { dai_fail('The AI suggested no valid changes.'); }
    echo json_encode(['success' => true, 'theme' => $theme, 'custom_css' => $customCss]);
    exit;
}

// edit_element: accept {css?, template?}; apply scoped CSS and/or a token-safe template.
$obj = json_decode($text, true);
if (!is_array($obj) && preg_match('/\{[\s\S]*\}/', $text, $mm)) { $obj = json_decode($mm[0], true); }

$css = ''; $tpl = '';
if (is_array($obj)) {
    $css = (isset($obj['css']) && is_string($obj['css'])) ? trim($obj['css']) : '';
    $tpl = (isset($obj['template']) && is_string($obj['template'])) ? trim($obj['template']) : '';
} else {
    // Not JSON — treat the whole reply as a template (backward compatible).
    $tpl = $text;
}

if ($css !== '') {
    if (preg_match('/<|@import|javascript:|expression\s*\(/i', $css)) { $css = ''; }
    else { $css = mb_substr($css, 0, 4000); }
}
if ($tpl !== '') {
    if (mb_strlen($tpl) > 20000) { dai_fail('The AI edit was too large.'); }
    if (preg_match('/<script\b/i', $tpl)) { dai_fail('The AI edit contained a script and was blocked.'); }
    if (dai_tokens($template) !== dai_tokens($tpl)) {
        // Placeholders were altered — drop the template, but still apply CSS if any.
        $tpl = '';
        if ($css === '') {
            dai_fail('The AI changed the automatic content placeholders, so the edit was blocked. Try describing a styling change (e.g. "keep the logo on one line").');
        }
    }
}
if ($css === '' && $tpl === '') {
    dai_fail('The AI suggested no change. Try being more specific about the look you want.');
}
echo json_encode(['success' => true, 'css' => $css, 'template' => $tpl]);
