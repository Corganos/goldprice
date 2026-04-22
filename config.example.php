<?php
/**
 * config.example.php
 *
 * Copy this to config.php and fill in your API keys.
 * Make sure config.php is ABOVE your web root so it can't be served.
 *
 * Suggested directory layout on cPanel:
 *
 *   /home/youruser/private/
 *     config.php                  ← your real config (not web-accessible)
 *     data/
 *       cache/                    ← file cache
 *       news.sqlite               ← auto-created by cron
 *       rhodium.json              ← manually maintained rhodium price
 *     cron/
 *       fetch-news.php
 *       tag-news.php
 *     lib/                        ← (optional) shared helpers
 *
 *   /home/youruser/public_html/
 *     api/
 *       dashboard.php             ← the endpoint your JS calls
 *     index.html                  ← the dashboard page
 *
 *   dashboard.php requires '../../private/config.php'
 */

return [
    // ── API keys ─────────────────────────────────────────
    'metalprice_key' => 'PUT_YOUR_METALPRICEAPI_KEY_HERE',
    'finnhub_key'    => 'PUT_YOUR_FINNHUB_KEY_HERE',
    'openai_key'     => 'PUT_YOUR_OPENAI_KEY_HERE', // optional, only for news tagging

    // ── paths ────────────────────────────────────────────
    'data_dir'       => __DIR__ . '/data',
    'cache_dir'      => __DIR__ . '/data/cache',
    'news_db'        => __DIR__ . '/data/news.sqlite',
    'rhodium_file'   => __DIR__ . '/data/rhodium.json',

    // ── cache TTLs (seconds) ─────────────────────────────
    // cache_metals MUST be ≥ your MetalpriceAPI plan's update frequency,
    // otherwise you're polling faster than their data actually refreshes
    // and burning quota. Basic Plus = 60s updates = cache 60.
    // Monthly budget math:
    //   60s cache × (60/60) calls/min × 60 min × 24hr × 30d = 43,200 calls
    //   Basic Plus allows 50,000/month → ~6,800 headroom for cold starts
    //   and manual refreshes. Safe.
    'cache_metals'   => 60,
    'cache_stocks'   => 60,
    'cache_news'     => 300,

    // ── which tickers to fetch from Finnhub ──────────────
    'tickers' => [
        'miners_gold'   => ['NEM','GOLD','AEM','FNV','WPM','KGC','AU','GFI'],
        'miners_silver' => ['PAAS','AG','HL','SVM','FSM','EXK','MAG','SSRM'],
        'correlated'    => ['SPY','QQQ','DIA','GLD','SLV','USO','UUP'],
    ],

    // ── FX table: which currencies to show gold priced in ─
    'fx_currencies' => [
        'USD' => 'US Dollar',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'JPY' => 'Japanese Yen',
        'CAD' => 'Canadian Dollar',
        'AUD' => 'Australian Dollar',
        'CHF' => 'Swiss Franc',
        'CNY' => 'Chinese Yuan',
        'INR' => 'Indian Rupee',
        'MXN' => 'Mexican Peso',
        'BRL' => 'Brazilian Real',
        'HKD' => 'Hong Kong Dollar',
        'SGD' => 'Singapore Dollar',
        'ZAR' => 'South African Rand',
        'RUB' => 'Russian Ruble',
        'TRY' => 'Turkish Lira',
    ],

    // ── Corgano WordPress integration ────────────────────
    // Pulls articles from the main corgano.com WordPress site via its
    // built-in REST API (public, no auth required). These appear in the
    // "Contributed Analysis" panel on the dashboard with a CORGANO badge.
    //
    // Since corgano.com is a multi-topic site, we filter by WORDPRESS TAGS
    // to scope content to what's relevant for this specific subdomain.
    // Articles matching ANY tag in the list below will be pulled (OR logic).
    //
    // Each subdomain uses its own filter set:
    //   goldprice    : ['gold', 'precious-metals', 'bullion', 'mining', 'central-banks']
    //   silverprice  : ['silver', 'industrial-metals', 'mining']
    //   bitcoinprice : ['bitcoin', 'crypto', 'digital-assets']
    //
    // Leave the array empty to pull ALL posts regardless of tag (not
    // recommended for a topic-specific subdomain).
    //
    // To see what tags already exist on corgano.com, visit:
    //   https://corgano.com/wp-json/wp/v2/tags?per_page=100
    // Tag slugs are case-sensitive and use hyphens, not underscores
    // (WordPress convention: "precious-metals" not "precious_metals").
    'corgano_wp_url'         => 'https://corgano.com',
    'corgano_per_page'       => 10,
    'corgano_tag_slugs'      => ['gold', 'precious-metals', 'bullion', 'mining', 'central-banks'],
    'corgano_category_slug'  => '',    // optional secondary AND-filter; e.g. 'commentary' to only pull commentary-category posts that ALSO match a tag
    'cache_articles'         => 900,   // 15 min — articles change rarely

    // ── Kitco fallback charts ────────────────────────────
    // Hotlinked from kitconet.com. FREE per Kitco's TOS, but the image MUST
    // link back to kitco.com/connecting.html (we do this in the frontend).
    // These render automatically when MetalpriceAPI is unreachable.
    //
    // URL pattern I could confirm: /images/live/s_{metal}.gif (small banner).
    // There are also m_ (medium) and l_ (large) sizes — visit
    // https://www.kitconet.com/main.html to browse the full menu and swap
    // any of these for the layout you prefer.
    //
    // If Kitco ever changes these URLs, fallbacks silently fail to a broken
    // image icon. Consider hosting your own static PNG copy of each as a
    // second-line fallback (snapshot yesterday's chart, update daily).
    'fallback_charts' => [
        'gold'      => 'http://www.kitconet.com/images/live/s_gold.gif',
        'silver'    => 'http://www.kitconet.com/images/live/s_silver.gif',
        'platinum'  => 'http://www.kitconet.com/images/live/s_platinum.gif',
        'palladium' => 'http://www.kitconet.com/images/live/s_palladium.gif',
        'rhodium'   => 'http://www.kitconet.com/images/live/s_rhodium.gif',
    ],
    // REQUIRED by Kitco's TOS — every fallback image must wrap this URL.
    'fallback_link' => 'http://www.kitco.com/connecting.html',

    // ── RSS sources for the news cron ────────────────────
    // The fetcher de-dupes by URL, so overlapping feeds (e.g. two outlets
    // reporting the same wire story) won't produce duplicate rows.
    //
    // Some publishers (Kitco, Mining.com) will 403 on the default PHP user
    // agent — the fetcher sets a browser-style UA to get past that.
    // If a feed still fails, check cron.log and swap in a new URL.
    'rss_feeds' => [
        // Core precious-metals news
        ['source' => 'Kitco',            'url' => 'https://www.kitco.com/news/category/mining/rss'],
        ['source' => 'Kitco Metals',     'url' => 'https://www.kitco.com/news/category/metal/rss'],
        ['source' => 'Mining.com',       'url' => 'https://www.mining.com/tag/precious-metals/feed/'],
        ['source' => 'Mining.com Gold',  'url' => 'https://www.mining.com/tag/gold/feed/'],
        ['source' => 'Northern Miner',   'url' => 'https://www.northernminer.com/feed/'],

        // Industry / mining
        ['source' => 'Mining Weekly',    'url' => 'https://www.miningweekly.com/topic/gold/rss'],
        ['source' => 'Intl Mining',      'url' => 'https://im-mining.com/feed/'],

        // Commentary / analysis
        ['source' => 'BullionStar',      'url' => 'https://bullionstar.com/blogs/feed/'],
        ['source' => 'GoldBroker',       'url' => 'https://goldbroker.com/news/rss-feed-40'],
        ['source' => 'Sprott Money',     'url' => 'https://sprottmoney.com/blog.rss'],
        ['source' => 'BullionVault',     'url' => 'https://www.bullionvault.com/gold-news/rss'],
        ['source' => 'JM Bullion',       'url' => 'https://www.jmbullion.com/blog/feed/'],
    ],
];
