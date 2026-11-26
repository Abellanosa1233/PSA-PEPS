<?php
declare(strict_types=1);

function employees_view(array $state): void
{
    $employees = array_column($state['employees'], null, 'id');
    $id = (int) ($_GET['id'] ?? 0);
    $employee = $employees[$id] ?? [];
    if ($employee) {
        echo '<div class="employee-profile">';
        link_to('employees', 'Back to employees');
        echo '<div class="employee-profile-heading"><div class="employee-profile-person"><span class="avatar lg">' . h(initials(employee_name($employee))) . '</span><div><h1>' . h(employee_name($employee)) . '</h1><p>' . h($employee['title'] . ' · ' . $employee['department']) . '</p></div></div>';
        echo '<div class="employee-profile-heading-actions"><button class="btn primary" type="button" data-open-leave-history>View leave history</button>';
        if ($employee['status'] === 'Active') leave_form([$id => employee_name($employee)], true);
        echo '</div></div>';
        echo '<div class="employee-profile-grid">';
        employee_form($state, $employee);
        echo '<div class="employee-profile-side">';
        card('Employment status', 'employee-profile-status');
        if ($employee['status'] === 'Active') {
            post_form('employee.status', ['id' => $id, 'status' => 'Offboarded']);
            field('endDate', 'Last working day', $state['today'], 'date');
            end_form('Offboard');
        } else {
            action_button('employee.status', 'Reactivate', ['id' => $id, 'status' => 'Active']);
        }
        confirm_action('employee.delete', 'Delete employee', 'This also deletes the employee’s attendance and leave records, and their sign-in access.', ['id' => $id]);
        echo '</section>';
        dtr_card_form($id, false);
        hr_personal_details_card($employee);
        hr_employee_documents_card($state, $employee);
        card('Leave balance', 'employee-profile-balances');
        leave_balances($state, $id);
        echo '</section></div>';
        $employeeLeaves = array_values(array_filter($state['leaves'], function ($l) use ($id) { return $l['empId'] === $id; }));
        echo '</div></div>';

        $leaveSearch = trim((string) ($_GET['leaveSearch'] ?? ''));
        $leaveFrom = trim((string) ($_GET['leaveFrom'] ?? ''));
        $leaveTo = trim((string) ($_GET['leaveTo'] ?? ''));
        $leaveType = trim((string) ($_GET['leaveType'] ?? ''));
        $leaveStatus = trim((string) ($_GET['leaveStatus'] ?? ''));
        $filteredLeaves = array_values(array_filter($employeeLeaves, function ($l) use ($leaveSearch, $leaveFrom, $leaveTo, $leaveType, $leaveStatus) {
            if ($leaveType && $l['type'] !== $leaveType) return false;
            if ($leaveStatus && $l['status'] !== $leaveStatus) return false;
            if ($leaveFrom && $l['end'] < $leaveFrom) return false;
            if ($leaveTo && $l['start'] > $leaveTo) return false;
            if ($leaveSearch) {
                $searchText = strtolower($l['type'] . ' ' . ($l['reason'] ?? '') . ' ' . $l['status'] . ' ' . $l['start'] . ' ' . $l['end']);
                if (strpos($searchText, strtolower($leaveSearch)) === false) return false;
            }
            return true;
        }));
        $history = paginate($filteredLeaves, 'leavePage');
        ?>
        <dialog class="modal wide leave-history-dialog" id="leave-history-dialog" aria-labelledby="leave-history-title">
            <div class="modal-head">
                <div><h2 id="leave-history-title">Leave history</h2><p><?= h(employee_name($employee)) ?></p></div>
                <button class="sq" type="button" data-close-leave-history aria-label="Close"><?= icon('x', 14) ?></button>
            </div>
            <form class="leave-history-filters" method="get" action="<?= h(page_url('employees', ['id' => $id])) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <label class="field leave-history-search"><span>Search</span><input class="input" type="search" name="leaveSearch" value="<?= h($leaveSearch) ?>" placeholder="Search type, reason or status"></label>
                <label class="field"><span>From</span><input class="input" type="date" name="leaveFrom" value="<?= h($leaveFrom) ?>"></label>
                <label class="field"><span>To</span><input class="input" type="date" name="leaveTo" value="<?= h($leaveTo) ?>"></label>
                <label class="field"><span>Leave type</span><select class="input" name="leaveType"><option value="">All types</option><?php foreach (LEAVE_TYPES as $type): ?><option value="<?= h($type) ?>"<?= $leaveType === $type ? ' selected' : '' ?>><?= h($type) ?></option><?php endforeach; ?></select></label>
                <label class="field leave-history-status"><span>Status</span><select class="input" name="leaveStatus"><option value="">All statuses</option><?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $status): ?><option value="<?= h($status) ?>"<?= $leaveStatus === $status ? ' selected' : '' ?>><?= h(ucfirst($status)) ?></option><?php endforeach; ?></select></label>
                <div class="leave-history-filter-actions"><button class="btn primary" type="submit">Apply filters</button></div>
            </form>
            <div class="modal-body leave-history-body">
                <?php leave_table($state, $history['rows'], true, false, true); ?>
            </div>
            <?php pagination($history, 'employees', [
                'id' => $id,
                'leaveSearch' => $leaveSearch,
                'leaveFrom' => $leaveFrom,
                'leaveTo' => $leaveTo,
                'leaveType' => $leaveType,
                'leaveStatus' => $leaveStatus,
            ], 'leave requests', 'leavePage'); ?>
        </dialog>
        <script>
        (function () {
            var dialog = document.getElementById('leave-history-dialog');
            var openButton = document.querySelector('[data-open-leave-history]');
            var closeButton = dialog && dialog.querySelector('[data-close-leave-history]');
            if (!dialog || !openButton || typeof dialog.showModal !== 'function') return;
            openButton.addEventListener('click', function () { dialog.showModal(); });
            closeButton.addEventListener('click', function () { dialog.close(); });
            dialog.addEventListener('click', function (event) {
                if (event.target === dialog) dialog.close();
            });
            <?php if ($leaveSearch || $leaveFrom || $leaveTo || $leaveType || $leaveStatus || (int) ($_GET['leavePage'] ?? 0) > 1): ?>
            dialog.showModal();
            <?php endif; ?>
        }());
        </script>
        <?php
        return;
    }

    $todayStatuses = employee_today_statuses($state);
    $list = employee_list($state, $todayStatuses);
    $searchIndex = array_values(array_map(function ($person) use ($todayStatuses) {
        return [
            'name' => employee_name($person),
            'email' => $person['email'],
            'title' => $person['title'],
            'department' => $person['department'],
            'type' => $person['type'],
            'status' => $person['status'],
            'todayStatus' => $todayStatuses[$person['id']] ?? 'none',
            'profile' => page_url('employees', ['id' => $person['id']]),
        ];
    }, $state['employees']));
    $filters = array_intersect_key($_GET, array_flip(['q', 'department', 'status', 'type', 'todayStatus']));
    $pagination = paginate($list, 'p', 10);
    $accounts = (array) ($state['accounts'] ?? []);
    $attendanceOptions = ['present' => 'Present today', 'office' => 'In office', 'remote' => 'Remote', 'away' => 'Away', 'leave' => 'On leave', 'none' => 'No record', 'offboarded' => 'Offboarded'];
    $active = count(array_filter($state['employees'], function ($e) { return $e['status'] === 'Active'; }));
    ?>
    <div class="page-head">
        <div><h1>Employees</h1><p><?= $active ?> active employee<?= $active === 1 ? '' : 's' ?> across <?= count(DEPARTMENTS) ?> departments.</p></div>
        <div class="actions">
            <a class="btn" id="employee-export" href="<?= h(page_url('employees', ['export' => 'xlsx'] + $filters)) ?>"><?= icon('download') ?> Export Excel</a>
            <details class="employee-import">
                <summary class="btn"><?= icon('file') ?> Import CSV</summary>
                <div class="employee-import-panel">
                    <strong>Import employee records</strong>
                    <p>Required: First name, Last name, Email, Job title, Department, Type, Start date, and Annual salary.</p>
                    <a href="<?= h(page_url('employees', ['template' => 1])) ?>">Download CSV template</a>
                    <?php post_form('employee.import', [], false, 'multipart/form-data'); ?>
                    <div class="field"><label for="employeeCsv">CSV file</label><input class="input" id="employeeCsv" name="employeeCsv" type="file" accept=".csv,text/csv" required></div>
                    <?php end_form('Import employees'); ?>
                    <small>Maximum 2 MB and 500 employees. The import is all-or-nothing if any row is invalid.</small>
                </div>
            </details>
            <?php add_employee_button('employees', array_intersect_key($_GET, ['p' => true]) + $filters); ?>
        </div>
    </div>
    <section class="card web-directory" id="employee-directory">
        <form class="card-body toolbar" method="get" action="<?= h(page_url('employees')) ?>">
            <div class="grow employee-search">
                <input class="input" name="q" type="search" placeholder="Search by name, email or title" aria-label="Search by name, email or title" value="<?= h($_GET['q'] ?? '') ?>">
                <div id="employee-suggestions" role="listbox" aria-label="Employee suggestions"
                    data-employees="<?= html_json($searchIndex) ?>" hidden></div>
            </div>
            <?php foreach ([
                ['department', 'Department', ['' => 'All departments'] + options(DEPARTMENTS), ''],
                ['type', 'Employment type', ['' => 'All types'] + options(array_merge(EMPLOYEE_TYPES, EMP_TYPES)), ''],
                ['status', 'Employment status', ['' => 'Everyone'] + options(['Active', 'Offboarded']), 'Active'],
                ['todayStatus', 'Today’s attendance', ['' => 'Any status today'] + $attendanceOptions, ''],
            ] as [$name, $label, $items, $default]): ?>
                <select class="input" name="<?= h($name) ?>" aria-label="<?= h($label) ?>">
                    <?php foreach ($items as $value => $text): ?>
                        <option value="<?= h($value) ?>"<?= ($_GET[$name] ?? $default) === $value ? ' selected' : '' ?>><?= h($text) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endforeach; ?>
            <?php link_to('employees', 'Clear filters', [], 'btn ghost'); ?>
            <span class="muted" id="employee-count" role="status"><?= count($list) ?> <?= count($list) === 1 ? 'person' : 'people' ?></span>
        </form>
        <div id="employee-results">
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Name</th><th>Job title</th><th>Department</th><th>Type</th><th>Start date</th><th>Today</th><th class="num">Actions</th></tr></thead>
                <tbody>
                    <?php if (!$list): ?><tr><td colspan="7"><div class="empty"><strong>No one matches these filters</strong><?php link_to('employees', 'Clear filters', [], 'btn sm'); ?></div></td></tr><?php endif; ?>
                    <?php foreach ($pagination['rows'] as $person):
                        $name = employee_name($person);
                        $profile = page_url('employees', ['id' => $person['id']]);
                    ?>
                        <tr>
                            <td><a class="who" href="<?= h($profile) ?>"><span class="avatar"><?= h(initials($name)) ?></span><span><strong><?= h($name) ?></strong><small><?= h($person['email']) ?></small></span></a></td>
                            <td><?= h($person['title']) ?></td>
                            <td><?= h($person['department']) ?></td>
                            <td><?= h($person['type']) ?></td>
                            <td class="tabular"><?= h((new DateTimeImmutable($person['startDate']))->format('M j, Y')) ?></td>
                            <td><?php status_badge($todayStatuses[$person['id']] ?? 'none'); ?></td>
                            <td class="actions"><a class="sq" href="<?= h(page_url('employees', ['edit' => $person['id']] + array_intersect_key($_GET, ['p' => true]) + $filters)) ?>" data-edit-employee="<?= html_json($person + ['loginUsername' => $accounts[$person['id']]['username'] ?? '']) ?>" aria-label="<?= h("Edit $name") ?>" title="<?= h("Edit $name") ?>"><?= icon('edit', 14) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php pagination($pagination, 'employees', $filters, 'employees'); ?>
        </div>
    </section>
    <script src="<?= h(versioned_asset('assets/js/employee-search.js')) ?>" defer></script>
    <?php
}

employees_view($state);
