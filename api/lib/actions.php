<?php
declare(strict_types=1);

/* Every write the app can make. Each handler validates its input and throws
   ApiError with a message the user can act on. */

function employee_exists(int $id): array
{
    $e = q('SELECT * FROM employees WHERE id = ?', [$id])->fetch();
    if (!$e) fail('That employee no longer exists.', 404);
    return $e;
}

function act_employee_save(array $in): array
{
    $id = int_in($in, 'id', false);
    $f = [
        'first_name' => str_in($in, 'firstName', 80, true, 'First name'),
        'last_name' => str_in($in, 'lastName', 80, true, 'Last name'),
        'email' => strtolower(str_in($in, 'email', 160, true, 'Email')),
        'phone' => ph_phone(str_in($in, 'phone', 40, false)),
        'title' => str_in($in, 'title', 120, true, 'Job title'),
        'department' => one_of(str_in($in, 'department', 60, true, 'Department'), DEPARTMENTS, 'Department'),
        'location' => str_in($in, 'location', 80, false),
        'emp_type' => one_of(str_in($in, 'type', 20, true, 'Employment type'), EMP_TYPES, 'Employment type'),
        'start_date' => date_in($in, 'startDate', true, 'Start date'),
        'salary' => (float) ($in['salary'] ?? 0),
        'manager_id' => int_in($in, 'managerId', false),
    ];
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address, like name@company.com.', field: 'email');
    if ($f['salary'] < 0 || $f['salary'] > 10000000) fail('Annual salary must be between 0 and 10,000,000.', field: 'salary');
    $dupe = q('SELECT id FROM employees WHERE email = ? AND id <> ?', [$f['email'], $id ?? 0])->fetchColumn();
    if ($dupe) fail('Another employee already uses that email address.', field: 'email');
    if ($f['manager_id'] !== null) {
        if ($id !== null && $f['manager_id'] === $id) fail('An employee cannot be their own manager.');
        employee_exists($f['manager_id']);
    }
    if ($id) {
        employee_exists($id);
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
        $updated = q("UPDATE employees SET $sets WHERE id = ?", [...array_values($f), $id]);
        return ['id' => $id, 'changed' => $updated->rowCount() > 0];
    } else {
        $cols = implode(', ', array_keys($f));
        $marks = implode(', ', array_fill(0, count($f), '?'));
        q("INSERT INTO employees ($cols) VALUES ($marks)", array_values($f));
        $id = (int) db()->lastInsertId();
        $candId = int_in($in, 'fromCandidate', false);
        if ($candId) q('UPDATE candidates SET employee_id = ? WHERE id = ?', [$id, $candId]);
    }
    return ['id' => $id];
}

function act_employee_status(array $in): array
{
    $id = int_in($in, 'id');
    employee_exists($id);
    $status = one_of((string) ($in['status'] ?? ''), ['Active', 'Offboarded'], 'Status');
    if ($status === 'Offboarded') {
        $end = date_in($in, 'endDate', true, 'Last working day');
        q("UPDATE employees SET status = 'Offboarded', end_date = ? WHERE id = ?", [$end, $id]);
        q("UPDATE employees SET manager_id = NULL WHERE manager_id = ?", [$id]);
        q("DELETE FROM leave_requests WHERE emp_id = ? AND status = 'pending'", [$id]);
    } else {
        q("UPDATE employees SET status = 'Active', end_date = NULL WHERE id = ?", [$id]);
    }
    return [];
}

function act_employee_delete(array $in): array
{
    $id = int_in($in, 'id');
    employee_exists($id);
    q('DELETE FROM employees WHERE id = ?', [$id]);
    return [];
}

