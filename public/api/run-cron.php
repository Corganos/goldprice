<?php
/**
 * Manual cron trigger. Protected by a secret token so only you can fire it.
 * Call it via: https://goldprice.corgano.com/api/run-cron.php?job=news&key=YOUR_SECRET
 */
declare(strict_types=1);

// Change this to any long random string you like
$SECRET = 'ivancicg-goldprice-cron-2026-04';

if (($_GET['key'] ?? '') !== $SECRET) {
    http_response_code(403);
    die('forbidden');
}

$cronDirCandidates = [
    __DIR__ . '/../../../private/cron',
    __DIR__ . '/../../private/cron',
    // '/home/YOURUSER/private/cron',
];
$cronDir = null;
foreach ($cronDirCandidates as $candidate) {
    if (is_dir($candidate)) {
        $cronDir = $candidate;
        break;
    }
}
if (!$cronDir) {
    http_response_code(500);
    die('cron dir not found');
}

$job = $_GET['job'] ?? '';
$scripts = [
    'news'        => $cronDir . '/fetch-news.php',
    'tag'         => $cronDir . '/tag-news.php',
    'seasonality' => $cronDir . '/build-seasonality.php',
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