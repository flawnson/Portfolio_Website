<?php
declare(strict_types=1);

/**
 * Public read endpoint for Google Health API data.
 *
 * Generic data fetch (anything about you):
 *   GET /api/health-metrics.php?dataType=<id>&days=<n>
 *   GET /api/health-metrics.php?dataType=<id>&start=YYYY-MM-DD&end=YYYY-MM-DD
 *       Returns normalized daily-aggregated points (via :dailyRollUp) for a
 *       single data type over the window. Valid <id>s are the Google Health
 *       data type identifiers, e.g. steps, distance, heart-rate, sleep.
 *
 * Default bundle (no dataType):
 *   GET /api/health-metrics.php
 *       Returns a normalized bundle across a curated set of common data types
 *       for the last 7 days (configurable via ?days=N or start/end).
 *
 * Metadata:
 *   GET /api/health-metrics.php?resource=identity|profile|pairedDevices
 *
 * Responses are uniform:
 *   { "ok": true,  "metrics": [ {dataType,start,end,value,unit,source,raw}, ... ], "meta": {...} }
 *   { "ok": false, "error": "<code>", ... }
 *
 * On expired/revoked auth the endpoint returns HTTP 409 with error
 * "needs_reauth" and a `reauth_hint` pointing at the authorize step.
 */

require __DIR__ . '/health-common.php';

// Tests define this to load the functions below without firing the web dispatch.
if (!defined('HEALTH_METRICS_NO_DISPATCH')) {

health_send_cors_headers();
health_load_config();

// Curated default set. These are the literal {dataType} path segments from the
// Google Health API spec. The bundle tolerates per-type errors, so adding more
// identifiers here is safe even if a given source doesn't populate them.
$DEFAULT_DATA_TYPES = [
    'steps',
    'distance',
    'active-energy-burned',
    'active-zone-minutes',
    'heart-rate',
    'daily-resting-heart-rate',
    'sleep',
];

$resource = $_GET['resource'] ?? '';
$dataType = trim((string) ($_GET['dataType'] ?? ''));

// Short cache so public homepage traffic doesn't hammer the API / hit limits.
$cacheTtl = 300; // 5 minutes

try {
    // TEMPORARY debug: returns the raw Google `list` response for one data type
    // so the exact dataPoint structure can be inspected, then removed.
    if (($_GET['debug'] ?? '') === 'raw') {
        $dbgType = health_clean_data_type((string) ($_GET['dataType'] ?? 'steps'));
        $tok = health_get_access_token();
        if (!$tok['ok']) {
            health_respond(409, ['ok' => false, 'error' => $tok['error']]);
        }
        $raw = health_fetch_data_type_raw($tok['access_token'], $dbgType, (int) ($_GET['pageSize'] ?? 5));
        health_respond($raw['ok'] ? 200 : ($raw['status'] ?: 502), [
            'ok'       => $raw['ok'],
            'dataType' => $dbgType,
            'status'   => $raw['status'],
            'error'    => $raw['error'],
            'raw'      => $raw['json'],
        ]);
    }

    if ($resource !== '') {
        health_handle_resource($resource, $cacheTtl);
    } elseif ($dataType !== '') {
        health_handle_single_type($dataType, $cacheTtl);
    } else {
        health_handle_bundle($DEFAULT_DATA_TYPES, $cacheTtl);
    }
} catch (Throwable $e) {
    error_log('health-metrics fatal: ' . $e->getMessage());
    health_respond(500, ['ok' => false, 'error' => 'internal_error']);
}

} // end web-dispatch guard

// ---------------------------------------------------------------------------

function health_token_or_fail(): string {
    $tok = health_get_access_token();
    if (!$tok['ok']) {
        if ($tok['error'] === 'needs_reauth') {
            health_respond(409, [
                'ok'          => false,
                'error'       => 'needs_reauth',
                'reauth_hint' => '/api/health-auth.php?action=authorize&token=<adminToken>',
            ]);
        }
        health_respond(502, ['ok' => false, 'error' => $tok['error']]);
    }
    return $tok['access_token'];
}

function health_bearer_headers(string $accessToken): array {
    return [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ];
}

/** Validates a data type segment to keep it safe for URL interpolation. */
function health_clean_data_type(string $dataType): string {
    return preg_replace('/[^a-zA-Z0-9_.\-]/', '', $dataType) ?? '';
}

/**
 * Builds a civil-date {start,end} range for the :dailyRollUp method.
 * Honors ?start=YYYY-MM-DD&end=YYYY-MM-DD, else ?days=N (default 7).
 * The range is closed-open, so end is exclusive (tomorrow by default).
 */
