<?php
declare(strict_types=1);

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function html_json(array $value): string
{
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        return h(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    $json = json_encode($value);
    if ($json === false && json_last_error() === JSON_ERROR_UTF8) {
        array_walk_recursive($value, function (&$item): void {
            if (is_string($item)) $item = mb_scrub($item, 'UTF-8');
        });
        $json = json_encode($value);
    }
    return h($json);
}

function page_url(string $page, array $query = []): string
{
    $route = $page === 'preferences' ? 'settings' : $page;
    $parameters = http_build_query($query);
    return $route . ($parameters === '' ? '' : '?' . $parameters);
}

function versioned_asset(string $path): string
{
    $version = hash_file('sha256', __DIR__ . '/../../' . $path);
    return $path . '?v=' . substr($version, 0, 12);
}

function web_message(string $error, string $notice): void
{
    if ($error !== '') echo '<p class="card web-message" role="alert">' . h($error) . '</p>';
    if ($notice !== '') echo '<p class="card web-message" role="status">' . h($notice) . '</p>';
}

function action_success_message(string $action, array $input): string
{
    $messages = [
        'install' => 'Database installed.',
        'setup' => 'Administrator account created.',
        'login' => 'Signed in successfully.',
        'logout' => 'Signed out successfully.',
        'employee.save' => empty($input['id']) ? 'Employee added successfully.' : 'Employee updated.',
        'employee.status' => ($input['status'] ?? '') === 'Active' ? 'Employee reactivated.' : 'Employee offboarded.',
        'employee.delete' => 'Employee deleted.',
        'leave.create' => empty($input['approveNow']) ? 'Leave request submitted.' : 'Leave request approved.',
        'leave.decide' => ($input['status'] ?? '') === 'approved' ? 'Leave request approved.' : 'Leave request declined.',
        'leave.cancel' => 'Leave request canceled.',
        'employee.request.create' => 'Request submitted for review.',
        'employee.request.decide' => ($input['status'] ?? '') === 'approved' ? 'Request approved.' : 'Request declined.',
        'employee.request.cancel' => 'Request canceled.',
        'attendance.set' => 'Attendance updated.',
        'attendance.fill' => 'Attendance fill completed.',
        'attendance.clock' => in_array($input['slot'] ?? '', ['amIn', 'pmIn'], true) ? 'Clock-in recorded.' : 'Clock-out recorded.',
        'event.save' => 'Schedule event saved.',
        'event.delete' => 'Schedule event removed.',
        'position.save' => 'Recruitment role saved.',
        'position.status' => ($input['status'] ?? '') === 'open' ? 'Recruitment role reopened.' : 'Recruitment role closed.',
        'position.delete' => 'Recruitment role deleted.',
        'candidate.save' => 'Candidate added.',
        'candidate.move' => 'Candidate stage updated.',
        'settings.save' => 'Organization settings saved.',
        'workspace.view' => 'Workspace selection updated.',
        'profile.save' => 'Profile saved.',
        'password.change' => 'Password changed.',
        'admin.create' => 'Administrator account added.',
        'theme' => 'Appearance saved.',
        'demo.reset' => 'Demo data reset.',
        'account.create' => 'Sign-in access created. Share the temporary password privately.',
        'account.password' => 'Temporary password set.',
        'account.remove' => 'Sign-in access removed.',
        'announcement.save' => 'Company update posted. Employees see it on their Overview.',
        'announcement.delete' => 'Company update removed.',
        'correction.create' => 'Correction sent to HR. You’ll be notified when it’s reviewed.',
        'correction.cancel' => 'Correction request cancelled.',
        'correction.decide' => ($input['status'] ?? '') === 'approved' ? 'Correction approved and attendance updated.' : 'Correction declined.',
        'profile.personal.save' => 'Personal details saved.',
        'profile.emergency.save' => 'Emergency contact saved.',
        'document.upload' => 'Document shared.',
        'document.delete' => 'Document removed.',
        'document.acknowledge' => 'Thanks — your acknowledgement is recorded.',
    ];
    return $messages[$action] ?? 'Changes saved.';
}

function post_form(string $action, array $values = [], bool $inline = false, string $enctype = ''): void
{
    echo '<form method="post" class="' . ($inline ? 'web-inline' : 'web-fields') . '"' .
        ($enctype !== '' ? ' enctype="' . h($enctype) . '"' : '') . '>';
    hidden_inputs(['_action' => $action, '_token' => $_SESSION['web_csrf'] ?? ''] + $values);
}

function hidden_inputs(array $values): void
{
    foreach ($values as $name => $value) {
        echo '<input type="hidden" name="' . h($name) . '" value="' . h($value) . '">';
    }
}

function end_form(string $label): void
{
    echo '<button class="btn primary" type="submit">' . h($label) . '</button></form>';
}

function action_button(string $action, string $label, array $values = []): void
{
    post_form($action, $values, true);
    end_form($label);
}

function field(string $name, string $label, $value = '', string $type = 'text', bool $required = true, string $error = '', array $attributes = []): void
{
    $errorId = 'field-error-' . $name;
    $passwordToggle = $type === 'password' && !empty($attributes['passwordToggle']);
    $inputId = $passwordToggle ? 'signin-password' : '';
    echo $passwordToggle
        ? '<div class="field"><label for="' . h($inputId) . '">' . h($label) . '</label><div class="password-field">'
        : '<label class="field"><span>' . h($label) . '</span>';
    echo '<input class="input" ' . ($passwordToggle ? 'id="' . h($inputId) . '" ' : '') . 'name="' . h($name)
        . '" type="' . h($type) . '" value="' . h($value) . '"' . ($required ? ' required' : '')
        . ($type === 'number' ? ' step="any"' : '')
        . ($error !== '' ? ' aria-invalid="true" aria-describedby="' . h($errorId) . '"' : '');
    foreach ($attributes as $attribute => $attributeValue) {
        if (in_array($attribute, ['autocomplete', 'minlength', 'maxlength', 'pattern'], true)) {
            echo ' ' . $attribute . '="' . h($attributeValue) . '"';
        }
    }
    echo '>';
    if ($passwordToggle) {
        echo '<button class="password-toggle" type="button" data-password-toggle aria-controls="' . h($inputId)
            . '" aria-label="Show password" aria-pressed="false" title="Show password"><span class="password-eye">'
            . icon('eye', 18) . '</span><span class="password-eye-off">' . icon('eyeOff', 18) . '</span></button></div>';
    }
    if ($error !== '') echo '<span class="field-error" id="' . h($errorId) . '">' . h($error) . '</span>';
    echo $passwordToggle ? '</div>' : '</label>';
}

function choice(string $name, string $label, array $options, $selected = ''): void
{
    echo '<label class="field"><span>' . h($label) . '</span><select class="input" name="' . h($name) . '">';
    foreach ($options as $value => $text) {
        echo '<option value="' . h($value) . '"' . ((string) $selected === (string) $value ? ' selected' : '') . '>' . h($text) . '</option>';
    }
    echo '</select></label>';
}

function options(array $values): array
{
    return array_combine($values, $values);
}

function card(string $title, string $class = ''): void
{
    echo '<section class="card web-card' . ($class !== '' ? ' ' . h($class) : '') . '"><h2>' . h($title) . '</h2>';
}

function web_table(array $headers, array $rows): void
{
    echo '<div class="table-wrap"><table class="data"><thead><tr>';
    foreach ($headers as $header) echo '<th scope="col">' . h($header) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $index => $cell) {
            echo ($headers[$index] ?? '') === 'Actions' ? '<td class="actions">' : '<td>';
            if ($cell instanceof Closure) $cell();
            else echo h($cell);
            echo '</td>';
        }
        echo '</tr>';
    }
    if (!$rows) echo '<tr><td colspan="' . count($headers) . '">No records found.</td></tr>';
    echo '</tbody></table></div>';
}

