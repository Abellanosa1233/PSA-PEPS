<?php
declare(strict_types=1);

/* Sections of the employee's My attendance page. They reuse the DTR logic (api/lib/dtr.php),
   so the numbers always match the printed Daily Time Record. */

function self_clock12(?string $hm): string
{
    return $hm === null || $hm === '' ? '—' : date('h:i A', strtotime('2000-01-01 ' . $hm));
}

function self_minutes_label(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return $h ? $h . 'h' . ($m ? ' ' . $m . 'm' : '') : $m . 'm';
}

/* Check-in, check-out, break minutes, worked total and status for one DTR row. */
function self_day_summary(array $row, string $workStart): array
{
    $s = $row['slots'];
    $in = $s['amIn'] ?? $s['pmIn'];
    $punches = array_values(array_filter([$s['amIn'], $s['amOut'], $s['pmIn'], $s['pmOut'], $s['otIn'], $s['otOut']]));
    $out = count($punches) > 1 ? end($punches) : null;
    $break = 0;
    if ($s['amOut'] && $s['pmIn']) {
        $break = intdiv(strtotime($s['pmIn']) - strtotime($s['amOut']), 60);
    } elseif ($s['amIn'] && $s['pmOut'] && !$s['amOut'] && !$s['pmIn'] && $s['pmOut'] > '13:00' && $s['amIn'] < DTR_NOON) {
        $break = DTR_LUNCH_MINUTES; // in/out only: the lunch hour is deducted
    }
    if ($in === null) {
        $status = in_array($row['note'], LEAVE_TYPES, true) ? 'On leave' : ($row['note'] === 'Absent' ? 'Absent' : '');
    } else {
        $status = $in > $workStart ? 'Late' : 'On time';
    }
    return ['in' => $in, 'out' => $out, 'break' => $break, 'total' => $row['total'], 'status' => $status];
}

function self_week_label(DateTimeImmutable $mon): string
{
    $fri = $mon->modify('+4 days');
    return $mon->format('M j') . ' – ' . ($mon->format('M') === $fri->format('M') ? $fri->format('j') : $fri->format('M j')) . ', ' . $fri->format('Y');
}

/* The four summary cards and the schedule line. */
function self_attendance_summary(array $state, int $id): void
{
    $people = array_column($state['employees'], null, 'id');
    $today = new DateTimeImmutable($state['today']);
    $start = $state['settings']['workStart'] ?? '08:00';
    $month = dtr_card($people[$id], $today->modify('first day of this month'), $today, $state['today']);
    $minutes = 0;
    $completed = 0;
    $onTime = 0;
    $through = null;
    $todaySum = null;
    foreach ($month['rows'] as $row) {
        $d = self_day_summary($row, $start);
        if ($row['date'] === $state['today']) $todaySum = $d;
        if ($row['total'] !== '') {
            [$h, $m] = explode(':', $row['total']);
            $minutes += (int) $h * 60 + (int) $m;
        }
        if ($d['in'] !== null && ($d['out'] !== null || $row['date'] < $state['today'])) {
            $completed++;
            if ($d['status'] === 'On time') $onTime++;
            if ($d['out'] !== null) $through = $row['date'];
        }
    }
    $rec = ((array) ($state['attendance']->{$state['today']} ?? []))[$id] ?? [];
    $where = ['office' => 'In office', 'remote' => 'Remote'][$rec['status'] ?? ''] ?? 'Checked in';
    $onLeaveToday = false;
    foreach ($state['leaves'] as $l) {
        if ($l['empId'] === $id && $l['status'] === 'approved' && $l['start'] <= $state['today'] && $l['end'] >= $state['today']) $onLeaveToday = true;
    }
    $todayIn = $todaySum['in'] ?? null;
    $present = $month['totals']['daysPresent'];
    $cards = [
        ['Today’s check-in', $todayIn ? self_clock12($todayIn) : '—',
            $onLeaveToday ? 'On leave today' : ($todayIn ? $where : 'Not checked in yet') . ' · ' . $today->format('M j')],
        ['Hours this month', intdiv($minutes, 60) . 'h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . 'm',
            $through ? 'Through ' . (new DateTimeImmutable($through))->format('F j') : $today->format('F Y')],
        ['On-time rate', $completed ? round(100 * $onTime / $completed) . '%' : '—',
            $completed ? "$onTime of $completed completed day" . ($completed === 1 ? '' : 's') : 'No completed days yet'],
        ['Attendance', $present . ' day' . ($present === 1 ? '' : 's'), $today->format('F Y')],
    ];
    echo '<div class="grid kpis">';
    foreach ($cards as [$label, $value, $note]) {
        echo '<section class="card kpi"><div class="kpi-top"><span>' . h($label) . '</span></div><div class="kpi-value">' . h($value)
            . '</div><div class="kpi-foot"><span>' . h($note) . '</span></div></section>';
    }
    echo '</div><p class="muted self-leave-note">Your schedule: Monday – Friday, ' . h(self_clock12($start) . ' – ' . self_clock12($state['settings']['workEnd'] ?? '17:00'))
        . ' · 1-hour unpaid break</p>';
}

