<?php
declare(strict_types=1);

/* Everything the frontend needs in one payload. Small enough for a team of a few hundred. */
function build_state(array $user): array
{
    $hm = fn(?string $t) => $t === null ? '' : substr($t, 0, 5);

    $employees = array_map(fn($e) => [
        'id' => (int) $e['id'], 'firstName' => $e['first_name'], 'lastName' => $e['last_name'],
        'email' => $e['email'], 'phone' => $e['phone'], 'title' => $e['title'], 'department' => $e['department'],
        'location' => $e['location'], 'type' => $e['emp_type'], 'startDate' => $e['start_date'], 'endDate' => $e['end_date'],
        'salary' => (float) $e['salary'], 'managerId' => $e['manager_id'] === null ? null : (int) $e['manager_id'], 'status' => $e['status'],
    ], q('SELECT * FROM employees ORDER BY first_name, last_name')->fetchAll());

    $leaves = array_map(fn($l) => [
        'id' => (int) $l['id'], 'empId' => (int) $l['emp_id'], 'type' => $l['leave_type'], 'start' => $l['start_date'],
        'end' => $l['end_date'], 'days' => (int) $l['days'], 'status' => $l['status'], 'reason' => $l['reason'], 'createdAt' => $l['created_at'],
    ], q('SELECT * FROM leave_requests ORDER BY start_date DESC, id DESC')->fetchAll());

    $attendance = [];
    $since = (new DateTime('-120 days'))->format('Y-m-d');
    foreach (q('SELECT * FROM attendance WHERE work_date >= ?', [$since])->fetchAll() as $a) {
        $attendance[$a['work_date']][(string) $a['emp_id']] = ['status' => $a['status'], 'in' => $hm($a['time_in']), 'out' => $hm($a['time_out'])];
    }
    $attendance = array_map(fn($day) => (object) $day, $attendance);

    $events = array_map(fn($e) => [
        'id' => (int) $e['id'], 'date' => $e['event_date'], 'start' => $hm($e['start_time']), 'end' => $hm($e['end_time']),
        'title' => $e['title'], 'detail' => $e['detail'],
    ], q('SELECT * FROM events WHERE event_date >= ? ORDER BY event_date, start_time', [(new DateTime('-30 days'))->format('Y-m-d')])->fetchAll());

    $positions = array_map(fn($p) => [
        'id' => (int) $p['id'], 'title' => $p['title'], 'department' => $p['department'], 'type' => $p['emp_type'],
        'location' => $p['location'], 'status' => $p['status'], 'createdAt' => $p['created_at'],
    ], q('SELECT * FROM positions ORDER BY status = "open" DESC, created_at DESC')->fetchAll());

    $candidates = array_map(fn($c) => [
        'id' => (int) $c['id'], 'positionId' => (int) $c['position_id'], 'name' => $c['name'], 'email' => $c['email'],
        'source' => $c['source'], 'stage' => $c['stage'], 'appliedAt' => $c['applied_at'],
        'employeeId' => $c['employee_id'] === null ? null : (int) $c['employee_id'],
    ], q('SELECT * FROM candidates ORDER BY applied_at DESC')->fetchAll());

    $bonuses = [];
    foreach (q('SELECT * FROM pay_bonuses')->fetchAll() as $b) $bonuses[(int) $b['run_id']][(string) $b['emp_id']] = (float) $b['amount'];
    $slips = [];
    foreach (q('SELECT * FROM payslips ORDER BY emp_name')->fetchAll() as $s) {
        $slips[(int) $s['run_id']][] = [
            'empId' => $s['emp_id'] === null ? null : (int) $s['emp_id'], 'name' => $s['emp_name'], 'department' => $s['department'],
            'base' => (float) $s['base'], 'bonus' => (float) $s['bonus'], 'gross' => (float) $s['gross'], 'tax' => (float) $s['tax'],
            'benefits' => (float) $s['benefits'], 'net' => (float) $s['net'],
        ];
    }
    $payRuns = array_map(fn($r) => [
        'id' => (int) $r['id'], 'period' => $r['period'], 'status' => $r['status'], 'paidAt' => $r['paid_at'],
        'bonuses' => (object) ($bonuses[(int) $r['id']] ?? []),
        'lines' => $r['status'] === 'paid' ? ($slips[(int) $r['id']] ?? []) : null,
    ], q('SELECT * FROM pay_runs ORDER BY period')->fetchAll());

    return [
        'user' => $user,
        'today' => today(),
        'settings' => settings(),
        'employees' => $employees,
        'leaves' => $leaves,
        'attendance' => (object) $attendance,
        'events' => $events,
        'positions' => $positions,
        'candidates' => $candidates,
        'payRuns' => $payRuns,
    ];
}
