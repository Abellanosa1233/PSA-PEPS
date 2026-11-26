<?php
declare(strict_types=1);

/* Sections of an employee's own workspace: used by the employee pages (Overview, My time off,
   My attendance, My payslips, My profile) and by HR's "Employee workspace" view. */

function self_leave_status(array $leave, string $today): array
{
    if ($leave['status'] === 'approved') return $leave['end'] < $today ? ['Taken', 'plain'] : ['Approved', 'ok'];
    if ($leave['status'] === 'rejected') return ['Declined', 'bad'];
    return ['Pending', 'warn'];
}

function self_date_range(string $start, string $end): string
{
    $a = new DateTimeImmutable($start);
    $b = new DateTimeImmutable($end);
    if ($start === $end) return $a->format('M j, Y');
    if ($a->format('Y-m') === $b->format('Y-m')) return $a->format('M j') . ' – ' . $b->format('j, Y');
    return $a->format('M j') . ' – ' . $b->format('M j, Y');
}

/* Remaining = entitlement − taken − approved upcoming leave; pending is not deducted until approved. */
function self_leave_cards(array $state, int $id): void
{
    $year = substr($state['today'], 0, 4);
    $approved = [];
    foreach ($state['leaves'] as $l) {
        if ($l['empId'] === $id && $l['status'] === 'approved' && strpos($l['start'], $year) === 0) $approved[$l['type']] = ($approved[$l['type']] ?? 0) + $l['days'];
    }
    echo '<div class="grid self-leave-cards">';
    foreach (['Annual leave', 'Sick leave', 'Personal leave'] as $type) {
        $allowance = (int) ($state['settings']['allowances'][$type] ?? 0);
        $left = max(0, $allowance - ($approved[$type] ?? 0));
        $pct = $allowance ? round(100 * $left / $allowance) : 0;
        echo '<section class="card kpi"><div class="kpi-top"><span>' . h($type) . '</span></div>'
            . '<div class="self-leave-value"><span class="kpi-value">' . $left . '</span><span class="muted">day' . ($left === 1 ? '' : 's') . ' left</span></div>'
            . '<div class="kpi-foot"><span>of ' . $allowance . ' day' . ($allowance === 1 ? '' : 's') . ' for ' . h($year) . '</span></div>'
            . '<div class="meter" role="img" aria-label="' . $left . ' of ' . $allowance . ' days left"><span style="width:' . $pct . '%"></span></div></section>';
    }
    echo '</div><p class="muted self-leave-note">' . h($year) . ' allowance · Remaining = entitlement − taken − approved upcoming leave. Pending requests are not deducted until approved.</p>';
}

function self_leave_requests_card(array $state, int $id, int $limit = 4): void
{
    $mine = array_values(array_filter($state['leaves'], function ($l) use ($id) { return $l['empId'] === $id; }));
    $today = $state['today'];
    // Upcoming and pending first (soonest first), then past requests (most recent first).
    usort($mine, function ($a, $b) use ($today) {
        $af = $a['end'] >= $today; $bf = $b['end'] >= $today;
        if ($af !== $bf) return $af ? -1 : 1;
        return $af ? strcmp($a['start'], $b['start']) : strcmp($b['start'], $a['start']);
    });
    echo '<section class="card"><div class="card-head"><h3>My leave requests</h3><a class="link-btn" href="' . h(page_url('my-time-off')) . '">View all requests ' . icon('arrowRight', 14) . '</a></div>';
    if (!$mine) {
        echo '<div class="empty"><strong>No leave requests yet</strong><span>Use Request leave to send one to HR.</span></div></section>';
        return;
    }
    echo '<div class="table-wrap"><table class="data"><thead><tr><th>Leave type</th><th>Dates</th><th class="num">Days</th><th>Status</th></tr></thead><tbody>';
    foreach (array_slice($mine, 0, $limit) as $l) {
        [$label, $style] = self_leave_status($l, $today);
        echo '<tr><td>' . h($l['type']) . '</td><td class="tabular">' . h(self_date_range($l['start'], $l['end'])) . '</td><td class="num">' . h(days_label($l['days'])) . '</td><td><span class="badge ' . $style . '">' . $label . '</span></td></tr>';
    }
    echo '</tbody></table></div>';
    $waiting = array_values(array_filter($mine, function ($l) { return $l['status'] === 'pending'; }));
    if ($waiting) echo '<div class="card-foot"><span>' . h(self_date_range($waiting[0]['start'], $waiting[0]['end'])) . ' · awaiting HR review</span></div>';
    echo '</section>';
}

