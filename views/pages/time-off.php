<?php
$status = (string) ($_GET['status'] ?? 'pending');
$type = (string) ($_GET['type'] ?? '');
$tabs = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Declined', '' => 'All'];
$counts = array_count_values(array_column($state['leaves'], 'status'));
$counts[''] = count($state['leaves']);
$employees = employee_options($state['employees']);
$active = employee_options(array_filter($state['employees'], fn($e) => $e['status'] === 'Active'));
$requests = array_filter($state['leaves'], fn($l) =>
    ($status === '' || $l['status'] === $status) && ($type === '' || $l['type'] === $type));
$monday = (new DateTimeImmutable($state['today']))->modify('monday this week');
$friday = $monday->modify('+4 days')->format('Y-m-d');
$approved = array_filter($state['leaves'], fn($l) => $l['status'] === 'approved');
usort($approved, fn($a, $b) => $a['start'] <=> $b['start']);
$out = array_filter($approved, fn($l) => $l['start'] <= $friday && $l['end'] >= $monday->format('Y-m-d'));
$upcoming = array_slice(array_filter($approved, fn($l) => $l['start'] > $friday), 0, 6);
?>
<div class="page-head">
    <div><h1>Time off</h1><p>Review requests, check who is out and manage leave balances.</p></div>
    <?php if ($active): ?><a class="btn primary" href="#new-request"><?= icon('plus') ?> New request</a><?php endif; ?>
</div>
<div class="grid cols-main">
    <section class="card web-directory">
        <div class="card-head"><h3>Leave requests</h3></div>
        <div class="card-body toolbar">
            <?php foreach ($tabs as $value => $label): ?>
                <a class="chip-btn<?= $value === $status ? ' on' : '' ?>" href="<?= h(page_url('time-off', ['status' => $value, 'type' => $type])) ?>"><?= h($label) ?> <span class="n"><?= $counts[$value] ?? 0 ?></span></a>
            <?php endforeach; ?>
            <?php get_form('time-off'); ?>
            <input type="hidden" name="status" value="<?= h($status) ?>">
            <select class="input sm" name="type" aria-label="Leave type">
                <?php foreach (['' => 'All leave types'] + options(LEAVE_TYPES) as $value => $label): ?>
                    <option value="<?= h($value) ?>"<?= $type === $value ? ' selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn sm">Filter</button></form>
        </div>
        <?php leave_table($state, $requests); ?>
        <?php if ($active): ?><div class="card-body" id="new-request"><?php leave_form($active, true); ?></div><?php endif; ?>
    </section>
    <div class="web-stack">
        <?php foreach (['Out this week' => $out, 'Coming up' => $upcoming] as $heading => $list): ?>
            <section class="card">
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
            <dl class="card-body dl">
                <?php foreach ($state['settings']['allowances'] as $label => $days): ?><dt><?= h($label) ?></dt><dd><?= h($days) ?></dd><?php endforeach; ?>
            </dl>
        </section>
    </div>
</div>
