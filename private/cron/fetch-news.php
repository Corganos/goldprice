<?php
/**
 * cron/fetch-news.php
 *
 * Harvests RSS feeds, de-dupes, stores in local SQLite.
 * Runs every ~10 minutes via cron.
 *
 * Example crontab line (adjust path to your PHP CLI binary):
 *   *‎/10 * * * * /usr/local/bin/php /home/youruser/private/cron/fetch-news.php > /dev/null 2>&1
 *
 * On cPanel: go to Cron Jobs → add the command above with "Every 10 minutes".
 */

declare(strict_types=1);
$CONFIG = require __DIR__ . '/../config.php';

$feeds = $CONFIG['rss_feeds'];
if (!is_dir($CONFIG['data_dir'])) mkdir($CONFIG['data_dir'], 0755, true);

$db = new PDO('sqlite:' . $CONFIG['news_db']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS news (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    source       TEXT NOT NULL,
    headline     TEXT NOT NULL,
    url          TEXT NOT NULL UNIQUE,
    published_at TEXT NOT NULL,
    tag          TEXT,
    fetched_at   TEXT NOT NULL
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_published ON news (published_at DESC)");

$insert = $db->prepare(
    'INSERT OR IGNORE INTO news (source, headline, url, published_at, fetched_at)
     VALUES (:source, :headline, :url, :published_at, :fetched_at)'
);

$inserted = 0;
$skipped  = 0;
$errored  = 0;
$perFeedLog = [];

// Many publishers (Kitco, Mining.com) 403 on unknown/PHP user-agents.
// Use a real-browser UA — their WAFs accept this for RSS access.
$userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
           . '(KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';

foreach ($feeds as $feed) {
    $feedInserted = 0; $feedErrored = 0;
    $ctx  = stream_context_create([
        'http' => [
            'timeout'       => 10,
            'user_agent'    => $userAgent,
            'header'        => "Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8\r\n"
                             . "Accept-Language: en-US,en;q=0.9\r\n",
            'follow_location' => 1,
            'max_redirects'   => 3,
            'ignore_errors'   => true,  // so we can see 4xx bodies if useful
        ],
        'https' => [
            'timeout'       => 10,
            'user_agent'    => $userAgent,
            'header'        => "Accept: application/rss+xml, application/atom+xml, application/xml;q=0.9, */*;q=0.8\r\n"
                             . "Accept-Language: en-US,en;q=0.9\r\n",
            'follow_location' => 1,
            'max_redirects'   => 3,
            'ignore_errors'   => true,
        ],
    ]);
    $xml = @file_get_contents($feed['url'], false, $ctx);
    if (!$xml) {
        $perFeedLog[] = "FAIL  {$feed['source']}: no response";
        $errored++; continue;
    }
    // check HTTP response code from $http_response_header (populated by stream wrapper)
    $httpStatus = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) $httpStatus = (int)$m[1];
        }
    }
    if ($httpStatus >= 400) {
        $perFeedLog[] = "FAIL  {$feed['source']}: HTTP {$httpStatus}";
        $errored++; continue;
    }

    libxml_use_internal_errors(true);
    $doc = @simplexml_load_string($xml);
    if (!$doc) {
        $perFeedLog[] = "FAIL  {$feed['source']}: invalid XML";
        $errored++; continue;
    }

    // handle both RSS 2.0 (<channel><item>) and Atom (<entry>) layouts
    $items = $doc->channel->item ?? $doc->entry ?? [];

    foreach ($items as $item) {
        $title = trim((string)($item->title ?? ''));
        // atom feeds put link in an attribute, RSS in the node body
        $link  = (string)($item->link['href'] ?? $item->link ?? '');
        $link  = trim($link);
        $pub   = trim((string)($item->pubDate ?? $item->published ?? $item->updated ?? ''));

        if (!$title || !$link) continue;

        // Google News: each <item> has <source> with the real publisher name,
        // and the <title> ends in " - Publisher". Extract the real publisher
        // and clean up the title so we show "Reuters" not "Google News · Gold".
        $displaySource = $feed['source'];
        if (strpos($feed['url'], 'news.google.com') !== false) {
            // Try <source> element first
            if (isset($item->source) && (string)$item->source !== '') {
                $displaySource = trim((string)$item->source);
            }
            // Strip the " - Publisher" suffix that Google News appends
            // Handle both " - " and "\u00a0-\u00a0" separator variants
            if (preg_match('/^(.+?)\s+[-–—]\s+([^-–—]+?)$/u', $title, $m)) {
                $title = trim($m[1]);
                if ($displaySource === $feed['source']) {
                    $displaySource = trim($m[2]);  // fallback if <source> was missing
                }
            }
        }

        $pubIso = $pub ? date('c', strtotime($pub) ?: time()) : gmdate('c');

        try {
            $insert->execute([
                ':source'       => $displaySource,
                ':headline'     => $title,
                ':url'          => $link,
                ':published_at' => $pubIso,
                ':fetched_at'   => gmdate('c'),
            ]);
            if ($insert->rowCount() > 0) { $inserted++; $feedInserted++; } else $skipped++;
        } catch (Throwable $e) {
            $errored++; $feedErrored++;
        }
    }
    $perFeedLog[] = "OK    {$feed['source']}: +{$feedInserted} new" . ($feedErrored ? " ({$feedErrored} errors)" : '');
}

// housekeeping: keep the most recent 500 items, drop the rest
$db->exec('DELETE FROM news WHERE id NOT IN
           (SELECT id FROM news ORDER BY published_at DESC LIMIT 500)');

echo "[" . date('c') . "] fetch-news: +{$inserted} inserted, {$skipped} duplicate, {$errored} errors\n";
foreach ($perFeedLog as $line) echo "  {$line}\n";
