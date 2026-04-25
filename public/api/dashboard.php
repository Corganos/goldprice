<?php
/**
 * api/dashboard.php
 *
 * Single endpoint your dashboard's JS calls on refresh.
 * Merges: metals + FX (MetalpriceAPI) + stocks (Finnhub) + news (local SQLite).
 * Uses file caching so you stay well under your API plan's monthly quota.
 *
 * Returns on success:
 *   { ok: true, updated_at, metals, fx_table, stocks, news, kgx, warnings? }
 *
 * On total failure returns HTTP 500 with { ok: false, error }.
 * On partial failure returns HTTP 200 with whatever worked + a `warnings` array.
 */

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════
// CONFIG PATH — tries common cPanel subdomain layouts automatically.
// If neither works, add your absolute path as a third entry.
// ═══════════════════════════════════════════════════════════════════
$configCandidates = [
    __DIR__ . '/../../../private/config.php',  // subdomain-as-subfolder (cPanel default: public_html/goldprice/api/)
    __DIR__ . '/../../private/config.php',     // separate subdomain dir (goldprice.corgano.com/api/)
    // '/home/YOURUSER/private/config.php',    // ← add absolute path here if needed
];
$CONFIG = null;
foreach ($configCandidates as $__cfgPath) {
    if (is_file($__cfgPath)) { $CONFIG = require $__cfgPath; break; }
}
if (!$CONFIG) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['ok' => false, 'error' => 'config.php not found — check path candidates in api/dashboard.php']));
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=30');
header('Access-Control-Allow-Origin: *'); // lock down in production

// Optional ?bust=1 — wipes all cache files and forces fresh fetches.
// Useful when schema changes (new ticker groups, new fields) and cached
// data is stale or malformed. Protected by Anthropic "is this you" sanity
// (just a query param, but not widely known and doesn't expose any data).
if (!empty($_GET['bust']) && is_dir($CONFIG['cache_dir'])) {
    foreach (glob($CONFIG['cache_dir'] . '/*.json') ?: [] as $cacheFile) {
        @unlink($cacheFile);
    }
}

// ─── file cache helpers ────────────────────────────────────────────────
function cache_get(string $key, int $ttl, string $dir): ?array {
    $f = $dir . '/' . md5($key) . '.json';
    if (!is_file($f) || time() - filemtime($f) > $ttl) return null;
    $d = @json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function cache_set(string $key, array $data, string $dir): void {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/' . md5($key) . '.json', json_encode($data));
}

function load_seasonality_snapshot(string $path): ?array {
    if (!is_file($path)) return null;
    $seasonality = @json_decode((string)file_get_contents($path), true);
    return is_array($seasonality) ? $seasonality : null;
}

function fetch_seasonality(array $cfg): ?array {
    $seasonalityPath = rtrim($cfg['data_dir'], '/\\') . '/seasonality.json';
    $seasonality = load_seasonality_snapshot($seasonalityPath);
    if (!empty($seasonality['months'])) {
        return $seasonality;
    }

    $scriptCandidates = [
        __DIR__ . '/../../../private/cron/build-seasonality.php',
        __DIR__ . '/../../private/cron/build-seasonality.php',
    ];

    foreach ($scriptCandidates as $scriptPath) {
        if (!is_file($scriptPath)) {
            continue;
        }

        require_once $scriptPath;
        if (!function_exists('build_seasonality_snapshot')) {
            continue;
        }

        @set_time_limit(45);
        $result = build_seasonality_snapshot($cfg, null, ['delay_seconds' => 0]);
        if (!empty($result['ok'])) {
            $built = load_seasonality_snapshot($seasonalityPath);
            if (!empty($built['months'])) {
                return $built;
            }
            return is_array($result['payload'] ?? null) ? $result['payload'] : null;
        }
    }

    return $seasonality;
}

// ─── HTTP helper (curl with timeout + error tolerance) ─────────────────
function http_json(string $url, int $timeout = 8): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'GoldTerminal/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $d = @json_decode($body, true);
    return is_array($d) ? $d : null;
}

