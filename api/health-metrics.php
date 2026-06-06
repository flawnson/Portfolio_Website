<?php
declare(strict_types=1);

/**
 * Public read endpoint for Google Health API data.
 *
 * Generic data fetch (anything about you):
 *   GET /api/health-metrics.php?dataType=<id>&startTime=<RFC3339>&endTime=<RFC3339>&pageSize=<n>
 *       Lists normalized data points for a single data type over a time window.
 *
 * Default bundle (no dataType):
 *   GET /api/health-metrics.php
 *       Returns a normalized bundle across a curated set of common data types
 *       for the last 7 days (configurable via ?days=N).
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

function health_default_window(): array {
    $days = max(1, min((int) ($_GET['days'] ?? 7), 90));
    $end   = gmdate('Y-m-d\TH:i:s\Z');
    $start = gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));
    return [$start, $end, $days];
}

/**
 * Lists data points for one data type over a window and returns normalized rows.
 * Returns ['ok'=>bool, 'metrics'=>array, 'status'=>int, 'error'=>string].
 */
function health_fetch_data_type(string $accessToken, string $dataType, string $start, string $end, int $pageSize): array {
    $clean = health_clean_data_type($dataType);
    if ($clean === '') {
        return ['ok' => false, 'metrics' => [], 'status' => 400, 'error' => 'invalid_data_type'];
    }

    $userId = rawurlencode(health_user_id());
    $url = HEALTH_API_BASE . "/users/{$userId}/dataTypes/{$clean}/dataPoints?"
        . http_build_query([
            'startTime' => $start,
            'endTime'   => $end,
            'pageSize'  => $pageSize,
        ]);

    $res = health_http_get($url, health_bearer_headers($accessToken));

    if (!$res['ok']) {
        // 401/403 mid-flight usually means the token/scopes went bad.
        if (in_array($res['status'], [401, 403], true)) {
            return ['ok' => false, 'metrics' => [], 'status' => $res['status'], 'error' => 'needs_reauth'];
        }
        $apiError = $res['json']['error']['message'] ?? ($res['json']['error'] ?? $res['error']);
        return ['ok' => false, 'metrics' => [], 'status' => $res['status'] ?: 502, 'error' => (string) $apiError];
    }

    $points = $res['json']['dataPoints'] ?? ($res['json']['point'] ?? []);
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

function health_handle_single_type(string $dataType, int $cacheTtl): void {
    [$defStart, $defEnd] = health_default_window();
    $start = trim((string) ($_GET['startTime'] ?? $defStart));
    $end   = trim((string) ($_GET['endTime'] ?? $defEnd));
    $pageSize = max(1, min((int) ($_GET['pageSize'] ?? 100), 1000));

    $clean = health_clean_data_type($dataType);
    if ($clean === '') {
        health_respond(400, ['ok' => false, 'error' => 'invalid_data_type']);
    }

    $cacheKey = "type_{$clean}_{$start}_{$end}_{$pageSize}";
    $cached = health_cache_get($cacheKey, $cacheTtl);
    if ($cached !== null) {
        health_respond(200, $cached);
    }

    $accessToken = health_token_or_fail();
    $result = health_fetch_data_type($accessToken, $clean, $start, $end, $pageSize);

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
        'meta'    => ['dataType' => $clean, 'startTime' => $start, 'endTime' => $end, 'count' => count($result['metrics'])],
    ];
    health_cache_put($cacheKey, $payload);
    health_respond(200, $payload);
}

function health_handle_bundle(array $dataTypes, int $cacheTtl): void {
    [$start, $end, $days] = health_default_window();

    $cacheKey = 'bundle_' . $days . '_' . md5(implode(',', $dataTypes));
    $cached = health_cache_get($cacheKey, $cacheTtl);
    if ($cached !== null) {
        health_respond(200, $cached);
    }

    $accessToken = health_token_or_fail();

    $metrics = [];
    $errors  = [];
    foreach ($dataTypes as $dt) {
        $result = health_fetch_data_type($accessToken, (string) $dt, $start, $end, 200);
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
            'startTime'  => $start,
            'endTime'    => $end,
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
