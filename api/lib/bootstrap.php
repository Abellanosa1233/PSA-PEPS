<?php
declare(strict_types=1);

$configPath = __DIR__ . '/../config.php';
$loopback = ['127.0.0.1', '::1'];
$localRequest = in_array($_SERVER['REMOTE_ADDR'] ?? '', $loopback, true)
    && preg_match('/^(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]+)?$/iD', $_SERVER['HTTP_HOST'] ?? '')
    && !isset($_SERVER['HTTP_FORWARDED'])
    && !isset($_SERVER['HTTP_X_FORWARDED_FOR']);
if (is_file($configPath)) {
    require $configPath;
} elseif ($localRequest) {
    define('DB_HOST', '127.0.0.1');
    define('DB_PORT', 3306);
    define('DB_NAME', 'horizon_hrms');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('APP_TIMEZONE', 'Asia/Manila');
    define('SETUP_KEY', '');
} else {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'This server requires a database configuration. Copy api/config.example.php to api/config.php and fill in your MySQL details.']);
    exit;
}
date_default_timezone_set(APP_TIMEZONE);

const LEAVE_TYPES = ['Annual leave', 'Personal leave', 'Sick leave', 'Parental leave', 'Unpaid leave'];
const DEPARTMENTS = ['Design', 'Engineering', 'People', 'Product', 'Marketing', 'Sales', 'Finance', 'Operations'];
const STAGES = ['Applied', 'Screening', 'Interview', 'Offer', 'Hired'];
const EMP_TYPES = ['Full-time', 'Part-time', 'Contract'];
const ATT_STATUSES = ['office', 'remote', 'away'];

final class ApiError extends Exception
{
    public $status;
    public $field;
    public function __construct(string $message, int $status = 422, ?string $field = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->field = $field;
    }
}

/** @return never */
function fail(string $message, int $status = 422, ?string $field = null): void
{
    throw new ApiError($message, $status, $field);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            $offset = (new DateTime())->format('P');
            $pdo->exec("SET time_zone = '$offset'");
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? $e->getCode());
            $messages = [
                1044 => 'The MySQL user does not have access to the database. Check its permissions and DB_USER in api/config.php.',
                1045 => 'MySQL rejected the login. Set DB_USER and DB_PASS in api/config.php to the credentials created on this computer.',
                1049 => 'The database does not exist. Create the database in phpMyAdmin and check DB_NAME in api/config.php.',
                2002 => 'Cannot reach MySQL. Start MySQL in XAMPP and check DB_HOST and DB_PORT in api/config.php.',
                2003 => 'Cannot reach MySQL. Start MySQL in XAMPP and check DB_HOST and DB_PORT in api/config.php.',
            ];
            fail($messages[$code] ?? 'Cannot connect to the database. Check api/config.php and make sure MySQL and the PHP PDO MySQL extension are enabled.', 503);
        }
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function installed(): bool
{
    return (bool) q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'candidates'", [DB_NAME])->fetchColumn();
}

/* Optional SETUP_KEY in config.php: when set, creating tables and the first
   administrator requires it, so a stranger can't claim a fresh public install. */
function setup_key_required(): bool
{
    return defined('SETUP_KEY') && (string) SETUP_KEY !== '';
}

function check_setup_key(array $in): void
{
    if (setup_key_required() && !hash_equals((string) SETUP_KEY, trim((string) ($in['setupKey'] ?? '')))) {
        fail('The setup key is not correct. Use the SETUP_KEY value from api/config.php.', 403);
    }
}

function has_users(): bool
{
    return installed() && (int) q('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

/* ---------- Session / auth ---------- */
const ACCOUNT_SESSION_IDLE_SECONDS = 1800;

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.gc_maxlifetime', (string) ACCOUNT_SESSION_IDLE_SECONDS);
    session_name('horizon_hrms');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
    } else {
        // PHP 7.2 takes positional arguments. Append SameSite to the cookie path header.
        session_set_cookie_params(0, '/; SameSite=Strict', '', false, true);
    }
    session_start();
}

function account_session_hash(): ?string
{
    $token = $_SESSION['account_session_token'] ?? null;
    return is_string($token) ? hash('sha256', $token) : null;
}

