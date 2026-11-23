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
    $labels = [];
    foreach ($runs as $item) $labels[$item['id']] = $item['period'] . ' · ' . ucfirst($item['status']);
    get_form('payroll');
    choice('id', 'Pay period', $labels, $run['id']);
    end_form('Show run');
    link_to('payroll', 'Export CSV', ['id' => $run['id'], 'export' => 1], 'btn');
    if ($draft) confirm_action('payroll.pay', 'Finalize payroll', 'This locks the pay run and saves its payslips.', ['runId' => $run['id']]);
    $rows = [];
    foreach ($lines as $line) {
        $bonus = $draft ? function () use ($run, $line) {
            post_form('payroll.bonus', ['runId' => $run['id'], 'empId' => $line['empId']]);
            field('amount', 'Bonus', $line['bonus'], 'number');
            end_form('Save');
        } : money($line['bonus'], $state);
        $rows[] = [$line['name'], $line['department'], money($line['base'], $state), $bonus, money($line['gross'], $state), money($line['tax'], $state), money($line['benefits'], $state), money($line['net'], $state)];
    }
    web_table(['Employee', 'Department', 'Base', 'Bonus', 'Gross', 'Tax', 'Benefits', 'Net'], $rows);
    echo '</section>';
}

payroll_view($state);
