<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';

$identity = normalize_pan_identity('abcde1234f');
if ($identity === null || $identity['masked'] !== '******234F') {
    throw new RuntimeException('PAN should be normalized and masked to its final four characters.');
}
if ($identity['hash'] !== hash('sha256', 'ABCDE1234F')) {
    throw new RuntimeException('PAN should be hashed after normalization.');
}
if (array_key_exists('pan', $identity) || count($identity) !== 2) {
    throw new RuntimeException('PAN plaintext must not be included in the normalized identity result.');
}
if (normalize_pan_identity('ABCDE12345') !== null || normalize_pan_identity('ABCDE-234F') !== null) {
    throw new RuntimeException('Invalid PAN formats must be rejected.');
}
if (normalize_bank_employee_id('ab1234') !== 'AB1234') {
    throw new RuntimeException('Bank Employee ID should be normalized to uppercase.');
}
if (normalize_bank_employee_id('AB-1234') !== null || normalize_bank_employee_id('ABC') !== null) {
    throw new RuntimeException('Bank Employee ID should accept only 4–32 alphanumeric characters.');
}

$customerReference = new_customer_reference_id();
if (!preg_match('/^SS-[A-F0-9]{16}$/', $customerReference)) {
    throw new RuntimeException('Customer verification IDs should use the expected opaque format.');
}

fwrite(STDOUT, "Registration identity checks passed.\n");
