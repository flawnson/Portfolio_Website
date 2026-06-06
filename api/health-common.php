<?php
declare(strict_types=1);

/**
 * Shared helpers for the Google Health API integration.
 *
 * This file is included by health-auth.php (OAuth flow) and health-metrics.php
 * (data endpoint). It is intentionally self-contained so it never depends on
 * micro-posts.php — the curl/response patterns below are copied from the proven
 * helpers there.
 *
 * Secrets live in the private (non-webroot, git-ignored) config:
 *   /home/flawhvna/private/microblog-config.php
 * which must define:
 *   $googleHealthClientId, $googleHealthClientSecret, $googleHealthRedirectUri,
 *   $googleHealthScopes (space-delimited), $googleHealthUserId (e.g. 'me'),
 *   and the existing $adminToken (reused to gate the authorize step).
 *
 * The OAuth refresh token + cached access token are persisted to a writable
 * file OUTSIDE the webroot (see HEALTH_TOKEN_PATH below).
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const HEALTH_PRIVATE_DIR   = '/home/flawhvna/private';
const HEALTH_CONFIG_PATH   = '/home/flawhvna/private/microblog-config.php';
const HEALTH_TOKEN_PATH    = '/home/flawhvna/private/google-health-token.json';
const HEALTH_CACHE_DIR     = '/home/flawhvna/private/cache';
const HEALTH_SNAPSHOT_DIR  = '/home/flawhvna/private/snapshots';

const GOOGLE_TOKEN_URL     = 'https://oauth2.googleapis.com/token';
const GOOGLE_AUTH_URL      = 'https://accounts.google.com/o/oauth2/v2/auth';
const HEALTH_API_BASE      = 'https://health.googleapis.com/v4';

// Refresh the access token this many seconds before it actually expires.
const HEALTH_TOKEN_SKEW    = 120;

// ---------------------------------------------------------------------------
// CORS + response helpers
// ---------------------------------------------------------------------------

function health_send_cors_headers(): void {
    $allowedOrigins = [
        'https://flawnson.com',
        'http://localhost:63342',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:63342',
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: $origin");
    }

    header('Vary: Origin');
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function health_respond(int $status, array $data): void {
    http_response_code($status);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// ---------------------------------------------------------------------------
// Config loading
// ---------------------------------------------------------------------------

function health_config_string(string $name, string $default = ''): string {
    $value = $GLOBALS[$name] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

/**
 * Loads the private config and verifies the required Google Health keys exist.
 * Exits with a 500 JSON error if anything is missing.
 */
function health_load_config(): void {
    if (!is_readable(HEALTH_CONFIG_PATH)) {
        error_log('Health config is not readable: ' . HEALTH_CONFIG_PATH);
        health_respond(500, ['ok' => false, 'error' => 'config_unreadable']);
    }

    try {
        // Imports config variables into the global scope.
        include HEALTH_CONFIG_PATH;
        // Re-export into $GLOBALS so health_config_string() can read them, since
        // include here runs in function scope.
        foreach (get_defined_vars() as $k => $v) {
            $GLOBALS[$k] = $v;
        }
    } catch (Throwable $e) {
        error_log('Health config failed to load: ' . $e->getMessage());
        health_respond(500, ['ok' => false, 'error' => 'config_load_failed']);
    }

    $required = [
        'googleHealthClientId',
        'googleHealthClientSecret',
        'googleHealthRedirectUri',
        'googleHealthScopes',
        'adminToken',
    ];
    foreach ($required as $key) {
        if (health_config_string($key) === '') {
            error_log("Health config missing required value: {$key}");
            health_respond(500, ['ok' => false, 'error' => 'config_missing_required_value', 'missing' => $key]);
        }
    }
}

function health_user_id(): string {
    $id = health_config_string('googleHealthUserId', 'me');
    return $id === '' ? 'me' : $id;
}

function health_require_admin_token(string $provided): void {
    if (!hash_equals(health_config_string('adminToken'), $provided)) {
        health_respond(401, ['ok' => false, 'error' => 'unauthorized']);
    }
}

// ---------------------------------------------------------------------------
// HTTP helpers (copied pattern from micro-posts.php)
// ---------------------------------------------------------------------------

function health_http_get(string $url, array $headers = [], int $timeoutSeconds = 10): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'error' => 'curl_unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET        => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
    ]);

    $body   = curl_exec($ch);
    $error  = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($body) ? json_decode($body, true) : null;

    return [
        'ok'     => $error === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'json'   => is_array($decoded) ? $decoded : null,
        'body'   => is_string($body) ? $body : '',
        'error'  => $error,
    ];
}

