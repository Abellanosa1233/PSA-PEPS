<?php
declare(strict_types=1);

function employees_view(array $state): void
{
    $employees = array_column($state['employees'], null, 'id');
    $id = (int) ($_GET['id'] ?? 0);
    $employee = $employees[$id] ?? [];
    if ($employee) {
        link_to('employees', 'Back to employees');
        employee_form($state, $employee);
        card('Employment status');
        if ($employee['status'] === 'Active') {
            post_form('employee.status', ['id' => $id, 'status' => 'Offboarded']);
            field('endDate', 'Last working day', $state['today'], 'date');
            end_form('Offboard');
        } else {
            action_button('employee.status', 'Reactivate', ['id' => $id, 'status' => 'Active']);
        }
        confirm_action('employee.delete', 'Delete employee', 'This also deletes the employee’s attendance and leave records, and their sign-in access.', ['id' => $id]);
        echo '</section>';
        employee_access_card($state, $employee);
        dtr_card_form($id, false);
        card('Leave history and balances');
        leave_balances($state, $id);
        if ($employee['status'] === 'Active') leave_form([$id => employee_name($employee)], true);
        $history = paginate(array_filter($state['leaves'], function ($l) use ($id) { return $l['empId'] === $id; }), 'leavePage');
        leave_table($state, $history['rows']);
        pagination($history, 'employees', ['id' => $id], 'requests', 'leavePage');
        echo '</section>';
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
    $pagination = paginate($list);
    $attendanceOptions = ['office' => 'In office', 'remote' => 'Remote', 'away' => 'Away', 'leave' => 'On leave', 'none' => 'No record', 'offboarded' => 'Offboarded'];
    $active = count(array_filter($state['employees'], function ($e) { return $e['status'] === 'Active'; }));
    ?>
    <div class="page-head">
        <div><h1>Employees</h1><p><?= $active ?> active employee<?= $active === 1 ? '' : 's' ?> across <?= count(DEPARTMENTS) ?> departments.</p></div>
        <div class="actions">
            <a class="btn" id="employee-export" href="<?= h(page_url('employees', ['export' => 1] + $filters)) ?>"><?= icon('download') ?> Export CSV</a>
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
                ['type', 'Employment type', ['' => 'All types'] + options(EMP_TYPES), ''],
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
                            <td class="actions"><a class="sq" href="<?= h(page_url('employees', ['edit' => $person['id']] + array_intersect_key($_GET, ['p' => true]) + $filters)) ?>" data-edit-employee="<?= html_json($person) ?>" aria-label="<?= h("Edit $name") ?>" title="<?= h("Edit $name") ?>"><?= icon('edit', 14) ?></a></td>
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

/* HR controls for an employee's own login to the employee workspace. */
function employee_access_card(array $state, array $employee): void
{
    $id = $employee['id'];
    $account = ((array) ($state['accounts'] ?? []))[$id] ?? null;
    card('Sign-in access');
    echo '<div class="card-body access-card">';
    if ($account) {
        echo '<p>' . h($employee['firstName']) . ' signs in as <strong>' . h($account['username']) . '</strong>';
        if ($account['mustChangePassword']) echo ' <span class="badge warn">Temporary password</span>';
        echo '</p><p class="muted">They see only their own workspace: attendance, time off, Special and Travel Orders, and payslips'
            . ($employee['status'] === 'Active' ? '' : '. Sign-in is blocked while they are offboarded') . '.</p><div class="toolbar">';
        echo '<details class="form-disclosure" data-form-dialog="form"><summary class="btn">Reset password</summary>';
        post_form('account.password', ['empId' => $id]);
        field('password', 'New temporary password', '', 'password', true, '', ['autocomplete' => 'new-password', 'minlength' => '8', 'maxlength' => '72']);
        echo '<p class="hint">Give this to ' . h($employee['firstName']) . ' privately. They’ll be asked to change it after signing in.</p>';
        end_form('Set temporary password');
        echo '</details>';
        confirm_action('account.remove', 'Remove access', 'Remove ' . employee_name($employee) . '’s sign-in access? Their HR records are kept.', ['empId' => $id]);
        echo '</div>';
    } elseif ($employee['status'] !== 'Active') {
        echo '<p class="muted">Offboarded employees can’t be given sign-in access.</p>';
    } else {
        $suggested = strtolower(preg_replace('/[^a-z0-9._-]/i', '', strstr($employee['email'], '@', true) ?: ''));
        echo '<p class="muted">Let ' . h($employee['firstName']) . ' sign in to their own workspace to clock in and out, request time off and Special or Travel Orders, and view payslips. They won’t see anyone else’s records.</p>';
        echo '<details class="form-disclosure" data-form-dialog="form"><summary class="btn primary">Give sign-in access</summary>';
        post_form('account.create', ['empId' => $id]);
        field('username', 'Username', $suggested, 'text', true, '', ['autocomplete' => 'off', 'minlength' => '3', 'maxlength' => '60', 'pattern' => '[A-Za-z0-9._-]+']);
        field('password', 'Temporary password', '', 'password', true, '', ['autocomplete' => 'new-password', 'minlength' => '8', 'maxlength' => '72']);
        echo '<p class="hint">At least 8 characters. Share it privately; they’ll be asked to choose their own after signing in.</p>';
        end_form('Create login');
        echo '</details>';
    }
    echo '</div></section>';
}

employees_view($state);
