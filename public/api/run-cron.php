<?php
/**
 * Manual cron trigger. Protected by a secret token so only you can fire it.
 * Call it via: https://goldprice.corgano.com/api/run-cron.php?job=news&key=YOUR_SECRET
 */
declare(strict_types=1);

// Change this to any long random string you like
$SECRET = 'your-random-secret-change-me';

if (($_GET['key'] ?? '') !== $SECRET) {
    http_response_code(403);
    die('forbidden');
}

$job = $_GET['job'] ?? '';
$scripts = [
    'news' => '/home8/ivancicg/private/cron/fetch-news.php',
    'tag'  => '/home8/ivancicg/private/cron/tag-news.php',
];
if (!isset($scripts[$job]) || !is_file($scripts[$job])) {
    die('unknown job');
}

header('Content-Type: text/plain');
echo "Running {$job}...\n\n";
ob_flush(); flush();

// Include the cron script directly in this request
require $scripts[$job];

echo "\n[done]\n";