/* Weekly log (Mon–Fri), picked with a plain GET form. */
function self_attendance_log(array $state, int $id): void
{
    $people = array_column($state['employees'], null, 'id');
    $thisMonday = (new DateTimeImmutable($state['today']))->modify('monday this week');
    $weeks = [];
    for ($i = 0; $i < 8; $i++) {
        $mon = $thisMonday->modify("-$i week");
        $weeks[$mon->format('Y-m-d')] = self_week_label($mon);
    }
    // On Mondays the current week is nearly empty, so show last week by default.
    $default = (int) $thisMonday->format('Ymd') === (int) str_replace('-', '', $state['today']) ? $thisMonday->modify('-1 week')->format('Y-m-d') : $thisMonday->format('Y-m-d');
    $week = isset($weeks[(string) ($_GET['week'] ?? '')]) ? (string) $_GET['week'] : $default;
    $mon = new DateTimeImmutable($week);
    $card = dtr_card($people[$id], $mon, $mon->modify('+4 days'), $state['today']);
    $start = $state['settings']['workStart'] ?? '08:00';

    echo '<section class="card"><div class="card-head"><h3>Attendance log</h3>';
    echo '<form method="get" action="' . h(page_url('my-attendance')) . '" class="toolbar self-filter"><select class="input sm" name="week" aria-label="Week">';
    foreach ($weeks as $value => $label) echo '<option value="' . h($value) . '"' . ($value === $week ? ' selected' : '') . '>' . h($label) . '</option>';
    echo '</select><button class="btn sm" type="submit">Show</button></form></div>';
    echo '<div class="table-wrap"><table class="data"><thead><tr><th>Date</th><th>Check-in</th><th>Check-out</th><th>Break</th><th>Total hours</th><th>Status</th></tr></thead><tbody>';
    $shown = 0;
    foreach (array_reverse($card['rows']) as $row) {
        if ($row['date'] > $state['today']) continue;
        $shown++;
        $d = self_day_summary($row, $start);
        $total = $d['total'] !== '' ? str_replace(':', 'h ', $d['total']) . 'm' : '—';
        $style = ['On time' => 'ok', 'Late' => 'warn', 'Absent' => 'bad', 'On leave' => 'plain'][$d['status']] ?? 'plain';
        echo '<tr><td>' . h((new DateTimeImmutable($row['date']))->format('D, M j')) . '</td>'
            . '<td class="tabular">' . h(self_clock12($d['in'])) . '</td><td class="tabular">' . h(self_clock12($d['out'])) . '</td>'
            . '<td>' . ($d['break'] ? h(self_minutes_label($d['break'])) : '—') . '</td><td class="tabular">' . h($total) . '</td>'
            . '<td>' . ($d['status'] !== '' ? '<span class="badge ' . $style . '">' . h($d['status']) . '</span>' : '<span class="muted">—</span>') . '</td></tr>';
    }
    if (!$shown) echo '<tr><td colspan="6">No workdays yet in this week.</td></tr>';
    echo '</tbody></table></div></section>';
}

/* Mon–Fri tiles for the current week. */
function self_week_tiles(array $state, int $id): void
{
    $people = array_column($state['employees'], null, 'id');
    $mon = (new DateTimeImmutable($state['today']))->modify('monday this week');
    $card = dtr_card($people[$id], $mon, $mon->modify('+4 days'), $state['today']);
    $pending = array_filter($state['leaves'], function ($l) use ($id) { return $l['empId'] === $id && $l['status'] === 'pending'; });
    $rec = ((array) ($state['attendance']->{$state['today']} ?? []))[$id] ?? [];
    $label = self_week_label($mon);
    echo '<section class="card"><div class="card-head"><h3>This week · ' . h(substr($label, 0, (int) strrpos($label, ','))) . '</h3></div><div class="card-body"><div class="self-week">';
    foreach ($card['rows'] as $row) {
        $day = $row['date'];
        $d = self_day_summary($row, $state['settings']['workStart'] ?? '08:00');
        $isLeave = in_array($row['note'], LEAVE_TYPES, true);
        $isPending = (bool) array_filter($pending, function ($l) use ($day) { return $l['start'] <= $day && $l['end'] >= $day; });
        if ($day === $state['today']) {
            $status = $isLeave ? 'On leave' : ($d['in'] ? (['office' => 'In office', 'remote' => 'Remote'][$rec['status'] ?? ''] ?? 'Checked in') : 'Not checked in');
        } elseif ($day < $state['today']) {
            $status = $isLeave ? 'On leave' : ($d['in'] ? 'Present' : ($row['note'] === 'Absent' ? 'Absent' : '—'));
        } else {
            $status = $isLeave ? 'On leave' : ($isPending ? 'Leave pending' : 'Scheduled');
        }
        echo '<div class="self-day' . ($day === $state['today'] ? ' today' : '') . '"><span>' . h(strtoupper((new DateTimeImmutable($day))->format('D')))
            . '</span><strong>' . (int) $row['day'] . '</strong><small>' . h($status) . '</small></div>';
    }
    echo '</div></div></section>';
}

