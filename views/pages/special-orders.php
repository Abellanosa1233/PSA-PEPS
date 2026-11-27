<?php
/* Special Orders (SO / RTA). HR (the secretary) works through the four steps:
   request → generate for the CSS's signature → upload the signed copy (stored + emailed)
   → on the calendar. One SO can list many participants (group SO): their rows point to the
   SO's row ("docId") and share its document. Employees request SOs here and open their signed copies. */
$isAdmin = is_admin($user);
$people = array_column($state['employees'], null, 'id');
$allOrders = array_values(array_filter($state['employeeRequests'], function ($r) { return $r['type'] === 'special_order'; }));
// Participants of each SO, by the SO's row id.
$groups = [];
foreach ($allOrders as $r) $groups[$r['docId']][] = $r;
foreach ($groups as &$members) {
    usort($members, function ($a, $b) use ($people) {
        $pa = $people[$a['empId']] ?? ['lastName' => '', 'firstName' => ''];
        $pb = $people[$b['empId']] ?? ['lastName' => '', 'firstName' => ''];
        return [$pa['lastName'], $pa['firstName']] <=> [$pb['lastName'], $pb['firstName']];
    });
}
unset($members);
// HR sees one row per SO; an employee sees their own rows (with the shared SO document).
$orders = array_values(array_filter($allOrders, function ($r) use ($isAdmin, $user) {
    return $isAdmin ? $r['groupId'] === null : $r['empId'] === (int) $user['employeeId'];
}));
$stages = ['requested' => 'To generate', 'for_signature' => 'For CSS signature', 'signed' => 'Signed', 'declined' => 'Declined', '' => 'All'];
$counts = ['' => count($orders)];
foreach ($orders as $o) {
    $s = special_order_stage($o);
    $counts[$s] = ($counts[$s] ?? 0) + 1;
}
$stage = (string) ($_GET['stage'] ?? ($isAdmin && !empty($counts['requested']) ? 'requested' : ''));
if (!array_key_exists($stage, $stages)) $stage = '';
$shown = array_values(array_filter($orders, function ($o) use ($stage) { return $stage === '' || special_order_stage($o) === $stage; }));
$list = paginate($shown);
$steps = [
    ['Request', $isAdmin ? 'Employees fill in the activity, dates and venue on the Special Order.' : 'Fill in the activity, dates and venue on the Special Order.'],
    ['Generate SO/RTA', 'The secretary completes the SO/RTA on the document itself, lists the participants and prints it for the CSS’s signature.'],
    ['Signed & emailed', 'The signed copy is stored and emailed to every participant.'],
    ['On the calendar', 'The activity and names are added to the calendar for each day.'],
];
// HR completes the SO/RTA on the document itself (views/pages/special-order-edit.php).
$generateLink = function (array $o) {
    $editing = !empty($o['generatedAt']);
    echo '<a class="btn sm' . ($editing ? '' : ' primary') . '" href="' . h(page_url('special-order-edit', ['id' => $o['id']])) . '">' . ($editing ? 'Edit SO/RTA' : 'Generate SO/RTA') . '</a>';
};
?>
<div class="page-head">
    <div><h1>Special Orders</h1><p><?= $isAdmin ? 'Generate SO/RTAs for the CSS’s signature, then store and email the signed copies.' : 'Request a Special Order and open your signed copies.' ?></p></div>
    <?php if (!$isAdmin): ?><a class="btn primary" href="<?= h(page_url('special-order-request')) ?>">Request Special Order</a><?php endif; ?>
</div>

<ol class="so-steps" aria-label="How a Special Order is processed">
    <?php foreach ($steps as $i => [$title, $text]): ?>
        <li><span class="so-step-n"><?= $i + 1 ?></span><span><strong><?= h($title) ?></strong><small><?= h($text) ?></small></span></li>
    <?php endforeach; ?>
</ol>

