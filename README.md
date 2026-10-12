# SMARTSPLIT – An AI-Driven Adaptive Budget Allocation and EMI Vault Management System

A full-stack educational prototype for tracking self-reported monthly finances and sharing an explainable, synthetic-model risk score with a bank verifier by explicit consent. It does not connect to bank accounts.

> **Educational prototype only — not a regulated credit assessment tool. No real bank data is processed.**

## Project structure

```text
start-local-services.ps1       Start/check XAMPP MySQL and local ML API
database/
  schema.sql                 Importable MySQL schema
backend/
  index.php                  JSON API router
  auth.php                   Password hashing, sessions and login throttling
  finance.php                Monthly data, limits and notifications
  loans.php                  Customer-owned EMI commitment count
  risk.php                   Risk scoring integration
  blockchain.php             PHP-to-local-ledger integration
  bank.php                   Customer consent and read-only verification
  admin.php                  Account approval, system logs and model retraining
  create_admin.php           CLI-only administrator bootstrap
frontend/
  index.html                 Login/register and role-specific views
  styles.css                 Responsive GPay-inspired UI
  app.js                     Browser UI and PHP API client
ml/
  app.py                     Local Flask risk and audit API
  train_model.py             Synthetic Decision Tree training and SHAP features
  blockchain.py              SHA-256 append-only hash chain
  data/synthetic_finance.csv 300 synthetic training examples
  requirements.txt
  tests/                       Audit chain and model API tests
```

## Requirements

- XAMPP with Apache, MySQL/MariaDB, and PHP 8.1+; enable `pdo_mysql`, `curl`, and `mbstring` in the active `php.ini`.
- Python 3.10–3.12 recommended for the local ML service.
- A modern browser. Browser notifications require `localhost` or HTTPS.

## Run with XAMPP

1. Copy the project to `C:\xampp\htdocs\XAI` (or configure an Apache virtual host/alias to serve this folder). Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. Open `http://localhost/phpmyadmin`, import [`database/schema.sql`](database/schema.sql), and confirm the `explainable_budget` database was created. The development defaults are MySQL user `root`, an empty password, and database `explainable_budget`. For a configured MySQL password, set `APP_DB_USER` / `APP_DB_PASSWORD` in the Apache environment (and in the shell when running the CLI bootstrap), or update the local-only fallback in `backend/config.php`.
   If you imported an earlier version of this project, re-import the updated schema: `CREATE TABLE IF NOT EXISTS` adds new tables without replacing existing users or finance data. For an existing `loan_commitments` table, add the new loan columns once in phpMyAdmin: `ALTER TABLE loan_commitments ADD COLUMN is_demo BOOLEAN NOT NULL DEFAULT FALSE, ADD COLUMN demo_key VARCHAR(40) NULL, ADD COLUMN amount_paid DECIMAL(13,2) NULL, ADD COLUMN paid_on_time BOOLEAN NULL, ADD UNIQUE KEY uq_loan_commitments_demo (user_id, demo_key);` Skip columns already present.
   On the first API request, the backend adds the nullable unique customer reference column to an older `users` table if needed; existing account and finance data are preserved. Existing customer accounts receive an opaque `SS-...` ID on their next successful sign-in.
3. Create the first administrator from PowerShell. Replace the example name, email, and password with your own; use a unique email and a strong password. There is no built-in default admin account:

   ```powershell
   & C:\xampp\php\php.exe D:\XAI\backend\create_admin.php "Demo Admin" "admin@example.test" "Use-A-Unique-Strong-Passphrase"
   ```

   Adjust the project path if you installed it somewhere else. To pass a local database password to this command, set `$env:APP_DB_PASSWORD` in the same PowerShell process first.

4. Open `http://localhost/XAI/frontend/admin.html` and sign in with the administrator email and password you created. In the Admin panel, review pending bank employees in **Accounts**, then select **Verify ID** or **Reject** after checking their bank and employee ID. Customers and bank employees use the standard page at `http://localhost/XAI/frontend/`.
5. Open `http://localhost/XAI/frontend/` (do not open `frontend/index.html` directly as a `file://` URL) and register a **Normal User** with a PAN ID in `ABCDE1234F` format, or register a **Bank Employee** with a bank name and 4–32 character alphanumeric employee ID. The PAN is normalized and validated, then only its SHA-256 hash and `******`-masked suffix are stored; it is never logged or returned in plaintext. Bank Employee requests remain **Pending Admin Approval** until an administrator manually reviews the submitted bank and employee ID and verifies or rejects the account.

### Create a local demo customer

For a local classroom demo only, run:

```powershell
& C:\xampp\php\php.exe D:\XAI\backend\seed_demo_customer.php
```

This creates `demo.customer@smartsplit.local` with synthetic monthly finance and repayment data, spending limits, two active sample EMIs, and one closed sample EMI. The script generates a fresh random password and prints it once on success; save it from the command output. PAN is stored only as a hash and masked suffix. The script refuses to overwrite an account with that email. This demo account is for a local installation; do not use its credentials on a public deployment.

## Customer ID and bank verification

