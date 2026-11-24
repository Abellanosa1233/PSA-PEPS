<?php
$today = $state['today'];
$day = new DateTimeImmutable($today);
$firstName = explode(' ', trim($state['user']['name']))[0];
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$recent = $day->modify('-30 days')->format('Y-m-d');
$active = array_filter($state['employees'], function ($e) { return $e['status'] === 'Active'; });
$joined = count(array_filter($active, function ($e) use ($recent, $today) { return $e['startDate'] > $recent && $e['startDate'] <= $today; }));
$open = array_column(array_filter($state['positions'], function ($p) { return $p['status'] === 'open'; }), null, 'id');
$pipeline = array_filter($state['candidates'], function ($c) use ($open) { return isset($open[$c['positionId']]) && !in_array($c['stage'], ['Rejected', 'Hired'], true); });
$pending = array_values(array_filter($state['leaves'], function ($l) { return $l['status'] === 'pending'; }));
usort($pending, function ($a, $b) { return $a['start'] <=> $b['start']; });
$present = $out = 0;
$records = attendance_records($state, $today);
foreach ($records as [, $record, $onLeave]) {
    if ($onLeave) $out++;
    elseif (in_array($record['status'], ['office', 'remote'], true)) $present++;
}
$sick = count(array_filter($state['leaves'], function ($l) use ($today) { return $l['status'] === 'approved' && $l['type'] === 'Sick leave' && $l['start'] <= $today && $l['end'] >= $today; }
));
$metrics = [
    ['Total employees', 'users', count($active), '+' . $joined, 'joined in the last 30 days'],
    ['Present today', 'clock', $present, ($records ? round(100 * $present / count($records)) : 0) . '%', 'of your workforce'],
    ['On leave today', 'calendar', $out, max(0, $out - $sick) . ' planned', $sick . ' unplanned'],
    ['Open positions', 'briefcase', count($open), count($pipeline) . ' candidates', 'in the pipeline'],
];
$offset = max(-5, min(0, (int) ($_GET['week'] ?? ((int) $day->format('N') <= 2 ? -1 : 0))));
$thisMonday = $day->modify('monday this week');
$monday = $thisMonday->modify("$offset weeks");
$date = selected_date($today);
$scheduleEvents = array_map(function ($event) {
    $event['startLabel'] = date('g:i A', strtotime($event['start']));
    $event['endLabel'] = date('g:i A', strtotime($event['end']));
    return $event;
}, $state['events']);
$events = array_filter($scheduleEvents, function ($event) use ($date) { return $event['date'] === $date; });
$scheduleDay = new DateTimeImmutable($date);
$calendarMonth = $scheduleDay->modify('first day of this month');
?>
<div class="page-head">
    <div><h1><?= h("$greeting, $firstName") ?></h1><p>Review workforce activity and priority updates for today.</p></div>
    <div class="actions">
        <a class="btn" href="#schedule"><?= icon('calendar') ?> <?= h($day->format('l, M j')) ?></a>
    </div>
</div>
<div class="grid kpis">
    <?php foreach ($metrics as [$label, $glyph, $value, $pill, $note]): ?>
        <section class="card kpi">
            <div class="kpi-top"><span><?= h($label) ?></span><?= icon($glyph) ?></div>
            <div class="kpi-value"><?= number_format($value) ?></div>
            <div class="kpi-foot"><span class="pill"><?= h($pill) ?></span><span><?= h($note) ?></span></div>
        </section>
    <?php endforeach; ?>
