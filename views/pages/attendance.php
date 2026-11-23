<?php
$date = selected_date($state['today']);
$day = new DateTimeImmutable($date);
$records = attendance_records($state, $date);
$future = $date > $state['today'];
$counts = ['office' => 0, 'remote' => 0, 'away' => 0, 'leave' => 0, '' => 0];
$labels = ['office' => 'In office', 'remote' => 'Remote', 'away' => 'Away', 'leave' => 'On leave', '' => 'Not recorded'];
$department = (string) ($_GET['department'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
foreach ($records as [, $record, $onLeave]) {
    $key = $onLeave ? 'leave' : $record['status'];
    if (isset($counts[$key])) $counts[$key]++;
}
$people = array_filter($records, fn($item) =>
    ($department === '' || $item[0]['department'] === $department)
    && ($search === '' || mb_stripos(employee_name($item[0]), $search) !== false));
$filters = ['department' => $department, 'q' => $search];
$pageRows = paginate($people);
?>
<div class="page-head">
    <div><h1>Attendance</h1><p>Record where people worked and when they clocked in and out.</p></div>
    <div class="actions">
        <a class="btn" href="<?= h(page_url('attendance', ['date' => $day->modify('-1 day')->format('Y-m-d')] + $filters)) ?>" aria-label="Previous day"><?= icon('chevLeft') ?></a>
        <?php get_form('attendance'); ?>
        <input type="hidden" name="department" value="<?= h($department) ?>"><input type="hidden" name="q" value="<?= h($search) ?>">
        <input class="input" type="date" name="date" value="<?= h($date) ?>" aria-label="Day" required>
        <button class="btn">Show</button></form>
        <a class="btn" href="<?= h(page_url('attendance', ['date' => $day->modify('+1 day')->format('Y-m-d')] + $filters)) ?>" aria-label="Next day"><?= icon('chevRight') ?></a>
        <?php if ($date !== $state['today']) link_to('attendance', 'Today', $filters, 'btn'); ?>
        <a class="btn" href="<?= h(page_url('attendance', ['date' => $date, 'export' => 1])) ?>"><?= icon('download') ?> Export</a>
        <?php if (!$future) action_button('attendance.fill', 'Mark unrecorded as in office', ['date' => $date]); ?>
    </div>
</div>
<div class="toolbar">
    <?php foreach ($labels as $key => $label): ?><span class="pill"><?= h($label) ?> <strong><?= $counts[$key] ?></strong></span><?php endforeach; ?>
    <span class="muted web-push"><?= h($day->format('l, M j')) ?></span>
</div>
<section class="card web-directory">
    <div class="card-head"><h3>Daily register</h3></div>
    <form class="card-body toolbar" method="get">
        <input type="hidden" name="page" value="attendance"><input type="hidden" name="date" value="<?= h($date) ?>">
        <input class="input grow" type="search" name="q" placeholder="Find a person" aria-label="Find a person" value="<?= h($search) ?>">
        <select class="input" name="department" aria-label="Department">
            <?php foreach (['' => 'All departments'] + options(DEPARTMENTS) as $value => $label): ?>
                <option value="<?= h($value) ?>"<?= $value === $department ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn">Filter</button>
        <?php link_to('attendance', 'Clear filters', ['date' => $date], 'btn ghost'); ?>
        <?php if (!$future): ?><span class="muted web-push"><?= count($people) ?> matches</span><?php endif; ?>
    </form>
    <?php if ($future || !$people): ?>
        <div class="empty"><strong><?= $future ? "This day hasn't happened yet" : 'Nobody matches' ?></strong>
            <?php if (!$future && ($department !== '' || $search !== '')) link_to('attendance', 'Clear filters', ['date' => $date], 'btn sm'); ?>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data">
                <thead><tr><th>Employee</th><th>Status</th><th>Clock in</th><th>Clock out</th><th>Hours</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($pageRows['rows'] as [$person, $record, $onLeave]):
                    $name = employee_name($person);
                    $id = 'attendance-' . $person['id'];
                    $minutes = $record['in'] && $record['out'] ? (strtotime($record['out']) - strtotime($record['in'])) / 60 : 0;
                ?>
                    <tr>
                        <td><a class="who" href="<?= h(page_url('employees', ['id' => $person['id']])) ?>"><span class="avatar"><?= h(initials($name)) ?></span><span><strong><?= h($name) ?></strong><small><?= h($person['department']) ?></small></span></a></td>
                        <td>
                            <?php if ($onLeave): ?><?php status_badge('leave'); ?>
                            <?php else: ?>
                                <select class="input sm" name="status" form="<?= h($id) ?>" aria-label="<?= h("Status for $name") ?>">
                                    <?php foreach (['' => 'Not recorded'] + array_intersect_key($labels, options(ATT_STATUSES)) as $value => $label): ?>
                                        <option value="<?= h($value) ?>"<?= $value === $record['status'] ? ' selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <td><input class="input sm" type="time" name="in" value="<?= h($record['in']) ?>" form="<?= h($id) ?>" aria-label="<?= h("Clock in for $name") ?>"<?= $onLeave ? ' disabled' : '' ?>></td>
                        <td><input class="input sm" type="time" name="out" value="<?= h($record['out']) ?>" form="<?= h($id) ?>" aria-label="<?= h("Clock out for $name") ?>"<?= $onLeave ? ' disabled' : '' ?>></td>
                        <td><?= $minutes > 0 ? h(sprintf('%dh %02dm', intdiv((int) $minutes, 60), $minutes % 60)) : '—' ?></td>
                        <td class="actions">
                            <?php if (!$onLeave): ?>
                                <form method="post" id="<?= h($id) ?>">
                                    <?php hidden_inputs(['_action' => 'attendance.set', '_token' => $_SESSION['web_csrf'], 'date' => $date, 'empId' => $person['id']]); ?>
                                    <button class="btn sm">Save</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php pagination($pageRows, 'attendance', ['date' => $date] + $filters, 'employees'); ?>
    <?php endif; ?>
</section>
