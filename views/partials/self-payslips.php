<?php
declare(strict_types=1);

/* My payslips: an employee's issued payslips (paid pay runs only). Amounts come from the
   payslips saved when HR approved each pay run, so they never change afterwards. */

/* ₱4,800.00 — symbol for common currencies, otherwise "SGD 4,800.00". */
function self_money($amount, array $state): string
{
    $code = $state['settings']['currency'] ?? 'PHP';
    $symbol = ['PHP' => '₱', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'INR' => '₹'][$code] ?? $code . ' ';
    return ($amount < 0 ? '−' : '') . $symbol . number_format(abs((float) $amount), 2);
}

/* One row per issued payslip for this employee, newest first. */
function self_payslips(array $state, int $id): array
{
    $slips = [];
    foreach ($state['payRuns'] as $run) {
        if ($run['status'] !== 'paid') continue;
        foreach ($run['lines'] ?? [] as $line) {
            if ($line['empId'] !== $id) continue;
            $slips[] = $line + [
                'runId' => $run['id'], 'period' => $run['period'],
                'paidOn' => substr((string) ($run['paidAt'] ?: period_end($run['period'])), 0, 10),
                'deductions' => round($line['tax'] + $line['benefits'], 2),
            ];
        }
    }
    usort($slips, function ($a, $b) { return strcmp($b['period'], $a['period']); });
    return $slips;
}

function self_period_label(string $period): string
{
    return (new DateTimeImmutable($period . '-01'))->format('F Y');
}

function self_payslip_summary(array $state, array $slips): void
{
    $latest = $slips[0] ?? null;
    $cards = [];
    if ($latest) {
        $year = substr($latest['period'], 0, 4);
        $ytd = array_filter($slips, function ($s) use ($year, $latest) { return strpos($s['period'], $year) === 0 && $s['period'] <= $latest['period']; });
        $first = min(array_column($ytd, 'period'));
        $cards[] = ['Latest net pay', self_money($latest['net'], $state), self_period_label($latest['period']) . ' · Paid ' . (new DateTimeImmutable($latest['paidOn']))->format('M j')];
        $cards[] = ['Year-to-date net pay', self_money(array_sum(array_column($ytd, 'net')), $state),
            (new DateTimeImmutable($first . '-01'))->format('F') . ($first === $latest['period'] ? '' : ' – ' . (new DateTimeImmutable($latest['period'] . '-01'))->format('F')) . ' ' . $year];
    } else {
        $cards[] = ['Latest net pay', '—', 'No payslips yet'];
        $cards[] = ['Year-to-date net pay', '—', 'No payslips yet'];
    }
    $next = $state['nextPayday'] ?? null;
    $cards[] = ['Next pay date', $next ? (new DateTimeImmutable($next['date']))->format('M j, Y') : '—', $next ? 'Monthly pay schedule' : 'Not scheduled yet'];
    echo '<div class="grid cols-3 self-pay-cards">';
    foreach ($cards as [$label, $value, $note]) {
        echo '<section class="card kpi"><div class="kpi-top"><span>' . h($label) . '</span></div><div class="kpi-value">' . h($value) . '</div><div class="kpi-foot"><span>' . h($note) . '</span></div></section>';
    }
    echo '</div><p class="muted self-leave-note">Amounts shown in ' . h($state['settings']['currency'] ?? 'PHP') . ' · Payslips are available after payroll is processed.</p>';
}