// ─── metals + FX via MetalpriceAPI ─────────────────────────────────────
function fetch_metals_and_fx(array $cfg): ?array {
    $cached = cache_get('metals_fx', $cfg['cache_metals'], $cfg['cache_dir']);
    if ($cached) return $cached;

    // build one comma-separated list of everything we want
    $metalCodes = ['XAU','XAG','XPT','XPD'];
    $fxCodes    = array_keys($cfg['fx_currencies']);
    $all        = array_unique(array_merge($metalCodes, array_filter($fxCodes, fn($c) => $c !== 'USD')));
    $syms       = implode(',', $all);
    $key        = urlencode($cfg['metalprice_key']);

    $latest    = http_json("https://api.metalpriceapi.com/v1/latest?api_key={$key}&base=USD&currencies={$syms}");
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $prev      = http_json("https://api.metalpriceapi.com/v1/{$yesterday}?api_key={$key}&base=USD&currencies={$syms}");

    if (!$latest || empty($latest['rates'])) return null;
    $prev = $prev['rates'] ?? $latest['rates']; // fall back to today if yesterday fails

    // MetalpriceAPI returns rates in one of two key formats depending on plan:
    //   (a) {"USDXAU": 0.000213}  — "XAU per 1 USD" (inverse, needs 1/x to get USD/oz)
    //   (b) {"XAU": 0.000213}     — same value, no base prefix
    // AND rates can be in one of two modes:
    //   (a) inverse: XAU ≈ 0.0002 (tiny fraction of ounce per dollar)
    //   (b) direct:  XAU ≈ 4700    (dollars per ounce, NO inversion needed)
    // We detect both automatically by probing the actual response.
    $rateLookup = function(string $code, array $rates): ?float {
        // Try prefixed key first, then unprefixed
        if (isset($rates['USD' . $code])) return (float)$rates['USD' . $code];
        if (isset($rates[$code]))         return (float)$rates[$code];
        return null;
    };

    // Detect the rate mode: if XAU rate is less than 1, it's inverse (fraction per USD).
    // If it's way bigger than 1 (like 4000+), it's already USD per ounce.
    $xauRate = $rateLookup('XAU', $latest['rates']);
    $isInverse = $xauRate !== null && $xauRate < 1;

    $priceOf = function(string $code, array $rates) use ($rateLookup, $isInverse): ?float {
        $r = $rateLookup($code, $rates);
        if ($r === null || $r <= 0) return null;
        return $isInverse ? (1 / $r) : $r;
    };
    // FX rate for fiat is always "CURRENCY per USD" regardless of mode, so no inversion.
    $fxRateOf = function(string $code, array $rates) use ($rateLookup): ?float {
        return $rateLookup($code, $rates);
    };

    $out = ['metals' => [], 'fx_table' => []];

    // ── metals ────────────────────────────────────────
    $nameMap = ['XAU' => 'gold', 'XAG' => 'silver', 'XPT' => 'platinum', 'XPD' => 'palladium'];
    foreach ($nameMap as $code => $name) {
        $p  = $priceOf($code, $latest['rates']);
        $pp = $priceOf($code, $prev);
        if ($p === null) continue;
        $chg    = $pp ? $p - $pp : 0;
        $chgPct = $pp ? ($chg / $pp) * 100 : 0;
        $out['metals'][$name] = [
            'price'      => round($p, 2),
            'change'     => round($chg, 2),
            'change_pct' => round($chgPct, 2),
        ];
    }

    // rhodium — manually maintained in a small JSON file
    if (is_file($cfg['rhodium_file'])) {
        $rh = json_decode((string)file_get_contents($cfg['rhodium_file']), true);
        if (is_array($rh) && isset($rh['price'])) {
            $out['metals']['rhodium'] = [
                'price'         => (float)$rh['price'],
                'change'        => 0,
                'change_pct'    => 0,
                'manual'        => true,
                'last_update'   => $rh['updated_at'] ?? null,
            ];
        }
    }

    // ── FX table: gold priced in each currency ──────
    $goldUsd     = $out['metals']['gold']['price'] ?? null;
    $goldUsdPrev = $priceOf('XAU', $prev);
    if ($goldUsd) {
        foreach ($cfg['fx_currencies'] as $code => $label) {
            if ($code === 'USD') {
                $fxR = 1.0;
                $price = $goldUsd;
                $prevPrice = $goldUsdPrev ?? $goldUsd;
            } else {
                $fxR       = $fxRateOf($code, $latest['rates']);
                $fxPrev    = $fxRateOf($code, $prev) ?? $fxR;
                if (!$fxR) continue;
                $price     = $goldUsd * $fxR;
                $prevPrice = ($goldUsdPrev ?? $goldUsd) * $fxPrev;
            }
            $chg    = $price - $prevPrice;
            $chgPct = $prevPrice > 0 ? ($chg / $prevPrice) * 100 : 0;
            $out['fx_table'][] = [
                'code'       => $code,
                'name'       => $label,
                'fx_rate'    => $code === 'USD' ? null : round($fxR, 4),
                'gold_price' => round($price, 2),
                'change'     => round($chg, 2),
                'change_pct' => round($chgPct, 2),
            ];
        }
    }

    cache_set('metals_fx', $out, $cfg['cache_dir']);
    return $out;
}

