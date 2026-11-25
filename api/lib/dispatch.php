<?php
declare(strict_types=1);

function action_handler(string $action): string
{
    static $writeActions = [
        'employee.save' => 'act_employee_save', 'employee.status' => 'act_employee_status', 'employee.delete' => 'act_employee_delete',
        'employee.request.create' => 'act_employee_request_create', 'employee.request.decide' => 'act_employee_request_decide',
        'employee.request.cancel' => 'act_employee_request_cancel',
        'leave.create' => 'act_leave_create', 'leave.decide' => 'act_leave_decide', 'leave.cancel' => 'act_leave_cancel',
        'attendance.set' => 'act_attendance_set', 'attendance.fill' => 'act_attendance_fill', 'attendance.clock' => 'act_attendance_clock',
        'event.save' => 'act_event_save', 'event.delete' => 'act_event_delete',
        'position.save' => 'act_position_save', 'position.status' => 'act_position_status', 'position.delete' => 'act_position_delete',
        'candidate.save' => 'act_candidate_save', 'candidate.move' => 'act_candidate_move',
        'payroll.bonus' => 'act_payroll_bonus', 'payroll.pay' => 'act_payroll_pay', 'payroll.create' => 'act_payroll_create',
        'settings.save' => 'act_settings_save', 'workspace.view' => 'act_workspace_view', 'profile.save' => 'act_profile_save',
        'password.change' => 'act_password_change', 'demo.reset' => 'act_demo_reset',
        'account.create' => 'act_account_create', 'account.password' => 'act_account_password', 'account.remove' => 'act_account_remove',
        'announcement.save' => 'act_announcement_save', 'announcement.delete' => 'act_announcement_delete',
    ];
    if (!isset($writeActions[$action])) fail('Unknown action.', 404);
    return $writeActions[$action];
}

/* The single gate for every write an employee account makes. HR administrators may do
   anything; employees may only act on their own records, plus approve or decline their
   direct reports' Special and Travel Orders. Leave is HR-only: supervisors can't decide
   (or even see) their team's leave, so nobody learns of a colleague's planned time off.
   Inputs that name an employee are overwritten with the signed-in employee, so a
   tampered form can't act for someone else. */
function authorize_action(string $action, array &$in, array $user): void
{
    if (is_admin($user)) return;
    $me = (int) $user['employeeId'];
    switch ($action) {
        case 'password.change':
        case 'profile.photo':
        case 'profile.photo.remove':
            return;

        case 'attendance.clock':
        case 'employee.request.create':
        case 'employee.request.cancel':
            $in['empId'] = $me;
            return;

        case 'leave.create':
            $in['empId'] = $me;
            unset($in['approveNow']);
            if (trim((string) ($in['reason'] ?? '')) === '') fail('Add a reason so HR can review your request.', 422, 'reason');
            return;

        case 'leave.cancel':
            $leave = q('SELECT emp_id, status FROM leave_requests WHERE id = ?', [(int) ($in['id'] ?? 0)])->fetch();
            if (!$leave || (int) $leave['emp_id'] !== $me) fail('You can only cancel your own requests.', 403);
            if ($leave['status'] !== 'pending') fail('Approved time off can only be cancelled by HR. Contact your HR administrator.', 403);
            return;

        case 'leave.decide':
            fail('Leave requests are approved by HR only.', 403);

        case 'employee.request.decide':
            $in['reviewerId'] = $me; // act_employee_request_decide checks the reviewer is the direct supervisor
            return;
    }
    fail('Your account doesn’t have access to that. Ask your HR administrator.', 403);
}

function account_action(string $action, array $in): array
{
    switch ($action) {
        case 'install':
            if (installed() && has_users()) fail('Horizon HRMS is already set up.', 403);
            check_setup_key($in);
            install_schema();
            if (!empty($in['demo'])) seed_demo();
            return ['ok' => true];

        case 'setup':
            if (!installed()) fail('Set up the database first.', 409);
            if (has_users()) fail('An administrator account already exists. Sign in instead.', 403);
            check_setup_key($in);
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
            return build_state(require_user());

        case 'login':
            $username = strtolower(trim((string) ($in['username'] ?? '')));
            $password = (string) ($in['password'] ?? '');
            if ($username === '') fail('Please enter your username.', 422, 'username');
            if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
                fail('Username must be 3–60 characters and may contain only letters, numbers, periods, hyphens, and underscores.', 422, 'username');
            }
            if ($password === '') fail('Please enter your password.', 422, 'password');
            ensure_account_columns();
            $u = q('SELECT u.id, u.password_hash, u.account_role, e.status AS employee_status
                    FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.username = ?', [$username])->fetch();
            if (!$u || !password_verify($password, $u['password_hash'])) {
                usleep(400000);
                fail('The username or password is incorrect.', 401);
            }
            if ($u['account_role'] === 'employee' && $u['employee_status'] !== 'Active') {
                fail('This account is no longer active. Contact your HR administrator.', 403);
            }
            start_session();
            session_regenerate_id(true);
            $_SESSION['uid'] = (int) $u['id'];
            return build_state(require_user());

        case 'logout':
            start_session();
            $_SESSION = [];
            session_destroy();
            return ['ok' => true];
    }
    fail('Unknown action.', 404);
}
