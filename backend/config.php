<?php
declare(strict_types=1);

function env_value(string $name, string $fallback): string
{
    $value = getenv($name);
    return $value === false ? $fallback : $value;
}

const APP_NAME = 'Explainable Budget & Loan Verification System';
const APP_DISCLAIMER = 'Educational prototype only — not a regulated credit assessment tool. No real bank data is processed.';

function database_config(): array
{
    return [
        'dsn' => env_value('APP_DSN', 'mysql:host=127.0.0.1;dbname=explainable_budget;charset=utf8mb4'),
        'user' => env_value('APP_DB_USER', 'root'),
        'password' => env_value('APP_DB_PASSWORD', ''),
        'ml_url' => rtrim(env_value('ML_SERVICE_URL', 'http://127.0.0.1:5001'), '/'),
    ];
}
