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
    ['Total employees', 'users', count($active), '+' . $joined, 'joined in the last 30 days', page_url('employees', ['status' => 'Active'])],
    ['Present today', 'clock', $present, ($records ? round(100 * $present / count($records)) : 0) . '%', 'of your workforce', page_url('employees', ['todayStatus' => 'present'])],
    ['On leave today', 'calendar', $out, max(0, $out - $sick) . ' planned', $sick . ' unplanned', page_url('employees', ['todayStatus' => 'leave'])],
    ['Open positions', 'briefcase', count($open), count($pipeline) . ' candidates', 'in the pipeline', page_url('recruitment')],
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
$events = array_values(array_filter($scheduleEvents, function ($event) use ($date) { return $event['date'] === $date; }));
usort($events, function ($a, $b) { return $a['start'] <=> $b['start']; });
$scheduleDay = new DateTimeImmutable($date);
$calendarMonth = $scheduleDay->modify('first day of this month');
$eventFormError = is_array($formError['shared'] ?? null) && ($formError['shared']['action'] ?? '') === 'event.save'
    ? $formError['shared'] : [];
$eventFormValues = (array) ($eventFormError['values'] ?? []);
$eventErrorField = $eventFormError['field'] ?? '';
$eventErrorMessage = (string) ($eventFormError['message'] ?? '');
$eventGeneralError = $eventFormError && !in_array($eventErrorField, ['date', 'title', 'detail', 'start', 'end'], true)
    ? $eventErrorMessage : '';
?>
<div class="page-head">
    <div><h1><?= h("$greeting, $firstName") ?></h1><p>Review workforce activity and priority updates for today.</p></div>
    <div class="actions">
        <a class="btn" href="#schedule"><?= icon('calendar') ?> <?= h($day->format('l, M j')) ?></a>
    </div>
</div>
<div class="grid kpis">
    <?php foreach ($metrics as [$label, $glyph, $value, $pill, $note, $url]): ?>
        <a class="card kpi kpi-link" href="<?= h($url) ?>" aria-label="<?= h("$label: $value. View details.") ?>">
            <div class="kpi-top"><span><?= h($label) ?></span><?= icon($glyph) ?></div>
            <div class="kpi-value"><?= number_format($value) ?></div>
            <div class="kpi-foot"><span class="pill"><?= h($pill) ?></span><span><?= h($note) ?></span></div>
        </a>
    <?php endforeach; ?>
