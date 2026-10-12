<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if ($argc !== 4) {
    fwrite(STDERR, "Usage: php create_admin.php \"Full Name\" admin@example.test \"StrongPassword\"\n");
    exit(2);
}
[$script, $name, $email, $password] = $argv;
if (strlen($password) < 10 || strlen($password) > 72 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Use a valid email and a 10–72 character password.\n");
    exit(2);
}
$config = database_config();
$pdo = new PDO($config['dsn'], $config['user'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, is_approved) VALUES (?, ?, ?, ?, TRUE)');
$statement->execute([trim($name), strtolower(trim($email)), password_hash($password, PASSWORD_BCRYPT), 'admin']);
fwrite(STDOUT, "Administrator created.\n");
