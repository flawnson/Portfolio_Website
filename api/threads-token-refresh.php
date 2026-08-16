<?php
declare(strict_types=1);

/**
 * Threads access-token keep-alive.
 *
 * Long-lived Threads tokens expire after ~60 days, and once expired they can
 * NOT be refreshed — the account must be re-authorized by hand. This script
 * runs from cPanel cron and refreshes the token while it is still valid
 * (Meta allows refreshing any token older than 24h), persisting the
 * replacement to the token store that micro-posts.php reads first. Run it
 * weekly and the token never expires again.
 *
 * If the token has already expired (refresh returns an OAuthException), it
 * emails you ONE alert (de-duped via a flag file, same pattern as
 * health-reauth-check.php) with instructions, and clears the flag once a
 * working token is back in place.
 *
 * Cron example (weekly, Monday 08:00 — use the php path cPanel shows):
 *   0 8 * * 1 /usr/local/bin/php /home/flawhvna/public_html/api/threads-token-refresh.php >/dev/null 2>&1
 *
 * CLI-first. If hit over the web it requires ?token=<adminToken>.
 */

const THREADS_CONFIG_PATH = '/home/flawhvna/private/microblog-config.php';
const THREADS_REAUTH_FLAG = '/home/flawhvna/private/threads-reauth-alert.flag';
const THREADS_REFRESH_LOG = '/home/flawhvna/private/threads-token-refresh.log';

$isCli = (PHP_SAPI === 'cli');

if (!is_readable(THREADS_CONFIG_PATH)) {
    if (!$isCli) {
        http_response_code(500);
    }
    echo "config unreadable\n";
    exit(1);
}
include THREADS_CONFIG_PATH;

function tt_config(string $name, string $default = ''): string {
    $value = $GLOBALS[$name] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function tt_log(string $message): void {
    @file_put_contents(THREADS_REFRESH_LOG, gmdate('c') . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
}

function tt_http_get(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = is_string($body) ? json_decode($body, true) : null;
    return [
        'ok' => $error === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'json' => is_array($decoded) ? $decoded : null,
        'error' => $error,
    ];
}

function tt_send_reauth_email(string $detail): bool {
    $to = tt_config('threadsAlertEmail', 'flawnsontong1@gmail.com');

    $subject = 'Reconnect Threads (Fwitter token expired)';
    $body = "Fwitter can no longer post to Threads: the access token has expired and an\n"
        . "expired token cannot be refreshed automatically.\n\n"
        . "API said: {$detail}\n\n"
        . "To fix (takes a couple of minutes):\n"
        . "1. Open https://developers.facebook.com/, pick your Threads app, and generate\n"
        . "   a new long-lived Threads access token for your account.\n"
        . "2. Paste it into \$threadsAccessToken in " . THREADS_CONFIG_PATH . "\n"
        . "   (cPanel File Manager, same as the original setup).\n"
        . "3. Delete " . tt_config('threadsTokenStorePath', '/home/flawhvna/private/threads-token.json') . "\n"
        . "   so the stale stored token stops shadowing the new one.\n\n"
        . "The weekly cron will then keep the new token refreshed so this does not recur.\n";

    $headers = "From: Fwitter Watchdog <no-reply@flawnson.com>\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n";

    return @mail($to, $subject, $body, $headers);
}

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals(tt_config('adminToken'), (string)($_GET['token'] ?? ''))) {
        http_response_code(401);
        echo "unauthorized\n";
        exit;
    }
}

$storePath = tt_config('threadsTokenStorePath', '/home/flawhvna/private/threads-token.json');

// Freshest known token: the store written by a previous refresh, else config.
$token = '';
if (is_readable($storePath)) {
    $stored = json_decode((string)@file_get_contents($storePath), true);
    if (is_array($stored) && is_string($stored['access_token'] ?? null)) {
        $token = trim($stored['access_token']);
    }
}
if ($token === '') {
    $token = tt_config('threadsAccessToken');
}
if ($token === '') {
    tt_log('no token configured, nothing to refresh');
    echo "no token configured\n";
    exit(1);
}

$result = tt_http_get(
    'https://graph.threads.net/refresh_access_token?grant_type=th_refresh_token&access_token=' . rawurlencode($token)
);

$newToken = is_string($result['json']['access_token'] ?? null) ? trim($result['json']['access_token']) : '';

if (($result['ok'] ?? false) && $newToken !== '') {
    $expiresIn = (int)($result['json']['expires_in'] ?? 0);
    $payload = json_encode([
        'access_token' => $newToken,
        'expires_in' => $expiresIn,
        'refreshed_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);

    // Atomic write so micro-posts.php never reads a half-written store.
    $tmpPath = $storePath . '.tmp';
    if (@file_put_contents($tmpPath, $payload . "\n", LOCK_EX) === false || !@rename($tmpPath, $storePath)) {
        tt_log('refresh succeeded but writing the token store FAILED');
        echo "refresh ok but store write failed\n";
        exit(1);
    }
    @chmod($storePath, 0600);

    if (file_exists(THREADS_REAUTH_FLAG)) {
        @unlink(THREADS_REAUTH_FLAG);
        tt_log('recovered: token refreshed after alert, cleared flag');
    }
    $days = $expiresIn > 0 ? (string)(int)floor($expiresIn / 86400) : '?';
    tt_log("ok: token refreshed, expires in {$days} days");
    echo "refreshed, expires in {$days} days\n";
    exit(0);
}

$apiError = $result['json']['error'] ?? null;
$detail = is_array($apiError)
    ? (string)($apiError['message'] ?? json_encode($apiError))
    : ($result['error'] !== '' ? $result['error'] : "http status {$result['status']}");
$isOAuthFailure = is_array($apiError) && (string)($apiError['type'] ?? '') === 'OAuthException';

if ($isOAuthFailure) {
    if (!file_exists(THREADS_REAUTH_FLAG)) {
        $sent = tt_send_reauth_email($detail);
        @file_put_contents(THREADS_REAUTH_FLAG, gmdate('c') . "\n", LOCK_EX);
        tt_log('needs_reauth: ' . $detail . ' — alert ' . ($sent ? 'emailed' : 'EMAIL FAILED'));
        echo ($sent ? "needs_reauth: reconnect email sent\n" : "needs_reauth: email send FAILED\n");
    } else {
        tt_log('needs_reauth: already alerted, staying quiet');
        echo "needs_reauth: already alerted\n";
    }
    exit(1);
}

// Transient failure (network, 5xx, or token <24h old): log and let next run retry.
tt_log('transient refresh failure: ' . $detail);
echo "transient failure: {$detail}\n";
exit(1);
