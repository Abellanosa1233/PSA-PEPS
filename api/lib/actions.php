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
    $salary = filter_var($in['salary'] ?? '', FILTER_VALIDATE_FLOAT);
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
        'salary' => $salary,
        'manager_id' => int_in($in, 'managerId', false),
    ];
    if (array_key_exists('biometricId', $in)) {
        ensure_biometrics();
        $bio = str_in($in, 'biometricId', 30, false, 'Biometric ID');
        if ($bio !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $bio)) fail('Biometric ID uses letters, numbers, dashes or underscores — the user number shown on the biometric device.', 422, 'biometricId');
        $f['biometric_id'] = $bio === '' ? null : normalize_biometric_id($bio);
        if ($f['biometric_id'] !== null && q('SELECT id FROM employees WHERE biometric_id = ? AND id <> ?', [$f['biometric_id'], $id ?? 0])->fetchColumn()) {
            fail('Another employee already has that biometric ID.', 422, 'biometricId');
        }
    }
    if (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address, like name@company.com.', 422, 'email');
    if ($salary === false || $salary < 0.01 || $salary > 10000000) fail('Annual salary must be between 0.01 and 10,000,000.', 422, 'salary');
    $dupe = q('SELECT id FROM employees WHERE email = ? AND id <> ?', [$f['email'], $id ?? 0])->fetchColumn();
    if ($dupe) fail('Another employee already uses that email address.', 422, 'email');
    if ($f['manager_id'] !== null) {
        if ($id !== null && $f['manager_id'] === $id) fail('An employee cannot be their own manager.');
        employee_exists($f['manager_id']);
    }
    if ($id) {
        employee_exists($id);
        $sets = implode(', ', array_map(function ($k) { return "$k = ?"; }, array_keys($f)));
        $updated = q("UPDATE employees SET $sets WHERE id = ?", array_merge(array_values($f), [$id]));
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

function act_employee_import(array $file): array
{
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) fail($code === UPLOAD_ERR_INI_SIZE ? 'The CSV exceeds the server upload limit.' : 'Choose a valid CSV file.', 422, 'employeeCsv');
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) fail('Choose a CSV file smaller than 2 MB.', 422, 'employeeCsv');
    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) fail('The uploaded CSV could not be verified.', 422, 'employeeCsv');
    $handle = fopen($tmp, 'r');
    if (!$handle) fail('The uploaded CSV could not be read.', 422, 'employeeCsv');
    $headers = fgetcsv($handle, 0, ',', '"', '');
    if (!$headers) { fclose($handle); fail('The CSV is empty.', 422, 'employeeCsv'); }
    $aliases = [
        'firstname' => 'firstName', 'lastname' => 'lastName', 'email' => 'email', 'phone' => 'phone',
        'jobtitle' => 'title', 'title' => 'title', 'department' => 'department', 'type' => 'type',
        'employmenttype' => 'type', 'location' => 'location', 'startdate' => 'startDate',
        'annualsalary' => 'salary', 'salary' => 'salary', 'enddate' => 'endDate', 'status' => 'status',
    ];
    $columns = [];
    foreach ($headers as $index => $header) {
        $key = strtolower(preg_replace('/[^a-z0-9]/', '', trim((string) $header, " \t\n\r\0\x0B\xEF\xBB\xBF")));
        if (isset($aliases[$key])) $columns[$aliases[$key]] = $index;
    }
    foreach (['firstName', 'lastName', 'email', 'title', 'department', 'type', 'startDate', 'salary'] as $required) {
        if (!isset($columns[$required])) { fclose($handle); fail('The CSV is missing required column "' . $required . '".', 422, 'employeeCsv'); }
    }
    $row = 1;
    $count = 0;
    db()->beginTransaction();
    try {
        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $row++;
            if ($values === [null] || !array_filter($values, function ($value) { return trim((string) $value) !== ''; })) continue;
            if (count($values) > count($headers)) fail("CSV row $row has more values than the header.", 422, 'employeeCsv');
            if ($count >= 500) fail('A CSV import can contain at most 500 employees.', 422, 'employeeCsv');
            $value = function ($key) use ($columns, $values) { return isset($columns[$key]) ? trim((string) ($values[$columns[$key]] ?? '')) : ''; };
            $result = act_employee_save([
                'firstName' => $value('firstName'), 'lastName' => $value('lastName'), 'email' => $value('email'),
                'phone' => $value('phone'), 'title' => $value('title'), 'department' => $value('department'),
                'type' => $value('type'), 'location' => $value('location'), 'startDate' => $value('startDate'), 'salary' => $value('salary'),
            ]);
            $status = $value('status') ?: 'Active';
            if ($status !== 'Active' || $value('endDate') !== '') act_employee_status(['id' => $result['id'], 'status' => $status, 'endDate' => $value('endDate')]);
            $count++;
        }
        if (!$count) fail('The CSV does not contain any employee rows.', 422, 'employeeCsv');
        db()->commit();
    } catch (ApiError $e) {
        if (db()->inTransaction()) db()->rollBack();
        fclose($handle);
        fail("CSV row $row: " . $e->getMessage(), $e->status, $e->field);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        fclose($handle);
        throw $e;
    }
    fclose($handle);
    return ['imported' => $count];
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
/* Working days a request will use: weekdays in the range, or ½ for a half day. */
function leave_request_days(string $start, string $end, string $dayLength): float
{
    $days = workdays($start, $end);
    if ($dayLength === 'full') return (float) $days;
    if ($start !== $end) fail('A half day must start and end on the same date.', 422, 'dayLength');
    if ($days === 0) fail('That date is a weekend. Pick a weekday.', 422, 'start');
    return 0.5;
}