function paginate(array $rows, string $parameter = 'p', int $size = 9): array
{
    $total = count($rows);
    $pages = max(1, (int) ceil($total / $size));
    $current = max(1, min($pages, (int) ($_GET[$parameter] ?? 1)));
    $offset = ($current - 1) * $size;
    return [
        'rows' => array_slice($rows, $offset, $size), 'current' => $current, 'pages' => $pages,
        'first' => $total ? $offset + 1 : 0, 'last' => min($offset + $size, $total), 'total' => $total,
    ];
}

function pagination(array $list, string $page, array $query, string $label, string $parameter = 'p'): void
{
    if (!$list['total']) return;
    $current = $list['current'];
    $pages = $list['pages'];
    ?>
    <nav class="card-foot list-pagination" aria-label="<?= h(ucfirst($label) . ' pages') ?>">
        <span>Showing <?= $list['first'] ?>–<?= $list['last'] ?> of <?= $list['total'] ?> <?= h($label) ?></span>
        <?php if ($pages > 1): ?>
            <div class="toolbar">
                <?php if ($current > 1) link_to($page, 'Previous', [$parameter => $current - 1] + $query, 'btn sm'); ?>
                <?php
                if ($pages <= 7) {
                    $numbers = range(1, $pages);
                } else {
                    $numbers = [1, 2, 3];
                    $windowStart = max(4, $current - 1);
                    $windowEnd = min($pages - 1, $current + 1);
                    if ($windowStart <= $windowEnd) $numbers = array_merge($numbers, range($windowStart, $windowEnd));
                    $numbers[] = $pages;
                }
                $previous = 0;
                foreach ($numbers as $number):
                    if ($previous && $number > $previous + 1) echo '<span aria-hidden="true">…</span>';
                    $previous = $number;
                    if ($number === $current): ?>
                        <span class="btn sm primary" aria-current="page" aria-label="Page <?= $number ?>"><?= $number ?></span>
                    <?php else: ?>
                        <a class="btn sm" href="<?= h(page_url($page, [$parameter => $number] + $query)) ?>" aria-label="Page <?= $number ?>"><?= $number ?></a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($current < $pages) link_to($page, 'Next', [$parameter => $current + 1] + $query, 'btn sm'); ?>
            </div>
        <?php endif; ?>
    </nav>
    <?php
}