function self_today_attendance_card(array $state, int $id, bool $withClock = true): void
{
    $today = $state['today'];
    $record = ((array) ($state['attendance']->{$today} ?? []))[$id] ?? [];
    $onLeave = false;
    foreach ($state['leaves'] as $l) if ($l['empId'] === $id && $l['status'] === 'approved' && $l['start'] <= $today && $l['end'] >= $today) $onLeave = true;
    $in = $record['in'] ?? '';
    $out = $record['out'] ?? '';
    [$state1, $style] = $onLeave ? ['On leave', 'warn'] : ($out ? ['Clocked out', 'plain'] : ($in ? ['Clocked in', 'ok'] : ['Not clocked in', 'plain']));
    $fmt = function (string $hm) { return $hm === '' ? '' : (new DateTimeImmutable('2000-01-01 ' . $hm))->format('g:i A'); };
    $start = $state['settings']['workStart'] ?? '08:00';
    $end = $state['settings']['workEnd'] ?? '17:00';
    $where = ['office' => 'In office', 'remote' => 'Remote', 'away' => 'Away'][$record['status'] ?? ''] ?? '—';
    echo '<section class="card self-today"><div class="card-head"><h3>Today’s attendance</h3><span class="badge ' . $style . '">' . $state1 . '</span></div><div class="card-body">';
    echo '<div class="self-today-time">' . h($in ? $fmt($in) : '—') . '</div><p class="muted">' . h((new DateTimeImmutable($today))->format('l, M j, Y')) . ($in ? ' · time in' : '') . ($out ? ' · out at ' . h($fmt($out)) : '') . '</p>';
    echo '<dl class="self-today-facts"><dt>Schedule</dt><dd>' . h($fmt($start) . ' – ' . $fmt($end)) . '</dd><dt>Work location</dt><dd>' . h($onLeave ? 'On leave' : $where) . '</dd></dl>';
    if ($withClock && !$onLeave) self_clock_actions($id, $today, $record);
    echo '<a class="btn self-wide" href="' . h(page_url('my-attendance')) . '">View attendance ' . icon('arrowRight', 14) . '</a></div></section>';
}

function self_schedule_card(array $state): void
{
    $today = array_values(array_filter($state['events'], function ($e) use ($state) { return $e['date'] === $state['today']; }));
    echo '<section class="card"><div class="card-head"><h3>Today’s schedule</h3></div>';
    if (!$today) {
        echo '<div class="empty"><strong>Nothing scheduled today</strong><span>Meetings and events HR adds to the calendar appear here.</span></div></section>';
        return;
    }
    echo '<div class="sched">';
    foreach ($today as $e) {
        $minutes = intdiv(strtotime($e['end']) - strtotime($e['start']), 60);
        $length = $minutes >= 60 ? (intdiv($minutes, 60) . ' hour' . (intdiv($minutes, 60) === 1 ? '' : 's') . ($minutes % 60 ? ' ' . ($minutes % 60) . ' min' : '')) : ($minutes . ' min');
        echo '<div class="sched-item"><div class="t"><strong>' . h($e['start']) . '</strong></div><div class="rule"></div><div class="e"><strong>' . h($e['title']) . '</strong><small>' . h($length . ($e['detail'] ? ' · ' . $e['detail'] : '')) . '</small></div></div>';
    }
    echo '</div></section>';
}

function self_company_updates_card(array $state): void
{
    $items = [];
    if (!empty($state['nextPayday'])) {
        $date = new DateTimeImmutable($state['nextPayday']['date']);
        $items[] = [$date->format('F') . ' payslip is coming soon', 'Your next pay date is ' . $date->format('F j, Y') . '.'];
    }
    foreach ($state['announcements'] ?? [] as $a) $items[] = [$a['title'], $a['body']];
    echo '<section class="card"><div class="card-head"><h3>Company updates</h3></div>';
    if (!$items) {
        echo '<div class="empty"><strong>No updates right now</strong><span>Announcements from HR appear here.</span></div></section>';
        return;
    }
    echo '<div class="self-updates">';
    foreach (array_slice($items, 0, 4) as [$title, $body]) echo '<div class="self-update"><strong>' . h($title) . '</strong><p class="muted">' . h($body) . '</p></div>';
    echo '</div></section>';
}

/* ---------- Sections reused by My time off / My attendance / My payslips and HR's employee view ---------- */

