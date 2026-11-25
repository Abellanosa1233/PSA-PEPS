<?php
declare(strict_types=1);

function leave_form(array $employees, bool $allowApproval = false): void
{
    if (!$employees) return;
    ?>
    <button class="btn primary" type="button" data-open-leave-request aria-haspopup="dialog" aria-controls="leave-request-dialog">Request time off</button>
    <dialog class="modal wide leave-request-dialog" id="leave-request-dialog" aria-labelledby="leave-request-title">
        <form method="post" class="leave-request-form">
            <?php hidden_inputs(['_action' => 'leave.create', '_token' => $_SESSION['web_csrf'], '_shared_form' => 'leave.create']); ?>
            <div class="modal-head">
                <div><h2 id="leave-request-title">Request time off</h2><p>Choose an employee, leave type, and dates.</p></div>
                <button class="sq" type="button" data-close-leave-request aria-label="Close"><?= icon('x', 14) ?></button>
            </div>
            <div class="modal-body">
                <div class="form-grid">
                    <?php
                    choice('empId', 'Employee', $employees);
                    choice('type', 'Leave type', options(LEAVE_TYPES));
                    field('start', 'First day', today(), 'date');
                    field('end', 'Last day', today(), 'date');
                    echo '<div class="full">';
                    field('reason', 'Reason', '', 'text', false);
                    echo '</div>';
                    if ($allowApproval) echo '<label class="full leave-approval"><input type="checkbox" name="approveNow" value="1"> Approve immediately</label>';
                    ?>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" type="button" data-close-leave-request>Cancel</button>
                <button class="btn primary" type="submit">Submit request</button>
            </div>
        </form>
    </dialog>
    <script src="<?= h(versioned_asset('assets/js/leave-request-dialog.js')) ?>" defer></script>
    <?php
}

function leave_table(array $state, array $leaves, bool $canDecide = true, bool $compact = false, bool $employeeOnly = false): void
{
    $names = employee_options($state['employees']);
    if ($employeeOnly) {
        web_table(['Type', 'Dates', 'Days', 'Reason', 'Status', 'Actions'], array_map(function ($l) use ($state, $canDecide) {
            return [
                $l['type'],
                function () use ($l) { echo h($l['start'] . ' - ' . $l['end']); },
                (int) $l['days'],
                $l['reason'] !== '' ? $l['reason'] : '—',
                function () use ($l) { return status_badge($l['status']); },
                function () use ($l, $state, $canDecide) {
                    if ($l['status'] === 'pending' && $canDecide) {
                        action_button('leave.decide', 'Approve', ['id' => $l['id'], 'status' => 'approved']);
                        action_button('leave.decide', 'Decline', ['id' => $l['id'], 'status' => 'rejected']);
                    }
                    if ($l['status'] === 'pending' || ($l['status'] === 'approved' && $l['end'] >= $state['today'])) {
                        confirm_action('leave.cancel', 'Cancel', 'Cancel this leave request?', ['id' => $l['id']]);
                    }
                },
            ];
        }, $leaves));
        return;
    }
    if ($compact) {
        web_table(['Employee', 'Leave type', 'Dates', 'Actions'], array_map(function ($l) use ($names) {
            return [
                function () use ($names, $l) { return link_to('employees', $names[$l['empId']] ?? 'Former employee', ['id' => $l['empId']]); },
                $l['type'],
                function () use ($l) {
                    echo h($l['start'] . ' – ' . $l['end']) . '<span class="sub">' . (int) $l['days'] . ' day(s)</span>';
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
            $names[$l['empId']] ?? 'Former employee', $l['type'], $l['start'] . ' – ' . $l['end'], $l['days'], $l['reason'], function () use ($l) { return status_badge($l['status']); },
            function () use ($l, $state, $canDecide) {
                if ($l['status'] === 'pending' && $canDecide) {
                    action_button('leave.decide', 'Approve', ['id' => $l['id'], 'status' => 'approved']);
                    action_button('leave.decide', 'Decline', ['id' => $l['id'], 'status' => 'rejected']);
                }
                if ($l['status'] === 'pending' || ($l['status'] === 'approved' && $l['end'] >= $state['today'])) {
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