/* ---------- Time off ---------- */
function act_leave_create(array $in): array
{
    $emp = employee_exists(int_in($in, 'empId'));
    if ($emp['status'] !== 'Active') fail('Time off can only be requested for active employees.');
    $type = one_of((string) ($in['type'] ?? ''), LEAVE_TYPES, 'Leave type');
    $start = date_in($in, 'start', true, 'First day');
    $end = date_in($in, 'end', true, 'Last day');
    if ($end < $start) fail('The last day must be on or after the first day.', field: 'end');
    $days = workdays($start, $end);
    if ($days === 0) fail('Those dates fall on a weekend. Pick at least one weekday.');
    $overlap = q("SELECT COUNT(*) FROM leave_requests WHERE emp_id = ? AND status IN ('pending','approved') AND start_date <= ? AND end_date >= ?", [$emp['id'], $end, $start])->fetchColumn();
    if ($overlap) fail('These dates overlap another request for ' . $emp['first_name'] . '.');
    $s = settings();
    $allow = (int) ($s['allowances'][$type] ?? 0);
    $year = substr($start, 0, 4);
    $taken = (int) q("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE emp_id = ? AND leave_type = ? AND status IN ('pending','approved') AND YEAR(start_date) = ?", [$emp['id'], $type, $year])->fetchColumn();
    if ($taken + $days > $allow) fail($emp['first_name'] . ' has ' . max(0, $allow - $taken) . " day(s) of $type left this year; this request needs $days.");
    $status = !empty($in['approveNow']) ? 'approved' : 'pending';
    q('INSERT INTO leave_requests (emp_id,leave_type,start_date,end_date,days,status,reason,created_at,decided_at) VALUES (?,?,?,?,?,?,?,?,?)',
        [$emp['id'], $type, $start, $end, $days, $status, str_in($in, 'reason', 255, false), today(), $status === 'approved' ? date('Y-m-d H:i:s') : null]);
    return ['id' => (int) db()->lastInsertId()];
}

function act_leave_decide(array $in): array
{
    $id = int_in($in, 'id');
    $status = one_of((string) ($in['status'] ?? ''), ['approved', 'rejected'], 'Decision');
    $l = q('SELECT status FROM leave_requests WHERE id = ?', [$id])->fetch();
    if (!$l) fail('That request no longer exists.', 404);
    if ($l['status'] !== 'pending') fail('This request was already ' . $l['status'] . '.');
    q('UPDATE leave_requests SET status = ?, decided_at = NOW() WHERE id = ?', [$status, $id]);
    return [];
}

function act_leave_cancel(array $in): array
{
    $id = int_in($in, 'id');
    $l = q('SELECT status, end_date FROM leave_requests WHERE id = ?', [$id])->fetch();
    if (!$l) fail('That request no longer exists.', 404);
    if ($l['status'] === 'approved' && $l['end_date'] < today()) fail('Past time off cannot be cancelled.');
    q('DELETE FROM leave_requests WHERE id = ?', [$id]);
    return [];
}

/* ---------- Attendance ---------- */
function act_attendance_set(array $in): array
{
    $date = date_in($in, 'date');
    $emp = employee_exists(int_in($in, 'empId'));
    $status = (string) ($in['status'] ?? '');
    if ($status === '') {
        q('DELETE FROM attendance WHERE work_date = ? AND emp_id = ?', [$date, $emp['id']]);
        return [];
    }
    one_of($status, ATT_STATUSES, 'Attendance status');
    $tin = $status === 'away' ? null : time_in($in, 'in');
    $tout = $status === 'away' ? null : time_in($in, 'out');
    if ($tin && $tout && $tout <= $tin) fail('Clock-out must be later than clock-in.');
    q('INSERT INTO attendance (work_date, emp_id, status, time_in, time_out) VALUES (?,?,?,?,?)
       ON DUPLICATE KEY UPDATE status = VALUES(status), time_in = VALUES(time_in), time_out = VALUES(time_out)',
        [$date, $emp['id'], $status, $tin, $tout]);
    return [];
}

function act_attendance_fill(array $in): array
{
    $date = date_in($in, 'date');
    $past = $date < today();
    $n = q("INSERT IGNORE INTO attendance (work_date, emp_id, status, time_in, time_out)
            SELECT ?, e.id, 'office', '09:00', ? FROM employees e
            WHERE e.status = 'Active' AND e.start_date <= ?
              AND NOT EXISTS (SELECT 1 FROM leave_requests l WHERE l.emp_id = e.id AND l.status = 'approved' AND l.start_date <= ? AND l.end_date >= ?)",
        [$date, $past ? '17:30' : null, $date, $date, $date])->rowCount();
    return ['count' => $n];
}

