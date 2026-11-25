<?php
/* Request leave, following the team's design. Plain PHP: "Check days & balance" recalculates on the
   server (the typed values are kept in the session), and "Submit request" sends it to HR. */
$me = (int) $state['user']['employeeId'];
$today = $state['today'];
$draft = $_SESSION['leave_draft'] ?? [];
$nextWorkday = new DateTimeImmutable($today);
while ((int) $nextWorkday->format('N') >= 6) $nextWorkday = $nextWorkday->modify('+1 day');
$type = in_array($draft['type'] ?? '', LEAVE_TYPES, true) ? $draft['type'] : 'Annual leave';
$validDate = function ($v) { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)); };
$start = $validDate($draft['start'] ?? null) ? $draft['start'] : $nextWorkday->format('Y-m-d');
$end = $validDate($draft['end'] ?? null) ? $draft['end'] : $start;
$dayLength = in_array($draft['dayLength'] ?? '', ['full', 'am', 'pm'], true) ? $draft['dayLength'] : 'full';
$reason = (string) ($draft['reason'] ?? '');

// Working days for the chosen dates
$daysNote = '';
$requested = 0.0;
if ($end < $start) {
    $daysNote = 'The end date is before the start date.';
} else {
    try { $requested = leave_request_days($start, $end, $dayLength); }
    catch (ApiError $e) { $daysNote = $e->getMessage(); }
}

// Balance for the chosen leave type: approved leave is deducted, pending is not.
$year = substr($start, 0, 4);
$allowance = (float) ($state['settings']['allowances'][$type] ?? 0);
$approved = $pending = 0.0;
foreach ($state['leaves'] as $l) {
    if ($l['empId'] !== $me || $l['type'] !== $type || strpos($l['start'], $year) !== 0) continue;
    if ($l['status'] === 'approved') $approved += $l['days'];
    if ($l['status'] === 'pending') $pending += $l['days'];
}
$available = max(0, $allowance - $approved);
$after = $available - $requested;
$num = function ($n) { return $n == floor($n) ? (string) (int) $n : rtrim(rtrim(number_format($n, 1), '0'), '.'); };
?>
<div class="page-head">
    <div><h1>Request leave</h1><p>Take time for what matters. Send your request to HR.</p></div>
    <div class="actions"><a class="btn" href="<?= h(page_url('my-time-off')) ?>"><?= icon('arrowLeft', 15) ?> My time off</a></div>
</div>
<div class="grid cols-main request-leave">
    <section class="card">
        <div class="card-head"><div><h3>Leave details</h3><p>Fields marked * are required.</p></div></div>
        <div class="card-body">
            <?php post_form('leave.create', ['empId' => $me], false, 'multipart/form-data'); ?>
            <?php choice('type', 'Leave type *', options(LEAVE_TYPES), $type); ?>
            <div class="form-grid">
                <?php field('start', 'Start date *', $start, 'date'); ?>
                <?php field('end', 'End date *', $end, 'date'); ?>
            </div>
            <div class="request-days<?= $daysNote !== '' ? ' warn' : '' ?>" role="status">
                <?php if ($daysNote !== ''): ?>
                    <strong><?= h($daysNote) ?></strong>
                <?php else: ?>
                    <strong><?= h($requested == 0.5 ? '½ working day' : $num($requested) . ' working day' . ($requested == 1 ? '' : 's')) ?></strong>
                <?php endif; ?>
                <span>Weekends are excluded. Changed the dates or day length? Select <em>Check days &amp; balance</em>.</span>
            </div>
            <?php choice('dayLength', 'Day length *', ['full' => 'Full day', 'am' => 'Half day — morning', 'pm' => 'Half day — afternoon'], $dayLength); ?>
            <div class="field">
                <label for="leave-reason">Reason *</label>
                <textarea class="input" id="leave-reason" name="reason" rows="4" maxlength="255" required><?= h($reason) ?></textarea>
            </div>
            <div class="field">
                <label for="leave-attachment">Attachment (optional)</label>
                <input class="input" id="leave-attachment" name="attachment" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
                <small class="hint">PDF, JPG or PNG up to 5 MB, for example a medical certificate. Choose it right before you submit. Only HR can open it.</small>
            </div>
            <div class="request-foot">
                <button class="btn" type="submit" name="_action" value="leave.preview" formnovalidate>Check days &amp; balance</button>
                <span class="spacer"></span>
                <a class="btn" href="<?= h(page_url('request-leave', ['clear' => 1])) ?>">Cancel</a>
                <button class="btn primary" type="submit">Submit request</button>
            </div>
            </form>
        </div>
    </section>
    <div class="request-side">
        <section class="card">
            <div class="card-head"><h3>Your <?= h(strtolower($type)) ?></h3></div>
            <div class="card-body">
                <div class="self-leave-value"><span class="kpi-value"><?= h($num($available)) ?></span><span class="muted">day<?= $available == 1 ? '' : 's' ?> available</span></div>
                <dl class="self-today-facts">
                    <dt>Requested</dt><dd><?= h(days_label($requested)) ?></dd>
                    <dt>Balance after approval</dt><dd><?= h(days_label(max(0, $after))) ?></dd>
                    <dt>Other pending requests</dt><dd><?= h(days_label($pending)) ?></dd>
                </dl>
                <?php if ($after - $pending < 0): ?>
                    <p class="request-warn">Not enough <?= h(strtolower($type)) ?> left for this request once your pending requests are counted.</p>
                <?php endif; ?>
                <p class="request-note"><strong>Pending days are not yet deducted.</strong> Your balance updates when a request is approved.</p>
            </div>
        </section>
        <section class="card">
            <div class="card-body">
                <p class="muted">Sent for approval to</p>
                <h3 class="request-to">HR administrators</h3>
                <p class="muted"><?= h($state['settings']['company'] ?? '') ?></p>
                <p class="request-next"><strong>What happens next?</strong><br>You’ll be notified when HR reviews your request. Track its status in My time off. Your team only sees that you’re out once it’s approved.</p>
            </div>
        </section>
        <section class="card request-tip">
            <div class="card-body">
                <strong>A little planning helps</strong>
                <p class="muted">Request annual leave at least 5 working days ahead and arrange a handover with your team.</p>
                <a class="link-btn" href="<?= h(page_url('help')) ?>">View leave policy <?= icon('arrowRight', 14) ?></a>
            </div>
        </section>
    </div>
</div>
