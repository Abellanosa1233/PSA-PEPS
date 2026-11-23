<?php
$today = $state['today'];
$day = new DateTimeImmutable($today);
$firstName = explode(' ', trim($state['user']['name']))[0];
$hour = (int) date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$recent = $day->modify('-30 days')->format('Y-m-d');
$active = array_filter($state['employees'], fn($e) => $e['status'] === 'Active');
$joined = count(array_filter($active, fn($e) => $e['startDate'] > $recent && $e['startDate'] <= $today));
$open = array_column(array_filter($state['positions'], fn($p) => $p['status'] === 'open'), null, 'id');
$pipeline = array_filter($state['candidates'], fn($c) => isset($open[$c['positionId']]) && !in_array($c['stage'], ['Rejected', 'Hired'], true));
$pending = array_values(array_filter($state['leaves'], fn($l) => $l['status'] === 'pending'));
usort($pending, fn($a, $b) => $a['start'] <=> $b['start']);
$present = $out = 0;
$records = attendance_records($state, $today);
foreach ($records as [, $record, $onLeave]) {
    if ($onLeave) $out++;
    elseif (in_array($record['status'], ['office', 'remote'], true)) $present++;
}
$sick = count(array_filter($state['leaves'], fn($l) =>
    $l['status'] === 'approved' && $l['type'] === 'Sick leave' && $l['start'] <= $today && $l['end'] >= $today
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
$events = array_filter($state['events'], fn($event) => $event['date'] === $date);
?>
<div class="page-head">
    <div><h1><?= h("$greeting, $firstName") ?></h1><p>Here's what's happening with your team today.</p></div>
    <div class="actions">
        <a class="btn" href="#schedule"><?= icon('calendar') ?> <?= h($day->format('l, M j')) ?></a>
        <?php add_employee_button('overview', array_intersect_key($_GET, ['date' => true, 'week' => true])); ?>
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
    <section class="card">
        <div class="card-head">
            <div><h3>Attendance overview</h3><p>Attendance by workday</p></div>
            <form method="get" class="web-week">
                <input type="hidden" name="page" value="overview">
                <select class="input sm" name="week" aria-label="Week">
                    <?php for ($week = 0; $week >= -5; $week--):
                        $from = $thisMonday->modify("$week weeks"); ?>
                        <option value="<?= $week ?>"<?= $week === $offset ? ' selected' : '' ?>><?= h($from->format('M j') . ' – ' . $from->modify('+4 days')->format('M j')) ?></option>
                    <?php endfor; ?>
                </select>
                <button class="btn sm" type="submit">Show</button>
            </form>
        </div>
        <div class="legend"><span><i class="web-office"></i>In office</span><span><i class="web-remote"></i>Remote</span><span><i class="web-away"></i>Away or on leave</span></div>
        <div class="chart">
            <svg viewBox="0 0 560 210" role="img" aria-label="Daily attendance as a share of the workforce">
                <?php foreach ([0, 50, 100] as $tick): $y = 184 - 1.74 * $tick; ?>
                    <line class="grid-line" x1="36" x2="554" y1="<?= $y ?>" y2="<?= $y ?>"/>
                    <text x="28" y="<?= $y + 4 ?>" text-anchor="end"><?= $tick ?>%</text>
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
                    $x = 66 + $i * 103.6;
                    $base = 184;
                ?>
                    <a href="<?= h(page_url('attendance', ['date' => $workday])) ?>">
                        <text x="<?= $x + 22 ?>" y="204" text-anchor="middle"><?= h($dateObject->format('D')) ?></text>
                        <?php if (!$daily || $workday > $today): ?>
                            <rect x="<?= $x ?>" y="10" width="44" height="174" rx="3" fill="none" stroke="var(--line-strong)" stroke-dasharray="3 3"><title>No data yet</title></rect>
                        <?php else: ?>
                            <?php foreach ($counts as $status => $count):
                                if (!$count) continue;
                                $height = 174 * $count / count($daily);
                                $base -= $height; ?>
                                <rect class="web-<?= h($status) ?>" x="<?= $x ?>" y="<?= $base ?>" width="44" height="<?= max(0.5, $height - 1) ?>"><title><?= h(ucfirst($status) . ' · ' . $count) ?></title></rect>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </a>
                <?php endfor; ?>
            </svg>
        </div>
    </section>
    <section class="card" id="schedule">
        <div class="card-head"><h3><?= $date === $today ? "Today's schedule" : 'Schedule' ?></h3><a class="link-btn" href="#schedule-date">View calendar</a></div>
        <div class="sched-day"><?= h((new DateTimeImmutable($date))->format('D, j M')) ?></div>
        <?php if (!$events): ?><div class="empty"><strong>Nothing scheduled</strong></div><?php endif; ?>
        <div class="sched">
            <?php foreach ($events as $event): ?>
                <div class="sched-item">
                    <div class="t"><strong><?= h($event['start']) ?></strong><small><?= h($event['end']) ?></small></div><div class="rule"></div>
                    <div class="e"><strong><?= h($event['title']) ?></strong><small><?= h($event['detail']) ?></small>
                        <?php confirm_action('event.delete', 'Remove', 'Remove this scheduled event?', ['id' => $event['id']]); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card-body" id="schedule-date">
            <?php get_form('overview'); field('date', 'Schedule date', $date, 'date'); end_form('Show'); ?>
            <details class="form-disclosure" data-form-dialog="form"><summary class="btn">Add event</summary>
                <?php post_form('event.save', ['date' => $date]); field('title', 'Event title'); field('detail', 'Details', '', 'text', false); field('start', 'Start time', '', 'time'); field('end', 'End time', '', 'time'); end_form('Save event'); ?>
            </details>
        </div>
    </section>
</div>
<div class="grid cols-main">
    <section class="card">
        <div class="card-head"><h3>Time-off requests <span class="pill"><?= count($pending) ?> pending</span></h3><a class="link-btn" href="<?= h(page_url('time-off')) ?>">View all requests <?= icon('arrowRight', 14) ?></a></div>
        <?php if ($pending): ?>
            <?php leave_table($state, array_slice($pending, 0, 5), compact: true); ?>
        <?php else: ?>
            <div class="empty"><strong>You're all caught up</strong><span>New requests will appear here for approval.</span></div>
        <?php endif; ?>
    </section>
    <section class="card">
        <div class="card-head"><h3>Hiring at a glance</h3><span class="pill"><?= count($open) ?> open roles</span></div>
        <?php foreach (array_slice($open, 0, 3) as $position):
            $candidates = array_filter($state['candidates'], fn($c) => $c['positionId'] === $position['id'] && $c['stage'] !== 'Rejected');
            $progress = $candidates ? 25 * array_sum(array_map(fn($c) => array_search($c['stage'], STAGES, true), $candidates)) / count($candidates) : 0;
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
