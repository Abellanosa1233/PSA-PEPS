<?php
declare(strict_types=1);

/* Special Orders (SO / RTA), from request to the employee's inbox:
     1. The employee requests an SO (My workspace → Special Orders).
     2. The secretary (an HR administrator account) generates the SO/RTA document and prints
        it for the CSS's signature.
     3. The secretary uploads the signed copy: it is stored and emailed to the employee.
     4. The activity and the employee's name are put on the calendar (Overview) for each day.
   The work happens on employee_requests rows with request_type = 'special_order'. */

/* Adds the SO columns to employee_requests (older databases, and fresh installs before the
   schema had them). Every change is checked first, because DDL commits open transactions. */
function ensure_special_orders(): void
{
    static $checked = false;
    if ($checked) return;
    ensure_employee_requests_table();
    $cols = array_flip(q("SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = ? AND table_name = 'employee_requests'", [DB_NAME])->fetchAll(PDO::FETCH_COLUMN));
    $add = [];
    foreach ([
        'so_number' => 'VARCHAR(40) NULL',
        'so_title' => 'VARCHAR(80) NULL',
        'so_office' => 'VARCHAR(160) NULL',
        'so_body' => 'TEXT NULL',
        'so_meta' => 'TEXT NULL', // JSON: reference no., TO/SUBJECT, RTA checkboxes, participants columns …
        'signatory_name' => 'VARCHAR(120) NULL',
        'signatory_title' => 'VARCHAR(120) NULL',
        'generated_at' => 'DATETIME NULL',
        'generated_by' => 'INT NULL',
        'signed_file' => 'MEDIUMBLOB NULL',
        'signed_file_name' => 'VARCHAR(160) NULL',
        'signed_file_type' => 'VARCHAR(60) NULL',
        'signed_at' => 'DATETIME NULL',
        'signed_by' => 'INT NULL',
        'emailed_at' => 'DATETIME NULL',
        'email_error' => 'VARCHAR(255) NULL',
        'calendar_added' => 'TINYINT(1) NOT NULL DEFAULT 0',
        // Group SOs: participants' rows point to the SO's row (the first requester's) and share its document.
        'so_group_id' => 'INT NULL',
        'so_accommodation' => 'VARCHAR(10) NULL',  // LIVE-OUT / LIVE-IN, per participant
        'so_travel_time' => 'VARCHAR(10) NULL',    // INCLUSIVE / EXCLUSIVE, per participant
        'so_added_by_hr' => 'TINYINT(1) NOT NULL DEFAULT 0', // listed by the secretary, not requested by the employee
    ] as $name => $definition) {
        if (!isset($cols[$name])) $add[] = "ADD COLUMN $name $definition";
    }
    if (!isset($cols['so_group_id'])) $add[] = 'ADD INDEX idx_employee_request_so_group (so_group_id)';
    if ($add) q('ALTER TABLE employee_requests ' . implode(', ', $add));
    $checked = true;
}

/* Participants of a group SO share the SO row's document: copy it onto their rows (for the state),
   and add "docId" (the SO row every link and action uses). A row whose SO row is gone stands alone. */
function special_order_share_documents(array $requests): array
{
    $byId = array_column($requests, null, 'id');
    $shared = ['soNumber', 'soTitle', 'soOffice', 'soBody', 'soMeta', 'signatoryName', 'signatoryTitle', 'generatedAt', 'signedAt', 'hasSignedFile'];
    foreach ($requests as &$r) {
        $lead = $r['groupId'] !== null ? ($byId[$r['groupId']] ?? null) : null;
        if ($lead) {
            foreach ($shared as $key) $r[$key] = $lead[$key];
        } else {
            $r['groupId'] = null;
        }
        $r['docId'] = $lead ? $lead['id'] : $r['id'];
    }
    unset($r);
    return $requests;
}

/* Everything the SO document (print, HR editor) shows, from the SO's row and its participants.
   A row not generated yet starts from the office's remembered values and a default order text.
   $suggested: other requesters of the same activity to list as participants on a new SO. */
