<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/blockchain.php';

function bank_create_consent(): never
{
    require_method('POST');
    $user = require_role('customer');
    $reference = bin2hex(random_bytes(16));
    $expiresAt = (new DateTimeImmutable('+7 days'))->format('Y-m-d H:i:s');
    $save = db()->prepare('INSERT INTO bank_consents (user_id, reference_id, expires_at) VALUES (?, ?, ?)');
    $save->execute([$user['id'], $reference, $expiresAt]);
    log_event($user['id'], 'bank_consent_created', ['expires_at' => $expiresAt]);
    json_response(['reference_id' => $reference, 'expires_at' => $expiresAt]);
}

function bank_revoke_consent(): never
{
    require_method('POST');
    $user = require_role('customer');
    $input = request_data();
    $reference = $input['reference_id'] ?? '';
    if (!is_string($reference) || !preg_match('/^[a-f0-9]{32}$/', $reference)) {
        json_response(['error' => 'Reference ID is invalid.'], 422);
    }
    $update = db()->prepare('UPDATE bank_consents SET revoked_at = NOW() WHERE user_id = ? AND reference_id = ? AND revoked_at IS NULL');
    $update->execute([$user['id'], $reference]);
    if ($update->rowCount() === 0) {
        json_response(['error' => 'Active consent was not found.'], 404);
    }
    log_event($user['id'], 'bank_consent_revoked');
    json_response(['message' => 'Bank access was revoked.']);
}

function bank_lookup(): never
{
    require_method('GET');
    $bank = require_role('bank');
    $reference = (string)($_GET['reference_id'] ?? '');
    $customerReference = strtoupper((string)($_GET['customer_reference_id'] ?? ''));
    if (!preg_match('/^[a-f0-9]{32}$/', $reference) || !preg_match('/^SS-[A-F0-9]{16}$/', $customerReference)) {
        json_response(['error' => 'Enter a valid SmartSplit customer ID and consent reference.'], 422);
    }
    $consentQuery = db()->prepare(
        'SELECT c.user_id, u.name, u.customer_reference_id FROM bank_consents c JOIN users u ON u.id = c.user_id '
        . 'WHERE c.reference_id = ? AND u.customer_reference_id = ? AND c.revoked_at IS NULL '
        . 'AND c.expires_at > NOW() AND u.is_approved = TRUE LIMIT 1'
    );
    $consentQuery->execute([$reference, $customerReference]);
    $consent = $consentQuery->fetch();
    if (!$consent) {
        json_response(['error' => 'The customer ID and consent reference do not match, or consent has expired or been revoked.'], 404);
    }
    $scoreQuery = db()->prepare(
        'SELECT id, score, risk_category, explanation_json, computed_at FROM risk_scores '
        . 'WHERE user_id = ? ORDER BY computed_at DESC, id DESC LIMIT 1'
    );
    $scoreQuery->execute([(int)$consent['user_id']]);
    $risk = $scoreQuery->fetch();
    if (!$risk) {
        json_response(['error' => 'The customer has not computed a risk score yet.'], 404);
    }
    $risk['id'] = (int)$risk['id'];
    $risk['score'] = (float)$risk['score'];
    $risk['explanation'] = json_decode($risk['explanation_json'], true);
    unset($risk['explanation_json']);

    $financeQuery = db()->prepare('SELECT month, category, amount FROM finance_entries WHERE user_id = ? ORDER BY month DESC, category');
    $financeQuery->execute([(int)$consent['user_id']]);
    $commitmentQuery = db()->prepare(
        'SELECT id, loan_label, monthly_emi, status, amount_paid, paid_on_time, is_demo '
        . 'FROM loan_commitments WHERE user_id = ? AND is_demo = FALSE ORDER BY status, id DESC'
    );
    $commitmentQuery->execute([(int)$consent['user_id']]);
    $commitments = array_map(static function (array $row): array {
        $row['id'] = (int)$row['id'];
        $row['monthly_emi'] = (float)$row['monthly_emi'];
        $row['amount_paid'] = $row['amount_paid'] === null ? null : (float)$row['amount_paid'];
        $row['paid_on_time'] = $row['paid_on_time'] === null ? null : (bool)$row['paid_on_time'];
        return $row;
    }, $commitmentQuery->fetchAll());
    $activeCommitments = array_values(array_filter($commitments, static fn(array $row): bool => $row['status'] === 'active'));
    $ledgerRef = 'risk-' . $risk['id'];
    $ledgerQuery = db()->prepare(
        'SELECT block_index, timestamp, data_hash, previous_hash, record_ref_id, block_hash, record_type '
        . 'FROM blockchain_ledger WHERE record_ref_id = ? ORDER BY block_index'
    );
    $ledgerQuery->execute([$ledgerRef]);
    $riskProof = $ledgerQuery->fetchAll();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        append_audit_block($pdo, 'verify-' . bin2hex(random_bytes(12)), 'bank_verification', [
            'verifier_user_id' => $bank['id'],
            'consented_user_id' => (int)$consent['user_id'],
            'risk_score_id' => $risk['id'],
            'verified_at' => gmdate('c'),
        ]);
        log_event($bank['id'], 'consented_user_verified', ['risk_score_id' => $risk['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    $chain = call_ml_service('/ledger/verify', [], 'GET');
    $databaseBlocks = db()->query(
        'SELECT block_index, timestamp, data_hash, previous_hash, record_ref_id, block_hash, record_type FROM blockchain_ledger ORDER BY block_index'
    )->fetchAll();
    $chainBlocks = $chain['blocks'] ?? [];
    $integrity = ($chain['valid'] ?? false) === true && count($chainBlocks) === count($databaseBlocks);
    if ($integrity) {
        foreach ($databaseBlocks as $position => $databaseBlock) {
            $chainBlock = $chainBlocks[$position] ?? [];
            foreach (['block_index', 'timestamp', 'data_hash', 'previous_hash', 'record_ref_id', 'block_hash', 'record_type'] as $field) {
                if ((string)$databaseBlock[$field] !== (string)($chainBlock[$field] ?? '')) {
                    $integrity = false;
                    break 2;
                }
            }
        }
    }
    json_response([
        'customer_name' => $consent['name'],
        'customer_reference_id' => $consent['customer_reference_id'],
        'active_emi_count' => count($activeCommitments),
        'loan_commitments' => $commitments,
        'risk' => $risk,
        'finance_history' => $financeQuery->fetchAll(),
        'risk_proof' => $riskProof,
        'chain_integrity' => [
            'valid' => $integrity,
            'checked_blocks' => (int)($chain['checked_blocks'] ?? 0),
            'chain_head' => $chain['chain_head'] ?? null,
        ],
    ]);
}