/* $attachment comes only from leave_attachment_upload() (a real uploaded file), never from form fields. */
function act_leave_create(array $in, array $user = [], ?array $attachment = null): array
{
    ensure_leave_extras();
    $emp = employee_exists(int_in($in, 'empId'));
    if ($emp['status'] !== 'Active') fail('Time off can only be requested for active employees.');
    $type = one_of((string) ($in['type'] ?? ''), LEAVE_TYPES, 'Leave type');
    $start = date_in($in, 'start', true, 'First day');
    $end = date_in($in, 'end', true, 'Last day');
    if ($end < $start) fail('The last day must be on or after the first day.', 422, 'end');
    $dayLength = one_of((string) ($in['dayLength'] ?? 'full'), ['full', 'am', 'pm'], 'Day length');
    $days = leave_request_days($start, $end, $dayLength);
    if ($days == 0) fail('Those dates fall on a weekend. Pick at least one weekday.');
    $overlap = q("SELECT COUNT(*) FROM leave_requests WHERE emp_id = ? AND status IN ('pending','approved') AND start_date <= ? AND end_date >= ?", [$emp['id'], $end, $start])->fetchColumn();
    if ($overlap) fail('These dates overlap another request for ' . $emp['first_name'] . '.');
    $s = settings();
    $allow = (float) ($s['allowances'][$type] ?? 0);
    $year = substr($start, 0, 4);
    $taken = (float) q("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE emp_id = ? AND leave_type = ? AND status IN ('pending','approved') AND YEAR(start_date) = ?", [$emp['id'], $type, $year])->fetchColumn();
    if ($taken + $days > $allow) fail($emp['first_name'] . ' has ' . days_label(max(0, $allow - $taken)) . " of $type left this year (counting pending requests); this request needs " . days_label($days) . '.');
    $status = !empty($in['approveNow']) ? 'approved' : 'pending';
    q('INSERT INTO leave_requests (emp_id,leave_type,start_date,end_date,days,status,reason,created_at,decided_at,day_length,attachment,attachment_name,attachment_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$emp['id'], $type, $start, $end, $days, $status, str_in($in, 'reason', 255, false), today(), $status === 'approved' ? date('Y-m-d H:i:s') : null,
         $dayLength, $attachment['bytes'] ?? null, $attachment['name'] ?? null, $attachment['type'] ?? null]);
    return ['id' => (int) db()->lastInsertId()];
}

