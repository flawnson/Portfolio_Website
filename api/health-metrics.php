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

    $toCivil = function (int $ts): array {
        return [
            'year'  => (int) gmdate('Y', $ts),
            'month' => (int) gmdate('n', $ts),
            'day'   => (int) gmdate('j', $ts),
        ];
    };

    return [$toCivil($startTs), $toCivil($endTs), $days];
}

/**
 * Fetches daily-aggregated points for one data type via the :dailyRollUp method
 * and returns normalized rows.
 * Returns ['ok'=>bool, 'metrics'=>array, 'status'=>int, 'error'=>string].
 */
function health_fetch_data_type(string $accessToken, string $dataType, array $startCivil, array $endCivil): array {
    $clean = health_clean_data_type($dataType);
    if ($clean === '') {
        return ['ok' => false, 'metrics' => [], 'status' => 400, 'error' => 'invalid_data_type'];
    }

    $userId = rawurlencode(health_user_id());
    $url = HEALTH_API_BASE . "/users/{$userId}/dataTypes/{$clean}/dataPoints:dailyRollUp";

    $payload = [
        'range'          => ['start' => $startCivil, 'end' => $endCivil],
        'windowSizeDays' => 1,
        'pageSize'       => 1000,
    ];

    $res = health_http_post_json($url, health_bearer_headers($accessToken), $payload);

    if (!$res['ok']) {
        // 401/403 mid-flight usually means the token/scopes went bad.
        if (in_array($res['status'], [401, 403], true)) {
            return ['ok' => false, 'metrics' => [], 'status' => $res['status'], 'error' => 'needs_reauth'];
        }
        $apiError = $res['json']['error']['message'] ?? ($res['json']['error'] ?? $res['error']);
        return ['ok' => false, 'metrics' => [], 'status' => $res['status'] ?: 502, 'error' => (string) $apiError];
    }

    $points = $res['json']['rollupDataPoints'] ?? [];
    if (!is_array($points)) {
        $points = [];
    }

    $metrics = [];
    foreach ($points as $p) {
        if (is_array($p)) {
            $metrics[] = health_normalize_rollup_point($clean, $p);
        }
    }

    return ['ok' => true, 'metrics' => $metrics, 'status' => 200, 'error' => ''];
}

function health_handle_single_type(string $dataType, int $cacheTtl): void {
    [$startCivil, $endCivil, $days] = health_civil_range();

    $clean = health_clean_data_type($dataType);
    if ($clean === '') {
        health_respond(400, ['ok' => false, 'error' => 'invalid_data_type']);
    }

    $cacheKey = "type_{$clean}_" . md5(json_encode([$startCivil, $endCivil]));
    $cached = health_cache_get($cacheKey, $cacheTtl);
    if ($cached !== null) {
        health_respond(200, $cached);
    }

    $accessToken = health_token_or_fail();
    $result = health_fetch_data_type($accessToken, $clean, $startCivil, $endCivil);

    if (!$result['ok']) {
        if ($result['error'] === 'needs_reauth') {
            health_respond(409, ['ok' => false, 'error' => 'needs_reauth',
                'reauth_hint' => '/api/health-auth.php?action=authorize&token=<adminToken>']);
        }
        health_respond($result['status'] >= 400 ? $result['status'] : 502,
            ['ok' => false, 'error' => $result['error'], 'dataType' => $clean]);
    }

    $payload = [
        'ok'      => true,
        'metrics' => $result['metrics'],
        'meta'    => ['dataType' => $clean, 'range' => ['start' => $startCivil, 'end' => $endCivil], 'count' => count($result['metrics'])],
    ];
    health_cache_put($cacheKey, $payload);
    health_respond(200, $payload);
}

function health_handle_bundle(array $dataTypes, int $cacheTtl): void {
    [$startCivil, $endCivil, $days] = health_civil_range();

    $cacheKey = 'bundle_' . md5(json_encode([$startCivil, $endCivil, $dataTypes]));
    $cached = health_cache_get($cacheKey, $cacheTtl);
    if ($cached !== null) {
        health_respond(200, $cached);
    }

    $accessToken = health_token_or_fail();

    $metrics = [];
    $errors  = [];
    foreach ($dataTypes as $dt) {
        $result = health_fetch_data_type($accessToken, (string) $dt, $startCivil, $endCivil);
        if ($result['ok']) {
            foreach ($result['metrics'] as $m) {
                $metrics[] = $m;
            }
        } elseif ($result['error'] === 'needs_reauth') {
            health_respond(409, ['ok' => false, 'error' => 'needs_reauth',
                'reauth_hint' => '/api/health-auth.php?action=authorize&token=<adminToken>']);
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
            'partial_errors' => $errors ?: null,
        ],
    ];
    health_cache_put($cacheKey, $payload);
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
