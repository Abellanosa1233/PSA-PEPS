<?php
/* My payslips: approved pay runs for the signed-in employee only. */
echo '<p class="muted workspace-intro">Payslips appear here once HR approves the month’s payroll.</p>';
self_payslips_section($state, (int) $state['user']['employeeId'], 'my-payslips');
