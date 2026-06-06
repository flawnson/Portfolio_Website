<?php
declare(strict_types=1);

// Load the real endpoint functions without firing the web dispatch.
define('HEALTH_METRICS_NO_DISPATCH', true);
require __DIR__ . '/health-metrics.php';

$fail = 0;
function check(string $label, $got, $expected): void {
    global $fail;
    $ok = ($got === $expected);
    if (!$ok) { $fail++; }
    echo ($ok ? "PASS " : "FAIL ") . $label . "\n";
    if (!$ok) {
        echo "   expected: " . json_encode($expected) . "\n";
        echo "   got:      " . json_encode($got) . "\n";
    }
}

echo "=== 1. Request payload shape (the bug we just fixed) ===\n";
$_GET = ['days' => '7'];
[$startCivil, $endCivil, $days] = health_civil_range();
// This is exactly what health_fetch_data_type sends as the request body:
$payload = ['range' => ['start' => $startCivil, 'end' => $endCivil], 'windowSizeDays' => 1, 'pageSize' => 1000];
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
// CivilDateTime must nest year/month/day under "date".
check('start is nested under date', array_keys($startCivil), ['date']);
check('start.date has year/month/day', array_keys($startCivil['date']), ['year', 'month', 'day']);
check('no bare year at start level', $startCivil['year'] ?? 'absent', 'absent');

echo "\n=== 2. Explicit start/end params ===\n";
$_GET = ['start' => '2026-01-01', 'end' => '2026-01-08'];
[$s, $e, $d] = health_civil_range();
check('explicit start.date', $s['date'], ['year' => 2026, 'month' => 1, 'day' => 1]);
check('explicit end.date', $e['date'], ['year' => 2026, 'month' => 1, 'day' => 8]);

echo "\n=== 3. CivilDateTime -> string (response parsing) ===\n";
check('nested date', health_civil_to_string(['date' => ['year' => 2026, 'month' => 6, 'day' => 5]]), '2026-06-05');
check('nested date+time', health_civil_to_string(['date' => ['year' => 2026, 'month' => 6, 'day' => 5], 'time' => ['hours' => 23, 'minutes' => 7, 'seconds' => 0]]), '2026-06-05T23:07:00');
check('missing date -> null', health_civil_to_string(['time' => ['hours' => 1]]), null);

echo "\n=== 4. Normalize a realistic dailyRollUp point ===\n";
$point = [
    'civilStartTime' => ['date' => ['year' => 2026, 'month' => 6, 'day' => 5]],
    'civilEndTime'   => ['date' => ['year' => 2026, 'month' => 6, 'day' => 6]],
    'steps'          => ['count_sum' => 8432, 'unit' => 'count'],
];
$norm = health_normalize_rollup_point('steps', $point);
echo json_encode($norm, JSON_PRETTY_PRINT) . "\n";
check('dataType', $norm['dataType'], 'steps');
check('metric', $norm['metric'], 'steps');
check('start', $norm['start'], '2026-06-05');
check('end', $norm['end'], '2026-06-06');
check('value (count_sum)', $norm['value'], 8432);
check('unit', $norm['unit'], 'count');

echo "\n=== 5. Heart-rate style point prefers avg ===\n";
$hr = [
    'civilStartTime' => ['date' => ['year' => 2026, 'month' => 6, 'day' => 5]],
    'civilEndTime'   => ['date' => ['year' => 2026, 'month' => 6, 'day' => 6]],
    'heartRate'      => ['bpm_avg' => 61.5, 'bpm_min' => 48, 'bpm_max' => 142],
];
$normHr = health_normalize_rollup_point('heart-rate', $hr);
check('hr metric key', $normHr['metric'], 'heartRate');
check('hr value (bpm_avg)', $normHr['value'], 61.5);

echo "\n" . ($fail === 0 ? "ALL PASSED ✅" : "{$fail} FAILED ❌") . "\n";
exit($fail === 0 ? 0 : 1);
