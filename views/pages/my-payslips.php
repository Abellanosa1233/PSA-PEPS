<?php
/* My payslips, following the team's design: summary, history by year, a month's breakdown and
   payment details. "Download" opens a printable payslip (views/pages/payslip.php). */
$me = (int) $state['user']['employeeId'];
$slips = self_payslips($state, $me);
$periods = array_column($slips, null, 'period');
$selected = isset($periods[(string) ($_GET['period'] ?? '')]) ? (string) $_GET['period'] : ($slips[0]['period'] ?? '');
?>
<div class="page-head">
    <div><h1>My payslips</h1><p>Your pay information, securely in one place.</p></div>
    <?php if ($slips): ?>
        <div class="actions"><a class="btn primary" href="<?= h(page_url('payslip', ['period' => $slips[0]['period']])) ?>" target="_blank" rel="noopener"><?= icon('download') ?> Download latest</a></div>
    <?php endif; ?>
</div>
<?php self_payslip_summary($state, $slips); ?>
<?php self_payslip_history($state, $slips, $selected); ?>
<div class="grid cols-main">
    <?php self_payslip_breakdown($state, $periods[$selected] ?? null); ?>
    <?php self_payment_details($state); ?>
</div>