function special_order_document(array $state, array $order, array $suggested = []): array
{
    $people = array_column($state['employees'], null, 'id');
    $generated = !empty($order['generatedAt']);
    $defaults = special_order_defaults();
    $m = $generated ? $order['soMeta'] : array_merge($order['soMeta'], array_intersect_key($defaults, $order['soMeta']));
    $rows = [];
    foreach ($state['employeeRequests'] as $r) {
        if ($r['type'] === 'special_order' && $r['docId'] === $order['id'] && isset($people[$r['empId']])) $rows[$r['empId']] = $r;
    }
    foreach ($suggested as $r) if (!isset($rows[$r['empId']]) && isset($people[$r['empId']])) $rows[$r['empId']] = $r;
    $participants = [];
    foreach ($rows as $empId => $r) {
        $e = $people[$empId];
        $participants[] = ['empId' => $empId, 'name' => $e['lastName'] . ', ' . $e['firstName'], 'office' => $e['location'] ?: $e['department'],
            'title' => $e['title'], 'type' => $e['type'], 'accommodation' => $r['accommodation'], 'travelTime' => $r['travelTime'],
            'requester' => $empId === $order['empId']];
    }
    usort($participants, function ($a, $b) { return $a['name'] <=> $b['name']; });
    $employees = array_map(function ($p) { return ['type' => $p['type']]; }, $participants);
    return [
        'id' => $order['id'], 'generated' => $generated, 'signed' => !empty($order['signedAt']),
        'company' => $state['settings']['company'] ?? 'Philippine Statistics Authority',
        'office' => $generated ? (string) $order['soOffice'] : $defaults['office'],
        'number' => $order['soNumber'] ?: ($generated ? '' : special_order_next_number()),
        'issued' => $generated ? new DateTimeImmutable($order['generatedAt']) : new DateTimeImmutable(),
        'body' => $order['soBody'] ?: special_order_default_body($employees ?: [['type' => '']], $order),
        'signatoryName' => $order['signatoryName'] ?: $defaults['signatoryName'],
        'signatoryTitle' => $order['signatoryTitle'] ?: $defaults['signatoryTitle'],
        'meta' => array_merge($m, ['subject' => $m['subject'] ?: 'Authority to Attend the ' . $order['purpose']]),
        'purpose' => $order['purpose'], 'destination' => $order['destination'], 'start' => $order['start'], 'end' => $order['end'],
        'participants' => $participants,
    ];
}

/* Where an SO is in the four steps: requested → for_signature → signed (or declined). */
function special_order_stage(array $request): string
{
    if ($request['status'] === 'rejected') return 'declined';
    if (!empty($request['signedAt'])) return 'signed';
    if (!empty($request['generatedAt'])) return 'for_signature';
    return 'requested';
}

function special_order_stage_badge(string $stage): void
{
    [$label, $style] = [
        'requested' => ['Requested', 'warn'],
        'for_signature' => ['For CSS signature', ''],
        'signed' => ['Signed', 'ok'],
        'declined' => ['Declined', 'bad'],
    ][$stage] ?? ['Requested', 'warn'];
    echo '<span class="badge ' . h($style) . '">' . h($label) . '</span>';
}

function special_order_dates(string $start, ?string $end): string
{
    $a = new DateTimeImmutable($start);
    if (!$end || $end === $start) return $a->format('F j, Y');
    $b = new DateTimeImmutable($end);
    if ($a->format('Y-m') === $b->format('Y-m')) return $a->format('F j') . '–' . $b->format('j, Y');
    if ($a->format('Y') === $b->format('Y')) return $a->format('F j') . ' – ' . $b->format('F j, Y');
    return $a->format('F j, Y') . ' – ' . $b->format('F j, Y');
}

/* The next suggested number, in the office's style: 2026-001, 2026-002 … (printed as
   "SPECIAL ORDER NO. 2026-001"). The secretary can change it to the records office's number. */
function special_order_next_number(): string
{
    ensure_special_orders();
    $count = (int) q("SELECT COUNT(*) FROM employee_requests WHERE request_type = 'special_order' AND generated_at IS NOT NULL AND YEAR(generated_at) = ?", [date('Y')])->fetchColumn();
    return date('Y') . '-' . str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);
}