function status_badge(string $status): void
{
    $badges = [
        'approved' => ['Approved', 'ok'], 'pending' => ['Pending', 'warn'], 'rejected' => ['Declined', 'bad'],
        'office' => ['In office', 'ok'], 'remote' => ['Remote', ''], 'away' => ['Away', 'warn'],
        'leave' => ['On leave', 'warn'], 'offboarded' => ['Offboarded', 'plain'],
        'paid' => ['Paid', 'ok'], 'draft' => ['Draft', 'warn'],
    ];
    [$label, $style] = $badges[$status] ?? ['No record', 'plain'];
    echo '<span class="badge ' . h($style) . '">' . h($label) . '</span>';
}

function employee_name(array $employee): string
{
    return $employee['firstName'] . ' ' . $employee['lastName'];
}

function employee_options(array $employees): array
{
    return array_map('employee_name', array_column($employees, null, 'id'));
}

function link_to(string $page, string $label, array $query = [], string $class = ''): void
{
    echo '<a class="' . h($class) . '" href="' . h(page_url($page, $query)) . '">' . h($label) . '</a>';
}

function money($amount, array $state): string
{
    return $state['settings']['currency'] . ' ' . number_format((float) $amount, 2);
}

function selected_date(string $default): string
{
    $value = (string) ($_GET['date'] ?? $default);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $default;
}

function employee_today_statuses(array $state): array
{
    $statuses = [];
    foreach (attendance_records($state, $state['today']) as [$employee, $record, $onLeave]) {
        $statuses[$employee['id']] = $onLeave ? 'leave' : ($record['status'] ?: 'none');
    }
    foreach ($state['employees'] as $employee) {
        if ($employee['status'] === 'Offboarded') $statuses[$employee['id']] = 'offboarded';
    }
    return $statuses;
}

function employee_list(array $state, ?array $todayStatuses = null): array
{
    $search = trim((string) ($_GET['q'] ?? ''));
    $department = (string) ($_GET['department'] ?? '');
    $status = (string) ($_GET['status'] ?? 'Active');
    $type = (string) ($_GET['type'] ?? '');
    $todayStatus = (string) ($_GET['todayStatus'] ?? '');
    if ($todayStatus !== '' && $todayStatuses === null) $todayStatuses = employee_today_statuses($state);
    return array_values(array_filter($state['employees'], function ($e) use ($search, $department, $status, $type, $todayStatus, $todayStatuses) { return ($search === '' || mb_stripos(employee_name($e) . ' ' . $e['email'] . ' ' . $e['title'], $search) !== false)
        && ($department === '' || $e['department'] === $department)
        && ($status === '' || $e['status'] === $status)
        && ($type === '' || $e['type'] === $type)
        && ($todayStatus === '' || ($todayStatus === 'present'
            ? in_array($todayStatuses[$e['id']] ?? 'none', ['office', 'remote'], true)
            : ($todayStatuses[$e['id']] ?? 'none') === $todayStatus)); }
    ));
}

