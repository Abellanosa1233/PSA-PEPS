<?php
declare(strict_types=1);

function leave_form(array $employees, bool $allowApproval = false, bool $openNow = false, string $buttonLabel = 'Request time off'): void
{
    if (!$employees) return;
    // $openNow: the page was reached through a "Request leave" button, so show the form straight away.
    echo '<details class="form-disclosure" data-form-dialog="form"' . ($openNow ? ' open data-auto-open' : '') . '><summary class="btn primary">'
        . ($buttonLabel === 'Request time off' ? '' : icon('plus') . ' ') . h($buttonLabel) . '</summary>';
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
        web_table(['Employee', 'Leave type', 'Dates', 'Actions'], array_map(function ($l) use ($names) {
            return [
                function () use ($names, $l) { return link_to('employees', $names[$l['empId']] ?? 'Former employee', ['id' => $l['empId']]); },
                $l['type'],
                function () use ($l) {
                    echo h($l['start'] . ' – ' . $l['end']) . '<span class="sub">' . h(days_label($l['days'])) . '</span>';
                },
                function () use ($l) {
                    action_button('leave.decide', 'Approve', ['id' => $l['id'], 'status' => 'approved']);
                    action_button('leave.decide', 'Decline', ['id' => $l['id'], 'status' => 'rejected']);
                },
            ];
        }, $leaves));
        return;
    }
    web_table(['Employee', 'Type', 'Dates', 'Days', 'Reason', 'Status', 'Actions'], array_map(function ($l) use ($names, $state, $canDecide) {
        return [
            $names[$l['empId']] ?? 'Former employee', $l['type'], $l['start'] . ' – ' . $l['end'], days_label($l['days']), function () use ($l) { echo h($l['reason']); if (!empty($l['hasAttachment'])) echo '<span class="sub"><a href="' . h(page_url('leave-file', ['id' => $l['id']])) . '" target="_blank" rel="noopener">View document</a></span>'; }, function () use ($l) { return status_badge($l['status']); },
            function () use ($l, $state, $canDecide) {
                if ($l['status'] === 'pending' && $canDecide) {
                    action_button('leave.decide', 'Approve', ['id' => $l['id'], 'status' => 'approved']);
                    action_button('leave.decide', 'Decline', ['id' => $l['id'], 'status' => 'rejected']);
                }
                // Employees may withdraw only their own pending requests; HR can also cancel upcoming approved leave.
                $canCancel = is_admin($state['user'])
                    ? ($l['status'] === 'pending' || ($l['status'] === 'approved' && $l['end'] >= $state['today']))
                    : ($l['status'] === 'pending' && $l['empId'] === $state['user']['employeeId']);
                if ($canCancel) {
                    confirm_action('leave.cancel', 'Cancel request', 'Remove this leave request?', ['id' => $l['id']]);
                }
            },
        ];
    }, $leaves));
}

function leave_balances(array $state, int $employeeId): void
{
    $year = substr($state['today'], 0, 4);
    $balances = [];
    foreach ($state['leaves'] as $leave) {
        if ($leave['empId'] !== $employeeId || strpos($leave['start'], $year) !== 0) continue;
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

