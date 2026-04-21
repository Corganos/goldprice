<?php
/**
 * cron/tag-news.php
 *
 * OPTIONAL. Sends untagged headlines to GPT-4o-mini for classification.
 * Costs: ~$0.01–$2/month depending on volume. Skip this script entirely
 * if you don't want to spend anything — the dashboard works fine without tags.
 *
 * Example crontab (runs 1 min after the fetch cron):
 *   1‎,11,21,31,41,51 * * * * /usr/local/bin/php /home/youruser/private/cron/tag-news.php
 */

declare(strict_types=1);
$CONFIG = require __DIR__ . '/../config.php';

if (empty($CONFIG['openai_key']) || $CONFIG['openai_key'] === 'PUT_YOUR_OPENAI_KEY_HERE') {
    fwrite(STDERR, "OpenAI key not set — skipping tagger.\n");
    exit(0);
}

$db = new PDO('sqlite:' . $CONFIG['news_db']);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// one batch at a time — even at 50/run we stay trivially cheap
$rows = $db->query('SELECT id, headline FROM news
                    WHERE tag IS NULL
                    ORDER BY id DESC
                    LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) exit(0);

$update = $db->prepare('UPDATE news SET tag = :tag WHERE id = :id');

$allowedTags = [
    'USD',    // dollar / DXY driven
    'CPI',    // inflation / macro data
    'CB',     // central banks
    'M&A',    // mergers, acquisitions, stakes
    'PHYS',   // physical demand / bullion
    'MINE',   // mining operations / production
    'ETF',    // fund flows
    'POS',    // positioning / COT / futures
    'FCAST',  // forecasts, price targets
    'RETAIL', // retail demand, sentiment
    'EM',     // emerging markets (India, Turkey, China demand)
    'RSCH',   // research / analysis / reports
    'AG',     // silver-specific
    'PT',     // platinum-specific
    'PD',     // palladium-specific
    'REG',    // regulation / policy / tariffs
];

$system = "You classify precious-metals financial news headlines into exactly ONE tag.
Allowed tags: " . implode(', ', $allowedTags) . ".
Respond with ONLY the tag, nothing else. No punctuation, no explanation.";

$tagged = 0;
$failed = 0;

foreach ($rows as $r) {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $CONFIG['openai_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'model'       => 'gpt-4o-mini',
            'temperature' => 0,
            'max_tokens'  => 8,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $r['headline']],
            ],
        ]),
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) { $failed++; continue; }

    $data = json_decode($body, true);
    $raw  = trim($data['choices'][0]['message']['content'] ?? '');
    $tag  = preg_replace('/[^A-Z&]/', '', strtoupper($raw));

    if (in_array($tag, $allowedTags, true)) {
        $update->execute([':tag' => $tag, ':id' => $r['id']]);
        $tagged++;
    } else {
        // mark as processed so we don't burn tokens retrying — use a sentinel
        $update->execute([':tag' => 'MISC', ':id' => $r['id']]);
        $failed++;
    }
}

echo "[" . date('c') . "] tag-news: {$tagged} tagged, {$failed} failed\n";
