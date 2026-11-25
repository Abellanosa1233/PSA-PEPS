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
        card($supervisor ? 'Select supervisor' : 'Select employee');
        post_form('workspace.view');
        choice($key, 'Profile', ['' => 'Select a profile'] + employee_options($selectable), $profileId);
        end_form('Open workspace');
        echo '</section>';
    }
    if (!isset($selectable[$profileId])) return;
    if ($supervisor) {
        $team = array_filter($active, function ($e) use ($profileId) { return $e['managerId'] === $profileId; });
        $rows = [];
        foreach (attendance_records($state, $state['today']) as [$employee, $record, $onLeave]) {
            if (isset($team[$employee['id']])) $rows[] = [employee_name($employee), function () use ($onLeave, $record) { return status_badge($onLeave ? 'leave' : $record['status']); }, $record['in'], $record['out']];
        }
        card('Team today');
        $teamPage = paginate($rows);
        web_table(['Employee', 'Status', 'Clock in', 'Clock out'], $teamPage['rows']);
        pagination($teamPage, 'supervisor', array_intersect_key($_GET, ['leavePage' => true]), 'employees');
        echo '</section>';
        if ($selfAccount) {
            // Leave is private between the employee and HR; supervisors never see their team's leave.
            echo '<p class="muted workspace-intro">Leave requests go straight to HR and aren’t shown here, to keep your team’s plans private.</p>';
        } else {
            card('Pending team leave (HR view)');
            $requests = paginate(array_filter($state['leaves'], function ($l) use ($team) { return isset($team[$l['empId']]) && $l['status'] === 'pending'; }), 'leavePage');
            leave_table($state, $requests['rows']);
            pagination($requests, 'supervisor', array_intersect_key($_GET, ['p' => true]), 'requests', 'leavePage');
            echo '</section>';
        }
        $teamRequests = array_values(array_filter($state['employeeRequests'], function ($request) use ($team) { return isset($team[$request['empId']]); }));
        $teamRequestPage = paginate($teamRequests, 'requestPage');
        card('Special and travel order requests');
        web_table(['Employee', 'Request', 'Purpose / destination', 'Dates', 'Status', 'Actions'], array_map(function ($request) use ($employees, $profileId) {
            $employee = $employees[$request['empId']] ?? null;
            $type = $request['type'] === 'travel_order' ? 'Travel Order' : 'Special Order';
            return [
                $employee ? employee_name($employee) : 'Former employee',
                $type,
                function () use ($request) {
                    echo h($request['purpose']);
                    if ($request['destination']) echo '<span class="sub">' . h($request['destination']) . '</span>';
                    if ($request['details']) echo '<span class="sub">' . h($request['details']) . '</span>';
                },
                $request['start'] . ($request['end'] ? ' – ' . $request['end'] : ''),
                function () use ($request) { status_badge($request['status']); },
                function () use ($request, $profileId) {
                    if ($request['status'] === 'pending') {
                        action_button('employee.request.decide', 'Approve', ['id' => $request['id'], 'status' => 'approved', 'reviewerId' => $profileId]);
                        action_button('employee.request.decide', 'Decline', ['id' => $request['id'], 'status' => 'rejected', 'reviewerId' => $profileId]);
                    }
                },
            ];
        }, $teamRequestPage['rows']));
        pagination($teamRequestPage, 'supervisor', [], 'requests', 'requestPage');
        echo '</section>';
        return;
    }
    $record = ((array) ($state['attendance']->{$state['today']} ?? []))[$profileId] ?? [];
    dtr_card_form($profileId, $selfAccount);
    card('Attendance today');
    if (empty($record['in'])) {
        action_button('attendance.clock', 'Clock in at office', ['empId' => $profileId, 'mode' => 'in', 'status' => 'office']);
        action_button('attendance.clock', 'Clock in remotely', ['empId' => $profileId, 'mode' => 'in', 'status' => 'remote']);
    } else {
        echo '<p>Clocked in at ' . h($record['in']) . '</p>';
        if (empty($record['out'])) action_button('attendance.clock', 'Clock out', ['empId' => $profileId, 'mode' => 'out']);
        else echo '<p>Clocked out at ' . h($record['out']) . '</p>';
    }
    echo '</section>';
    card('Time off');
    leave_balances($state, $profileId);
    leave_form([$profileId => employee_name($employees[$profileId])]);
    $requests = paginate(array_filter($state['leaves'], function ($l) use ($profileId) { return $l['empId'] === $profileId; }), 'leavePage');
    leave_table($state, $requests['rows'], false);
    pagination($requests, 'self-service', array_intersect_key($_GET, ['slipsPage' => true]), 'requests', 'leavePage');
    echo '</section>';
    $myRequests = array_values(array_filter($state['employeeRequests'], function ($request) use ($profileId) { return $request['empId'] === $profileId; }));
    card('Special and travel orders');
    echo '<div class="card-body"><p class="muted" style="margin-bottom:12px">Submit a request for review by your supervisor or HR administrator.</p><div class="toolbar">';
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
    echo '</details></div>';
    web_table(['Request', 'Purpose / destination', 'Dates', 'Status', 'Actions'], array_map(function ($request) use ($profileId) {
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
            function () use ($request, $profileId) {
                if ($request['status'] === 'pending') confirm_action('employee.request.cancel', 'Cancel', 'Cancel this request?', ['id' => $request['id'], 'empId' => $profileId]);
            },
        ];
    }, $myRequests));
    echo '</div></section>';
    card('Payslips');
    $rows = [];
    foreach (array_reverse($state['payRuns']) as $run) {
        foreach ($run['lines'] ?? [] as $line) {
            if ($line['empId'] === $profileId) {
                $rows[] = [$run['period'], money($line['gross'], $state), money($line['tax'], $state), money($line['benefits'], $state), money($line['net'], $state)];
            }
        }
    }
    $slips = paginate($rows, 'slipsPage');
    web_table(['Period', 'Gross', 'Tax', 'Benefits', 'Net'], $slips['rows']);
    pagination($slips, 'self-service', array_intersect_key($_GET, ['leavePage' => true]), 'payslips', 'slipsPage');
    echo '</section>';
}

