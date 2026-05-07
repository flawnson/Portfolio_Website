<?php
declare(strict_types=1);

$allowedOrigins = [
    'https://flawnson.com',
    'http://localhost:63342',
    'http://localhost:8000',
    'http://127.0.0.1:8000',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Vary: Origin");
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Admin-Token");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

ignore_user_abort(true);

require '/home/flawhvna/private/microblog-config.php';

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function config_string(string $name, string $default = ''): string {
    $value = $GLOBALS[$name] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function http_json_request(string $url, array $headers, array $payload, int $timeoutSeconds = 6): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $timeoutSeconds,
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
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

function http_form_request(string $url, array $fields, int $timeoutSeconds = 6): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $timeoutSeconds,
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
        'body' => is_string($body) ? $body : '',
        'error' => $error,
    ];
}

function route_micro_post(string $body): ?string {
    $apiKey = config_string('geminiApiKey');
    if ($apiKey === '') {
        return null;
    }

    $model = rawurlencode(config_string('geminiModel', 'gemini-2.5-flash'));
    $prompt = <<<'PROMPT'
You are a routing model. Your ONLY job is to decide which social platform a text-only post should be published to.

You must output EXACTLY ONE of these three tokens and NOTHING else:

x
bluesky
threads

No punctuation.
No explanation.
No quotes.
No reasoning.
No extra words.

Platform definitions:

x

* Best for:

  * authority
  * startups
  * rare disease advocacy
  * technical ideas
  * AI
  * systems thinking
  * ambitious opinions
  * concise insight-dense takes
  * intellectual arguments
  * founder energy
  * professional reputation
* Tone:

  * sharp
  * high signal
  * direct
  * insightful
  * opinionated
* Route here if the post sounds:

  * strategic
  * analytical
  * technically informed
  * mission-driven
  * professionally valuable

bluesky

* Best for:

  * creativity
  * music
  * books
  * philosophy
  * internet culture
  * artistic observations
  * thoughtful reflection
  * experimental ideas
  * poetic or introspective writing
  * weird or niche observations
* Tone:

  * reflective
  * curious
  * artistic
  * intellectual but soft
* Route here if the post feels:

  * exploratory
  * contemplative
  * culturally aware
  * emotionally nuanced
  * creatively expressive

threads

* Best for:

  * lifestyle
  * fitness
  * food
  * cats
  * casual daily life
  * relatable humor
  * warm personal moments
  * approachable social content
  * light inspiration
* Tone:

  * casual
  * warm
  * friendly
  * accessible
  * emotionally immediate
* Route here if the post feels:

  * conversational
  * cozy
  * aesthetic
  * low-stakes
  * socially relatable

Priority rules:

* If a post is about startups, rare disease work, AI, technical systems, or ambitious ideas -> prefer x
* If a post is artistic, philosophical, reflective, or culturally experimental -> prefer bluesky
* If a post is about everyday life, food, fitness, pets, or casual relatable experiences -> prefer threads

Tie-breaking rules:

* Serious/professional -> x
* Thoughtful/artistic -> bluesky
* Casual/social -> threads

Input post:
{{POST}}

Return only:
x
or
bluesky
or
threads
PROMPT;

    $response = http_json_request(
        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
        ['x-goog-api-key: ' . $apiKey],
        [
            'contents' => [
                [
                    'parts' => [
                        ['text' => str_replace('{{POST}}', $body, $prompt)],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0,
                'maxOutputTokens' => 5,
            ],
        ]
    );

    $text = '';
    if (isset($response['json']['candidates'][0]['content']['parts'][0]['text'])) {
        $text = strtolower(trim((string)$response['json']['candidates'][0]['content']['parts'][0]['text']));
    }

    return in_array($text, ['x', 'bluesky', 'threads'], true) ? $text : null;
}

function publish_to_bluesky(string $body): array {
    $handle = config_string('blueskyHandle');
    $password = config_string('blueskyAppPassword');
    $service = rtrim(config_string('blueskyService', 'https://bsky.social'), '/');

    if ($handle === '' || $password === '') {
        return ['ok' => false, 'error' => 'bluesky_not_configured'];
    }

    $session = http_json_request($service . '/xrpc/com.atproto.server.createSession', [], [
        'identifier' => $handle,
        'password' => $password,
    ]);

    $accessJwt = (string)($session['json']['accessJwt'] ?? '');
    $repo = (string)($session['json']['did'] ?? $handle);
    if (!$session['ok'] || $accessJwt === '') {
        return ['ok' => false, 'error' => 'bluesky_session_failed', 'status' => $session['status'] ?? 0];
    }

    return http_json_request(
        $service . '/xrpc/com.atproto.repo.createRecord',
        ['Authorization: Bearer ' . $accessJwt],
        [
            'repo' => $repo,
            'collection' => 'app.bsky.feed.post',
            'record' => [
                '$type' => 'app.bsky.feed.post',
                'text' => $body,
                'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ]
    );
}

function oauth1_header(string $method, string $url, array $bodyParams = []): string {
    $consumerKey = config_string('xApiKey');
    $consumerSecret = config_string('xApiSecret');
    $token = config_string('xAccessToken');
    $tokenSecret = config_string('xAccessTokenSecret');

    $oauth = [
        'oauth_consumer_key' => $consumerKey,
        'oauth_nonce' => bin2hex(random_bytes(16)),
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => (string)time(),
        'oauth_token' => $token,
        'oauth_version' => '1.0',
    ];

    $signatureParams = array_merge($oauth, $bodyParams);
    ksort($signatureParams);

    $encodedPairs = [];
    foreach ($signatureParams as $key => $value) {
        $encodedPairs[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
    }

    $baseString = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode(implode('&', $encodedPairs));
    $signingKey = rawurlencode($consumerSecret) . '&' . rawurlencode($tokenSecret);
    $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));

    $headerPairs = [];
    foreach ($oauth as $key => $value) {
        $headerPairs[] = rawurlencode($key) . '="' . rawurlencode((string)$value) . '"';
    }

    return 'Authorization: OAuth ' . implode(', ', $headerPairs);
}

function publish_to_x(string $body): array {
    if (
        config_string('xApiKey') === '' ||
        config_string('xApiSecret') === '' ||
        config_string('xAccessToken') === '' ||
        config_string('xAccessTokenSecret') === ''
    ) {
        return ['ok' => false, 'error' => 'x_not_configured'];
    }

    $url = 'https://api.x.com/2/tweets';
    return http_json_request($url, [oauth1_header('POST', $url)], ['text' => $body]);
}

function publish_to_threads(string $body): array {
    $userId = config_string('threadsUserId');
    $accessToken = config_string('threadsAccessToken');

    if ($userId === '' || $accessToken === '') {
        return ['ok' => false, 'error' => 'threads_not_configured'];
    }

    $baseUrl = 'https://graph.threads.net/v1.0/' . rawurlencode($userId);
    $container = http_form_request($baseUrl . '/threads', [
        'media_type' => 'TEXT',
        'text' => $body,
        'access_token' => $accessToken,
    ]);

    $creationId = (string)($container['json']['id'] ?? '');
    if (!$container['ok'] || $creationId === '') {
        return ['ok' => false, 'error' => 'threads_container_failed', 'status' => $container['status'] ?? 0];
    }

    return http_form_request($baseUrl . '/threads_publish', [
        'creation_id' => $creationId,
        'access_token' => $accessToken,
    ]);
}

function syndicate_micro_post(string $body, int $postId): void {
    if (!filter_var($GLOBALS['socialSyndicationEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        return;
    }

    $platform = route_micro_post($body);
    if ($platform === null) {
        error_log("Flitter syndication skipped for post {$postId}: routing_failed");
        return;
    }

    if ($platform === 'x') {
        $result = publish_to_x($body);
    } elseif ($platform === 'bluesky') {
        $result = publish_to_bluesky($body);
    } else {
        $result = publish_to_threads($body);
    }

    if (!($result['ok'] ?? false)) {
        $status = (int)($result['status'] ?? 0);
        $error = (string)($result['error'] ?? 'unknown_error');
        error_log("Flitter syndication failed for post {$postId} to {$platform}: {$status} {$error}");
    }
}

function run_syndication_safely(string $body, int $postId): void {
    try {
        syndicate_micro_post($body, $postId);
    } catch (Throwable $e) {
        error_log("Flitter syndication crashed for post {$postId}: " . $e->getMessage());
    }
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    respond(500, [
        'error' => 'database_connection_failed',
    ]);
}

if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $limit = isset($_GET["limit"]) ? (int) $_GET["limit"] : 50;
    $limit = max(1, min($limit, 100));
    $beforeId = isset($_GET["before_id"]) ? (int) $_GET["before_id"] : 0;

    $sql = "
        SELECT id, body, created_at
        FROM micro_posts
    ";

    if ($beforeId > 0) {
        $sql .= " WHERE id < :before_id ";
    }

    $sql .= "
        ORDER BY id DESC
        LIMIT :limit_plus_one
    ";

    $stmt = $pdo->prepare($sql);
    if ($beforeId > 0) {
        $stmt->bindValue(":before_id", $beforeId, PDO::PARAM_INT);
    }
    $stmt->bindValue(":limit_plus_one", $limit + 1, PDO::PARAM_INT);
    $stmt->execute();

    $posts = $stmt->fetchAll();
    $hasMore = count($posts) > $limit;
    if ($hasMore) {
        array_pop($posts);
    }

    $posts = array_map(static function (array $post): array {
        return [
            'id' => (int) $post['id'],
            'body' => (string) $post['body'],
            'created_at' => (string) $post['created_at'],
        ];
    }, $posts);

    $nextBeforeId = null;
    if (!empty($posts)) {
        $lastPost = end($posts);
        if (is_array($lastPost) && isset($lastPost['id'])) {
            $nextBeforeId = (int) $lastPost['id'];
        }
    }

    respond(200, [
        'posts' => $posts,
        'has_more' => $hasMore,
        'next_before_id' => $nextBeforeId,
    ]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $providedToken = $_SERVER["HTTP_X_ADMIN_TOKEN"] ?? "";
    if (!hash_equals($adminToken, $providedToken)) {
        respond(401, ["error" => "unauthorized"]);
    }

    $rawBody = file_get_contents("php://input");
    $json = json_decode($rawBody ?: "", true);

    if (!is_array($json)) {
        respond(400, ["error" => "invalid_json"]);
    }

    $body = trim((string)($json["body"] ?? ""));
    if ($body === "") {
        respond(422, ["error" => "body_required"]);
    }

    if (mb_strlen($body) > 1000) {
        respond(422, ["error" => "body_too_long"]);
    }

    $stmt = $pdo->prepare("
        INSERT INTO micro_posts (body)
        VALUES (:body)
    ");
    $stmt->execute([
        ":body" => $body,
    ]);

    $postId = (int)$pdo->lastInsertId();

    http_response_code(201);
    echo json_encode([
        "ok" => true,
        "id" => $postId,
        "syndication" => filter_var($GLOBALS['socialSyndicationEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? "queued" : "disabled",
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        flush();
    }

    run_syndication_safely($body, $postId);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "PUT" || $_SERVER["REQUEST_METHOD"] === "PATCH") {
    $providedToken = $_SERVER["HTTP_X_ADMIN_TOKEN"] ?? "";
    if (!hash_equals($adminToken, $providedToken)) {
        respond(401, ["error" => "unauthorized"]);
    }

    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    if ($id <= 0) {
        respond(422, ["error" => "invalid_id"]);
    }

    $rawBody = file_get_contents("php://input");
    $json = json_decode($rawBody ?: "", true);

    if (!is_array($json)) {
        respond(400, ["error" => "invalid_json"]);
    }

    $body = trim((string)($json["body"] ?? ""));
    if ($body === "") {
        respond(422, ["error" => "body_required"]);
    }

    if (mb_strlen($body) > 1000) {
        respond(422, ["error" => "body_too_long"]);
    }

    $stmt = $pdo->prepare("
        UPDATE micro_posts
        SET body = :body
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ":body" => $body,
        ":id" => $id,
    ]);

    respond(200, [
        "ok" => true,
        "updated" => $stmt->rowCount() > 0,
    ]);
}

if ($_SERVER["REQUEST_METHOD"] === "DELETE") {
    $providedToken = $_SERVER["HTTP_X_ADMIN_TOKEN"] ?? "";
    if (!hash_equals($adminToken, $providedToken)) {
        respond(401, ["error" => "unauthorized"]);
    }

    $id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;
    if ($id <= 0) {
        respond(422, ["error" => "invalid_id"]);
    }

    $stmt = $pdo->prepare("
        DELETE FROM micro_posts
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ":id" => $id,
    ]);

    respond(200, [
        "ok" => true,
        "deleted" => $stmt->rowCount() > 0,
    ]);
}

respond(405, ["error" => "method_not_allowed"]);