</div>
<div class="grid cols-main">
    <section class="card attendance-overview">
        <div class="card-head">
            <div><h3>Attendance overview</h3><p>A little perspective on your team’s week.</p></div>
            <form method="get" class="attendance-week">
                <input type="hidden" name="page" value="overview">
                <select class="input sm" name="week" aria-label="Week">
                    <?php for ($week = 0; $week >= -5; $week--):
                        $from = $thisMonday->modify("$week weeks");
                        $to = $from->modify('+4 days');
                        $range = $from->format('M j') . ' – ' . ($from->format('Y-m') === $to->format('Y-m') ? $to->format('j') : $to->format('M j')); ?>
                        <option value="<?= $week ?>"<?= $week === $offset ? ' selected' : '' ?>><?= h($range) ?></option>
                    <?php endfor; ?>
                </select>
                <button class="btn sm" type="submit">Show</button>
            </form>
        </div>
        <div class="legend attendance-legend"><span><i class="attendance-office"></i>In office</span><span><i class="attendance-remote"></i>Remote</span><span><i class="attendance-away"></i>Away</span></div>
        <div class="chart attendance-chart">
            <svg viewBox="0 0 760 210" role="img" aria-label="Daily attendance as a share of the workforce">
                <?php foreach ([0, 50, 100] as $tick): $y = 184 - 1.74 * $tick; ?>
                    <line class="grid-line" x1="58" x2="752" y1="<?= $y ?>" y2="<?= $y ?>"/>
                    <text x="46" y="<?= $y + 4 ?>" text-anchor="end"><?= $tick ?>%</text>
                <?php endforeach; ?>
                <?php for ($i = 0; $i < 5; $i++):
                    $dateObject = $monday->modify("+$i days");
                    $workday = $dateObject->format('Y-m-d');
                    $daily = attendance_records($state, $workday);
                    $counts = ['office' => 0, 'remote' => 0, 'away' => 0];
                    foreach ($daily as [, $record, $onLeave]) {
                        if ($onLeave || ($record['status'] === '' && $workday < $today)) $counts['away']++;
                        elseif (isset($counts[$record['status']])) $counts[$record['status']]++;
                    }
                    $x = 58 + $i * 138.8 + 45.4;
                    $clipId = 'attendance-clip-' . $i;
                    $base = 184;
                ?>
                    <a href="<?= h(page_url('attendance', ['date' => $workday])) ?>">
                        <text x="<?= $x + 24 ?>" y="204" text-anchor="middle"><?= h($dateObject->format('D')) ?></text>
                        <?php if (!$daily || $workday > $today): ?>
                            <rect x="<?= $x ?>" y="10" width="48" height="174" rx="4" fill="none" stroke="var(--line-strong)" stroke-dasharray="3 3"><title>No data yet</title></rect>
                        <?php else: ?>
                            <defs><clipPath id="<?= h($clipId) ?>"><rect x="<?= $x ?>" y="10" width="48" height="174" rx="4"/></clipPath></defs>
                            <g clip-path="url(#<?= h($clipId) ?>)">
                            <?php foreach ($counts as $status => $count):
                                if (!$count) continue;
                                $height = 174 * $count / count($daily);
                                $base -= $height; ?>
                                <rect class="attendance-<?= h($status) ?>" x="<?= $x ?>" y="<?= $base ?>" width="48" height="<?= $height ?>"><title><?= h(['office' => 'In office', 'remote' => 'Remote', 'away' => 'Away'][$status] . ' · ' . $count) ?></title></rect>
                            <?php endforeach; ?>
                            </g>
                        <?php endif; ?>
                    </a>
                <?php endfor; ?>
            </svg>
        </div>
    </section>
    <section class="card schedule-card" id="schedule">
        <div class="card-head">
            <div><h3><?= $date === $today ? "Today's schedule" : 'Schedule' ?></h3><p><?= h($scheduleDay->format('D, j M')) ?></p></div>
            <button class="btn sm" type="button" data-open-calendar><?= icon('calendar', 14) ?> View calendar</button>
        </div>
        <?php if ($events): ?>
            <div class="sched">
                <?php foreach ($events as $event): ?>
                    <div class="sched-item">
                        <div class="t"><strong><?= h($event['startLabel']) ?></strong><small><?= h($event['endLabel']) ?></small></div><div class="rule"></div>
                        <div class="e"><strong><?= h($event['title']) ?></strong><small><?= h($event['detail']) ?></small>
                            <?php confirm_action('event.delete', 'Remove', 'Remove this scheduled event?', ['id' => $event['id']]); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty"><strong>No events scheduled</strong></div>
        <?php endif; ?>
        <div class="card-body schedule-toolbar" id="schedule-date">
            <form method="get" class="web-fields" action="<?= h(page_url('overview')) ?>">
                <?php hidden_inputs(array_intersect_key($_GET, ['week' => true])); ?>
                <?php field('date', 'Schedule date', $date, 'date'); ?>
                <div class="field schedule-date-picker" hidden>
                    <span id="schedule-date-label">Schedule date</span>
                    <button class="btn" type="button" data-pick-schedule-date aria-haspopup="dialog" aria-controls="schedule-calendar-modal" aria-labelledby="schedule-date-label schedule-date-value">
                        <?= icon('calendar', 16) ?><span id="schedule-date-value"><?= h($scheduleDay->format('M j, Y')) ?></span><?= icon('chevDown', 14) ?>
                    </button>
                </div>
                <button class="btn" type="submit">Show events</button>
            </form>
            <details class="form-disclosure" data-form-dialog="form"><summary class="btn primary"><?= icon('plus', 14) ?> Add event</summary>
                <?php post_form('event.save'); field('date', 'Event date', $date, 'date'); field('title', 'Event title'); field('detail', 'Details', '', 'text', false); field('start', 'Start time', '', 'time'); field('end', 'End time', '', 'time'); end_form('Save event'); ?>
            </details>
        </div>
    </section>
    <dialog class="schedule-calendar-modal" id="schedule-calendar-modal" aria-labelledby="schedule-calendar-title">
        <div class="modal-head">
            <h2 id="schedule-calendar-title">Schedule calendar</h2>
            <button class="icon-btn" type="button" data-close-calendar aria-label="Close">×</button>
        </div>
        <div class="modal-body">
            <div class="schedule-calendar-toolbar">
                <button class="icon-btn" type="button" data-calendar-prev aria-label="Previous month"><?= icon('chevLeft', 16) ?></button>
                <strong id="schedule-calendar-month"><?= h($calendarMonth->format('F Y')) ?></strong>
                <button class="icon-btn" type="button" data-calendar-next aria-label="Next month"><?= icon('chevRight', 16) ?></button>
            </div>
            <div class="schedule-calendar-weekdays"><?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday): ?><span><?= h($weekday) ?></span><?php endforeach; ?></div>
            <div class="schedule-calendar-grid" id="schedule-calendar-grid"></div>
        </div>
        <div class="modal-foot"><button class="btn" type="button" data-close-calendar>Close</button></div>
    </dialog>
    <dialog class="schedule-day-modal" id="schedule-day-modal" aria-labelledby="schedule-day-title">
        <div class="modal-head"><div><h2 id="schedule-day-title"></h2><p id="schedule-day-count"></p></div><button class="icon-btn" type="button" data-close-day aria-label="Close">×</button></div>
        <div class="modal-body" id="schedule-day-content"></div>
        <div class="modal-foot">
            <button class="btn" type="button" data-back-to-calendar><?= icon('chevLeft', 14) ?> Back to calendar</button>
            <a class="btn primary" id="schedule-show-events" href="<?= h(page_url('overview', ['date' => $date] + array_intersect_key($_GET, ['week' => true]))) ?>">Show events</a>
        </div>
    </dialog>
    <script>
        (function () {
            var calendar = document.getElementById('schedule-calendar-modal');
            var dayModal = document.getElementById('schedule-day-modal');
            if (!calendar || !dayModal || typeof calendar.showModal !== 'function') return;
            var selectedDate = <?= json_encode($date) ?>;
            var selectedMonth = new Date(selectedDate + 'T12:00:00');
            var scheduleEvents = <?= json_encode(array_values($scheduleEvents), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?: '[]' ?>;
            var openButton = document.querySelector('[data-open-calendar]');
            var datePicker = document.querySelector('.schedule-date-picker');
            var dateButton = datePicker.querySelector('[data-pick-schedule-date]');
            var dateValue = document.getElementById('schedule-date-value');
            var calendarTitle = document.getElementById('schedule-calendar-title');
            var monthLabel = document.getElementById('schedule-calendar-month');
            var calendarGrid = document.getElementById('schedule-calendar-grid');
            var showEvents = document.getElementById('schedule-show-events');
            var scheduleDate = document.querySelector('#schedule-date > form input[name="date"]');
            var eventTrigger = document.querySelector('#schedule-date .form-disclosure > summary');
            var eventDate = document.querySelector('#schedule-date .form-disclosure input[name="date"]');
            var pickingDate = false;
            scheduleDate.closest('.field').hidden = true;
            datePicker.hidden = false;
            eventTrigger.addEventListener('click', function () { eventDate.value = scheduleDate.value; });
            async function close(dialog, afterClose) {
                if (!dialog.open || dialog.hasAttribute('data-closing')) return;
                dialog.setAttribute('data-closing', '');
                if (typeof dialog.getAnimations === 'function') {
                    await Promise.allSettled(dialog.getAnimations().map(function (animation) { return animation.finished; }));
                }
                dialog.close();
                dialog.removeAttribute('data-closing');
                if (afterClose) afterClose();
            }
            function escapeHtml(value) {
                return String(value || '').replace(/[&<>"']/g, function (character) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
                });
            }
            function renderCalendar() {
                var year = selectedMonth.getFullYear();
                var month = selectedMonth.getMonth();
                var first = new Date(year, month, 1);
                var lastDay = new Date(year, month + 1, 0).getDate();
                var leading = (first.getDay() + 6) % 7;
                var rows = Math.ceil((leading + lastDay) / 7) * 7;
                monthLabel.textContent = first.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
                var html = '';
                for (var index = 0; index < rows; index++) {
                    var number = index - leading + 1;
                    if (number < 1 || number > lastDay) {
                        html += '<div class="schedule-calendar-day is-outside" aria-hidden="true"></div>';
                        continue;
                    }
                    var localDate = new Date(year, month, number);
                    var iso = localDate.getFullYear() + '-' + String(month + 1).padStart(2, '0') + '-' + String(number).padStart(2, '0');
                    var dayEvents = scheduleEvents.filter(function (item) { return item.date === iso; }).sort(function (a, b) { return a.start.localeCompare(b.start); });
                    var classes = 'schedule-calendar-day';
                    if (iso === <?= json_encode($today) ?>) classes += ' is-today';
                    if (iso === selectedDate) classes += ' is-selected';
                    var eventLines = pickingDate ? '' : dayEvents.slice(0, 2).map(function (item) {
                        return '<span class="schedule-calendar-event"><b>' + escapeHtml(item.startLabel) + '</b> ' + escapeHtml(item.title) + '</span>';
                    }).join('');
                    html += '<button class="' + classes + '" type="button" data-cal-date="' + iso + '" aria-label="' +
                        escapeHtml(localDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }) + ', ' + dayEvents.length + ' events') + '">' +
                        '<span class="schedule-calendar-number">' + number + '</span>' + eventLines +
                        (!pickingDate && dayEvents.length > 2 ? '<span class="schedule-calendar-more">+' + (dayEvents.length - 2) + ' more</span>' : '') + '</button>';
                }
                calendarGrid.innerHTML = html;
            }
            function openCalendar(selectDate) {
                pickingDate = selectDate;
                calendar.classList.toggle('is-date-picker', pickingDate);
                calendarTitle.textContent = pickingDate ? 'Choose schedule date' : 'Schedule calendar';
                if (pickingDate) {
                    selectedDate = scheduleDate.value;
                    selectedMonth = new Date(selectedDate + 'T12:00:00');
                }
                renderCalendar();
                calendar.showModal();
                calendarGrid.querySelector('.is-selected')?.focus();
            }
            openButton.addEventListener('click', function () { openCalendar(false); });
            dateButton.addEventListener('click', function () { openCalendar(true); });
            calendar.querySelectorAll('[data-close-calendar]').forEach(function (button) { button.addEventListener('click', function () { close(calendar); }); });
            dayModal.querySelectorAll('[data-close-day]').forEach(function (button) { button.addEventListener('click', function () { close(dayModal); }); });
            calendar.querySelector('[data-calendar-prev]').addEventListener('click', function () {
                selectedMonth = new Date(selectedMonth.getFullYear(), selectedMonth.getMonth() - 1, 1);
                renderCalendar();
            });
            calendar.querySelector('[data-calendar-next]').addEventListener('click', function () {
                selectedMonth = new Date(selectedMonth.getFullYear(), selectedMonth.getMonth() + 1, 1);
                renderCalendar();
            });
            calendar.addEventListener('click', function (event) {
                var cell = event.target.closest('[data-cal-date]');
                if (!cell || calendar.hasAttribute('data-closing')) return;
                selectedDate = cell.dataset.calDate;
                if (pickingDate) {
                    scheduleDate.value = selectedDate;
                    dateValue.textContent = new Date(selectedDate + 'T12:00:00').toLocaleDateString('en-US', {
                        month: 'short', day: 'numeric', year: 'numeric'
                    });
                    close(calendar);
                    return;
                }
                var events = scheduleEvents.filter(function (item) { return item.date === selectedDate; }).sort(function (a, b) { return a.start.localeCompare(b.start); });
                document.getElementById('schedule-day-title').textContent = new Date(selectedDate + 'T12:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
                document.getElementById('schedule-day-count').textContent = events.length === 1 ? '1 event' : events.length + ' events';
                var scheduleUrl = new URL(showEvents.href);
                scheduleUrl.searchParams.set('date', selectedDate);
                showEvents.href = scheduleUrl.href;
                var content = document.getElementById('schedule-day-content');
                content.textContent = '';
                if (!events.length) {
                    var empty = document.createElement('div');
                    empty.className = 'calendar-day-empty';
                    empty.textContent = 'No events scheduled';
                    content.appendChild(empty);
                } else {
                    var list = document.createElement('div');
                    list.className = 'calendar-day-events';
                    events.forEach(function (item) {
                        var article = document.createElement('article');
                        article.className = 'calendar-day-event';
                        var time = document.createElement('div');
                        time.className = 'calendar-day-event-time';
                        var start = document.createElement('strong');
                        start.textContent = item.startLabel;
                        var end = document.createElement('span');
                        end.textContent = item.endLabel;
                        time.append(start, end);
                        var info = document.createElement('div');
                        info.className = 'calendar-day-event-info';
                        var title = document.createElement('strong');
                        title.textContent = item.title;
                        var detail = document.createElement('span');
                        detail.textContent = item.detail || 'No additional details';
                        info.append(title, detail);
                        article.append(time, info);
                        list.appendChild(article);
                    });
                    content.appendChild(list);
                }
                close(calendar, function () { dayModal.showModal(); });
            });
            dayModal.querySelector('[data-back-to-calendar]').addEventListener('click', function () {
                close(dayModal, function () {
                    renderCalendar();
                    calendar.showModal();
                    calendarGrid.querySelector('.is-selected').focus();
                });
            });
            showEvents.addEventListener('click', function (event) {
                if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                close(dayModal, function () { window.location.assign(showEvents.href); });
            });
            [calendar, dayModal].forEach(function (dialog) {
                dialog.addEventListener('click', function (event) { if (event.target === dialog) close(dialog); });
                dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(dialog); });
            });
        }());
    </script>
