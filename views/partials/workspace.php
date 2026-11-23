<?php
declare(strict_types=1);

function workspace_view(array $state, bool $supervisor): void
{
    $employees = array_column($state['employees'], null, 'id');
    $active = array_filter($employees, fn($e) => $e['status'] === 'Active');
    $key = $supervisor ? 'supervisorId' : 'selfEmpId';
    $profileId = (int) ($state['settings'][$key] ?? 0);
    $selectable = $active;
    if ($supervisor) {
        $managerIds = array_column($active, 'managerId');
        $selectable = array_filter($active, fn($e) => in_array($e['id'], $managerIds, true));
    }
    card($supervisor ? 'Select supervisor' : 'Select employee');
    post_form('workspace.view');
    choice($key, 'Profile', ['' => 'Select a profile'] + employee_options($selectable), $profileId);
    end_form('Open workspace');
    echo '</section>';
    if (!isset($selectable[$profileId])) return;
    if ($supervisor) {
        $team = array_filter($active, fn($e) => $e['managerId'] === $profileId);
        $rows = [];
        foreach (attendance_records($state, $state['today']) as [$employee, $record, $onLeave]) {
            if (isset($team[$employee['id']])) $rows[] = [employee_name($employee), $onLeave ? 'Leave' : ucfirst($record['status']), $record['in'], $record['out']];
        }
        card('Team today');
        web_table(['Employee', 'Status', 'Clock in', 'Clock out'], $rows);
        echo '</section>';
        card('Pending team requests');
        leave_table($state, array_filter($state['leaves'], fn($l) => isset($team[$l['empId']]) && $l['status'] === 'pending'));
        echo '</section>';
        return;
    }
    $record = ((array) ($state['attendance']->{$state['today']} ?? []))[$profileId] ?? [];
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
    leave_table($state, array_filter($state['leaves'], fn($l) => $l['empId'] === $profileId), false);
    echo '</section>';
    card('Payslips');
    $rows = [];
    foreach (array_reverse($state['payRuns']) as $run) {
        foreach ($run['lines'] ?? [] as $line) {
            if ($line['empId'] === $profileId) {
                $rows[] = [$run['period'], money($line['gross'], $state), money($line['tax'], $state), money($line['benefits'], $state), money($line['net'], $state)];
            }
        }
    }
    web_table(['Period', 'Gross', 'Tax', 'Benefits', 'Net'], $rows);
    echo '</section>';
}

