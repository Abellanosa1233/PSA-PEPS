<?php
declare(strict_types=1);

function payroll_view(array $state): void
{
    [$runs, $run, $lines] = pay_selection($state);
    card('Pay runs');
    if (!in_array('draft', array_column($runs, 'status'), true)) action_button('payroll.create', 'Start pay run');
    if (!$run) {
        echo '<p>No pay runs yet.</p></section>';
        return;
    }
    $draft = $run['status'] === 'draft';
    $search = trim((string) ($_GET['q'] ?? ''));
    $department = (string) ($_GET['department'] ?? '');
    $matches = array_filter($lines, function ($line) use ($department, $search) { return ($department === '' || $line['department'] === $department)
        && ($search === '' || mb_stripos($line['name'], $search) !== false); });
    $pageRows = paginate($matches);
    $labels = [];
    foreach ($runs as $item) $labels[$item['id']] = $item['period'] . ' · ' . ucfirst($item['status']);
    get_form('payroll');
    choice('id', 'Pay period', $labels, $run['id']);
    end_form('Show run');
    status_badge($run['status']);
    link_to('payroll', 'Export CSV', ['id' => $run['id'], 'export' => 1], 'btn');
    if ($draft) confirm_action('payroll.pay', 'Finalize payroll', 'This locks the pay run and saves its payslips.', ['runId' => $run['id']]);
    $rows = [];
    echo '<form method="get" class="toolbar">';
    hidden_inputs(['page' => 'payroll', 'id' => $run['id']]);
    field('q', 'Find an employee', $search, 'search', false);
    choice('department', 'Department', ['' => 'All departments'] + options(DEPARTMENTS), $department);
    echo '<button class="btn" type="submit">Filter</button>';
    link_to('payroll', 'Clear filters', ['id' => $run['id']], 'btn ghost');
    echo '<span class="muted web-push">' . count($matches) . ' matches</span></form>';
    foreach ($pageRows['rows'] as $line) {
        $bonus = $draft ? function () use ($run, $line) {
            post_form('payroll.bonus', ['runId' => $run['id'], 'empId' => $line['empId']]);
            field('amount', 'Bonus', $line['bonus'], 'number');
            end_form('Save');
        } : money($line['bonus'], $state);
        $row = [$line['name'], $line['department'], money($line['base'], $state), money($line['bonus'], $state), money($line['gross'], $state), money($line['tax'], $state), money($line['benefits'], $state), money($line['net'], $state)];
        if ($draft) $row[] = $bonus;
        $rows[] = $row;
    }
    if ($rows) {
        $headers = ['Employee', 'Department', 'Base', 'Bonus', 'Gross', 'Tax', 'Benefits', 'Net'];
        if ($draft) $headers[] = 'Actions';
        web_table($headers, $rows);
        pagination($pageRows, 'payroll', ['id' => $run['id'], 'q' => $search, 'department' => $department], 'payroll entries');
    } else {
        echo '<div class="empty"><strong>No matching payroll entries</strong>';
        link_to('payroll', 'Clear filters', ['id' => $run['id']], 'btn sm');
        echo '</div>';
    }
    echo '</section>';
}

payroll_view($state);
