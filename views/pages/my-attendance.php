<?php
/* My attendance, following the team's design: summary cards, weekly log, this week, corrections.
   The printable DTR follows below. */
$me = (int) $state['user']['employeeId'];
$openCorrection = isset($_GET['correct']);
?>
<div class="page-head">
    <div><h1>My attendance</h1><p>A clear view of your workdays, hours, and attendance.</p></div>
    <div class="actions"><a class="btn" href="<?= h(page_url('my-attendance', ['correct' => 1])) ?>#correction">Request correction</a></div>
</div>
<?php self_attendance_summary($state, $me); ?>
<?php self_attendance_log($state, $me); ?>
<div class="grid cols-main">
    <?php self_week_tiles($state, $me); ?>
    <?php self_correction_card($state, $me, $openCorrection); ?>
</div>
<?php dtr_card_form($me, true); ?>