/* "Something doesn't look right?" with the correction form and the employee's recent requests. */
function self_correction_card(array $state, int $id, bool $openForm = false): void
{
    $mine = array_values(array_filter($state['corrections'] ?? [], function ($c) use ($id) { return $c['empId'] === $id; }));
    echo '<section class="card" id="correction"><div class="card-head"><h3>Something doesn’t look right?</h3></div><div class="card-body">';
    echo '<p class="muted">Forgot to clock in or need to adjust a record? Send a correction with the date and reason. HR will review the change.</p>';
    echo '<details class="form-disclosure" data-form-dialog="form"' . ($openForm ? ' open data-auto-open' : '') . '><summary class="btn self-wide">Request a correction ' . icon('arrowRight', 14) . '</summary>';
    post_form('correction.create', ['empId' => $id]);
    field('date', 'Date', $state['today'], 'date');
    field('in', 'Correct check-in', '', 'time', false);
    field('out', 'Correct check-out', '', 'time', false);
    field('reason', 'Reason', '', 'text', true, '', ['maxlength' => '255']);
    echo '<p class="hint">Fill in the time that’s missing or wrong; leave the other blank to keep it as recorded.</p>';
    end_form('Send to HR');
    echo '</details>';
    if ($mine) {
        echo '<div class="self-corrections"><div class="side-label">Your requests</div>';
        foreach (array_slice($mine, 0, 4) as $c) {
            $style = ['approved' => 'ok', 'rejected' => 'bad', 'pending' => 'warn'][$c['status']] ?? 'plain';
            $label = ['approved' => 'Approved', 'rejected' => 'Declined', 'pending' => 'Pending'][$c['status']] ?? $c['status'];
            $times = trim(($c['in'] ? 'In ' . self_clock12($c['in']) : '') . ($c['in'] && $c['out'] ? ' · ' : '') . ($c['out'] ? 'Out ' . self_clock12($c['out']) : ''));
            echo '<div class="self-correction"><span><strong>' . h((new DateTimeImmutable($c['date']))->format('D, M j')) . '</strong><small>' . h($times) . '</small></span>'
                . '<span class="badge ' . $style . '">' . h($label) . '</span>';
            if ($c['status'] === 'pending') confirm_action('correction.cancel', 'Cancel', 'Cancel this correction request?', ['id' => $c['id'], 'empId' => $id]);
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div></section>';
}

/* HR: pending attendance corrections, with Approve / Decline. Shown on the Attendance page. */
function hr_corrections_card(array $state): void
{
    $pending = array_values(array_filter($state['corrections'] ?? [], function ($c) { return $c['status'] === 'pending'; }));
    if (!$pending) return;
    $people = array_column($state['employees'], null, 'id');
    card('Attendance corrections to review');
    web_table(['Employee', 'Date', 'Requested change', 'Reason', 'Actions'], array_map(function ($c) use ($people) {
        $person = $people[$c['empId']] ?? null;
        return [
            $person ? employee_name($person) : 'Former employee',
            (new DateTimeImmutable($c['date']))->format('D, M j, Y'),
            trim(($c['in'] ? 'Check-in ' . self_clock12($c['in']) : '') . ($c['in'] && $c['out'] ? ' · ' : '') . ($c['out'] ? 'Check-out ' . self_clock12($c['out']) : '')),
            $c['reason'],
            function () use ($c) {
                action_button('correction.decide', 'Approve', ['id' => $c['id'], 'status' => 'approved']);
                action_button('correction.decide', 'Decline', ['id' => $c['id'], 'status' => 'rejected']);
            },
        ];
    }, $pending));
    echo '</section>';
}
