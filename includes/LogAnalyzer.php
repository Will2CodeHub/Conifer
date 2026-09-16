<?php
/**
 * LogAnalyzer Class
 * Enhanced traffic log analysis with bot filtering and accurate visitor tracking
 * Uses dynamically downloaded bot lists with graceful fallback
 */
class LogAnalyzer {
    
    private $logPath;
    private $siteKey;
    private $botDataDir;
    
    // Cached bot patterns
    private $botPatterns = null;
    private $spamReferrers = null;

    // Auto-reclassification of browser-UA crawlers hiding in the "human" bucket.
    // These slip past the user-agent bot filter, so we also judge by source IP:
    // an IP that alone accounts for an implausible SHARE of human-candidate hits
    // (no single shared NAT should dominate a news audience) or an extreme
    // ABSOLUTE volume is reclassified as a bot. Deliberately CONSERVATIVE so we
    // don't erase real readers behind big corporate/mobile gateways — tune these
    // against real per-site IP data. The no-IP 5-min monitor (IP "unknown") is
    // always reclassified regardless of volume.
    const RECLASSIFY_MIN_ABS   = 3000;  // >=3000 human-candidate hits from one IP in the period
    const RECLASSIFY_MIN_SHARE = 0.15;  // OR >=15% of all human-candidate hits...
    const RECLASSIFY_SHARE_FLOOR = 300; // ...but only once the IP clears this many hits
    const MONITOR_IP = 'unknown';       // no-IP health-check/uptime monitor token
    
    // Fallback bot patterns if files don't exist
    private $fallbackBotPatterns = [
        'googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
        'yandexbot', 'facebookexternalhit', 'twitterbot', 'linkedinbot',
        'whatsapp', 'telegrambot', 'slackbot', 'applebot', 'amazonbot',
        'semrushbot', 'ahrefsbot', 'mj12bot', 'dotbot', 'rogerbot',
        'screaming frog', 'uptimerobot', 'pingdom', 'lighthouse',
        'headless', 'phantom', 'selenium',
        'gptbot', 'chatgpt-user', 'claude-web', 'company.info',
        'bytespider', 'petalbot', 'moreover', 'googleother',
        'spider', 'crawler', 'scraper', 'fetch',
        ' bot', 'bot/', 'bot-', '_bot', 'bot.',
    ];
    
    // Fallback spam referrers
    private $fallbackSpamReferrers = [
        'semalt.com', 'buttons-for-website.com', 'free-share-buttons.com',
        'pornhub-forum.ga', 'buy-cheap-online.info', 'social-buttons.com',
        'event-tracking.com', 'best-seo-offer.com', 'floating-share-buttons.com'
    ];
    
    public function __construct($logPath, $siteKey = 'default', $botDataDir = '/home/tenuser/private/bot_data/') {
        $this->logPath = $logPath;
        $this->siteKey = $siteKey;
        $this->botDataDir = rtrim($botDataDir, '/') . '/';
        
        // Load bot patterns and spam referrers on initialization
        $this->loadBotPatterns();
        $this->loadSpamReferrers();
    }
    
