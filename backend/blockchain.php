<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function append_audit_block(PDO $pdo, string $recordRefId, string $recordType, array $recordData): array
{
    $result = call_ml_service('/ledger/add', [
        'record_ref_id' => $recordRefId,
        'record_type' => $recordType,
        'record_data' => $recordData,
    ]);
    foreach (['block_index', 'timestamp', 'data_hash', 'previous_hash', 'block_hash'] as $field) {
        if (!isset($result[$field]) || !is_scalar($result[$field])) {
            throw new RuntimeException('The audit-chain service returned an incomplete block.');
        }
    }
    $insert = $pdo->prepare(
        'INSERT INTO blockchain_ledger (block_index, timestamp, data_hash, previous_hash, record_ref_id, block_hash, record_type) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        (int)$result['block_index'],
        (string)$result['timestamp'],
        (string)$result['data_hash'],
        (string)$result['previous_hash'],
        $recordRefId,
        (string)$result['block_hash'],
        $recordType,
    ]);
    return $result;
}