function attendance_records(array $state, string $date): array
{
    $recorded = (array) ($state['attendance']->{$date} ?? []);
    $onLeave = [];
    foreach ($state['leaves'] as $leave) {
        if ($leave['status'] === 'approved' && $leave['start'] <= $date && $leave['end'] >= $date) {
            $onLeave[$leave['empId']] = true;
        }
    }
    $records = [];
    foreach ($state['employees'] as $employee) {
        if ($employee['startDate'] > $date || ($employee['endDate'] && $employee['endDate'] < $date)) continue;
        $id = $employee['id'];
        $records[] = [$employee, $recorded[$id] ?? ['status' => '', 'in' => '', 'out' => ''], isset($onLeave[$id])];
    }
    return $records;
}

function department_report(array $state, string $month): array
{
    $end = period_end($month);
    $groups = [];
    foreach ($state['employees'] as $employee) {
        if ($employee['startDate'] <= $end && (!$employee['endDate'] || $employee['endDate'] >= $end)) {
            $groups[$employee['department']][] = $employee['salary'];
        }
    }
    ksort($groups);
    $rows = [];
    foreach ($groups as $department => $salaries) {
        $rows[] = [$department, count($salaries), round(array_sum($salaries) / count($salaries), 2)];
    }
    return $rows;
}

function report_month(array $state): string
{
    $month = (string) ($_GET['month'] ?? '');
    return preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $month) ? $month : substr($state['today'], 0, 7);
}

function report_types(): array
{
    return [
        'headcount' => 'Headcount by department',
        'hiring' => 'Hiring and departures',
        'attendance' => 'Attendance summary',
        'leave' => 'Approved time off',
    ];
}

function selected_report_type(): string
{
    $type = $_GET['report'] ?? 'headcount';
    if (!is_string($type)) return 'headcount';
    return array_key_exists($type, report_types()) ? $type : 'headcount';
}

function report_range(array $state): array
{
    $defaultTo = $state['today'];
    $defaultFrom = substr($defaultTo, 0, 7) . '-01';
    $fromInput = $_GET['from'] ?? $defaultFrom;
    $toInput = $_GET['to'] ?? $defaultTo;
    $from = is_string($fromInput) ? $fromInput : '';
    $to = is_string($toInput) ? $toInput : '';
    $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    $error = '';
    if (!$fromDate || $fromDate->format('Y-m-d') !== $from) {
        $from = $defaultFrom;
        $error = 'Choose a valid start date.';
    }
    if (!$toDate || $toDate->format('Y-m-d') !== $to) {
        $to = $defaultTo;
        $error = 'Choose a valid end date.';
    }
    if ($from > $to) {
        $from = $defaultFrom;
        $to = $defaultTo;
        $error = 'The start date must be on or before the end date.';
    }
    return [$from, $to, $error];
}

