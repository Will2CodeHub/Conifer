<?php
/**
 * Canonical publication list for the General Statistics tool.
 *
 * KEYS ARE THE CANONICAL PUBLICATION ACRONYMS (same as admin_ten.publications /
 * articles.publications / the scraper), so the site_key stored by
 * cron_collect_stats matches what the stats page queries. This is the single
 * source of truth — statistics.php, ajax/traffic_stats.php and settings.php all
 * read from here so the three lists can never drift apart.
 *
 * NOTE the Berlin mismatch: acronym `tbere` but the home dir is /home/tberuser.
 */
function ten_stats_sites(): array {
    // 'domain' lets the stats page tell a site's OWN referrers (internal
    // click-throughs) apart from external traffic sources.
    return [
        'ten'   => ['name' => 'The Eye Newspapers', 'domain' => 'theeyenewspapers.com', 'log_path' => '/home/tenuser/private/unique_visitors_count.txt'],
        'tme'   => ['name' => 'The Munich Eye',     'domain' => 'themunicheye.com',     'log_path' => '/home/tmeuser/private/unique_visitors_count.txt'],
        'tge'   => ['name' => 'The Germany Eye',    'domain' => 'thegermanyeye.com',    'log_path' => '/home/tgeuser/private/unique_visitors_count.txt'],
        'bae'   => ['name' => 'Buenos Aires Eye',   'domain' => 'buenosaireseve.com',   'log_path' => '/home/baeuser/private/unique_visitors_count.txt'],
        'tbare' => ['name' => 'The Barcelona Eye',  'domain' => 'thebarcelonaeye.com',  'log_path' => '/home/tbareuse/private/unique_visitors_count.txt'],
        'tbrae' => ['name' => 'The Brazil Eye',     'domain' => 'thebrazileye.com',     'log_path' => '/home/tbeuser/private/unique_visitors_count.txt'],
        'tce'   => ['name' => 'The Canary Eye',     'domain' => 'thecanaryeye.com',     'log_path' => '/home/tceuser/private/unique_visitors_count.txt'],
        'tmae'  => ['name' => 'The Madrid Eye',     'domain' => 'themadrideye.com',     'log_path' => '/home/tmaeuser/private/unique_visitors_count.txt'],
        'truse' => ['name' => 'The Russia Eye',     'domain' => 'therussiaeye.com',     'log_path' => '/home/treuser/private/unique_visitors_count.txt'],
        'tte'   => ['name' => 'The Tokyo Eye',      'domain' => 'thetokyoeye.com',      'log_path' => '/home/tteuser/private/unique_visitors_count.txt'],
        'tpe'   => ['name' => 'The Paris Eye',      'domain' => 'thepariseye.com',      'log_path' => '/home/tpeuser/private/unique_visitors_count.txt'],
        'tbere' => ['name' => 'The Berlin Eye',     'domain' => 'theberlineye.com',     'log_path' => '/home/tberuser/private/unique_visitors_count.txt'],
    ];
}

/** Allowed time-period keys for the stats page (value => human label). */
function ten_stats_periods(): array {
    return [
        'last_hour'      => 'Last Hour',
        'last_2_hours'   => 'Last 2 Hours',
        'last_4_hours'   => 'Last 4 Hours',
        'last_12_hours'  => 'Last 12 Hours',
        'last_24_hours'  => 'Last 24 Hours',
        'today'          => 'Today',
        'yesterday'      => 'Yesterday',
        'last_7_days'    => 'Last 7 Days',
        'last_30_days'   => 'Last 30 Days',
        'this_month'     => 'This Month',
        'last_month'     => 'Last Month',
    ];
}
