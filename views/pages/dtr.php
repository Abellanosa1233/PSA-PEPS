<?php
declare(strict_types=1);

/* Printable Daily Time Record, laid out like the paper time card:
   header lines, hours/deductions box, then Morning/Afternoon/Overtime IN-OUT per day.
   Employees can only open their own; HR can open anyone's, or every active employee. */

$isAdmin = is_admin($user);
$people = array_column($state['employees'], null, 'id');
[$month, $half, $from, $to] = dtr_period((string) ($_GET['month'] ?? date('Y-m')), (string) ($_GET['half'] ?? ((int) date('j') <= 15 ? 'first' : 'second')));
$department = $isAdmin ? (string) ($_GET['department'] ?? '') : '';
$choice = $isAdmin ? (string) ($_GET['emp'] ?? '') : (string) $user['employeeId'];

$selected = [];
if ($choice === 'all') {
    foreach ($people as $p) {
        $employed = $p['startDate'] <= $to->format('Y-m-d') && (!$p['endDate'] || $p['endDate'] >= $from->format('Y-m-d'));
        if ($employed && ($p['status'] === 'Active' || $p['endDate']) && ($department === '' || $p['department'] === $department)) $selected[] = $p;
    }
} elseif (isset($people[(int) $choice])) {
    $selected[] = $people[(int) $choice];
}

