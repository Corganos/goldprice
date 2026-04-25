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
    'cache_stocks'   => 180,  // 3 min — reduces Finnhub API pressure; stocks don't tick that fast
    'cache_news'     => 300,

    // ── which tickers to fetch from Finnhub ──────────────
    'tickers' => [
        // Individual gold miners (displayed in TOP GOLD MINERS table)
        'miners_gold'     => ['NEM','GOLD','AEM','FNV','WPM','KGC','AU','GFI'],

        // Individual silver miners (displayed in TOP SILVER MINERS table)
        'miners_silver'   => ['PAAS','AG','HL','SVM','FSM','EXK','MAG','SSRM'],

        // Mining-industry ETFs (displayed in MINING INDICES panel).
        // Core set — these 5 cover seniors, juniors, silver-miners, silver-juniors,
        // and royalty/streaming. Drops the 3 Sprott variants (SGDM/SGDJ/RING)
        // to keep Finnhub call count manageable on shared hosting.
        'mining_indices'  => ['GDX','GDXJ','SIL','SILJ','GOAU'],

        // Correlated macro markets (displayed in CORRELATED MARKETS panel).
        // DIA→DJIA, SPY→SPX, QQQ→NASDAQ, UUP→DXY (dollar), USO→WTI (oil),
        // IEF→US10Y (inverse bond proxy).
        'correlated'      => ['SPY','DIA','QQQ','UUP','USO','IEF'],
    ],

    // Crypto tickers — Finnhub accepts these via /quote with BINANCE: prefix
    'crypto_tickers'      => ['BINANCE:BTCUSDT'],

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
    // We use Google News RSS as the primary aggregator. Major publishers
    // (Kitco, Mining.com, Reuters, Bloomberg) have made direct RSS access
    // unreliable — either killing feeds outright or putting them behind
    // Cloudflare. Google News bypasses both problems: it returns a real
    // feed from dozens of publishers per query, no auth, no rate limit.
    //
    // Each entry below is a Google News search. URL format:
    //   https://news.google.com/rss/search?q=QUERY&hl=en-US&gl=US&ceid=US:en
    //
    // Tips:
    //   - `when:3d` restricts to last 3 days
    //   - `when:1d` for daily-briefing feeds
    //   - Use OR for broader topics, AND for narrower ones
    //   - URL-encode spaces as + and double-quotes as %22
    //
    // The fetcher de-dupes by URL, so overlapping queries won't produce dupes.
    // One Google News URL typically returns 20–100 items, so 4–6 queries
    // here produce plenty of diverse headlines.
    'rss_feeds' => [
        // Primary: broad precious-metals aggregation
        ['source' => 'Google News · Gold',
         'url'    => 'https://news.google.com/rss/search?q=gold+price+OR+%22gold+market%22+when:3d&hl=en-US&gl=US&ceid=US:en'],
        ['source' => 'Google News · Silver',
         'url'    => 'https://news.google.com/rss/search?q=silver+price+OR+%22silver+market%22+when:3d&hl=en-US&gl=US&ceid=US:en'],
        ['source' => 'Google News · Central Banks',
         'url'    => 'https://news.google.com/rss/search?q=%22central+bank%22+gold+buying+OR+reserves+when:7d&hl=en-US&gl=US&ceid=US:en'],
        ['source' => 'Google News · Mining',
         'url'    => 'https://news.google.com/rss/search?q=%22gold+mining%22+OR+%22silver+mining%22+when:3d&hl=en-US&gl=US&ceid=US:en'],
        ['source' => 'Google News · Macro',
         'url'    => 'https://news.google.com/rss/search?q=%22gold%22+Fed+OR+inflation+OR+dollar+when:2d&hl=en-US&gl=US&ceid=US:en'],

        // Secondary direct feeds that are known-working from our testing:
        ['source' => 'Northern Miner',
         'url'    => 'https://www.northernminer.com/feed/'],
        ['source' => 'Intl Mining',
         'url'    => 'https://im-mining.com/feed/'],
    ],
];