function release_account_session(): void
{
    $hash = account_session_hash();
    if (empty($_SESSION['uid']) || $hash === null) return;
    ensure_account_columns();
    q('UPDATE users SET session_token_hash = NULL, session_expires_at = NULL
       WHERE id = ? AND session_token_hash = ?', [$_SESSION['uid'], $hash]);
}

function account_session_status(): string
{
    $hash = account_session_hash();
    if (empty($_SESSION['uid']) || $hash === null) return 'ended';
    $account = q('SELECT session_token_hash, session_expires_at > UTC_TIMESTAMP(6) AS active
                  FROM users WHERE id = ?', [$_SESSION['uid']])->fetch();
    if (!$account || $account['session_token_hash'] === null) return 'ended';
    if (!hash_equals($account['session_token_hash'], $hash)) return 'replaced';
    return $account['active'] ? 'active' : 'ended';
}

function sign_in_account(int $id): void
{
    start_session();
    ensure_account_columns();
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $previousId = (int) ($_SESSION['uid'] ?? 0);
    if (!session_regenerate_id(true)) fail('Sign-in could not be started. Please try again.', 500);

    // The most recent successful sign-in replaces the token on the account row.
    $claimed = q('UPDATE users SET session_token_hash = ?,
                  session_expires_at = TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6))
                  WHERE id = ?', [$hash, ACCOUNT_SESSION_IDLE_SECONDS, $id]);
    if (!$claimed->rowCount()) {
        fail('This account is no longer available.', 401);
    }

    if ($previousId !== $id) release_account_session();
    $_SESSION['uid'] = $id;
    $_SESSION['account_session_token'] = $token;
    unset($_SESSION['account_session_replaced']);
}

function current_user(): ?array
{
    start_session();
    $hash = account_session_hash();
    if (empty($_SESSION['uid']) || $hash === null || !installed()) return null;
    ensure_account_columns();
    $refreshed = q('UPDATE users SET session_expires_at = GREATEST(
                        TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(6)),
                        TIMESTAMPADD(MICROSECOND, 1, session_expires_at))
                    WHERE id = ? AND session_token_hash = ? AND session_expires_at > UTC_TIMESTAMP(6)',
        [ACCOUNT_SESSION_IDLE_SECONDS, $_SESSION['uid'], $hash]);
    if (!$refreshed->rowCount()) {
        if (account_session_status() === 'replaced') $_SESSION['account_session_replaced'] = true;
        unset($_SESSION['uid'], $_SESSION['account_session_token']);
        return null;
    }
    ensure_profile_image_columns();
    ensure_employee_requests_table();
    ensure_biometrics();
    ensure_announcements();
    ensure_leave_extras();
    ensure_attendance_corrections();
    ensure_profile_details();
    $u = q('SELECT u.id, u.username, u.display_name, u.role_title, u.profile_image_updated_at, u.account_role, u.employee_id,
                   u.must_change_password, e.first_name, e.last_name, e.title, e.status AS employee_status
            FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ?', [$_SESSION['uid']])->fetch();
    if (!$u) return null;
    $isEmployee = $u['account_role'] === 'employee';
    // Employee logins stop working as soon as the employee is offboarded or deleted.
    if ($isEmployee && (!$u['employee_id'] || $u['employee_status'] !== 'Active')) return null;
    $employeeId = $isEmployee ? (int) $u['employee_id'] : null;
    return [
        'id' => (int) $u['id'], 'username' => $u['username'],
        // Employees always see their name and title as HR keeps them in the employee record.
        'name' => $isEmployee ? $u['first_name'] . ' ' . $u['last_name'] : $u['display_name'],
        'role' => $isEmployee ? $u['title'] : $u['role_title'],
        'accountRole' => $isEmployee ? 'employee' : 'admin',
        'employeeId' => $employeeId,
        'hasTeam' => $isEmployee && (int) q("SELECT COUNT(*) FROM employees WHERE manager_id = ? AND status = 'Active'", [$employeeId])->fetchColumn() > 0,
        'mustChangePassword' => (bool) $u['must_change_password'],
        'avatarUrl' => $u['profile_image_updated_at']
            ? 'api/index.php?action=profile.image&v=' . rawurlencode((string) strtotime($u['profile_image_updated_at']))
            : null,
    ];
}

/* Biometric support for databases created before it existed: each employee's ID on the
   biometric device, and every raw time punch (from the device import or web clock-in). */