function act_attendance_clock(array $in): array
{
    $emp = employee_exists(int_in($in, 'empId'));
    $day = today();
    $now = date('H:i');
    $mode = one_of((string) ($in['mode'] ?? ''), ['in', 'out'], 'Clock action');
    $rec = q('SELECT * FROM attendance WHERE work_date = ? AND emp_id = ?', [$day, $emp['id']])->fetch();
    if ($mode === 'in') {
        $status = one_of((string) ($in['status'] ?? 'office'), ['office', 'remote'], 'Work location');
        if ($rec && $rec['time_in']) fail('Already clocked in today at ' . substr($rec['time_in'], 0, 5) . '.');
        q('INSERT INTO attendance (work_date, emp_id, status, time_in) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status), time_in = VALUES(time_in), time_out = NULL',
            [$day, $emp['id'], $status, $now]);
    } else {
        if (!$rec || !$rec['time_in']) fail('Clock in before clocking out.');
        if ($rec['time_out']) fail('Already clocked out today.');
        if ($now <= substr($rec['time_in'], 0, 5)) fail('Clock-out must be later than clock-in.');
        q('UPDATE attendance SET time_out = ? WHERE work_date = ? AND emp_id = ?', [$now, $day, $emp['id']]);
    }
    return [];
}

/* ---------- Schedule ---------- */
function act_event_save(array $in): array
{
    $id = int_in($in, 'id', false);
    $start = time_in($in, 'start') ?? fail('Start time is required.', field: 'start');
    $end = time_in($in, 'end') ?? fail('End time is required.', field: 'end');
    if ($end <= $start) fail('The event must end after it starts.', field: 'end');
    $vals = [date_in($in, 'date'), $start, $end, str_in($in, 'title', 160, true, 'Title'), str_in($in, 'detail', 255, false)];
    if ($id) q('UPDATE events SET event_date=?, start_time=?, end_time=?, title=?, detail=? WHERE id = ?', [...$vals, $id]);
    else q('INSERT INTO events (event_date,start_time,end_time,title,detail) VALUES (?,?,?,?,?)', $vals);
    return [];
}

function act_event_delete(array $in): array
{
    q('DELETE FROM events WHERE id = ?', [int_in($in, 'id')]);
    return [];
}

/* ---------- Recruitment ---------- */
function act_position_save(array $in): array
{
    $id = int_in($in, 'id', false);
    $vals = [
        str_in($in, 'title', 160, true, 'Role title'),
        one_of(str_in($in, 'department', 60, true, 'Department'), DEPARTMENTS, 'Department'),
        one_of(str_in($in, 'type', 20, true, 'Employment type'), EMP_TYPES, 'Employment type'),
        str_in($in, 'location', 80, false),
    ];
    if ($id) q('UPDATE positions SET title=?, department=?, emp_type=?, location=? WHERE id = ?', [...$vals, $id]);
    else {
        q("INSERT INTO positions (title,department,emp_type,location,status,created_at) VALUES (?,?,?,?,'open',?)", [...$vals, today()]);
        $id = (int) db()->lastInsertId();
    }
    return ['id' => $id];
}

function act_position_status(array $in): array
{
    q('UPDATE positions SET status = ? WHERE id = ?', [one_of((string) ($in['status'] ?? ''), ['open', 'closed'], 'Status'), int_in($in, 'id')]);
    return [];
}

function act_position_delete(array $in): array
{
    q('DELETE FROM positions WHERE id = ?', [int_in($in, 'id')]);
    return [];
}

function act_candidate_save(array $in): array
{
    $pid = int_in($in, 'positionId');
    if (!q('SELECT id FROM positions WHERE id = ?', [$pid])->fetch()) fail('That role no longer exists.', 404);
    $email = str_in($in, 'email', 160, false);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address, or leave it blank.', field: 'email');
    q("INSERT INTO candidates (position_id,name,email,source,stage,applied_at) VALUES (?,?,?,?,'Applied',?)",
        [$pid, str_in($in, 'name', 160, true, 'Name'), $email, str_in($in, 'source', 60, false), today()]);
    return [];
}