// ─── stocks via Finnhub ────────────────────────────────────────────────
//
// This fetcher has two key robustness properties:
//
//   1. PARALLEL FETCH — all 30+ Finnhub quote requests fire simultaneously
//      via curl_multi_exec, not serially. A serial loop with 34 symbols and
//      typical 300ms API latency would take ~10s, which risks hitting shared
//      hosting's PHP max_execution_time (often 30s) when combined with the
//      MetalpriceAPI + WordPress + SQLite work happening in the same request.
//      Parallel cuts this to ~1-2s total.
//
//   2. PRESERVE LAST-KNOWN-GOOD — if a symbol group comes back empty (e.g.,
//      Finnhub rate-limited us or had a blip), we DO NOT overwrite the
//      existing cached values. The old data stays visible rather than
//      vanishing. Only fresh successful responses replace cached values.
//      This matters because the cache is served to the frontend, and an
//      empty response would wipe the miners tables / indices / etc.
//
function fetch_stocks(array $cfg): array {
    $freshCache = cache_get('stocks', $cfg['cache_stocks'], $cfg['cache_dir']);
    if ($freshCache) return $freshCache;

    // For the "last known good" fallback we also read any existing stocks cache
    // file ignoring TTL. This is our safety net when the current fetch fails.
    $staleCache = cache_get_stale('stocks', $cfg['cache_dir']);
    $previous   = is_array($staleCache) ? $staleCache : [];

    $key = urlencode($cfg['finnhub_key']);

    // Build the full list of (group, symbol, url) tuples so curl_multi can
    // fire them all at once. Crypto uses the same /quote endpoint in most
    // cases — if it fails we silently drop that row (rare on Binance pairs).
    $jobs = [];  // array of ['group'=>str, 'symbol'=>str, 'url'=>str, 'is_crypto'=>bool]
    foreach ($cfg['tickers'] as $group => $symbols) {
        foreach ($symbols as $sym) {
            $jobs[] = [
                'group'     => $group,
                'symbol'    => $sym,
                'url'       => "https://finnhub.io/api/v1/quote?symbol=" . rawurlencode($sym) . "&token={$key}",
                'is_crypto' => false,
            ];
        }
    }
    if (!empty($cfg['crypto_tickers'])) {
        foreach ($cfg['crypto_tickers'] as $sym) {
            $jobs[] = [
                'group'     => 'crypto',
                'symbol'    => $sym,
                'url'       => "https://finnhub.io/api/v1/quote?symbol=" . rawurlencode($sym) . "&token={$key}",
                'is_crypto' => true,
            ];
        }
    }

    // Fire all requests in parallel with curl_multi.
    $multi = curl_multi_init();
    $handles = [];
    foreach ($jobs as $i => $job) {
        $ch = curl_init($job['url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);          // per-request cap
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GoldTerminal/1.0');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }
    // Drive the multi loop until all are done. Overall wall-clock cap is
    // roughly the slowest request (~5s), not the sum of them. We also add
    // a hard wall-clock cap of 12 seconds as belt-and-suspenders.
    $hardDeadline = microtime(true) + 12.0;
    $running = null;
    do {
        curl_multi_exec($multi, $running);
        curl_multi_select($multi, 0.5);
    } while ($running > 0 && microtime(true) < $hardDeadline);

    // Collect responses
    $out = [];
    foreach ($cfg['tickers'] as $group => $_) $out[$group] = [];
    $out['crypto'] = [];

    foreach ($jobs as $i => $job) {
        $ch   = $handles[$i];
        $body = curl_multi_getcontent($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        if ($code !== 200 || !$body) continue;
        $q = json_decode($body, true);
        if (!is_array($q) || !isset($q['c']) || $q['c'] == 0) continue;

        if ($job['is_crypto']) {
            $out['crypto'][] = [
                'symbol'     => $job['symbol'],
                'price'      => round((float)$q['c'], 2),
                'change'     => round((float)($q['d']  ?? 0), 2),
                'change_pct' => round((float)($q['dp'] ?? 0), 2),
            ];
        } else {
            $out[$job['group']][] = [
                'symbol'     => $job['symbol'],
                'price'      => round((float)$q['c'], 2),
                'change'     => round((float)($q['d']  ?? 0), 2),
                'change_pct' => round((float)($q['dp'] ?? 0), 2),
                'high'       => round((float)($q['h']  ?? 0), 2),
                'low'        => round((float)($q['l']  ?? 0), 2),
            ];
        }
    }
    curl_multi_close($multi);

    // ── PRESERVE LAST-KNOWN-GOOD ──
    // For any group that came back empty this cycle but had data last cycle,
    // keep the previous values. This stops a transient Finnhub blip from
    // wiping the frontend tables. A group only updates when we get fresh data.
    foreach ($out as $group => $rows) {
        if (empty($rows) && !empty($previous[$group])) {
            $out[$group] = $previous[$group];
        }
    }

    cache_set('stocks', $out, $cfg['cache_dir']);
    return $out;
}

// Read a cache file ignoring its TTL — used for last-known-good fallback.
// Matches the format of cache_get/cache_set (JSON-encoded, .json extension).
// Returns null if the file doesn't exist or can't be decoded.
function cache_get_stale(string $key, string $dir): ?array {
    $path = rtrim($dir, '/') . '/' . md5($key) . '.json';
    if (!is_file($path)) return null;
    $d = @json_decode((string)file_get_contents($path), true);
    return is_array($d) ? $d : null;
}

// ─── macro data (real indices, yields, commodities) via stooq.com ─────
//
// Finnhub's free tier doesn't cover indices (SPX, DJIA, NDX), commodity
// futures (WTI), or Treasury yields (^TNX). Without a real source we'd be
// forced to show ETF proxy prices (SPY=704, DIA=491, UUP=27) alongside
// labels that imply the underlying ("S&P 500", "Dow Jones", "US Dollar"),
// which is confusing at best.
//
// Tried Yahoo's v7 quote endpoint first — they block server-to-server
// requests from shared hosting IPs. Stooq.com is a long-running Polish
// financial data site with a free CSV API that has worked reliably for
// 20+ years. No auth, no cookies, no API key.
//
// We emit NORMALIZED symbols (SPX, DJIA, NDX, DXY, WTI, US10Y) so the
// frontend doesn't need to care which data source populated them. If we
// later swap stooq for something else, the frontend unchanged.
//
// CHANGE SEMANTICS: stooq's CSV gives us today's OHLC. We compute change
// as (close - open) / open, which is intraday change (where we are now
// vs where we opened). This differs from the traditional "vs prior close"
// change but is still real, meaningful data that updates through the day.
// Getting prior-close would require 2 stooq calls per symbol.
//
function fetch_macro(array $cfg): array {
    $cached = cache_get('macro', $cfg['cache_stocks'] ?? 180, $cfg['cache_dir']);
    if ($cached) return $cached;

    // stooq symbol (lowercase) → normalized output symbol + display + formatting
    $symbols = [
        '^spx' => ['out_sym' => 'SPX',   'name' => 'S&P 500',      'decimals' => 2],
        '^dji' => ['out_sym' => 'DJIA',  'name' => 'Dow Jones',    'decimals' => 0],
        '^ndx' => ['out_sym' => 'NDX',   'name' => 'Nasdaq 100',   'decimals' => 2],
        'dx.f' => ['out_sym' => 'DXY',   'name' => 'US Dollar',    'decimals' => 2],
        'cl.f' => ['out_sym' => 'WTI',   'name' => 'WTI Crude',    'decimals' => 2],
        '^tnx' => ['out_sym' => 'US10Y', 'name' => 'US 10Y Yield', 'decimals' => 2, 'suffix' => '%'],
    ];

    $list = implode(',', array_keys($symbols));
    // f=sd2t2ohlcv means: Symbol, Date(YYYY-MM-DD), Time, Open, High, Low, Close, Volume
    // h = include header row
    // e=csv = CSV output
    $url = 'https://stooq.com/q/l/?s=' . $list . '&f=sd2t2ohlcv&h&e=csv';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36');
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) {
        $stale = cache_get_stale('macro', $cfg['cache_dir']);
        return is_array($stale) ? $stale : [];
    }

    // Parse CSV response:
    //   Symbol,Date,Time,Open,High,Low,Close,Volume
    //   ^SPX,2026-04-21,22:00:04,7117.05,7147.52,7046.85,7064.01,0
    $lines = explode("\n", trim($body));
    if (count($lines) < 2) {
        $stale = cache_get_stale('macro', $cfg['cache_dir']);
        return is_array($stale) ? $stale : [];
    }
    array_shift($lines);  // discard header

    $out = [];
    foreach ($lines as $line) {
        $parts = str_getcsv($line);
        if (count($parts) < 7) continue;

        $sym_raw = strtolower(trim($parts[0]));
        if (!isset($symbols[$sym_raw])) continue;
        $meta = $symbols[$sym_raw];

        $open_str  = trim($parts[3]);
        $close_str = trim($parts[6]);

        // stooq returns "N/D" for cells with no data (e.g., overnight, weekend)
        if ($open_str === 'N/D' || $close_str === 'N/D') continue;
        if (!is_numeric($open_str) || !is_numeric($close_str)) continue;

        $open  = (float)$open_str;
        $close = (float)$close_str;
        $change     = $close - $open;
        $change_pct = $open > 0 ? ($change / $open * 100) : 0;

        $out[] = [
            'symbol'     => $meta['out_sym'],
            'display'    => $meta['name'],
            'price'      => round($close, $meta['decimals']),
            'change'     => round($change, 2),
            'change_pct' => round($change_pct, 2),
            'suffix'     => $meta['suffix'] ?? '',
        ];
    }

    if (empty($out)) {
        $stale = cache_get_stale('macro', $cfg['cache_dir']);
        if (is_array($stale) && !empty($stale)) return $stale;
    }

    cache_set('macro', $out, $cfg['cache_dir']);
    return $out;
}

// ─── news from local SQLite (populated by cron) ────────────────────────
function fetch_news(array $cfg, int $limit = 20): array {
    $cached = cache_get('news_' . $limit, $cfg['cache_news'], $cfg['cache_dir']);
    if ($cached) return $cached;

    if (!is_file($cfg['news_db'])) return [];
    try {
        $db = new PDO('sqlite:' . $cfg['news_db']);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->prepare('SELECT source, headline, url, published_at, tag
                              FROM news ORDER BY published_at DESC LIMIT :n');
        $stmt->bindValue(':n', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }

    cache_set('news_' . $limit, $items, $cfg['cache_dir']);
    return $items;
}

// ─── KGX-style attribution: how much of the move is USD vs buyers ──────
function compute_kgx(array $metals_fx): array {
    $goldChg    = $metals_fx['metals']['gold']['change']     ?? 0;
    $goldChgPct = $metals_fx['metals']['gold']['change_pct'] ?? 0;

    // DXY basket weights
    $weights = ['EUR' => 0.576, 'JPY' => 0.136, 'GBP' => 0.119,
                'CAD' => 0.091, 'CHF' => 0.036];
    $dxyDelta = 0; $wSum = 0;
    foreach ($metals_fx['fx_table'] as $row) {
        if (!isset($weights[$row['code']])) continue;
        // if gold moved more in EUR than in USD, USD weakened → boost to gold
        $diff     = $row['change_pct'] - $goldChgPct;
        $dxyDelta += $diff * $weights[$row['code']];
        $wSum     += $weights[$row['code']];
    }
    $usdPctOfMove   = $wSum > 0 ? $dxyDelta / $wSum : 0;
    $usdDollar      = ($metals_fx['metals']['gold']['price'] ?? 0) * $usdPctOfMove / 100;
    $buyerDollar    = $goldChg - $usdDollar;
    $total          = abs($usdDollar) + abs($buyerDollar);
    $usdPct         = $total > 0 ? abs($usdDollar)   / $total * 100 : 0;
    $buyerPct       = $total > 0 ? abs($buyerDollar) / $total * 100 : 0;

    // plain-English summary for the UI
    $summary = sprintf(
        'Roughly %d%% of today\'s move is coming from %s rather than %s.',
        round($buyerPct),
        'actual buying pressure',
        'dollar movement'
    );

    return [
        'total_change' => round($goldChg,     2),
        'usd_impact'   => round($usdDollar,   2),
        'buyer_impact' => round($buyerDollar, 2),
        'usd_pct'      => round($usdPct,      0),
        'buyer_pct'    => round($buyerPct,    0),
        'summary'      => $summary,
    ];
}

// ─── Corgano editorial articles via WordPress REST API ────────────────
function fetch_corgano_articles(array $cfg): array {
    $cached = cache_get('corgano_articles', $cfg['cache_articles'] ?? 900, $cfg['cache_dir']);
    if ($cached !== null) return $cached;

    $wpBase = rtrim($cfg['corgano_wp_url'] ?? 'https://corgano.com', '/');
    $perPage = (int)($cfg['corgano_per_page'] ?? 10);
    $tagSlugs = array_filter(array_map('trim', (array)($cfg['corgano_tag_slugs'] ?? [])));
    $catSlug = trim($cfg['corgano_category_slug'] ?? '');

    // Build the query. `_embed=1` tells WordPress to include author, featured
    // media, and term data inline — saves us separate N+1 requests.
    $query = [
        '_embed'   => '1',
        'per_page' => $perPage,
        'status'   => 'publish',
    ];

    // Primary filter: resolve tag slugs → tag IDs. WP REST needs numeric IDs
    // in the `tags` parameter, and multiple IDs comma-separated = OR logic
    // (post matches ANY of these tags), which is what we want for topic
    // inclusion. Each slug's ID is cached for 24h since tags rarely change.
    if (!empty($tagSlugs)) {
        $tagIds = [];
        foreach ($tagSlugs as $slug) {
            if ($slug === '') continue;
            $slugKey = 'corgano_tag_' . $slug;
            $cachedTag = cache_get($slugKey, 86400, $cfg['cache_dir']);
            if (is_array($cachedTag) && isset($cachedTag['id'])) {
                $tagIds[] = (int)$cachedTag['id'];
                continue;
            }
            $resp = http_json("{$wpBase}/wp-json/wp/v2/tags?slug=" . urlencode($slug), 6);
            if (is_array($resp) && !empty($resp[0]['id'])) {
                $id = (int)$resp[0]['id'];
                $tagIds[] = $id;
                cache_set($slugKey, ['id' => $id], $cfg['cache_dir']);
            }
            // silently skip slugs that don't resolve — a typo shouldn't break the whole feed
        }
        if (!empty($tagIds)) {
            $query['tags'] = implode(',', $tagIds);
        } else {
            // No tag slugs resolved — rather than fall back to "all posts"
            // (which would dump arbitrary non-PM content onto this subdomain),
            // return empty and let the demo content stay visible.
            cache_set('corgano_articles', [], $cfg['cache_dir']);
            return [];
        }
    }

    // Optional secondary AND-filter: category slug. When combined with the
    // tag filter above, WP REST requires posts to match BOTH the category
    // AND at least one of the tags. Useful if you want e.g. only posts
    // that are tagged 'gold' AND categorized as 'Commentary'.
    if ($catSlug !== '') {
        $catCached = cache_get('corgano_cat_' . $catSlug, 86400, $cfg['cache_dir']);
        $catId = is_array($catCached) && isset($catCached['id']) ? $catCached['id'] : null;
        if ($catId === null) {
            $catResp = http_json("{$wpBase}/wp-json/wp/v2/categories?slug=" . urlencode($catSlug), 6);
            if (is_array($catResp) && !empty($catResp[0]['id'])) {
                $catId = (int)$catResp[0]['id'];
                cache_set('corgano_cat_' . $catSlug, ['id' => $catId], $cfg['cache_dir']);
            }
        }
        if ($catId) $query['categories'] = $catId;
    }

    $url = "{$wpBase}/wp-json/wp/v2/posts?" . http_build_query($query);
    $posts = http_json($url, 10);
    if (!is_array($posts)) {
        // WP REST API unreachable — return empty, cache briefly to avoid retry-spam
        cache_set('corgano_articles', [], $cfg['cache_dir']);
        return [];
    }

    $clean = function(string $html): string {
        $s = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    };

    $out = [];
    foreach ($posts as $p) {
        if (!is_array($p)) continue;

        $author = 'Corgano';
        if (!empty($p['_embedded']['author'][0]['name'])) {
            $author = (string)$p['_embedded']['author'][0]['name'];
        }

        $image = null;
        if (!empty($p['_embedded']['wp:featuredmedia'][0]['source_url'])) {
            $image = (string)$p['_embedded']['wp:featuredmedia'][0]['source_url'];
        }

        // wp:term is an array-of-arrays: [0] = categories, [1] = tags
        $category = null;
        if (!empty($p['_embedded']['wp:term'][0][0]['name'])) {
            $category = (string)$p['_embedded']['wp:term'][0][0]['name'];
        }

        // read-time estimate from full content word count
        $content = (string)($p['content']['rendered'] ?? '');
        $wordCount = str_word_count($clean($content));
        $readMin = max(1, (int)round($wordCount / 200)); // 200 wpm

        $excerpt = $clean((string)($p['excerpt']['rendered'] ?? ''));
        if (mb_strlen($excerpt) > 220) {
            $excerpt = mb_substr($excerpt, 0, 217) . '…';
        }

        $out[] = [
            'id'       => (int)($p['id'] ?? 0),
            'title'    => $clean((string)($p['title']['rendered'] ?? '')),
            'excerpt'  => $excerpt,
            'url'      => (string)($p['link'] ?? ''),
            'date'     => (string)($p['date_gmt'] ?? $p['date'] ?? ''),
            'author'   => $author,
            'category' => $category,
            'image'    => $image,
            'read_min' => $readMin,
        ];
    }

    cache_set('corgano_articles', $out, $cfg['cache_dir']);
    return $out;
}

// ─── assemble the payload ──────────────────────────────────────────────
$warnings        = [];
$fallback_active = false;

$metals_fx = fetch_metals_and_fx($CONFIG);

if (!$metals_fx) {
    // Metals feed is the anchor — but instead of 503'ing the whole endpoint,
    // flip to Kitco fallback mode. Dashboard stays responsive; users see
    // hotlinked Kitco charts instead of live SVG charts until we recover.
    $fallback_active = true;
    $warnings[]      = 'Metals feed unavailable — Kitco fallback charts active.';

    $metals_fx = [
        'metals' => [
            'gold'      => ['price' => null, 'change' => null, 'change_pct' => null, 'status' => 'fallback'],
            'silver'    => ['price' => null, 'change' => null, 'change_pct' => null, 'status' => 'fallback'],
            'platinum'  => ['price' => null, 'change' => null, 'change_pct' => null, 'status' => 'fallback'],
            'palladium' => ['price' => null, 'change' => null, 'change_pct' => null, 'status' => 'fallback'],
            'rhodium'   => ['price' => null, 'change' => null, 'change_pct' => null, 'status' => 'fallback'],
        ],
        'fx_table' => [], // no FX table in fallback mode
    ];
} else {
    // mark every returned metal as live
    foreach ($metals_fx['metals'] as $name => &$m) {
        if (($m['status'] ?? null) !== 'manual') {
            $m['status'] = 'live';
        }
    }
    unset($m);
}

$stocks = fetch_stocks($CONFIG);
if (empty($stocks['miners_gold']) && empty($stocks['miners_silver'])) {
    $warnings[] = 'Stock feed partially unavailable.';
}

// Stooq: real indices (SPX/DJIA/NDX), DXY, WTI, US10Y yield.
// This replaces the Finnhub ETF proxies (SPY/DIA/QQQ/UUP/USO/IEF) which
// were honest but confusing (SPY≈$704, labeled "S&P 500" implies ~5,682).
$stocks['macro'] = fetch_macro($CONFIG);

// Stooq doesn't serve crypto, so we graft BTC onto the macro array from the
// Finnhub crypto feed. This gives the frontend a single uniform list for
// the Correlated Markets panel, with consistent schema (display/symbol/suffix).
if (!empty($stocks['crypto'])) {
    foreach ($stocks['crypto'] as $c) {
        $sym = $c['symbol'] ?? '';
        if (stripos($sym, 'BTC') !== false && isset($c['price'])) {
            $stocks['macro'][] = [
                'symbol'     => 'BTC',
                'display'    => 'Bitcoin',
                'price'      => $c['price'],
                'change'     => $c['change']     ?? null,
                'change_pct' => $c['change_pct'] ?? null,
                'suffix'     => '',
            ];
            break;
        }
    }
}

if (empty($stocks['macro'])) {
    $warnings[] = 'Macro market feed unavailable — Correlated Markets may be empty.';
}

$news = fetch_news($CONFIG, 20);
if (empty($news)) {
    $warnings[] = 'News database empty — has the cron run yet?';
}

$corgano_articles = fetch_corgano_articles($CONFIG);
if (empty($corgano_articles)) {
    $warnings[] = 'Corgano WordPress feed empty or unreachable — commentary panel will show fallback content.';
}

$seasonality = fetch_seasonality($CONFIG);
if (empty($seasonality['months'])) {
    $warnings[] = 'Seasonality snapshot missing — has the monthly build-seasonality cron run yet?';
}

// KGX needs live metals+fx — skip entirely in fallback mode
$kgx = $fallback_active ? null : compute_kgx($metals_fx);

$payload = [
    'ok'               => true,
    'updated_at'       => gmdate('c'),
    'metals'           => $metals_fx['metals'],
    'fx_table'         => $metals_fx['fx_table'],
    'stocks'           => $stocks,
    'news'             => $news,
    'corgano_articles' => $corgano_articles,
    'seasonality'      => $seasonality,
    'kgx'              => $kgx,
    'fallback_active'  => $fallback_active,
    'fallback_charts'  => $CONFIG['fallback_charts'] ?? [],
    'fallback_link'    => $CONFIG['fallback_link']   ?? 'http://www.kitco.com/connecting.html',
];
if ($warnings) $payload['warnings'] = $warnings;

echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