</div>
<div class="grid cols-main overview-grid overview-schedule-grid">
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
            <?php $chartTop = 10; $chartHeight = 132; $chartBaseline = $chartTop + $chartHeight; ?>
            <svg viewBox="0 0 760 180" role="img" aria-label="Daily attendance as a share of the workforce">
                <?php foreach ([0, 50, 100] as $tick): $y = $chartBaseline - $chartHeight * $tick / 100; ?>
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
                    $base = $chartBaseline;
                ?>
                    <a href="<?= h(page_url('attendance', ['date' => $workday])) ?>">
                        <text x="<?= $x + 24 ?>" y="168" text-anchor="middle"><?= h($dateObject->format('D')) ?></text>
                        <?php if (!$daily || $workday > $today): ?>
                            <rect x="<?= $x ?>" y="<?= $chartTop ?>" width="48" height="<?= $chartHeight ?>" rx="4" fill="none" stroke="var(--line-strong)" stroke-dasharray="3 3"><title>No data yet</title></rect>
                        <?php else: ?>
                            <defs><clipPath id="<?= h($clipId) ?>"><rect x="<?= $x ?>" y="<?= $chartTop ?>" width="48" height="<?= $chartHeight ?>" rx="4"/></clipPath></defs>
                            <g clip-path="url(#<?= h($clipId) ?>)">
                            <?php foreach ($counts as $status => $count):
                                if (!$count) continue;
                                $height = $chartHeight * $count / count($daily);
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
            <div class="sched" id="schedule-events">
                <?php foreach ($events as $index => $event): ?>
                    <div class="sched-item" data-schedule-event<?= $index >= 3 ? ' hidden' : '' ?>>
                        <div class="t"><strong><?= h($event['startLabel']) ?></strong><small><?= h($event['endLabel']) ?></small></div><div class="rule"></div>
                        <div class="e"><strong><?= h($event['title']) ?></strong><small><?= h($event['detail']) ?></small>
                            <?php confirm_action('event.delete', 'Remove', 'Remove this scheduled event?', ['id' => $event['id']]); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($events) > 3): ?>
                <div class="card-foot schedule-pagination" data-schedule-pagination aria-label="Schedule event pages">
                    <span data-schedule-page-status aria-live="polite">Showing 1-3 of <?= count($events) ?></span>
                    <div class="toolbar">
                        <button class="btn sm" type="button" data-schedule-prev aria-label="Previous event page" disabled>Previous</button>
                        <button class="btn sm" type="button" data-schedule-next aria-label="Next event page">Next</button>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty"><strong>No events scheduled</strong></div>
        <?php endif; ?>
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
            <button class="btn primary" type="button" data-open-event-form aria-haspopup="dialog" aria-controls="schedule-event-modal"><?= icon('plus', 14) ?> Add event</button>
            <a class="btn primary" id="schedule-show-events" href="<?= h(page_url('overview', ['date' => $date] + array_intersect_key($_GET, ['week' => true]))) ?>">Show events</a>
        </div>
    </dialog>
    <dialog class="schedule-event-modal" id="schedule-event-modal" aria-labelledby="schedule-event-title">
        <?php post_form('event.save'); hidden_inputs(['_shared_form' => 'event.save']); ?>
        <div class="modal-head">
            <div><h2 id="schedule-event-title">Add event</h2><p id="schedule-event-date-label"><?= h($scheduleDay->format('l, F j, Y')) ?></p></div>
            <button class="icon-btn" type="button" data-close-event aria-label="Close">×</button>
        </div>
        <div class="modal-body">
            <?php if ($eventGeneralError !== ''): ?><p class="form-error" role="alert"><?= h($eventGeneralError) ?></p><?php endif; ?>
            <div class="form-grid">
                <?php
                field('date', 'Event date', $eventFormValues['date'] ?? $date, 'date', true, $eventErrorField === 'date' ? $eventErrorMessage : '');
                field('title', 'Event title', $eventFormValues['title'] ?? '', 'text', true, $eventErrorField === 'title' ? $eventErrorMessage : '', ['maxlength' => 160]);
                ?>
                <div class="full"><?php field('detail', 'Details', $eventFormValues['detail'] ?? '', 'text', false, $eventErrorField === 'detail' ? $eventErrorMessage : '', ['maxlength' => 255]); ?></div>
                <?php
                field('start', 'Start time', $eventFormValues['start'] ?? '', 'time', true, $eventErrorField === 'start' ? $eventErrorMessage : '');
                field('end', 'End time', $eventFormValues['end'] ?? '', 'time', true, $eventErrorField === 'end' ? $eventErrorMessage : '');
                ?>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn" type="button" data-close-event>Cancel</button>
            <?php end_form('Save event'); ?>
        </div>
    </dialog>
    <script>
        (function () {
            var scheduleItems = Array.from(document.querySelectorAll('#schedule-events [data-schedule-event]'));
            var schedulePagination = document.querySelector('[data-schedule-pagination]');
            if (schedulePagination) {
                var schedulePageSize = 3;
                var schedulePage = 0;
                var previousSchedulePage = schedulePagination.querySelector('[data-schedule-prev]');
                var nextSchedulePage = schedulePagination.querySelector('[data-schedule-next]');
                var schedulePageStatus = schedulePagination.querySelector('[data-schedule-page-status]');
                function renderSchedulePage() {
                    var pageCount = Math.ceil(scheduleItems.length / schedulePageSize);
                    var start = schedulePage * schedulePageSize;
                    var end = Math.min(start + schedulePageSize, scheduleItems.length);
                    scheduleItems.forEach(function (item, index) {
                        item.hidden = index < start || index >= end;
                    });
                    previousSchedulePage.disabled = schedulePage === 0;
                    nextSchedulePage.disabled = schedulePage >= pageCount - 1;
                    schedulePageStatus.textContent = 'Showing ' + (start + 1) + '-' + end + ' of ' + scheduleItems.length;
                }
                previousSchedulePage.addEventListener('click', function () {
                    if (schedulePage > 0) {
                        schedulePage--;
                        renderSchedulePage();
                    }
                });
                nextSchedulePage.addEventListener('click', function () {
                    if ((schedulePage + 1) * schedulePageSize < scheduleItems.length) {
                        schedulePage++;
                        renderSchedulePage();
                    }
                });
                renderSchedulePage();
            }
            var calendar = document.getElementById('schedule-calendar-modal');
            var dayModal = document.getElementById('schedule-day-modal');
            var eventModal = document.getElementById('schedule-event-modal');
            if (!calendar || !dayModal || !eventModal || typeof calendar.showModal !== 'function') return;
            var selectedDate = <?= json_encode($date) ?>;
            var selectedMonth = new Date(selectedDate + 'T12:00:00');
            var scheduleEvents = <?= json_encode(array_values($scheduleEvents), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?: '[]' ?>;
            var openButton = document.querySelector('[data-open-calendar]');
            var addEventButton = dayModal.querySelector('[data-open-event-form]');
            var calendarTitle = document.getElementById('schedule-calendar-title');
            var monthLabel = document.getElementById('schedule-calendar-month');
            var calendarGrid = document.getElementById('schedule-calendar-grid');
            var showEvents = document.getElementById('schedule-show-events');
            var eventForm = eventModal.querySelector('form');
            var eventDate = eventForm.elements.namedItem('date');
            var eventDateLabel = document.getElementById('schedule-event-date-label');
            var eventStart = eventForm.elements.namedItem('start');
            var eventEnd = eventForm.elements.namedItem('end');
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
                    var eventLines = dayEvents.slice(0, 2).map(function (item) {
                        return '<span class="schedule-calendar-event"><b>' + escapeHtml(item.startLabel) + '</b> ' + escapeHtml(item.title) + '</span>';
                    }).join('');
                    html += '<button class="' + classes + '" type="button" data-cal-date="' + iso + '" aria-label="' +
                        escapeHtml(localDate.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }) + ', ' + dayEvents.length + ' events') + '">' +
                        '<span class="schedule-calendar-number">' + number + '</span>' + eventLines +
                        (dayEvents.length > 2 ? '<span class="schedule-calendar-more">+' + (dayEvents.length - 2) + ' more</span>' : '') + '</button>';
                }
                calendarGrid.innerHTML = html;
            }
            function openCalendar() {
                calendarTitle.textContent = 'Schedule calendar';
                renderCalendar();
                calendar.showModal();
                calendarGrid.querySelector('.is-selected')?.focus();
            }
            openButton.addEventListener('click', openCalendar);
            addEventButton.addEventListener('click', function () {
                eventDate.value = selectedDate;
                eventEnd.setCustomValidity('');
                eventDateLabel.textContent = new Date(selectedDate + 'T12:00:00').toLocaleDateString('en-US', {
                    weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'
                });
                eventModal.showModal();
            });
            calendar.querySelectorAll('[data-close-calendar]').forEach(function (button) { button.addEventListener('click', function () { close(calendar); }); });
            dayModal.querySelectorAll('[data-close-day]').forEach(function (button) { button.addEventListener('click', function () { close(dayModal); }); });
            eventModal.querySelectorAll('[data-close-event]').forEach(function (button) { button.addEventListener('click', function () { close(eventModal); }); });
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
            eventModal.querySelector('form').addEventListener('submit', function (event) {
                eventEnd.setCustomValidity('');
                if (eventStart.value && eventEnd.value && eventEnd.valueAsNumber <= eventStart.valueAsNumber) {
                    event.preventDefault();
                    eventEnd.setCustomValidity('End time must be after start time.');
                    eventEnd.reportValidity();
                }
            });
            [eventStart, eventEnd].forEach(function (input) {
                input.addEventListener('input', function () { eventEnd.setCustomValidity(''); });
            });
            eventModal.querySelectorAll('.field input[aria-invalid="true"]').forEach(function (input) {
                input.addEventListener('input', function () {
                    input.removeAttribute('aria-invalid');
                    input.removeAttribute('aria-describedby');
                    input.closest('.field').querySelector('.field-error')?.remove();
                }, { once: true });
            });
            [calendar, dayModal].forEach(function (dialog) {
                dialog.addEventListener('click', function (event) { if (event.target === dialog) close(dialog); });
                dialog.addEventListener('cancel', function (event) { event.preventDefault(); close(dialog); });
            });
            eventModal.addEventListener('click', function (event) { if (event.target === eventModal) close(eventModal); });
            eventModal.addEventListener('cancel', function (event) { event.preventDefault(); close(eventModal); });
            if (<?= $eventFormError ? 'true' : 'false' ?>) {
                eventModal.showModal();
                eventModal.querySelector('[aria-invalid="true"]')?.focus();
            }
        }());
    </script>
</div>
<div class="grid cols-main overview-grid">
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
