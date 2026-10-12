<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/blockchain.php';

function risk_compute(): never
{
    require_method('POST');
    $user = require_role('customer');
    $input = request_data();
    $month = validate_month($input['month'] ?? date('Y-m'));
    $pdo = db();
    $entries = $pdo->prepare('SELECT category, amount FROM finance_entries WHERE user_id = ? AND month = ?');
    $entries->execute([$user['id'], $month]);
    $finance = array_fill_keys(FINANCE_CATEGORIES, 0.0);
    foreach ($entries->fetchAll() as $entry) {
        $finance[$entry['category']] = (float)$entry['amount'];
    }
    $finance['emi'] = loans_active_total($pdo, $user['id']);
    if ($finance['income'] <= 0) {
        json_response(['error' => 'Save a positive monthly income before computing a score.'], 422);
    }
    $repayment = db()->prepare('SELECT on_time_payments, missed_payments FROM repayment_history WHERE user_id = ? AND month = ?');
    $repayment->execute([$user['id'], $month]);
    $history = $repayment->fetch() ?: ['on_time_payments' => 0, 'missed_payments' => 0];
    $prediction = call_ml_service('/predict', [
        'monthly_income' => $finance['income'],
        'savings' => $finance['savings'],
        'emi' => $finance['emi'],
        'rent' => $finance['rent'],
        'other_expenses' => $finance['other'],
        'on_time_payments' => (int)$history['on_time_payments'],
        'missed_payments' => (int)$history['missed_payments'],
    ]);
    foreach (['score', 'risk_category', 'explanation'] as $field) {
        if (!array_key_exists($field, $prediction)) {
            throw new RuntimeException('The ML service omitted required prediction data.');
        }
    }
    if (!is_numeric($prediction['score']) || !in_array($prediction['risk_category'], ['Low', 'Medium', 'High'], true) || !is_array($prediction['explanation'])) {
        throw new RuntimeException('The ML service returned malformed prediction data.');
    }
    $pdo->beginTransaction();
    try {
        $save = $pdo->prepare('INSERT INTO risk_scores (user_id, score, risk_category, explanation_json) VALUES (?, ?, ?, ?)');
        $save->execute([
            $user['id'],
            max(0, min(100, (float)$prediction['score'])),
            $prediction['risk_category'],
            json_encode($prediction['explanation'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        $scoreId = (int)$pdo->lastInsertId();
        $refId = 'risk-' . $scoreId;
        append_audit_block($pdo, $refId, 'risk_score', [
            'risk_score_id' => $scoreId,
            'user_id' => $user['id'],
            'score' => (float)$prediction['score'],
            'risk_category' => $prediction['risk_category'],
            'computed_at' => gmdate('c'),
        ]);
        log_event($user['id'], 'risk_score_computed', ['risk_score_id' => $scoreId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    json_response([
        'risk' => [
            'id' => $scoreId,
            'score' => (float)$prediction['score'],
            'risk_category' => $prediction['risk_category'],
            'explanation' => $prediction['explanation'],
            'computed_at' => gmdate('c'),
        ],
    ]);
}
