<?php
/* My profile, following the team's design: identity, employment summary, personal information,
   emergency contact, documents and a privacy note. */
$meId = (int) $state['user']['employeeId'];
$people = array_column($state['employees'], null, 'id');
$me = $people[$meId];
?>
<div class="employee-dashboard employee-profile-dashboard">
<div class="page-head">
    <div><h1>My profile</h1><p>Keep your personal details and work information up to date.</p></div>
</div>
<?php self_profile_header_card($me); ?>
<div class="grid employee-dashboard-split">
    <?php self_personal_card($me, true, isset($_GET['edit'])); ?>
    <?php self_employment_card($me, $people); ?>
</div>
<div class="grid employee-dashboard-split">
    <?php self_documents_card($state, $meId); ?>
    <?php self_emergency_card($me, true); ?>
</div>
<?php self_protected_card(); ?>
</div>
