<?php
$currentMonth = new DateTimeImmutable(substr($state['today'], 0, 7) . '-01');
$selectedDate = $_GET['date'] ?? '';
if (!is_string($selectedDate) || !preg_match('/^([1-9]\d{3})-(\d{2})-(\d{2})$/D', $selectedDate, $parts)
    || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
    $selectedDate = '';
}
$month = $_GET['month'] ?? '';
if ($selectedDate !== '') $month = substr($selectedDate, 0, 7);
$first = is_string($month) && preg_match('/^[1-9]\d{3}-(0[1-9]|1[0-2])$/D', $month)
    ? new DateTimeImmutable($month . '-01') : $currentMonth;
$last = $first->modify('last day of this month');
$from = $first->format('Y-m-d');
$through = $last->format('Y-m-d');
$previousMonth = $first->modify('-1 month')->format('Y-m');
$nextMonth = $first->modify('+1 month')->format('Y-m');
$weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$holidayYears = require __DIR__ . '/../../api/lib/philippine-holidays.php';
$holidays = $holidayYears[$first->format('Y')] ?? [];
$holidayTypes = [
    'regular' => 'Regular holiday',
    'non-working' => 'Special non-working day',
    'working' => 'Special working day',
];
$pickerDate = new DateTimeImmutable($selectedDate ?: ($first == $currentMonth ? $state['today'] : $from));
$activities = q("SELECT CONCAT(e.first_name, ' ', e.last_name) AS employee, r.request_type AS kind,
                         r.start_date AS starts, COALESCE(r.end_date, r.start_date) AS ends,
                         CASE WHEN r.request_type = 'coe' THEN r.purpose END AS project
                    FROM employee_requests r JOIN employees e ON e.id = r.emp_id
                    LEFT JOIN employee_documents d ON d.id = r.coe_document_id AND d.emp_id = r.emp_id
                   WHERE r.start_date <= ? AND COALESCE(r.end_date, r.start_date) >= ?
                     AND ((r.request_type IN ('special_order', 'travel_order', 'employment_memo') AND r.status = 'approved')
                          OR (r.request_type = 'coe' AND d.id IS NOT NULL))
                   UNION ALL
                  SELECT CONCAT(e.first_name, ' ', e.last_name), 'leave', l.start_date, l.end_date, NULL
                    FROM leave_requests l JOIN employees e ON e.id = l.emp_id
                   WHERE l.status = 'approved' AND l.start_date <= ? AND l.end_date >= ?
                   ORDER BY employee, starts", [$through, $from, $through, $from])->fetchAll();
$byDay = [];
foreach ($state['events'] as $event) {
    if ($event['forEmployees'] && $event['date'] >= $from && $event['date'] <= $through) {
        $byDay[$event['date']][] = ['label' => $event['title'], 'group' => 'Company events', 'detail' => 'Company event', 'period' => ''];
    }
}
foreach ($activities as $item) {
    $start = new DateTimeImmutable(max($item['starts'], $from));
    $end = min($item['ends'], $through);
    $label = $item['kind'] === 'leave'
        ? 'Out of office'
        : (trim($item['project'] ?? '') ?: employee_request_label($item['kind']));
    $period = self_date_range($item['starts'], $item['ends']);
    for ($date = $start; $date->format('Y-m-d') <= $end; $date = $date->modify('+1 day')) {
        $byDay[$date->format('Y-m-d')][] = ['label' => $item['employee'], 'group' => $label, 'detail' => $label . ' · ' . $period, 'period' => $period];
    }
}
foreach ($holidays as $monthDay => [$name, $type]) {
    $day = $first->format('Y') . '-' . $monthDay;
    if ($day < $from || $day > $through) continue;
    $byDay[$day] = array_merge([
        ['label' => $name, 'group' => 'Philippine holidays', 'detail' => $holidayTypes[$type], 'period' => $holidayTypes[$type], 'holiday' => $type],
    ], $byDay[$day] ?? []);
}
$visibleCount = 3;
?>
<div class="page-head">
    <div><h1>Work calendar</h1><p>See approved assignments, who’s out of office, and company events.</p></div>
    <div class="actions">
        <a class="btn" href="<?= h(page_url('work-calendar', ['month' => $previousMonth])) ?>">Previous month</a>
        <a class="btn" href="<?= h(page_url('work-calendar')) ?>">This month</a>
        <a class="btn" href="<?= h(page_url('work-calendar', ['month' => $nextMonth])) ?>">Next month</a>
    </div>
</div>
<section class="card">
    <div class="card-head work-calendar-head">
        <h3><?= h($first->format('F Y')) ?></h3>
        <details class="work-date-picker"<?= ($_GET['picker'] ?? '') === '1' ? ' open' : '' ?>>
            <summary class="btn" aria-label="<?= h('Jump to date, ' . $pickerDate->format('F j, Y')) ?>">
                <?= icon('calendar', 16) ?> <span><?= h($pickerDate->format('M j, Y')) ?></span> <?= icon('chevDown', 14) ?>
            </summary>
            <div class="work-date-panel">
                <div class="work-date-nav">
                    <a class="btn sm" aria-label="Previous month" href="<?= h(page_url('work-calendar', ['month' => $previousMonth, 'picker' => 1])) ?>"><?= icon('chevLeft', 16) ?></a>
                    <strong><?= h($first->format('F Y')) ?></strong>
                    <a class="btn sm" aria-label="Next month" href="<?= h(page_url('work-calendar', ['month' => $nextMonth, 'picker' => 1])) ?>"><?= icon('chevRight', 16) ?></a>
                </div>
                <div class="work-date-grid">
                    <?php foreach ($weekdays as $weekday): ?>
                        <span class="work-date-weekday"><?= h($weekday) ?></span>
                    <?php endforeach; ?>
                    <?php for ($i = 1; $i < (int) $first->format('N'); $i++): ?>
                        <span aria-hidden="true"></span>
                    <?php endfor; ?>
                    <?php for ($date = $first; $date <= $last; $date = $date->modify('+1 day')):
                        $day = $date->format('Y-m-d');
                        $holiday = $holidays[$date->format('m-d')] ?? null;
                        $label = $date->format('l, F j, Y') . ($holiday ? ' · ' . $holiday[0] : '');
                        ?>
                        <a href="<?= h(page_url('work-calendar', ['date' => $day])) ?>#calendar-selected"
                            class="work-date-day<?= $holiday ? ' has-holiday' : '' ?>"
                            aria-label="<?= h($label) ?>" title="<?= h($label) ?>"
                            <?= $day === $pickerDate->format('Y-m-d') ? ' aria-current="date"' : '' ?>><?= h($date->format('j')) ?></a>
                    <?php endfor; ?>
                </div>
                <div class="work-date-footer">
                    <span><i class="work-holiday-dot" aria-hidden="true"></i> Philippine holiday</span>
                    <a class="btn sm" href="<?= h(page_url('work-calendar', ['date' => $state['today']])) ?>#calendar-selected">Today</a>
                </div>
            </div>
        </details>
    </div>
    <div class="table-wrap">
        <table class="data work-calendar" aria-label="<?= h($first->format('F Y') . ' work calendar') ?>">
            <thead><tr>
                <?php foreach ($weekdays as $weekday): ?>
                    <th scope="col"><?= h($weekday) ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
                <?php for ($week = $first->modify('monday this week'); $week <= $last; $week = $week->modify('+1 week')): ?>
                    <tr>
                        <?php for ($offset = 0; $offset < 7; $offset++):
                            $date = $week->modify('+' . $offset . ' days');
                            $day = $date->format('Y-m-d');
                            $entries = $byDay[$day] ?? [];
                            ?>
                            <td class="<?= $day === $state['today'] ? 'work-calendar-today' : '' ?>"<?= $day === $selectedDate ? ' id="calendar-selected"' : '' ?>>
                                <?php if ($date >= $first && $date <= $last): ?>
                                    <time datetime="<?= h($day) ?>"<?= $day === $state['today'] ? ' aria-current="date"' : '' ?>><?= h($date->format('j')) ?></time>
                                    <?php if ($entries): ?>
                                        <ul>
                                            <?php foreach (array_slice($entries, 0, $visibleCount) as $item): ?>
                                                <li<?= isset($item['holiday']) ? ' class="work-calendar-holiday is-' . h($item['holiday']) . '"' : '' ?> title="<?= h($item['label'] . ' · ' . $item['detail']) ?>">
                                                    <strong><?= h($item['label']) ?></strong><small><?= h($item['detail']) ?></small>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <?php if (count($entries) > $visibleCount): ?>
                                            <button type="button" class="link-btn work-calendar-more" data-work-calendar-dialog="work-calendar-day-<?= h($day) ?>"
                                                aria-haspopup="dialog" aria-label="<?= h('View all ' . count($entries) . ' entries for ' . $date->format('F j')) ?>">+<?= count($entries) - $visibleCount ?> more</button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
</section>
<?php foreach ($byDay as $day => $entries):
    if (count($entries) <= $visibleCount) continue;
    $groups = [];
    foreach ($entries as $item) {
        $groups[$item['group']][] = $item;
    }
    ?>
    <dialog class="modal sign-out-dialog wide work-calendar-day" id="work-calendar-day-<?= h($day) ?>" aria-labelledby="work-calendar-title-<?= h($day) ?>">
        <div class="modal-head"><h2 id="work-calendar-title-<?= h($day) ?>"><?= h((new DateTimeImmutable($day))->format('l, F j, Y')) ?></h2></div>
        <div class="modal-body">
            <?php foreach ($groups as $group => $items): ?>
                <section>
                    <h3><?= h($group) ?></h3>
                    <ul>
                        <?php foreach ($items as $item): ?>
                            <li><strong><?= h($item['label']) ?></strong><?php if ($item['period'] !== ''): ?><small><?= h($item['period']) ?></small><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        </div>
        <form method="dialog" class="modal-foot"><button type="submit" class="btn" autofocus>Close</button></form>
    </dialog>
<?php endforeach; ?>
<script>
document.querySelectorAll('[data-work-calendar-dialog]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById(button.dataset.workCalendarDialog).showModal();
    });
});
</script>