    /**
     * Load bot patterns from compiled file or fallback to defaults
     */
    private function loadBotPatterns() {
        $compiledFile = $this->botDataDir . 'compiled_bot_patterns.json';
        
        try {
            if (file_exists($compiledFile) && is_readable($compiledFile)) {
                $content = @file_get_contents($compiledFile);
                if ($content !== false) {
                    $patterns = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($patterns) && count($patterns) > 0) {
                        $this->botPatterns = $patterns;
                        error_log("LogAnalyzer: Loaded " . count($patterns) . " bot patterns from compiled file");
                        return;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("LogAnalyzer: Error loading bot patterns: " . $e->getMessage());
        }
        
        // Fallback to default patterns
        $this->botPatterns = $this->fallbackBotPatterns;
        error_log("LogAnalyzer: Using " . count($this->botPatterns) . " fallback bot patterns");
    }
    
    /**
     * Load spam referrers from downloaded list or fallback to defaults
     */
    private function loadSpamReferrers() {
        $spamFile = $this->botDataDir . 'referrer-spam.json';
        
        try {
            if (file_exists($spamFile) && is_readable($spamFile)) {
                $content = @file_get_contents($spamFile);
                if ($content !== false) {
                    $referrers = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($referrers) && count($referrers) > 0) {
                        $this->spamReferrers = $referrers;
                        error_log("LogAnalyzer: Loaded " . count($referrers) . " spam referrers from file");
                        return;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("LogAnalyzer: Error loading spam referrers: " . $e->getMessage());
        }
        
        // Fallback to default referrers
        $this->spamReferrers = $this->fallbackSpamReferrers;
        error_log("LogAnalyzer: Using " . count($this->spamReferrers) . " fallback spam referrers");
    }
    
    /**
     * Parse log file for a specific time period
     * @param int $startTime Unix timestamp
     * @param int $endTime Unix timestamp
     * @return array Statistics array
     */
    public function analyzeTimePeriod($startTime, $endTime, $maxTailBytes = 0, $includeIds = false) {
        if (!file_exists($this->logPath) || !is_readable($this->logPath)) {
            error_log("LogAnalyzer: Log file not accessible at {$this->logPath}");
            return $this->getEmptyStats();
        }

        // Stream the file line-by-line — the visitor log can be very large and
        // loading it all into memory (file_get_contents) exhausts PHP's memory
        // limit and fatals, which is what used to break the stats tool.
        $fh = @fopen($this->logPath, 'r');
        if ($fh === false) {
            error_log("LogAnalyzer: Failed to open log file at {$this->logPath}");
            return $this->getEmptyStats();
        }

        // For recent/live periods, only scan the TAIL of the file — recent entries
        // are appended at the end, so reading the last few MB bounds the work even
        // when the log is huge (otherwise a busy site's log times out the request).
        if ($maxTailBytes > 0) {
            $sz = @filesize($this->logPath);
            if ($sz !== false && $sz > $maxTailBytes) {
                fseek($fh, -$maxTailBytes, SEEK_END);
                fgets($fh); // discard the partial first line after the seek
            }
        }

        error_log("LogAnalyzer: Streaming log for period " .
                  date('Y-m-d H:i:s', $startTime) . " to " . date('Y-m-d H:i:s', $endTime));
        
        $stats = [
            'total_visits' => 0,
            'unique_visitors' => [],
            'human_visits' => 0,
            'human_unique' => [],
            'bot_visits' => 0,
            'spam_visits' => 0,
            'hourly_distribution' => array_fill(0, 24, 0),
            'page_views' => [],
            'referrers' => [],
            'user_agents' => [],
            // Per-IP hit counts among HUMAN-classified traffic. Browser-UA crawlers
            // slip past the user-agent bot filter and land in the human bucket, so a
            // single IP (or a few) racking up thousands of "human" hits is the tell.
            'human_ip_hits' => [],
        ];

        // PASS 1: tally human-candidate hits per source IP, then decide which IPs
        // are automated (dominant/extreme-volume crawlers + the no-IP 5-min
        // monitor). PASS 2 (the loop below) reclassifies their hits as bots so
        // human_visits / uniques / page-views / fingerprints stay honest.
        $flaggedIps = $this->computeFlaggedIps(
            $this->scanHumanIpVolumes($startTime, $endTime, $maxTailBytes)
        );
        $stats['bot_reclassified'] = 0;
        $stats['reclassified_ips'] = [];

        $processedLines = 0;
        $skippedLines = 0;
        
        while (($line = fgets($fh)) !== false) {
            if (trim($line) === '') { continue; }
            $parsed = $this->parseLogLine($line);
            if (!$parsed) {
                $skippedLines++;
                continue;
            }
            
            // Check if within time range
            if ($parsed['timestamp'] < $startTime || $parsed['timestamp'] > $endTime) {
                continue;
            }
            
            $processedLines++;
            $stats['total_visits']++;
            $stats['unique_visitors'][$parsed['visitor_id']] = true;
            
            // Hourly distribution
            $hour = (int)date('G', $parsed['timestamp']);
            $stats['hourly_distribution'][$hour]++;
            
            // Normalise the source IP the same way pass 1 did (missing IP → monitor).
            $lineIp = (isset($parsed['ip']) && $parsed['ip'] !== '' && $parsed['ip'] !== '-')
                ? $parsed['ip'] : self::MONITOR_IP;

            // Categorize traffic
            if ($this->isBot($parsed)) {
                $stats['bot_visits']++;
            } elseif ($this->isSpam($parsed)) {
                $stats['spam_visits']++;
            } elseif (isset($flaggedIps[$lineIp])) {
                // Reclassified: automated traffic wearing an ordinary browser UA
                // (or the no-IP monitor) — counted as a bot, not a human visitor.
                $stats['bot_visits']++;
                $stats['bot_reclassified']++;
                $stats['reclassified_ips'][$lineIp] = ($stats['reclassified_ips'][$lineIp] ?? 0) + 1;
            } else {
                $stats['human_visits']++;
                $stats['human_unique'][$parsed['visitor_id']] = true;

                // Tally the source IP for human hits (crawler-in-disguise detection).
                if (isset($parsed['ip']) && $parsed['ip'] !== '' && $parsed['ip'] !== '-') {
                    $stats['human_ip_hits'][$parsed['ip']] =
                        ($stats['human_ip_hits'][$parsed['ip']] ?? 0) + 1;
                }

                // Track page views
                if (isset($parsed['url'])) {
                    if (!isset($stats['page_views'][$parsed['url']])) {
                        $stats['page_views'][$parsed['url']] = 0;
                    }
                    $stats['page_views'][$parsed['url']]++;
                }
                
                // Track referrers
                if (isset($parsed['referrer']) && $parsed['referrer'] !== '-') {
                    if (!isset($stats['referrers'][$parsed['referrer']])) {
                        $stats['referrers'][$parsed['referrer']] = 0;
                    }
                    $stats['referrers'][$parsed['referrer']]++;
                }
            }
            
            // Track user agents
            if (isset($parsed['user_agent'])) {
                $browser = $this->detectBrowser($parsed['user_agent']);
                if (!isset($stats['user_agents'][$browser])) {
                    $stats['user_agents'][$browser] = 0;
                }
                $stats['user_agents'][$browser]++;
            }
        }
        fclose($fh);

        error_log("LogAnalyzer: Processed $processedLines lines, skipped $skippedLines invalid lines");
        error_log("LogAnalyzer: Found {$stats['total_visits']} visits, {$stats['human_visits']} human, " . 
                  "{$stats['bot_visits']} bots, {$stats['spam_visits']} spam");
        
        // Capture per-day distinct visitor-ID fingerprints (crc32) BEFORE collapsing
        // to counts. Storing these lets month-level views UNION them across days for
        // true dedup, instead of summing daily uniques (which double-counts anyone
        // who returns on more than one day). crc32 keeps each id to 4 bytes; at these
        // volumes hash collisions are negligible (<0.02%).
        if ($includeIds) {
            $stats['visitor_hashes'] = array_values(array_map('crc32', array_keys($stats['unique_visitors'])));
            $stats['human_unique_hashes'] = array_values(array_map('crc32', array_keys($stats['human_unique'])));
        }

        // Convert unique arrays to counts
        $stats['unique_visitors'] = count($stats['unique_visitors']);
        $stats['human_unique'] = count($stats['human_unique']);

        // Distinct human IPs, then keep only the top offenders (bounds storage; the
        // long tail of one-hit reader IPs isn't useful and is needless PII to store).
        $stats['human_ip_distinct'] = count($stats['human_ip_hits']);
        arsort($stats['human_ip_hits']);
        $stats['top_ips'] = array_slice($stats['human_ip_hits'], 0, 50, true);
        unset($stats['human_ip_hits']);

        // Top reclassified (automated) IPs, for transparency on the stats page.
        arsort($stats['reclassified_ips']);
        $stats['reclassified_ips'] = array_slice($stats['reclassified_ips'], 0, 50, true);

        // Sort page views and referrers
        arsort($stats['page_views']);
        arsort($stats['referrers']);

        return $stats;
    }
    
    /**
     * PASS 1 for reclassification: stream the log once and count, per source IP,
     * the hits that WOULD be classified human (not a UA-bot, not spam). Uses the
     * same tail-seek + time-window logic as the main pass so the volumes match.
     * A missing IP is bucketed under MONITOR_IP so the no-IP monitor is counted.
     *
     * @return array ip => human-candidate hit count
     */
    private function scanHumanIpVolumes($startTime, $endTime, $maxTailBytes) {
        $counts = [];
        $fh = @fopen($this->logPath, 'r');
        if ($fh === false) return $counts;

        if ($maxTailBytes > 0) {
            $sz = @filesize($this->logPath);
            if ($sz !== false && $sz > $maxTailBytes) {
                fseek($fh, -$maxTailBytes, SEEK_END);
                fgets($fh); // discard the partial first line after the seek
            }
        }

        while (($line = fgets($fh)) !== false) {
            if (trim($line) === '') { continue; }
            $parsed = $this->parseLogLine($line);
            if (!$parsed) { continue; }
            if ($parsed['timestamp'] < $startTime || $parsed['timestamp'] > $endTime) { continue; }
            // Only human-candidate lines count toward an IP's "human" volume.
            if ($this->isBot($parsed) || $this->isSpam($parsed)) { continue; }
            $ip = (isset($parsed['ip']) && $parsed['ip'] !== '' && $parsed['ip'] !== '-')
                ? $parsed['ip'] : self::MONITOR_IP;
            $counts[$ip] = ($counts[$ip] ?? 0) + 1;
        }
        fclose($fh);
        return $counts;
    }

    /**
     * Decide which IPs are automated, from pass-1 volumes. An IP is flagged when:
     *  - it is the no-IP monitor (MONITOR_IP), always; or
     *  - its human-candidate hits reach RECLASSIFY_MIN_ABS (extreme single-IP
     *    volume); or
     *  - it clears RECLASSIFY_SHARE_FLOOR hits AND is >= RECLASSIFY_MIN_SHARE of
     *    all human-candidate hits (dominant single source — no legitimate shared
     *    gateway should be that large a slice of a news audience).
     * Conservative by design: a distributed crawler whose IPs each stay small is
     * NOT auto-reclassified (the pages-per-visitor read-out still flags it).
     *
     * @return array ip => hits (only the flagged IPs)
     */
    private function computeFlaggedIps(array $ipCounts) {
        $total = array_sum($ipCounts);
        $flagged = [];
        foreach ($ipCounts as $ip => $hits) {
            $isMonitor = ($ip === self::MONITOR_IP);
            $byAbs = $hits >= self::RECLASSIFY_MIN_ABS;
            $byShare = $hits >= self::RECLASSIFY_SHARE_FLOOR
                && $total > 0
                && ($hits / $total) >= self::RECLASSIFY_MIN_SHARE;
            if ($isMonitor || $byAbs || $byShare) {
                $flagged[$ip] = $hits;
            }
        }
        return $flagged;
    }

    /**
     * Parse a single log line
     * Format: [30/Oct/2025:13:38:41 +0100] visitor_id ip user_agent referrer url
     * Note: user_agent can contain spaces, referrer can be URL or "-", url is path
     */
    private function parseLogLine($line) {
        $line = trim($line);
        
        if (empty($line)) {
            return false;
        }
        
        // Match the timestamp in brackets
        if (!preg_match('/^\[(.*?)\]\s+(.*)/', $line, $matches)) {
            return false;
        }
        
        $timestampRaw = $matches[1];
        $rest = $matches[2];
        
        // Parse timestamp
        $timestamp = $this->parseTimestamp($timestampRaw);
        if (!$timestamp) return false;
        
        // Split into tokens
        $parts = preg_split('/\s+/', $rest);
        
        if (count($parts) < 4) {
            return false; // At minimum need visitor_id, ip, user_agent, referrer
        }
        
        $visitorId = $parts[0];
        $ip = $parts[1];
        
        $parsed = [
            'timestamp' => $timestamp,
            'visitor_id' => $visitorId,
            'ip' => $ip,
        ];
        
        // Strategy: Find the referrer (starts with http or is "-") and URL (starts with "/")
        // Everything between IP and referrer is user_agent
        
        $referrerIndex = -1;
        $urlIndex = -1;
        
        // Find referrer - look for token that is "-" or starts with "http"
        for ($i = 2; $i < count($parts); $i++) {
            if ($parts[$i] === '-' || strpos($parts[$i], 'http://') === 0 || strpos($parts[$i], 'https://') === 0) {
                $referrerIndex = $i;
                break;
            }
        }
        
        // Find URL - look for token that starts with "/"
        if ($referrerIndex !== -1) {
            for ($i = $referrerIndex + 1; $i < count($parts); $i++) {
                if (strpos($parts[$i], '/') === 0) {
                    $urlIndex = $i;
                    break;
                }
            }
        }
        
        // Extract user agent (from index 2 to referrerIndex)
        if ($referrerIndex > 2) {
            $userAgentParts = array_slice($parts, 2, $referrerIndex - 2);
            $parsed['user_agent'] = implode(' ', $userAgentParts);
        } elseif ($referrerIndex === 2) {
            $parsed['user_agent'] = 'Unknown';
        } else {
            // No referrer found, everything after IP is user agent
            $userAgentParts = array_slice($parts, 2);
            $parsed['user_agent'] = implode(' ', $userAgentParts);
        }
        
        // Extract referrer
        if ($referrerIndex !== -1) {
            $parsed['referrer'] = $parts[$referrerIndex];
        } else {
            $parsed['referrer'] = '-';
        }
        
        // Extract URL (may have spaces if encoded)
        if ($urlIndex !== -1) {
            $urlParts = array_slice($parts, $urlIndex);
            $parsed['url'] = implode(' ', $urlParts);
        } else {
            $parsed['url'] = '/';
        }
        
        return $parsed;
    }
    
    /**
     * Parse Apache/Varnish timestamp format
     */
    private function parseTimestamp($timestamp) {
        // Format: 30/Oct/2025:13:38:41 +0100
        // Convert to: 30-Oct-2025 13:38:41 +0100
        try {
            $cleaned = str_replace('/', '-', $timestamp);
            $cleaned = preg_replace('/:/', ' ', $cleaned, 1);
            $time = strtotime($cleaned);
            return $time !== false ? $time : null;
        } catch (Exception $e) {
            error_log("LogAnalyzer: Error parsing timestamp '$timestamp': " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check if request is from a bot using downloaded patterns
     */
    private function isBot($parsed) {
        if (!isset($parsed['user_agent'])) {
            return false;
        }
        
        $userAgent = strtolower($parsed['user_agent']);
        
        // Empty or very short user agents are suspicious
        if (strlen(trim($userAgent)) < 3) {
            return true;
        }
        
        // Use loaded patterns (either from file or fallback)
        if (is_array($this->botPatterns)) {
            foreach ($this->botPatterns as $pattern) {
                if (strpos($userAgent, strtolower($pattern)) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if request is spam using downloaded referrer list
     */
    private function isSpam($parsed) {
        if (!isset($parsed['referrer']) || $parsed['referrer'] === '-') {
            return false;
        }
        
        $referrer = strtolower($parsed['referrer']);
        
        // Use loaded spam referrers (either from file or fallback)
        if (is_array($this->spamReferrers)) {
            foreach ($this->spamReferrers as $spamDomain) {
                if (strpos($referrer, strtolower($spamDomain)) !== false) {
                    return true;
                }
            }
        }
        
        // Check for suspicious patterns
        if (preg_match('/(pills|viagra|casino|poker|xxx|porn|sex|nude|adult)/i', $referrer)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Detect browser from user agent
     */
    private function detectBrowser($userAgent) {
        $userAgent = strtolower($userAgent);
        
        if (strpos($userAgent, 'edg') !== false) return 'Edge';
        if (strpos($userAgent, 'chrome') !== false && strpos($userAgent, 'edg') === false) return 'Chrome';
        if (strpos($userAgent, 'safari') !== false && strpos($userAgent, 'chrome') === false) return 'Safari';
        if (strpos($userAgent, 'firefox') !== false) return 'Firefox';
        if (strpos($userAgent, 'opera') !== false || strpos($userAgent, 'opr') !== false) return 'Opera';
        if (strpos($userAgent, 'msie') !== false || strpos($userAgent, 'trident') !== false) return 'IE';
        
        return 'Other';
    }
    
    /**
     * Get empty stats structure
     */
    private function getEmptyStats() {
        return [
            'total_visits' => 0,
            'unique_visitors' => 0,
            'human_visits' => 0,
            'human_unique' => 0,
            'bot_visits' => 0,
            'spam_visits' => 0,
            'hourly_distribution' => array_fill(0, 24, 0),
            'page_views' => [],
            'referrers' => [],
            'user_agents' => [],
            'top_ips' => [],
            'human_ip_distinct' => 0,
            'bot_reclassified' => 0,
            'reclassified_ips' => [],
        ];
    }
    
    /**
     * Get quick stats for dashboard (last N hours from live log)
     */
    public function getQuickStats($hours = 24) {
        $endTime = time();
        $startTime = $endTime - ($hours * 3600);
        return $this->analyzeTimePeriod($startTime, $endTime);
    }
    
    /**
     * Get information about loaded bot detection data
     */
    public function getBotDetectionInfo() {
        $compiledFile = $this->botDataDir . 'compiled_bot_patterns.json';
        $spamFile = $this->botDataDir . 'referrer-spam.json';
        $summaryFile = $this->botDataDir . 'update_summary.json';
        
        $info = [
            'bot_patterns_count' => is_array($this->botPatterns) ? count($this->botPatterns) : 0,
            'spam_referrers_count' => is_array($this->spamReferrers) ? count($this->spamReferrers) : 0,
            'using_fallback_bots' => !file_exists($compiledFile),
            'using_fallback_spam' => !file_exists($spamFile),
            'last_update' => null,
        ];
        
        // Get last update time from summary
        if (file_exists($summaryFile)) {
            $summary = json_decode(file_get_contents($summaryFile), true);
            if (isset($summary['last_updated'])) {
                $info['last_update'] = $summary['last_updated'];
            }
        }
        
        return $info;
    }
}