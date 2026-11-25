<?php
/* My time off: balances, leave requests (sent to HR), Special/Travel Orders, and teammates out of office. */
$me = (int) $state['user']['employeeId'];
echo '<p class="muted workspace-intro">Leave requests go to HR for approval. Your teammates only see that you’re out, and when, once HR approves.</p>';
self_time_off_section($state, $me, 'my-time-off', isset($_GET['request']));
self_orders_section($state, $me);
team_absence_card($state);