function health_http_post_json(string $url, array $headers, array $payload, int $timeoutSeconds = 10): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'error' => 'curl_unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
    ]);

    $body   = curl_exec($ch);
    $error  = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($body) ? json_decode($body, true) : null;

    return [
        'ok'     => $error === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'json'   => is_array($decoded) ? $decoded : null,
        'body'   => is_string($body) ? $body : '',
        'error'  => $error,
    ];
}

function health_http_post_form(string $url, array $fields, int $timeoutSeconds = 10): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'error' => 'curl_unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
    ]);

    $body   = curl_exec($ch);
    $error  = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($body) ? json_decode($body, true) : null;

    return [
        'ok'     => $error === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'json'   => is_array($decoded) ? $decoded : null,
        'body'   => is_string($body) ? $body : '',
        'error'  => $error,
    ];
}

// ---------------------------------------------------------------------------
// Token store (refresh token + cached access token)
// ---------------------------------------------------------------------------

function health_read_token_store(): array {
    if (!is_readable(HEALTH_TOKEN_PATH)) {
        return [];
    }
    $raw = file_get_contents(HEALTH_TOKEN_PATH);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : [];
}

function health_write_token_store(array $data): bool {
    $dir = dirname(HEALTH_TOKEN_PATH);
    if (!is_dir($dir)) {
        // Don't try to create the private dir; it should already exist.
        error_log('Health token dir missing: ' . $dir);
        return false;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $bytes = @file_put_contents(HEALTH_TOKEN_PATH, $json, LOCK_EX);
    if ($bytes === false) {
        error_log('Health token store not writable: ' . HEALTH_TOKEN_PATH);
        return false;
    }
    @chmod(HEALTH_TOKEN_PATH, 0600);
    return true;
}

/**
 * Exchanges an authorization code for tokens and persists the refresh token.
 * Returns ['ok' => bool, 'error' => string].
 */
function health_exchange_code(string $code): array {
    $result = health_http_post_form(GOOGLE_TOKEN_URL, [
        'code'          => $code,
        'client_id'     => health_config_string('googleHealthClientId'),
        'client_secret' => health_config_string('googleHealthClientSecret'),
        'redirect_uri'  => health_config_string('googleHealthRedirectUri'),
        'grant_type'    => 'authorization_code',
    ]);

    if (!$result['ok'] || !is_array($result['json'])) {
        $apiError = $result['json']['error_description'] ?? ($result['json']['error'] ?? $result['error']);
        return ['ok' => false, 'error' => 'token_exchange_failed: ' . (string) $apiError];
    }

    $json = $result['json'];
    $refreshToken = $json['refresh_token'] ?? '';
    if ($refreshToken === '') {
        // Google only returns a refresh token when access_type=offline AND the
        // user is freshly consenting (prompt=consent). Surface this clearly.
        return ['ok' => false, 'error' => 'no_refresh_token_returned'];
    }

    $store = health_read_token_store();
    $store['refresh_token']           = $refreshToken;
    $store['access_token']            = $json['access_token'] ?? '';
    $store['access_token_expires_at'] = time() + (int) ($json['expires_in'] ?? 3600);
    $store['scope']                   = $json['scope'] ?? '';
    $store['updated_at']              = time();

    if (!health_write_token_store($store)) {
        return ['ok' => false, 'error' => 'token_store_not_writable'];
    }

    return ['ok' => true, 'error' => ''];
}

/**
 * Returns a valid access token, refreshing it if needed.
 * Returns ['ok' => bool, 'access_token' => string, 'error' => string].
 * On expiry/revocation, error is 'needs_reauth'.
 */
function health_get_access_token(): array {
    $store = health_read_token_store();

    if (($store['refresh_token'] ?? '') === '') {
        return ['ok' => false, 'access_token' => '', 'error' => 'needs_reauth'];
    }

    $cached  = $store['access_token'] ?? '';
    $expires = (int) ($store['access_token_expires_at'] ?? 0);
    if ($cached !== '' && $expires > (time() + HEALTH_TOKEN_SKEW)) {
        return ['ok' => true, 'access_token' => $cached, 'error' => ''];
    }

    $result = health_http_post_form(GOOGLE_TOKEN_URL, [
        'client_id'     => health_config_string('googleHealthClientId'),
        'client_secret' => health_config_string('googleHealthClientSecret'),
        'refresh_token' => $store['refresh_token'],
        'grant_type'    => 'refresh_token',
    ]);

    if (!$result['ok'] || !is_array($result['json']) || ($result['json']['access_token'] ?? '') === '') {
        $apiError = $result['json']['error'] ?? $result['error'];
        // invalid_grant => refresh token expired/revoked (e.g. 7-day testing limit).
        if ($apiError === 'invalid_grant') {
            return ['ok' => false, 'access_token' => '', 'error' => 'needs_reauth'];
        }
        return ['ok' => false, 'access_token' => '', 'error' => 'token_refresh_failed: ' . (string) $apiError];
    }

    $json = $result['json'];
    $store['access_token']            = $json['access_token'];
    $store['access_token_expires_at'] = time() + (int) ($json['expires_in'] ?? 3600);
    // Google may return a rotated refresh token; keep it if present.
    if (($json['refresh_token'] ?? '') !== '') {
        $store['refresh_token'] = $json['refresh_token'];
    }
    $store['updated_at'] = time();
    health_write_token_store($store);

    return ['ok' => true, 'access_token' => $json['access_token'], 'error' => ''];
}

// ---------------------------------------------------------------------------
// Response cache (short TTL, so public traffic doesn't hammer the API)
// ---------------------------------------------------------------------------

function health_cache_get(string $key, int $ttlSeconds): ?array {
    $path = HEALTH_CACHE_DIR . '/health-' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
    if (!is_readable($path)) {
        return null;
    }
    if ((time() - (int) @filemtime($path)) > $ttlSeconds) {
        return null;
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function health_cache_put(string $key, array $data): void {
    if (!is_dir(HEALTH_CACHE_DIR)) {
        @mkdir(HEALTH_CACHE_DIR, 0700, true);
    }
    if (!is_dir(HEALTH_CACHE_DIR)) {
        return;
    }
    $path = HEALTH_CACHE_DIR . '/health-' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
    @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ---------------------------------------------------------------------------
// Persistent snapshot store (no TTL) — keeps the LAST successful fetch so the
// public panel can keep showing data even after the refresh token lapses.
// Keyed by request *shape* (data types + window length), NOT absolute dates,
// so the fallback is still found on later days.
// ---------------------------------------------------------------------------

function health_snapshot_path(string $key): string {
    return HEALTH_SNAPSHOT_DIR . '/snap-' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
}

/** Returns ['saved_at' => ISO8601, 'payload' => array] or null. */
function health_snapshot_get(string $key): ?array {
    $path = health_snapshot_path($key);
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function health_snapshot_put(string $key, array $payload): void {
    if (!is_dir(HEALTH_SNAPSHOT_DIR)) {
        @mkdir(HEALTH_SNAPSHOT_DIR, 0700, true);
    }
    if (!is_dir(HEALTH_SNAPSHOT_DIR)) {
        return;
    }
    $wrapper = ['saved_at' => gmdate('Y-m-d\TH:i:s\Z'), 'payload' => $payload];
    @file_put_contents(health_snapshot_path($key), json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// ---------------------------------------------------------------------------
// Data normalization
// ---------------------------------------------------------------------------

/**
 * Normalizes a single Google Health API dataPoint into a flat, uniform shape.
 * The API returns type-specific nested value structures; we flatten the first
 * meaningful numeric/string value plus the time window and source.
 *
 * Output: ['dataType','start','end','value','unit','source','raw'?]
 */
function health_normalize_point(string $dataType, array $point): array {
    // Time window: the API uses RFC3339 strings in start/endTime (naming varies
    // slightly by surface), so check the common variants.
    $start = $point['startTime'] ?? ($point['interval']['startTime'] ?? ($point['time'] ?? null));
    $end   = $point['endTime']   ?? ($point['interval']['endTime']   ?? $start);

    [$value, $unit] = health_extract_value($point);

    $source = $point['dataSource']['device']['model']
        ?? ($point['origin']['name']
        ?? ($point['source'] ?? null));

    return [
        'dataType' => $dataType,
        'start'    => $start,
        'end'      => $end,
        'value'    => $value,
        'unit'     => $unit,
        'source'   => $source,
        'raw'      => $point,
    ];
}

/**
 * Best-effort extraction of a primary [value, unit] from a dataPoint's value
 * payload, tolerant of the API's several value shapes.
 */
function health_extract_value(array $point): array {
    // Common container keys seen across health data surfaces.
    $candidates = [
        $point['value'] ?? null,
        $point['fieldValues'] ?? null,
        $point['values'] ?? null,
    ];

    foreach ($candidates as $container) {
        if ($container === null) {
            continue;
        }

        // Scalar value directly.
        if (is_int($container) || is_float($container) || is_string($container)) {
            return [$container, $point['unit'] ?? null];
        }

        if (is_array($container)) {
            // Typed scalar object, e.g. {"intVal":8432} / {"fpVal":72.5} / {"value":..,"unit":..}.
            foreach (['intVal', 'fpVal', 'doubleValue', 'integerValue', 'stringValue', 'value'] as $k) {
                if (array_key_exists($k, $container) && !is_array($container[$k])) {
                    return [$container[$k], $container['unit'] ?? ($point['unit'] ?? null)];
                }
            }
            // List of typed values: take the first scalar found.
            foreach ($container as $entry) {
                if (is_array($entry)) {
                    foreach (['intVal', 'fpVal', 'doubleValue', 'integerValue', 'stringValue', 'value'] as $k) {
                        if (array_key_exists($k, $entry) && !is_array($entry[$k])) {
                            return [$entry[$k], $entry['unit'] ?? null];
                        }
                    }
                } elseif (is_int($entry) || is_float($entry) || is_string($entry)) {
                    return [$entry, null];
                }
            }
        }
    }

    // Nothing scalar found — return null value; caller still has 'raw'.
    return [null, $point['unit'] ?? null];
}

/**
 * Formats a Google Health CivilDateTime ({year,month,day,hours?,...}) as a
 * readable ISO-like string. Returns null if not enough fields are present.
 */
function health_civil_to_string(?array $civil): ?string {
    if (!is_array($civil) || !isset($civil['year'], $civil['month'], $civil['day'])) {
        return null;
    }
    $date = sprintf('%04d-%02d-%02d', (int) $civil['year'], (int) $civil['month'], (int) $civil['day']);
    if (isset($civil['hours']) || isset($civil['minutes'])) {
        $date .= sprintf('T%02d:%02d:%02d', (int) ($civil['hours'] ?? 0), (int) ($civil['minutes'] ?? 0), (int) ($civil['seconds'] ?? 0));
    }
    return $date;
}

/**
 * Normalizes a DailyRollupDataPoint (from the :dailyRollUp method) into the same
 * flat shape as health_normalize_point().
 *
 * Shape: { civilStartTime, civilEndTime, <metricKey>: { <field>_<agg>: number, ... } }
 * The metric value is a "union" — exactly one metric key (steps/heartRate/...) is
 * present per point. We surface the most useful aggregate (sum, else avg/last/max).
 */
function health_normalize_rollup_point(string $dataType, array $point): array {
    $start = health_civil_to_string($point['civilStartTime'] ?? null);
    $end   = health_civil_to_string($point['civilEndTime'] ?? null);

    // The metric union: the first non-metadata object key holds the value bag.
    $metaKeys = ['civilStartTime', 'civilEndTime', 'startTime', 'endTime', 'dataSource', 'dataSourceFamily'];
    $value = null;
    $unit  = null;
    $metricKey = null;

    foreach ($point as $key => $val) {
        if (in_array($key, $metaKeys, true)) {
            continue;
        }
        if (is_array($val)) {
            $metricKey = $key;
            [$value, $unit] = health_pick_rollup_value($val);
            break;
        }
        if (is_int($val) || is_float($val) || is_string($val)) {
            $metricKey = $key;
            $value = $val;
            break;
        }
    }

    return [
        'dataType' => $dataType,
        'metric'   => $metricKey,
        'start'    => $start,
        'end'      => $end,
        'value'    => $value,
        'unit'     => $unit,
        'source'   => $point['dataSource']['device']['model'] ?? null,
        'raw'      => $point,
    ];
}

/**
 * Picks the most representative scalar from a RollupValue bag whose fields look
 * like "{field}_{aggregation}" (e.g. count_sum, bpm_avg). Preference order:
 * *_sum, *_avg, *_last, *_max, *_min, then the first scalar found.
 */
function health_pick_rollup_value(array $bag): array {
    $unit = $bag['unit'] ?? null;
    foreach (['_sum', '_avg', '_average', '_last', '_latest', '_max', '_min'] as $suffix) {
        foreach ($bag as $k => $v) {
            if (is_string($k) && substr($k, -strlen($suffix)) === $suffix && (is_int($v) || is_float($v))) {
                return [$v, $unit];
            }
        }
    }
    foreach ($bag as $v) {
        if (is_int($v) || is_float($v) || is_string($v)) {
            return [$v, $unit];
        }
    }
    return [null, $unit];
}
