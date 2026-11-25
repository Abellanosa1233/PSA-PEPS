<?php
declare(strict_types=1);

function payroll_view(array $state): void
{
    [$runs, $run, $lines] = pay_selection($state);
    $draft = $run && $run['status'] === 'draft';
    echo '<section class="card web-card payroll-card' . ($draft ? ' payroll-draft' : '') . '">';
    echo '<div class="payroll-card-header"><div><h2>Pay runs</h2><p>Review employee earnings and manage payments by pay period.</p></div><div class="payroll-header-actions">';
    if (!in_array('draft', array_column($runs, 'status'), true)) action_button('payroll.create', 'Start pay run');
    echo '</div></div>';
    if (!$run) {
        echo '<div class="payroll-empty"><strong>No pay runs yet</strong><p>Create a pay run to get started.</p></div></section>';
        return;
    }
    $search = trim((string) ($_GET['q'] ?? ''));
    $department = (string) ($_GET['department'] ?? '');
    $matches = array_filter($lines, function ($line) use ($department, $search) { return ($department === '' || $line['department'] === $department)
        && ($search === '' || mb_stripos($line['name'], $search) !== false); });
    $pageRows = paginate($matches, 'p', 10);
    $labels = [];
    foreach ($runs as $item) $labels[$item['id']] = $item['period'] . ' · ' . ucfirst($item['status']);
    echo '<div class="payroll-run-bar"><div class="payroll-period">';
    get_form('payroll');
    choice('id', 'Pay period', $labels, $run['id']);
    end_form('Show run');
    echo '</div><div class="payroll-run-actions">';
    status_badge($run['status']);
    link_to('payroll', 'Export CSV', ['id' => $run['id'], 'export' => 1], 'btn');
    if ($draft) confirm_action('payroll.pay', 'Finalize payroll', 'This locks the pay run and saves its payslips.', ['runId' => $run['id']]);
    echo '</div></div>';
    $rows = [];
    echo '<form method="get" action="' . h(page_url('payroll')) . '" class="payroll-filters">';
    hidden_inputs(['page' => 'payroll', 'id' => $run['id']]);
    field('q', 'Find an employee', $search, 'search', false);
    choice('department', 'Department', ['' => 'All departments'] + options(DEPARTMENTS), $department);
    echo '<div class="payroll-filter-actions"><button class="btn primary" type="submit">Filter</button>';
    link_to('payroll', 'Clear filters', ['id' => $run['id']], 'btn ghost');
    echo '</div><span class="payroll-match-count">' . count($matches) . ' employees</span></form>';
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
        echo '<div class="payroll-table">';
        web_table($headers, $rows);
        echo '</div>';
        pagination($pageRows, 'payroll', ['id' => $run['id'], 'q' => $search, 'department' => $department], 'payroll entries');
    } else {
        echo '<div class="payroll-empty"><strong>No matching payroll entries</strong>';
        link_to('payroll', 'Clear filters', ['id' => $run['id']], 'btn sm');
        echo '</div>';
    }
    echo '</section>';
}

payroll_view($state);