/* A leave request's supporting document, if this user may open it: HR, or the employee who
   sent it. Nobody else, including supervisors. Returns null when not found or not allowed. */
function leave_attachment_for(array $user, int $leaveId): ?array
{
    ensure_leave_extras();
    $file = q('SELECT emp_id, attachment, attachment_name, attachment_type FROM leave_requests WHERE id = ? AND attachment IS NOT NULL', [$leaveId])->fetch();
    if (!$file) return null;
    if (!is_admin($user) && (int) $file['emp_id'] !== (int) ($user['employeeId'] ?? 0)) return null;
    return $file;
}

/* Validates an uploaded supporting document by its contents (not its name): PDF, JPG or PNG, up to 5 MB. */
function leave_attachment_upload(array $file, string $field = 'attachment'): ?array
{
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code === UPLOAD_ERR_NO_FILE) return null;
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) fail('That document is too large. Attach a file up to 5 MB.', 422, $field);
    if ($code !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) fail('The document could not be uploaded. Try again.', 422, $field);
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) fail('That document is too large. Attach a file up to 5 MB.', 422, $field);
    $bytes = (string) file_get_contents($file['tmp_name']);
    $type = strncmp($bytes, '%PDF', 4) === 0 ? 'application/pdf'
        : (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0 ? 'image/jpeg'
        : (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0 ? 'image/png' : null));
    if ($type === null) fail('Attach a PDF, JPG or PNG file (for example a medical certificate).', 422, $field);
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename((string) ($file['name'] ?? 'document')));
    return ['bytes' => $bytes, 'type' => $type, 'name' => mb_substr($name !== '' ? $name : 'document', 0, 160)];
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

function act_employee_request_create(array $in): array
{
    ensure_employee_requests_table();
    $employee = employee_exists(int_in($in, 'empId'));
    if ($employee['status'] !== 'Active') fail('Requests can only be submitted for active employees.');
    $type = one_of((string) ($in['type'] ?? ''), ['special_order', 'travel_order'], 'Request type');
    $purpose = str_in($in, 'purpose', 160, true, $type === 'special_order' ? 'Order subject' : 'Travel purpose');
    $start = date_in($in, 'start', true, $type === 'special_order' ? 'Effective date' : 'Departure date');
    $end = null;
    $destination = '';
    if ($type === 'travel_order') {
        $destination = str_in($in, 'destination', 160, true, 'Destination');
        $end = date_in($in, 'end', true, 'Return date');
        if ($end < $start) fail('Return date must be on or after departure date.', 422, 'end');
    }
    q('INSERT INTO employee_requests (emp_id, request_type, purpose, destination, start_date, end_date, details) VALUES (?,?,?,?,?,?,?)',
        [$employee['id'], $type, $purpose, $destination, $start, $end, str_in($in, 'details', 1000, false)]);
    return ['id' => (int) db()->lastInsertId()];
}

function act_employee_request_decide(array $in, array $user): array
{
    ensure_employee_requests_table();
    $id = int_in($in, 'id');
    $status = one_of((string) ($in['status'] ?? ''), ['approved', 'rejected'], 'Decision');
    $request = q('SELECT emp_id, status FROM employee_requests WHERE id = ?', [$id])->fetch();
    if (!$request) fail('That request no longer exists.', 404);
    if ($request['status'] !== 'pending') fail('This request was already ' . $request['status'] . '.');
    $reviewerId = int_in($in, 'reviewerId', false);
    $reviewerRole = $reviewerId === null ? 'hr_admin' : 'supervisor';
    if ($reviewerId !== null) {
        $employee = employee_exists((int) $request['emp_id']);
        if ((int) $employee['manager_id'] !== $reviewerId) fail('Only the employee’s direct supervisor can decide this request.', 403);
    }
    $updated = q('UPDATE employee_requests SET status=?, decision_role=?, decided_by_user_id=?, decided_by_employee_id=?, decided_at=NOW() WHERE id=? AND status="pending"',
        [$status, $reviewerRole, $reviewerId === null ? $user['id'] : null, $reviewerId, $id]);
    if (!$updated->rowCount()) fail('This request was already decided.', 409);
    return [];
}

