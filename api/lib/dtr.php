<?php
declare(strict_types=1);

/* Biometric import and Daily Time Record (DTR).

   Biometric devices (ZKTeco and similar) export a log of punches: one line per
   fingerprint/face scan with the device user number and the date and time. The
   import accepts the device's attlog .dat/.txt export or a CSV from its software,
   matches the user number to each employee's Biometric ID, and stores every punch.
   The DTR then turns each day's punches into Morning IN/OUT, Afternoon IN/OUT and
   Overtime IN/OUT, like the printed time card. */

const DTR_NOON = '12:00';          // Morning arrivals are before this
const DTR_LUNCH_END = '13:30';     // A punch before this, after arrival, is the lunch-out
const DTR_PM_IN_FROM = '11:30';    // Earliest afternoon arrival (lunch-back)
const DTR_PM_IN_UNTIL = '14:00';   // Latest punch treated as afternoon arrival
const DTR_LUNCH_MINUTES = 60;      // Deducted when someone punches only in and out
const DTR_DUPLICATE_MINUTES = 2;   // Repeated scans this close together count once

/* Device user numbers are often zero-padded ("0007") in one export and not in another. */
function normalize_biometric_id(string $id): string
{
    $id = trim($id);
    if (ctype_digit($id)) return ltrim($id, '0') === '' ? '0' : ltrim($id, '0');
    return strtoupper($id);
}

/* Reads a device export. Returns [[biometricId, 'Y-m-d H:i:s'], ...] and the number of
   lines that held no punch (headers, blank lines, unreadable rows). */
function parse_biometric_log(string $content): array
{
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $punches = [];
    $skipped = 0;
    foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
        if (trim($line) === '') continue;
        $when = null;
        if (preg_match('/(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})[ T]+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?/', $line, $m, PREG_OFFSET_CAPTURE)) {
            [$y, $mo, $d] = [(int) $m[1][0], (int) $m[2][0], (int) $m[3][0]];
        } elseif (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?/', $line, $m, PREG_OFFSET_CAPTURE)) {
            [$mo, $d, $y] = [(int) $m[1][0], (int) $m[2][0], (int) $m[3][0]]; // MM/DD/YYYY, as used in the Philippines
        } else {
            $skipped++;
            continue;
        }
        $h = (int) $m[4][0];
        $min = (int) $m[5][0];
        $sec = isset($m[6]) && $m[6][1] >= 0 && $m[6][0] !== '' ? (int) $m[6][0] : 0;
        $ampm = isset($m[7]) && $m[7][1] >= 0 ? strtoupper($m[7][0]) : '';
        if ($ampm === 'PM' && $h < 12) $h += 12;
        if ($ampm === 'AM' && $h === 12) $h = 0;
        if (!checkdate($mo, $d, $y) || $h > 23 || $min > 59 || $sec > 59) { $skipped++; continue; }
        $when = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $min, $sec);

        // The user number is the first field on the line, before the date.
        $before = trim(substr($line, 0, $m[0][1]));
        $fields = preg_split('/[\t,;]+/', $before);
        $first = trim((string) ($fields[0] ?? ''), " \"'");
        if (strpos($first, ' ') !== false) $first = strtok($first, ' ');
        if ($first === '' || !preg_match('/^[A-Za-z0-9_-]{1,30}$/', $first)) { $skipped++; continue; }
        $punches[] = [normalize_biometric_id($first), $when];
    }
    return [$punches, $skipped];
}

function act_attendance_biometrics(array $file): array
{
    ensure_biometrics();
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($code !== UPLOAD_ERR_OK) fail($code === UPLOAD_ERR_INI_SIZE ? 'The file exceeds the server upload limit.' : 'Choose the log file exported from the biometric device.', 422, 'biometricLog');
    if (($file['size'] ?? 0) > 10 * 1024 * 1024) fail('Choose a log file smaller than 10 MB. Export a shorter date range from the device.', 422, 'biometricLog');
    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) fail('The uploaded file could not be verified.', 422, 'biometricLog');
    db()->beginTransaction();
    $result = import_biometric_punches((string) file_get_contents($tmp));
    db()->commit();
    return $result;
}

