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
        confirm_action('employee.delete', 'Delete employee', 'This also deletes the employee’s attendance and leave records.', ['id' => $id]);
        echo '</section>';
        card('Leave history and balances');
        leave_balances($state, $id);
        if ($employee['status'] === 'Active') leave_form([$id => employee_name($employee)], true);
        leave_table($state, array_filter($state['leaves'], fn($l) => $l['empId'] === $id));
        echo '</section>';
        return;
    }

    $todayStatuses = employee_today_statuses($state);
    $list = employee_list($state, $todayStatuses);
    $filters = array_intersect_key($_GET, array_flip(['q', 'department', 'status', 'type', 'todayStatus']));
    $total = count($list);
    $pageSize = 9;
    $pageCount = max(1, (int) ceil($total / $pageSize));
    $pageNumber = max(1, min($pageCount, (int) ($_GET['p'] ?? 1)));
    $offset = ($pageNumber - 1) * $pageSize;
    $visible = array_slice($list, $offset, $pageSize);
    $badges = ['office' => ['In office', 'ok'], 'remote' => ['Remote', ''], 'away' => ['Away', 'warn'], 'leave' => ['On leave', 'warn'], 'none' => ['No record', 'plain'], 'offboarded' => ['Offboarded', 'plain']];
    $active = count(array_filter($state['employees'], fn($e) => $e['status'] === 'Active'));
    ?>
    <div class="page-head">
        <div><h1>Employees</h1><p><?= $active ?> active employee<?= $active === 1 ? '' : 's' ?> across <?= count(DEPARTMENTS) ?> departments.</p></div>
        <div class="actions">
            <a class="btn" id="employee-export" href="<?= h(page_url('employees', ['export' => 1] + $filters)) ?>"><?= icon('download') ?> Export CSV</a>
            <?php add_employee_button('employees', array_intersect_key($_GET, ['p' => true]) + $filters); ?>
        </div>
    </div>
    <section class="card web-directory" id="employee-directory">
        <form class="card-body toolbar" method="get">
            <input type="hidden" name="page" value="employees">
            <input class="input grow" name="q" type="search" placeholder="Search by name, email or title" aria-label="Search by name, email or title" value="<?= h($_GET['q'] ?? '') ?>">
            <?php foreach ([
                ['department', 'Department', ['' => 'All departments'] + options(DEPARTMENTS), ''],
                ['type', 'Employment type', ['' => 'All types'] + options(EMP_TYPES), ''],
                ['status', 'Employment status', ['' => 'Everyone'] + options(['Active', 'Offboarded']), 'Active'],
                ['todayStatus', 'Today’s attendance', ['' => 'Any status today'] + array_map(fn($badge) => $badge[0], $badges), ''],
            ] as [$name, $label, $items, $default]): ?>
                <select class="input" name="<?= h($name) ?>" aria-label="<?= h($label) ?>">
                    <?php foreach ($items as $value => $text): ?>
                        <option value="<?= h($value) ?>"<?= ($_GET[$name] ?? $default) === $value ? ' selected' : '' ?>><?= h($text) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endforeach; ?>
            <span class="muted" id="employee-count" role="status"><?= count($list) ?> <?= count($list) === 1 ? 'person' : 'people' ?></span>
        </form>
        <div id="employee-results">
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Name</th><th>Job title</th><th>Department</th><th>Type</th><th>Start date</th><th>Today</th><th class="num">Actions</th></tr></thead>
                <tbody>
                    <?php if (!$list): ?><tr><td colspan="7"><div class="empty"><strong>No one matches these filters</strong></div></td></tr><?php endif; ?>
                    <?php foreach ($visible as $person):
                        $name = employee_name($person);
                        $profile = page_url('employees', ['id' => $person['id']]);
                        [$badge, $style] = $badges[$todayStatuses[$person['id']] ?? 'none'] ?? $badges['none'];
                    ?>
                        <tr>
                            <td><a class="who" href="<?= h($profile) ?>"><span class="avatar"><?= h(initials($name)) ?></span><span><strong><?= h($name) ?></strong><small><?= h($person['email']) ?></small></span></a></td>
                            <td><?= h($person['title']) ?></td>
                            <td><?= h($person['department']) ?></td>
                            <td><?= h($person['type']) ?></td>
                            <td class="tabular"><?= h((new DateTimeImmutable($person['startDate']))->format('M j, Y')) ?></td>
                            <td><span class="badge <?= h($style) ?>"><?= h($badge) ?></span></td>
                            <td class="actions"><a class="sq" href="<?= h(page_url('employees', ['edit' => $person['id']] + array_intersect_key($_GET, ['p' => true]) + $filters)) ?>" data-edit-employee="<?= h(json_encode($person, JSON_INVALID_UTF8_SUBSTITUTE)) ?>" aria-label="<?= h("Edit $name") ?>" title="<?= h("Edit $name") ?>"><?= icon('edit', 14) ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pageCount > 1): ?>
            <nav class="card-foot" aria-label="Employee directory pages">
                <span>Showing <?= $offset + 1 ?>–<?= min($offset + $pageSize, $total) ?> of <?= $total ?> employees</span>
                <div class="toolbar">
                    <?php if ($pageNumber > 1) link_to('employees', 'Previous', ['p' => $pageNumber - 1] + $filters, 'btn sm'); ?>
                    <span>Page <?= $pageNumber ?> of <?= $pageCount ?></span>
                    <?php if ($pageNumber < $pageCount) link_to('employees', 'Next', ['p' => $pageNumber + 1] + $filters, 'btn sm'); ?>
                </div>
            </nav>
        <?php endif; ?>
        </div>
    </section>
    <script src="<?= h(versioned_asset('assets/js/employee-search.js')) ?>" defer></script>
    <?php
}

employees_view($state);