function act_employee_request_cancel(array $in): array
{
    ensure_employee_requests_table();
    $id = int_in($in, 'id');
    $employeeId = int_in($in, 'empId');
    $request = q('SELECT status FROM employee_requests WHERE id=? AND emp_id=?', [$id, $employeeId])->fetch();
    if (!$request) fail('That request no longer exists.', 404);
    if ($request['status'] !== 'pending') fail('Only pending requests can be canceled.');
    q('DELETE FROM employee_requests WHERE id=?', [$id]);
    return [];
}

/* ---------- Attendance ---------- */
function act_attendance_set(array $in): array
{
    $date = date_in($in, 'date');
    if ($date < today()) fail('Past attendance records are read-only.', 422, 'date');
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
    if ($date < today()) fail('Past attendance records are read-only.', 422, 'date');
    $n = q("INSERT IGNORE INTO attendance (work_date, emp_id, status, time_in, time_out)
            SELECT ?, e.id, 'office', '09:00', NULL FROM employees e
            WHERE e.status = 'Active' AND e.start_date <= ?
              AND NOT EXISTS (SELECT 1 FROM leave_requests l WHERE l.emp_id = e.id AND l.status = 'approved' AND l.start_date <= ? AND l.end_date >= ?)",
        [$date, $date, $date, $date])->rowCount();
    return ['count' => $n];
}