function health_civil_range(): array {
    $days = max(1, min((int) ($_GET['days'] ?? 7), 90));

    $startStr = trim((string) ($_GET['start'] ?? ''));
    $endStr   = trim((string) ($_GET['end'] ?? ''));

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startStr) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endStr)) {
        $startTs = strtotime($startStr . ' UTC');
        $endTs   = strtotime($endStr . ' UTC');
    } else {
        // Default: last N days through tomorrow (exclusive end).
        $endTs   = strtotime(gmdate('Y-m-d') . ' UTC') + 86400;
        $startTs = $endTs - (($days + 1) * 86400);
    }

    // CivilDateTime nests the date under a "date" object: { date: {year,month,day} }.
    $toCivil = function (int $ts): array {
        return [
            'date' => [
                'year'  => (int) gmdate('Y', $ts),
                'month' => (int) gmdate('n', $ts),
                'day'   => (int) gmdate('j', $ts),
            ],
        ];
    };

    return [$toCivil($startTs), $toCivil($endTs), $days];
}

/**
 * Fetches data points for one data type via the universal `list` method (the one
 * method every data type supports) and returns normalized rows.
 * Returns ['ok'=>bool, 'metrics'=>array, 'status'=>int, 'error'=>string].
 */
function health_fetch_data_type(string $accessToken, string $dataType, int $pageSize = 100): array {
    $clean = health_clean_data_type($dataType);
    if ($clean === '') {
        return ['ok' => false, 'metrics' => [], 'status' => 400, 'error' => 'invalid_data_type'];
    }

    $raw = health_fetch_data_type_raw($accessToken, $clean, $pageSize);
    if (!$raw['ok']) {
        return ['ok' => false, 'metrics' => [], 'status' => $raw['status'], 'error' => $raw['error']];
    }

    $points = $raw['json']['dataPoints'] ?? ($raw['json']['point'] ?? []);
    if (!is_array($points)) {
        $points = [];
    }

    $metrics = [];
    foreach ($points as $p) {
        if (is_array($p)) {
            $metrics[] = health_normalize_point($clean, $p);
        }
    }

    return ['ok' => true, 'metrics' => $metrics, 'status' => 200, 'error' => ''];
}

/**
 * Raw `list` call for one data type. Returns the decoded Google response so the
 * debug path can inspect the real structure.
 * Returns ['ok'=>bool, 'json'=>array|null, 'status'=>int, 'error'=>string].
 */
function health_fetch_data_type_raw(string $accessToken, string $clean, int $pageSize): array {
    $userId = rawurlencode(health_user_id());
    $url = HEALTH_API_BASE . "/users/{$userId}/dataTypes/{$clean}/dataPoints?"
        . http_build_query(['pageSize' => $pageSize]);

    $res = health_http_get($url, health_bearer_headers($accessToken));

    if (!$res['ok']) {
        if (in_array($res['status'], [401, 403], true)) {
            return ['ok' => false, 'json' => $res['json'], 'status' => $res['status'], 'error' => 'needs_reauth'];
        }
        $apiError = $res['json']['error']['message'] ?? ($res['json']['error'] ?? $res['error']);
        return ['ok' => false, 'json' => $res['json'], 'status' => $res['status'] ?: 502, 'error' => (string) $apiError];
    }

    return ['ok' => true, 'json' => $res['json'], 'status' => 200, 'error' => ''];
}

/**
 * Last-resort response when a live fetch fails: serve the last successful
 * snapshot (marked stale) so the public panel never goes blank. If there is no
 * snapshot yet, fall back to the appropriate hard error.
 */
function health_serve_snapshot_or_error(string $snapshotKey, string $reason, int $errorStatus): void {
    $snap = health_snapshot_get($snapshotKey);
    if ($snap !== null && isset($snap['payload']) && is_array($snap['payload'])) {
        $payload = $snap['payload'];
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $meta['stale']        = true;
        $meta['stale_reason'] = $reason;
        $meta['as_of']        = $snap['saved_at'] ?? null;
        if ($reason === 'needs_reauth') {
            $meta['reauth_hint'] = '/api/health-auth.php?action=authorize&token=<adminToken>';
        }
        $payload['meta'] = $meta;
        health_respond(200, $payload);
    }

    if ($reason === 'needs_reauth') {
        health_respond(409, ['ok' => false, 'error' => 'needs_reauth',
            'reauth_hint' => '/api/health-auth.php?action=authorize&token=<adminToken>']);
    }
    health_respond($errorStatus, ['ok' => false, 'error' => $reason]);
}