/* "December 1-3, 2026" — the way the SO writes dates. */
function special_order_dates_text(string $start, ?string $end): string
{
    $a = new DateTimeImmutable($start);
    if (!$end || $end === $start) return $a->format('F j, Y');
    $b = new DateTimeImmutable($end);
    if ($a->format('Y-m') === $b->format('Y-m')) return $a->format('F j') . '-' . $b->format('j, Y');
    if ($a->format('Y') === $b->format('Y')) return $a->format('F j') . ' - ' . $b->format('F j, Y');
    return $a->format('F j, Y') . ' - ' . $b->format('F j, Y');
}

/* The SO body, following the office's sample (S.O. No. 2026-530): authority to attend, the EO 77
   paragraph, the JO/COS paragraph when the employee is one, and the effectivity clause. */
function special_order_default_body(array $participants, array $request): string
{
    $where = $request['destination'] !== '' ? ', at ' . $request['destination'] : '';
    $many = count($participants) > 1;
    $paragraphs = [
        'The official participant' . ($many ? 's' : '') . ' listed in the attached document' . ($many ? ' are' : ' is') . ' hereby authorized to attend the ' . $request['purpose']
            . ' on ' . special_order_dates_text($request['start'], $request['end']) . $where . '.',
        'Payment/reimbursement of allowable traveling expenses based on EO No. 77 series of 2019 is chargeable to the general fund, subject to the usual accounting and auditing rules and regulations.',
    ];
    $jobOrder = array_filter($participants, function ($e) { return in_array($e['type'] ?? '', ['Job Order', 'Contract of Service Worker'], true); });
    if ($jobOrder) {
        $paragraphs[] = 'Job Order/Contract of Service Worker shall be entitled to payment of travelling expenses following Office Memorandum 2025-175 subject to the usual accounting and auditing rules and regulations.';
    }
    $paragraphs[] = 'THIS SPECIAL ORDER SHALL TAKE EFFECT ON DATE SPECIFIED, INCLUSIVE OF TRAVEL TIME, AND REMAIN IN FORCE UNTIL SOONER REVOKED OR MODIFIED.';
    return implode("\n\n", $paragraphs);
}

/* The fields that are the same on every SO of the office (letterhead line, signatory, the RTA's
   "prepared by", footer …). Remembered from the last SO so the secretary types them once. */
function special_order_defaults(): array
{
    $saved = q("SELECT v FROM settings WHERE k = 'soDefaults'")->fetchColumn();
    $saved = $saved ? (array) json_decode((string) $saved, true) : [];
    // First-time values follow the office's sample (S.O. No. 2026-530, PSA RSSO X).
    return array_merge([
        'office' => 'REGION X - NORTHERN MINDANAO', 'to' => 'ALL CONCERNED PSA RSSO 10 OFFICIALS AND EMPLOYEES', 'forLine' => 'FOR THE REGIONAL DIRECTOR',
        'signatoryName' => '', 'signatoryTitle' => 'Senior Statistical Specialist', 'signatoryDesignation' => 'In-Charge of Office, RSSO 10',
        'initials' => '', 'preparedName' => '', 'preparedTitle' => '', 'routing' => '',
        'footer' => "3rd Floor, Limketkai Module-2 BPO & Cyberpark Bldg., Rosario Crescent\nLimketkai Center, Brgy. 31, Cagayan de Oro City\nTelephone: (088)291-1672; 09178219152\nhttp://rsso10.psa.gov.ph      rsso10@psa.gov.ph",
    ], array_map('strval', $saved));
}

/* Everything on the SO, RTA and participants list that has no column of its own. */
function special_order_meta(?string $json): array
{
    $meta = $json ? (array) json_decode($json, true) : [];
    return array_merge([
        'refNo' => '', 'to' => 'ALL CONCERNED', 'subject' => '', 'forLine' => 'FOR THE REGIONAL DIRECTOR',
        'signatoryDesignation' => '', 'initials' => '', 'footer' => '', 'routing' => '',
        'vehicle' => 'none', 'plate' => '', 'driver' => '',
        'allowances' => true, 'meals' => true, 'incidental' => true, 'lodging' => false,
        'expenses' => false, 'terminal' => false, 'fuel' => false, 'others' => '',
        'preparedName' => '', 'preparedTitle' => '',
        'accommodation' => 'LIVE-OUT', 'travelTime' => 'INCLUSIVE',
    ], $meta);
}

