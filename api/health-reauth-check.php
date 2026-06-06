<?php
declare(strict_types=1);

/**
 * Weekly/daily re-auth watchdog for the Google Health integration.
 *
 * Run from cPanel cron (CLI). It checks whether the stored refresh token can
 * still mint an access token; if it has expired/been revoked (the ~7-day
 * Testing-mode limit), it emails you a one-tap authorize link so reconnecting
 * takes ~10 seconds. It de-dupes via a flag file so you get exactly ONE email
 * per outage, and clears the flag automatically once you reconnect.
 *
 * Cron example (daily at 08:00 — adjust the php path to what cPanel shows):
 *   0 8 * * * /usr/local/bin/php /home/flawhvna/public_html/api/health-reauth-check.php >/dev/null 2>&1
 *
 * It is CLI-first. If hit over the web it requires ?token=<adminToken> so it
 * can't be triggered by random visitors.
 */

require __DIR__ . '/health-common.php';

const HEALTH_REAUTH_FLAG = '/home/flawhvna/private/health-reauth-alert.flag';
const HEALTH_REAUTH_LOG  = '/home/flawhvna/private/health-reauth-check.log';

$isCli = (PHP_SAPI === 'cli');

health_load_config();

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals(health_config_string('adminToken'), (string) ($_GET['token'] ?? ''))) {
        http_response_code(401);
        echo "unauthorized\n";
        exit;
    }
}

$tok = health_get_access_token();
$needsReauth = (!$tok['ok'] && $tok['error'] === 'needs_reauth');

if ($needsReauth) {
    if (!file_exists(HEALTH_REAUTH_FLAG)) {
        $sent = health_send_reauth_email();
        @file_put_contents(HEALTH_REAUTH_FLAG, gmdate('c') . "\n", LOCK_EX);
        health_reauth_log('needs_reauth: alert ' . ($sent ? 'emailed' : 'EMAIL FAILED'));
        // stdout so an optional cPanel cron-notification email also catches it.
        echo ($sent ? "needs_reauth: reconnect email sent\n" : "needs_reauth: email send FAILED\n");
    } else {
        health_reauth_log('needs_reauth: already alerted, staying quiet');
    }
} else {
    if (file_exists(HEALTH_REAUTH_FLAG)) {
        @unlink(HEALTH_REAUTH_FLAG);
        health_reauth_log('recovered: token healthy again, cleared alert flag');
        echo "recovered: Google Health reconnected\n";
    } else {
        health_reauth_log($tok['ok'] ? 'ok: token healthy' : ('error: ' . $tok['error']));
    }
}

function health_send_reauth_email(): bool {
    $to = health_config_string('googleHealthAlertEmail', 'flawnsontong1@gmail.com');
    if ($to === '') {
        $to = 'flawnsontong1@gmail.com';
    }

    $authorizeUrl = 'https://flawnson.com/api/health-auth.php?action=authorize&token='
        . rawurlencode(health_config_string('adminToken'));

    $subject = 'Reconnect Google Health (token expired)';
    $body = "Your Google Health connection needs a quick reconnect.\n\n"
        . "Because the app runs in OAuth Testing mode, Google expires the token about every 7 days.\n"
        . "Your health panel keeps showing the last snapshot in the meantime, but it has stopped updating.\n\n"
        . "Tap to reconnect (takes ~10 seconds):\n"
        . $authorizeUrl . "\n\n"
        . "After you approve Google's consent screen, the panel resumes updating automatically.\n";

    $headers = "From: Health Watchdog <no-reply@flawnson.com>\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n";

    return @mail($to, $subject, $body, $headers);
}

function health_reauth_log(string $msg): void {
    @file_put_contents(HEALTH_REAUTH_LOG, gmdate('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}
