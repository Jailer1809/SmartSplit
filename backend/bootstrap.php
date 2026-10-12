<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function db(): PDO
{
    static $connection;
    if (!$connection instanceof PDO) {
        $config = database_config();
        $connection = new PDO($config['dsn'], $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $connection;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function request_data(): array
{
    $raw = file_get_contents('php://input');
    $parsed = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($parsed)) {
        json_response(['error' => 'Request body must be a JSON object.'], 400);
    }
    return $parsed;
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        json_response(['error' => 'Method not allowed.'], 405);
    }
}

function enforce_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        return;
    }
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        json_response(['error' => 'The request token is invalid or expired. Refresh the page and try again.'], 403);
    }
}

function ensure_customer_reference_column(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'customer_reference_id'");
    if ($column->fetch() === false) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN customer_reference_id CHAR(19) NULL UNIQUE');
        } catch (PDOException $error) {
            if ($error->getCode() !== '42S21') {
                throw $error;
            }
        }
    }
    $checked = true;
}

function new_customer_reference_id(): string
{
    return 'SS-' . strtoupper(bin2hex(random_bytes(8)));
}

function ensure_customer_reference(PDO $pdo, int $userId): string
{
    $select = $pdo->prepare('SELECT customer_reference_id FROM users WHERE id = ?');
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $select->execute([$userId]);
        $reference = $select->fetchColumn();
        if (is_string($reference) && $reference !== '') {
            if (preg_match('/^FS-([A-F0-9]{16})$/', $reference, $matches)) {
                $reference = 'SS-' . $matches[1];
                $rename = $pdo->prepare('UPDATE users SET customer_reference_id = ? WHERE id = ?');
                $rename->execute([$reference, $userId]);
            }
            return $reference;
        }
        $reference = new_customer_reference_id();
        try {
            $update = $pdo->prepare('UPDATE users SET customer_reference_id = ? WHERE id = ? AND customer_reference_id IS NULL');
            $update->execute([$reference, $userId]);
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }
            continue;
        }
        if ($update->rowCount() === 1) {
            return $reference;
        }
    }
    throw new RuntimeException('Could not assign a unique customer verification ID.');
}

function current_user(bool $required = true): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !ctype_digit((string)$userId)) {
        if ($required) {
            json_response(['error' => 'Authentication is required.'], 401);
        }
        return null;
    }
    $pdo = db();
    $query = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.role, u.is_approved, u.customer_reference_id, d.pan_id_masked, d.verification_status '
        . 'FROM users u LEFT JOIN user_verification_details d ON d.user_id = u.id WHERE u.id = ?'
    );
    $query->execute([(int)$userId]);
    $user = $query->fetch();
    if (!$user || !(bool)$user['is_approved']) {
        $_SESSION = [];
        session_destroy();
        if ($required) {
            json_response(['error' => 'This account is unavailable or awaiting approval.'], 403);
        }
        return null;
    }
    $user['id'] = (int)$user['id'];
    if ($user['role'] === 'customer') {
        $user['customer_reference_id'] = ensure_customer_reference($pdo, $user['id']);
    }
    return $user;
}

function require_role(string ...$roles): array
{
    $user = current_user();
    if (!in_array($user['role'], $roles, true)) {
        json_response(['error' => 'You do not have permission to access this resource.'], 403);
    }
    return $user;
}

function log_event(?int $actorId, string $event, array $details = []): void
{
    $statement = db()->prepare('INSERT INTO system_logs (actor_user_id, event, details_json) VALUES (?, ?, ?)');
    $statement->execute([
        $actorId,
        $event,
        json_encode($details, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
    ]);
}

function call_ml_service(string $path, array $payload = [], string $method = 'POST'): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required to connect to the local ML service. Enable the curl extension in XAMPP.');
    }
    $url = database_config()['ml_url'] . $path;
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    if ($method !== 'GET') {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($response === false) {
        $message = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('The local ML service is unavailable: ' . $message);
    }
    curl_close($curl);
    $decoded = json_decode($response, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new RuntimeException('The local ML service returned an invalid response (HTTP ' . $status . ').');
    }
    return $decoded;
}

set_exception_handler(static function (Throwable $error): void {
    error_log((string)$error);
    if (!headers_sent()) {
        json_response(['error' => 'The server could not complete the request. Check the application logs.'], 500);
    }
});
