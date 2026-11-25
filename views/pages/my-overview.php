<?php
/* Employee home page (employee accounts see this instead of HR's Overview). */
$me = (int) $state['user']['employeeId'];
$firstName = explode(' ', trim($state['user']['name']))[0];
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="page-head">
    <div><h1><?= h("$greeting, $firstName") ?></h1><p>Your workday, time off, and updates — all in one place.</p></div>
    <div class="actions"><a class="btn primary" href="<?= h(page_url('my-time-off', ['request' => 1])) ?>"><?= icon('plus') ?> Request leave</a></div>
</div>
<?php self_leave_cards($state, $me); ?>
<div class="grid cols-main">
    <?php self_leave_requests_card($state, $me); ?>
    <?php self_today_attendance_card($state, $me); ?>
</div>
<div class="grid cols-even">
    <?php self_schedule_card($state); ?>
    <?php self_company_updates_card($state); ?>
</div>
