<?php
/**
 * Bot Lists Update Cron Job
 * Downloads and updates bot/crawler lists and spam databases
 * 
 * Run daily:
 * 0 3 * * * /usr/bin/php /path/to/cron_update_bot_lists.php >> /path/to/logs/bot_lists.log 2>&1
 */

date_default_timezone_set('Europe/Berlin');

echo "=== Bot Lists Update Started at " . date('Y-m-d H:i:s') . " ===\n";

// Configuration
$dataDir = '/home/tenuser/private/bot_data/';
$tempDir = $dataDir . 'temp/';

// Create directories if they don't exist
if (!is_dir($dataDir)) {
    if (!mkdir($dataDir, 0755, true)) {
        echo "ERROR: Failed to create data directory: $dataDir\n";
        exit(1);
    }
    echo "Created data directory: $dataDir\n";
}

if (!is_dir($tempDir)) {
    if (!mkdir($tempDir, 0755, true)) {
        echo "ERROR: Failed to create temp directory: $tempDir\n";
        exit(1);
    }
    echo "Created temp directory: $tempDir\n";
}

// Verify directories are writable
if (!is_writable($dataDir)) {
    echo "ERROR: Data directory is not writable: $dataDir\n";
    exit(1);
}

if (!is_writable($tempDir)) {
    echo "ERROR: Temp directory is not writable: $tempDir\n";
    exit(1);
}

$errors = [];
$successes = [];

// ==========================================
// 1. Download Crawler User Agents List
// ==========================================
echo "\n--- Downloading Crawler User Agents ---\n";

$crawlerUrl = 'https://raw.githubusercontent.com/monperrus/crawler-user-agents/master/crawler-user-agents.json';
$crawlerFile = $dataDir . 'crawler-user-agents.json';
$crawlerTemp = $tempDir . 'crawler-user-agents.json';

echo "Fetching: $crawlerUrl\n";

$crawlerData = @file_get_contents($crawlerUrl, false, stream_context_create([
    'http' => [
        'timeout' => 30,
        'user_agent' => 'TEN-Management-BotUpdater/1.0'
    ]
]));

if ($crawlerData !== false && strlen($crawlerData) > 0) {
    file_put_contents($crawlerTemp, $crawlerData);
    
    // Validate JSON
    $json = json_decode($crawlerData, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($json) && count($json) > 0) {
        rename($crawlerTemp, $crawlerFile);
        echo "✓ Downloaded " . count($json) . " crawler patterns\n";
        $successes[] = "Crawler user agents: " . count($json) . " patterns";
    } else {
        echo "✗ Invalid JSON in crawler data (error: " . json_last_error_msg() . ")\n";
        $errors[] = "Crawler user agents - invalid JSON: " . json_last_error_msg();
        if (file_exists($crawlerTemp)) {
            unlink($crawlerTemp);
        }
    }
} else {
    echo "✗ Failed to download crawler list\n";
    $errors[] = "Crawler user agents - download failed";
}

// ==========================================
// 2. Download Known Bot User Agents
// ==========================================
echo "\n--- Downloading Additional Bot Lists ---\n";

// Matomo's device detector bot list
$matomoUrl = 'https://raw.githubusercontent.com/matomo-org/device-detector/master/Tests/fixtures/bots.yml';
$matomoFile = $dataDir . 'matomo-bots.yml';
$matomoTemp = $tempDir . 'matomo-bots.yml';

echo "Fetching: $matomoUrl\n";

$matomoData = @file_get_contents($matomoUrl, false, stream_context_create([
    'http' => [
        'timeout' => 30,
        'user_agent' => 'TEN-Management-BotUpdater/1.0'
    ]
]));

if ($matomoData !== false && strlen($matomoData) > 0) {
    file_put_contents($matomoTemp, $matomoData);
    rename($matomoTemp, $matomoFile);
    echo "✓ Downloaded Matomo bot list (" . strlen($matomoData) . " bytes)\n";
    $successes[] = "Matomo bots: " . strlen($matomoData) . " bytes";
} else {
    echo "✗ Failed to download Matomo bot list\n";
    $errors[] = "Matomo bots - download failed";
}

// ==========================================
// 3. Build Compiled Bot Patterns
// ==========================================
echo "\n--- Compiling Bot Patterns ---\n";

$allBotPatterns = [];