function descriptive_report_data(array $state, string $type, string $from, string $to): array
{
    $money = function ($amount) use ($state) { return money($amount, $state); };
    if ($type === 'headcount') {
        $groups = array_fill_keys(DEPARTMENTS, []);
        foreach ($state['employees'] as $employee) {
            if ($employee['startDate'] <= $to && (!$employee['endDate'] || $employee['endDate'] >= $to)) {
                $groups[$employee['department']][] = $employee['salary'];
            }
        }
        $rows = [];
        $totalSalary = 0;
        $total = 0;
        $chart = [];
        foreach ($groups as $department => $salaries) {
            $count = count($salaries);
            $average = $count ? round(array_sum($salaries) / $count, 2) : 0;
            $rows[] = [$department, $count, $average];
            $chart[] = [$department, $count];
            $total += $count;
            $totalSalary += array_sum($salaries);
        }
        $departments = count(array_filter($groups, function ($salaries) { return count($salaries) > 0; }));
        return [
            'title' => 'Headcount by department',
            'description' => 'Employees with an active employment period on ' . $to . ', grouped by department. Salary figures show average annual base salary.',
            'headers' => ['Department', 'Employees', 'Average annual base salary'],
            'rows' => $rows, 'chartTitle' => 'Employees by department', 'chart' => $chart, 'chartType' => 'bar', 'chartFormat' => 'count',
            'metrics' => [
                ['Employees at period end', $total, 'As of ' . $to],
                ['Departments represented', $departments, 'With at least one active employee'],
                ['Average annual base salary', $money($total ? $totalSalary / $total : 0), 'Across active employees'],
            ],
        ];
    }

    if ($type === 'hiring') {
        $groups = [];
        foreach (DEPARTMENTS as $department) $groups[$department] = ['hires' => 0, 'leavers' => 0];
        foreach ($state['employees'] as $employee) {
            if ($employee['startDate'] >= $from && $employee['startDate'] <= $to && isset($groups[$employee['department']])) {
                $groups[$employee['department']]['hires']++;
            }
            if ($employee['endDate'] && $employee['endDate'] >= $from && $employee['endDate'] <= $to && isset($groups[$employee['department']])) {
                $groups[$employee['department']]['leavers']++;
            }
        }
        $rows = [];
        $chart = [];
        $hires = $leavers = 0;
        foreach ($groups as $department => $counts) {
            $rows[] = [$department, $counts['hires'], $counts['leavers'], $counts['hires'] - $counts['leavers']];
            $chart[] = [$department, $counts['hires'], $counts['leavers']];
            $hires += $counts['hires'];
            $leavers += $counts['leavers'];
        }
        return [
            'title' => 'Hiring and departures',
            'description' => 'Employee start dates and last working dates within the selected period, grouped by department.',
            'headers' => ['Department', 'Hires', 'Departures', 'Net change'],
            'rows' => $rows, 'chartTitle' => 'Hires and departures by department', 'chart' => $chart, 'chartType' => 'grouped', 'chartFormat' => 'count',
            'metrics' => [
                ['Hires', $hires, 'Started between ' . $from . ' and ' . $to],
                ['Departures', $leavers, 'Last working day in the selected period'],
                ['Net change', $hires - $leavers, 'Hires minus departures'],
            ],
        ];
    }

    if ($type === 'attendance') {
        $groups = [];
        foreach (DEPARTMENTS as $department) $groups[$department] = ['office' => 0, 'remote' => 0, 'away' => 0];
        $records = q(
            'SELECT e.department, a.status, COUNT(*) AS total
             FROM attendance a INNER JOIN employees e ON e.id = a.emp_id
             WHERE a.work_date BETWEEN ? AND ?
             GROUP BY e.department, a.status',
            [$from, $to]
        )->fetchAll();
        foreach ($records as $record) {
            if (isset($groups[$record['department']][$record['status']])) {
                $groups[$record['department']][$record['status']] = (int) $record['total'];
            }
        }
        $rows = [];
        $chart = [];
        $office = $remote = $away = 0;
        foreach ($groups as $department => $counts) {
            $recorded = $counts['office'] + $counts['remote'] + $counts['away'];
            $rate = $recorded ? round(($counts['office'] + $counts['remote']) / $recorded * 100, 1) : 0;
            $rows[] = [$department, $counts['office'], $counts['remote'], $counts['away'], $recorded, $rate];
            $chart[] = [$department, $counts['office'], $counts['remote'], $counts['away'], $rate];
            $office += $counts['office'];
            $remote += $counts['remote'];
            $away += $counts['away'];
        }
        $recorded = $office + $remote + $away;
        $rate = $recorded ? round(($office + $remote) / $recorded * 100, 1) : 0;
        return [
            'title' => 'Attendance summary',
            'description' => 'Recorded office, remote, and away workdays between ' . $from . ' and ' . $to . '. Attendance rate is present workdays divided by recorded workdays.',
            'headers' => ['Department', 'Office days', 'Remote days', 'Away days', 'Recorded days', 'Attendance rate'],
            'rows' => $rows, 'chartTitle' => 'Attendance mix by department', 'chart' => $chart, 'chartType' => 'stacked', 'chartFormat' => 'percent',
            'metrics' => [
                ['Attendance rate', number_format($rate, 1) . '%', 'Office and remote ÷ recorded days'],
                ['Recorded workdays', $recorded, 'Across all departments'],
                ['Office / remote days', $office + $remote, $office . ' office · ' . $remote . ' remote'],
            ],
        ];
    }

    if ($type === 'leave') {
        $groups = [];
        foreach (LEAVE_TYPES as $leaveType) $groups[$leaveType] = ['requests' => 0, 'days' => 0];
        foreach ($state['leaves'] as $leave) {
            if ($leave['status'] !== 'approved' || $leave['start'] > $to || $leave['end'] < $from || !isset($groups[$leave['type']])) continue;
            $groups[$leave['type']]['requests']++;
            $groups[$leave['type']]['days'] += workdays(max($from, $leave['start']), min($to, $leave['end']));
        }
        $rows = [];
        $chart = [];
        $requests = $days = 0;
        $topType = 'None';
        $topDays = 0;
        foreach ($groups as $leaveType => $counts) {
            $rows[] = [$leaveType, $counts['requests'], $counts['days']];
            $chart[] = [$leaveType, $counts['days']];
            $requests += $counts['requests'];
            $days += $counts['days'];
            if ($counts['days'] > $topDays) { $topType = $leaveType; $topDays = $counts['days']; }
        }
        return [
            'title' => 'Approved time off',
            'description' => 'Approved leave requests overlapping ' . $from . ' through ' . $to . '. Days are counted as weekdays within the selected range.',
            'headers' => ['Leave type', 'Approved requests', 'Weekdays taken'],
            'rows' => $rows, 'chartTitle' => 'Approved weekdays by leave type', 'chart' => $chart, 'chartType' => 'donut', 'chartFormat' => 'days',
            'metrics' => [
                ['Approved leave days', $days, 'Weekdays in the selected period'],
                ['Approved requests', $requests, 'Requests overlapping the period'],
                ['Most used leave type', $topType, $topDays ? $topDays . ($topDays === 1 ? ' weekday taken' : ' weekdays taken') : 'No approved leave'],
            ],
        ];
    }

    throw new InvalidArgumentException('Unknown report type.');
}

