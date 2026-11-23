<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/seed.php';
require __DIR__ . '/lib/state.php';
require __DIR__ . '/lib/actions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$writeActions = [
    'employee.save' => 'act_employee_save', 'employee.status' => 'act_employee_status', 'employee.delete' => 'act_employee_delete',
    'leave.create' => 'act_leave_create', 'leave.decide' => 'act_leave_decide', 'leave.cancel' => 'act_leave_cancel',
    'attendance.set' => 'act_attendance_set', 'attendance.fill' => 'act_attendance_fill', 'attendance.clock' => 'act_attendance_clock',
    'event.save' => 'act_event_save', 'event.delete' => 'act_event_delete',
    'position.save' => 'act_position_save', 'position.status' => 'act_position_status', 'position.delete' => 'act_position_delete',
    'candidate.save' => 'act_candidate_save', 'candidate.move' => 'act_candidate_move',
    'payroll.bonus' => 'act_payroll_bonus', 'payroll.pay' => 'act_payroll_pay', 'payroll.create' => 'act_payroll_create',
    'settings.save' => 'act_settings_save', 'workspace.view' => 'act_workspace_view', 'profile.save' => 'act_profile_save',
    'demo.reset' => 'act_demo_reset',
];

try {
    $action = (string) ($_GET['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'];
    $in = [];
    if ($method === 'POST') {
        // Custom header blocks cross-site form posts (browsers cannot set it without CORS approval).
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'HorizonHRMS') fail('Request rejected.', 400);
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($in)) $in = [];
    }

    switch ($action) {
        case 'status':
            $ok = installed();
            respond(['installed' => $ok, 'hasUsers' => $ok && has_users(), 'user' => $ok ? current_user() : null]);

        case 'install':
            if ($method !== 'POST') fail('Use POST.', 405);
            if (installed() && has_users()) fail('Horizon HRMS is already set up.', 403);
            install_schema();
            if (!empty($in['demo'])) seed_demo();
            respond(['ok' => true]);

        case 'setup':
            if ($method !== 'POST') fail('Use POST.', 405);
            if (!installed()) fail('Set up the database first.', 409);
            if (has_users()) fail('An administrator account already exists. Sign in instead.', 403);
            $username = strtolower(str_in($in, 'username', 60, true, 'Username'));
            if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) fail('Usernames use 3–60 letters, numbers, dots, dashes or underscores.');
            $pw = (string) ($in['password'] ?? '');
            if (strlen($pw) < 8) fail('Choose a password with at least 8 characters.');
            q('INSERT INTO users (username, display_name, role_title, password_hash) VALUES (?,?,?,?)', [
                $username, str_in($in, 'name', 120, true, 'Your name'), str_in($in, 'role', 120, false) ?: 'HR administrator', password_hash($pw, PASSWORD_DEFAULT),
            ]);
            start_session();
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) db()->lastInsertId();
            respond(build_state(require_user()));

        case 'login':
            if ($method !== 'POST') fail('Use POST.', 405);
            $u = q('SELECT id, password_hash FROM users WHERE username = ?', [strtolower(trim((string) ($in['username'] ?? '')))])->fetch();
            if (!$u || !password_verify((string) ($in['password'] ?? ''), $u['password_hash'])) {
                usleep(400000);
                fail('That username and password do not match.', 401);
            }
            start_session();
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            respond(build_state(require_user()));

        case 'logout':
            start_session();
            $_SESSION = [];
            session_destroy();
            respond(['ok' => true]);

        case 'state':
            respond(build_state(require_user()));
    }

    if (!isset($writeActions[$action])) fail('Unknown action.', 404);
    if ($method !== 'POST') fail('Use POST.', 405);
    $user = require_user();
    $fn = $writeActions[$action];
    $result = $fn($in, $user);
    respond(['result' => $result, 'state' => build_state($user)]);
} catch (ApiError $e) {
    if (db_in_tx()) db()->rollBack();
    respond(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    if (db_in_tx()) db()->rollBack();
    error_log('[horizon-hrms] ' . $e);
    respond(['error' => 'Something went wrong on the server. Details were written to the Apache error log.'], 500);
}

function db_in_tx(): bool
{
    try { return db()->inTransaction(); } catch (Throwable $e) { return false; }
}
