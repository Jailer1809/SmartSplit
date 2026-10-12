<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const FINANCE_CATEGORIES = ['income', 'savings', 'emi', 'rent', 'other'];
const LIMIT_CATEGORIES = ['savings', 'emi', 'rent', 'other'];

function finance_record_threshold_notifications(PDO $pdo, int $userId, string $month, string $category, float $spent, float $limit): void
{
    if ($limit <= 0) {
        return;
    }
    $ratio = $spent / $limit;
    foreach ([80, 100] as $threshold) {
        if ($ratio * 100 < $threshold) {
            continue;
        }
        $message = sprintf('%s is at %s%% of its monthly limit for %s.', ucfirst($category), number_format($ratio * 100, 0), $month);
        $notification = $pdo->prepare(
            'INSERT IGNORE INTO notifications (user_id, message, category, month, threshold_percent) VALUES (?, ?, ?, ?, ?)'
        );
        $notification->execute([$userId, $message, $category, $month, $threshold]);
    }
}

function validate_month(mixed $month): string
{
    if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        json_response(['error' => 'Month must use the YYYY-MM format.'], 422);
    }
    return $month;
}

function validate_amount(mixed $value, string $label): float
{
    if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0 || (float)$value > 99999999999.99) {
        json_response(['error' => $label . ' must be a non-negative amount within the supported range.'], 422);
    }
    return round((float)$value, 2);
}

function finance_dashboard(): never
{
    require_method('GET');
    $user = require_role('customer');
    $month = validate_month($_GET['month'] ?? date('Y-m'));
    $pdo = db();
    $entries = $pdo->prepare('SELECT category, amount FROM finance_entries WHERE user_id = ? AND month = ?');
    $entries->execute([$user['id'], $month]);
    $amounts = array_fill_keys(FINANCE_CATEGORIES, 0.0);
    foreach ($entries->fetchAll() as $entry) {
        $amounts[$entry['category']] = (float)$entry['amount'];
    }
    $limits = db()->prepare('SELECT category, limit_amount FROM spending_limits WHERE user_id = ?');
    $limits->execute([$user['id']]);
    $limitValues = [];
    foreach ($limits->fetchAll() as $limit) {
        $limitValues[$limit['category']] = (float)$limit['limit_amount'];
    }
    $repayment = db()->prepare('SELECT on_time_payments, missed_payments FROM repayment_history WHERE user_id = ? AND month = ?');
    $repayment->execute([$user['id'], $month]);
    $repaymentRow = $repayment->fetch() ?: ['on_time_payments' => 0, 'missed_payments' => 0];
    $scores = db()->prepare('SELECT id, score, risk_category, explanation_json, computed_at FROM risk_scores WHERE user_id = ? ORDER BY computed_at DESC, id DESC LIMIT 1');
    $scores->execute([$user['id']]);
    $risk = $scores->fetch() ?: null;
    if ($risk) {
        $risk['id'] = (int)$risk['id'];
        $risk['score'] = (float)$risk['score'];
        $risk['explanation'] = json_decode($risk['explanation_json'], true);
        unset($risk['explanation_json']);
    }
    $notifications = db()->prepare('SELECT id, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 30');
    $notifications->execute([$user['id']]);
    $consents = db()->prepare('SELECT reference_id, expires_at FROM bank_consents WHERE user_id = ? AND revoked_at IS NULL AND expires_at > NOW() ORDER BY created_at DESC');
    $consents->execute([$user['id']]);
    $amounts['emi'] = loans_active_total($pdo, $user['id']);
    loans_ensure_demo_examples($pdo, $user['id']);
    $commitments = $pdo->prepare(
        'SELECT id, loan_label, monthly_emi, status, amount_paid, paid_on_time, is_demo '
        . "FROM loan_commitments WHERE user_id = ? AND status = 'active' ORDER BY is_demo, id DESC"
    );
    $commitments->execute([$user['id']]);
    $commitmentRows = $commitments->fetchAll();
    $realCommitments = array_values(array_filter($commitmentRows, static fn(array $row): bool => !(bool)$row['is_demo']));
    $demoLoans = array_values(array_filter($commitmentRows, static fn(array $row): bool => (bool)$row['is_demo']));
    $activeCommitments = $realCommitments;
    $mapLoan = static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['monthly_emi'] = (float)$row['monthly_emi'];
        $row['amount_paid'] = $row['amount_paid'] === null ? null : (float)$row['amount_paid'];
        $row['paid_on_time'] = $row['paid_on_time'] === null ? null : (bool)$row['paid_on_time'];
        return $row;
    };
    json_response([
        'month' => $month,
        'entries' => $amounts,
        'limits' => $limitValues,
        'repayment' => ['on_time' => (int)$repaymentRow['on_time_payments'], 'missed' => (int)$repaymentRow['missed_payments']],
        'risk' => $risk,
        'notifications' => $notifications->fetchAll(),
        'consents' => $consents->fetchAll(),
        'loan_commitments' => array_map($mapLoan, $realCommitments),
        'demo_loans' => array_map($mapLoan, $demoLoans),
        'active_emi_count' => count($activeCommitments),
        'active_emi_total' => array_sum(array_map(static fn(array $row): float => (float)$row['monthly_emi'], $activeCommitments)),
    ]);
}