/* Stores the punches in a device log; the caller manages the transaction. */
function import_biometric_punches(string $content): array
{
    [$punches, $skippedLines] = parse_biometric_log($content);
    if (!$punches) fail('No punches were found. Use the attendance log (.dat or .txt) or CSV exported from the biometric device.', 422, 'biometricLog');

    $byBio = [];
    foreach (q('SELECT id, biometric_id FROM employees WHERE biometric_id IS NOT NULL')->fetchAll() as $e) {
        $byBio[normalize_biometric_id($e['biometric_id'])] = (int) $e['id'];
    }
    $insert = db()->prepare("INSERT IGNORE INTO attendance_punches (emp_id, punched_at, source) VALUES (?, ?, 'biometric')");
    $imported = 0;
    $duplicates = 0;
    $unknown = [];
    $touched = [];
    foreach ($punches as [$bio, $when]) {
        if (!isset($byBio[$bio])) { $unknown[$bio] = ($unknown[$bio] ?? 0) + 1; continue; }
        $insert->execute([$byBio[$bio], $when]);
        if ($insert->rowCount()) {
            $imported++;
            $touched[$byBio[$bio] . '|' . substr($when, 0, 10)] = true;
        } else {
            $duplicates++;
        }
    }
    // Keep the daily attendance summary (first in, last out) in step with the punches.
    foreach (array_keys($touched) as $key) {
        [$empId, $day] = explode('|', $key);
        sync_attendance_from_punches((int) $empId, $day);
    }
    $employees = count(array_unique(array_map(function ($k) { return strtok($k, '|'); }, array_keys($touched))));
    ksort($unknown);
    return ['imported' => $imported, 'duplicates' => $duplicates, 'employees' => $employees,
        'unknown' => array_map('strval', array_keys($unknown)), 'unknownPunches' => array_sum($unknown), 'skippedLines' => $skippedLines];
}

function sync_attendance_from_punches(int $empId, string $day): void
{
    $punches = attendance_day_punches($empId, $day);
    if (!$punches) return;
    $slots = attendance_punch_slots($punches);
    $in = $punches[0]['t'];
    $out = count($punches) > 1 ? $punches[count($punches) - 1]['t'] : null;
    foreach ($punches as $punch) {
        if (strpos($punch['source'], 'web:') !== 0) continue;
        $in = $slots['amIn'] ?? $slots['pmIn'];
        $out = $slots['otOut'] ?? $slots['pmOut'];
        break;
    }
    q("INSERT INTO attendance (work_date, emp_id, status, time_in, time_out) VALUES (?, ?, 'office', ?, ?)
       ON DUPLICATE KEY UPDATE status = IF(status = 'away', 'office', status), time_in = VALUES(time_in), time_out = VALUES(time_out)",
        [$day, $empId, $in, $out]);
}

function attendance_day_punches(int $empId, string $day): array
{
    return q('SELECT DATE_FORMAT(punched_at, "%H:%i") AS t, source FROM attendance_punches
              WHERE emp_id = ? AND punched_at >= ? AND punched_at < DATE_ADD(?, INTERVAL 1 DAY)
              ORDER BY punched_at', [$empId, $day, $day])->fetchAll();
}

function attendance_day_slots(int $empId, string $day, array $record): array
{
    $punches = attendance_day_punches($empId, $day);
    return $punches ? attendance_punch_slots($punches) : dtr_slots(array_values(array_filter([
        $record['in'] ?? null, $record['out'] ?? null,
    ])));
}

function biometric_import_message(array $r): string
{
    $msg = $r['imported'] . ' punch' . ($r['imported'] === 1 ? '' : 'es') . ' imported for ' . $r['employees'] . ' employee' . ($r['employees'] === 1 ? '' : 's') . '.';
    if ($r['duplicates']) $msg .= ' ' . $r['duplicates'] . ' already imported earlier were skipped.';
    if ($r['unknown']) {
        $shown = array_slice($r['unknown'], 0, 8);
        $msg .= ' ' . $r['unknownPunches'] . ' punch' . ($r['unknownPunches'] === 1 ? '' : 'es') . ' had no matching employee (biometric ID'
            . (count($r['unknown']) === 1 ? ' ' : 's ') . implode(', ', $shown) . (count($r['unknown']) > 8 ? ', …' : '')
            . '). Add these IDs to the employees’ records, then import the same file again.';
    }
    return $msg;
}

/* ---------- DTR ---------- */

/* 'first' = days 1–15, 'second' = 16 to month end, 'month' = whole month. */
function dtr_period(string $month, string $half): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $month) || !checkdate((int) substr($month, 5, 2), 1, (int) substr($month, 0, 4))) $month = date('Y-m');
    if (!in_array($half, ['first', 'second', 'month'], true)) $half = 'month';
    $start = new DateTimeImmutable($month . '-01');
    $last = (int) $start->format('t');
    [$from, $to] = $half === 'first' ? [1, 15] : ($half === 'second' ? [16, $last] : [1, $last]);
    return [$month, $half, $start->setDate((int) $start->format('Y'), (int) $start->format('n'), $from), $start->setDate((int) $start->format('Y'), (int) $start->format('n'), $to)];
}

