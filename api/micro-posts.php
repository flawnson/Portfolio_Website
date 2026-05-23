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

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function config_string(string $name, string $default = ''): string {
    $value = $GLOBALS[$name] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function config_enabled(string $name): bool {
    return filter_var($GLOBALS[$name] ?? false, FILTER_VALIDATE_BOOLEAN);
}

function config_int(string $name, int $default, int $min, int $max): int {
    $value = $GLOBALS[$name] ?? $default;
    $intValue = is_numeric($value) ? (int)$value : $default;
    return max($min, min($intValue, $max));
}

$configPath = '/home/flawhvna/private/microblog-config.php';
if (!is_readable($configPath)) {
    error_log("Fwitter config is not readable: {$configPath}");
    respond(500, ['error' => 'config_unreadable']);
}

try {
    include $configPath;
} catch (Throwable $e) {
    error_log("Fwitter config failed to load: " . $e->getMessage());
    respond(500, ['error' => 'config_load_failed']);
}

foreach (['dbHost', 'dbName', 'dbUser', 'dbPass', 'adminToken'] as $requiredConfigName) {
    if (config_string($requiredConfigName) === '') {
        error_log("Fwitter config is missing required value: {$requiredConfigName}");
        respond(500, ['error' => 'config_missing_required_value']);
    }
}

function require_admin_token(): void {
    $providedToken = $_SERVER["HTTP_X_ADMIN_TOKEN"] ?? "";
    if (!hash_equals(config_string('adminToken'), $providedToken)) {
        respond(401, ["error" => "unauthorized"]);
    }
}

function request_json(): array {
    $rawBody = file_get_contents("php://input");
    $json = json_decode($rawBody ?: "", true);

    if (!is_array($json)) {
        respond(400, ["error" => "invalid_json"]);
    }

    return $json;
}

function truncate_text(string $text, int $limit = 700): string {
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return mb_substr($text, 0, $limit) . '...';
}

function http_result_summary(array $result, bool $includeSuccessJson = false): array {
    $summary = [
        'ok' => (bool)($result['ok'] ?? false),
        'status' => (int)($result['status'] ?? 0),
    ];

    $error = (string)($result['error'] ?? '');
    if ($error !== '') {
        $summary['transport_error'] = $error;
    }

    if (isset($result['json']['error'])) {
        $summary['api_error'] = $result['json']['error'];
    } elseif (isset($result['json']['errors'])) {
        $summary['api_errors'] = $result['json']['errors'];
    } elseif ($includeSuccessJson && ($result['ok'] ?? false) && isset($result['json'])) {
        $summary['json'] = $result['json'];
    } elseif (!($result['ok'] ?? false) && isset($result['body']) && is_string($result['body']) && $result['body'] !== '') {
        $summary['body'] = truncate_text($result['body']);
    }

    return $summary;
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

function gemini_response_text(array $response): string {
    $parts = $response['json']['candidates'][0]['content']['parts'] ?? [];
    if (!is_array($parts)) {
        return '';
    }

    $text = '';
    foreach ($parts as $part) {
        if (isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }

    return strtolower(trim($text));
}

function gemini_response_raw_text(array $response): string {
    $parts = $response['json']['candidates'][0]['content']['parts'] ?? [];
    if (!is_array($parts)) {
        return '';
    }

    $text = '';
    foreach ($parts as $part) {
        if (isset($part['text']) && is_string($part['text'])) {
            $text .= $part['text'];
        }
    }

    return trim($text);
}

function gemini_response_details(array $response): array {
    $details = [];

    if (isset($response['json']['candidates'][0]['finishReason'])) {
        $details['finish_reason'] = $response['json']['candidates'][0]['finishReason'];
    }

    if (isset($response['json']['promptFeedback'])) {
        $details['prompt_feedback'] = $response['json']['promptFeedback'];
    }

    return $details;
}

function route_micro_post_result(string $body): array {
    $apiKey = config_string('geminiApiKey');
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'gemini_not_configured'];
    }

    $modelName = config_string('geminiModel', 'gemini-2.5-flash');
    $model = rawurlencode($modelName);
    $generationConfig = [
        'temperature' => 0,
        'maxOutputTokens' => 24,
    ];

    if (strpos($modelName, '2.5') !== false) {
        $generationConfig['thinkingConfig'] = [
            'thinkingBudget' => 0,
        ];
    }

    $prompt = <<<'PROMPT'
You are a routing model. Your ONLY job is to decide which social platform(s) a text-only post should be published to.

Output one or more platform tokens separated by commas. No spaces after commas. No punctuation other than commas. No explanation. No quotes. No reasoning.

Valid tokens: x, bluesky, threads, linkedin

Single-platform output is the default and strongly preferred.
Multi-platform output should be rare — only when a post genuinely and clearly fits two distinct audiences at once.
The ONLY valid multi-platform combination is: x,linkedin

Platform definitions:

x

* Best for:

  * authority
  * startups
  * technical ideas
  * AI
  * systems thinking
  * ambitious opinions
  * concise insight-dense takes
  * intellectual arguments
  * founder energy
  * philosophy
  * experimental ideas
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
  * music (analog and digital)
  * books
  * artistic observations
  * thoughtful reflection
  * poetic or introspective writing
  * weird or niche observations
  * chill hobbies like fountain pens, journaling, piano, keyboards, etc.
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
  * food and restaurants
  * cats and pets
  * internet culture
  * casual daily life
  * relatable humor
  * warm personal moments
  * approachable social content
  * light inspiration
  * active hobbies like motorcycles, sports, etc.
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

linkedin

* Best for:

  * software engineering insights
  * developer tooling and workflows
  * AI/ML technical observations
  * open-source development
  * technical architecture decisions
  * programming language observations
* Tone:

  * professional
  * precise
  * informative
  * never casual
  * never conversational
* Route here ONLY if ALL of the following are true:

  * The language is formal and professional (not casual, witty, jokey, or conversational)
  * The topic is purely technical: code, systems, algorithms, developer tools, AI/ML methods
  * A professional software engineer reading their LinkedIn feed would find it directly valuable
  * It does NOT contain: business strategy, startup culture, personal anecdotes, lifestyle content, health topics, or casual observations
* This platform should trigger rarely. If a post could go to x or linkedin, always prefer x.

Multi-platform rules:

* Output x,linkedin ONLY when the post is simultaneously: technically substantive enough for a professional engineering audience AND punchy/opinionated enough for X
* Never combine linkedin with bluesky or threads
* Strongly prefer single-platform. When uncertain about x,linkedin, output x only.

Guideline rules:

* If a post is about startups, AI, technical systems, culturally experimental, or ambitious ideas -> prefer x
* If a post is artistic, philosophical, or reflective -> prefer bluesky
* If a post is about everyday life, food, fitness, pets, or casual relatable experiences -> prefer threads
* If a post is formally written about engineering, development, or AI/ML methods -> consider linkedin (rare)

Tie-breaking rules:

* Serious/professional -> x
* Thoughtful/artistic -> bluesky
* Casual/social -> threads
* Technical + formal + clearly professional -> linkedin or x,linkedin (rare)
* When uncertain about linkedin -> x

Input post:
{{POST}}

Return only the platform token(s). Valid outputs: x — bluesky — threads — linkedin — x,linkedin
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
            'generationConfig' => $generationConfig,
        ],
        config_int('geminiTimeoutSeconds', 30, 1, 60)
    );

    if (!($response['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => 'gemini_request_failed',
            'response' => http_result_summary($response),
        ];
    }

    $text = gemini_response_text($response);

    $validPlatforms = ['x', 'bluesky', 'threads', 'linkedin'];
    $tokens = array_values(array_filter(array_map('trim', explode(',', $text))));

    $allValid = !empty($tokens) && count($tokens) === count(array_unique($tokens));
    foreach ($tokens as $token) {
        if (!in_array($token, $validPlatforms, true)) {
            $allValid = false;
            break;
        }
    }

    if ($allValid && count($tokens) > 1) {
        $sorted = $tokens;
        sort($sorted);
        $allValid = $sorted === ['linkedin', 'x'];
    }

    if (!$allValid) {
        return [
            'ok' => false,
            'error' => 'invalid_route',
            'raw_output' => truncate_text($text),
            'response' => array_merge(http_result_summary($response), gemini_response_details($response)),
        ];
    }

    return [
        'ok' => true,
        'platforms' => $tokens,
        'raw_output' => $text,
        'response' => array_merge(http_result_summary($response), gemini_response_details($response)),
    ];
}

