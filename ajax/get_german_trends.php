<?php
require_once '../config_ten_admin.php';
requireLogin();

// -----------------------------------------------------------------
// SECURITY & SETUP
// -----------------------------------------------------------------
if (!hasPermission('articles.view') && !isAdmin()) {
    echo json_encode(['error' => 'unauthorized']);
    exit();
}

header('Content-Type: application/json');
$open_ai_key = getenv('LATEST_OPENAI_KEY');

function return_json($arr) {
    header('Content-Type: application/json; charset=utf-8');
    // Using JSON_PRETTY_PRINT for better debugging during development
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// -----------------------------------------------------------------
// GOOGLE TRENDS FETCHER FUNCTION
// -----------------------------------------------------------------
/**
 * Robust Google Trends fetcher with debug info and fallback.
 *
 * @param string $geo e.g. 'US' or 'DE'
 * @param bool   $debug when true the function returns debug metadata (raw response, http_code, curl_info, json_error)
 * @param bool   $logToFile when true writes debug info to /tmp/google_trends_debug.log
 * @return array ['results' => [...]]
 */
function fetchGoogleTrends($geo = "US", $debug = false) {
    $url = "https://trends.google.com/trends/api/realtimetrends?hl=en-US&tz=0&cat=all&fi=0&fs=0&geo=" . urlencode($geo) . "&ri=300&rs=20&sort=0";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: application/json, text/javascript, */*; q=0.01",
        "Accept-Language: en-US,en;q=0.9",
        "Referer: https://trends.google.com",
    ]);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');

    $raw = curl_exec($ch);
    $curlErrNo = curl_errno($ch);
    $curlErr = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    if ($curlErrNo !== 0 || !$raw || intval($info['http_code']) >= 400) {
        return ['results' => [], 'error' => 'network or http error'];
    }

    // Clean the Google Trends prefix
    $firstBracePos = strpos($raw, '{');
    if ($firstBracePos === false) return ['results' => [], 'error' => 'no-json-found'];
    $decoded = json_decode(substr($raw, $firstBracePos), true);
    if (!$decoded) return ['results' => [], 'error' => 'json_decode_failed'];

    $items = [];

    // realtime structure: default.rankedList -> rankedKeyword
    if (isset($decoded['default']['rankedList'])) {
        foreach ($decoded['default']['rankedList'] as $rl) {
            if (isset($rl['rankedKeyword'])) {
                foreach ($rl['rankedKeyword'] as $rk) {
                    // timestamp in seconds (if available) or fallback to now
                    $time = isset($rk['time']) ? intval($rk['time']) : time();
                    // only include last 24 hours
                    if ($time >= time() - 24*3600) {
                        $items[] = [
                            'topic' => $rk['query'] ?? '',
                            'search_volume' => isset($rk['traffic']) ? intval($rk['traffic']) : ($rk['formattedTraffic'] ?? 'unknown'),
                            'related' => [],
                            'articles' => isset($rk['articles']) ? array_map(fn($a) => $a['title'] ?? '', $rk['articles']) : [],
                            'date' => date('Y-m-d H:i:s', $time),
                            'geo' => $geo,
                        ];
                    }
                }
            }
        }
    }

    return ['results' => $items];
}

$us_trends = fetchGoogleTrends("US", true); // Keep debug true for now
$de_trends = fetchGoogleTrends("DE", true);

// Merge the results arrays from the 'results' key
$combined_results = array_merge($de_trends['results'], $us_trends['results']);
$combined = array_slice($combined_results, 0, 200);

//print_r($us_trends['debug']);

// ------------------------------
// 2. SEND TO OPENAI FOR FILTERING
// ------------------------------
$prompt = "
You are now being given a list of real Google trending topics (US and Germany, see below).

Task:
1. Extract the **20 trends most relevant to Germany** (topic about Germany, German politics, cities, companies, people, culture, economics, migrants, etc.).  Order these by an optimised combination of search volume and relevance to Germany - priority should be given to high search volume from the US which is relevant to Germany, and to a lesser degree, Europe. These should not be generic terms which are incredibly difficult to rank in the SERPs for, but terms we can write about on social media.
2. If there are fewer than 20, then fill the rest with **topics that would perform well on Instagram news carousels** targeted at:
- English-speaking expats in Germany
- Germans who follow world news in English
- English-speaking global audience who follow news and events in Germany
3. Output **only** a JSON array. DO NOT include any text, markdown, or commentary outside of the array:
[
 {
 \"topic\": \"...\",
 \"search_volume\": \"...\",
 \"baseline_geo\": \"US or DE\",
 \"date\": \"YYYY-MM-DD\",
 \"why_selected\": \"German relevance OR Instagram engagement potential\"
 }
]
Here is the dataset:
" . json_encode($combined);


$data = [
    "model" => "gpt-3.5-turbo",
    "messages" => [
        ["role" => "system", "content" => "You are a trend-analysis expert. Your response must be only the requested JSON array and nothing else."],
        ["role" => "user", "content" => $prompt]
    ],
    "temperature" => 0.3,
    "max_tokens" => 1500
];

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer $open_ai_key"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$response_data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($response_data['choices'][0]['message']['content'])) {
    // Return error with raw response for debugging
    return_json([
        'error' => 'OpenAI API or network error.', 
        'raw_response' => $response
    ]);
}

$openai_content = $response_data['choices'][0]['message']['content'];

// 2. Clean and Decode the final JSON array from OpenAI's content field
// This step removes the markdown fences (```json, ```)
$json_content_clean = preg_replace('/```json\s*|```\s*/i', '', $openai_content);

$final_topics = json_decode($json_content_clean, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($final_topics)) {
    // If the clean content is still not a valid JSON array, return the content for manual review
    return_json([
        'error' => 'Failed to parse final JSON array from OpenAI content. Content was likely invalid JSON.',
        'content_to_parse' => $json_content_clean
    ]);
}

return_json($final_topics);