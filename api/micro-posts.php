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

function finish_response_early(): void {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    // LiteSpeed web server equivalent (lsphp/LSAPI)
    if (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
        return;
    }
    // Generic fallback: tell the client the connection is closing,
    // then flush everything out. Works on most Apache/CGI setups.
    if (!headers_sent()) {
        header('Connection: close');
    }
    if (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
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

function http_get_request(string $url, array $headers = [], int $timeoutSeconds = 6): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => $headers,
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

function http_binary_request(string $url, string $mimeType, string $binaryData, array $headers, int $timeoutSeconds = 30): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $binaryData,
        CURLOPT_HTTPHEADER => array_merge(["Content-Type: {$mimeType}"], $headers),
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

function http_form_request_with_headers(string $url, array $fields, array $headers, int $timeoutSeconds = 30): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl_unavailable'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
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

Output exactly one platform token. No punctuation. No explanation. No quotes. No reasoning.

Valid tokens: x, bluesky, threads

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

Guideline rules:

* If a post is about startups, AI, technical systems, culturally experimental, or ambitious ideas -> prefer x
* If a post is artistic, philosophical, or reflective -> prefer bluesky
* If a post is about everyday life, food, fitness, pets, or casual relatable experiences -> prefer threads
Tie-breaking rules:

* Serious/professional -> x
* Thoughtful/artistic -> bluesky
* Casual/social -> threads

Input post:
{{POST}}

Return only the platform token. Valid outputs: x — bluesky — threads
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

    $validPlatforms = ['x', 'bluesky', 'threads'];
    $tokens = array_values(array_filter(array_map('trim', explode(',', $text))));

    $allValid = !empty($tokens) && count($tokens) === count(array_unique($tokens));
    foreach ($tokens as $token) {
        if (!in_array($token, $validPlatforms, true)) {
            $allValid = false;
            break;
        }
    }

    if ($allValid && count($tokens) > 1) {
        $allValid = false;
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

function threads_fetch_permalink(string $threadId, string $accessToken): ?string {
    $result = http_get_request(
        'https://graph.threads.net/v1.0/' . rawurlencode($threadId) . '?fields=permalink&access_token=' . rawurlencode($accessToken)
    );
    $permalink = (string)($result['json']['permalink'] ?? '');
    return $permalink !== '' ? $permalink : null;
}

function extract_platform_post_id(string $platform, array $result): ?array {
    if (!($result['ok'] ?? false) || !isset($result['json'])) {
        return null;
    }

    $json = $result['json'];

    if ($platform === 'x') {
        $postId = (string)($json['data']['id'] ?? '');
        return $postId !== '' ? ['post_id' => $postId] : null;
    }

    if ($platform === 'threads') {
        $postId = (string)($json['id'] ?? '');
        if ($postId === '') return null;
        $data = ['post_id' => $postId];
        $url = (string)($json['permalink'] ?? '');
        if ($url !== '') $data['url'] = $url;
        return $data;
    }

    if ($platform === 'bluesky') {
        $uri = (string)($json['uri'] ?? '');
        $cid = (string)($json['cid'] ?? '');
        return ($uri !== '' && $cid !== '') ? ['uri' => $uri, 'cid' => $cid] : null;
    }

    return null;
}

function compress_image(string $filePath, string $mimeType): string {
    $src = false;
    switch ($mimeType) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($filePath); break;
        case 'image/png':  $src = @imagecreatefrompng($filePath);  break;
        case 'image/webp': $src = @imagecreatefromwebp($filePath); break;
        case 'image/gif':  $src = @imagecreatefromgif($filePath);  break;
    }

    if ($src === false) {
        return (string)file_get_contents($filePath);
    }

    $origW = imagesx($src);
    $origH = imagesy($src);
    $maxDim = 2048;

    if ($origW > $maxDim || $origH > $maxDim) {
        if ($origW >= $origH) {
            $newW = $maxDim;
            $newH = (int)round($origH * ($maxDim / $origW));
        } else {
            $newH = $maxDim;
            $newW = (int)round($origW * ($maxDim / $origH));
        }
        $dst = imagecreatetruecolor($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
        imagedestroy($src);
        $src = $dst;
    }

    ob_start();
    imagejpeg($src, null, 85);
    $binary = (string)ob_get_clean();
    imagedestroy($src);

    if (strlen($binary) > 921600) {
        $src2 = @imagecreatefromstring($binary);
        if ($src2 !== false) {
            ob_start();
            imagejpeg($src2, null, 70);
            $binary = (string)ob_get_clean();
            imagedestroy($src2);
        }
    }

    return $binary;
}

function upload_image_to_x(string $binaryData, string $mimeType): ?string {
    $base64 = base64_encode($binaryData);
    $url = 'https://upload.twitter.com/1.1/media/upload.json';
    $params = ['media_data' => $base64];
    $authHeader = oauth1_header('POST', $url, $params);
    $result = http_form_request_with_headers($url, $params, [$authHeader], 60);

    if (!($result['ok'] ?? false)) {
        error_log("Fwitter X image upload failed: " . json_encode(http_result_summary($result), JSON_UNESCAPED_SLASHES));
        return null;
    }

    $mediaId = (string)($result['json']['media_id_string'] ?? '');
    return $mediaId !== '' ? $mediaId : null;
}

function upload_image_to_bluesky(string $binaryData, string $mimeType, string $accessJwt, string $service): ?array {
    $result = http_binary_request(
        $service . '/xrpc/com.atproto.repo.uploadBlob',
        $mimeType,
        $binaryData,
        ['Authorization: Bearer ' . $accessJwt],
        60
    );

    if (!($result['ok'] ?? false)) {
        error_log("Fwitter Bluesky image upload failed: " . json_encode(http_result_summary($result), JSON_UNESCAPED_SLASHES));
        return null;
    }

    $blob = $result['json']['blob'] ?? null;
    return is_array($blob) ? $blob : null;
}

function temp_image_sign(string $filename): string {
    $secret = config_string('adminToken');
    return hash_hmac('sha256', 'temp_image:' . $filename, $secret);
}

function upload_image_to_threads_temp(string $binaryData, string $mimeType): ?array {
    $uploadsDir = '/home/flawhvna/public_html/api/uploads';
    if (!is_dir($uploadsDir)) {
        error_log("Fwitter Threads temp upload dir missing: {$uploadsDir}");
        return null;
    }
    if (!is_writable($uploadsDir)) {
        error_log("Fwitter Threads temp upload dir not writable: {$uploadsDir}");
        return null;
    }

    $extMap = ['image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $ext = $extMap[$mimeType] ?? 'jpg';
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $filePath = $uploadsDir . '/' . $filename;

    $written = file_put_contents($filePath, $binaryData);
    if ($written === false) {
        error_log("Fwitter Threads temp file write failed: {$filePath}");
        return null;
    }
    if ($written === 0) {
        error_log("Fwitter Threads temp file wrote 0 bytes (empty image data): {$filePath}");
        @unlink($filePath);
        return null;
    }
    @chmod($filePath, 0644);

    $sig = temp_image_sign($filename);
    $url = 'https://flawnson.com/api/micro-posts.php?serve_temp_image=' . rawurlencode($filename) . '&sig=' . $sig;
    error_log("Fwitter Threads temp image written: {$filePath} ({$written} bytes) served as {$url}");
    return ['url' => $url, 'path' => $filePath];
}

function cleanup_threads_temp(string $filePath): void {
    if (file_exists($filePath)) {
        @unlink($filePath);
    }
}

function publish_to_bluesky(string $body, ?array $imageData = null): array {
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

    $blob = null;
    if ($imageData !== null) {
        $blob = upload_image_to_bluesky($imageData['binary'], $imageData['mime'], $accessJwt, $service);
    }

    $record = [
        '$type' => 'app.bsky.feed.post',
        'text' => $body,
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
    ];
    if ($blob !== null) {
        $record['embed'] = [
            '$type' => 'app.bsky.embed.images',
            'images' => [['image' => $blob, 'alt' => '']],
        ];
    }

    return http_json_request(
        $service . '/xrpc/com.atproto.repo.createRecord',
        ['Authorization: Bearer ' . $accessJwt],
        [
            'repo' => $repo,
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
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

function publish_to_x(string $body, ?array $imageData = null): array {
    if (
        config_string('xApiKey') === '' ||
        config_string('xApiSecret') === '' ||
        config_string('xAccessToken') === '' ||
        config_string('xAccessTokenSecret') === ''
    ) {
        return ['ok' => false, 'error' => 'x_not_configured'];
    }

    $mediaId = null;
    if ($imageData !== null) {
        $mediaId = upload_image_to_x($imageData['binary'], $imageData['mime']);
    }

    $url = 'https://api.x.com/2/tweets';
    $payload = ['text' => $body];
    if ($mediaId !== null) {
        $payload['media'] = ['media_ids' => [$mediaId]];
    }
    return http_json_request($url, [oauth1_header('POST', $url)], $payload);
}

function publish_to_threads(string $body, ?array $imageData = null): array {
    $userId = config_string('threadsUserId');
    $accessToken = config_string('threadsAccessToken');

    if ($userId === '' || $accessToken === '') {
        return ['ok' => false, 'error' => 'threads_not_configured'];
    }

    $baseUrl = 'https://graph.threads.net/v1.0/' . rawurlencode($userId);

    $tempInfo = null;
    $containerParams = ['text' => $body, 'access_token' => $accessToken];
    if ($imageData !== null) {
        $tempInfo = upload_image_to_threads_temp($imageData['binary'], $imageData['mime']);
        if ($tempInfo !== null) {
            $containerParams['media_type'] = 'IMAGE';
            $containerParams['image_url'] = $tempInfo['url'];
            error_log("Fwitter Threads IMAGE container: url={$tempInfo['url']} body_len=" . strlen($imageData['binary']));
        } else {
            $containerParams['media_type'] = 'TEXT';
            error_log("Fwitter Threads IMAGE fallback to TEXT: upload_image_to_threads_temp returned null");
        }
    } else {
        $containerParams['media_type'] = 'TEXT';
    }

    $container = http_form_request($baseUrl . '/threads', $containerParams, 30);
    error_log("Fwitter Threads container result: ok=" . ($container['ok'] ? 'true' : 'false') . " status={$container['status']} body=" . truncate_text((string)($container['body'] ?? ''), 300));

    $creationId = (string)($container['json']['id'] ?? '');
    if (!$container['ok'] || $creationId === '') {
        if ($tempInfo !== null) { cleanup_threads_temp($tempInfo['path']); }
        return [
            'ok' => false,
            'error' => 'threads_container_failed',
            'status' => $container['status'] ?? 0,
            'json' => $container['json'] ?? null,
            'body' => $container['body'] ?? '',
        ];
    }

    $publishResult = http_form_request($baseUrl . '/threads_publish', [
        'creation_id' => $creationId,
        'access_token' => $accessToken,
    ]);

    if ($tempInfo !== null) {
        cleanup_threads_temp($tempInfo['path']);
    }

    if (($publishResult['ok'] ?? false) && isset($publishResult['json']['id'])) {
        $permalink = threads_fetch_permalink((string)$publishResult['json']['id'], $accessToken);
        if ($permalink !== null) {
            $publishResult['json']['permalink'] = $permalink;
        }
    }

    return $publishResult;
}

function publish_to_platform(string $platform, string $body, ?array $imageData = null): array {
    if ($platform === 'x') {
        return publish_to_x($body, $imageData);
    }

    if ($platform === 'bluesky') {
        return publish_to_bluesky($body, $imageData);
    }

    if ($platform === 'threads') {
        return publish_to_threads($body, $imageData);
    }

    return ['ok' => false, 'error' => 'invalid_platform'];
}

function reply_on_x(string $body, string $parentTweetId, ?array $imageData = null): array {
    if (
        config_string('xApiKey') === '' ||
        config_string('xApiSecret') === '' ||
        config_string('xAccessToken') === '' ||
        config_string('xAccessTokenSecret') === ''
    ) {
        return ['ok' => false, 'error' => 'x_not_configured'];
    }

    $mediaId = null;
    if ($imageData !== null) {
        $mediaId = upload_image_to_x($imageData['binary'], $imageData['mime']);
    }

    $url = 'https://api.x.com/2/tweets';
    $payload = [
        'text' => $body,
        'reply' => ['in_reply_to_tweet_id' => $parentTweetId],
    ];
    if ($mediaId !== null) {
        $payload['media'] = ['media_ids' => [$mediaId]];
    }
    return http_json_request($url, [oauth1_header('POST', $url)], $payload);
}

function reply_on_threads(string $body, string $parentId, ?array $imageData = null): array {
    $userId = config_string('threadsUserId');
    $accessToken = config_string('threadsAccessToken');

    if ($userId === '' || $accessToken === '') {
        return ['ok' => false, 'error' => 'threads_not_configured'];
    }

    $baseUrl = 'https://graph.threads.net/v1.0/' . rawurlencode($userId);

    $tempInfo = null;
    $containerParams = ['text' => $body, 'reply_to_id' => $parentId, 'access_token' => $accessToken];
    if ($imageData !== null) {
        $tempInfo = upload_image_to_threads_temp($imageData['binary'], $imageData['mime']);
        if ($tempInfo !== null) {
            $containerParams['media_type'] = 'IMAGE';
            $containerParams['image_url'] = $tempInfo['url'];
        } else {
            $containerParams['media_type'] = 'TEXT';
        }
    } else {
        $containerParams['media_type'] = 'TEXT';
    }

    $container = http_form_request($baseUrl . '/threads', $containerParams);

    $creationId = (string)($container['json']['id'] ?? '');
    if (!$container['ok'] || $creationId === '') {
        if ($tempInfo !== null) { cleanup_threads_temp($tempInfo['path']); }
        return [
            'ok' => false,
            'error' => 'threads_container_failed',
            'status' => $container['status'] ?? 0,
            'json' => $container['json'] ?? null,
            'body' => $container['body'] ?? '',
        ];
    }

    $publishResult = http_form_request($baseUrl . '/threads_publish', [
        'creation_id' => $creationId,
        'access_token' => $accessToken,
    ]);

    if ($tempInfo !== null) {
        cleanup_threads_temp($tempInfo['path']);
    }

    if (($publishResult['ok'] ?? false) && isset($publishResult['json']['id'])) {
        $permalink = threads_fetch_permalink((string)$publishResult['json']['id'], $accessToken);
        if ($permalink !== null) {
            $publishResult['json']['permalink'] = $permalink;
        }
    }

    return $publishResult;
}

function reply_on_bluesky(string $body, string $parentUri, string $parentCid, string $rootUri, string $rootCid, ?array $imageData = null): array {
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

    $blob = null;
    if ($imageData !== null) {
        $blob = upload_image_to_bluesky($imageData['binary'], $imageData['mime'], $accessJwt, $service);
    }

    $record = [
        '$type' => 'app.bsky.feed.post',
        'text' => $body,
        'createdAt' => gmdate('Y-m-d\TH:i:s\Z'),
        'reply' => [
            'root'   => ['uri' => $rootUri,   'cid' => $rootCid],
            'parent' => ['uri' => $parentUri, 'cid' => $parentCid],
        ],
    ];
    if ($blob !== null) {
        $record['embed'] = [
            '$type' => 'app.bsky.embed.images',
            'images' => [['image' => $blob, 'alt' => '']],
        ];
    }

    return http_json_request(
        $service . '/xrpc/com.atproto.repo.createRecord',
        ['Authorization: Bearer ' . $accessJwt],
        [
            'repo' => $repo,
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ]
    );
}

function find_bluesky_root(int $startPostId, PDO $pdo): ?array {
    $current = $startPostId;
    $maxHops = 50;

    for ($i = 0; $i < $maxHops; $i++) {
        $stmt = $pdo->prepare("SELECT parent_id, platform_post_ids FROM micro_posts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $current]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        if ($row['parent_id'] === null) {
            $ids = is_string($row['platform_post_ids']) ? json_decode($row['platform_post_ids'], true) : null;
            $bluesky = is_array($ids) ? ($ids['bluesky'] ?? null) : null;
            if (is_array($bluesky) && isset($bluesky['uri'], $bluesky['cid'])) {
                return ['uri' => (string)$bluesky['uri'], 'cid' => (string)$bluesky['cid']];
            }
            return null;
        }

        $current = (int)$row['parent_id'];
    }

    error_log("Fwitter find_bluesky_root: exceeded max hops from post {$startPostId}");
    return null;
}

function syndicate_micro_post(string $body, int $postId, ?array $imageData = null): array {
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
        $result = publish_to_platform($platform, $syndicationBody, $imageData);
        $summary = http_result_summary($result);

        if ($result['ok'] ?? false) {
            $anyOk = true;
        } else {
            error_log("Fwitter syndication failed for post {$postId} to {$platform}: " . json_encode($summary, JSON_UNESCAPED_SLASHES));
        }

        $results[$platform] = ['ok' => (bool)($result['ok'] ?? false), 'result' => $summary, 'post_id' => extract_platform_post_id($platform, $result)];
    }

    return [
        'status' => $anyOk ? 'published' : 'failed',
        'platforms' => $platforms,
        'route' => $route,
        'results' => $results,
    ];
}

function run_syndication_safely(string $body, int $postId, PDO $pdo, ?array $imageData = null): array {
    try {
        $result = syndicate_micro_post($body, $postId, $imageData);

        if (!empty($result['platforms'])) {
            $platformStr = implode(',', $result['platforms']);

            $platformPostIds = [];
            foreach ($result['results'] ?? [] as $platform => $platformResult) {
                if (($platformResult['ok'] ?? false) && is_array($platformResult['post_id'] ?? null)) {
                    $platformPostIds[$platform] = $platformResult['post_id'];
                }
            }

            try {
                $sql = "UPDATE micro_posts SET syndicated_platforms = :platforms";
                $params = [':platforms' => $platformStr, ':id' => $postId];
                if (!empty($platformPostIds)) {
                    $sql .= ", platform_post_ids = :ids";
                    $params[':ids'] = json_encode($platformPostIds, JSON_UNESCAPED_SLASHES);
                }
                $sql .= " WHERE id = :id LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } catch (Throwable $e) {
                error_log("Fwitter failed to write syndication data for post {$postId}: " . $e->getMessage());
            }
        }

        return $result;
    } catch (Throwable $e) {
        error_log("Fwitter syndication crashed for post {$postId}: " . $e->getMessage());
        return [
            'status' => 'crashed',
            'error' => $e->getMessage(),
        ];
    }
}

function syndicate_reply(int $replyPostId, int $parentPostId, string $body, PDO $pdo, ?array $imageData = null): array {
    if (!config_enabled('socialSyndicationEnabled')) {
        return ['status' => 'disabled'];
    }

    $stmt = $pdo->prepare("SELECT syndicated_platforms, platform_post_ids FROM micro_posts WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $parentPostId]);
    $parent = $stmt->fetch();

    if (!is_array($parent)) {
        return ['status' => 'skipped', 'reason' => 'parent_not_found'];
    }

    $rawIds = is_string($parent['platform_post_ids']) ? json_decode($parent['platform_post_ids'], true) : null;
    if (!is_array($rawIds) || empty($rawIds)) {
        error_log("Fwitter reply syndication skipped for post {$replyPostId}: parent {$parentPostId} has no platform_post_ids");
        return ['status' => 'skipped', 'reason' => 'parent_missing_platform_ids'];
    }

    $platforms = [];
    if (is_string($parent['syndicated_platforms']) && $parent['syndicated_platforms'] !== '') {
        $platforms = array_values(array_filter(array_map('trim', explode(',', $parent['syndicated_platforms']))));
    }

    if (empty($platforms)) {
        return ['status' => 'skipped', 'reason' => 'parent_not_syndicated'];
    }

    $results = [];
    $anyOk = false;

    foreach ($platforms as $platform) {
        $syndicationBody = resolve_mentions_for_syndication($body, $platform);

        if ($platform === 'x') {
            $parentTweetId = (string)($rawIds['x']['post_id'] ?? '');
            if ($parentTweetId === '') {
                $results[$platform] = ['ok' => false, 'result' => ['api_error' => 'missing_parent_post_id'], 'post_id' => null];
                error_log("Fwitter reply to x skipped for post {$replyPostId}: no x.post_id in parent {$parentPostId}");
                continue;
            }
            $result = reply_on_x($syndicationBody, $parentTweetId, $imageData);
        } elseif ($platform === 'threads') {
            $parentThreadsId = (string)($rawIds['threads']['post_id'] ?? '');
            if ($parentThreadsId === '') {
                $results[$platform] = ['ok' => false, 'result' => ['api_error' => 'missing_parent_post_id'], 'post_id' => null];
                error_log("Fwitter reply to threads skipped for post {$replyPostId}: no threads.post_id in parent {$parentPostId}");
                continue;
            }
            $result = reply_on_threads($syndicationBody, $parentThreadsId, $imageData);
        } elseif ($platform === 'bluesky') {
            $parentUri = (string)($rawIds['bluesky']['uri'] ?? '');
            $parentCid = (string)($rawIds['bluesky']['cid'] ?? '');
            if ($parentUri === '' || $parentCid === '') {
                $results[$platform] = ['ok' => false, 'result' => ['api_error' => 'missing_parent_bluesky_ids'], 'post_id' => null];
                error_log("Fwitter reply to bluesky skipped for post {$replyPostId}: no bluesky uri/cid in parent {$parentPostId}");
                continue;
            }
            $root = find_bluesky_root($parentPostId, $pdo);
            if ($root === null) {
                $results[$platform] = ['ok' => false, 'result' => ['api_error' => 'bluesky_root_not_found'], 'post_id' => null];
                error_log("Fwitter reply to bluesky skipped for post {$replyPostId}: could not resolve root from parent {$parentPostId}");
                continue;
            }
            $result = reply_on_bluesky($syndicationBody, $parentUri, $parentCid, $root['uri'], $root['cid'], $imageData);
        } else {
            $results[$platform] = ['ok' => false, 'result' => ['api_error' => 'unsupported_platform'], 'post_id' => null];
            continue;
        }

        $summary = http_result_summary($result);
        $platformId = extract_platform_post_id($platform, $result);

        if ($result['ok'] ?? false) {
            $anyOk = true;
        } else {
            error_log("Fwitter reply syndication failed for post {$replyPostId} to {$platform}: " . json_encode($summary, JSON_UNESCAPED_SLASHES));
        }

        $results[$platform] = ['ok' => (bool)($result['ok'] ?? false), 'result' => $summary, 'post_id' => $platformId];
    }

    return [
        'status' => $anyOk ? 'published' : 'failed',
        'platforms' => $platforms,
        'results' => $results,
    ];
}

function run_reply_syndication_safely(int $replyPostId, int $parentPostId, string $body, PDO $pdo, ?array $imageData = null): array {
    try {
        $result = syndicate_reply($replyPostId, $parentPostId, $body, $pdo, $imageData);

        if (!empty($result['platforms'])) {
            $platformStr = implode(',', $result['platforms']);

            $platformPostIds = [];
            foreach ($result['results'] ?? [] as $platform => $platformResult) {
                if (($platformResult['ok'] ?? false) && is_array($platformResult['post_id'] ?? null)) {
                    $platformPostIds[$platform] = $platformResult['post_id'];
                }
            }

            try {
                $sql = "UPDATE micro_posts SET syndicated_platforms = :platforms";
                $params = [':platforms' => $platformStr, ':id' => $replyPostId];
                if (!empty($platformPostIds)) {
                    $sql .= ", platform_post_ids = :ids";
                    $params[':ids'] = json_encode($platformPostIds, JSON_UNESCAPED_SLASHES);
                }
                $sql .= " WHERE id = :id LIMIT 1";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } catch (Throwable $e) {
                error_log("Fwitter failed to write syndication data for reply {$replyPostId}: " . $e->getMessage());
            }
        }

        return $result;
    } catch (Throwable $e) {
        error_log("Fwitter reply syndication crashed for post {$replyPostId}: " . $e->getMessage());
        return ['status' => 'crashed', 'error' => $e->getMessage()];
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
        if (!in_array($forcedPlatform, ['x', 'bluesky', 'threads'], true)) {
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

if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["serve_temp_image"])) {
    $filename = basename((string)($_GET['serve_temp_image'] ?? ''));
    $sig = (string)($_GET['sig'] ?? '');

    if ($filename === '' || !hash_equals(temp_image_sign($filename), $sig)) {
        http_response_code(403);
        exit;
    }

    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExts, true)) {
        http_response_code(403);
        exit;
    }

    $filePath = '/home/flawhvna/public_html/api/uploads/' . $filename;
    if (!is_file($filePath) || !is_readable($filePath)) {
        http_response_code(404);
        exit;
    }

    $mimeMap = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
    $mime = $mimeMap[$ext] ?? 'image/jpeg';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($filePath));
    header('Cache-Control: no-store');
    readfile($filePath);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "GET" && isset($_GET["uploads_diag"])) {
    require_admin_token();
    $uploadsDir = '/home/flawhvna/public_html/api/uploads';
    $keep = isset($_GET['keep']);
    $diag = [
        'dir' => $uploadsDir,
        'exists' => is_dir($uploadsDir),
        'writable' => is_writable($uploadsDir),
    ];
    if ($diag['writable']) {
        $testFilename = 'diag_' . bin2hex(random_bytes(8)) . '.jpg';
        $testFile = $uploadsDir . '/' . $testFilename;
        // Minimal valid 1x1 white JPEG
        $minJpeg = base64_decode(
            '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8U'
            . 'HRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgN'
            . 'DRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIy'
            . 'MjL/wAARCAABAAEDASIAAhEBAxEB/8QAFgABAQEAAAAAAAAAAAAAAAAABgUE/8QAIhAAAQQC'
            . 'AgMAAAAAAAAAAAAAAQIDBBEhBRIxQf/EABQBAQAAAAAAAAAAAAAAAAAAAAD/xAAUEQEAAAAA'
            . 'AAAAAAAAAAAAAP/aAAwDAQACEQMRAD8Amab1pRNQRQRZWVHXlJSlKUpSlKUpSlKUpSlKUpSl'
            . 'KX//2Q=='
        );
        $wrote = file_put_contents($testFile, $minJpeg);
        $diag['write_bytes'] = $wrote;
        if ($wrote !== false) {
            @chmod($testFile, 0644);
            $diag['readable_after_write'] = is_readable($testFile);
            $perms = fileperms($testFile);
            $diag['file_perms'] = $perms !== false ? sprintf('%04o', $perms & 0777) : null;
            $diag['test_url'] = 'https://flawnson.com/api/uploads/' . $testFilename;
            if (!$keep) {
                @unlink($testFile);
                $diag['kept'] = false;
            } else {
                $diag['kept'] = true;
                $diag['note'] = 'Test file left on disk. DELETE it when done: curl -X DELETE with ?id= or just unlink manually.';
            }
        }
    }
    respond(200, $diag);
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
        SELECT id, body, parent_id, syndicated_platforms, platform_post_ids, has_image, created_at
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
        $platforms = isset($post['syndicated_platforms']) && $post['syndicated_platforms'] !== null
            ? explode(',', (string) $post['syndicated_platforms'])
            : null;
        $platformPostIds = isset($post['platform_post_ids']) && $post['platform_post_ids'] !== null
            ? json_decode((string) $post['platform_post_ids'], true)
            : null;
        return [
            'id' => (int) $post['id'],
            'body' => (string) $post['body'],
            'parent_id' => isset($post['parent_id']) ? (int) $post['parent_id'] : null,
            'syndicated_platforms' => $platforms,
            'platform_post_ids' => is_array($platformPostIds) ? $platformPostIds : null,
            'has_image' => (bool)(int)($post['has_image'] ?? 0),
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

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isMultipart = stripos($contentType, 'multipart/form-data') !== false;

    if ($isMultipart) {
        $body = trim((string)($_POST['body'] ?? ''));
        $replyToId = isset($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : 0;
    } else {
        $rawBody = file_get_contents("php://input");
        $json = json_decode($rawBody ?: "", true);
        if (!is_array($json)) {
            respond(400, ["error" => "invalid_json"]);
        }
        $body = trim((string)($json["body"] ?? ""));
        $replyToId = isset($json["reply_to_id"]) ? (int)$json["reply_to_id"] : 0;
    }

    if ($body === "") {
        respond(422, ["error" => "body_required"]);
    }

    if (mb_strlen($body) > 1000) {
        respond(422, ["error" => "body_too_long"]);
    }

    $imageData = null;
    if ($isMultipart && isset($_FILES['image'])) {
        $uploadError = $_FILES['image']['error'];
        $uploadSize = $_FILES['image']['size'] ?? 0;
        if ($uploadError !== UPLOAD_ERR_OK) {
            error_log("Fwitter image upload error: code={$uploadError} size={$uploadSize} (1=ini_size 2=form_size 3=partial 4=no_file)");
        } else {
            error_log("Fwitter image upload received: size={$uploadSize} bytes");
        }
    }
    if ($isMultipart && isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($_FILES['image']['tmp_name']);
        if (!in_array($detectedMime, $allowedMimes, true)) {
            respond(422, ["error" => "invalid_image_type"]);
        }
        if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
            respond(422, ["error" => "image_too_large"]);
        }
        if (!function_exists('imagecreatefromjpeg')) {
            respond(500, ["error" => "gd_unavailable"]);
        }
        $compressed = compress_image($_FILES['image']['tmp_name'], $detectedMime);
        $imageData = ['binary' => $compressed, 'mime' => 'image/jpeg'];
    }

    if ($replyToId > 0) {
        $stmt = $pdo->prepare("SELECT id FROM micro_posts WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $replyToId]);
        if (!$stmt->fetch()) {
            respond(422, ["error" => "parent_not_found"]);
        }

        $stmt = $pdo->prepare("INSERT INTO micro_posts (body, parent_id, has_image) VALUES (:body, :parent_id, :has_image)");
        $stmt->execute([':body' => $body, ':parent_id' => $replyToId, ':has_image' => $imageData !== null ? 1 : 0]);
        $postId = (int)$pdo->lastInsertId();

        http_response_code(201);
        header('Content-Type: application/json');
        $responseJson = json_encode(
            ["ok" => true, "id" => $postId, "reply_to_id" => $replyToId, "syndication" => "pending"],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        header('Content-Length: ' . strlen($responseJson));
        echo $responseJson;

        finish_response_early();

        run_reply_syndication_safely($postId, $replyToId, $body, $pdo, $imageData);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO micro_posts (body, has_image) VALUES (:body, :has_image)");
    $stmt->execute([":body" => $body, ":has_image" => $imageData !== null ? 1 : 0]);

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

    finish_response_early();

    run_syndication_safely($body, $postId, $pdo, $imageData);
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