function self_payslip_history(array $state, array $slips, string $selected): void
{
    $years = array_values(array_unique(array_map(function ($s) { return substr($s['period'], 0, 4); }, $slips)));
    $year = in_array((string) ($_GET['year'] ?? ''), $years, true) ? (string) $_GET['year'] : ($years[0] ?? date('Y'));
    $rows = array_filter($slips, function ($s) use ($year) { return strpos($s['period'], $year) === 0; });
    echo '<section class="card"><div class="card-head"><h3>Payslip history</h3>';
    if (count($years) > 1) {
        echo '<form method="get" action="' . h(page_url('my-payslips')) . '" class="toolbar self-filter"><select class="input sm" name="year" aria-label="Year">';
        foreach ($years as $y) echo '<option' . ($y === $year ? ' selected' : '') . '>' . h($y) . '</option>';
        echo '</select><button class="btn sm" type="submit">Show</button></form>';
    } else {
        echo '<span class="pill">' . h($year) . '</span>';
    }
    echo '</div>';
    if (!$rows) {
        echo '<div class="empty"><strong>No payslips yet</strong><span>Your first payslip appears here once HR processes payroll.</span></div></section>';
        return;
    }
    echo '<div class="table-wrap"><table class="data"><thead><tr><th>Pay period</th><th>Pay date</th><th class="num">Gross pay</th><th class="num">Deductions</th><th class="num">Net pay</th><th class="num">Payslip</th></tr></thead><tbody>';
    foreach ($rows as $s) {
        $isSelected = $s['period'] === $selected;
        echo '<tr' . ($isSelected ? ' class="is-selected"' : '') . '><td><a href="' . h(page_url('my-payslips', ['period' => $s['period'], 'year' => $year])) . '"' . ($isSelected ? ' aria-current="true"' : '') . '>' . h(self_period_label($s['period'])) . '</a></td>'
            . '<td class="tabular">' . h((new DateTimeImmutable($s['paidOn']))->format('M j, Y')) . '</td>'
            . '<td class="num">' . h(self_money($s['gross'], $state)) . '</td><td class="num">' . h(self_money($s['deductions'], $state)) . '</td>'
            . '<td class="num"><strong>' . h(self_money($s['net'], $state)) . '</strong></td>'
            . '<td class="num"><a class="link-btn" href="' . h(page_url('payslip', ['period' => $s['period']])) . '" target="_blank" rel="noopener">Download ' . icon('download', 13) . '</a></td></tr>';
    }
    echo '</tbody></table></div><div class="card-foot"><span>Select a pay period to see its breakdown. Download opens a printable payslip you can save as PDF.</span></div></section>';
}

function self_payslip_breakdown(array $state, ?array $slip): void
{
    echo '<section class="card">';
    if (!$slip) {
        echo '<div class="card-head"><h3>Pay breakdown</h3></div><div class="empty"><strong>Nothing to show yet</strong><span>The breakdown appears with your first payslip.</span></div></section>';
        return;
    }
    $month = (new DateTimeImmutable($slip['period'] . '-01'))->format('F');
    echo '<div class="card-head"><h3>' . h($month) . ' pay breakdown</h3></div><div class="card-body"><dl class="self-breakdown">';
    $lines = [['Base salary', $slip['base']]];
    if ((float) $slip['bonus'] > 0) $lines[] = ['Bonus', $slip['bonus']];
    $lines[] = ['Withholding tax', -$slip['tax']];
    $lines[] = ['SSS, PhilHealth and Pag-IBIG', -$slip['benefits']];
    foreach ($lines as [$label, $amount]) echo '<dt>' . h($label) . '</dt><dd>' . h(self_money($amount, $state)) . '</dd>';
    echo '<dt class="total">Net pay</dt><dd class="total">' . h(self_money($slip['net'], $state)) . '</dd></dl></div></section>';
}

function self_payment_details(array $state): void
{
    echo '<section class="card"><div class="card-head"><h3>Payment details</h3></div><div class="card-body">'
        . '<strong>Paid by ' . h($state['settings']['company'] ?? 'your employer') . '</strong>'
        . '<p class="muted">Salary is credited on each pay date as arranged with HR. Your bank details are kept by HR and are never shown here.</p>'
        . '<a class="btn self-wide" href="' . h(page_url('help')) . '">Contact payroll ' . icon('arrowRight', 14) . '</a></div></section>';
}