function act_attendance_import(array $file): array
{
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) fail($code === UPLOAD_ERR_INI_SIZE ? 'The CSV exceeds the server upload limit.' : 'Choose a valid CSV file.', 422, 'attendanceCsv');
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) fail('Choose a CSV file smaller than 2 MB.', 422, 'attendanceCsv');
    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) fail('The uploaded CSV could not be verified.', 422, 'attendanceCsv');
    $handle = fopen($tmp, 'r');
    if (!$handle) fail('The uploaded CSV could not be read.', 422, 'attendanceCsv');
    $headers = fgetcsv($handle, 0, ',', '"', '');
    if (!$headers) { fclose($handle); fail('The CSV is empty.', 422, 'attendanceCsv'); }
    $aliases = ['date'=>'date', 'workdate'=>'date', 'employee'=>'name', 'employeename'=>'name', 'email'=>'email', 'employeeemail'=>'email',
        'department'=>'department', 'status'=>'status', 'clockin'=>'in', 'timein'=>'in', 'clockout'=>'out', 'timeout'=>'out'];
    $columns = [];
    foreach ($headers as $index => $header) {
        $key = strtolower(preg_replace('/[^a-z0-9]/', '', trim((string) $header, " \t\n\r\0\x0B\xEF\xBB\xBF")));
        if (isset($aliases[$key])) $columns[$aliases[$key]] = $index;
    }
    foreach (['date', 'status', 'in', 'out'] as $required) {
        if (!isset($columns[$required])) { fclose($handle); fail('The CSV is missing required column "' . $required . '".', 422, 'attendanceCsv'); }
    }
    if (!isset($columns['email']) && !isset($columns['name'])) { fclose($handle); fail('Include employee email or employee name.', 422, 'attendanceCsv'); }
    $employees = q('SELECT id, first_name, last_name, email, department FROM employees')->fetchAll();
    $byEmail = [];
    foreach ($employees as $employee) $byEmail[strtolower($employee['email'])] = $employee;
    $row = 1;
    $count = 0;
    $seen = [];
    db()->beginTransaction();
    try {
        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $row++;
            if ($values === [null] || !array_filter($values, function ($value) { return trim((string) $value) !== ''; })) continue;
            if (count($values) > count($headers)) fail("CSV row $row has more values than the header.", 422, 'attendanceCsv');
            if ($count >= 5000) fail('A CSV import can contain at most 5,000 attendance records.', 422, 'attendanceCsv');
            $value = function ($key) use ($columns, $values) { return isset($columns[$key]) ? trim((string) ($values[$columns[$key]] ?? '')) : ''; };
            $statusText = strtolower($value('status'));
            if ($statusText === '' || $statusText === 'leave' || $statusText === 'on leave') continue;
            $date = date_in(['date' => $value('date')], 'date');
            if ($value('email') !== '') {
                $employee = $byEmail[strtolower($value('email'))] ?? null;
                if (!$employee) fail('No employee found for email ' . $value('email') . '.', 422, 'attendanceCsv');
            } else {
                $name = preg_replace('/\s+/', ' ', trim($value('name')));
                $matches = array_values(array_filter($employees, function ($candidate) use ($name, $value) {
                    return strcasecmp($candidate['first_name'] . ' ' . $candidate['last_name'], $name) === 0
                        && ($value('department') === '' || strcasecmp($candidate['department'], $value('department')) === 0);
                }));
                if (count($matches) !== 1) fail(count($matches) ? 'More than one employee matches this name; use Employee email.' : 'No employee found for "' . $name . '".', 422, 'attendanceCsv');
                $employee = $matches[0];
            }
            $statuses = ['office' => 'office', 'in office' => 'office', 'remote' => 'remote', 'away' => 'away'];
            if (!isset($statuses[$statusText])) fail('Status must be office, remote, or away.', 422, 'attendanceCsv');
            $key = $date . ':' . $employee['id'];
            if (isset($seen[$key])) fail('The same employee and date appear more than once.', 422, 'attendanceCsv');
            $seen[$key] = true;
            act_attendance_set(['date' => $date, 'empId' => $employee['id'], 'status' => $statuses[$statusText], 'in' => $value('in'), 'out' => $value('out')]);
            $count++;
        }
        if (!$count) fail('The CSV does not contain any attendance records.', 422, 'attendanceCsv');
        db()->commit();
    } catch (ApiError $e) {
        if (db()->inTransaction()) db()->rollBack();
        fclose($handle);
        fail("CSV row $row: " . $e->getMessage(), $e->status, $e->field);
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        fclose($handle);
        throw $e;
    }
    fclose($handle);
    return ['imported' => $count];
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
    // Web clock-ins also count as punches, so they appear on the DTR next to biometric ones.
    ensure_biometrics();
    q("INSERT IGNORE INTO attendance_punches (emp_id, punched_at, source) VALUES (?, ?, 'web')", [$emp['id'], $day . ' ' . $now . ':00']);
    return [];
}