// Load crawler-user-agents.json
if (file_exists($crawlerFile)) {
    echo "Loading crawler patterns...\n";
    $crawlers = json_decode(file_get_contents($crawlerFile), true);
    if (is_array($crawlers)) {
        $extracted = 0;
        foreach ($crawlers as $crawler) {
            if (isset($crawler['pattern'])) {
                // Convert regex pattern to simple string match
                $pattern = strtolower($crawler['pattern']);
                // Remove regex characters
                $pattern = str_replace(['.*', '.+', '\\', '/', '(', ')', '[', ']', '^', '$', '|', '?'], '', $pattern);
                $pattern = trim($pattern);
                
                if (!empty($pattern) && strlen($pattern) > 2) {
                    $allBotPatterns[] = $pattern;
                    $extracted++;
                }
            }
        }
        echo "  → Extracted $extracted patterns from crawler list\n";
    }
}

// Parse Matomo YAML (simplified - just extract bot names)
if (file_exists($matomoFile)) {
    echo "Loading Matomo bot patterns...\n";
    $matomoContent = file_get_contents($matomoFile);
    $extracted = 0;
    
    // Extract bot names from YAML
    preg_match_all('/name:\s*[\'"]?([^\'"]+)[\'"]?/i', $matomoContent, $matches);
    if (isset($matches[1])) {
        foreach ($matches[1] as $botName) {
            $pattern = strtolower(trim($botName));
            if (!empty($pattern) && strlen($pattern) > 2) {
                $allBotPatterns[] = $pattern;
                $extracted++;
            }
        }
        echo "  → Extracted $extracted patterns from Matomo list\n";
    }
}

// Add our custom patterns
$customPatterns = [
    'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
    'yandexbot', 'facebookexternalhit', 'twitterbot', 'linkedinbot',
    'whatsapp', 'telegrambot', 'slackbot', 'applebot', 'amazonbot',
    'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'rogerbot',
    'uptimerobot', 'pingdom', 'lighthouse',
    'gptbot', 'chatgpt-user', 'claude-web',
    'bytespider', 'petalbot', 'moreover', 'googleother',
    'spider', 'crawler', 'scraper', 'fetcher', 'bot',
    ' bot', 'bot/', 'bot-', '_bot', 'bot.',
    'headless', 'phantom', 'selenium', 'puppeteer',
    'curl', 'wget', 'python-requests', 'go-http-client',
];

echo "  → Adding " . count($customPatterns) . " custom patterns\n";
$allBotPatterns = array_merge($allBotPatterns, $customPatterns);

// Remove duplicates and empty patterns
$allBotPatterns = array_filter(array_unique($allBotPatterns), function($p) {
    return !empty($p) && strlen($p) > 2;
});

// Sort for faster searching (alphabetically)
sort($allBotPatterns);

// Save compiled list
$compiledFile = $dataDir . 'compiled_bot_patterns.json';
$jsonData = json_encode(array_values($allBotPatterns), JSON_PRETTY_PRINT);

if ($jsonData !== false) {
    if (file_put_contents($compiledFile, $jsonData) !== false) {
        echo "✓ Compiled " . count($allBotPatterns) . " unique bot patterns\n";
        echo "  File saved: $compiledFile\n";
        $successes[] = "Compiled patterns: " . count($allBotPatterns);
    } else {
        echo "✗ Failed to write compiled patterns file\n";
        $errors[] = "Failed to write compiled patterns";
    }
} else {
    echo "✗ Failed to encode bot patterns as JSON\n";
    $errors[] = "Failed to encode bot patterns";
}

// ==========================================
// 4. Download Spam Referrer Lists
// ==========================================
echo "\n--- Downloading Spam Referrer Lists ---\n";

// Matomo's referrer spam list
$referrerSpamUrl = 'https://raw.githubusercontent.com/matomo-org/referrer-spam-list/master/spammers.txt';
$referrerSpamFile = $dataDir . 'referrer-spam.txt';
$referrerSpamTemp = $tempDir . 'referrer-spam.txt';

echo "Fetching: $referrerSpamUrl\n";

$spamData = @file_get_contents($referrerSpamUrl, false, stream_context_create([
    'http' => [
        'timeout' => 30,
        'user_agent' => 'TEN-Management-BotUpdater/1.0'
    ]
]));

if ($spamData !== false && strlen($spamData) > 0) {
    file_put_contents($referrerSpamTemp, $spamData);
    
    // Parse and clean spam referrers
    $spamReferrers = array_filter(explode("\n", $spamData), function($line) {
        $line = trim($line);
        return !empty($line) && strpos($line, '#') !== 0;
    });
    
    // Clean up the referrers
    $spamReferrers = array_map('trim', $spamReferrers);
    $spamReferrers = array_unique($spamReferrers);
    $spamReferrers = array_values($spamReferrers);
    
    if (count($spamReferrers) > 0) {
        rename($referrerSpamTemp, $referrerSpamFile);
        echo "✓ Downloaded " . count($spamReferrers) . " spam referrer domains\n";
        $successes[] = "Spam referrers: " . count($spamReferrers) . " domains";
        
        // Also save as JSON for easier loading
        $jsonFile = $dataDir . 'referrer-spam.json';
        $jsonData = json_encode($spamReferrers, JSON_PRETTY_PRINT);
        if ($jsonData !== false && file_put_contents($jsonFile, $jsonData) !== false) {
            echo "  → JSON version saved: $jsonFile\n";
        }
    } else {
        echo "✗ No valid spam referrers found in downloaded data\n";
        $errors[] = "Spam referrers - no valid entries";
    }
} else {
    echo "✗ Failed to download spam referrer list\n";
    $errors[] = "Spam referrers - download failed";
}