function self_clock_actions(int $id, string $day, array $record): void
{
    $windows = attendance_clock_windows(attendance_day_slots($id, $day, $record));
    if (!$windows) return;
    $clock = new DateTimeImmutable();
    $now = $clock->format('H:i');
    echo '<div class="attendance-clock-actions" data-attendance-clock data-clock-seconds="'
        . (dtr_minutes($now) * 60 + (int) $clock->format('s')) . '">';
    foreach ($windows as $slot => [$from, $until, $late, $label]) {
        $available = $now >= $from && $now < $until;
        $isLate = $late !== null && $now >= $late;
        echo '<div data-clock-from="' . dtr_minutes($from) . '" data-clock-until="' . dtr_minutes($until)
            . '"' . ($late === null ? '' : ' data-clock-late="' . dtr_minutes($late) . '"') . ($available ? ' data-available' : ' hidden') . '>';
        post_form('attendance.clock', ['empId' => $id, 'slot' => $slot], true);
        echo '<button class="btn primary attendance-clock-button' . ($isLate ? ' is-late' : '') . '" type="submit">'
            . h($label) . '<span data-clock-late-label' . ($isLate ? '' : ' hidden') . '> · Late</span></button></form></div>';
    }
    echo '</div>';
}

function self_clock_card(array $state, int $id): void
{
    $record = ((array) ($state['attendance']->{$state['today']} ?? []))[$id] ?? [];
    card('Attendance today');
    if (!empty($record['in'])) echo '<p>Clocked in at ' . h($record['in']) . '</p>';
    if (!empty($record['out'])) echo '<p>Clocked out at ' . h($record['out']) . '</p>';
    self_clock_actions($id, $state['today'], $record);
    echo '</section>';
}

function self_time_off_section(array $state, int $id, string $page, bool $openForm = false): void
{
    $employees = array_column($state['employees'], null, 'id');
    card('Time off');
    leave_balances($state, $id);
    leave_form([$id => employee_name($employees[$id])], false, $openForm);
    $requests = paginate(array_filter($state['leaves'], function ($l) use ($id) { return $l['empId'] === $id; }), 'leavePage');
    leave_table($state, $requests['rows'], false);
    pagination($requests, $page, array_intersect_key($_GET, ['slipsPage' => true]), 'requests', 'leavePage');
    echo '</section>';
}

function self_orders_section(array $state, int $id): void
{
    $myRequests = array_values(array_filter($state['employeeRequests'], function ($request) use ($id) { return $request['empId'] === $id; }));
    card('Special and travel orders');
    echo '<div class="card-body"><p class="muted" style="margin-bottom:12px">Submit a request for review by your supervisor or HR administrator.</p><div class="toolbar">';
    echo '<details class="form-disclosure" data-form-dialog="form"><summary class="btn primary">Request Special Order</summary>';
    post_form('employee.request.create', ['empId' => $id, 'type' => 'special_order']);
    field('purpose', 'Order subject');
    field('start', 'Effective date', today(), 'date');
    field('details', 'Details', '', 'text', false);
    end_form('Submit Special Order');
    echo '</details><details class="form-disclosure" data-form-dialog="form"><summary class="btn">Request Travel Order</summary>';
    post_form('employee.request.create', ['empId' => $id, 'type' => 'travel_order']);
    field('purpose', 'Travel purpose');
    field('destination', 'Destination');
    field('start', 'Departure date', today(), 'date');
    field('end', 'Return date', today(), 'date');
    field('details', 'Details', '', 'text', false);
    end_form('Submit Travel Order');
    echo '</details></div>';
    web_table(['Request', 'Purpose / destination', 'Dates', 'Status', 'Actions'], array_map(function ($request) use ($id) {
        return [
            $request['type'] === 'travel_order' ? 'Travel Order' : 'Special Order',
            function () use ($request) {
                echo h($request['purpose']);
                if ($request['destination']) echo '<span class="sub">' . h($request['destination']) . '</span>';
                if ($request['details']) echo '<span class="sub">' . h($request['details']) . '</span>';
            },
            $request['start'] . ($request['end'] ? ' – ' . $request['end'] : ''),
            function () use ($request) {
                status_badge($request['status']);
                if ($request['decisionRole']) echo '<span class="sub">Reviewed by ' . h($request['decisionRole'] === 'hr_admin' ? 'HR administrator' : 'supervisor') . ($request['decidedBy'] ? ' · ' . h($request['decidedBy']) : '') . '</span>';
            },
            function () use ($request, $id) {
                if ($request['status'] === 'pending') confirm_action('employee.request.cancel', 'Cancel', 'Cancel this request?', ['id' => $request['id'], 'empId' => $id]);
            },
        ];
    }, $myRequests));
    echo '</div></section>';
}