function finance_save(): never
{
    require_method('POST');
    $user = require_role('customer');
    $input = request_data();
    $month = validate_month($input['month'] ?? null);
    $values = [];
    foreach (array_diff(FINANCE_CATEGORIES, ['emi']) as $category) {
        $values[$category] = validate_amount($input[$category] ?? null, ucfirst($category));
    }
    $onTime = filter_var($input['on_time_payments'] ?? 0, FILTER_VALIDATE_INT);
    $missed = filter_var($input['missed_payments'] ?? 0, FILTER_VALIDATE_INT);
    if ($onTime === false || $missed === false || $onTime < 0 || $missed < 0 || $onTime > 60 || $missed > 60 || $onTime + $missed > 60) {
        json_response(['error' => 'Repayment counts must be whole numbers from 0 to 60, with a combined maximum of 60.'], 422);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $values['emi'] = loans_sync_monthly_entry($pdo, $user['id'], $month);
        $upsert = $pdo->prepare(
            'INSERT INTO finance_entries (user_id, category, amount, month) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE amount = VALUES(amount)'
        );
        foreach ($values as $category => $amount) {
            $upsert->execute([$user['id'], $category, $amount, $month]);
        }
        $history = $pdo->prepare(
            'INSERT INTO repayment_history (user_id, month, on_time_payments, missed_payments) VALUES (?, ?, ?, ?) '
            . 'ON DUPLICATE KEY UPDATE on_time_payments = VALUES(on_time_payments), missed_payments = VALUES(missed_payments)'
        );
        $history->execute([$user['id'], $month, $onTime, $missed]);
        foreach (LIMIT_CATEGORIES as $category) {
            $check = $pdo->prepare('SELECT limit_amount FROM spending_limits WHERE user_id = ? AND category = ?');
            $check->execute([$user['id'], $category]);
            $limit = $check->fetchColumn();
            if ($limit !== false) {
                finance_record_threshold_notifications($pdo, $user['id'], $month, $category, $values[$category], (float)$limit);
            }
        }
        log_event($user['id'], 'finance_month_updated', ['month' => $month]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    json_response(['message' => 'Monthly finance details saved.', 'month' => $month]);
}

function finance_save_limits(): never
{
    require_method('POST');
    $user = require_role('customer');
    $input = request_data();
    $limits = $input['limits'] ?? null;
    if (!is_array($limits)) {
        json_response(['error' => 'Limits must be supplied as a category-to-amount object.'], 422);
    }
    $month = validate_month($input['month'] ?? date('Y-m'));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (LIMIT_CATEGORIES as $category) {
            if (!array_key_exists($category, $limits)) {
                continue;
            }
            $amount = validate_amount($limits[$category], ucfirst($category) . ' limit');
            if ($amount === 0.0) {
                $delete = $pdo->prepare('DELETE FROM spending_limits WHERE user_id = ? AND category = ?');
                $delete->execute([$user['id'], $category]);
            } else {
                $save = $pdo->prepare(
                    'INSERT INTO spending_limits (user_id, category, limit_amount) VALUES (?, ?, ?) '
                    . 'ON DUPLICATE KEY UPDATE limit_amount = VALUES(limit_amount)'
                );
                $save->execute([$user['id'], $category, $amount]);
                $spentQuery = $pdo->prepare('SELECT amount FROM finance_entries WHERE user_id = ? AND category = ? AND month = ?');
                $spentQuery->execute([$user['id'], $category, $month]);
                $spent = $spentQuery->fetchColumn();
                if ($spent !== false) {
                    finance_record_threshold_notifications($pdo, $user['id'], $month, $category, (float)$spent, $amount);
                }
            }
        }
        log_event($user['id'], 'spending_limits_updated');
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
    json_response(['message' => 'Spending limits saved.']);
}

function finance_mark_notifications_read(): never
{
    require_method('POST');
    $user = require_role('customer');
    $input = request_data();
    $ids = $input['ids'] ?? [];
    if (!is_array($ids) || count($ids) > 30) {
        json_response(['error' => 'Invalid notification selection.'], 422);
    }
    $ids = array_values(array_unique(array_filter($ids, static fn($id) => filter_var($id, FILTER_VALIDATE_INT) !== false)));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $update = db()->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND id IN ($placeholders)");
        $update->execute(array_merge([$user['id']], array_map('intval', $ids)));
    }
    json_response(['message' => 'Notifications marked as read.']);
}
