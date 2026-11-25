<?php
/* My time off, following the team's design: balances, request history, breakdown and tips.
   Special/Travel Orders and teammates out of office follow below. */
$me = (int) $state['user']['employeeId'];
$people = array_column($state['employees'], null, 'id');
?>
<div class="page-head">
    <div><h1>My time off</h1><p>Plan your breaks and keep track of every request.</p></div>
    <div class="actions"><?php leave_form([$me => employee_name($people[$me])], false, isset($_GET['request']), 'Request leave'); ?></div>
</div>
<?php self_leave_cards($state, $me); ?>
<?php self_leave_history_card($state, $me); ?>
<div class="grid cols-main">
    <?php self_leave_breakdown_card($state, $me); ?>
    <?php self_before_you_request_card(); ?>
</div>
<?php
self_orders_section($state, $me);
team_absence_card($state);