// Cards in print order, two per sheet: a whole month is the card's front (1–15) and back (16–end).
$cards = [];
foreach ($selected as $p) {
    if ($half === 'month') {
        $mid = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), 15);
        $cards[] = dtr_card($p, $from, $mid, $state['today']);
        $cards[] = dtr_card($p, $mid->modify('+1 day'), $to, $state['today']);
    } else {
        $cards[] = dtr_card($p, $from, $to, $state['today']);
    }
}
$sheets = array_chunk($cards, 2);
$periodLabel = $from->format('F j') . '–' . $to->format('j, Y');
$titleWho = count($selected) === 1 ? employee_name($selected[0]) : (count($selected) . ' employees');
$company = $state['settings']['company'] ?? 'HRMS';
$back = $isAdmin ? ($choice !== '' && $choice !== 'all' ? page_url('employees', ['id' => (int) $choice]) : page_url('attendance')) : page_url('my-attendance');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $selected ? 'DTR · ' . h($titleWho) . ' · ' . h($periodLabel) : 'Daily Time Record · HRMS' ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap">
<style>
  /* Paper look: printed in black on white whatever the app theme. */
  :root { --ink: #111; --rule: #222; --soft: #6b6b6b; --paper: #fff; --desk: #eef2f7; --accent: #0038a8; --line: #d7e1ee; --ui-text: #172b46; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--desk); color: var(--ink); font: 12px/1.35 "Inter", "Segoe UI", Arial, sans-serif; }
  .toolbar { position: sticky; top: 0; z-index: 2; background: #fff; border-bottom: 1px solid var(--line); color: var(--ui-text); }
  .toolbar-inner { max-width: 1320px; margin: auto; padding: 16px 24px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 14px 24px; }
  .toolbar-title, .toolbar-actions, .toolbar-filters { display: flex; align-items: center; gap: 10px; }
  .toolbar-title img { flex: none; }
  .toolbar h1 { font-size: clamp(17px, 2vw, 22px); line-height: 1.2; margin: 0; }
  .toolbar-title p { margin: 4px 0 0; color: #52647a; overflow-wrap: anywhere; }
  .toolbar-filters { flex-wrap: wrap; align-items: end; }
  .toolbar label { display: flex; flex-direction: column; gap: 4px; min-width: 0; font-size: 12px; font-weight: 600; }
  .toolbar select, .toolbar input, .toolbar .btn { min-height: 38px; border: 1px solid var(--line); border-radius: 8px; background: #fff; color: var(--ui-text); font: inherit; }
  .toolbar select, .toolbar input { max-width: min(260px, 100%); padding: 0 10px; }
  .toolbar .btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 0 14px; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; }
  .toolbar .btn:hover { background: #f2f6fc; }
  .toolbar .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
  .toolbar .btn.primary:hover { background: #002b82; }
  .toolbar :focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
  .sheets { display: flex; flex-direction: column; align-items: center; gap: 28px; padding: 28px 16px 48px; }
  .sheet { display: flex; gap: 10mm; justify-content: center; flex-wrap: wrap; }
  .empty { background: #fff; padding: 24px; border-radius: 8px; max-width: 520px; text-align: center; }

  .card { width: 92mm; background: var(--paper); padding: 5mm 5mm 4mm; box-shadow: 0 1px 3px rgba(0,0,0,.15); font-size: 8.6pt; }
  .card .co { text-align: center; font-weight: 700; font-size: 8pt; letter-spacing: .06em; text-transform: uppercase; margin-bottom: 1mm; }
  .card .co small { display: block; font-weight: 500; letter-spacing: .02em; text-transform: none; color: var(--soft); }
  .hd .line { display: grid; grid-template-columns: auto 1fr auto 1fr; gap: 0 1.5mm; align-items: end; margin-top: 1.6mm; }
  .hd .line span { font-weight: 700; white-space: nowrap; }
  .hd .line b { font-weight: 500; border-bottom: 1px solid var(--rule); min-height: 4.2mm; padding: 0 1mm; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
  .pay { display: grid; grid-template-columns: 1fr 5mm 1fr; border: 1px solid var(--rule); margin-top: 2.5mm; }
  .pay table { border-collapse: collapse; width: 100%; font-size: 7.4pt; }
  .pay td, .pay th { border: 1px solid var(--rule); height: 4.4mm; padding: 0 1mm; font-weight: 500; text-align: left; }
  .pay th { font-weight: 600; text-align: center; }
  .pay tr > :first-child { border-left: 0; } .pay tr > :last-child { border-right: 0; }
  .pay tr:first-child > * { border-top: 0; } .pay tr:last-child > * { border-bottom: 0; }
  .pay .v { text-align: right; font-variant-numeric: tabular-nums; }
  .pay .vert { writing-mode: vertical-rl; transform: rotate(180deg); text-align: center; font-weight: 700; font-size: 7pt; letter-spacing: .25em; border-left: 1px solid var(--rule); border-right: 1px solid var(--rule); }
  .days { border-collapse: collapse; width: 100%; margin-top: 2.5mm; font-size: 8pt; font-variant-numeric: tabular-nums; }
  .days th, .days td { border: 1px solid var(--rule); text-align: center; padding: 0; }
  .days th { font-size: 7pt; font-weight: 700; height: 4mm; }
  .days td { height: 5.2mm; }
  .days td.d { font-weight: 700; width: 7mm; }
  .days td.note { color: var(--soft); font-style: italic; font-size: 7.4pt; }
  .days tr.wk td.note { background: #f2f2f2; }
  .days td.tot { font-weight: 600; }
  .days tfoot td { font-weight: 700; height: 5mm; }
  .cert { text-align: center; font-size: 7.6pt; margin: 2.2mm 0 0; }
  .sig { display: flex; gap: 4mm; margin-top: 6mm; }
  .sig div { flex: 1; border-top: 1px solid var(--rule); text-align: center; font-size: 6.6pt; font-weight: 700; letter-spacing: .04em; padding-top: .8mm; }
  .foot { margin-top: 2.5mm; font-size: 6.4pt; color: var(--soft); display: flex; justify-content: space-between; gap: 2mm; }

  @media screen {
    .sheet { width: 100%; gap: 24px; }
    .card { width: min(100%, 510px); padding: clamp(14px, 3vw, 26px); border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 12px 32px rgba(25, 46, 77, .10); font-size: 13px; }
    .card .co { color: #183860; font-size: 13px; }
    .card .co small { margin-top: 3px; font-size: 11px; }
    .hd .line { grid-template-columns: auto minmax(0, 1fr) auto minmax(0, 1fr); align-items: baseline; }
    .hd .line b { white-space: normal; overflow-wrap: anywhere; }
    .days { font-size: 12px; }
    .days th { height: 20px; background: #eef4fb; color: #183860; }
    .days td { height: 25px; }
    .days tfoot td { background: #eef4fb; }
  }
  @media screen and (max-width: 760px) {
    .toolbar { position: static; }
    .toolbar-inner { padding: 14px 16px; }
    .toolbar-filters { order: 1; width: 100%; }
  }
  @page { size: A4 portrait; margin: 10mm; }
  @media print {
    body { background: #fff; }
    .toolbar, .no-print { display: none !important; }
    .sheets { padding: 0; gap: 0; display: block; }
    .sheet { break-after: page; page-break-after: always; flex-wrap: nowrap; }
    .sheet:last-child { break-after: auto; page-break-after: auto; }
    .card { box-shadow: none; }
  }
</style>
</head>
<body>
<header class="toolbar">
<div class="toolbar-inner">
    <div class="toolbar-title">
        <img src="assets/psa-logo.svg" alt="" width="38" height="38">
        <div><h1>Daily Time Record</h1><?php if ($cards): ?><p><?= h($titleWho . ' · ' . $periodLabel) ?></p><?php endif; ?></div>
    </div>
    <form class="toolbar-filters" method="get" action="<?= h(page_url('dtr')) ?>">
    <?php if ($isAdmin): ?>
        <label>Employee
            <select name="emp" required>
                <option value="">Choose…</option>
                <option value="all"<?= $choice === 'all' ? ' selected' : '' ?>>All employees</option>
                <?php foreach ($people as $p): if ($p['status'] !== 'Active') continue; ?>
                    <option value="<?= (int) $p['id'] ?>"<?= (string) $p['id'] === $choice ? ' selected' : '' ?>><?= h(employee_name($p) . ' · ' . $p['department']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Department (for All employees)
            <select name="department">
                <option value="">All departments</option>
                <?php foreach (DEPARTMENTS as $dep): ?><option<?= $dep === $department ? ' selected' : '' ?>><?= h($dep) ?></option><?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <label>Month <input type="month" name="month" value="<?= h($month) ?>" required></label>
    <label>Period
        <select name="half">
            <option value="first"<?= $half === 'first' ? ' selected' : '' ?>>1st–15th</option>
            <option value="second"<?= $half === 'second' ? ' selected' : '' ?>>16th–end of month</option>
            <option value="month"<?= $half === 'month' ? ' selected' : '' ?>>Whole month</option>
        </select>
    </label>
    <button class="btn" type="submit">Show record</button>
    </form>
    <div class="toolbar-actions">
        <a class="btn" href="<?= h($back) ?>"><?= icon('arrowLeft', 15) ?> Back</a>
        <?php if ($cards): ?><button class="btn primary" type="button" onclick="window.print()">Print record</button><?php endif; ?>
    </div>
</div>
</header>
<main class="sheets">
<?php if (!$cards): ?>
    <div class="empty"><?= $isAdmin
        ? 'Choose an employee (or All employees), a month and a period, then select <strong>Show</strong>.'
        : 'No record found for this period.' ?></div>
<?php endif; ?>
<?php foreach ($sheets as $sheet): ?>
    <section class="sheet">
    <?php foreach ($sheet as $card):
        $e = $card['employee'];
        $t = $card['totals'];
    ?>
        <article class="card" aria-label="<?= h('DTR for ' . employee_name($e) . ', ' . $card['from']->format('M j') . '–' . $card['to']->format('j, Y')) ?>">
            <div class="co"><?= h($company) ?><small>Daily Time Record · <?= h($card['from']->format('F j') . '–' . $card['to']->format('j, Y')) ?></small></div>
            <div class="hd">
                <div class="line"><span>No.</span><b><?= h($e['biometricId'] ?? '' ?: (string) $e['id']) ?></b><span>Pay Ending</span><b><?= h($card['to']->format('F j, Y')) ?></b></div>
                <div class="line"><span>Name</span><b><?= h(employee_name($e)) ?></b><span>Position</span><b><?= h($e['title']) ?></b></div>
                <div class="line"><span>Dept.</span><b><?= h($e['department']) ?></b><span>Age</span><b></b></div>
            </div>
            <div class="pay">
                <table>
                    <tr><th></th><th>Hours</th><th>Rate</th><th>Amount</th></tr>
                    <tr><td>Reg.</td><td class="v"><?= h(dtr_hm($t['regular'])) ?></td><td></td><td></td></tr>
                    <tr><td>Over.</td><td class="v"><?= h(dtr_hm($t['overtime'])) ?></td><td></td><td></td></tr>
                    <tr><td colspan="3">Total Earnings</td><td></td></tr>
                    <tr><td colspan="3">Less Deductions</td><td></td></tr>
                    <tr><td colspan="3"><strong>NET PAY</strong></td><td></td></tr>
                </table>
                <div class="vert">DEDUCTIONS</div>
                <table>
                    <tr><th>Absences</th><th class="v"><?= $t['absences'] ? $t['absences'] . ' day' . ($t['absences'] === 1 ? '' : 's') : '' ?></th></tr>
                    <tr><td>Fines</td><td></td></tr>
                    <tr><td>Withholding Tax</td><td></td></tr>
                    <tr><td>S.S.S.</td><td></td></tr>
                    <tr><td>&nbsp;</td><td></td></tr>
                    <tr><td><strong>TOTAL</strong></td><td></td></tr>
                </table>
            </div>
            <table class="days">
                <thead>
                    <tr><th rowspan="2">Days</th><th colspan="2">MORNING</th><th colspan="2">AFTERNOON</th><th colspan="2">OVERTIME</th><th rowspan="2">Daily<br>Total</th></tr>
                    <tr><th>IN</th><th>OUT</th><th>IN</th><th>OUT</th><th>IN</th><th>OUT</th></tr>
                </thead>
                <tbody>
                <?php foreach ($card['rows'] as $r): $s = $r['slots']; $any = array_filter($s); ?>
                    <tr<?= $r['weekend'] ? ' class="wk"' : '' ?>>
                        <td class="d"><?= $r['day'] ?></td>
                        <?php if (!$any && $r['note'] !== ''): ?>
                            <td class="note" colspan="6"><?= h($r['note']) ?></td><td></td>
                        <?php else: ?>
                            <?php foreach (['amIn', 'amOut', 'pmIn', 'pmOut', 'otIn', 'otOut'] as $k): ?><td><?= h(dtr_clock($s[$k])) ?></td><?php endforeach; ?>
                            <td class="tot"><?= h($r['total']) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="7" style="text-align:right;padding-right:2mm">Total hours (<?= (int) $t['daysPresent'] ?> day<?= $t['daysPresent'] === 1 ? '' : 's' ?> present)</td><td><?= h(dtr_hm($t['regular'] + $t['overtime'])) ?: '0:00' ?></td></tr></tfoot>
            </table>
            <p class="cert">I hereby certify that the above records are true and correct.</p>
            <div class="sig"><div>EMPLOYEE’S SIGNATURE</div><div>VERIFIED BY (SUPERVISOR / HR)</div></div>
            <div class="foot"><span>Printed <?= h(date('M j, Y g:i A')) ?></span><span><?= h($e['department']) ?></span></div>
        </article>
    <?php endforeach; ?>
    </section>
<?php endforeach; ?>
</main>
</body>
</html>
