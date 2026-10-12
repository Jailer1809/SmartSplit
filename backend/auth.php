<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function normalize_pan_identity(mixed $value): ?array
{
    if (!is_string($value)) {
        return null;
    }
    $pan = strtoupper(trim($value));
    if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/D', $pan)) {
        return null;
    }
    $identity = [
        'hash' => hash('sha256', $pan),
        'masked' => str_repeat('*', strlen($pan) - 4) . substr($pan, -4),
    ];
    unset($pan);
    return $identity;
}

function normalize_bank_employee_id(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $employeeId = strtoupper(trim($value));
    return preg_match('/^[A-Z0-9]{4,32}$/D', $employeeId) ? $employeeId : null;
}

function auth_csrf(): never
{
    require_method('GET');
    ensure_customer_reference_column(db());
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    json_response(['csrf_token' => $_SESSION['csrf_token'], 'user' => current_user(false)]);
}

function auth_register(): never
{
    require_method('POST');
    $input = request_data();
    $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
    $email = is_string($input['email'] ?? null) ? strtolower(trim($input['email'])) : '';
    $password = $input['password'] ?? null;
    $role = $input['role'] ?? 'customer';
    if ($name === '' || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        json_response(['error' => 'Enter a valid name and email address.'], 422);
    }
    if (!is_string($password) || strlen($password) < 10 || strlen($password) > 72) {
        json_response(['error' => 'Password must contain 10–72 characters.'], 422);
    }
    if (!in_array($role, ['customer', 'bank'], true)) {
        json_response(['error' => 'Choose a customer or bank account. Admin accounts are created by the administrator.'], 422);
    }
    $verificationRole = $role === 'customer' ? 'normal_user' : 'bank_employee';
    $panHash = null;
    $panMasked = null;
    $bankName = null;
    $bankEmployeeId = null;
    if ($role === 'customer') {
        $panIdentity = normalize_pan_identity($input['pan_id'] ?? null);
        unset($input['pan_id']);
        if ($panIdentity === null) {
            json_response(['error' => 'Enter a valid PAN ID in the format ABCDE1234F.'], 422);
        }
        $panHash = $panIdentity['hash'];
        $panMasked = $panIdentity['masked'];
        unset($panIdentity);
    } else {
        $bankName = is_string($input['bank_name'] ?? null) ? trim($input['bank_name']) : '';
        $bankEmployeeId = normalize_bank_employee_id($input['bank_employee_id'] ?? null);
        if (mb_strlen($bankName) < 2 || mb_strlen($bankName) > 120) {
            json_response(['error' => 'Enter a bank name between 2 and 120 characters.'], 422);
        }
        unset($input['bank_employee_id']);
        if ($bankEmployeeId === null) {
            json_response(['error' => 'Bank Employee ID must contain 4–32 letters or digits.'], 422);
        }
    }
    $approved = $role === 'customer';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $customerReference = $approved ? new_customer_reference_id() : null;
        $statement = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, customer_reference_id, is_approved) VALUES (?, ?, ?, ?, ?, ?)');
        $statement->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), $role, $customerReference, $approved]);
        $userId = (int)$pdo->lastInsertId();
        $details = $pdo->prepare(
            'INSERT INTO user_verification_details '
            . '(user_id, role, pan_id_hash, pan_id_masked, bank_name, bank_employee_id, verification_status) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $details->execute([
            $userId,
            $verificationRole,
            $panHash,
            $panMasked,
            $bankName,
            $bankEmployeeId,
            $role === 'bank' ? 'pending' : 'verified',
        ]);
        log_event($userId, 'account_registered', ['role' => $role]);
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error->getCode() === '23000') {
            json_response(['error' => 'This email or verification identity is already registered.'], 409);
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    if (!$approved) {
        json_response(['message' => 'Bank Employee account submitted. Status: Pending Admin Approval. An administrator must manually verify your employee ID before sign-in.']);
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    json_response(['message' => 'Account created.', 'user' => current_user()]);
}

function auth_login(?string $requiredRole = null): never
{
    require_method('POST');
    $input = request_data();
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = $input['password'] ?? null;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || strlen($password) > 72) {
        json_response(['error' => 'Email or password is incorrect.'], 401);
    }
    $subjectHash = hash('sha256', $email);
    $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $attempts = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts WHERE created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) AND (subject_hash = ? OR ip_hash = ?)'
    );
    $attempts->execute([$subjectHash, $ipHash]);
    if ((int)$attempts->fetchColumn() >= 10) {
        json_response(['error' => 'Too many sign-in attempts. Try again in 15 minutes.'], 429);
    }
    $statement = db()->prepare('SELECT id, password_hash, role, is_approved FROM users WHERE email = ? LIMIT 1');
    $statement->execute([$email]);
    $row = $statement->fetch();
    if (
        !$row
        || !password_verify($password, $row['password_hash'])
        || ($requiredRole !== null && $row['role'] !== $requiredRole)
    ) {
        $failed = db()->prepare('INSERT INTO login_attempts (subject_hash, ip_hash) VALUES (?, ?)');
        $failed->execute([$subjectHash, $ipHash]);
        json_response(['error' => 'Email or password is incorrect.'], 401);
    }
    if (!(bool)$row['is_approved']) {
        json_response(['error' => 'This bank account is awaiting administrator approval.'], 403);
    }
    $clearAttempts = db()->prepare('DELETE FROM login_attempts WHERE subject_hash = ? OR ip_hash = ?');
    $clearAttempts->execute([$subjectHash, $ipHash]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$row['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $user = current_user();
    log_event($user['id'], 'user_login');
    json_response(['message' => 'Signed in.', 'user' => $user, 'csrf_token' => $_SESSION['csrf_token']]);
}

function auth_logout(): never
{
    require_method('POST');
    $user = current_user(false);
    if ($user) {
        log_event($user['id'], 'user_logout');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $parameters['path'], '', (bool)$parameters['secure'], (bool)$parameters['httponly']);
    }
    session_destroy();
    json_response(['message' => 'Signed out.']);
}
