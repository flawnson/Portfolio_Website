<?php
declare(strict_types=1);

/**
 * Public read endpoint for an aggregated GitHub contributions heatmap.
 *
 *   GET /api/github-contributions.php
 *       Returns the trailing ~12 months of daily contribution counts, summed
 *       across every account listed in $githubContributionAccounts.
 *
 * Response shape:
 *   { "ok": true,
 *     "days": [ { "date": "2025-06-14", "count": 3 }, ... ],   // ascending
 *     "totalContributions": 1284,
 *     "meta": { "accounts": 2, "partial": false, "failed_accounts": [],
 *               "stale": false, "as_of": "2026-06-13T..Z" } }
 *
 * Data comes from the GitHub GraphQL `contributionsCollection.contributionCalendar`
 * — the only API that returns accurate per-day counts and, for the authenticated
 * `viewer`, includes that account's PRIVATE contributions. Each account needs its
 * own token (a token can only read its own account's private data); we query each
 * with `viewer` and sum contributionCount per date.
 *
 * Reliability mirrors the health panel: a short-TTL response cache plus a
 * persistent snapshot fallback, so the public panel never goes blank. A single
 * failing account is skipped (meta.partial=true) rather than failing the panel.
 *
 * Secrets live in the private (non-webroot, git-ignored) config:
 *   /home/flawhvna/private/microblog-config.php
 * which must define:
 *   $githubContributionAccounts = [
 *     ['label' => 'personal', 'token' => '...'],
 *     ['label' => 'work',     'token' => '...'],
 *   ];
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const GH_CONFIG_PATH   = '/home/flawhvna/private/microblog-config.php';
const GH_CACHE_DIR     = '/home/flawhvna/private/cache';
const GH_SNAPSHOT_DIR  = '/home/flawhvna/private/snapshots';
const GH_GRAPHQL_URL   = 'https://api.github.com/graphql';

// Bump on any change that affects response shape — mixed into the cache key so a
// deploy auto-invalidates stale cached payloads.
const GH_BUILD         = 'gh-contrib-v1';

// Contribution counts change slowly; a longer TTL keeps the slow multi-account
// cache-miss path rare and stays well clear of GitHub rate limits.
const GH_CACHE_TTL     = 1800; // 30 minutes

// ---------------------------------------------------------------------------
// CORS + response helpers
// ---------------------------------------------------------------------------

function gh_send_cors_headers(): void {
    $allowedOrigins = [
        'https://flawnson.com',
        'http://localhost:63342',
        'http://localhost:3000',
        'http://localhost:8000',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:8000',
        'http://127.0.0.1:63342',
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: $origin");
    }

    header('Vary: Origin');
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function gh_respond(int $status, array $data): void {
    http_response_code($status);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------------------------------------------------------------------------
// Config loading
// ---------------------------------------------------------------------------

/**
 * Loads the private config and returns the configured accounts as a list of
 * ['label' => string, 'token' => string]. Exits 500 if config or the accounts
 * array is missing/empty.
 */
function gh_load_accounts(): array {
    if (!is_readable(GH_CONFIG_PATH)) {
        error_log('GitHub contributions config is not readable: ' . GH_CONFIG_PATH);
        gh_respond(500, ['ok' => false, 'error' => 'config_unreadable']);
    }

    try {
        include GH_CONFIG_PATH;
        // include runs in function scope; re-export so we can read the variable.
        foreach (get_defined_vars() as $k => $v) {
            $GLOBALS[$k] = $v;
        }
    } catch (Throwable $e) {
        error_log('GitHub contributions config failed to load: ' . $e->getMessage());
        gh_respond(500, ['ok' => false, 'error' => 'config_load_failed']);
    }

    $raw = $GLOBALS['githubContributionAccounts'] ?? null;
    if (!is_array($raw) || $raw === []) {
        error_log('GitHub contributions config missing $githubContributionAccounts');
        gh_respond(500, ['ok' => false, 'error' => 'config_missing_accounts']);
    }

    $accounts = [];
    foreach ($raw as $i => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $token = is_string($entry['token'] ?? null) ? trim($entry['token']) : '';
        if ($token === '') {
            continue;
        }
        $label = is_string($entry['label'] ?? null) && trim($entry['label']) !== ''
            ? trim($entry['label'])
            : 'account_' . $i;
        $accounts[] = ['label' => $label, 'token' => $token];
    }

    if ($accounts === []) {
        gh_respond(500, ['ok' => false, 'error' => 'config_no_valid_tokens']);
    }

    return $accounts;
}