</div>
<div class="grid cols-main">
    <section class="card">
        <div class="card-head"><h3>Time-off requests <span class="pill"><?= count($pending) ?> pending</span></h3><a class="link-btn" href="<?= h(page_url('time-off')) ?>">View all requests <?= icon('arrowRight', 14) ?></a></div>
        <?php if ($pending): ?>
            <?php leave_table($state, array_slice($pending, 0, 5), true, true); ?>
        <?php else: ?>
            <div class="empty"><strong>No pending requests</strong><span>New leave requests will appear here for review.</span></div>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card-head"><h3>Hiring at a glance</h3><span class="pill"><?= count($open) ?> open roles</span></div>
        <?php foreach (array_slice($open, 0, 3) as $position):
            $candidates = array_filter($state['candidates'], function ($c) use ($position) { return $c['positionId'] === $position['id'] && $c['stage'] !== 'Rejected'; });
            $progress = $candidates ? 25 * array_sum(array_map(function ($c) { return array_search($c['stage'], STAGES, true); }, $candidates)) / count($candidates) : 0;
        ?>
            <div class="role-row">
                <div class="line"><strong><?= h($position['title']) ?></strong></div>
                <div class="line"><span class="muted"><?= h($position['department'] . ' · ' . $position['type']) ?></span><span class="muted"><?= count($candidates) ?> candidates</span></div>
                <div class="meter"><span style="width:<?= $progress ?>%"></span></div>
            </div>
        <?php endforeach; ?>
        <?php if (!$open): ?><div class="empty"><strong>No open roles</strong></div><?php endif; ?>
        <div class="card-body"><a class="btn web-full" href="<?= h(page_url('recruitment')) ?>">View recruitment <?= icon('arrowRight', 14) ?></a></div>
    </section>
</div>
