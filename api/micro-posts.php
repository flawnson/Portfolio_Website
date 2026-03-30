<?php
declare(strict_types=1);

$allowedOrigins = [
    'https://flawnson.com',
    'http://localhost:63342',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
}

header("Vary: Origin");
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, X-Admin-Token");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

require '/home/flawhvna/private/microblog-config.php';

function respond(int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
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

    $stmt = $pdo->prepare("
        SELECT id, body, created_at
        FROM micro_posts
        ORDER BY created_at DESC, id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->execute();

    $posts = $stmt->fetchAll();

    $posts = array_map(static function (array $post): array {
        return [
            'id' => (int) $post['id'],
            'body' => (string) $post['body'],
            'created_at' => (string) $post['created_at'],
        ];
    }, $posts);

    respond(200, ['posts' => $posts]);
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

    respond(201, [
        "ok" => true,
        "id" => (int)$pdo->lastInsertId(),
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