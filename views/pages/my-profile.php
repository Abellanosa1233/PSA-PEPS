<?php
/* My profile, following the team's design: identity, employment summary, personal information,
   emergency contact, documents and a privacy note. */
$meId = (int) $state['user']['employeeId'];
$people = array_column($state['employees'], null, 'id');
$me = $people[$meId];
?>
<div class="page-head">
    <div><h1>My profile</h1><p>Keep your personal details and work information up to date.</p></div>
    <div class="actions"><a class="btn primary" href="<?= h(page_url('my-profile', ['edit' => 1])) ?>#personal">Edit personal details</a></div>
</div>
<div class="grid cols-main">
    <?php self_profile_header_card($me); ?>
    <?php self_employment_card($me, $people); ?>
</div>
<div class="grid cols-main">
    <?php self_personal_card($me, true, isset($_GET['edit'])); ?>
    <?php self_emergency_card($me, true); ?>
</div>
<div class="grid cols-main">
    <?php self_documents_card($state, $meId); ?>
    <?php self_protected_card(); ?>
</div>
