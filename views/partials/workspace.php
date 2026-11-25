<?php
declare(strict_types=1);

function workspace_view(array $state, bool $supervisor): void
{
    $employees = array_column($state['employees'], null, 'id');
    $active = array_filter($employees, function ($e) { return $e['status'] === 'Active'; });
    $key = $supervisor ? 'supervisorId' : 'selfEmpId';
    $profileId = (int) ($state['settings'][$key] ?? 0);
    $selectable = $active;
    if ($supervisor) {
        $managerIds = array_column($active, 'managerId');
        $selectable = array_filter($active, function ($e) use ($managerIds) { return in_array($e['id'], $managerIds, true); });
    }
    if ($supervisor) {
        echo '<section class="card web-card supervisor-selector"><div class="supervisor-selector-copy"><h2>Select supervisor</h2><p>Choose a profile to review their team and requests.</p></div>';
    } else {
        echo '<section class="card web-card employee-selector"><div class="employee-selector-copy"><h2>Select employee</h2><p>View attendance, leave, requests, and payslips for a profile.</p></div>';
    }
    post_form('workspace.view');
    choice($key, 'Profile', ['' => 'Select a profile'] + employee_options($selectable), $profileId);
    end_form('Open workspace');
    echo '</section>';
    if (!isset($selectable[$profileId])) return;
    if ($supervisor) {
        $team = array_filter($active, function ($e) use ($profileId) { return $e['managerId'] === $profileId; });
        $rows = [];
        $attendanceCounts = ['office' => 0, 'remote' => 0, 'leave' => 0];
        foreach (attendance_records($state, $state['today']) as [$employee, $record, $onLeave]) {
            if (!isset($team[$employee['id']])) continue;
            $status = $onLeave ? 'leave' : $record['status'];
            if (isset($attendanceCounts[$status])) $attendanceCounts[$status]++;
            $rows[] = [employee_name($employee), function () use ($onLeave, $record) { return status_badge($onLeave ? 'leave' : $record['status']); }, $record['in'], $record['out']];
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
        $requests = paginate($pendingRequests, 'leavePage');
        echo '<div class="supervisor-request-grid">';
        card('Pending team requests', 'supervisor-requests-card');
        echo '<p class="supervisor-section-note">Review leave requests from your direct reports.</p>';
        if ($requests['rows']) {
            echo '<div class="workspace-request-list">';
            foreach ($requests['rows'] as $request) {
                $name = isset($employees[$request['empId']]) ? employee_name($employees[$request['empId']]) : 'Former employee';
                echo '<article class="workspace-request-item"><div class="workspace-request-top"><div><strong>' . h($name) . '</strong><span>' . h($request['type']) . '</span></div>';
                status_badge($request['status']);
                echo '</div><div class="workspace-request-meta"><div><span>Dates</span><strong>' . h($request['start'] . ' – ' . $request['end']) . '</strong></div><div><span>Duration</span><strong>' . h((int) $request['days']) . ' days</strong></div></div>';
                echo '<div class="workspace-request-reason"><span>Reason</span><p>' . h($request['reason'] !== '' ? $request['reason'] : 'No reason provided') . '</p></div>';
                echo '<div class="workspace-request-actions">';
                action_button('leave.decide', 'Approve', ['id' => $request['id'], 'status' => 'approved']);
                action_button('leave.decide', 'Decline', ['id' => $request['id'], 'status' => 'rejected']);
                echo '</div></article>';
            }
            echo '</div>';
        } else {
            echo '<div class="workspace-empty"><strong>No pending team requests</strong><span>New requests will appear here for review.</span></div>';
        }
        pagination($requests, 'supervisor', array_intersect_key($_GET, ['p' => true]), 'requests', 'leavePage');
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
    $record = ((array) ($state['attendance']->{$state['today']} ?? []))[$profileId] ?? [];
    $myLeaves = array_values(array_filter($state['leaves'], function ($leave) use ($profileId) { return $leave['empId'] === $profileId; }));
    $pendingLeaveCount = 0;
    $annualUsed = 0;
    $annualPending = 0;
    $year = substr($state['today'], 0, 4);
    foreach ($myLeaves as $leave) {
        if ($leave['status'] === 'pending') $pendingLeaveCount++;
        if ($leave['type'] !== 'Annual leave' || substr($leave['start'], 0, 4) !== $year) continue;
        if ($leave['status'] === 'approved') $annualUsed += $leave['days'];
        if ($leave['status'] === 'pending') $annualPending += $leave['days'];
    }
    $annualRemaining = ($state['settings']['allowances']['Annual leave'] ?? 0) - $annualUsed - $annualPending;
    $myRequests = array_values(array_filter($state['employeeRequests'], function ($request) use ($profileId) { return $request['empId'] === $profileId; }));
    $rows = [];
    foreach (array_reverse($state['payRuns']) as $run) {
        foreach ($run['lines'] ?? [] as $line) {
            if ($line['empId'] === $profileId) {
                $rows[] = [$run['period'], money($line['gross'], $state), money($line['tax'], $state), money($line['benefits'], $state), money($line['net'], $state)];
            }
        }
    }
    $attendanceSummary = empty($record['in']) ? 'Not clocked in' : (empty($record['out']) ? 'Clocked in' : 'Shift complete');
    $attendanceDetail = empty($record['in']) ? 'Attendance has not been recorded' : 'Today, ' . (empty($record['out']) ? $record['in'] : $record['out']);
    $latestPay = $rows ? $rows[0][4] : 'No payslip yet';
    $latestPeriod = $rows ? $rows[0][0] : 'No payroll run available';
    echo '<div class="employee-metrics" aria-label="Employee summary">';
    foreach ([
        ['Attendance today', $attendanceSummary, $attendanceDetail],
        ['Annual leave', $annualRemaining . ' days', 'Remaining after requests'],
        ['Pending leave', $pendingLeaveCount, 'Requests awaiting review'],
        ['Latest net pay', $latestPay, $latestPeriod],
    ] as [$label, $value, $detail]) {
        echo '<article class="employee-metric"><span>' . h($label) . '</span><strong>' . h($value) . '</strong><small>' . h($detail) . '</small></article>';
    }
    echo '</div>';
    $slips = paginate($rows, 'slipsPage');
    echo '<div class="employee-overview-grid">';
    card('Attendance today', 'employee-attendance-card');
    if (empty($record['in'])) {
        echo '<div class="employee-attendance-state"><span class="badge plain">Not clocked in</span><p>Choose how you are working today to record your attendance.</p></div>';
        echo '<div class="employee-clock-actions">';
        action_button('attendance.clock', 'Clock in at office', ['empId' => $profileId, 'mode' => 'in', 'status' => 'office']);
        action_button('attendance.clock', 'Clock in remotely', ['empId' => $profileId, 'mode' => 'in', 'status' => 'remote']);
        echo '</div>';
    } else {
        echo '<div class="employee-attendance-state">';
        status_badge($record['status']);
        echo '<p>Clocked in at ' . h($record['in']) . (empty($record['out']) ? '' : ' · clocked out at ' . h($record['out'])) . '</p></div>';
        if (empty($record['out'])) action_button('attendance.clock', 'Clock out', ['empId' => $profileId, 'mode' => 'out']);
    }
    echo '</section>';
    card('Payslips', 'employee-payslips-card');
    if ($slips['rows']) {
        echo '<div class="employee-payslip-list">';
        foreach ($slips['rows'] as $slip) {
            echo '<article class="employee-payslip-item"><div class="employee-payslip-head"><div><span>Pay period</span><strong>' . h($slip[0]) . '</strong></div><div class="employee-payslip-net"><span>Net pay</span><strong>' . h($slip[4]) . '</strong></div></div><div class="employee-payslip-details">';
            foreach ([['Gross', $slip[1]], ['Tax', $slip[2]], ['Benefits', $slip[3]]] as [$label, $amount]) {
                echo '<div><span>' . h($label) . '</span><strong>' . h($amount) . '</strong></div>';
            }
            echo '</div></article>';
        }
        echo '</div>';
        pagination($slips, 'self-service', array_intersect_key($_GET, ['leavePage' => true]), 'payslips', 'slipsPage');
    } else {
        echo '<div class="workspace-empty"><strong>No payslips yet</strong><span>Published payslips will appear here.</span></div>';
    }
    echo '</section></div>';
    card('Time off', 'employee-timeoff-card');
    echo '<div class="employee-timeoff-overview"><div class="employee-balance-panel"><div class="employee-subsection-heading"><h3>Leave balances</h3><span>Available days by leave type</span></div>';
    leave_balances($state, $profileId);
    echo '</div><div class="employee-timeoff-action"><div><h3>Request time off</h3><p>Choose dates and a leave type to send a request for review.</p></div>';
    leave_form([$profileId => employee_name($employees[$profileId])]);
    echo '</div></div>';
    $requests = paginate($myLeaves, 'leavePage');
    echo '<div class="employee-request-history"><div class="employee-subsection-heading"><h3>My leave requests</h3><span>' . count($myLeaves) . ' total</span></div>';
    if ($requests['rows']) {
        leave_table($state, $requests['rows'], false, false, true);
        pagination($requests, 'self-service', array_intersect_key($_GET, ['slipsPage' => true]), 'requests', 'leavePage');
    } else {
        echo '<div class="workspace-empty"><strong>No leave requests yet</strong><span>Your submitted requests will appear here.</span></div>';
    }
    echo '</div></section>';
    card('Special and travel orders', 'employee-orders-card');
    echo '<div class="employee-order-intro"><div><p>Submit requests for review by your supervisor or HR administrator.</p><span>' . count($myRequests) . ' requests submitted</span></div><div class="employee-order-actions">';
    echo '<details class="form-disclosure" data-form-dialog="form"><summary class="btn primary">Request Special Order</summary>';
    post_form('employee.request.create', ['empId' => $profileId, 'type' => 'special_order']);
    field('purpose', 'Order subject');
    field('start', 'Effective date', today(), 'date');
    field('details', 'Details', '', 'text', false);
    end_form('Submit Special Order');
    echo '</details><details class="form-disclosure" data-form-dialog="form"><summary class="btn">Request Travel Order</summary>';
    post_form('employee.request.create', ['empId' => $profileId, 'type' => 'travel_order']);
    field('purpose', 'Travel purpose');
    field('destination', 'Destination');
    field('start', 'Departure date', today(), 'date');
    field('end', 'Return date', today(), 'date');
    field('details', 'Details', '', 'text', false);
    end_form('Submit Travel Order');
    echo '</details></div></div>';
    echo '<div class="employee-request-history"><div class="employee-subsection-heading"><h3>My special and travel orders</h3><span>' . count($myRequests) . ' total</span></div>';
    if ($myRequests) {
        echo '<div class="workspace-request-list">';
        foreach ($myRequests as $request) {
            $type = $request['type'] === 'travel_order' ? 'Travel Order' : 'Special Order';
            echo '<article class="workspace-request-item"><div class="workspace-request-top"><div><strong>' . h($type) . '</strong><span>' . h($request['purpose']) . '</span></div>';
            status_badge($request['status']);
            echo '</div><div class="workspace-request-meta"><div><span>Dates</span><strong>' . h($request['start'] . ($request['end'] ? ' – ' . $request['end'] : '')) . '</strong></div>';
            if ($request['destination'] !== '') echo '<div><span>Destination</span><strong>' . h($request['destination']) . '</strong></div>';
            echo '</div>';
            if ($request['details'] !== '') echo '<div class="workspace-request-reason"><span>Details</span><p>' . h($request['details']) . '</p></div>';
            if ($request['decisionRole']) echo '<div class="workspace-request-reason"><span>Reviewed by</span><p>' . h(($request['decisionRole'] === 'hr_admin' ? 'HR administrator' : 'Supervisor') . ($request['decidedBy'] ? ' · ' . $request['decidedBy'] : '')) . '</p></div>';
            if ($request['status'] === 'pending') {
                echo '<div class="workspace-request-actions">';
                confirm_action('employee.request.cancel', 'Cancel', 'Cancel this request?', ['id' => $request['id'], 'empId' => $profileId]);
                echo '</div>';
            }
            echo '</article>';
        }
        echo '</div>';
    } else {
        echo '<div class="workspace-empty"><strong>No special or travel orders yet</strong><span>Your submitted requests will appear here.</span></div>';
    }
    echo '</div></section>';
}

