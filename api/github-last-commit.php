<?php
declare(strict_types=1);

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
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function fail(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_PRETTY_PRINT);
    exit;
}

$tokenPath = '/home/flawhvna/.github-last-commit-token';

if (!file_exists($tokenPath)) {
    fail(500, 'Token file not found.');
}

$token = trim((string) file_get_contents($tokenPath));
if ($token === '') {
    fail(500, 'Token file is empty.');
}

$username = 'flawnson';

$ch = curl_init("https://api.github.com/users/{$username}/events?per_page=100");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2026-03-10',
        'User-Agent: flawnson.com-last-commit-widget',
    ],
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    fail(502, 'GitHub request failed: ' . $curlError);
}

if ($httpCode >= 400) {
    fail($httpCode, 'GitHub API returned HTTP ' . $httpCode);
}

$data = json_decode($response, true);
if (!is_array($data)) {
    fail(500, 'Invalid GitHub response.');
}

$latestPush = null;

foreach ($data as $event) {
    if (($event['type'] ?? null) !== 'PushEvent') {
        continue;
    }

    $createdAt = $event['created_at'] ?? null;
    if (!$createdAt) {
        continue;
    }

    if ($latestPush === null || strtotime($createdAt) > strtotime($latestPush['created_at'])) {
        $latestPush = $event;
    }
}

if ($latestPush === null) {
    fail(404, 'No recent push event found.');
}

echo json_encode([
    'username' => $username,
    'repo' => $latestPush['repo']['name'] ?? null,
    'created_at' => $latestPush['created_at'],
    'public' => $latestPush['public'] ?? null,
], JSON_PRETTY_PRINT);