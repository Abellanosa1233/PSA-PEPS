<?php
/* My attendance: today's time in/out, the printable DTR, and the last two weeks. */
$me = (int) $state['user']['employeeId'];
echo '<div class="grid cols-main">';
self_today_attendance_card($state, $me);
echo '<div>';
dtr_card_form($me, true);
echo '</div></div>';
self_recent_attendance($state, $me);