function dtr_minutes(?string $hm): ?int
{
    return $hm === null ? null : ((int) substr($hm, 0, 2)) * 60 + (int) substr($hm, 3, 2);
}

/* Assigns one day's punches (sorted "HH:MM") to the six time-card columns. */
function dtr_slots(array $times): array
{
    $slots = ['amIn' => null, 'amOut' => null, 'pmIn' => null, 'pmOut' => null, 'otIn' => null, 'otOut' => null];
    $last = null;
    foreach ($times as $t) {
        if ($last !== null && dtr_minutes($t) - dtr_minutes($last) < DTR_DUPLICATE_MINUTES) continue; // double scan
        $last = $t;
        if ($slots['amIn'] === null && $slots['amOut'] === null && $slots['pmIn'] === null && $t < DTR_NOON) $slots['amIn'] = $t;
        elseif ($slots['amIn'] !== null && $slots['amOut'] === null && $slots['pmIn'] === null && $slots['pmOut'] === null && $t < DTR_LUNCH_END) $slots['amOut'] = $t;
        elseif ($slots['pmIn'] === null && $slots['pmOut'] === null && $t >= DTR_PM_IN_FROM && $t < DTR_PM_IN_UNTIL && ($slots['amOut'] !== null || $slots['amIn'] === null)) $slots['pmIn'] = $t;
        elseif ($slots['pmOut'] === null) $slots['pmOut'] = $t;
        elseif ($slots['otIn'] === null) $slots['otIn'] = $t;
        else $slots['otOut'] = $t;
    }
    return $slots;
}

function attendance_punch_slots(array $punches): array
{
    $slots = dtr_slots(array_column($punches, 't'));
    $explicit = [];
    foreach ($punches as $punch) {
        $slot = substr($punch['source'], 4);
        if (strpos($punch['source'], 'web:') === 0 && array_key_exists($slot, $slots)) {
            $explicit[$slot] = $punch['t'];
        }
    }
    foreach ($slots as $slot => $time) {
        if (in_array($time, $explicit, true)) $slots[$slot] = null;
    }
    return array_replace($slots, $explicit);
}

function attendance_clock_windows(array $slots): array
{
    $windows = [];
    if (!$slots['amIn'] && !$slots['pmIn'] && !$slots['pmOut']) {
        $windows['amIn'] = ['00:00', '12:01', '08:01', 'Clock in'];
    }
    if ($slots['amIn'] && !$slots['amOut'] && !$slots['pmIn'] && !$slots['pmOut']) {
        $windows['amOut'] = ['12:01', '13:00', null, 'Clock out for lunch'];
    }
    if (!$slots['pmIn'] && !$slots['pmOut']) {
        $windows['pmIn'] = ['13:00', '17:01', '13:01', 'Clock in'];
    }
    if (($slots['pmIn'] || ($slots['amIn'] && !$slots['amOut'])) && !$slots['pmOut']) {
        $windows['pmOut'] = ['17:01', '24:00', null, 'Clock out'];
    }
    return $windows;
}

/* Worked minutes for one day: [regular, overtime]. */
function dtr_worked(array $s): array
{
    $span = function ($a, $b) { return $a !== null && $b !== null && $b > $a ? dtr_minutes($b) - dtr_minutes($a) : 0; };
    $regular = $span($s['amIn'], $s['amOut']) + $span($s['pmIn'], $s['pmOut']);
    if ($s['amIn'] !== null && $s['amOut'] === null && $s['pmIn'] === null && $s['pmOut'] !== null) {
        // In and out only, no lunch punches: count the whole day less the lunch hour.
        $regular = max(0, $span($s['amIn'], $s['pmOut']) - ($s['pmOut'] > '13:00' && $s['amIn'] < DTR_NOON ? DTR_LUNCH_MINUTES : 0));
    }
    return [$regular, $span($s['otIn'], $s['otOut'])];
}

