<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/auth.php';

const DEMO_CUSTOMER_NAME = 'Aarav Demo';
const DEMO_CUSTOMER_EMAIL = 'demo.customer@smartsplit.local';
const DEMO_CUSTOMER_PAN = 'DEMOX1234Z';

try {
    $pdo = db();
    ensure_customer_reference_column($pdo);
    $demoPassword = bin2hex(random_bytes(12));

    $existing = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existing->execute([DEMO_CUSTOMER_EMAIL]);
    if ($existing->fetchColumn() !== false) {
        throw new RuntimeException(
            'The demo email already exists. Sign in with its current password or remove that demo account before running this seed again.'
        );
    }

    $identity = normalize_pan_identity(DEMO_CUSTOMER_PAN);
    if ($identity === null) {
        throw new RuntimeException('The built-in synthetic PAN value is invalid.');
    }

    $month = date('Y-m');
    $pdo->beginTransaction();

    $user = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, role, customer_reference_id, is_approved) '
        . 'VALUES (?, ?, ?, ?, ?, TRUE)'
    );
    $user->execute([
        DEMO_CUSTOMER_NAME,
        DEMO_CUSTOMER_EMAIL,
        password_hash($demoPassword, PASSWORD_BCRYPT),
        'customer',
        new_customer_reference_id(),
    ]);
    $userId = (int)$pdo->lastInsertId();

    $verification = $pdo->prepare(
        'INSERT INTO user_verification_details '
        . '(user_id, role, pan_id_hash, pan_id_masked, verification_status) VALUES (?, ?, ?, ?, ?)'
    );
    $verification->execute([$userId, 'normal_user', $identity['hash'], $identity['masked'], 'verified']);

    $entries = $pdo->prepare(
        'INSERT INTO finance_entries (user_id, category, amount, month) VALUES (?, ?, ?, ?)'
    );
    foreach ([
        'income' => 85000,
        'savings' => 12000,
        'emi' => 14500,
        'rent' => 18000,
        'other' => 10000,
    ] as $category => $amount) {
        $entries->execute([$userId, $category, $amount, $month]);
    }

    $limits = $pdo->prepare(
        'INSERT INTO spending_limits (user_id, category, limit_amount) VALUES (?, ?, ?)'
    );
    foreach ([
        'savings' => 20000,
        'emi' => 16000,
        'rent' => 20000,
        'other' => 12000,
    ] as $category => $amount) {
        $limits->execute([$userId, $category, $amount]);
    }

    $loan = $pdo->prepare(
        'INSERT INTO loan_commitments '
        . '(user_id, loan_label, monthly_emi, status, is_demo, demo_key) VALUES (?, ?, ?, ?, FALSE, NULL)'
    );
    $loan->execute([$userId, 'Demo vehicle loan', 8500, 'active']);
    $loan->execute([$userId, 'Demo personal loan', 6000, 'active']);

    $closedLoan = $pdo->prepare(
        'INSERT INTO loan_commitments '
        . '(user_id, loan_label, monthly_emi, status, amount_paid, paid_on_time, is_demo, demo_key) '
        . 'VALUES (?, ?, ?, ?, ?, TRUE, FALSE, NULL)'
    );
    $closedLoan->execute([$userId, 'Demo closed education loan', 4500, 'closed', 54000]);

    $repayment = $pdo->prepare(
        'INSERT INTO repayment_history (user_id, month, on_time_payments, missed_payments) VALUES (?, ?, ?, ?)'
    );
    $repayment->execute([$userId, $month, 12, 0]);

    log_event($userId, 'demo_customer_seeded', ['month' => $month]);
    $pdo->commit();

    fwrite(STDOUT, "Demo customer created successfully.\n");
    fwrite(STDOUT, 'Name: ' . DEMO_CUSTOMER_NAME . "\n");
    fwrite(STDOUT, 'Email: ' . DEMO_CUSTOMER_EMAIL . "\n");
    fwrite(STDOUT, 'Password: ' . $demoPassword . "\n");
    fwrite(STDOUT, 'PAN: synthetic; stored only as a SHA-256 hash and masked suffix.' . "\n");
    fwrite(STDOUT, "Monthly income: INR 85,000; savings: INR 12,000; rent: INR 18,000; other expenses: INR 10,000.\n");
    fwrite(STDOUT, "Active EMIs: 2 (INR 8,500 and INR 6,000). Closed demo EMI history included.\n");
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Demo customer was not created: ' . $error->getMessage() . "\n");
    exit(1);
}