// ---------------------------------------------------------------------------
// HTTP helper (curl pattern copied from health-common.php)
// ---------------------------------------------------------------------------

function gh_http_post_json(string $url, array $headers, array $payload, int $timeoutSeconds = 10): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'body' => '', 'error' => 'curl_unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_CONNECTTIMEOUT => 4,
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
// Response cache + persistent snapshot (pattern from health-common.php)
// ---------------------------------------------------------------------------

function gh_cache_path(string $key): string {
    return GH_CACHE_DIR . '/gh-' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
}

function gh_cache_get(string $key, int $ttlSeconds): ?array {
    $path = gh_cache_path($key);
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

function gh_cache_put(string $key, array $data): void {
    if (!is_dir(GH_CACHE_DIR)) {
        @mkdir(GH_CACHE_DIR, 0700, true);
    }
    if (!is_dir(GH_CACHE_DIR)) {
        return;
    }
    @file_put_contents(gh_cache_path($key), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function gh_snapshot_path(string $key): string {
    return GH_SNAPSHOT_DIR . '/snap-gh-' . preg_replace('/[^a-z0-9_-]/i', '_', $key) . '.json';
}

/** Returns ['saved_at' => ISO8601, 'payload' => array] or null. */
function gh_snapshot_get(string $key): ?array {
    $path = gh_snapshot_path($key);
    if (!is_readable($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($data) ? $data : null;
}

function gh_snapshot_put(string $key, array $payload): void {
    if (!is_dir(GH_SNAPSHOT_DIR)) {
        @mkdir(GH_SNAPSHOT_DIR, 0700, true);
    }
    if (!is_dir(GH_SNAPSHOT_DIR)) {
        return;
    }
    $wrapper = ['saved_at' => gmdate('Y-m-d\TH:i:s\Z'), 'payload' => $payload];
    @file_put_contents(gh_snapshot_path($key), json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Last-resort response when every live fetch fails: serve the last good snapshot
 * (marked stale) so the panel never goes blank. Falls back to a 200 ok:false if
 * there is no snapshot yet (200 keeps the CDN from swallowing the error body —
 * the same lesson the health endpoint learned).
 */
function gh_serve_snapshot_or_error(string $snapshotKey, string $reason): void {
    $snap = gh_snapshot_get($snapshotKey);
    if ($snap !== null && isset($snap['payload']) && is_array($snap['payload'])) {
        $payload = $snap['payload'];
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $meta['stale']        = true;
        $meta['stale_reason'] = $reason;
        $meta['as_of']        = $snap['saved_at'] ?? null;
        $payload['meta'] = $meta;
        gh_respond(200, $payload);
    }

    gh_respond(200, ['ok' => false, 'error' => $reason, 'days' => [], 'totalContributions' => 0,
        'meta' => ['accounts' => 0, 'partial' => true, 'stale' => true]]);
}

// ---------------------------------------------------------------------------
// GitHub fetch
// ---------------------------------------------------------------------------

const GH_QUERY = <<<'GQL'
query($from: DateTime!, $to: DateTime!) {
  viewer {
    contributionsCollection(from: $from, to: $to) {
      contributionCalendar {
        totalContributions
        weeks { contributionDays { date contributionCount } }
      }
    }
  }
}
GQL;

/**
 * Fetches one account's contribution calendar. Returns
 * ['ok'=>bool, 'days'=>array<string,int>, 'total'=>int, 'error'=>string].
 * `days` maps 'YYYY-MM-DD' => count.
 */
function gh_fetch_account(array $account, string $from, string $to): array {
    $res = gh_http_post_json(GH_GRAPHQL_URL, [
        'Authorization: Bearer ' . $account['token'],
        'User-Agent: flawnson.com-contributions',
        'Accept: application/json',
    ], [
        'query'     => GH_QUERY,
        'variables' => ['from' => $from, 'to' => $to],
    ], 12);

    if (!$res['ok']) {
        // GraphQL auth failures surface as HTTP 401; everything else as the status.
        return ['ok' => false, 'days' => [], 'total' => 0,
            'error' => 'http_' . ($res['status'] ?: 'error')];
    }

    // GitHub returns 200 with an `errors` array for bad queries / scopes.
    if (!empty($res['json']['errors'])) {
        $msg = $res['json']['errors'][0]['message'] ?? 'graphql_error';
        return ['ok' => false, 'days' => [], 'total' => 0, 'error' => 'graphql: ' . $msg];
    }

    $calendar = $res['json']['data']['viewer']['contributionsCollection']['contributionCalendar'] ?? null;
    if (!is_array($calendar)) {
        return ['ok' => false, 'days' => [], 'total' => 0, 'error' => 'missing_calendar'];
    }

    $days = [];
    foreach (($calendar['weeks'] ?? []) as $week) {
        foreach (($week['contributionDays'] ?? []) as $day) {
            $date = $day['date'] ?? null;
            if (is_string($date) && $date !== '') {
                $days[$date] = ($days[$date] ?? 0) + (int) ($day['contributionCount'] ?? 0);
            }
        }
    }

    return ['ok' => true, 'days' => $days,
        'total' => (int) ($calendar['totalContributions'] ?? 0), 'error' => ''];
}

// ---------------------------------------------------------------------------
// Main handler
// ---------------------------------------------------------------------------

function gh_handle(): void {
    $accounts = gh_load_accounts();

    // Trailing 12 months (contributionCalendar caps the range at one year).
    $toTs   = time();
    $fromTs = $toTs - (364 * 86400);
    $from   = gmdate('Y-m-d\T00:00:00\Z', $fromTs);
    $to     = gmdate('Y-m-d\TH:i:s\Z', $toTs);

    $labels = array_map(static fn($a) => $a['label'], $accounts);
    // Cache key is date-stamped (per UTC day) + build + account labels.
    $cacheKey    = 'contrib_' . md5(json_encode([GH_BUILD, gmdate('Y-m-d'), $labels]));
    // Snapshot key is date-independent so the fallback survives day changes.
    $snapshotKey = 'contrib_' . md5(json_encode([GH_BUILD, $labels]));

    $cached = gh_cache_get($cacheKey, GH_CACHE_TTL);
    if ($cached !== null) {
        gh_respond(200, $cached);
    }

    $merged = [];   // date => summed count
    $total  = 0;
    $failed = [];
    $anyOk  = false;
    $accountTotals = [];   // per-account breakdown, for verifying each token works

    foreach ($accounts as $account) {
        $result = gh_fetch_account($account, $from, $to);
        if (!$result['ok']) {
            $failed[] = ['label' => $account['label'], 'error' => $result['error']];
            $accountTotals[] = ['label' => $account['label'], 'total' => 0, 'ok' => false, 'error' => $result['error']];
            error_log("github-contributions: account '{$account['label']}' failed: {$result['error']}");
            continue;
        }
        $anyOk = true;
        $total += $result['total'];
        $accountTotals[] = ['label' => $account['label'], 'total' => $result['total'], 'ok' => true];
        foreach ($result['days'] as $date => $count) {
            $merged[$date] = ($merged[$date] ?? 0) + $count;
        }
    }

    // Every account failed: serve the last good snapshot instead of a blank panel.
    if (!$anyOk) {
        $reason = $failed[0]['error'] ?? 'all_accounts_failed';
        gh_serve_snapshot_or_error($snapshotKey, $reason);
    }

    // Build a continuous ascending day list across the whole window so the grid
    // has no gaps even on dates where no account recorded a contribution.
    $days = [];
    $startDay = gmdate('Y-m-d', strtotime($from));
    $endDay   = gmdate('Y-m-d', $toTs);
    for ($d = $startDay; $d <= $endDay; $d = gmdate('Y-m-d', strtotime($d . ' +1 day'))) {
        $days[] = ['date' => $d, 'count' => (int) ($merged[$d] ?? 0)];
    }

    $payload = [
        'ok'                 => true,
        'days'               => $days,
        'totalContributions' => $total,
        'meta'               => [
            'accounts'        => count($accounts),
            'account_totals'  => $accountTotals,
            'partial'         => $failed !== [],
            'failed_accounts' => $failed,
            'stale'           => false,
            'as_of'           => gmdate('Y-m-d\TH:i:s\Z'),
            'build'           => GH_BUILD,
        ],
    ];

    gh_cache_put($cacheKey, $payload);
    // Only snapshot a successful (and non-empty) result so a partial/empty fetch
    // never overwrites a good fallback.
    if ($total > 0 || !$payload['meta']['partial']) {
        gh_snapshot_put($snapshotKey, $payload);
    }
    gh_respond(200, $payload);
}

// Tests define GITHUB_CONTRIBUTIONS_NO_DISPATCH to load the helpers above without
// firing the web dispatch.
if (!defined('GITHUB_CONTRIBUTIONS_NO_DISPATCH')) {
    gh_send_cors_headers();
    try {
        gh_handle();
    } catch (Throwable $e) {
        error_log('github-contributions fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        gh_respond(200, ['ok' => false, 'error' => 'internal_error', 'days' => [],
            'totalContributions' => 0, 'meta' => ['stale' => true, 'partial' => true]]);
    }
}
