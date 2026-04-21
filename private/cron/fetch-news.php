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

foreach ($feeds as $feed) {
    $ctx  = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'GoldTerminal/1.0']]);
    $xml  = @file_get_contents($feed['url'], false, $ctx);
    if (!$xml) { $errored++; continue; }

    libxml_use_internal_errors(true);
    $doc = @simplexml_load_string($xml);
    if (!$doc) { $errored++; continue; }

    // handle both RSS 2.0 (<channel><item>) and Atom (<entry>) layouts
    $items = $doc->channel->item ?? $doc->entry ?? [];

    foreach ($items as $item) {
        $title = trim((string)($item->title ?? ''));
        // atom feeds put link in an attribute, RSS in the node body
        $link  = (string)($item->link['href'] ?? $item->link ?? '');
        $link  = trim($link);
        $pub   = trim((string)($item->pubDate ?? $item->published ?? $item->updated ?? ''));

        if (!$title || !$link) continue;

        $pubIso = $pub ? date('c', strtotime($pub) ?: time()) : gmdate('c');

        try {
            $insert->execute([
                ':source'       => $feed['source'],
                ':headline'     => $title,
                ':url'          => $link,
                ':published_at' => $pubIso,
                ':fetched_at'   => gmdate('c'),
            ]);
            if ($insert->rowCount() > 0) $inserted++; else $skipped++;
        } catch (Throwable $e) {
            $errored++;
        }
    }
}

// housekeeping: keep the most recent 500 items, drop the rest
$db->exec('DELETE FROM news WHERE id NOT IN
           (SELECT id FROM news ORDER BY published_at DESC LIMIT 500)');

echo "[" . date('c') . "] fetch-news: +{$inserted} inserted, {$skipped} duplicate, {$errored} errors\n";
