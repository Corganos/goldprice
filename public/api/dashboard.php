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

    // helper: MetalpriceAPI returns USDXAU as "XAU per 1 USD" (tiny number).
    // 1 oz gold in USD = 1 / USDXAU
    $priceOf = function(string $code, array $rates): ?float {
        $k = 'USD' . $code;
        return isset($rates[$k]) && $rates[$k] > 0 ? 1 / $rates[$k] : null;
    };
    $fxRateOf = function(string $code, array $rates): ?float {
        return $rates['USD' . $code] ?? null;
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
function fetch_stocks(array $cfg): array {
    $cached = cache_get('stocks', $cfg['cache_stocks'], $cfg['cache_dir']);
    if ($cached) return $cached;

    $key = urlencode($cfg['finnhub_key']);
    $out = [];
    foreach ($cfg['tickers'] as $group => $symbols) {
        $out[$group] = [];
        foreach ($symbols as $sym) {
            $q = http_json("https://finnhub.io/api/v1/quote?symbol={$sym}&token={$key}", 4);
            if (!$q || !isset($q['c']) || $q['c'] == 0) continue;
            $out[$group][] = [
                'symbol'     => $sym,
                'price'      => round((float)$q['c'], 2),
                'change'     => round((float)($q['d']  ?? 0), 2),
                'change_pct' => round((float)($q['dp'] ?? 0), 2),
                'high'       => round((float)($q['h']  ?? 0), 2),
                'low'        => round((float)($q['l']  ?? 0), 2),
            ];
            usleep(60000); // ~60ms between calls — stay comfortably under 60/min free tier
        }
    }
    cache_set('stocks', $out, $cfg['cache_dir']);
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

$news = fetch_news($CONFIG, 20);
if (empty($news)) {
    $warnings[] = 'News database empty — has the cron run yet?';
}

$corgano_articles = fetch_corgano_articles($CONFIG);
if (empty($corgano_articles)) {
    $warnings[] = 'Corgano WordPress feed empty or unreachable — commentary panel will show fallback content.';
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
    'kgx'              => $kgx,
    'fallback_active'  => $fallback_active,
    'fallback_charts'  => $CONFIG['fallback_charts'] ?? [],
    'fallback_link'    => $CONFIG['fallback_link']   ?? 'http://www.kitco.com/connecting.html',
];
if ($warnings) $payload['warnings'] = $warnings;

echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
