<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/loans.php';
require_once __DIR__ . '/finance.php';
require_once __DIR__ . '/risk.php';
require_once __DIR__ . '/bank.php';
require_once __DIR__ . '/admin.php';

$action = (string)($_GET['action'] ?? '');
if ($action !== 'csrf') {
    enforce_csrf();
}

switch ($action) {
    case 'csrf': auth_csrf();
    case 'register': auth_register();
    case 'login': auth_login();
    case 'admin-login': auth_login('admin');
    case 'logout': auth_logout();
    case 'me':
        require_method('GET');
        json_response(['user' => current_user(false)]);
    case 'dashboard': finance_dashboard();
    case 'finance/save': finance_save();
    case 'limits/save': finance_save_limits();
    case 'loan-commitments/save': loans_save_commitment();
    case 'loan-commitments/close': loans_close_commitment();
    case 'loan-commitments/close-demo': loans_close_demo_commitment();
    case 'notifications/read': finance_mark_notifications_read();
    case 'risk/compute': risk_compute();
    case 'consent/create': bank_create_consent();
    case 'consent/revoke': bank_revoke_consent();
    case 'bank/lookup': bank_lookup();
    case 'admin/users': admin_users();
    case 'admin/approve-bank': admin_approve_bank();
    case 'admin/review-bank': admin_review_bank();
    case 'admin/set-user-status': admin_set_user_status();
    case 'admin/logs': admin_logs();
    case 'admin/retrain': admin_retrain();
    default: json_response(['error' => 'Unknown API action.'], 404);
}