function route_micro_post(string $body): ?array {
    $result = route_micro_post_result($body);
    return ($result['ok'] ?? false) ? (array)$result['platforms'] : null;
}

function gemini_resolve_mentions(string $body, string $platform, string $apiKey): ?string {
    $modelName = config_string('geminiModel', 'gemini-2.5-flash');
    $model = rawurlencode($modelName);

    $handleExamples = [
        'x'       => '@username (e.g. @sama, @netflix)',
        'bluesky' => '@handle.bsky.social (e.g. @bsky.app, @bluesky.social)',
        'threads' => '@username (e.g. @zuck, @netflix)',
    ];
    $handleFormat = $handleExamples[$platform] ?? '@username';

    $prompt = "You are enhancing a social media post for {$platform} by adding @ mentions for recognizable people and companies.\n\n"
        . "Post to enhance:\n{$body}\n\n"
        . "Instructions:\n"
        . "1. Identify any named people, companies, or organizations mentioned in the post.\n"
        . "2. Use Google Search to find their official {$platform} account handle.\n"
        . "3. Only substitute if you have HIGH confidence this is the correct, official account (not a parody, fan account, or unofficial one).\n"
        . "4. Handle format for {$platform}: {$handleFormat}\n"
        . "5. Replace the entity name in the post text with the @ handle.\n\n"
        . "Return ONLY valid JSON with no markdown, no code fences, no extra text:\n"
        . "{\"resolved\": \"<full post with substitutions applied>\", \"substitutions\": [{\"original\": \"name\", \"handle\": \"@handle\", \"confidence\": \"high\"}]}\n\n"
        . "If you find no entities, or cannot confirm any with high confidence, return:\n"
        . "{\"resolved\": null, \"substitutions\": []}\n\n"
        . "Critical rules:\n"
        . "- Only use \"high\" confidence if search results clearly confirm an official account\n"
        . "- Never guess; if a search doesn't unambiguously confirm the official account, skip it\n"
        . "- Skip ambiguous names that could refer to multiple entities (e.g. \"John\", \"Apple\" as a fruit)\n"
        . "- Keep all other post text exactly as written";

    $generationConfig = [
        'temperature'     => 0,
        'maxOutputTokens' => 512,
    ];

    if (strpos($modelName, '2.5') !== false) {
        $generationConfig['thinkingConfig'] = ['thinkingBudget' => 0];
    }

    $response = http_json_request(
        "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent",
        ['x-goog-api-key: ' . $apiKey],
        [
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'tools'            => [['google_search' => new stdClass()]],
            'generationConfig' => $generationConfig,
        ],
        config_int('geminiTimeoutSeconds', 30, 1, 60)
    );

    if (!($response['ok'] ?? false)) {
        error_log("Fwitter mention resolution API call failed: " . json_encode(http_result_summary($response), JSON_UNESCAPED_SLASHES));
        return null;
    }

    $rawText = gemini_response_raw_text($response);
    if ($rawText === '') {
        return null;
    }

    $rawText = preg_replace('/^```(?:json)?\s*/i', '', $rawText);
    $rawText = preg_replace('/\s*```$/i', '', $rawText);
    $rawText = trim($rawText ?? '');

    $parsed = json_decode($rawText, true);
    if (!is_array($parsed)) {
        error_log("Fwitter mention resolution: unparseable JSON: " . truncate_text($rawText));
        return null;
    }

    $resolved = $parsed['resolved'] ?? null;
    if (!is_string($resolved) || trim($resolved) === '') {
        return null;
    }

    return $resolved;
}

