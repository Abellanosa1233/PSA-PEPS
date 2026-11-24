<?php
$date = selected_date($state['today']);
$day = new DateTimeImmutable($date);
$records = attendance_records($state, $date);
$future = $date > $state['today'];
$past = $date < $state['today'];
$counts = ['office' => 0, 'remote' => 0, 'away' => 0, 'leave' => 0, '' => 0];
$labels = ['office' => 'In office', 'remote' => 'Remote', 'away' => 'Away', 'leave' => 'On leave', '' => 'Not recorded'];
$department = (string) ($_GET['department'] ?? '');
$search = trim((string) ($_GET['q'] ?? ''));
foreach ($records as [, $record, $onLeave]) {
    $key = $onLeave ? 'leave' : $record['status'];
    if (isset($counts[$key])) $counts[$key]++;
}
$people = array_filter($records, function ($item) use ($department, $search) { return ($department === '' || $item[0]['department'] === $department)
    && ($search === '' || mb_stripos(employee_name($item[0]), $search) !== false); });
$filters = ['department' => $department, 'q' => $search];
$pageRows = paginate($people);
$searchIndex = $future ? [] : array_values(array_map(function ($item) {
    return ['name' => employee_name($item[0]), 'department' => $item[0]['department']];
}, $records));
$now = new DateTimeImmutable();
?>
<div class="page-head">
    <div>
        <h1>Attendance</h1>
        <p>Record where people worked and when they clocked in and out.</p>
        <div class="attendance-clock">
            <span class="attendance-clock-icon"><?= icon('clock', 16) ?></span>
            <div>
                <span class="attendance-clock-label">Current time</span>
                <time id="attendance-current-time" datetime="<?= h($now->format(DateTimeInterface::ATOM)) ?>" data-time-zone="<?= h(APP_TIMEZONE) ?>"><?= h($now->format('g:i:s A')) ?></time>
            </div>
        </div>
    </div>
    <div class="actions">
        <a class="btn" href="<?= h(page_url('attendance', ['date' => $day->modify('-1 day')->format('Y-m-d')] + $filters)) ?>" aria-label="Previous day"><?= icon('chevLeft') ?></a>
        <?php get_form('attendance'); ?>
        <input type="hidden" name="department" value="<?= h($department) ?>"><input type="hidden" name="q" value="<?= h($search) ?>">
        <input class="input" type="date" name="date" value="<?= h($date) ?>" aria-label="Day" required>
        <button class="btn">Show</button></form>
        <a class="btn" href="<?= h(page_url('attendance', ['date' => $day->modify('+1 day')->format('Y-m-d')] + $filters)) ?>" aria-label="Next day"><?= icon('chevRight') ?></a>
        <?php if ($date !== $state['today']) link_to('attendance', 'Today', $filters, 'btn'); ?>
        <a class="btn" href="<?= h(page_url('attendance', ['date' => $date, 'export' => 'xlsx'])) ?>"><?= icon('download') ?> Export Excel</a>
        <details class="attendance-import">
            <summary class="btn"><?= icon('file') ?> Import CSV</summary>
            <div class="attendance-import-panel">
                <strong>Import attendance records</strong>
                <p>Use employee email to identify people, dates in YYYY-MM-DD format, and statuses office, remote, or away. Blank and leave rows are skipped.</p>
                <a href="<?= h(page_url('attendance', ['template' => 1])) ?>">Download CSV template</a>
                <?php post_form('attendance.import', [], false, 'multipart/form-data'); ?>
                <div class="field"><label for="attendanceCsv">CSV file</label><input class="input" id="attendanceCsv" name="attendanceCsv" type="file" accept=".csv,text/csv" required></div>
                <?php end_form('Import attendance'); ?>
                <small>Maximum 2 MB and 5,000 records. Past dates are read-only. The import is all-or-nothing.</small>
            </div>
        </details>
        <?php if (!$future && !$past) action_button('attendance.fill', 'Mark unrecorded as in office', ['date' => $date]); ?>
    </div>
</div>
<script>
    (function () {
        const clock = document.getElementById('attendance-current-time');
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: clock.dataset.timeZone,
            hour: 'numeric',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });
        function updateClock() {
            const now = new Date();
            clock.textContent = formatter.format(now);
            clock.dateTime = now.toISOString();
        }
        updateClock();
        window.setInterval(updateClock, 1000);
    }());
</script>
<div class="toolbar">
    <?php foreach ($labels as $key => $label): ?><span class="pill"><?= h($label) ?> <strong><?= $counts[$key] ?></strong></span><?php endforeach; ?>
    <span class="muted web-push"><?= h($day->format('l, M j')) ?></span>
</div>
<section class="card web-directory" id="attendance-directory">
    <div class="card-head"><h3>Daily register</h3><?php if ($past): ?><p>Read-only</p><?php endif; ?></div>
    <form class="card-body toolbar" method="get" action="<?= h(page_url('attendance')) ?>">
        <input type="hidden" name="date" value="<?= h($date) ?>">
        <div class="grow employee-search">
            <input class="input" type="search" name="q" placeholder="Find a person" aria-label="Find a person" value="<?= h($search) ?>">
            <div id="attendance-suggestions" role="listbox" aria-label="Attendance search suggestions" data-employees="<?= html_json($searchIndex) ?>" hidden></div>
        </div>
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
                <thead><tr><th>Employee</th><th>Status</th><th>Clock in</th><th>Clock out</th><th>Hours</th><?php if (!$past): ?><th>Actions</th><?php endif; ?></tr></thead>
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
                            <?php elseif ($past): ?><?= h($labels[$record['status']] ?? $labels['']) ?>
                            <?php else: ?>
                                <select class="input sm" name="status" form="<?= h($id) ?>" aria-label="<?= h("Status for $name") ?>">
                                    <?php foreach (['' => 'Not recorded'] + array_intersect_key($labels, options(ATT_STATUSES)) as $value => $label): ?>
                                        <option value="<?= h($value) ?>"<?= $value === $record['status'] ? ' selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <?php foreach (['in' => 'Clock in', 'out' => 'Clock out'] as $key => $label): ?>
                            <td>
                                <?php if (!$past): ?>
                                    <input class="input sm" type="time" name="<?= h($key) ?>" value="<?= h($record[$key]) ?>" form="<?= h($id) ?>" aria-label="<?= h("$label for $name") ?>"<?= $onLeave ? ' disabled' : '' ?>>
                                <?php elseif ($record[$key]): ?>
                                    <time datetime="<?= h($record[$key]) ?>"><?= h(date('g:i A', strtotime($record[$key]))) ?></time>
                                <?php else: ?>
                                    <span class="muted">Did not clock <?= h($key) ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td><?= $minutes > 0 ? h(sprintf('%dh %02dm', intdiv((int) $minutes, 60), $minutes % 60)) : '—' ?></td>
                        <?php if (!$past): ?><td class="actions">
                            <?php if (!$onLeave): ?>
                                <form method="post" id="<?= h($id) ?>">
                                    <?php hidden_inputs(['_action' => 'attendance.set', '_token' => $_SESSION['web_csrf'], 'date' => $date, 'empId' => $person['id']]); ?>
                                    <button class="btn sm">Save</button>
                                </form>
                            <?php endif; ?>
                        </td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php pagination($pageRows, 'attendance', ['date' => $date] + $filters, 'employees'); ?>
    <?php endif; ?>
</section>
<script src="<?= h(versioned_asset('assets/js/employee-search.js')) ?>" defer></script>
