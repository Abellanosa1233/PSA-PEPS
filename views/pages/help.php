<?php
declare(strict_types=1);

function help_view(): void
{
    card('Using HRMS');
    echo '<dl><dt>Employees</dt><dd>Use the directory to update profiles and employment status.</dd>'
        . '<dt>Attendance</dt><dd>Choose a date to review or update clock records.</dd>'
        . '<dt>Time off</dt><dd>Submit requests and review pending approvals.</dd>'
        . '<dt>Payroll</dt><dd>Review the draft and finalize it to save payslips.</dd></dl></section>';
}

help_view($state);