function health_handle_single_type(string $dataType, int $cacheTtl): void {
    [$startCivil, $endCivil, $days] = health_civil_range();

    $clean = health_clean_data_type($dataType);
    if ($clean === '') {
        health_respond(400, ['ok' => false, 'error' => 'invalid_data_type']);
    }

    $cacheKey    = "type_{$clean}_" . md5(json_encode([$startCivil, $endCivil]));
    $snapshotKey = "type_{$clean}_{$days}"; // date-independent, so fallback survives day changes

    $cached = health_cache_get($cacheKey, $cacheTtl);
    if ($cached !== null) {
        health_respond(200, $cached);
    }

    $tok = health_get_access_token();
    if (!$tok['ok']) {
        health_serve_snapshot_or_error($snapshotKey, $tok['error'], 502);
    }

    $result = health_fetch_data_type($tok['access_token'], $clean);
    if (!$result['ok']) {
        if ($result['error'] === 'needs_reauth') {
            health_serve_snapshot_or_error($snapshotKey, 'needs_reauth', 409);
        }
        // Transient upstream error: prefer a stale snapshot over a hard failure.
        health_serve_snapshot_or_error($snapshotKey, $result['error'], $result['status'] >= 400 ? $result['status'] : 502);
    }

    $payload = [
        'ok'      => true,
        'metrics' => $result['metrics'],
        'meta'    => [
            'dataType' => $clean,
            'range'    => ['start' => $startCivil, 'end' => $endCivil],
            'count'    => count($result['metrics']),
            'stale'    => false,
            'as_of'    => gmdate('Y-m-d\TH:i:s\Z'),
        ],
    ];
    health_cache_put($cacheKey, $payload);
    health_snapshot_put($snapshotKey, $payload);
    health_respond(200, $payload);
}

function health_handle_bundle(array $dataTypes, int $cacheTtl): void {
    [$startCivil, $endCivil, $days] = health_civil_range();

    $cacheKey    = 'bundle_' . md5(json_encode([$startCivil, $endCivil, $dataTypes]));
    $snapshotKey = 'bundle_' . md5(json_encode([$days, $dataTypes])); // date-independent

    $cached = health_cache_get($cacheKey, $cacheTtl);
    if ($cached !== null) {
        health_respond(200, $cached);
    }

    $tok = health_get_access_token();
    if (!$tok['ok']) {
        health_serve_snapshot_or_error($snapshotKey, $tok['error'], 502);
    }
    $accessToken = $tok['access_token'];

    $metrics = [];
    $errors  = [];
    foreach ($dataTypes as $dt) {
        $result = health_fetch_data_type($accessToken, (string) $dt);
        if ($result['ok']) {
            foreach ($result['metrics'] as $m) {
                $metrics[] = $m;
            }
        } elseif ($result['error'] === 'needs_reauth') {
            // Auth died mid-flight: serve the last good snapshot instead of erroring.
            health_serve_snapshot_or_error($snapshotKey, 'needs_reauth', 409);
        } else {
            // A single unsupported/empty type shouldn't fail the whole bundle.
            $errors[$dt] = $result['error'];
        }
    }

    $payload = [
        'ok'      => true,
        'metrics' => $metrics,
        'meta'    => [
            'range'      => ['start' => $startCivil, 'end' => $endCivil],
            'days'       => $days,
            'dataTypes'  => $dataTypes,
            'count'      => count($metrics),
            'stale'      => false,
            'as_of'      => gmdate('Y-m-d\TH:i:s\Z'),
            'partial_errors' => $errors ?: null,
        ],
    ];
    health_cache_put($cacheKey, $payload);
    // Only snapshot a non-empty result, so one bad day doesn't overwrite good data.
    if (!empty($metrics)) {
        health_snapshot_put($snapshotKey, $payload);
    }
    health_respond(200, $payload);
}

function health_handle_resource(string $resource, int $cacheTtl): void {
    $map = [
        'identity'      => 'identity',
        'profile'       => 'profile',
        'pairedDevices' => 'pairedDevices',
    ];
    if (!isset($map[$resource])) {
        health_respond(400, ['ok' => false, 'error' => 'unknown_resource',
            'allowed' => array_keys($map)]);
    }

    $cacheKey = 'res_' . $resource;
    $cached = health_cache_get($cacheKey, $cacheTtl);
    if ($cached !== null) {
        health_respond(200, $cached);
    }

    $accessToken = health_token_or_fail();
    $userId = rawurlencode(health_user_id());
    $url = HEALTH_API_BASE . "/users/{$userId}/{$map[$resource]}";

    $res = health_http_get($url, health_bearer_headers($accessToken));
    if (!$res['ok']) {
        if (in_array($res['status'], [401, 403], true)) {
            health_respond(409, ['ok' => false, 'error' => 'needs_reauth',
                'reauth_hint' => '/api/health-auth.php?action=authorize&token=<adminToken>']);
        }
        $apiError = $res['json']['error']['message'] ?? ($res['json']['error'] ?? $res['error']);
        health_respond($res['status'] ?: 502, ['ok' => false, 'error' => (string) $apiError]);
    }

    $payload = ['ok' => true, 'resource' => $resource, 'data' => $res['json']];
    health_cache_put($cacheKey, $payload);
    health_respond(200, $payload);
}
