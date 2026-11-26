<?php
declare(strict_types=1);

/* A plain GET form that opens the printable DTR (views/pages/dtr.php) for one person. */
function dtr_card_form(int $employeeId, bool $ownRecord): void
{
    $half = (int) date('j') <= 15 ? 'first' : 'second';
    card('Daily Time Record (DTR)');
    echo '<p class="muted">' . ($ownRecord
        ? 'Your time in and out from the biometric device, laid out like the printed time card. Open a period, check it, then print and sign.'
        : 'Time in and out from the biometric device, laid out like the printed time card.') . '</p>';
    echo '<form method="get" action="' . h(page_url('dtr')) . '" class="toolbar dtr-open">';
    if (!$ownRecord) echo '<input type="hidden" name="emp" value="' . $employeeId . '">';
    echo '<label class="field"><span>Month</span><input class="input" type="month" name="month" value="' . h(date('Y-m')) . '" required></label>';
    echo '<label class="field"><span>Period</span><select class="input" name="half">';
    foreach (['first' => '1st–15th', 'second' => '16th–end of month', 'month' => 'Whole month'] as $value => $label) {
        echo '<option value="' . $value . '"' . ($value === $half ? ' selected' : '') . '>' . $label . '</option>';
    }
    echo '</select></label><button class="btn primary" type="submit">' . icon('file', 15) . ' View DTR</button></form></section>';
}

/* "Out of office" wording for a teammate's approved absence. Deliberately vague: the type
   of leave, its reason and an order's purpose or destination stay private. */
function absence_label(string $kind): string
{
    return ['leave' => 'On leave', 'travel_order' => 'On travel order', 'special_order' => 'On special order'][$kind] ?? 'Out of office';
}

function absence_badge(string $kind): void
{
    echo '<span class="badge ' . ($kind === 'leave' ? 'warn' : '') . '">' . h(absence_label($kind)) . '</span>';
}

function absence_dates(array $a, string $today): string
{
    $from = new DateTimeImmutable($a['start']);
    $to = new DateTimeImmutable($a['end']);
    $range = $a['start'] === $a['end'] ? $from->format('M j')
        : ($from->format('Y-m') === $to->format('Y-m') ? $from->format('M j') . '–' . $to->format('j') : $from->format('M j') . ' – ' . $to->format('M j'));
    if ($a['start'] <= $today && $a['end'] >= $today) $range .= ' · out today' . ($a['end'] > $today ? ', back ' . $to->modify('+1 day')->format('M j') : '');
    return $range;
}

/* Teammates' approved leave and orders (current and upcoming), so the team can plan around them. */
function team_absence_card(array $state): void
{
    if (!isset($state['teamAbsences'])) return; // only employee accounts receive this
    $people = array_column($state['employees'], null, 'id');
    card('Team out of office');
    echo '<p class="muted">Approved leave and Special/Travel Orders of your teammates, today and coming up. Details of the leave stay with HR.</p>';
    web_table(['Teammate', 'Status', 'Dates'], array_map(function ($a) use ($people, $state) {
        $person = $people[$a['empId']] ?? null;
        return [
            function () use ($person) {
                echo '<strong>' . h($person ? employee_name($person) : 'Teammate') . '</strong>';
                if ($person) echo '<span class="sub">' . h($person['title']) . '</span>';
            },
            function () use ($a) { absence_badge($a['kind']); },
            absence_dates($a, $state['today']),
        ];
    }, $state['teamAbsences']));
    echo '</section>';
}