function dtr_hm(int $minutes): string
{
    return $minutes > 0 ? intdiv($minutes, 60) . ':' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) : '';
}

function dtr_clock(?string $hm): string
{
    if ($hm === null) return '';
    $h = (int) substr($hm, 0, 2);
    return (($h % 12) ?: 12) . ':' . substr($hm, 3, 2); // 12-hour, as written on time cards
}

/* Every day in the period for one employee: punches placed into columns, plus notes for
   weekends, approved leave and days without any record. Days with no punches fall back to
   the attendance record's clock-in/out so older data still shows. */
function dtr_card(array $employee, DateTimeImmutable $from, DateTimeImmutable $to, string $today): array
{
    ensure_biometrics();
    $id = (int) $employee['id'];
    $byDay = [];
    foreach (q('SELECT DATE(punched_at) AS d, DATE_FORMAT(punched_at, "%H:%i") AS t, source FROM attendance_punches WHERE emp_id = ? AND punched_at >= ? AND punched_at < ? ORDER BY punched_at',
        [$id, $from->format('Y-m-d'), $to->modify('+1 day')->format('Y-m-d')])->fetchAll() as $p) {
        $byDay[$p['d']][] = $p;
    }
    $records = [];
    foreach (q('SELECT work_date, status, TIME_FORMAT(time_in, "%H:%i") AS i, TIME_FORMAT(time_out, "%H:%i") AS o FROM attendance WHERE emp_id = ? AND work_date BETWEEN ? AND ?',
        [$id, $from->format('Y-m-d'), $to->format('Y-m-d')])->fetchAll() as $r) {
        $records[$r['work_date']] = $r;
    }
    $leaves = q("SELECT leave_type, start_date, end_date FROM leave_requests WHERE emp_id = ? AND status = 'approved' AND start_date <= ? AND end_date >= ?",
        [$id, $to->format('Y-m-d'), $from->format('Y-m-d')])->fetchAll();

    $corrections = approved_corrections($id, $from->format('Y-m-d'), $to->format('Y-m-d'));
    $rows = [];
    $totals = ['regular' => 0, 'overtime' => 0, 'absences' => 0, 'daysPresent' => 0];
    for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
        $day = $d->format('Y-m-d');
        $weekend = (int) $d->format('N') >= 6;
        $punches = $byDay[$day] ?? [];
        $times = array_column($punches, 't');
        $note = '';
        if (!$times && isset($records[$day]) && $records[$day]['i']) {
            $times = array_values(array_filter([$records[$day]['i'], $records[$day]['o']]));
        }
        if (isset($corrections[$day])) {
            // HR approved a correction: its times replace the first in and/or last out for the day.
            $c = $corrections[$day];
            $times = array_values(array_filter([$c['in'] ?? ($times[0] ?? null), $c['out'] ?? ($times ? end($times) : null)]));
            if (count($times) === 2 && $times[0] === $times[1]) $times = [$times[0]];
        }
        $slots = $punches && !isset($corrections[$day]) ? attendance_punch_slots($punches) : dtr_slots($times);
        [$regular, $overtime] = dtr_worked($slots);
        if (!$times) {
            foreach ($leaves as $l) if ($l['start_date'] <= $day && $l['end_date'] >= $day) $note = $l['leave_type'];
            if ($note === '' && $weekend) $note = $d->format('l');
            if ($note === '' && isset($records[$day]) && $records[$day]['status'] === 'remote') $note = 'Remote work';
            $outside = $day < $employee['startDate'] || ($employee['endDate'] && $day > $employee['endDate']);
            if ($note === '' && !$weekend && $day < $today && !$outside) { $note = 'Absent'; $totals['absences']++; }
        } else {
            $totals['daysPresent']++;
        }
        $totals['regular'] += $regular;
        $totals['overtime'] += $overtime;
        $rows[] = ['day' => (int) $d->format('j'), 'date' => $day, 'weekend' => $weekend, 'slots' => $slots,
            'total' => dtr_hm($regular + $overtime), 'note' => $note];
    }
    return ['employee' => $employee, 'from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals];
}

