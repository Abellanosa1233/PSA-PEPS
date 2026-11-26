<?php
declare(strict_types=1);

/* Everything the frontend needs in one payload. Small enough for a team of a few hundred. */
function build_state(array $user): array
{
    $hm = function (?string $t) { return $t === null ? '' : substr($t, 0, 5); };

    $employees = array_map(function ($e) {
        return [
            'id' => (int) $e['id'], 'firstName' => $e['first_name'], 'lastName' => $e['last_name'],
            'email' => $e['email'], 'phone' => $e['phone'], 'title' => $e['title'], 'department' => $e['department'],
            'location' => $e['location'], 'type' => $e['emp_type'], 'startDate' => $e['start_date'], 'endDate' => $e['end_date'],
            'salary' => (float) $e['salary'], 'managerId' => $e['manager_id'] === null ? null : (int) $e['manager_id'], 'status' => $e['status'],
            'biometricId' => $e['biometric_id'] ?? null,
            // Personal and emergency details: blanked for everyone except the employee and HR (see scope_state_to_employee)
            'preferredName' => $e['preferred_name'] ?? null, 'personalEmail' => $e['personal_email'] ?? null, 'homeAddress' => $e['home_address'] ?? null,
            'emergencyName' => $e['emergency_name'] ?? null, 'emergencyRelationship' => $e['emergency_relationship'] ?? null, 'emergencyPhone' => $e['emergency_phone'] ?? null,
        ];
    }, q('SELECT * FROM employees ORDER BY first_name, last_name')->fetchAll());

    ensure_leave_extras();
    $leaves = array_map(function ($l) {
        $days = (float) $l['days'];
        return [
            'id' => (int) $l['id'], 'empId' => (int) $l['emp_id'], 'type' => $l['leave_type'], 'start' => $l['start_date'],
            'end' => $l['end_date'], 'days' => $days == floor($days) ? (int) $days : $days, 'status' => $l['status'], 'reason' => $l['reason'], 'createdAt' => $l['created_at'],
            'dayLength' => $l['day_length'], 'hasAttachment' => (bool) $l['has_attachment'], 'decidedAt' => $l['decided_at'],
        ];
    }, q('SELECT id, emp_id, leave_type, start_date, end_date, days, status, reason, created_at, decided_at, day_length, attachment IS NOT NULL AS has_attachment
          FROM leave_requests ORDER BY start_date DESC, id DESC')->fetchAll()); // the document itself is never loaded here

    ensure_special_orders();
    $employeeRequests = array_map(function ($r) {
        $decidedBy = $r['reviewer_user_name'] ?: trim(($r['reviewer_first_name'] ?? '') . ' ' . ($r['reviewer_last_name'] ?? ''));
        return [
            'id' => (int) $r['id'], 'empId' => (int) $r['emp_id'], 'type' => $r['request_type'],
            'purpose' => $r['purpose'], 'destination' => $r['destination'], 'start' => $r['start_date'],
            'end' => $r['end_date'], 'details' => $r['details'], 'status' => $r['status'],
            'decisionRole' => $r['decision_role'], 'decidedBy' => $decidedBy, 'decidedAt' => $r['decided_at'],
            'createdAt' => $r['created_at'],
            // Special Order document (see special-orders.php); the signed file itself is never loaded here
            'soNumber' => $r['so_number'], 'soTitle' => $r['so_title'], 'soOffice' => $r['so_office'], 'soBody' => $r['so_body'], 'soMeta' => special_order_meta($r['so_meta']),
            'signatoryName' => $r['signatory_name'], 'signatoryTitle' => $r['signatory_title'],
            'generatedAt' => $r['generated_at'], 'signedAt' => $r['signed_at'], 'hasSignedFile' => (bool) $r['has_signed_file'],
            'emailedAt' => $r['emailed_at'], 'emailError' => $r['email_error'],
            'groupId' => $r['so_group_id'] === null ? null : (int) $r['so_group_id'],
            'accommodation' => $r['so_accommodation'] ?: 'LIVE-OUT', 'travelTime' => $r['so_travel_time'] ?: 'INCLUSIVE',
        ];
    }, q('SELECT r.id, r.emp_id, r.request_type, r.purpose, r.destination, r.start_date, r.end_date, r.details, r.status,
                 r.decision_role, r.decided_at, r.created_at, r.so_number, r.so_title, r.so_office, r.so_body, r.so_meta, r.signatory_name, r.signatory_title,
                 r.generated_at, r.signed_at, r.signed_file IS NOT NULL AS has_signed_file, r.emailed_at, r.email_error,
                 r.so_group_id, r.so_accommodation, r.so_travel_time,
                 u.display_name AS reviewer_user_name, e.first_name AS reviewer_first_name, e.last_name AS reviewer_last_name
          FROM employee_requests r LEFT JOIN users u ON u.id=r.decided_by_user_id
          LEFT JOIN employees e ON e.id=r.decided_by_employee_id ORDER BY r.created_at DESC, r.id DESC')->fetchAll());
    $employeeRequests = special_order_share_documents($employeeRequests);

    $attendance = [];
    $since = (new DateTime('-120 days'))->format('Y-m-d');
    foreach (q('SELECT * FROM attendance WHERE work_date >= ?', [$since])->fetchAll() as $a) {
        $attendance[$a['work_date']][(string) $a['emp_id']] = ['status' => $a['status'], 'in' => $hm($a['time_in']), 'out' => $hm($a['time_out'])];
    }
    $attendance = array_map(function ($day) { return (object) $day; }, $attendance);

    $events = array_map(function ($e) use ($hm) {
        return [
            'id' => (int) $e['id'], 'date' => $e['event_date'], 'start' => $hm($e['start_time']), 'end' => $hm($e['end_time']),
            'title' => $e['title'], 'detail' => $e['detail'], 'forEmployees' => !empty($e['for_employees']),
        ];
    }, q('SELECT * FROM events ORDER BY event_date, start_time')->fetchAll());

    $positions = array_map(function ($p) {
        return [
            'id' => (int) $p['id'], 'title' => $p['title'], 'department' => $p['department'], 'type' => $p['emp_type'],
            'location' => $p['location'], 'status' => $p['status'], 'createdAt' => $p['created_at'],
        ];
    }, q('SELECT * FROM positions ORDER BY status = "open" DESC, created_at DESC')->fetchAll());

    $candidates = array_map(function ($c) {
        return [
            'id' => (int) $c['id'], 'positionId' => (int) $c['position_id'], 'name' => $c['name'], 'email' => $c['email'],
            'source' => $c['source'], 'stage' => $c['stage'], 'appliedAt' => $c['applied_at'],
            'employeeId' => $c['employee_id'] === null ? null : (int) $c['employee_id'],
        ];
    }, q('SELECT * FROM candidates ORDER BY applied_at DESC')->fetchAll());

    $state = [
        'user' => $user,
        'today' => today(),
        'settings' => settings(),
        'employees' => $employees,
        'leaves' => $leaves,
        'employeeRequests' => $employeeRequests,
        'attendance' => (object) $attendance,
        'events' => $events,
        'positions' => $positions,
        'candidates' => $candidates,
    ];
    ensure_announcements();
    $state['announcements'] = array_map(function ($a) {
        return ['id' => (int) $a['id'], 'title' => $a['title'], 'body' => $a['body'], 'createdAt' => $a['created_at']];
    }, q('SELECT id, title, body, created_at FROM announcements ORDER BY created_at DESC, id DESC LIMIT 20')->fetchAll());
    // Attendance correction requests (pending, plus decisions from the last 60 days)
    ensure_attendance_corrections();
    $hmOrNull = function ($t) { return $t === null ? null : substr($t, 0, 5); };
    $state['corrections'] = array_map(function ($c) use ($hmOrNull) {
        return ['id' => (int) $c['id'], 'empId' => (int) $c['emp_id'], 'date' => $c['work_date'], 'in' => $hmOrNull($c['time_in']), 'out' => $hmOrNull($c['time_out']),
            'reason' => $c['reason'], 'status' => $c['status'], 'createdAt' => $c['created_at'], 'decidedAt' => $c['decided_at']];
    }, q("SELECT * FROM attendance_corrections WHERE status = 'pending' OR decided_at >= NOW() - INTERVAL 60 DAY ORDER BY created_at DESC, id DESC")->fetchAll());
    $state['documents'] = documents_state($user); // already limited to what this account may see
    if (!is_admin($user)) return scope_state_to_employee($state, (int) $user['employeeId']);

    // HR sees which employees can sign in (usernames only, never passwords).
    ensure_account_columns();
    $accounts = [];
    foreach (q("SELECT employee_id, username, must_change_password FROM users WHERE account_role = 'employee' AND employee_id IS NOT NULL")->fetchAll() as $a) {
        $accounts[(string) $a['employee_id']] = ['username' => $a['username'], 'mustChangePassword' => (bool) $a['must_change_password']];
    }
    $state['accounts'] = (object) $accounts;
    return $state;
}

/* What an employee account may see: their own records and, if they supervise people,
   their direct reports' attendance and Special/Travel Orders. Never anyone else's leave
   request (planned time off is private between the employee and HR), salaries except
   their own, recruitment, schedule or private settings.
   Once HR approves leave or an order, the person's team sees only that they are out and
   when ("teamAbsences"): no leave type, reason, purpose or destination. */
function scope_state_to_employee(array $state, int $me): array
{
    $byId = array_column($state['employees'], null, 'id');
    $self = $byId[$me] ?? null;
    $myManager = $self['managerId'] ?? null;
    $teamIds = [];   // direct reports
    $peerIds = [];   // colleagues who share my supervisor
    foreach ($state['employees'] as $e) {
        if ($e['status'] !== 'Active' || $e['id'] === $me) continue;
        if ($e['managerId'] === $me) $teamIds[$e['id']] = true;
        if ($myManager !== null && $e['managerId'] === $myManager) $peerIds[$e['id']] = true;
    }
    $peopleIds = [$me => true] + $teamIds;
    $aroundMe = $teamIds + $peerIds + ($myManager !== null ? [$myManager => true] : []);
    $visible = $peopleIds + $aroundMe;

    $employees = [];
    foreach ($state['employees'] as $e) {
        if (!isset($visible[$e['id']])) continue;
        if ($e['id'] !== $me) {
            $e['salary'] = null;
            $e['phone'] = '';
            foreach (['preferredName', 'personalEmail', 'homeAddress', 'emergencyName', 'emergencyRelationship', 'emergencyPhone'] as $private) $e[$private] = null;
        }
        $employees[] = $e;
    }
    // Approved absences of the people around me, reduced to who / what kind / when.
    $teamAbsences = [];
    foreach ($state['leaves'] as $l) {
        if ($l['status'] === 'approved' && isset($aroundMe[$l['empId']]) && $l['end'] >= $state['today']) {
            $teamAbsences[] = ['empId' => $l['empId'], 'kind' => 'leave', 'start' => $l['start'], 'end' => $l['end']];
        }
    }
    foreach ($state['employeeRequests'] as $r) {
        $end = $r['end'] ?: $r['start'];
        if ($r['status'] === 'approved' && isset($aroundMe[$r['empId']]) && $end >= $state['today']) {
            $teamAbsences[] = ['empId' => $r['empId'], 'kind' => $r['type'], 'start' => $r['start'], 'end' => $end];
        }
    }
    usort($teamAbsences, function ($a, $b) { return [$a['start'], $a['empId']] <=> [$b['start'], $b['empId']]; });
    $mine = function (array $rows) use ($peopleIds) {
        return array_values(array_filter($rows, function ($row) use ($peopleIds) { return isset($peopleIds[$row['empId']]); }));
    };
    $attendance = [];
    foreach ((array) $state['attendance'] as $day => $records) {
        $kept = array_intersect_key((array) $records, array_flip(array_map('strval', array_keys($peopleIds))));
        if ($kept) $attendance[$day] = (object) $kept;
    }
    $settings = array_intersect_key($state['settings'], array_flip(['company', 'workspace', 'currency', 'allowances', 'workStart', 'workEnd']));
    // Only calendar events HR marked for employees, today and the next two weeks.
    $soon = (new DateTimeImmutable($state['today']))->modify('+14 days')->format('Y-m-d');
    $events = array_values(array_filter($state['events'], function ($e) use ($state, $soon) {
        return $e['forEmployees'] && $e['date'] >= $state['today'] && $e['date'] <= $soon;
    }));
    return [
        'user' => $state['user'],
        'today' => $state['today'],
        'settings' => $settings + ['selfEmpId' => $me, 'supervisorId' => $me],
        'employees' => $employees,
        'leaves' => array_values(array_filter($state['leaves'], function ($l) use ($me) { return $l['empId'] === $me; })),
        'employeeRequests' => $mine($state['employeeRequests']),
        'teamAbsences' => $teamAbsences,
        'corrections' => array_values(array_filter($state['corrections'] ?? [], function ($c) use ($me) { return $c['empId'] === $me; })),
        'documents' => $state['documents'] ?? [],
        'attendance' => (object) $attendance,
        'events' => $events,
        'positions' => [],
        'candidates' => [],
        'announcements' => $state['announcements'] ?? [],
        'accounts' => (object) [],
    ];
}
