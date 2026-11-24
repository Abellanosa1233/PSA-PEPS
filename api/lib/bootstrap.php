<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Database settings are missing. Copy api/config.example.php to api/config.php and fill in your MySQL details.']);
    exit;
}
require __DIR__ . '/../config.php';
date_default_timezone_set(APP_TIMEZONE);

const LEAVE_TYPES = ['Annual leave', 'Personal leave', 'Sick leave', 'Parental leave', 'Unpaid leave'];
const DEPARTMENTS = ['Design', 'Engineering', 'People', 'Product', 'Marketing', 'Sales', 'Finance', 'Operations'];
const STAGES = ['Applied', 'Screening', 'Interview', 'Offer', 'Hired'];
const EMP_TYPES = ['Full-time', 'Part-time', 'Contract'];
const ATT_STATUSES = ['office', 'remote', 'away'];

final class ApiError extends Exception
{
    public int $status;
    public function __construct(string $message, int $status = 422, public ?string $field = null)
    {
        parent::__construct($message);
        $this->status = $status;
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
            fail('Cannot connect to the database. Check the details in api/config.php and that MySQL is running (on UniServer, start it in UniController).', 503);
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
    return (bool) q("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = 'pay_runs'", [DB_NAME])->fetchColumn();
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
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('horizon_hrms');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
    session_start();
}

function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['uid']) || !installed()) return null;
    $u = q('SELECT id, username, display_name, role_title FROM users WHERE id = ?', [$_SESSION['uid']])->fetch();
    if (!$u) return null;
    return ['id' => (int) $u['id'], 'username' => $u['username'], 'name' => $u['display_name'], 'role' => $u['role_title']];
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
        'company' => 'Horizon Studio',
        'workspace' => 'People workspace',
        'currency' => 'PHP',
        'allowances' => ['Annual leave' => 20, 'Personal leave' => 5, 'Sick leave' => 10, 'Parental leave' => 60, 'Unpaid leave' => 30],
        'taxRate' => 20,
        'benefits' => 2000,
        'selfEmpId' => null,
        'supervisorId' => null,
    ];
}

function settings(): array
{
    $s = default_settings();
    foreach (q('SELECT k, v FROM settings')->fetchAll() as $row) {
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
    if ($required && $v === '') fail(($label ?: ucfirst($key)) . ' is required.', field: $key);
    if (mb_strlen($v) > $max) fail(($label ?: ucfirst($key)) . " must be $max characters or fewer.", field: $key);
    return $v;
}

function date_in(array $in, string $key, bool $required = true, string $label = 'Date'): ?string
{
    $v = trim((string) ($in[$key] ?? ''));
    if ($v === '') {
        if ($required) fail("$label is required.", field: $key);
        return null;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) fail("$label must be a valid date.", field: $key);
    return $v;
}

function time_in(array $in, string $key): ?string
{
    $v = trim((string) ($in[$key] ?? ''));
    if ($v === '') return null;
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) fail('Times must look like 09:30.', field: $key);
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
function ph_phone(string $raw): string
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
    fail('Enter a Philippine number, like 0917 123 4567 or (02) 8123 4567.');
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

/* Pay calculation — the single source of truth for payslips. */
function compute_lines(int $runId, string $period): array
{
    $s = settings();
    $start = $period . '-01';
    $end = period_end($period);
    $bonuses = [];
    foreach (q('SELECT emp_id, amount FROM pay_bonuses WHERE run_id = ?', [$runId])->fetchAll() as $b) {
        $bonuses[(int) $b['emp_id']] = (float) $b['amount'];
    }
    $rows = q(
        'SELECT id, first_name, last_name, department, salary FROM employees
         WHERE start_date <= ? AND (end_date IS NULL OR end_date >= ?) ORDER BY first_name, last_name',
        [$end, $start]
    )->fetchAll();
    $lines = [];
    foreach ($rows as $e) {
        $base = round((float) $e['salary'] / 12, 2);
        $bonus = $bonuses[(int) $e['id']] ?? 0.0;
        $gross = $base + $bonus;
        $tax = round($gross * (float) $s['taxRate'] / 100, 2);
        $benefits = (float) $s['benefits'];
        $lines[] = [
            'empId' => (int) $e['id'], 'name' => $e['first_name'] . ' ' . $e['last_name'], 'department' => $e['department'],
            'base' => $base, 'bonus' => $bonus, 'gross' => round($gross, 2), 'tax' => $tax, 'benefits' => $benefits,
            'net' => round($gross - $tax - $benefits, 2),
        ];
    }
    return $lines;
}