/* The request an SO is managed from: the first requester's row (the "lead"). Other
   participants' rows point to it with so_group_id and share its document and signed copy. */
function special_order_row(int $id): array
{
    ensure_special_orders();
    $row = q("SELECT id, emp_id, status, start_date, end_date, purpose, destination, details, generated_at, signed_at, calendar_added, so_number, so_group_id
              FROM employee_requests WHERE id = ? AND request_type = 'special_order'", [$id])->fetch();
    if (!$row) fail('That Special Order request no longer exists.', 404);
    if ($row['so_group_id'] !== null && !q('SELECT COUNT(*) FROM employee_requests WHERE id = ?', [$row['so_group_id']])->fetchColumn()) {
        // Its SO row was deleted (with that employee): this request stands on its own again.
        q('UPDATE employee_requests SET so_group_id = NULL WHERE id = ?', [$row['id']]);
        $row['so_group_id'] = null;
    }
    if ($row['so_group_id'] !== null) fail('This person is a participant of another Special Order. Open that Special Order instead.', 409);
    return $row;
}

/* The lead and every participant row of one SO, with the employee's name and email. */
function special_order_participants(int $leadId): array
{
    return q("SELECT r.id, r.emp_id, r.emailed_at, e.first_name, e.last_name, e.email
              FROM employee_requests r JOIN employees e ON e.id = r.emp_id
              WHERE r.id = ? OR r.so_group_id = ? ORDER BY e.last_name, e.first_name", [$leadId, $leadId])->fetchAll();
}

/* Step 2: the secretary drafts the SO/RTA and picks its participants. Saving again edits the
   draft (and the participants) until it is signed. */
function act_special_order_generate(array $in, array $user): array
{
    $row = special_order_row(int_in($in, 'id'));
    if ($row['status'] === 'rejected') fail('This request was declined, so no Special Order can be generated.');
    if ($row['signed_at']) fail('This Special Order is already signed. Upload a new signed copy instead of editing it.');
    $number = str_in($in, 'soNumber', 40, true, 'SO number');
    $office = str_in($in, 'soOffice', 160, false, 'Region / office line');
    $body = str_in($in, 'soBody', 4000, true, 'Order text');
    $signatory = str_in($in, 'signatoryName', 120, true, 'Name of the signatory');
    $signatoryTitle = str_in($in, 'signatoryTitle', 120, true, 'Position of the signatory');
    $meta = [
        'refNo' => str_in($in, 'refNo', 60, false, 'Reference No.'),
        'to' => str_in($in, 'to', 160, true, 'TO'),
        'subject' => str_in($in, 'subject', 255, true, 'Subject'),
        'forLine' => str_in($in, 'forLine', 80, false, 'Signing line'),
        'signatoryDesignation' => str_in($in, 'signatoryDesignation', 120, false, 'Designation'),
        'initials' => str_in($in, 'initials', 40, false, 'Initials'),
        'footer' => str_in($in, 'footer', 400, false, 'Footer'),
        'vehicle' => one_of((string) ($in['vehicle'] ?? 'none'), ['none', 'official'], 'Vehicle'),
        'plate' => str_in($in, 'plate', 40, false, 'Plate number'),
        'driver' => str_in($in, 'driver', 120, false, 'Driver'),
        'allowances' => !empty($in['allowances']), 'meals' => !empty($in['meals']), 'incidental' => !empty($in['incidental']), 'lodging' => !empty($in['lodging']),
        'expenses' => !empty($in['expenses']), 'terminal' => !empty($in['terminal']), 'fuel' => !empty($in['fuel']),
        'others' => str_in($in, 'others', 120, false, 'Other expenses'),
        'preparedName' => str_in($in, 'preparedName', 120, false, 'Prepared by'),
        'preparedTitle' => str_in($in, 'preparedTitle', 120, false, 'Position of the preparer'),
        'routing' => str_in($in, 'routing', 300, false, 'Routing slip'),
    ];
    // Participants: the requester always, plus everyone ticked. Each has their own accommodation and travel time.
    $chosen = array_values(array_unique(array_map('intval', array_filter((array) ($in['p'] ?? []), 'is_numeric'))));
    if (!in_array((int) $row['emp_id'], $chosen, true)) array_unshift($chosen, (int) $row['emp_id']);
    if (count($chosen) > 300) fail('A Special Order can list up to 300 participants.');
    $accommodation = (array) ($in['acc'] ?? []);
    $travelTime = (array) ($in['tt'] ?? []);
    $columns = function (int $empId) use ($accommodation, $travelTime): array {
        return [
            one_of((string) ($accommodation[$empId] ?? 'LIVE-OUT'), ['LIVE-OUT', 'LIVE-IN'], 'Accommodation'),
            one_of((string) ($travelTime[$empId] ?? 'INCLUSIVE'), ['INCLUSIVE', 'EXCLUSIVE'], 'Travel time'),
        ];
    };
    foreach ($chosen as $empId) {
        $employee = employee_exists($empId);
        if ($employee['status'] !== 'Active') fail(employee_display_name($employee) . ' is no longer active and cannot be a participant.');
    }
    $taken = q("SELECT COUNT(*) FROM employee_requests WHERE request_type = 'special_order' AND so_number = ? AND id <> ? AND (so_group_id IS NULL OR so_group_id <> ?)",
        [$number, $row['id'], $row['id']])->fetchColumn();
    if ($taken) fail('Another Special Order already uses number ' . $number . '.', 422, 'soNumber');

    $leadId = (int) $row['id'];
    db()->beginTransaction();
    // Generating the order means the secretary accepted the request, so a pending one counts as approved.
    [$acc, $tt] = $columns((int) $row['emp_id']);
    q("UPDATE employee_requests SET so_number=?, so_title='Special Order', so_office=?, so_body=?, signatory_name=?, signatory_title=?, so_meta=?,
         so_accommodation=?, so_travel_time=?, generated_at=COALESCE(generated_at, NOW()), generated_by=?,
         decision_role=IF(status='pending', 'hr_admin', decision_role), decided_by_user_id=IF(status='pending', ?, decided_by_user_id),
         decided_at=IF(status='pending', NOW(), decided_at), status='approved' WHERE id=?",
        [$number, $office, $body, $signatory, $signatoryTitle, json_encode($meta), $acc, $tt, $user['id'], $user['id'], $leadId]);
    $members = [];
    foreach (q('SELECT id, emp_id, so_added_by_hr FROM employee_requests WHERE so_group_id = ?', [$leadId])->fetchAll() as $m) $members[(int) $m['emp_id']] = $m;
    // Taken off the list: rows HR added are removed; an employee's own request goes back to "To generate".
    foreach ($members as $empId => $m) {
        if (in_array($empId, $chosen, true)) continue;
        if ((int) $m['so_added_by_hr']) q('DELETE FROM employee_requests WHERE id = ?', [$m['id']]);
        else q('UPDATE employee_requests SET so_group_id=NULL, so_accommodation=NULL, so_travel_time=NULL WHERE id = ?', [$m['id']]);
    }
    foreach ($chosen as $empId) {
        if ($empId === (int) $row['emp_id']) continue;
        [$acc, $tt] = $columns($empId);
        if (isset($members[$empId])) {
            q('UPDATE employee_requests SET so_accommodation=?, so_travel_time=? WHERE id=?', [$acc, $tt, $members[$empId]['id']]);
            continue;
        }
        // Their own request for the same activity joins this SO; otherwise HR adds them.
        $own = q("SELECT id FROM employee_requests WHERE emp_id = ? AND request_type = 'special_order' AND so_group_id IS NULL
                  AND generated_at IS NULL AND status <> 'rejected' AND id <> ? AND start_date = ? ORDER BY id LIMIT 1",
            [$empId, $leadId, $row['start_date']])->fetchColumn();
        if ($own) {
            q("UPDATE employee_requests SET so_group_id=?, so_accommodation=?, so_travel_time=?, status='approved',
                 decision_role=COALESCE(decision_role, 'hr_admin'), decided_by_user_id=COALESCE(decided_by_user_id, ?), decided_at=COALESCE(decided_at, NOW())
               WHERE id=?", [$leadId, $acc, $tt, $user['id'], $own]);
        } else {
            q("INSERT INTO employee_requests (emp_id, request_type, purpose, destination, start_date, end_date, details, status,
                 decision_role, decided_by_user_id, decided_at, so_group_id, so_accommodation, so_travel_time, so_added_by_hr)
               VALUES (?, 'special_order', ?, ?, ?, ?, ?, 'approved', 'hr_admin', ?, NOW(), ?, ?, ?, 1)",
                [$empId, $row['purpose'], $row['destination'], $row['start_date'], $row['end_date'], $row['details'], $user['id'], $leadId, $acc, $tt]);
        }
    }
    set_setting('soDefaults', [
        'office' => $office, 'to' => $meta['to'], 'forLine' => $meta['forLine'], 'signatoryName' => $signatory, 'signatoryTitle' => $signatoryTitle,
        'signatoryDesignation' => $meta['signatoryDesignation'], 'initials' => $meta['initials'],
        'preparedName' => $meta['preparedName'], 'preparedTitle' => $meta['preparedTitle'], 'footer' => $meta['footer'], 'routing' => $meta['routing'],
    ]);
    db()->commit();
    return ['id' => $leadId, 'participants' => count($chosen)];
}

function employee_display_name(array $employee): string
{
    return $employee['first_name'] . ' ' . $employee['last_name'];
}

/* Step 3 and 4: the signed copy is stored on the SO, emailed to every participant and put on the calendar. */
function act_special_order_sign(array $in, array $user, ?array $file): array
{
    $row = special_order_row(int_in($in, 'id'));
    if (!$row['generated_at']) fail('Generate the Special Order first, then upload the signed copy.');
    if ($row['status'] === 'rejected') fail('This request was declined.');
    if (!$file) fail('Choose the scanned signed Special Order (PDF, JPG or PNG).', 422, 'signedFile');
    $leadId = (int) $row['id'];
    q('UPDATE employee_requests SET signed_file=?, signed_file_name=?, signed_file_type=?, signed_by=? WHERE id=?',
        [$file['bytes'], $file['name'], $file['type'], $user['id'], $leadId]);
    q("UPDATE employee_requests SET signed_at=NOW(), status='approved', decision_role=COALESCE(decision_role, 'hr_admin'),
         decided_by_user_id=COALESCE(decided_by_user_id, ?), decided_at=COALESCE(decided_at, NOW()) WHERE id=? OR so_group_id=?",
        [$user['id'], $leadId, $leadId]);
    $added = 0;
    if (!(int) $row['calendar_added']) {
        $added = special_order_add_to_calendar($leadId);
        q('UPDATE employee_requests SET calendar_added=1 WHERE id=?', [$leadId]);
    }
    return ['calendarDays' => $added] + special_order_send($leadId, false);
}

/* "Send email": to the participants who haven't received it yet, or to everyone again when all have. */
function act_special_order_email(array $in): array
{
    $row = special_order_row(int_in($in, 'id'));
    if (!$row['signed_at']) fail('Upload the signed Special Order before emailing it.');
    return special_order_send((int) $row['id'], true);
}

/* One calendar entry per day of the activity (up to 31 days), during office hours, with the
   activity and the participant's name (or how many participants, with their names in the detail). */
function special_order_add_to_calendar(int $leadId): int
{
    ensure_announcements(); // adds events.for_employees on older databases
    $r = q('SELECT so_number, purpose, destination, start_date, end_date FROM employee_requests WHERE id = ?', [$leadId])->fetch();
    if (!$r) return 0;
    $names = array_map(function ($p) { return $p['first_name'] . ' ' . $p['last_name']; }, special_order_participants($leadId));
    $settings = settings();
    $title = count($names) === 1
        ? 'SO ' . $r['so_number'] . ' · ' . $names[0] . ' — ' . $r['purpose']
        : 'SO ' . $r['so_number'] . ' · ' . $r['purpose'] . ' (' . count($names) . ' participants)';
    $detail = ($r['destination'] !== '' ? $r['destination'] . ' · ' : '') . (count($names) === 1 ? 'Special Order ' . $r['so_number'] : implode(', ', $names));
    $title = mb_substr($title, 0, 160);
    $detail = mb_strlen($detail) > 255 ? mb_substr($detail, 0, 254) . '…' : $detail;
    $day = new DateTimeImmutable($r['start_date']);
    $last = new DateTimeImmutable($r['end_date'] ?: $r['start_date']);
    $n = 0;
    while ($day <= $last && $n < 31) {
        q('INSERT INTO events (event_date, start_time, end_time, title, detail, for_employees) VALUES (?,?,?,?,?,0)',
            [$day->format('Y-m-d'), $settings['workStart'] ?: '08:00', $settings['workEnd'] ?: '17:00', $title, $detail]);
        $day = $day->modify('+1 day');
        $n++;
    }
    return $n;
}

/* Emails the signed copy to each participant. A mail problem never undoes the upload: it is
   recorded on that person's row so the secretary can fix the settings and press "Send email". */
function special_order_send(int $leadId, bool $pendingOnly): array
{
    $doc = q('SELECT so_number, purpose, start_date, end_date, signed_file, signed_file_name, signed_file_type FROM employee_requests WHERE id = ?', [$leadId])->fetch();
    $participants = special_order_participants($leadId);
    $targets = $pendingOnly ? array_values(array_filter($participants, function ($p) { return !$p['emailed_at']; })) : $participants;
    if (!$targets) $targets = $participants; // everyone already has it: send it again
    $subject = 'Special Order No. ' . $doc['so_number'] . ' — ' . $doc['purpose'];
    $sent = 0;
    $error = null;
    foreach ($targets as $p) {
        $message = 'Hello ' . $p['first_name'] . ' ' . $p['last_name'] . ",\n\nAttached is the signed Special Order No. " . $doc['so_number']
            . ' for the ' . $doc['purpose'] . ' (' . special_order_dates($doc['start_date'], $doc['end_date']) . ").\n\n"
            . "You can also view it any time in PSA HRMS under Special Orders.\n\nThank you.";
        $failed = special_order_mail($p['email'], $subject, $message, [
            'name' => $doc['signed_file_name'] ?: 'special-order.pdf', 'type' => $doc['signed_file_type'], 'bytes' => $doc['signed_file'],
        ]);
        if ($failed === null) {
            $sent++;
            q('UPDATE employee_requests SET emailed_at=NOW(), email_error=NULL WHERE id=?', [$p['id']]);
        } else {
            $error = $error ?? $failed;
            q('UPDATE employee_requests SET email_error=? WHERE id=?', [mb_substr($failed, 0, 255), $p['id']]);
            if (strpos($failed, 'not set up') !== false || strpos($failed, 'cURL') !== false) {
                // Nothing will go through until the mail settings are fixed: record it for the rest and stop.
                foreach ($targets as $rest) if (!$rest['emailed_at']) q('UPDATE employee_requests SET email_error=? WHERE id=? AND emailed_at IS NULL', [$failed, $rest['id']]);
                break;
            }
        }
    }
    return ['sent' => $sent, 'total' => count($targets), 'emailError' => $error,
            'email' => count($targets) === 1 ? $targets[0]['email'] : null];
}

/* Returns null when sent, or why it wasn't. Uses the same senders as employee onboarding
   (Gmail first, then Resend), configured in api/config.php. */
function special_order_mail(string $to, string $subject, string $text, array $attachment): ?string
{
    if (!function_exists('curl_init')) return 'Email needs the PHP cURL extension.';
    $gmail = defined('GMAIL_SMTP_USER') && GMAIL_SMTP_USER !== '';
    $resend = defined('RESEND_API_KEY') && trim(RESEND_API_KEY) !== '' && defined('EMPLOYEE_MAIL_FROM') && trim(EMPLOYEE_MAIL_FROM) !== '';
    if (!$gmail && !$resend) return 'Email is not set up yet. Add a Gmail or Resend sender in api/config.php.';

    if ($gmail) {
        $sender = GMAIL_SMTP_USER;
        $boundary = 'so-' . bin2hex(random_bytes(12));
        $file = str_replace(['"', "\r", "\n"], '', $attachment['name']);
        $payload = implode("\r\n", [
            'Date: ' . date(DATE_RFC2822),
            "From: PSA HRMS <$sender>",
            "To: <$to>",
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"$boundary\"",
            '',
            "--$boundary",
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($text)),
            "--$boundary",
            'Content-Type: ' . $attachment['type'] . "; name=\"$file\"",
            "Content-Disposition: attachment; filename=\"$file\"",
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($attachment['bytes'])),
            "--$boundary--",
            '',
        ]);
        $offset = 0;
        $request = curl_init('smtp://smtp.gmail.com:587');
        curl_setopt_array($request, [
            CURLOPT_USE_SSL => CURLUSESSL_ALL,
            CURLOPT_USERNAME => $sender,
            CURLOPT_PASSWORD => str_replace(' ', '', (string) (defined('GMAIL_SMTP_APP_PASSWORD') ? GMAIL_SMTP_APP_PASSWORD : '')),
            CURLOPT_MAIL_FROM => "<$sender>",
            CURLOPT_MAIL_RCPT => ["<$to>"],
            CURLOPT_UPLOAD => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_READFUNCTION => function ($handle, $stream, int $length) use ($payload, &$offset): string {
                $chunk = substr($payload, $offset, $length);
                $offset += strlen($chunk);
                return $chunk;
            },
        ]);
        $sent = curl_exec($request);
        $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);
        return $sent === false || $status < 200 || $status >= 300 ? 'Gmail did not accept the email. Check the sender settings.' : null;
    }

    $request = curl_init('https://api.resend.com/emails');
    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'from' => EMPLOYEE_MAIL_FROM,
            'to' => [$to],
            'subject' => $subject,
            'text' => $text,
            'attachments' => [['filename' => $attachment['name'], 'content' => base64_encode($attachment['bytes'])]],
        ]),
    ]);
    $body = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_HTTP_CODE);
    curl_close($request);
    return $status < 200 || $status >= 300 || !is_string($body) || empty(json_decode($body, true)['id'])
        ? 'The mail service did not accept the email. Check the address or mail settings.' : null;
}