/* ---------- Schedule ---------- */
function act_event_save(array $in): array
{
    $id = int_in($in, 'id', false);
    $start = time_in($in, 'start') ?? fail('Start time is required.', 422, 'start');
    $end = time_in($in, 'end') ?? fail('End time is required.', 422, 'end');
    if ($end <= $start) fail('The event must end after it starts.', 422, 'end');
    ensure_announcements(); // adds events.for_employees on older databases
    $vals = [date_in($in, 'date'), $start, $end, str_in($in, 'title', 160, true, 'Title'), str_in($in, 'detail', 255, false), empty($in['forEmployees']) ? 0 : 1];
    if ($id) q('UPDATE events SET event_date=?, start_time=?, end_time=?, title=?, detail=?, for_employees=? WHERE id = ?', array_merge($vals, [$id]));
    else q('INSERT INTO events (event_date,start_time,end_time,title,detail,for_employees) VALUES (?,?,?,?,?,?)', $vals);
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
    if ($id) q('UPDATE positions SET title=?, department=?, emp_type=?, location=? WHERE id = ?', array_merge($vals, [$id]));
    else {
        q("INSERT INTO positions (title,department,emp_type,location,status,created_at) VALUES (?,?,?,?,'open',?)", array_merge($vals, [today()]));
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
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email address, or leave it blank.', 422, 'email');
    q("INSERT INTO candidates (position_id,name,email,source,stage,applied_at) VALUES (?,?,?,?,'Applied',?)",
        [$pid, str_in($in, 'name', 160, true, 'Name'), $email, str_in($in, 'source', 60, false), today()]);
    return [];
}

function act_candidate_move(array $in): array
{
    $stage = one_of((string) ($in['stage'] ?? ''), array_merge(STAGES, ['Rejected']), 'Stage');
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
    if (array_key_exists('workStart', $in) || array_key_exists('workEnd', $in)) {
        $start = time_in($in, 'workStart') ?? fail('Enter when office hours start.', 422, 'workStart');
        $end = time_in($in, 'workEnd') ?? fail('Enter when office hours end.', 422, 'workEnd');
        if ($end <= $start) fail('Office hours must end after they start.', 422, 'workEnd');
        set_setting('workStart', $start);
        set_setting('workEnd', $end);
    }
    return [];
}

/* ---------- Company updates (HR only; see authorize_action) ---------- */
function act_announcement_save(array $in, array $user): array
{
    ensure_announcements();
    q('INSERT INTO announcements (title, body, created_by) VALUES (?, ?, ?)', [
        str_in($in, 'title', 120, true, 'Headline'), str_in($in, 'body', 500, false, 'Message'), $user['id'] ?: null,
    ]);
    return ['id' => (int) db()->lastInsertId()];
}

function act_announcement_delete(array $in): array
{
    ensure_announcements();
    q('DELETE FROM announcements WHERE id = ?', [int_in($in, 'id')]);
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
    $name = str_in($in, 'name', 120, true, 'Display name');
    $role = str_in($in, 'role', 120, true, 'Role title');
    if (($in['newPassword'] ?? '') !== '') {
        [$oldHash, $newHash] = account_password_hashes($in, $user['id']);
        $updated = q('UPDATE users SET display_name = ?, role_title = ?, password_hash = ? WHERE id = ? AND password_hash = ?',
            [$name, $role, $newHash, $user['id'], $oldHash]);
        if (!$updated->rowCount()) fail('Your password changed. Enter your current password and try again.', 422, 'currentPassword');
    } else {
        q('UPDATE users SET display_name = ?, role_title = ? WHERE id = ?', [$name, $role, $user['id']]);
    }
    return [];
}

function act_profile_photo(array $file, array $user): array
{
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) fail($code === UPLOAD_ERR_INI_SIZE ? 'The image exceeds the server upload limit.' : 'Choose a profile image first.', 422, 'profileImage');
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) fail('Choose an image smaller than 2 MB.', 422, 'profileImage');
    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) fail('The uploaded image could not be verified.', 422, 'profileImage');
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) fail('The server could not verify the image type.', 500);
    $mime = finfo_file($finfo, $tmp);
    finfo_close($finfo);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $image = @getimagesize($tmp);
    if (!in_array($mime, $allowed, true) || !$image || ($image['mime'] ?? '') !== $mime) fail('Use a JPG, PNG, or WebP image.', 422, 'profileImage');
    if ($image[0] > 2048 || $image[1] > 2048) fail('Image dimensions must be 2048 × 2048 pixels or smaller.', 422, 'profileImage');
    $contents = file_get_contents($tmp);
    if ($contents === false) fail('The uploaded image could not be read.', 500);
    q('UPDATE users SET profile_image=?, profile_image_type=?, profile_image_updated_at=NOW() WHERE id=?', [$contents, $mime, $user['id']]);
    return [];
}

function act_profile_photo_remove(array $user): array
{
    q('UPDATE users SET profile_image=NULL, profile_image_type=NULL, profile_image_updated_at=NULL WHERE id=?', [$user['id']]);
    return [];
}

