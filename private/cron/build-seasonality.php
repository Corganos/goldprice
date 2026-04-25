<?php
/**
 * cron/build-seasonality.php
 *
 * Builds a static 20-year monthly seasonality snapshot for gold.
 * Suggested cron:
 *   0 3 1 * * /usr/bin/php /home/YOURUSER/private/cron/build-seasonality.php >> /home/YOURUSER/private/cron.log 2>&1
 */

declare(strict_types=1);

$CONFIG = require __DIR__ . '/../config.php';

if (!is_dir($CONFIG['data_dir'])) {
    mkdir($CONFIG['data_dir'], 0755, true);
}

function http_json(string $url, int $timeout = 20): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'GoldTerminal/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function rate_lookup(string $code, array $rates): ?float {
    if (isset($rates['USD' . $code])) {
        return (float)$rates['USD' . $code];
    }
    if (isset($rates[$code])) {
        return (float)$rates[$code];
    }
    return null;
}

function extract_daily_gold_prices(array $timeframeRates): array {
    $sample = null;
    foreach ($timeframeRates as $rates) {
        if (!is_array($rates)) {
            continue;
        }
        $sample = rate_lookup('XAU', $rates);
        if ($sample !== null && $sample > 0) {
            break;
        }
    }

    if ($sample === null || $sample <= 0) {
        return [];
    }

    $isInverse = $sample < 1;
    $daily = [];
    foreach ($timeframeRates as $date => $rates) {
        if (!is_array($rates)) {
            continue;
        }
        $xau = rate_lookup('XAU', $rates);
        if ($xau === null || $xau <= 0) {
            continue;
        }
        $daily[$date] = $isInverse ? (1 / $xau) : $xau;
    }

    ksort($daily);
    return $daily;
}

$tz = new DateTimeZone('UTC');
$currentMonthStart = new DateTimeImmutable('first day of this month 00:00:00', $tz);
$lastCompleteMonthStart = $currentMonthStart->modify('-1 month');
$windowStart = $currentMonthStart->modify('-20 years');
$baselineStart = $windowStart->modify('-1 month');
$windowEnd = $currentMonthStart->modify('-1 day');

$apiKey = urlencode($CONFIG['metalprice_key']);
$allDaily = [];

$startYear = (int)$baselineStart->format('Y');
$endYear = (int)$windowEnd->format('Y');
for ($year = $startYear; $year <= $endYear; $year++) {
    $rangeStart = new DateTimeImmutable($year . '-01-01', $tz);
    $rangeEnd = new DateTimeImmutable($year . '-12-31', $tz);

    if ($rangeStart < $baselineStart) {
        $rangeStart = $baselineStart;
    }
    if ($rangeEnd > $windowEnd) {
        $rangeEnd = $windowEnd;
    }
    if ($rangeStart > $rangeEnd) {
        continue;
    }

    $url = 'https://api.metalpriceapi.com/v1/timeframe'
         . '?api_key=' . $apiKey
         . '&base=USD&currencies=XAU'
         . '&start_date=' . $rangeStart->format('Y-m-d')
         . '&end_date=' . $rangeEnd->format('Y-m-d');

    $data = http_json($url);
    if (empty($data['rates']) || !is_array($data['rates'])) {
        fwrite(STDERR, 'seasonality: missing rates for ' . $rangeStart->format('Y-m-d') . ' to ' . $rangeEnd->format('Y-m-d') . PHP_EOL);
        if ($year < $endYear) {
            sleep(1);
        }
        continue;
    }

    foreach (extract_daily_gold_prices($data['rates']) as $date => $price) {
        $allDaily[$date] = $price;
    }

    if ($year < $endYear) {
        sleep(1);
    }
}

ksort($allDaily);

$monthEndPrices = [];
foreach ($allDaily as $date => $price) {
    $monthEndPrices[substr($date, 0, 7)] = $price;
}

$monthlyReturns = array_fill(1, 12, []);
$cursor = $windowStart;
while ($cursor <= $lastCompleteMonthStart) {
    $currentYm = $cursor->format('Y-m');
    $prevYm = $cursor->modify('-1 month')->format('Y-m');

    if (isset($monthEndPrices[$currentYm], $monthEndPrices[$prevYm]) && $monthEndPrices[$prevYm] > 0) {
        $ret = (($monthEndPrices[$currentYm] - $monthEndPrices[$prevYm]) / $monthEndPrices[$prevYm]) * 100;
        $monthlyReturns[(int)$cursor->format('n')][] = $ret;
    }

    $cursor = $cursor->modify('+1 month');
}

$out = [];
foreach ($monthlyReturns as $month => $returns) {
    if (!$returns) {
        continue;
    }

    $positive = count(array_filter($returns, static fn(float $ret): bool => $ret > 0));
    $out[] = [
        'month'    => $month,
        'avg'      => round(array_sum($returns) / count($returns), 2),
        'positive' => $positive,
        'total'    => count($returns),
    ];
}

$expectedSamples = 20;
$isComplete = count($out) === 12;
foreach ($out as $row) {
    if (($row['total'] ?? 0) !== $expectedSamples) {
        $isComplete = false;
        break;
    }
}

if (!$isComplete) {
    fwrite(STDERR, 'seasonality: incomplete dataset, existing snapshot left untouched' . PHP_EOL);
    exit(1);
}

$payload = [
    'generated_at' => gmdate('c'),
    'years'        => 20,
    'window_start' => $windowStart->format('Y-m'),
    'window_end'   => $lastCompleteMonthStart->format('Y-m'),
    'months'       => $out,
];

$target = rtrim($CONFIG['data_dir'], '/\\') . '/seasonality.json';
$tmp = $target . '.tmp';
file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
rename($tmp, $target);

echo 'seasonality: wrote ' . count($out) . ' months to ' . $target . PHP_EOL;