/* ---------- Attendance corrections ---------- */

function act_correction_create(array $in): array
{
    ensure_attendance_corrections();
    $emp = employee_exists(int_in($in, 'empId'));
    $day = date_in($in, 'date', true, 'Date');
    if ($day > today()) fail('You can only correct today or an earlier day.', 422, 'date');
    if ($day < $emp['start_date']) fail('That date is before your start date.', 422, 'date');
    $in1 = time_in($in, 'in');
    $out1 = time_in($in, 'out');
    if ($in1 === null && $out1 === null) fail('Enter the correct check-in, check-out, or both.', 422, 'in');
    if ($in1 !== null && $out1 !== null && $out1 <= $in1) fail('Check-out must be later than check-in.', 422, 'out');
    $reason = str_in($in, 'reason', 255, true, 'Reason');
    if (q("SELECT COUNT(*) FROM attendance_corrections WHERE emp_id = ? AND work_date = ? AND status = 'pending'", [$emp['id'], $day])->fetchColumn()) {
        fail('You already have a pending correction for that day. Cancel it first to send a new one.', 422, 'date');
    }
    q('INSERT INTO attendance_corrections (emp_id, work_date, time_in, time_out, reason) VALUES (?,?,?,?,?)', [$emp['id'], $day, $in1, $out1, $reason]);
    return ['id' => (int) db()->lastInsertId()];
}

function act_correction_cancel(array $in): array
{
    ensure_attendance_corrections();
    $deleted = q("DELETE FROM attendance_corrections WHERE id = ? AND emp_id = ? AND status = 'pending'", [int_in($in, 'id'), int_in($in, 'empId')]);
    if (!$deleted->rowCount()) fail('Only your own pending corrections can be cancelled.', 404);
    return [];
}

/* HR only. Approving writes the corrected times onto that day's attendance record. */
function act_correction_decide(array $in, array $user): array
{
    ensure_attendance_corrections();
    $id = int_in($in, 'id');
    $status = one_of((string) ($in['status'] ?? ''), ['approved', 'rejected'], 'Decision');
    $c = q('SELECT * FROM attendance_corrections WHERE id = ?', [$id])->fetch();
    if (!$c) fail('That correction no longer exists.', 404);
    if ($c['status'] !== 'pending') fail('This correction was already ' . ($c['status'] === 'rejected' ? 'declined' : $c['status']) . '.');
    if ($status === 'approved') {
        $rec = q('SELECT time_in, time_out FROM attendance WHERE work_date = ? AND emp_id = ?', [$c['work_date'], $c['emp_id']])->fetch();
        $newIn = $c['time_in'] ?? ($rec['time_in'] ?? null);
        $newOut = $c['time_out'] ?? ($rec['time_out'] ?? null);
        if ($newIn !== null && $newOut !== null && $newOut <= $newIn) fail('With this correction, check-out would be before check-in. Decline it and ask for both times.');
        q("INSERT INTO attendance (work_date, emp_id, status, time_in, time_out) VALUES (?, ?, 'office', ?, ?)
           ON DUPLICATE KEY UPDATE status = IF(status = 'away', 'office', status), time_in = VALUES(time_in), time_out = VALUES(time_out)",
            [$c['work_date'], $c['emp_id'], $newIn, $newOut]);
    }
    q('UPDATE attendance_corrections SET status = ?, decided_at = NOW(), decided_by = ? WHERE id = ?', [$status, ($user['id'] ?? 0) ?: null, $id]);
    return [];
}

/* Approved corrections in a date range: [day => ['in' => 'HH:MM'|null, 'out' => ...]] (latest wins). */
function approved_corrections(int $empId, string $from, string $to): array
{
    ensure_attendance_corrections();
    $out = [];
    foreach (q("SELECT work_date, TIME_FORMAT(time_in, '%H:%i') AS i, TIME_FORMAT(time_out, '%H:%i') AS o FROM attendance_corrections
                WHERE emp_id = ? AND status = 'approved' AND work_date BETWEEN ? AND ? ORDER BY decided_at", [$empId, $from, $to])->fetchAll() as $c) {
        $out[$c['work_date']] = ['in' => $c['i'], 'out' => $c['o']];
    }
    return $out;
}