function export_page(string $page, array $state): void
{
    switch ($page) {
        case 'employees':
            $names = employee_options($state['employees']);
            $rows = [['ID', 'First name', 'Last name', 'Email', 'Phone', 'Job title', 'Department', 'Type', 'Location', 'Start date', 'End date', 'Annual salary', 'Manager', 'Status']];
            foreach (employee_list($state) as $e) {
                $rows[] = [$e['id'], $e['firstName'], $e['lastName'], $e['email'], $e['phone'], $e['title'], $e['department'], $e['type'], $e['location'], $e['startDate'], $e['endDate'], $e['salary'], $names[$e['managerId']] ?? '', $e['status']];
            }
            if (($_GET['export'] ?? '') === 'xlsx') {
                require_once __DIR__ . '/employee-workbook.php';
                download_employee_workbook($rows);
            }
            break;
        case 'attendance':
            $rows = [['Date', 'Employee', 'Employee email', 'Department', 'Status', 'Clock in', 'Clock out']];
            $date = selected_date($state['today']);
            foreach (attendance_records($state, $date) as [$e, $record, $onLeave]) {
                $rows[] = [$date, employee_name($e), $e['email'], $e['department'], $onLeave ? 'Leave' : $record['status'], $record['in'], $record['out']];
            }
            if (($_GET['export'] ?? '') === 'xlsx') {
                require_once __DIR__ . '/attendance-workbook.php';
                download_attendance_workbook($date, $rows);
            }
            break;
        case 'reports':
            [$from, $to] = report_range($state);
            $report = descriptive_report_data($state, selected_report_type(), $from, $to);
            $rows = array_merge([$report['headers']], $report['rows']);
            break;
        default:
            return;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $page . '.csv"');
    $output = fopen('php://output', 'w');
    foreach ($rows as $row) {
        $safe = array_map(function ($v) { return is_string($v) && preg_match('/^(?:\s*[=+@-]|[\t\r\n])/', $v) ? "'" . $v : $v; }, $row);
        fputcsv($output, $safe, ',', '"', '\\');
    }
    fclose($output);
    exit;
}

function attendance_import_template(): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="attendance-import-template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Employee email', 'Status', 'Clock in', 'Clock out']);
    fclose($output);
    exit;
}

function confirm_action(string $action, string $label, string $prompt, array $values = []): void
{
    echo '<details class="form-disclosure destructive-action" data-form-dialog="confirm"><summary class="btn sm">' . h($label) . '</summary><p>' . h($prompt) . '</p>';
    action_button($action, $label, $values);
    echo '</details>';
}

function get_form(string $page): void
{
    echo '<form method="get" action="' . h(page_url($page)) . '" class="web-fields">';
}

function initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name), 3) ?: [];
    return mb_strtoupper(implode('', array_map(function ($word) { return mb_substr($word, 0, 1); }, array_slice($words, 0, 2))));
}

require __DIR__ . '/../../views/partials/icons.php';
require __DIR__ . '/../../views/partials/employee-form.php';
require __DIR__ . '/../../views/partials/leave.php';
require __DIR__ . '/../../views/partials/workspace.php';
require __DIR__ . '/../../views/partials/self.php';
require __DIR__ . '/../../views/partials/self-attendance.php';
require __DIR__ . '/../../views/partials/self-payslips.php';
require __DIR__ . '/../../views/partials/self-profile.php';
