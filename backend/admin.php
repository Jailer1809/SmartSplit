<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function admin_users(): never
{
    require_method('GET');
    require_role('admin');
    $users = db()->query(
        'SELECT u.id, u.name, u.email, u.role, u.is_approved, u.created_at, '
        . 'd.role AS verification_role, d.pan_id_masked, d.bank_name, d.bank_employee_id, d.verification_status '
        . 'FROM users u LEFT JOIN user_verification_details d ON d.user_id = u.id '
        . 'ORDER BY u.created_at DESC, u.id DESC LIMIT 500'
    );
    json_response(['users' => $users->fetchAll()]);
}

function admin_approve_bank(): never
{
    require_method('POST');
    $admin = require_role('admin');
    $input = request_data();
    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    if ($userId === false || $userId < 1) {
        json_response(['error' => 'A valid user ID is required.'], 422);
    }
    admin_review_bank_employee($admin, $userId, 'verified');
}

function admin_review_bank_employee(array $admin, int $userId, string $status): never
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare(
            "SELECT d.verification_status FROM users u "
            . "JOIN user_verification_details d ON d.user_id = u.id "
            . "WHERE u.id = ? AND u.role = 'bank' AND d.role = 'bank_employee' FOR UPDATE"
        );
        $check->execute([$userId]);
        $currentStatus = $check->fetchColumn();
        if ($currentStatus === false) {
            $pdo->rollBack();
            json_response(['error' => 'Bank Employee account was not found.'], 404);
        }
        $details = $pdo->prepare('UPDATE user_verification_details SET verification_status = ? WHERE user_id = ?');
        $details->execute([$status, $userId]);
        $user = $pdo->prepare('UPDATE users SET is_approved = ? WHERE id = ? AND role = ?');
        $user->execute([$status === 'verified', $userId, 'bank']);
        log_event($admin['id'], 'bank_employee_verification_reviewed', [
            'user_id' => $userId,
            'verification_status' => $status,
        ]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    json_response([
        'message' => $status === 'verified'
            ? 'Bank Employee ID verified. The account is approved.'
            : 'Bank Employee ID rejected. The account remains disabled.',
    ]);
}

function admin_review_bank(): never
{
    require_method('POST');
    $admin = require_role('admin');
    $input = request_data();
    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    $status = $input['verification_status'] ?? null;
    if ($userId === false || $userId < 1 || !in_array($status, ['verified', 'rejected'], true)) {
        json_response(['error' => 'Provide a valid user ID and choose verified or rejected.'], 422);
    }
    admin_review_bank_employee($admin, $userId, $status);
}

function admin_set_user_status(): never
{
    require_method('POST');
    $admin = require_role('admin');
    $input = request_data();
    $userId = filter_var($input['user_id'] ?? null, FILTER_VALIDATE_INT);
    $approved = $input['is_approved'] ?? null;
    if ($userId === false || $userId < 1 || !is_bool($approved)) {
        json_response(['error' => 'A valid user ID and boolean status are required.'], 422);
    }
    if ($userId === $admin['id']) {
        json_response(['error' => 'Administrators cannot change their own account status.'], 422);
    }
    $target = db()->prepare(
        'SELECT u.role, d.verification_status FROM users u '
        . 'LEFT JOIN user_verification_details d ON d.user_id = u.id WHERE u.id = ?'
    );
    $target->execute([$userId]);
    $targetUser = $target->fetch();
    if (!$targetUser || $targetUser['role'] === 'admin') {
        json_response(['error' => 'Customer or bank account was not found.'], 404);
    }
    if ($targetUser['role'] === 'bank' && $approved && $targetUser['verification_status'] !== 'verified') {
        json_response(['error' => 'Verify the Bank Employee ID before enabling this account.'], 409);
    }
    $update = db()->prepare("UPDATE users SET is_approved = ? WHERE id = ? AND role <> 'admin'");
    $update->execute([$approved, $userId]);
    if ($update->rowCount() === 0) {
        json_response(['error' => 'Customer or bank account was not found or already has that status.'], 404);
    }
    log_event($admin['id'], $approved ? 'user_account_enabled' : 'user_account_suspended', ['user_id' => $userId]);
    json_response(['message' => $approved ? 'Account enabled.' : 'Account suspended.']);
}

function admin_logs(): never
{
    require_method('GET');
    require_role('admin');
    $logs = db()->query(
        'SELECT l.id, l.event, l.details_json, l.created_at, u.email AS actor_email '
        . 'FROM system_logs l LEFT JOIN users u ON u.id = l.actor_user_id ORDER BY l.created_at DESC, l.id DESC LIMIT 200'
    );
    $rows = $logs->fetchAll();
    foreach ($rows as &$row) {
        $row['details'] = json_decode($row['details_json'], true);
        unset($row['details_json']);
    }
    json_response(['logs' => $rows]);
}

function admin_retrain(): never
{
    require_method('POST');
    $admin = require_role('admin');
    $result = call_ml_service('/retrain');
    log_event($admin['id'], 'model_retrained', ['rows' => $result['rows'] ?? null]);
    json_response(['model' => $result]);
}
