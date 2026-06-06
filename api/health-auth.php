<?php
declare(strict_types=1);

/**
 * One-time Google OAuth 2.0 flow for the Health API.
 *
 *   GET /api/health-auth.php?action=authorize&token=<adminToken>
 *       Admin-gated. Redirects you to Google's consent screen with the
 *       configured scopes, access_type=offline and prompt=consent so Google
 *       returns a refresh token. A CSRF `state` value is stored in the token
 *       store and verified on callback.
 *
 *   GET /api/health-auth.php?action=callback&code=...&state=...
 *       Google redirects here after consent. Exchanges the code for tokens and
 *       persists the refresh token. This URL is the Authorized Redirect URI you
 *       registered in the Google Cloud OAuth client.
 *
 * Re-run the authorize step any time data calls return `needs_reauth`
 * (e.g. the 7-day refresh-token expiry in OAuth "Testing" mode).
 */

require __DIR__ . '/health-common.php';

health_load_config();

$action = $_GET['action'] ?? '';

if ($action === 'authorize') {
    health_authorize();
} elseif ($action === 'callback') {
    health_callback();
} else {
    header('Content-Type: application/json; charset=utf-8');
    health_respond(400, ['ok' => false, 'error' => 'unknown_action', 'expected' => ['authorize', 'callback']]);
}

function health_authorize(): void {
    // Gate behind the admin token so random visitors can't start an OAuth flow.
    health_require_admin_token((string) ($_GET['token'] ?? ''));

    $state = bin2hex(random_bytes(16));
    $store = health_read_token_store();
    $store['oauth_state']            = $state;
    $store['oauth_state_created_at'] = time();
    if (!health_write_token_store($store)) {
        header('Content-Type: application/json; charset=utf-8');
        health_respond(500, ['ok' => false, 'error' => 'token_store_not_writable',
            'hint' => 'Ensure ' . HEALTH_PRIVATE_DIR . ' is writable by PHP.']);
    }

    $params = [
        'client_id'     => health_config_string('googleHealthClientId'),
        'redirect_uri'  => health_config_string('googleHealthRedirectUri'),
        'response_type' => 'code',
        'scope'         => health_config_string('googleHealthScopes'),
        'access_type'   => 'offline',
        'include_granted_scopes' => 'true',
        'prompt'        => 'consent',
        'state'         => $state,
    ];

    header('Location: ' . GOOGLE_AUTH_URL . '?' . http_build_query($params), true, 302);
    exit;
}

function health_callback(): void {
    header('Content-Type: text/html; charset=utf-8');

    $error = $_GET['error'] ?? '';
    if ($error !== '') {
        health_callback_page(false, 'Google returned an error: ' . htmlspecialchars((string) $error, ENT_QUOTES));
    }

    $code  = (string) ($_GET['code'] ?? '');
    $state = (string) ($_GET['state'] ?? '');

    if ($code === '') {
        health_callback_page(false, 'Missing authorization code.');
    }

    // CSRF: state must match what we stored at authorize time (and be recent).
    $store = health_read_token_store();
    $expectedState = (string) ($store['oauth_state'] ?? '');
    $stateAge = time() - (int) ($store['oauth_state_created_at'] ?? 0);
    if ($expectedState === '' || !hash_equals($expectedState, $state) || $stateAge > 600) {
        health_callback_page(false, 'State mismatch or expired. Please restart the authorize step.');
    }

    // One-time use: clear the state regardless of exchange outcome.
    unset($store['oauth_state'], $store['oauth_state_created_at']);
    health_write_token_store($store);

    $result = health_exchange_code($code);
    if (!$result['ok']) {
        health_callback_page(false, 'Token exchange failed: ' . htmlspecialchars($result['error'], ENT_QUOTES));
    }

    health_callback_page(true, 'Your Google Health refresh token was saved. You can close this tab.');
}

/**
 * Renders a tiny self-contained HTML result page (the callback is hit in a
 * browser, not by fetch), then exits.
 */
function health_callback_page(bool $ok, string $message): void {
    http_response_code($ok ? 200 : 400);
    $title = $ok ? '✅ Connected' : '⚠️ Could not connect';
    $color = $ok ? '#1a7f37' : '#b42318';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Google Health · ' . ($ok ? 'Connected' : 'Error') . '</title>'
        . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
        . 'max-width:36rem;margin:12vh auto;padding:0 1.25rem;line-height:1.5;color:#1f2328}'
        . 'h1{color:' . $color . ';font-size:1.4rem}code{background:#f3f4f6;padding:.1rem .35rem;border-radius:4px}'
        . '</style></head><body><h1>' . $title . '</h1><p>' . $message . '</p>'
        . ($ok ? '<p>Health data is now available at <code>/api/health-metrics.php</code>.</p>' : '')
        . '</body></html>';
    exit;
}