function resolve_mentions_for_syndication(string $body, string $platform): string {
    if ($platform === 'linkedin') {
        return $body;
    }

    $apiKey = config_string('geminiApiKey');
    if ($apiKey === '') {
        return $body;
    }

    try {
        $resolved = gemini_resolve_mentions($body, $platform, $apiKey);
        if ($resolved !== null && $resolved !== $body) {
            error_log("Fwitter mention resolution applied for {$platform}: " . json_encode(['original' => $body, 'resolved' => $resolved], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return $resolved ?? $body;
    } catch (Throwable $e) {
        error_log("Fwitter mention resolution crashed for {$platform}: " . $e->getMessage());
        return $body;
    }
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
        return [
            'ok' => false,
            'error' => 'bluesky_session_failed',
            'status' => $session['status'] ?? 0,
            'json' => $session['json'] ?? null,
            'body' => $session['body'] ?? '',
        ];
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
        return [
            'ok' => false,
            'error' => 'threads_container_failed',
            'status' => $container['status'] ?? 0,
            'json' => $container['json'] ?? null,
            'body' => $container['body'] ?? '',
        ];
    }

    return http_form_request($baseUrl . '/threads_publish', [
        'creation_id' => $creationId,
        'access_token' => $accessToken,
    ]);
}

function publish_to_linkedin(string $body): array {
    $accessToken = config_string('linkedInAccessToken');
    $personUrn = config_string('linkedInPersonUrn');

    if ($accessToken === '' || $personUrn === '') {
        return ['ok' => false, 'error' => 'linkedin_not_configured'];
    }

    return http_json_request(
        'https://api.linkedin.com/v2/ugcPosts',
        [
            'Authorization: Bearer ' . $accessToken,
            'X-Restli-Protocol-Version: 2.0.0',
        ],
        [
            'author' => $personUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $body],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ]
    );
}

function publish_to_platform(string $platform, string $body): array {
    if ($platform === 'x') {
        return publish_to_x($body);
    }

    if ($platform === 'bluesky') {
        return publish_to_bluesky($body);
    }

    if ($platform === 'threads') {
        return publish_to_threads($body);
    }

    if ($platform === 'linkedin') {
        return publish_to_linkedin($body);
    }

    return ['ok' => false, 'error' => 'invalid_platform'];
}

function syndicate_micro_post(string $body, int $postId): array {
    if (!config_enabled('socialSyndicationEnabled')) {
        return [
            'status' => 'disabled',
        ];
    }

    $route = route_micro_post_result($body);
    if (!($route['ok'] ?? false)) {
        error_log("Fwitter syndication skipped for post {$postId}: " . json_encode($route, JSON_UNESCAPED_SLASHES));
        return [
            'status' => 'skipped',
            'reason' => 'route_failed',
            'route' => $route,
        ];
    }

    $platforms = (array)$route['platforms'];
    $results = [];
    $anyOk = false;

    foreach ($platforms as $platform) {
        $syndicationBody = resolve_mentions_for_syndication($body, $platform);
        $result = publish_to_platform($platform, $syndicationBody);
        $summary = http_result_summary($result);

        if ($result['ok'] ?? false) {
            $anyOk = true;
        } else {
            error_log("Fwitter syndication failed for post {$postId} to {$platform}: " . json_encode($summary, JSON_UNESCAPED_SLASHES));
        }

        $results[$platform] = ['ok' => (bool)($result['ok'] ?? false), 'result' => $summary];
    }

    return [
        'status' => $anyOk ? 'published' : 'failed',
        'platforms' => $platforms,
        'route' => $route,
        'results' => $results,
    ];
}

function run_syndication_safely(string $body, int $postId): array {
    try {
        return syndicate_micro_post($body, $postId);
    } catch (Throwable $e) {
        error_log("Fwitter syndication crashed for post {$postId}: " . $e->getMessage());
        return [
            'status' => 'crashed',
            'error' => $e->getMessage(),
        ];
    }
}

function social_config_status(): array {
    return [
        'syndication_enabled' => config_enabled('socialSyndicationEnabled'),
        'curl_available' => function_exists('curl_init'),
        'gemini' => [
            'api_key_present' => config_string('geminiApiKey') !== '',
            'model' => config_string('geminiModel', 'gemini-2.5-flash'),
            'timeout_seconds' => config_int('geminiTimeoutSeconds', 30, 1, 60),
        ],
        'x' => [
            'api_key_present' => config_string('xApiKey') !== '',
            'api_secret_present' => config_string('xApiSecret') !== '',
            'access_token_present' => config_string('xAccessToken') !== '',
            'access_token_secret_present' => config_string('xAccessTokenSecret') !== '',
        ],
        'bluesky' => [
            'handle' => config_string('blueskyHandle'),
            'app_password_present' => config_string('blueskyAppPassword') !== '',
            'service' => config_string('blueskyService', 'https://bsky.social'),
        ],
        'threads' => [
            'user_id_present' => config_string('threadsUserId') !== '',
            'access_token_present' => config_string('threadsAccessToken') !== '',
        ],
        'linkedin' => [
            'access_token_present' => config_string('linkedInAccessToken') !== '',
            'person_urn_present' => config_string('linkedInPersonUrn') !== '',
        ],
    ];
}

function handle_syndication_debug(): void {
    require_admin_token();
    $json = request_json();

    $body = trim((string)($json["body"] ?? ""));
    if ($body === "") {
        respond(422, ["error" => "body_required"]);
    }

    $forcedPlatform = strtolower(trim((string)($json["platform"] ?? "")));
    $publish = filter_var($json["publish"] ?? false, FILTER_VALIDATE_BOOLEAN);

    $debug = [
        'ok' => true,
        'config' => social_config_status(),
    ];

    if ($forcedPlatform !== '') {
        if (!in_array($forcedPlatform, ['x', 'bluesky', 'threads', 'linkedin'], true)) {
            respond(422, ["error" => "invalid_platform"]);
        }

        $platforms = [$forcedPlatform];
        $debug['route'] = ['ok' => true, 'forced' => true, 'platforms' => $platforms];
    } else {
        $route = route_micro_post_result($body);
        $debug['route'] = $route;

        if (!($route['ok'] ?? false)) {
            $debug['publish'] = ['skipped' => true, 'reason' => 'route_failed'];
            respond(200, $debug);
        }

        $platforms = (array)$route['platforms'];
    }

    $mentionResults = [];
    foreach ($platforms as $plt) {
        $resolved = resolve_mentions_for_syndication($body, $plt);
        if ($resolved !== $body) {
            $mentionResults[$plt] = $resolved;
        }
    }
    $debug['mentions'] = [
        'changed' => !empty($mentionResults),
        'resolved_per_platform' => empty($mentionResults) ? null : $mentionResults,
    ];

    if (!$publish) {
        $debug['publish'] = ['skipped' => true, 'reason' => 'publish_false', 'platforms' => $platforms];
        respond(200, $debug);
    }

    $publishResults = [];
    foreach ($platforms as $plt) {
        $pBody = $mentionResults[$plt] ?? $body;
        $publishResults[$plt] = http_result_summary(publish_to_platform($plt, $pBody), true);
    }
    $debug['publish'] = ['platforms' => $platforms, 'results' => $publishResults];

    respond(200, $debug);
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_GET["syndication_debug"])) {
    handle_syndication_debug();
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

    // Respond immediately — post is saved; syndication runs after the client gets its response
    http_response_code(201);
    header('Content-Type: application/json');
    $responseJson = json_encode(
        ["ok" => true, "id" => $postId, "syndication" => "pending"],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    header('Content-Length: ' . strlen($responseJson));
    echo $responseJson;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ob_flush();
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