/* The signed copy: HR, or any participant of that Special Order. $id may be the SO's row or a participant's. */
function special_order_file_for(array $user, int $id): ?array
{
    ensure_special_orders();
    $leadId = q("SELECT COALESCE(so_group_id, id) FROM employee_requests WHERE id = ? AND request_type = 'special_order'", [$id])->fetchColumn();
    if (!$leadId) return null;
    $row = q('SELECT signed_file, signed_file_name, signed_file_type FROM employee_requests WHERE id = ? AND signed_file IS NOT NULL', [$leadId])->fetch();
    if (!$row) return null;
    if (!is_admin($user)) {
        $mine = q('SELECT COUNT(*) FROM employee_requests WHERE (id = ? OR so_group_id = ?) AND emp_id = ?', [$leadId, $leadId, (int) ($user['employeeId'] ?? 0)])->fetchColumn();
        if (!$mine) return null;
    }
    return $row;
}

function special_order_email_message(array $result): string
{
    if ($result['sent'] === $result['total']) {
        return $result['total'] === 1 ? 'Emailed to ' . $result['email'] . '.' : 'Emailed to all ' . $result['total'] . ' participants.';
    }
    $text = $result['sent'] ? 'Emailed to ' . $result['sent'] . ' of ' . $result['total'] . ' participants. ' : 'It was not emailed: ';
    return $text . $result['emailError'];
}

function special_order_message(array $result): string
{
    $text = 'Signed Special Order saved';
    if (!empty($result['calendarDays'])) $text .= ' and added to the calendar';
    return $text . '. ' . special_order_email_message($result);
}
