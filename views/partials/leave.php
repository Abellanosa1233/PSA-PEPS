<?php
declare(strict_types=1);

function leave_form(array $employees, bool $allowApproval = false): void
{
    if (!$employees) return;
    echo '<details class="form-disclosure" data-form-dialog="form"><summary class="btn primary">Request time off</summary>';
    post_form('leave.create');
    choice('empId', 'Employee', $employees);
    choice('type', 'Leave type', options(LEAVE_TYPES));
    field('start', 'First day', today(), 'date');
    field('end', 'Last day', today(), 'date');
    field('reason', 'Reason', '', 'text', false);
    if ($allowApproval) echo '<label><input type="checkbox" name="approveNow" value="1"> Approve immediately</label>';
    end_form('Submit request');
    echo '</details>';
}

function leave_table(array $state, array $leaves, bool $canDecide = true, bool $compact = false): void
{
    $names = employee_options($state['employees']);
    if ($compact) {
        web_table(['Employee', 'Leave type', 'Dates', 'Actions'], array_map(fn($l) => [
            fn() => link_to('employees', $names[$l['empId']] ?? 'Former employee', ['id' => $l['empId']]),
            $l['type'],
            function () use ($l) {
                echo h($l['start'] . ' – ' . $l['end']) . '<span class="sub">' . (int) $l['days'] . ' day(s)</span>';
            },
            function () use ($l) {
                action_button('leave.decide', 'Approve', ['id' => $l['id'], 'status' => 'approved']);
                action_button('leave.decide', 'Decline', ['id' => $l['id'], 'status' => 'rejected']);
            },
        ], $leaves));
        return;
    }
    web_table(['Employee', 'Type', 'Dates', 'Days', 'Reason', 'Status', 'Actions'], array_map(fn($l) => [
        $names[$l['empId']] ?? 'Former employee', $l['type'], $l['start'] . ' – ' . $l['end'], $l['days'], $l['reason'], fn() => status_badge($l['status']),
        function () use ($l, $state, $canDecide) {
            if ($l['status'] === 'pending' && $canDecide) {
                action_button('leave.decide', 'Approve', ['id' => $l['id'], 'status' => 'approved']);
                action_button('leave.decide', 'Decline', ['id' => $l['id'], 'status' => 'rejected']);
            }
            if ($l['status'] === 'pending' || ($l['status'] === 'approved' && $l['end'] >= $state['today'])) {
                confirm_action('leave.cancel', 'Cancel request', 'Remove this leave request?', ['id' => $l['id']]);
            }
        },
    ], $leaves));
}

function leave_balances(array $state, int $employeeId): void
{
    $year = substr($state['today'], 0, 4);
    $balances = [];
    foreach ($state['leaves'] as $leave) {
        if ($leave['empId'] !== $employeeId || !str_starts_with($leave['start'], $year)) continue;
        $type = $leave['type'];
        $status = $leave['status'];
        $balances[$type][$status] = ($balances[$type][$status] ?? 0) + $leave['days'];
    }
    $rows = [];
    foreach ($state['settings']['allowances'] as $type => $allowance) {
        $used = $balances[$type]['approved'] ?? 0;
        $pending = $balances[$type]['pending'] ?? 0;
        $rows[] = [$type, $allowance, $used, $pending, $allowance - $used - $pending];
    }
    web_table(['Type', 'Allowance', 'Used', 'Pending', 'Remaining'], $rows);
}

