<?php
declare(strict_types=1);

/* Printable payslip for one pay period. Employees only ever get their own (their page data holds
   no one else's payslips); HR can add ?emp=ID. Save as PDF uses the browser's print dialog. */

$isAdmin = is_admin($user);
$empId = $isAdmin ? (int) ($_GET['emp'] ?? 0) : (int) $user['employeeId'];
$period = (string) ($_GET['period'] ?? '');
$slip = null;
$run = null;
foreach ($state['payRuns'] as $r) {
    if ($r['status'] !== 'paid' || $r['period'] !== $period) continue;
    foreach ($r['lines'] ?? [] as $line) {
        if ($line['empId'] === $empId) { $slip = $line; $run = $r; }
    }
}
$people = array_column($state['employees'], null, 'id');
$person = $people[$empId] ?? null;
$company = $state['settings']['company'] ?? 'HRMS';
$back = $isAdmin ? ($empId ? page_url('employees', ['id' => $empId]) : page_url('payroll')) : page_url('my-payslips', ['period' => $period]);
if (!$slip) http_response_code(404);
$paidOn = $run ? new DateTimeImmutable(substr((string) ($run['paidAt'] ?: period_end($run['period'])), 0, 10)) : null;
$monthLabel = $slip ? (new DateTimeImmutable($period . '-01'))->format('F Y') : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $slip ? 'Payslip · ' . h($slip['name']) . ' · ' . h($monthLabel) : 'Payslip · HRMS' ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap">
<style>
  /* Paper look: black on white whatever the app theme. */
  :root { --ink: #111; --soft: #5f6670; --rule: #d9dde3; --paper: #fff; --desk: #e9edf2; --accent: #1f4e8c; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--desk); color: var(--ink); font: 13px/1.45 "Inter", "Segoe UI", Arial, sans-serif; }
  .toolbar { position: sticky; top: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 12px max(16px, 3vw); background: #fff; border-bottom: 1px solid #cfd8e3; }
  .toolbar strong { margin-right: auto; font-size: 15px; }
  .btn { height: 34px; padding: 0 14px; border-radius: 6px; border: 1px solid #b9c5d4; background: #fff; font: 600 12.5px/32px inherit; color: #1b2a3d; text-decoration: none; cursor: pointer; display: inline-block; }
  .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  .hint { margin: 0; padding: 10px max(16px, 3vw) 0; color: #405065; font-size: 12px; }
  main { display: flex; justify-content: center; padding: 24px 16px 48px; }
  .slip { width: min(190mm, 100%); background: var(--paper); padding: 14mm 14mm 12mm; box-shadow: 0 1px 3px rgba(0,0,0,.15); }
  .head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; border-bottom: 2px solid var(--ink); padding-bottom: 10px; }
  .head h1 { margin: 0; font-size: 18px; }
  .head small { display: block; color: var(--soft); }
  .head .doc { text-align: right; }
  .head .doc strong { font-size: 15px; letter-spacing: .08em; text-transform: uppercase; }
  .who { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px 24px; margin: 14px 0 18px; }
  .who div { display: flex; justify-content: space-between; gap: 10px; border-bottom: 1px solid var(--rule); padding: 4px 0; }
  .who span { color: var(--soft); }
  table { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-variant-numeric: tabular-nums; }
  th { text-align: left; font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: var(--soft); border-bottom: 1px solid var(--ink); padding: 6px 0; }
  td { padding: 7px 0; border-bottom: 1px solid var(--rule); }
  td.num, th.num { text-align: right; }
  tr.sum td { font-weight: 700; border-bottom: 1px solid var(--ink); }
  .net { display: flex; justify-content: space-between; align-items: baseline; padding: 12px 14px; background: #f2f4f7; border: 1px solid var(--rule); font-size: 15px; }
  .net strong { font-size: 20px; }
  .foot { margin-top: 18px; color: var(--soft); font-size: 11px; display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .empty { background: #fff; padding: 24px; border-radius: 8px; max-width: 520px; text-align: center; }
  @page { size: A4 portrait; margin: 12mm; }
  @media print { body { background: #fff; } .toolbar, .hint { display: none !important; } main { padding: 0; } .slip { box-shadow: none; width: 100%; padding: 0; } }
</style>
</head>
<body>
<div class="toolbar">
    <strong>Payslip</strong>
    <a class="btn" href="<?= h($back) ?>">Back</a>
    <?php if ($slip): ?><button class="btn primary" type="button" onclick="window.print()">Save as PDF / Print</button><?php endif; ?>
</div>
<?php if ($slip): ?>
<p class="hint">To save a PDF, choose <strong>Save as PDF</strong> as the printer. If the button doesn’t respond, press Ctrl+P.</p>
<main>
    <article class="slip" aria-label="<?= h('Payslip for ' . $slip['name'] . ', ' . $monthLabel) ?>">
        <div class="head">
            <div><h1><?= h($company) ?></h1><small>Human Resource Management System</small></div>
            <div class="doc"><strong>Payslip</strong><small><?= h($monthLabel) ?></small></div>
        </div>
        <div class="who">
            <div><span>Employee</span><strong><?= h($slip['name']) ?></strong></div>
            <div><span>Pay period</span><strong><?= h((new DateTimeImmutable($period . '-01'))->format('M j') . ' – ' . (new DateTimeImmutable(period_end($period)))->format('M j, Y')) ?></strong></div>
            <div><span>Position</span><strong><?= h($person['title'] ?? '—') ?></strong></div>
            <div><span>Pay date</span><strong><?= h($paidOn->format('M j, Y')) ?></strong></div>
            <div><span>Department</span><strong><?= h($slip['department']) ?></strong></div>
            <div><span>Employee ID</span><strong><?= h((string) $empId) ?></strong></div>
        </div>
        <table>
            <thead><tr><th>Earnings</th><th class="num">Amount</th></tr></thead>
            <tbody>
                <tr><td>Base salary</td><td class="num"><?= h(self_money($slip['base'], $state)) ?></td></tr>
                <?php if ((float) $slip['bonus'] > 0): ?><tr><td>Bonus</td><td class="num"><?= h(self_money($slip['bonus'], $state)) ?></td></tr><?php endif; ?>
                <tr class="sum"><td>Gross pay</td><td class="num"><?= h(self_money($slip['gross'], $state)) ?></td></tr>
            </tbody>
        </table>
        <table>
            <thead><tr><th>Deductions</th><th class="num">Amount</th></tr></thead>
            <tbody>
                <tr><td>Withholding tax</td><td class="num"><?= h(self_money($slip['tax'], $state)) ?></td></tr>
                <tr><td>SSS, PhilHealth and Pag-IBIG</td><td class="num"><?= h(self_money($slip['benefits'], $state)) ?></td></tr>
                <tr class="sum"><td>Total deductions</td><td class="num"><?= h(self_money($slip['tax'] + $slip['benefits'], $state)) ?></td></tr>
            </tbody>
        </table>
        <div class="net"><span>Net pay</span><strong><?= h(self_money($slip['net'], $state)) ?></strong></div>
        <div class="foot">
            <span>Issued when payroll for <?= h($monthLabel) ?> was approved. This payslip is confidential.</span>
            <span>Printed <?= h(date('M j, Y g:i A')) ?></span>
        </div>
    </article>
</main>
<?php else: ?>
<main><div class="empty"><strong>This payslip isn’t available.</strong><p>Payslips appear once HR approves the month’s payroll. <a href="<?= h($back) ?>">Go back</a></p></div></main>
<?php endif; ?>
</body>
</html>
