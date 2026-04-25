<?php
/**
 * cron/build-seasonality.php
 *
 * Builds a static 20-year monthly seasonality snapshot for gold.
 * Suggested cron:
 *   0 3 1 * * /usr/bin/php /home/YOURUSER/private/cron/build-seasonality.php >> /home/YOURUSER/private/cron.log 2>&1
 */

declare(strict_types=1);

function seasonality_http_json(string $url, int $timeout = 20): ?array {
    if (function_exists('curl_init')) {
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

        if ($code === 200 && $body) {
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        }
    }

    $context = stream_context_create([
        'http' => [
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'header'        => "User-Agent: GoldTerminal/1.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false || $body === '') {
        return null;
    }

    $code = 0;
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#HTTP/\\S+\\s+(\\d+)#', $headerLine, $matches)) {
            $code = (int)$matches[1];
            break;
        }
    }
    if ($code !== 0 && $code !== 200) {
        return null;
    }

    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function seasonality_rate_lookup(string $code, array $rates): ?float {
    if (isset($rates['USD' . $code])) {
        return (float)$rates['USD' . $code];
    }
    if (isset($rates[$code])) {
        return (float)$rates[$code];
    }
    return null;
}

function seasonality_extract_daily_gold_prices(array $timeframeRates): array {
    $sample = null;
    foreach ($timeframeRates as $rates) {
        if (!is_array($rates)) {
            continue;
        }
        $sample = seasonality_rate_lookup('XAU', $rates);
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
        $xau = seasonality_rate_lookup('XAU', $rates);
        if ($xau === null || $xau <= 0) {
            continue;
        }
        $daily[$date] = $isInverse ? (1 / $xau) : $xau;
    }

    ksort($daily);
    return $daily;
}

function build_seasonality_snapshot(array $config, ?callable $logger = null, array $options = []): array {
    if (!is_dir($config['data_dir'])) {
        @mkdir($config['data_dir'], 0755, true);
    }

    $log = $logger ?? static function (string $_message): void {
    };
    $delaySeconds = max(0, (int)($options['delay_seconds'] ?? 1));
    $years = max(1, (int)($options['years'] ?? 20));
    $target = rtrim($config['data_dir'], '/\\') . '/seasonality.json';

    $tz = new DateTimeZone('UTC');
    $currentMonthStart = new DateTimeImmutable('first day of this month 00:00:00', $tz);
    $lastCompleteMonthStart = $currentMonthStart->modify('-1 month');
    $windowStart = $currentMonthStart->modify('-' . $years . ' years');
    $baselineStart = $windowStart->modify('-1 month');
    $windowEnd = $currentMonthStart->modify('-1 day');

    $apiKey = urlencode($config['metalprice_key']);
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

        $data = seasonality_http_json($url);
        if (empty($data['rates']) || !is_array($data['rates'])) {
            $log('seasonality: missing rates for ' . $rangeStart->format('Y-m-d') . ' to ' . $rangeEnd->format('Y-m-d'));
            if ($delaySeconds > 0 && $year < $endYear) {
                sleep($delaySeconds);
            }
            continue;
        }

        foreach (seasonality_extract_daily_gold_prices($data['rates']) as $date => $price) {
            $allDaily[$date] = $price;
        }

        if ($delaySeconds > 0 && $year < $endYear) {
            sleep($delaySeconds);
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

    $isComplete = count($out) === 12;
    $availableYears = 0;
    if ($isComplete) {
        $totals = array_map(static fn(array $row): int => (int)($row['total'] ?? 0), $out);
        $availableYears = min($totals);
        if ($availableYears < 1) {
            $isComplete = false;
        }
    }

    if (!$isComplete) {
        return [
            'ok'    => false,
            'error' => 'seasonality: incomplete dataset, existing snapshot left untouched',
            'target' => $target,
        ];
    }

    $payload = [
        'generated_at' => gmdate('c'),
        'years'        => $availableYears,
        'requested_years' => $years,
        'window_start' => $windowStart->format('Y-m'),
        'window_end'   => $lastCompleteMonthStart->format('Y-m'),
        'months'       => $out,
    ];

    $tmp = $target . '.tmp';
    $written = @file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    if ($written === false || !@rename($tmp, $target)) {
        return [
            'ok'    => false,
            'error' => 'seasonality: failed to write snapshot',
            'target' => $target,
        ];
    }

    return [
        'ok'      => true,
        'payload' => $payload,
        'target'  => $target,
        'message' => 'seasonality: wrote ' . count($out) . ' months to ' . $target,
    ];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $CONFIG = require __DIR__ . '/../config.php';
    $result = build_seasonality_snapshot(
        $CONFIG,
        static function (string $message): void {
            fwrite(STDERR, $message . PHP_EOL);
        }
    );

    if (empty($result['ok'])) {
        fwrite(STDERR, ($result['error'] ?? 'seasonality: build failed') . PHP_EOL);
        exit(1);
    }

    echo ($result['message'] ?? 'seasonality: build complete') . PHP_EOL;
}