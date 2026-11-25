<?php
$status = (string) ($_GET['status'] ?? 'pending');
$type = (string) ($_GET['type'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
$tabs = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Declined', '' => 'All'];
$counts = array_count_values(array_column($state['leaves'], 'status'));
$counts[''] = count($state['leaves']);
$employees = employee_options($state['employees']);
$active = employee_options(array_filter($state['employees'], function ($e) { return $e['status'] === 'Active'; }));
$requests = array_filter($state['leaves'], function ($l) use ($status, $type, $search, $employees) { return ($status === '' || $l['status'] === $status) && ($type === '' || $l['type'] === $type)
    && ($search === '' || mb_stripos($employees[$l['empId']] ?? '', $search) !== false); });
$pageRows = paginate($requests);
$monday = (new DateTimeImmutable($state['today']))->modify('monday this week');
$friday = $monday->modify('+4 days')->format('Y-m-d');
$approved = array_filter($state['leaves'], function ($l) { return $l['status'] === 'approved'; });
usort($approved, function ($a, $b) { return $a['start'] <=> $b['start']; });
$out = array_filter($approved, function ($l) use ($friday, $monday) { return $l['start'] <= $friday && $l['end'] >= $monday->format('Y-m-d'); });
$upcoming = array_slice(array_filter($approved, function ($l) use ($friday) { return $l['start'] > $friday; }), 0, 6);
?>
<div class="page-head">
    <div><h1>Time off</h1><p>Review requests, check who is out and manage leave balances.</p></div>
    <?php leave_form($active, true); ?>
</div>
<?php
$orderRequests = paginate($state['employeeRequests'], 'orderRequestPage');
card('Special and travel order requests');
web_table(['Employee', 'Request', 'Purpose / destination', 'Dates', 'Status', 'Actions'], array_map(function ($request) use ($employees) {
    return [
        $employees[$request['empId']] ?? 'Former employee',
        $request['type'] === 'travel_order' ? 'Travel Order' : 'Special Order',
        function () use ($request) {
            echo h($request['purpose']);
            if ($request['destination']) echo '<span class="sub">' . h($request['destination']) . '</span>';
            if ($request['details']) echo '<span class="sub">' . h($request['details']) . '</span>';
        },
        $request['start'] . ($request['end'] ? ' – ' . $request['end'] : ''),
        function () use ($request) {
            status_badge($request['status']);
            if ($request['decisionRole']) echo '<span class="sub">Reviewed by ' . h($request['decisionRole'] === 'hr_admin' ? 'HR administrator' : 'supervisor') . ($request['decidedBy'] ? ' · ' . h($request['decidedBy']) : '') . '</span>';
        },
        function () use ($request) {
            if ($request['status'] === 'pending') {
                action_button('employee.request.decide', 'Approve', ['id' => $request['id'], 'status' => 'approved']);
                action_button('employee.request.decide', 'Decline', ['id' => $request['id'], 'status' => 'rejected']);
            }
        },
    ];
}, $orderRequests['rows']));
pagination($orderRequests, 'time-off', [], 'requests', 'orderRequestPage');
echo '</section>';
?>
<div class="grid cols-main">
    <section class="card web-directory">
        <div class="card-head"><h3>Leave requests</h3></div>
        <div class="card-body toolbar">
            <?php foreach ($tabs as $value => $label): ?>
                <a class="chip-btn<?= $value === $status ? ' on' : '' ?>" href="<?= h(page_url('time-off', ['status' => $value, 'type' => $type, 'q' => $search])) ?>"><?= h($label) ?> <span class="n"><?= $counts[$value] ?? 0 ?></span></a>
            <?php endforeach; ?>
            <?php get_form('time-off'); ?>
            <input type="hidden" name="status" value="<?= h($status) ?>">
            <input class="input sm" type="search" name="q" value="<?= h($search) ?>" placeholder="Find an employee" aria-label="Find an employee">
            <select class="input sm" name="type" aria-label="Leave type">
                <?php foreach (['' => 'All leave types'] + options(LEAVE_TYPES) as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $type === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn sm">Filter</button></form>
            <?php link_to('time-off', 'Clear filters', ['status' => ''], 'btn ghost'); ?>
            <span class="muted web-push"><?= count($requests) ?> matches</span>
        </div>
        <?php if ($requests): ?>
            <?php leave_table($state, $pageRows['rows']); ?>
            <?php pagination($pageRows, 'time-off', ['status' => $status, 'type' => $type, 'q' => $search], 'requests'); ?>
        <?php else: ?>
            <div class="empty"><strong>No matching requests</strong><?php link_to('time-off', 'Clear filters', ['status' => ''], 'btn sm'); ?></div>
        <?php endif; ?>
    </section>
    <div class="web-stack">
        <?php foreach (['Out this week' => $out, 'Coming up' => $upcoming] as $heading => $list): ?>
            <section class="card<?= $heading === 'Out this week' ? ' timeoff-out-card' : '' ?>">
                <div class="card-head"><h3><?= h($heading) ?></h3></div>
                <div class="card-body web-people">
                    <?php if (!$list): ?><p class="muted">No approved leave.</p><?php endif; ?>
                    <?php foreach ($list as $request): $name = $employees[$request['empId']] ?? 'Former employee'; ?>
                        <a class="who" href="<?= h(page_url('employees', ['id' => $request['empId']])) ?>">
                            <span class="avatar"><?= h(initials($name)) ?></span>
                            <span><strong><?= h($name) ?></strong><small><?= h($request['type'] . ' · ' . $request['start'] . ' – ' . $request['end']) ?></small></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
        <section class="card">
            <div class="card-head"><div><h3>Leave policy</h3><p>Days per person per year</p></div><a class="link-btn" href="<?= h(page_url('preferences')) ?>">Edit</a></div>
            <dl class="card-body dl leave-policy-list">
                <?php foreach ($state['settings']['allowances'] as $label => $days): ?><dt><?= h($label) ?></dt><dd><span class="leave-policy-days"><?= h($days) ?> days</span></dd><?php endforeach; ?>
            </dl>
        </section>
    </div>
</div>
