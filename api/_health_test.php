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
// This is exactly what health_fetch_rollup sends as the request body (no pageSize):
$payload = ['range' => ['start' => $startCivil, 'end' => $endCivil], 'windowSizeDays' => 1];
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

echo "\n=== 4. Normalize a REAL dailyRollUp point (camelCase countSum) ===\n";
// Exact shape observed live: civilStartTime nested date, value field "countSum".
$point = [
    'civilStartTime' => ['date' => ['year' => 2026, 'month' => 6, 'day' => 6], 'time' => []],
    'civilEndTime'   => ['date' => ['year' => 2026, 'month' => 6, 'day' => 7], 'time' => []],
    'steps'          => ['countSum' => 4310],
];
$norm = health_normalize_rollup_point('steps', $point);
echo json_encode($norm, JSON_PRETTY_PRINT) . "\n";
check('dataType', $norm['dataType'], 'steps');
check('metric', $norm['metric'], 'steps');
check('start', $norm['start'], '2026-06-06');
check('value (countSum)', $norm['value'], 4310);

echo "\n=== 5. Heart-rate rollup prefers an avg-style field ===\n";
$hr = [
    'civilStartTime' => ['date' => ['year' => 2026, 'month' => 6, 'day' => 5]],
    'civilEndTime'   => ['date' => ['year' => 2026, 'month' => 6, 'day' => 6]],
    'heartRate'      => ['bpmAvg' => 61.5, 'bpmMin' => 48, 'bpmMax' => 142],
];
$normHr = health_normalize_rollup_point('heart-rate', $hr);
check('hr metric key', $normHr['metric'], 'heartRate');
check('hr value (bpmAvg)', $normHr['value'], 61.5);

echo "\n=== 5b. Total-calories rollup (kcal sum) ===\n";
$tc = [
    'civilStartTime' => ['date' => ['year' => 2026, 'month' => 8, 'day' => 10]],
    'civilEndTime'   => ['date' => ['year' => 2026, 'month' => 8, 'day' => 11]],
    'totalCalories'  => ['kcalSum' => 2570.4],
];
$normTc = health_normalize_rollup_point('total-calories', $tc);
check('tc metric key', $normTc['metric'], 'totalCalories');
check('tc value (kcalSum)', $normTc['value'], 2570.4);
check('tc date', $normTc['date'], '2026-08-10');

echo "\n=== 6. Normalize a REAL list point (steps, count string) ===\n";
// Exact shape observed live from the list method.
$listPoint = [
    'dataSource' => ['recordingMethod' => 'PASSIVELY_MEASURED', 'device' => [], 'platform' => 'FITBIT'],
    'steps'      => [
        'interval' => [
            'startTime'      => '2026-06-06T20:54:00Z',
            'endTime'        => '2026-06-06T20:55:00Z',
            'civilStartTime' => ['date' => ['year' => 2026, 'month' => 6, 'day' => 6], 'time' => ['hours' => 16, 'minutes' => 54]],
        ],
        'count'    => '20',
    ],
];
$nl = health_normalize_list_point('steps', $listPoint);
echo json_encode($nl, JSON_PRETTY_PRINT) . "\n";
check('list metric', $nl['metric'], 'steps');
check('list start (RFC3339)', $nl['start'], '2026-06-06T20:54:00Z');
check('list end (RFC3339)', $nl['end'], '2026-06-06T20:55:00Z');
check('list value (string "20" -> 20)', $nl['value'], 20);
check('list source', $nl['source'], 'FITBIT');

check('list date field', $nl['date'], '2026-06-06');

echo "\n=== 7. Method routing ===\n";
check('steps -> rollup', health_method_for('steps'), 'rollup');
check('heart-rate -> rollup', health_method_for('heart-rate'), 'rollup');
check('total-calories -> rollup', health_method_for('total-calories'), 'rollup');
check('sleep -> list', health_method_for('sleep'), 'list');
check('daily-resting-heart-rate -> list', health_method_for('daily-resting-heart-rate'), 'list');
check('exercise -> list', health_method_for('exercise'), 'list');

echo "\n=== 8. Sleep -> minutesAsleep as value ===\n";
$sleepPoint = [
    'dataSource' => ['platform' => 'FITBIT'],
    'sleep'      => [
        'interval' => [
            'startTime'      => '2026-06-06T06:21:00Z',
            'endTime'        => '2026-06-06T14:23:00Z',
            'civilStartTime' => ['date' => ['year' => 2026, 'month' => 6, 'day' => 6], 'time' => ['hours' => 2, 'minutes' => 21]],
        ],
        'type'    => 'STAGES',
        'summary' => ['minutesAsleep' => '469', 'minutesInSleepPeriod' => '482'],
    ],
];
$ns = health_normalize_list_point('sleep', $sleepPoint);
check('sleep value = minutesAsleep', $ns['value'], 469);
check('sleep unit', $ns['unit'], 'minutes');
check('sleep date (civil)', $ns['date'], '2026-06-06');

echo "\n=== 9. h:mm formatting (mirror of frontend) ===\n";
$hm = function (int $min): string { return intdiv($min, 60) . ':' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT); };
check('469 -> 7:49', $hm(469), '7:49');
check('60 -> 1:00', $hm(60), '1:00');
check('5 -> 0:05', $hm(5), '0:05');

echo "\n=== 10. Daily-summary (resting HR) date + value ===\n";
$rhrPoint = [
    'dataSource'           => ['platform' => 'FITBIT'],
    'dailyRestingHeartRate' => [
        'date'           => ['year' => 2026, 'month' => 6, 'day' => 6],
        'beatsPerMinute' => '67',
        'dailyRestingHeartRateMetadata' => ['calculationMethod' => 'WITH_SLEEP'],
    ],
];
$nr = health_normalize_list_point('daily-resting-heart-rate', $rhrPoint);
check('rhr date', $nr['date'], '2026-06-06');
check('rhr value (beatsPerMinute -> int)', $nr['value'], 67);

echo "\n" . ($fail === 0 ? "ALL PASSED ✅" : "{$fail} FAILED ❌") . "\n";
exit($fail === 0 ? 0 : 1);