function self_payslips_section(array $state, int $id, string $page): void
{
    card('Payslips');
    $rows = [];
    foreach (array_reverse($state['payRuns']) as $run) {
        foreach ($run['lines'] ?? [] as $line) {
            if ($line['empId'] === $id) {
                $rows[] = [(new DateTimeImmutable($run['period'] . '-01'))->format('F Y'), money($line['gross'], $state), money($line['tax'], $state), money($line['benefits'], $state), money($line['net'], $state)];
            }
        }
    }
    $slips = paginate($rows, 'slipsPage');
    web_table(['Period', 'Gross', 'Tax', 'Contributions', 'Net pay'], $slips['rows']);
    pagination($slips, $page, array_intersect_key($_GET, ['leavePage' => true]), 'payslips', 'slipsPage');
    echo '</section>';
}

function self_recent_attendance(array $state, int $id, int $days = 14): void
{
    card('Recent attendance');
    $rows = [];
    for ($d = new DateTimeImmutable($state['today']), $n = 0; $n < $days; $d = $d->modify('-1 day'), $n++) {
        $day = $d->format('Y-m-d');
        $r = ((array) ($state['attendance']->{$day} ?? []))[$id] ?? null;
        $leave = null;
        foreach ($state['leaves'] as $l) if ($l['empId'] === $id && $l['status'] === 'approved' && $l['start'] <= $day && $l['end'] >= $day) $leave = $l;
        $weekend = (int) $d->format('N') >= 6;
        if (!$r && !$leave && $weekend) continue;
        $status = $leave ? 'leave' : ($r['status'] ?? '');
        $hours = '';
        if ($r && $r['in'] && $r['out']) { $m = intdiv(strtotime($r['out']) - strtotime($r['in']), 60); $hours = intdiv($m, 60) . 'h ' . str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT) . 'm'; }
        $rows[] = [$d->format('D, M j'), function () use ($status) { status_badge($status); }, $r['in'] ?? '', $r['out'] ?? '', $hours];
    }
    web_table(['Day', 'Status', 'Time in', 'Time out', 'Hours'], $rows);
    echo '</section>';
}

/* ---------- My time off ---------- */