<section class="card web-directory">
    <div class="card-head"><h3><?= $isAdmin ? 'Special Order requests' : 'My Special Orders' ?></h3></div>
    <div class="card-body toolbar">
        <?php foreach ($stages as $value => $label): ?>
            <a class="chip-btn<?= $value === $stage ? ' on' : '' ?>" href="<?= h(page_url('special-orders', ['stage' => $value])) ?>"><?= h($label) ?> <span class="n"><?= $counts[$value] ?? 0 ?></span></a>
        <?php endforeach; ?>
    </div>
    <?php
    $headers = $isAdmin ? ['SO No.', 'Participants', 'Activity', 'Dates', 'Stage', 'Actions'] : ['SO No.', 'Activity', 'Dates', 'Stage', 'Actions'];
    web_table($headers, array_map(function ($o) use ($isAdmin, $people, $groups, $generateLink) {
        $stage = special_order_stage($o);
        $members = $groups[$o['docId']] ?? [$o];
        $row = [$o['soNumber'] ?: '—'];
        if ($isAdmin) {
            $row[] = function () use ($members, $people, $o) {
                $names = array_map(function ($m) use ($people) { return isset($people[$m['empId']]) ? employee_name($people[$m['empId']]) : 'Former employee'; }, $members);
                $requester = $people[$o['empId']] ?? null;
                if (count($names) === 1) {
                    echo $requester ? '<a href="' . h(page_url('employees', ['id' => $requester['id']])) . '">' . h($names[0]) . '</a><span class="sub">' . h($requester['title']) . '</span>' : h($names[0]);
                    return;
                }
                echo '<strong>' . count($names) . ' participants</strong><span class="sub">' . h(implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ' +' . (count($names) - 4) . ' more' : '')) . '</span>';
            };
        }
        $row[] = function () use ($o) {
            echo h($o['purpose']);
            if ($o['destination']) echo '<span class="sub">' . h($o['destination']) . '</span>';
            if ($o['details']) echo '<span class="sub">' . h($o['details']) . '</span>';
        };
        $row[] = special_order_dates($o['start'], $o['end']);
        $row[] = function () use ($o, $stage, $isAdmin, $members) {
            special_order_stage_badge($stage);
            if ($stage === 'signed') {
                if ($isAdmin) {
                    // Mail setup problems are for HR to fix; employees just see whether it was emailed.
                    $emailed = count(array_filter($members, function ($m) { return !empty($m['emailedAt']); }));
                    $errors = array_values(array_filter(array_column($members, 'emailError')));
                    echo '<span class="sub">' . ($emailed === count($members) ? 'Emailed' . (count($members) > 1 ? ' to all ' . count($members) : '')
                        : 'Emailed to ' . $emailed . ' of ' . count($members) . ($errors ? ' · ' . h($errors[0]) : '')) . '</span>';
                } else {
                    echo '<span class="sub">' . ($o['emailedAt'] ? 'Emailed ' . h(substr($o['emailedAt'], 0, 16)) : 'Not emailed yet') . '</span>';
                }
            } elseif ($stage === 'requested' && $o['status'] === 'approved') {
                echo '<span class="sub">Approved by ' . h($o['decisionRole'] === 'hr_admin' ? 'HR' : 'supervisor') . ($o['decidedBy'] ? ' · ' . h($o['decidedBy']) : '') . '</span>';
            }
        };
        $row[] = function () use ($o, $stage, $isAdmin, $members, $generateLink) {
            if ($stage === 'signed' && $o['hasSignedFile']) {
                echo '<a class="btn sm" href="' . h(page_url('special-order-file', ['id' => $o['docId']])) . '" target="_blank" rel="noopener">View signed SO</a>';
            }
            if (!$isAdmin) {
                // Once HR has prepared it, the employee can open and print the SO/RTA as it will be issued.
                if ($stage === 'for_signature') echo '<a class="btn sm primary" href="' . h(page_url('special-order-print', ['id' => $o['id']])) . '" target="_blank" rel="noopener">View / print SO</a>';
                if ($o['status'] === 'pending' && $stage === 'requested') confirm_action('employee.request.cancel', 'Cancel', 'Cancel this Special Order request?', ['id' => $o['id'], 'empId' => $o['empId']]);
                return;
            }
            if ($stage === 'requested') {
                $generateLink($o);
                if ($o['status'] === 'pending') confirm_action('employee.request.decide', 'Decline', 'Decline this Special Order request?', ['id' => $o['id'], 'status' => 'rejected']);
            } elseif ($stage === 'for_signature') {
                echo '<a class="btn sm primary" href="' . h(page_url('special-order-print', ['id' => $o['id']])) . '" target="_blank" rel="noopener">Print for signature</a>';
                $generateLink($o);
                $n = count($members);
                echo '<details class="form-disclosure" data-form-dialog="form"><summary class="btn sm">Upload signed SO</summary>';
                echo '<p>Upload the scanned SO signed by the CSS. It is stored here, emailed to ' . ($n === 1 ? 'the participant' : 'all ' . $n . ' participants') . ' and put on the calendar.</p>';
                post_form('specialorder.sign', ['id' => $o['id']], false, 'multipart/form-data');
                echo '<label class="field"><span>Signed SO (PDF, JPG or PNG, up to 5 MB)</span><input class="input" type="file" name="signedFile" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></label>';
                end_form('Save, email & add to calendar');
                echo '</details>';
            } elseif ($stage === 'signed') {
                $pending = count(array_filter($members, function ($m) { return empty($m['emailedAt']); }));
                action_button('specialorder.email', $pending ? 'Send email' . (count($members) > 1 ? ' (' . $pending . ')' : '') : 'Resend email', ['id' => $o['id']]);
            }
        };
        return $row;
    }, $list['rows']));
    pagination($list, 'special-orders', ['stage' => $stage], 'Special Orders');
    ?>
</section>
