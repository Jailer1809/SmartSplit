<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function loans_active_total(PDO $pdo, int $userId): float
{
    $query = $pdo->prepare(
        "SELECT COALESCE(SUM(monthly_emi), 0) FROM loan_commitments "
        . 'WHERE user_id = ? AND status = \'active\' AND is_demo = FALSE'
    );
    $query->execute([$userId]);
    return (float)$query->fetchColumn();
}

function loans_sync_monthly_entry(PDO $pdo, int $userId, string $month): float
{
    $total = loans_active_total($pdo, $userId);
    $save = $pdo->prepare(
        "INSERT INTO finance_entries (user_id, category, amount, month) VALUES (?, 'emi', ?, ?) "
        . 'ON DUPLICATE KEY UPDATE amount = VALUES(amount)'
    );
    $save->execute([$userId, $total, $month]);
    return $total;
}

function loans_ensure_demo_examples(PDO $pdo, int $userId): void
{
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO loan_commitments (user_id, loan_label, monthly_emi, status, is_demo, demo_key) '
        . 'VALUES (?, ?, ?, ?, TRUE, ?)'
    );
    foreach ([
        ['home-loan', 'Home loan · sample', 18500.00],
        ['vehicle-loan', 'Vehicle loan · sample', 7400.00],
        ['education-loan', 'Education loan · sample', 5200.00],
    ] as [$key, $label, $amount]) {
        $insert->execute([$userId, $label, $amount, 'active', $key]);
    }
}

function loans_save_commitment(): never
{
    require_method('POST');
    $user = require_role('customer');
    $input = request_data();
    $label = is_string($input['loan_label'] ?? null) ? trim($input['loan_label']) : '';
    $amount = $input['monthly_emi'] ?? null;
    if ($label === '' || mb_strlen($label) > 100) {
        json_response(['error' => 'Enter a loan label between 1 and 100 characters.'], 422);
    }
    if (!is_numeric($amount) || !is_finite((float)$amount) || (float)$amount <= 0 || (float)$amount > 99999999999.99) {
        json_response(['error' => 'Monthly EMI must be greater than zero and within the supported range.'], 422);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $save = $pdo->prepare('INSERT INTO loan_commitments (user_id, loan_label, monthly_emi) VALUES (?, ?, ?)');
        $save->execute([$user['id'], $label, round((float)$amount, 2)]);
        $commitmentId = (int)$pdo->lastInsertId();
        $month = date('Y-m');
        $total = loans_sync_monthly_entry($pdo, $user['id'], $month);
        $limitQuery = $pdo->prepare("SELECT limit_amount FROM spending_limits WHERE user_id = ? AND category = 'emi'");
        $limitQuery->execute([$user['id']]);
        $limit = $limitQuery->fetchColumn();
        if ($limit !== false) {
            finance_record_threshold_notifications($pdo, $user['id'], $month, 'emi', $total, (float)$limit);
        }
        log_event($user['id'], 'emi_commitment_added');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    json_response(['message' => 'EMI commitment added.', 'commitment_id' => $commitmentId]);
}

function loans_close_commitment(): never
{
    require_method('POST');
    $user = require_role('customer');
    $input = request_data();
    $id = filter_var($input['commitment_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        json_response(['error' => 'Choose a valid EMI commitment.'], 422);
    }
    $amountPaid = $input['amount_paid'] ?? null;
    $paidOnTime = filter_var($input['paid_on_time'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if (!is_numeric($amountPaid) || !is_finite((float)$amountPaid) || (float)$amountPaid < 0 || (float)$amountPaid > 99999999999.99) {
        json_response(['error' => 'Total amount paid must be a non-negative amount within the supported range.'], 422);
    }
    if ($paidOnTime === null) {
        json_response(['error' => 'Choose whether all EMI payments were made on time.'], 422);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            "UPDATE loan_commitments SET status = 'closed', amount_paid = ?, paid_on_time = ? "
            . "WHERE id = ? AND user_id = ? AND status = 'active' AND is_demo = FALSE"
        );
        $update->execute([round((float)$amountPaid, 2), $paidOnTime, $id, $user['id']]);
        if ($update->rowCount() === 0) {
            $pdo->rollBack();
            json_response(['error' => 'Active EMI commitment was not found.'], 404);
        }
        $month = date('Y-m');
        $total = loans_sync_monthly_entry($pdo, $user['id'], $month);
        $limitQuery = $pdo->prepare("SELECT limit_amount FROM spending_limits WHERE user_id = ? AND category = 'emi'");
        $limitQuery->execute([$user['id']]);
        $limit = $limitQuery->fetchColumn();
        if ($limit !== false) {
            finance_record_threshold_notifications($pdo, $user['id'], $month, 'emi', $total, (float)$limit);
        }
        log_event($user['id'], 'emi_commitment_closed', ['paid_on_time' => $paidOnTime]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    json_response(['message' => 'EMI commitment marked as closed.']);
}

function loans_close_demo_commitment(): never
{
    require_method('POST');
    $user = require_role('customer');
    $input = request_data();
    $id = filter_var($input['commitment_id'] ?? null, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        json_response(['error' => 'Choose a valid demo loan.'], 422);
    }
    $update = db()->prepare(
        "UPDATE loan_commitments SET status = 'closed' "
        . 'WHERE id = ? AND user_id = ? AND status = \'active\' AND is_demo = TRUE'
    );
    $update->execute([$id, $user['id']]);
    if ($update->rowCount() === 0) {
        json_response(['error' => 'Active demo loan was not found.'], 404);
    }
    log_event($user['id'], 'demo_loan_closed');
    json_response(['message' => 'Demo loan closed. This sample never affected your budget or score.']);
}