/* Every leave request of one employee, filterable by status, with Cancel on pending ones. */
function self_leave_history_card(array $state, int $id): void
{
    $today = $state['today'];
    $filters = ['' => 'All statuses', 'pending' => 'Pending', 'approved' => 'Approved', 'taken' => 'Taken', 'declined' => 'Declined'];
    $filter = (string) ($_GET['status'] ?? '');
    if (!isset($filters[$filter])) $filter = '';
    $mine = array_values(array_filter($state['leaves'], function ($l) use ($id, $today, $filter) {
        if ($l['empId'] !== $id) return false;
        return $filter === '' || strtolower(self_leave_status($l, $today)[0]) === $filter;
    }));
    usort($mine, function ($a, $b) { return strcmp($b['start'], $a['start']) ?: $b['id'] <=> $a['id']; });
    $page = paginate($mine, 'leavePage');

    echo '<section class="card"><div class="card-head"><h3>Leave request history</h3>';
    echo '<form method="get" action="' . h(page_url('my-time-off')) . '" class="toolbar self-filter">'
        . '<select class="input sm" name="status" aria-label="Filter by status">';
    foreach ($filters as $value => $label) echo '<option value="' . h($value) . '"' . ($value === $filter ? ' selected' : '') . '>' . h($label) . '</option>';
    echo '</select><button class="btn sm" type="submit">Filter</button></form></div>';
    if (!$page['rows']) {
        echo '<div class="empty"><strong>' . ($filter === '' ? 'No leave requests yet' : 'No ' . strtolower($filters[$filter]) . ' requests') . '</strong><span>Use Request leave to send one to HR.</span></div></section>';
        return;
    }
    echo '<div class="table-wrap"><table class="data"><thead><tr><th>Leave type</th><th>Dates</th><th>Duration</th><th>Submitted</th><th>Status</th><th class="num"><span class="sr-only">Actions</span></th></tr></thead><tbody>';
    foreach ($page['rows'] as $l) {
        [$label, $style] = self_leave_status($l, $today);
        echo '<tr><td>' . h($l['type']) . ($l['reason'] !== '' ? '<span class="sub">' . h($l['reason']) . '</span>' : '') . '</td>'
            . '<td class="tabular">' . h(self_date_range($l['start'], $l['end'])) . '</td>'
            . '<td>' . h(days_label($l['days'])) . ($l['dayLength'] !== 'full' ? '<span class="sub">' . ($l['dayLength'] === 'am' ? 'Morning' : 'Afternoon') . '</span>' : '') . '</td>'
            . '<td class="tabular">' . h((new DateTimeImmutable(substr($l['createdAt'], 0, 10)))->format('M j, Y')) . '</td>'
            . '<td><span class="badge ' . $style . '">' . $label . '</span></td><td class="actions">';
        if ($l['status'] === 'pending') confirm_action('leave.cancel', 'Cancel', 'Withdraw this leave request?', ['id' => $l['id']]);
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
    pagination($page, 'my-time-off', $filter === '' ? [] : ['status' => $filter], 'requests', 'leavePage');
    echo '</section>';
}

/* Entitlement − taken − approved upcoming = remaining, per leave type, for this year. */
function self_leave_breakdown_card(array $state, int $id): void
{
    $today = $state['today'];
    $year = substr($today, 0, 4);
    $taken = $upcoming = [];
    foreach ($state['leaves'] as $l) {
        if ($l['empId'] !== $id || $l['status'] !== 'approved' || strpos($l['start'], $year) !== 0) continue;
        if ($l['end'] < $today) $taken[$l['type']] = ($taken[$l['type']] ?? 0) + $l['days'];
        else $upcoming[$l['type']] = ($upcoming[$l['type']] ?? 0) + $l['days'];
    }
    echo '<section class="card"><div class="card-head"><h3>Your leave breakdown</h3><span class="pill">' . h($year) . '</span></div>';
    echo '<div class="table-wrap"><table class="data"><thead><tr><th>Leave type</th><th class="num">Entitlement</th><th class="num">Taken</th><th class="num">Approved</th><th class="num">Remaining</th></tr></thead><tbody>';
    foreach (['Annual leave', 'Sick leave', 'Personal leave'] as $type) {
        $entitlement = (int) ($state['settings']['allowances'][$type] ?? 0);
        $t = $taken[$type] ?? 0;
        $a = $upcoming[$type] ?? 0;
        echo '<tr><td>' . h($type) . '</td><td class="num">' . $entitlement . '</td><td class="num">' . $t . '</td><td class="num">' . $a . '</td><td class="num"><strong>' . max(0, $entitlement - $t - $a) . '</strong></td></tr>';
    }
    echo '</tbody></table></div></section>';
}

function self_before_you_request_card(): void
{
    echo '<section class="card"><div class="card-head"><h3>Before you request</h3></div><div class="card-body"><ol class="self-tips">'
        . '<li><span>01</span>Plan annual leave 5 working days ahead.</li>'
        . '<li><span>02</span>HR reviews each request. Your team only sees that you’re out once it’s approved.</li>'
        . '<li><span>03</span>Unused annual leave resets on Jan 1.</li>'
        . '</ol><a class="link-btn" href="' . h(page_url('help')) . '">Read the leave policy ' . icon('arrowRight', 14) . '</a></div></section>';
}

/* An employee's own leave and orders that were approved or declined in the last 14 days (for the bell). */
function employee_updates(array $state, int $me): array
{
    $since = date('Y-m-d H:i:s', strtotime('-14 days'));
    $kinds = ['travel_order' => 'travel order', 'special_order' => 'special order', 'correction' => 'attendance correction'];
    $updates = [];
    $corrections = array_map(function ($c) { return ['type' => 'correction', 'start' => $c['date'], 'end' => $c['date']] + $c; }, $state['corrections'] ?? []);
    foreach (array_merge($state['leaves'] ?? [], $state['employeeRequests'] ?? [], $corrections) as $item) {
        if ($item['empId'] !== $me || !in_array($item['status'], ['approved', 'rejected'], true) || ($item['decidedAt'] ?? '') < $since) continue;
        $updates[] = [
            'title' => 'Your ' . ($kinds[$item['type']] ?? strtolower($item['type'])) . ' was ' . ($item['status'] === 'approved' ? 'approved' : 'declined'),
            'detail' => self_date_range($item['start'], $item['end'] ?: $item['start']) . ' · ' . date('M j', strtotime($item['decidedAt'])),
            'ok' => $item['status'] === 'approved', 'when' => $item['decidedAt'],
        ];
    }
    usort($updates, function ($a, $b) { return strcmp($b['when'], $a['when']); });
    return array_slice($updates, 0, 5);
}