function workspace_view(array $state, bool $supervisor): void
{
    $employees = array_column($state['employees'], null, 'id');
    $active = array_filter($employees, function ($e) { return $e['status'] === 'Active'; });
    $key = $supervisor ? 'supervisorId' : 'selfEmpId';
    $selfAccount = !is_admin($state['user']);
    $profileId = $selfAccount ? (int) $state['user']['employeeId'] : (int) ($state['settings'][$key] ?? 0);
    $selectable = $active;
    if ($supervisor) {
        $managerIds = array_column($active, 'managerId');
        $selectable = array_filter($active, function ($e) use ($managerIds) { return in_array($e['id'], $managerIds, true); });
    }
    if ($selfAccount) {
        // Employee accounts always see their own workspace; there is nothing to choose.
        $me = $employees[$profileId] ?? null;
        if ($me) {
            echo '<p class="muted workspace-intro">' . h($supervisor
                ? 'Your direct reports today, and the requests waiting for your decision.'
                : employee_name($me) . ' · ' . $me['title'] . ' · ' . $me['department']) . '</p>';
        }
    } else {
        if ($supervisor) {
            echo '<section class="card web-card supervisor-selector"><div class="supervisor-selector-copy"><h2>Select supervisor</h2><p>Choose a profile to review their team and requests.</p></div>';
        } else {
            echo '<section class="card web-card employee-selector"><div class="employee-selector-copy"><h2>Select employee</h2><p>View attendance, leave, and requests for a profile.</p></div>';
        }
        post_form('workspace.view');
        choice($key, 'Profile', ['' => 'Select a profile'] + employee_options($selectable), $profileId);
        end_form('Open workspace');
        echo '</section>';
    }
    if (!isset($selectable[$profileId])) return;
    if ($supervisor) {
        $team = array_filter($active, function ($e) use ($profileId) { return $e['managerId'] === $profileId; });
        $rows = [];
        $attendanceCounts = ['office' => 0, 'remote' => 0, 'leave' => 0];
        // Approved absences today (employee accounts get these instead of the leave records themselves).
        $outToday = [];
        foreach ($state['teamAbsences'] ?? [] as $a) {
            if ($a['start'] <= $state['today'] && $a['end'] >= $state['today']) $outToday[$a['empId']] = $a['kind'];
        }
        foreach (attendance_records($state, $state['today']) as [$employee, $record, $onLeave]) {
            if (!isset($team[$employee['id']])) continue;
            $kind = $outToday[$employee['id']] ?? null;
            $status = ($onLeave || ($kind !== null && !$record['in'])) ? 'leave' : $record['status'];
            if (isset($attendanceCounts[$status])) $attendanceCounts[$status]++;
            $rows[] = [employee_name($employee), function () use ($onLeave, $record, $kind) {
                if ($kind !== null && !$record['in']) absence_badge($kind);
                else status_badge($onLeave ? 'leave' : $record['status']);
            }, $record['in'], $record['out']];
        }
        $pendingRequests = array_values(array_filter($state['leaves'], function ($l) use ($team) { return isset($team[$l['empId']]) && $l['status'] === 'pending'; }));
        echo '<div class="supervisor-metrics" aria-label="Team attendance summary">';
        foreach ([
            ['Team members', count($team), 'Direct reports'],
            ['In office', $attendanceCounts['office'], 'On-site today'],
            ['Remote', $attendanceCounts['remote'], 'Working remotely'],
            ['On leave', $attendanceCounts['leave'], 'Away today'],
        ] as [$label, $value, $detail]) {
            echo '<article class="supervisor-metric"><span>' . h($label) . '</span><strong>' . h($value) . '</strong><small>' . h($detail) . '</small></article>';
        }
        echo '</div>';
        card('Team today', 'supervisor-team-card');
        echo '<p class="supervisor-section-note">Attendance and availability for ' . h((new DateTimeImmutable($state['today']))->format('M j, Y')) . '.</p>';
        $teamPage = paginate($rows);
        web_table(['Employee', 'Status', 'Clock in', 'Clock out'], $teamPage['rows']);
        pagination($teamPage, 'supervisor', array_intersect_key($_GET, ['leavePage' => true]), 'employees');
        echo '</section>';
        echo '<div class="supervisor-request-grid">';
        if ($selfAccount) {
            // Leave is private between the employee and HR; supervisors never see their team's leave.
            card('Team leave', 'supervisor-requests-card');
            echo '<p class="supervisor-section-note">Leave requests go straight to HR and aren’t shown here, to keep your team’s plans private.</p>';
        } else {
            card('Pending team leave (HR view)', 'supervisor-requests-card');
            $requests = paginate($pendingRequests, 'leavePage');
            leave_table($state, $requests['rows']);
            pagination($requests, 'supervisor', array_intersect_key($_GET, ['p' => true]), 'requests', 'leavePage');
        }
        echo '</section>';
        $teamRequests = array_values(array_filter($state['employeeRequests'], function ($request) use ($team) { return isset($team[$request['empId']]); }));
        $teamRequestPage = paginate($teamRequests, 'requestPage');
        card('Special and travel order requests', 'supervisor-requests-card');
        echo '<p class="supervisor-section-note">Track special and travel orders submitted by your team.</p>';
        if ($teamRequestPage['rows']) {
            echo '<div class="workspace-request-list">';
            foreach ($teamRequestPage['rows'] as $request) {
                $employee = $employees[$request['empId']] ?? null;
                $name = $employee ? employee_name($employee) : 'Former employee';
                $type = $request['type'] === 'travel_order' ? 'Travel Order' : 'Special Order';
                echo '<article class="workspace-request-item"><div class="workspace-request-top"><div><strong>' . h($name) . '</strong><span>' . h($type) . '</span></div>';
                status_badge($request['status']);
                echo '</div><div class="workspace-order-purpose"><span>Purpose</span><strong>' . h($request['purpose']) . '</strong></div>';
                echo '<div class="workspace-request-meta"><div><span>Dates</span><strong>' . h($request['start'] . ($request['end'] ? ' – ' . $request['end'] : '')) . '</strong></div>';
                if ($request['destination'] !== '') echo '<div><span>Destination</span><strong>' . h($request['destination']) . '</strong></div>';
                echo '</div>';
                if ($request['details'] !== '') echo '<div class="workspace-request-reason"><span>Details</span><p>' . h($request['details']) . '</p></div>';
                if ($request['status'] === 'pending') {
                    echo '<div class="workspace-request-actions">';
                    action_button('employee.request.decide', 'Approve', ['id' => $request['id'], 'status' => 'approved', 'reviewerId' => $profileId]);
                    action_button('employee.request.decide', 'Decline', ['id' => $request['id'], 'status' => 'rejected', 'reviewerId' => $profileId]);
                    echo '</div>';
                }
                echo '</article>';
            }
            echo '</div>';
        } else {
            echo '<div class="workspace-empty"><strong>No special or travel orders</strong><span>Team requests will appear here.</span></div>';
        }
        pagination($teamRequestPage, 'supervisor', [], 'requests', 'requestPage');
        echo '</section></div>';
        return;
    }
    // HR's view of one employee's workspace: the same sections the employee sees on their own pages.
    dtr_card_form($profileId, $selfAccount);
    if ($selfAccount) team_absence_card($state);
    self_clock_card($state, $profileId);
    self_time_off_section($state, $profileId, 'self-service');
    self_orders_section($state, $profileId);
}