function ensure_biometrics(): void
{
    static $checked = false;
    if ($checked) return;
    $has = q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'employees' AND column_name = 'biometric_id'", [DB_NAME])->fetchColumn();
    if (!$has) q('ALTER TABLE employees ADD COLUMN biometric_id VARCHAR(30) NULL, ADD UNIQUE KEY uq_employees_biometric (biometric_id)');
    // Check first: in MySQL any CREATE TABLE, even "IF NOT EXISTS" on an existing table,
    // silently commits an open transaction.
    $exists = q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'attendance_punches'", [DB_NAME])->fetchColumn();
    if (!$exists) db()->exec("CREATE TABLE attendance_punches (
        id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, punched_at DATETIME NOT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'biometric', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_punch (emp_id, punched_at), INDEX idx_punch_time (punched_at),
        CONSTRAINT fk_punch_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $checked = true;
}

/* Company updates that HR posts for everyone (shown on the employee Overview). */
function ensure_announcements(): void
{
    static $checked = false;
    if ($checked) return;
    // Check first: CREATE TABLE would silently commit an open transaction.
    $exists = q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'announcements'", [DB_NAME])->fetchColumn();
    if (!$exists) db()->exec("CREATE TABLE announcements (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(120) NOT NULL, body VARCHAR(500) NOT NULL DEFAULT '',
        created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_announcement_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Calendar events are HR-only unless HR ticks "Show on employees' schedule".
    $col = q("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = 'events' AND column_name = 'for_employees'", [DB_NAME])->fetchColumn();
    if (!$col) q('ALTER TABLE events ADD COLUMN for_employees TINYINT(1) NOT NULL DEFAULT 0');
    $checked = true;
}

/* Half-day leave and supporting documents on leave requests (older databases stored
   whole days only). Every change is checked first, because DDL commits open transactions. */
function ensure_leave_extras(): void
{
    static $checked = false;
    if ($checked) return;
    $cols = [];
    foreach (q("SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.columns WHERE table_schema = ? AND table_name = 'leave_requests'", [DB_NAME])->fetchAll() as $c) {
        $cols[$c['COLUMN_NAME']] = strtolower($c['DATA_TYPE']);
    }
    if (($cols['days'] ?? 'decimal') !== 'decimal') q('ALTER TABLE leave_requests MODIFY days DECIMAL(5,1) NOT NULL');
    if (!isset($cols['day_length'])) q("ALTER TABLE leave_requests ADD COLUMN day_length VARCHAR(10) NOT NULL DEFAULT 'full'");
    if (!isset($cols['attachment'])) q('ALTER TABLE leave_requests ADD COLUMN attachment MEDIUMBLOB NULL, ADD COLUMN attachment_name VARCHAR(160) NULL, ADD COLUMN attachment_type VARCHAR(60) NULL');
    $checked = true;
}

/* Employees ask HR to fix a missing or wrong time; an approved correction replaces that day's times. */
function ensure_attendance_corrections(): void
{
    static $checked = false;
    if ($checked) return;
    // Check first: CREATE TABLE would silently commit an open transaction.
    $exists = q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'attendance_corrections'", [DB_NAME])->fetchColumn();
    if (!$exists) db()->exec("CREATE TABLE attendance_corrections (
        id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, work_date DATE NOT NULL,
        time_in TIME NULL, time_out TIME NULL, reason VARCHAR(255) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        decided_at DATETIME NULL, decided_by INT NULL,
        INDEX idx_correction_status (status), INDEX idx_correction_emp_date (emp_id, work_date),
        CONSTRAINT fk_correction_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE,
        CONSTRAINT fk_correction_user FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $checked = true;
}

/* My profile: personal and emergency details the employee keeps up to date, and documents HR
   shares (for one employee, or everyone when emp_id is NULL) with acknowledgements. */
function ensure_profile_details(): void
{
    static $checked = false;
    if ($checked) return;
    $cols = array_flip(q("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = ? AND table_name = 'employees'", [DB_NAME])->fetchAll(PDO::FETCH_COLUMN));
    $add = [];
    foreach (['preferred_name' => 'VARCHAR(80) NULL', 'personal_email' => 'VARCHAR(160) NULL', 'home_address' => 'VARCHAR(255) NULL',
              'emergency_name' => 'VARCHAR(120) NULL', 'emergency_relationship' => 'VARCHAR(60) NULL', 'emergency_phone' => 'VARCHAR(40) NULL'] as $name => $def) {
        if (!isset($cols[$name])) $add[] = "ADD COLUMN $name $def";
    }
    if ($add) q('ALTER TABLE employees ' . implode(', ', $add));
    // Check first: CREATE TABLE would silently commit an open transaction.
    $tables = array_flip(q("SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name IN ('employee_documents', 'document_acknowledgements')", [DB_NAME])->fetchAll(PDO::FETCH_COLUMN));
    if (!isset($tables['employee_documents'])) db()->exec("CREATE TABLE employee_documents (
        id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NULL, title VARCHAR(120) NOT NULL, note VARCHAR(160) NOT NULL DEFAULT '',
        requires_ack TINYINT(1) NOT NULL DEFAULT 0, file MEDIUMBLOB NOT NULL, file_name VARCHAR(160) NOT NULL, file_type VARCHAR(60) NOT NULL,
        created_by INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_document_emp (emp_id),
        CONSTRAINT fk_document_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE,
        CONSTRAINT fk_document_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!isset($tables['document_acknowledgements'])) db()->exec("CREATE TABLE document_acknowledgements (
        document_id INT NOT NULL, emp_id INT NOT NULL, acknowledged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (document_id, emp_id),
        CONSTRAINT fk_ack_document FOREIGN KEY (document_id) REFERENCES employee_documents(id) ON DELETE CASCADE,
        CONSTRAINT fk_ack_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $checked = true;
}

/* "½ day", "1 day", "2.5 days" */
function days_label($days): string
{
    $d = (float) $days;
    if ($d == 0.5) return '½ day';
    $n = $d == floor($d) ? (string) (int) $d : rtrim(rtrim(number_format($d, 1), '0'), '.');
    return $n . ($d == 1 ? ' day' : ' days');
}

function is_admin(array $user): bool
{
    return ($user['accountRole'] ?? 'admin') === 'admin';
}

/* Adds employee-account columns to databases created before employee logins existed. */
function ensure_account_columns(): void
{
    static $checked = false;
    if ($checked) return;
    $columns = array_flip(q('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [DB_NAME, 'users'])->fetchAll(PDO::FETCH_COLUMN));
    if (!isset($columns['account_role'])) q("ALTER TABLE users ADD COLUMN account_role VARCHAR(20) NOT NULL DEFAULT 'admin'");
    if (!isset($columns['must_change_password'])) q('ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0');
    if (!isset($columns['employee_id'])) q('ALTER TABLE users ADD COLUMN employee_id INT NULL, ADD UNIQUE KEY uq_users_employee (employee_id)');
    foreach (['session_token_hash' => 'CHAR(64) NULL', 'session_expires_at' => 'DATETIME(6) NULL'] as $column => $definition) {
        if (isset($columns[$column])) continue;
        try {
            q("ALTER TABLE users ADD COLUMN $column $definition");
        } catch (PDOException $e) {
            // Another request can add the column between the metadata query and ALTER.
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060) throw $e;
        }
    }
    $hasFk = (int) q("SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = 'users' AND constraint_name = 'fk_users_employee'", [DB_NAME])->fetchColumn();
    if (!$hasFk) {
        q("DELETE FROM users WHERE account_role = 'employee' AND (employee_id IS NULL OR employee_id NOT IN (SELECT id FROM employees))");
        q('ALTER TABLE users ADD CONSTRAINT fk_users_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE');
    }
    $checked = true;
}

function ensure_profile_image_columns(): void
{
    static $checked = false;
    if ($checked) return;
    $columns = q('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', [DB_NAME, 'users'])->fetchAll(PDO::FETCH_COLUMN);
    $columns = array_flip($columns);
    foreach (['profile_image' => 'MEDIUMBLOB NULL', 'profile_image_type' => 'VARCHAR(30) NULL', 'profile_image_updated_at' => 'DATETIME NULL'] as $name => $definition) {
        if (!isset($columns[$name])) q("ALTER TABLE users ADD COLUMN $name $definition");
    }
    $checked = true;
}

function ensure_employee_requests_table(): void
{
    static $checked = false;
    if ($checked) return;
    $exists = q('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?', [DB_NAME, 'employee_requests'])->fetchColumn();
    if (!$exists) {
        db()->exec("CREATE TABLE employee_requests (
            id INT AUTO_INCREMENT PRIMARY KEY, emp_id INT NOT NULL, request_type VARCHAR(24) NOT NULL,
            purpose VARCHAR(160) NOT NULL, destination VARCHAR(160) NOT NULL DEFAULT '',
            start_date DATE NOT NULL, end_date DATE NULL, details VARCHAR(1000) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending', decision_role VARCHAR(24) NULL,
            decided_by_user_id INT NULL, decided_by_employee_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME NULL,
            INDEX idx_employee_request_status (status), INDEX idx_employee_request_emp (emp_id),
            CONSTRAINT fk_employee_request_emp FOREIGN KEY (emp_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_employee_request_user FOREIGN KEY (decided_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_employee_request_reviewer FOREIGN KEY (decided_by_employee_id) REFERENCES employees(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    $checked = true;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) fail('Your session has ended. Sign in again.', 401);
    return $u;
}

/* ---------- Settings ---------- */
function default_settings(): array
{
    return [
        'company' => 'Philippine Statistics Authority',
        'workspace' => 'People workspace',
        'currency' => 'PHP',
        'allowances' => ['Annual leave' => 20, 'Personal leave' => 5, 'Sick leave' => 10, 'Parental leave' => 60, 'Unpaid leave' => 30],
        'selfEmpId' => null,
        'supervisorId' => null,
        'workStart' => '08:00', // office hours, shown as each employee's schedule
        'workEnd' => '17:00',
    ];
}

function settings(): array
{
    $s = default_settings();
    foreach (q('SELECT k, v FROM settings')->fetchAll() as $row) {
        if (!array_key_exists($row['k'], $s)) continue;
        $s[$row['k']] = json_decode($row['v'], true);
    }
    return $s;
}

function set_setting(string $k, $v): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, json_encode($v)]);
}

/* ---------- Input helpers ---------- */
function str_in(array $in, string $key, int $max = 255, bool $required = true, string $label = ''): string
{
    $v = trim((string) ($in[$key] ?? ''));
    if ($required && $v === '') fail(($label ?: ucfirst($key)) . ' is required.', 422, $key);
    if (mb_strlen($v) > $max) fail(($label ?: ucfirst($key)) . " must be $max characters or fewer.", 422, $key);
    return $v;
}

function date_in(array $in, string $key, bool $required = true, string $label = 'Date'): ?string
{
    $v = trim((string) ($in[$key] ?? ''));
    if ($v === '') {
        if ($required) fail("$label is required.", 422, $key);
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) fail("$label must be a valid date.", 422, $key);
    return $v;
}

function time_in(array $in, string $key): ?string
{
    $v = trim((string) ($in[$key] ?? ''));
    if ($v === '') return null;
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) fail('Times must look like 09:30.', 422, $key);
    return $v;
}

function int_in(array $in, string $key, bool $required = true): ?int
{
    $v = $in[$key] ?? null;
    if ($v === null || $v === '') {
        if ($required) fail(ucfirst($key) . ' is required.');
        return null;
    }
    if (!is_numeric($v)) fail(ucfirst($key) . ' must be a number.');
    return (int) $v;
}

function one_of(string $v, array $allowed, string $label): string
{
    if (!in_array($v, $allowed, true)) fail("$label is not valid.");
    return $v;
}

/* Philippine phone numbers, stored as +63 917 123 4567 / +63 2 8123 4567 / +63 32 123 4567.
   Accepts 09171234567, 0917-123-4567, +639171234567, (02) 8123 4567, (032) 123 4567 … */
function ph_phone(string $raw, ?string $field = null): string
{
    $raw = trim($raw);
    if ($raw === '') return '';
    $d = preg_replace('/[\s\-().]/', '', $raw);
    if (preg_match('/^(?:\+?63|0)?(9\d{9})$/', $d, $m)) {
        $n = $m[1];
        return '+63 ' . substr($n, 0, 3) . ' ' . substr($n, 3, 3) . ' ' . substr($n, 6);
    }
    if (preg_match('/^(?:\+?63|0)(2\d{8})$/', $d, $m)) {
        $n = $m[1];
        return '+63 2 ' . substr($n, 1, 4) . ' ' . substr($n, 5);
    }
    if (preg_match('/^(?:\+?63|0)([3-8]\d{8})$/', $d, $m)) {
        $n = $m[1];
        return '+63 ' . substr($n, 0, 2) . ' ' . substr($n, 2, 3) . ' ' . substr($n, 5);
    }
    fail('Enter a Philippine number, like 0917 123 4567 or (02) 8123 4567.', 422, $field);
}

function workdays(string $a, string $b): int
{
    $n = 0;
    $d = new DateTime($a);
    $e = new DateTime($b);
    while ($d <= $e) {
        if ((int) $d->format('N') < 6) $n++;
        $d->modify('+1 day');
    }
    return $n;
}

function today(): string
{
    return date('Y-m-d');
}

function period_end(string $period): string
{
    return (new DateTime($period . '-01'))->format('Y-m-t');
}