// ==========================================
// 5. Cache Stop Forum Spam Top IPs
// ==========================================
echo "\n--- Managing IP Cache ---\n";

// Note: Stop Forum Spam doesn't provide a full list for free
// We'll create a structure for caching checked IPs instead
$ipCacheDir = $dataDir . 'ip_cache/';
if (!is_dir($ipCacheDir)) {
    if (mkdir($ipCacheDir, 0755, true)) {
        echo "✓ Created IP cache directory\n";
    } else {
        echo "✗ Failed to create IP cache directory\n";
        $errors[] = "IP cache - directory creation failed";
    }
}

// Clean old IP cache files (older than 7 days)
$cleaned = 0;
$files = glob($ipCacheDir . '*.json');
if ($files !== false) {
    foreach ($files as $file) {
        if (time() - filemtime($file) > 604800) { // 7 days
            if (unlink($file)) {
                $cleaned++;
            }
        }
    }
    echo "✓ Cleaned $cleaned old IP cache files\n";
    if ($cleaned > 0) {
        $successes[] = "IP cache: cleaned $cleaned old files";
    }
}

// ==========================================
// 6. Generate Summary Report
// ==========================================
echo "\n--- Generating Summary ---\n";

$summary = [
    'last_updated' => date('Y-m-d H:i:s'),
    'update_timestamp' => time(),
    'bot_patterns_count' => count($allBotPatterns),
    'spam_referrers_count' => isset($spamReferrers) ? count($spamReferrers) : 0,
    'ip_cache_entries' => count(glob($ipCacheDir . '*.json')),
    'successes' => $successes,
    'errors' => $errors,
    'files' => [
        'compiled_bot_patterns' => file_exists($compiledFile) ? filesize($compiledFile) . ' bytes' : 'missing',
        'referrer_spam_json' => file_exists($dataDir . 'referrer-spam.json') ? filesize($dataDir . 'referrer-spam.json') . ' bytes' : 'missing',
        'crawler_user_agents' => file_exists($crawlerFile) ? filesize($crawlerFile) . ' bytes' : 'missing',
        'matomo_bots' => file_exists($matomoFile) ? filesize($matomoFile) . ' bytes' : 'missing',
    ]
];

$summaryFile = $dataDir . 'update_summary.json';
$summaryJson = json_encode($summary, JSON_PRETTY_PRINT);

if ($summaryJson !== false && file_put_contents($summaryFile, $summaryJson) !== false) {
    echo "✓ Summary report saved: $summaryFile\n";
} else {
    echo "✗ Failed to save summary report\n";
}

echo "\n=== Update Summary ===\n";
echo "Bot Patterns: " . $summary['bot_patterns_count'] . "\n";
echo "Spam Referrers: " . $summary['spam_referrers_count'] . "\n";
echo "Cached IPs: " . $summary['ip_cache_entries'] . "\n";
echo "Successes: " . count($successes) . "\n";
echo "Errors: " . count($errors) . "\n";

if (!empty($successes)) {
    echo "\nSuccesses:\n";
    foreach ($successes as $success) {
        echo "  ✓ $success\n";
    }
}

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  ✗ $error\n";
    }
}

// Verify critical files exist
echo "\n=== File Verification ===\n";
$criticalFiles = [
    'compiled_bot_patterns.json' => $compiledFile,
    'referrer-spam.json' => $dataDir . 'referrer-spam.json',
];

$allCriticalExist = true;
foreach ($criticalFiles as $name => $path) {
    if (file_exists($path) && is_readable($path) && filesize($path) > 0) {
        echo "✓ $name: " . filesize($path) . " bytes\n";
    } else {
        echo "✗ $name: MISSING or EMPTY\n";
        $allCriticalExist = false;
    }
}

if ($allCriticalExist) {
    echo "\n✓ All critical files present and valid\n";
} else {
    echo "\n✗ Some critical files are missing - bot detection will use fallback patterns\n";
}

echo "\n=== Update Complete at " . date('Y-m-d H:i:s') . " ===\n";

exit(count($errors) > 0 ? 1 : 0);