SmartSplit generates a random, stable customer reference ID (`SS-` plus 16 hexadecimal characters) at registration and displays it after sign-in. Existing customer IDs are assigned at their next sign-in after applying the migration above; older `FS-` IDs are upgraded to `SS-` on sign-in. The ID identifies an account but is not an access credential. A bank verifier must also enter the customer's separate, seven-day consent reference; the backend checks that both identifiers belong together and that consent remains active before returning any report. The report contains the app's educational synthetic score only. A genuine CIBIL score requires an authorized credit-bureau integration and customer authorization; this project does not connect to one and PAN alone is not used to fetch a bureau score.

## Run the ML and audit service

If MySQL or the local ML service is stopped, start XAMPP Apache, then run this from PowerShell to start/check MySQL, the ML service, and the PHP API:

```powershell
& D:\XAI\start-local-services.ps1
```

Adjust the script path if the project is installed elsewhere and pass `-AppUrl` if its local URL is not `http://localhost/XAI`. It reuses services that are already running and reports an error if one cannot start.

To run the ML service interactively and view its startup output, use a second PowerShell window:

```powershell
Set-Location D:\XAI\ml
py -3.11 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements.txt
.\.venv\Scripts\python.exe train_model.py
.\.venv\Scripts\python.exe app.py
```

The service binds to `127.0.0.1:5001`; keep it local and do not expose that port to a network. PHP connects to this address by default. Override `ML_SERVICE_URL` in the Apache environment if the local port changes. The model is a scikit-learn `DecisionTreeClassifier`; SHAP `TreeExplainer` reports contributions to the model's Low-risk probability. The retraining button uses only the bundled synthetic CSV.

The local audit chain is written to `ml/data/blockchain.json`. PHP mirrors block hashes into the MySQL `blockchain_ledger` table. Verification compares the complete local chain against the SQL ledger and flags a mismatch; this local hash chain is a tamper-evidence demonstration, not a decentralized blockchain.

## Typical demo workflow

1. All financial amounts are shown in Indian rupees (INR); existing numeric values are displayed as rupees with no currency conversion. As a customer, enter monthly income, savings, rent, other expenses, and self-reported on-time/missed payments. Save category limits. Add each active loan and monthly installment in **Monthly details**; the active EMI total is calculated automatically and replaces a manually entered monthly EMI amount. When closing a real loan, enter the self-reported total amount paid and whether every payment was on time.
2. Save entries at or above 80% / 100% of a limit to create in-app alerts. Browser notifications are requested after sign-in when supported and permitted by the browser.
3. Compute a risk score to see the category, reliability score, SHAP contribution chart, and a new audit block.
4. Create a seven-day share reference in **Bank access** and give it to an approved bank verifier. Revoke it at any time. A lookup logs a verification block, provides read-only information, and checks chain integrity.
5. As admin, approve bank accounts, enable/suspend non-admin accounts, review event logs, and retrain against the synthetic dataset.

## API and security notes

- `backend/index.php?action=...` is the JSON API. Mutations require the session-bound CSRF token returned by `action=csrf`; the included frontend sends it automatically.
- Passwords use PHP bcrypt (`password_hash` / `password_verify`). PDO prepared statements, strict PHP sessions, HTTP-only/SameSite cookies, session ID rotation, role checks on protected endpoints, input validation, and login-attempt throttling are enabled.
- Normal-user PAN format is validated in the browser and backend; the database stores only its SHA-256 hash and masked final four characters. The PAN is not independently checked against a government registry.
- EMI commitments and closed-loan payment history are self-reported records attached to the authenticated account associated with the registered PAN; the app does not ask for PAN again at sign-in, store PAN plaintext, or verify loans with a credit bureau. Closed loans are retained as history but no longer appear in the customer's active-loan list after refresh. Approved banks can see real active/closed loan details only when the customer grants consent.
- Clearly tagged synthetic demo loans are added per customer for UI practice and shown in the Overview and Monthly details. They can be closed, are not real debts, and are excluded from EMI totals, budgets, risk scoring, and bank-visible loan history.
- The “prototype risk score” is produced by the synthetic educational model; it is not a CIBIL score or a real credit-bureau result. Each customer can see their own score, and a bank verifier can see it only through active customer consent.
- Bank Employee registration requires a bank name and unique-per-bank alphanumeric employee ID. Accounts stay disabled until an admin reviews and verifies the ID; rejecting it keeps the account disabled. Bank lookup requires a random, active, non-revoked consent reference. Banks cannot update customer finance data.
- The local HTTP XAMPP setup is for development only. For any shared demo, configure HTTPS (a locally trusted or self-signed certificate is suitable for a private demo), use a non-default database account/password, and serve the frontend/API from the same origin. Session cookies are marked Secure automatically when PHP detects HTTPS.
- No real bank credentials, account logins, or live account access are requested or stored. The scores and explanations are illustrative only and must not be used to make lending decisions.

## Focused tests

```powershell
Set-Location D:\XAI\ml
.\.venv\Scripts\python.exe -m unittest discover -s tests
& C:\xampp\php\php.exe D:\XAI\backend\tests\registration_identity_test.php
```
