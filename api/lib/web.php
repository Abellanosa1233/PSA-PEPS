<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function page_url(string $page, array $query = []): string
{
    return 'index.php?' . http_build_query(['page' => $page] + $query);
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
    return match ($action) {
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
        'attendance.set' => 'Attendance updated.',
        'attendance.fill' => 'Attendance fill completed.',
        'attendance.clock' => ($input['mode'] ?? '') === 'in' ? 'Clock-in recorded.' : 'Clock-out recorded.',
        'event.save' => 'Schedule event saved.',
        'event.delete' => 'Schedule event removed.',
        'position.save' => 'Recruitment role saved.',
        'position.status' => ($input['status'] ?? '') === 'open' ? 'Recruitment role reopened.' : 'Recruitment role closed.',
        'position.delete' => 'Recruitment role deleted.',
        'candidate.save' => 'Candidate added.',
        'candidate.move' => 'Candidate stage updated.',
        'payroll.bonus' => 'Bonus saved.',
        'payroll.pay' => 'Payroll finalized.',
        'payroll.create' => 'Pay run created.',
        'settings.save' => 'Organization settings saved.',
        'workspace.view' => 'Workspace selection updated.',
        'profile.save' => 'Account settings saved.',
        'theme' => 'Appearance saved.',
        'demo.reset' => 'Demo data reset.',
        default => 'Changes saved.',
    };
}

function post_form(string $action, array $values = [], bool $inline = false): void
{
    echo '<form method="post" class="' . ($inline ? 'web-inline' : 'web-fields') . '">';
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

function field(string $name, string $label, mixed $value = '', string $type = 'text', bool $required = true): void
{
    echo '<label class="field"><span>' . h($label) . '</span><input class="input" name="' . h($name)
        . '" type="' . h($type) . '" value="' . h($value) . '"' . ($required ? ' required' : '')
        . ($type === 'number' ? ' step="any"' : '') . '></label>';
}

function choice(string $name, string $label, array $options, mixed $selected = ''): void
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

function card(string $title): void
{
    echo '<section class="card web-card"><h2>' . h($title) . '</h2>';
}

function web_table(array $headers, array $rows): void
{
    echo '<div class="table-wrap"><table class="data"><thead><tr>';
    foreach ($headers as $header) echo '<th scope="col">' . h($header) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>';
            if ($cell instanceof Closure) $cell();
            else echo h($cell);
            echo '</td>';
        }
        echo '</tr>';
    }
    if (!$rows) echo '<tr><td colspan="' . count($headers) . '">No records found.</td></tr>';
    echo '</tbody></table></div>';
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

function money(mixed $amount, array $state): string
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
    if ($todayStatus !== '') $todayStatuses ??= employee_today_statuses($state);
    return array_values(array_filter($state['employees'], fn($e) =>
        ($search === '' || mb_stripos(employee_name($e) . ' ' . $e['email'] . ' ' . $e['title'], $search) !== false)
        && ($department === '' || $e['department'] === $department)
        && ($status === '' || $e['status'] === $status)
        && ($type === '' || $e['type'] === $type)
        && ($todayStatus === '' || ($todayStatuses[$e['id']] ?? 'none') === $todayStatus)
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

function pay_selection(array $state): array
{
    $runs = array_reverse($state['payRuns']);
    $byId = array_column($runs, null, 'id');
    $byStatus = array_column($runs, null, 'status');
    $run = $byId[(int) ($_GET['id'] ?? 0)] ?? $byStatus['draft'] ?? $runs[0] ?? null;
    return [$runs, $run, $run ? ($run['lines'] ?? compute_lines($run['id'], $run['period'])) : []];
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

function export_page(string $page, array $state): void
{
    switch ($page) {
        case 'employees':
            $names = employee_options($state['employees']);
            $rows = [['ID', 'First name', 'Last name', 'Email', 'Phone', 'Job title', 'Department', 'Type', 'Location', 'Start date', 'End date', 'Annual salary', 'Manager', 'Status']];
            foreach (employee_list($state) as $e) {
                $rows[] = [$e['id'], $e['firstName'], $e['lastName'], $e['email'], $e['phone'], $e['title'], $e['department'], $e['type'], $e['location'], $e['startDate'], $e['endDate'], $e['salary'], $names[$e['managerId']] ?? '', $e['status']];
            }
            break;
        case 'attendance':
            $rows = [['Date', 'Employee', 'Department', 'Status', 'Clock in', 'Clock out']];
            $date = selected_date($state['today']);
            foreach (attendance_records($state, $date) as [$e, $record, $onLeave]) {
                $rows[] = [$date, employee_name($e), $e['department'], $onLeave ? 'Leave' : $record['status'], $record['in'], $record['out']];
            }
            break;
        case 'payroll':
            [, $run, $lines] = pay_selection($state);
            $rows = [['Period', 'Employee', 'Department', 'Base', 'Bonus', 'Gross', 'Tax', 'Benefits', 'Net', 'Status']];
            foreach ($lines as $line) {
                $rows[] = [$run['period'], $line['name'], $line['department'], $line['base'], $line['bonus'], $line['gross'], $line['tax'], $line['benefits'], $line['net'], $run['status']];
            }
            break;
        case 'reports':
            $rows = array_merge([['Department', 'Active employees', 'Average annual salary']], department_report($state, report_month($state)));
            break;
        default:
            return;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $page . '.csv"');
    $output = fopen('php://output', 'w');
    foreach ($rows as $row) {
        $safe = array_map(fn($v) => is_string($v) && preg_match('/^(?:\s*[=+@-]|[\t\r\n])/', $v) ? "'" . $v : $v, $row);
        fputcsv($output, $safe, ',', '"', '');
    }
    fclose($output);
    exit;
}

function confirm_action(string $action, string $label, string $prompt, array $values = []): void
{
    echo '<details><summary>' . h($label) . '</summary><p>' . h($prompt) . '</p>';
    action_button($action, $label, $values);
    echo '</details>';
}

function get_form(string $page): void
{
    echo '<form method="get" class="web-fields"><input type="hidden" name="page" value="' . h($page) . '">';
}

function initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name), 3) ?: [];
    return mb_strtoupper(implode('', array_map(fn($word) => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
}

require __DIR__ . '/../../views/partials/icons.php';
require __DIR__ . '/../../views/partials/employee-form.php';
require __DIR__ . '/../../views/partials/leave.php';
require __DIR__ . '/../../views/partials/workspace.php';
