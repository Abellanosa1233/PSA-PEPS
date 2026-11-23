<?php
declare(strict_types=1);

function reports_view(array $state): void
{
    $month = report_month($state);
    $start = $month . '-01';
    $end = period_end($month);
    $rows = department_report($state, $month);
    $departures = count(array_filter($state['employees'], fn($e) => $e['endDate'] && $e['endDate'] >= $start && $e['endDate'] <= $end));
    $headcount = array_sum(array_column($rows, 1));
    $turnoverBase = $headcount + $departures;
    $payroll = 0;
    foreach ($state['payRuns'] as $run) {
        if ($run['period'] === $month && $run['lines']) $payroll += array_sum(array_column($run['lines'], 'gross'));
    }
    $recorded = $present = 0;
    foreach ((array) $state['attendance'] as $date => $records) {
        if ($date < $start || $date > $end) continue;
        foreach ($records as $record) {
            $recorded++;
            if (in_array($record['status'], ['office', 'remote'], true)) $present++;
        }
    }
    $leave = array_fill_keys(LEAVE_TYPES, 0);
    foreach ($state['leaves'] as $request) {
        if ($request['status'] === 'approved' && $request['start'] <= $end && $request['end'] >= $start) {
            $leave[$request['type']] += workdays(max($start, $request['start']), min($end, $request['end']));
        }
    }
    ?>
    <div class="page-head">
        <div><h1>Reports</h1><p>Headcount, attendance, time off and payroll at a glance.</p></div>
        <div class="actions">
            <?php get_form('reports'); field('month', 'Month', $month, 'month'); end_form('Show month'); ?>
            <a class="btn" href="<?= h(page_url('reports', ['month' => $month, 'export' => 1])) ?>"><?= icon('download') ?> Export headcount CSV</a>
        </div>
    </div>
    <div class="grid kpis">
        <?php foreach ([
            ['Headcount', 'users', $headcount, 'at month end'],
            ['Leavers', 'logOut', $departures, $turnoverBase ? number_format(100 * $departures / $turnoverBase, 1) . '% monthly turnover' : '0% monthly turnover'],
            ['Attendance', 'clock', $recorded ? number_format(100 * $present / $recorded, 1) . '%' : '—', 'present among recorded days'],
            ['Payroll', 'wallet', money($payroll, $state), 'finalized gross pay'],
        ] as [$label, $glyph, $value, $note]): ?>
            <section class="card kpi">
                <div class="kpi-top"><?= h($label) ?><?= icon($glyph) ?></div>
                <div class="kpi-value"><?= h($value) ?></div>
                <div class="kpi-foot"><?= h($note) ?></div>
            </section>
        <?php endforeach; ?>
    </div>
    <div class="grid cols-even">
        <?php report_bars('Headcount by department', array_column($rows, 1, 0), 'strval'); ?>
        <?php report_bars('Time off taken', $leave, fn($days) => "$days days"); ?>
    </div>
    <?php report_bars('Average base salary by department', array_column($rows, 2, 0), fn($salary) => money($salary, $state)); ?>
    <?php
}

function report_bars(string $title, array $values, callable $format): void
{
    $max = max([1, ...array_values($values)]);
    ?>
    <section class="card">
        <div class="card-head"><h3><?= h($title) ?></h3></div>
        <div class="card-body">
            <?php if (!$values): ?><div class="empty">No records found.</div><?php endif; ?>
            <?php foreach ($values as $label => $value): ?>
                <div class="hbar"><span><?= h($label) ?></span><div class="track"><span style="width:<?= 100 * $value / $max ?>%"></span></div><span class="v"><?= h($format($value)) ?></span></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

reports_view($state);