function act_candidate_move(array $in): array
{
    $stage = one_of((string) ($in['stage'] ?? ''), [...STAGES, 'Rejected'], 'Stage');
    q('UPDATE candidates SET stage = ? WHERE id = ?', [$stage, int_in($in, 'id')]);
    return [];
}

/* ---------- Payroll ---------- */
function draft_run(int $id): array
{
    $run = q('SELECT * FROM pay_runs WHERE id = ?', [$id])->fetch();
    if (!$run) fail('That pay run no longer exists.', 404);
    if ($run['status'] !== 'draft') fail('Paid runs are locked and cannot be changed.');
    return $run;
}

function act_payroll_bonus(array $in): array
{
    $run = draft_run(int_in($in, 'runId'));
    $emp = employee_exists(int_in($in, 'empId'));
    $amount = (float) ($in['amount'] ?? 0);
    if ($amount < 0 || $amount > 1000000) fail('Bonus must be between 0 and 1,000,000.');
    if ($amount == 0) q('DELETE FROM pay_bonuses WHERE run_id = ? AND emp_id = ?', [$run['id'], $emp['id']]);
    else q('INSERT INTO pay_bonuses (run_id, emp_id, amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)', [$run['id'], $emp['id'], $amount]);
    return [];
}

function act_payroll_pay(array $in): array
{
    $run = draft_run(int_in($in, 'runId'));
    pay_run((int) $run['id']);
    return [];
}

function act_payroll_create(array $in): array
{
    $last = q('SELECT period FROM pay_runs ORDER BY period DESC LIMIT 1')->fetchColumn();
    if ($last && q("SELECT COUNT(*) FROM pay_runs WHERE status = 'draft'")->fetchColumn()) fail('Finish the open draft pay run first.');
    $period = $last ? (new DateTime($last . '-01'))->modify('+1 month')->format('Y-m') : date('Y-m');
    q("INSERT INTO pay_runs (period, status) VALUES (?, 'draft')", [$period]);
    return ['id' => (int) db()->lastInsertId()];
}

/* ---------- Settings & account ---------- */
function act_settings_save(array $in): array
{
    set_setting('company', str_in($in, 'company', 80, true, 'Company name'));
    set_setting('workspace', str_in($in, 'workspace', 80, true, 'Workspace name'));
    set_setting('currency', one_of((string) ($in['currency'] ?? 'PHP'), ['PHP', 'USD', 'EUR', 'GBP', 'SGD', 'AUD', 'CAD', 'JPY', 'INR'], 'Currency'));
    $tax = (float) ($in['taxRate'] ?? 0);
    if ($tax < 0 || $tax > 60) fail('Tax rate must be between 0 and 60%.');
    set_setting('taxRate', $tax);
    $ben = (float) ($in['benefits'] ?? 0);
    if ($ben < 0 || $ben > 100000) fail('Benefits deduction must be 0 or more.');
    set_setting('benefits', $ben);
    $allow = [];
    foreach (LEAVE_TYPES as $t) {
        $v = (int) ($in['allowances'][$t] ?? 0);
        if ($v < 0 || $v > 365) fail("$t allowance must be between 0 and 365 days.");
        $allow[$t] = $v;
    }
    set_setting('allowances', $allow);
    return [];
}

function act_workspace_view(array $in): array
{
    foreach (['selfEmpId', 'supervisorId'] as $k) {
        if (array_key_exists($k, $in)) set_setting($k, int_in($in, $k, false));
    }
    return [];
}

function act_profile_save(array $in, array $user): array
{
    q('UPDATE users SET display_name = ?, role_title = ? WHERE id = ?', [str_in($in, 'name', 120, true, 'Your name'), str_in($in, 'role', 120, true, 'Your role'), $user['id']]);
    $new = (string) ($in['newPassword'] ?? '');
    if ($new !== '') {
        if (strlen($new) < 8) fail('The new password needs at least 8 characters.');
        $hash = q('SELECT password_hash FROM users WHERE id = ?', [$user['id']])->fetchColumn();
        if (!password_verify((string) ($in['currentPassword'] ?? ''), $hash)) fail('Your current password is not correct.');
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
    }
    return [];
}

function act_demo_reset(array $in): array
{
    seed_demo();
    return [];
}