function act_password_change(array $in, array $user): array
{
    [$oldHash, $newHash] = account_password_hashes($in, $user['id'], true);
    $updated = q('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ? AND password_hash = ?', [$newHash, $user['id'], $oldHash]);
    if (!$updated->rowCount()) fail('Your password changed. Enter your current password and try again.', 422, 'currentPassword');
    return [];
}

/* ---------- Employee sign-in accounts (HR administrators only; see authorize_action) ---------- */
function temporary_password_in(array $in): string
{
    $password = (string) ($in['password'] ?? '');
    if (strlen($password) < 8) fail('Use a temporary password with at least 8 characters.', 422, 'password');
    if (strlen($password) > 72 || strpos($password, "\0") !== false) fail('Use a password of up to 72 bytes without null characters.', 422, 'password');
    return $password;
}

function act_account_create(array $in): array
{
    ensure_account_columns();
    $employee = employee_exists(int_in($in, 'empId'));
    if ($employee['status'] !== 'Active') fail('Only active employees can be given sign-in access.');
    if (q("SELECT id FROM users WHERE employee_id = ?", [$employee['id']])->fetch()) fail('This employee already has sign-in access.');
    $username = strtolower(str_in($in, 'username', 60, true, 'Username'));
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) fail('Usernames use 3–60 letters, numbers, dots, dashes or underscores.', 422, 'username');
    if (q('SELECT id FROM users WHERE username = ?', [$username])->fetch()) fail('That username is already taken. Choose another.', 422, 'username');
    q("INSERT INTO users (username, display_name, role_title, password_hash, account_role, employee_id, must_change_password) VALUES (?,?,?,?,'employee',?,1)", [
        $username, $employee['first_name'] . ' ' . $employee['last_name'], $employee['title'],
        password_hash(temporary_password_in($in), PASSWORD_DEFAULT), $employee['id'],
    ]);
    return ['id' => (int) $employee['id']];
}

function act_account_password(array $in): array
{
    ensure_account_columns();
    $employeeId = int_in($in, 'empId');
    $updated = q("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE employee_id = ? AND account_role = 'employee'",
        [password_hash(temporary_password_in($in), PASSWORD_DEFAULT), $employeeId]);
    if (!$updated->rowCount()) fail('This employee does not have sign-in access.', 404);
    return ['id' => $employeeId];
}

function act_account_remove(array $in): array
{
    ensure_account_columns();
    $employeeId = int_in($in, 'empId');
    q("DELETE FROM users WHERE employee_id = ? AND account_role = 'employee'", [$employeeId]);
    return ['id' => $employeeId];
}

function account_password_hashes(array $in, int $userId, bool $requireConfirmation = false): array
{
    $current = $in['currentPassword'] ?? '';
    $new = $in['newPassword'] ?? '';
    if (!is_string($current) || $current === '') fail('Enter your current password.', 422, 'currentPassword');
    if (!is_string($new) || mb_strlen($new) < 8) fail('Use at least 8 characters for the new password.', 422, 'newPassword');
    if (strlen($new) > 72 || strpos($new, "\0") !== false) fail('Use a password of up to 72 bytes without null characters.', 422, 'newPassword');
    if ($requireConfirmation && ($in['confirmPassword'] ?? '') !== $new) {
        fail('The new passwords must match.', 422, 'confirmPassword');
    }
    $oldHash = q('SELECT password_hash FROM users WHERE id = ?', [$userId])->fetchColumn();
    if (!$oldHash || !password_verify($current, $oldHash)) fail('Your current password is not correct.', 422, 'currentPassword');
    return [$oldHash, password_hash($new, PASSWORD_DEFAULT)];
}

function act_demo_reset(array $in): array
{
    seed_demo();
    return [];
}

require_once __DIR__ . '/dtr.php';
require_once __DIR__ . '/profile.php';
