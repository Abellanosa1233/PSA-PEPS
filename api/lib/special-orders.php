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
    q("CREATE TABLE IF NOT EXISTS so_branding (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        header_logo MEDIUMBLOB NULL,
        header_logo_type VARCHAR(30) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $checked = true;
}

/* The administration logo is shared by all SOs and can be replaced from the editor. */
function special_order_header_logo_source(): string
{
    static $source = null;
    if ($source !== null) return $source;
    ensure_special_orders();
    $row = q('SELECT header_logo, header_logo_type FROM so_branding WHERE id = 1')->fetch();
    if ($row && $row['header_logo'] !== null && $row['header_logo_type']) {
        return $source = 'data:' . $row['header_logo_type'] . ';base64,' . base64_encode($row['header_logo']);
    }
    return $source = 'assets/coe-bagong-pilipinas.png';
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

/* Keep only simple inline formatting from the editor. All attributes and other elements are removed. */
function special_order_sanitize_rich_html(string $html): string
{
    $tags = ['b' => true, 'strong' => true, 'i' => true, 'em' => true, 'u' => true, 'br' => true, 'p' => true, 'div' => true, 'span' => true,
        'ul' => true, 'ol' => true, 'li' => true, 'a' => true, 'img' => true, 'font' => true, 'blockquote' => true];
    $html = strip_tags(str_replace("\0", '', $html), '<b><strong><i><em><u><br><p><div><span><ul><ol><li><a><img><font><blockquote>');
    return preg_replace_callback('/<\/?([a-z][a-z0-9]*)\b([^>]*)>/i', function ($match) use ($tags) {
        $tag = strtolower($match[1]);
        if (!isset($tags[$tag])) return '';
        $closing = strncmp($match[0], '</', 2) === 0;
        if ($tag === 'br') return '<br>';
        if ($tag === 'img') {
            if ($closing || !preg_match('/\bsrc\s*=\s*(["\'])(data:image\/(?:png|jpeg|webp);base64,[a-z0-9+\/=]+)\1/i', $match[2], $src)) return '';
            $encoded = substr($src[2], strpos($src[2], ',') + 1);
            $bytes = base64_decode($encoded, true);
            $image = $bytes !== false && strlen($bytes) <= 16384 ? @getimagesizefromstring($bytes) : false;
            if (!$image || !in_array($image['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)) return '';
            return '<img src="' . h($src[2]) . '" alt="">';
        }
        if ($closing) return '</' . $tag . '>';
        $attrs = '';
        if ($tag === 'a' && preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $match[2], $href)) {
            $url = trim(html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('/^(?:https?:\/\/|mailto:)[^\s]+$/i', $url)) $attrs .= ' href="' . h($url) . '" rel="noopener noreferrer" target="_blank"';
        }
        $styles = [];
        if (in_array($tag, ['p', 'div'], true) && preg_match('/\balign\s*=\s*(["\']?)(left|center|right|justify)\1/i', $match[2], $align)) $styles['text-align'] = strtolower($align[2]);
        if (preg_match('/\bstyle\s*=\s*(["\'])(.*?)\1/i', $match[2], $styleMatch)) {
            foreach (explode(';', strtolower($styleMatch[2])) as $declaration) {
                $parts = explode(':', $declaration, 2);
                if (count($parts) !== 2) continue;
                [$property, $value] = array_map('trim', $parts);
                if (in_array($property, ['color', 'background-color'], true)
                    && (preg_match('/^#[0-9a-f]{6}$/', $value) || preg_match('/^rgb\(\s*(?:25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\s*,\s*(?:25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\s*,\s*(?:25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])\s*\)$/', $value))) $styles[$property] = $value;
                if ($property === 'text-align' && in_array($value, ['left', 'center', 'right', 'justify'], true)) $styles[$property] = $value;
                if ($property === 'line-height' && in_array($value, ['1', '1.15', '1.5', '2'], true)) $styles[$property] = $value;
            }
        }
        if ($tag === 'font' && preg_match('/\bcolor\s*=\s*(["\']?)(#[0-9a-f]{6})\1/i', $match[2], $color)) $attrs .= ' color="' . h($color[2]) . '"';
        if ($styles) $attrs .= ' style="' . h(implode(';', array_map(function ($key, $value) { return $key . ':' . $value; }, array_keys($styles), $styles))) . '"';
        return '<' . $tag . $attrs . '>';
    }, $html) ?? '';
}

function special_order_rich_html(string $value): string
{
    if (preg_match('/<\/?(?:b|strong|i|em|u|br|p|div|span|ul|ol|li|a|img|font|blockquote)\b/i', $value)) return special_order_sanitize_rich_html($value);
    return nl2br(h($value));
}

function special_order_rich_input(array $in, string $key, int $max, bool $required = false, string $label = ''): string
{
    $raw = trim((string) ($in[$key] ?? ''));
    $plain = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (strlen($raw) > ($max * 12 + 4096)) fail(($label ?: ucfirst($key)) . ' is too long.', 422, $key);
    if ($required && $plain === '') fail(($label ?: ucfirst($key)) . ' is required.', 422, $key);
    if (!$required && $plain === '') return '';
    if (mb_strlen($plain) > $max) fail(($label ?: ucfirst($key)) . " must be $max characters or fewer.", 422, $key);
    return special_order_sanitize_rich_html($raw);
}

function special_order_plain_text(string $value): string
{
    return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function special_order_fixed_footer(): string
{
    return "3rd Floor, Limketkai Module-2 BPO & Cyberpark Bldg., Rosario Crescent\nLimketkai Center, Brgy 31, Cagayan de Oro City\nContact Number: 09565766106\nEmail: misamisoriental@psa.gov.ph";
}

/* Everything the SO document (print, HR editor) shows, from the SO's row and its participants.
   A row not generated yet starts from the office's remembered values and a default order text.
   $suggested: other requesters of the same activity to list as participants on a new SO. */
function special_order_document(array $state, array $order, array $suggested = []): array
{
    $people = array_column($state['employees'], null, 'id');
    $generated = !empty($order['generatedAt']);
    $soNumber = special_order_plain_text((string) ($order['soNumber'] ?? ''));
    $defaults = special_order_defaults();
    $m = $generated ? $order['soMeta'] : array_merge($defaults, $order['soMeta']);
    $rows = [];
    foreach ($state['employeeRequests'] as $r) {
        if ($r['type'] === 'special_order' && $r['docId'] === $order['id'] && isset($people[$r['empId']])) $rows[$r['empId']] = $r;
    }
    foreach ((array) ($order['soMeta']['proposedParticipants'] ?? []) as $proposedId) {
        $proposedId = (int) $proposedId;
        if (!isset($people[$proposedId]) || isset($rows[$proposedId])) continue;
        $rows[$proposedId] = [
            'empId' => $proposedId,
            'accommodation' => $order['soMeta']['proposedAccommodation'][$proposedId] ?? 'LIVE-OUT',
            'travelTime' => $order['soMeta']['proposedTravelTime'][$proposedId] ?? 'INCLUSIVE',
        ];
    }
    foreach ($suggested as $r) if (!isset($rows[$r['empId']]) && isset($people[$r['empId']])) $rows[$r['empId']] = $r;
    $participants = [];
    foreach ($rows as $empId => $r) {
        $e = $people[$empId];
        $participants[] = ['empId' => $empId, 'name' => $e['lastName'] . ', ' . $e['firstName'], 'firstName' => $e['firstName'], 'lastName' => $e['lastName'], 'office' => $e['location'] ?: $e['department'],
            'title' => $e['title'], 'type' => $e['type'], 'accommodation' => $r['accommodation'], 'travelTime' => $r['travelTime'],
            'requester' => $empId === $order['empId']];
    }
    usort($participants, function ($a, $b) { return $a['name'] <=> $b['name']; });
    $employees = array_map(function ($p) { return ['type' => $p['type']]; }, $participants);
    return [
        'id' => $order['id'], 'generated' => $generated, 'signed' => !empty($order['signedAt']),
        'company' => $state['settings']['company'] ?? 'Philippine Statistics Authority',
        'office' => !empty($order['soOffice']) && $order['soOffice'] !== 'REGION X - NORTHERN MINDANAO' ? (string) $order['soOffice'] : $defaults['office'],
        'number' => $soNumber ?: ($generated ? '' : special_order_next_number()),
        'issued' => $generated ? new DateTimeImmutable($order['generatedAt']) : new DateTimeImmutable(),
        'body' => !$order['soBody'] || ($generated && empty($order['signedAt']) && $order['soBody'] === special_order_legacy_default_body($employees ?: [['type' => '']], $order))
            ? special_order_default_body($employees ?: [['type' => '']], $order) : $order['soBody'],
        'signatoryName' => $order['signatoryName'] ?: $defaults['signatoryName'],
        'signatoryTitle' => !$order['signatoryTitle'] || $order['signatoryTitle'] === 'Senior Statistical Specialist' ? $defaults['signatoryTitle'] : $order['signatoryTitle'],
        'meta' => array_merge($m, [
            'refNo' => $m['refNo'] ?: special_order_reference_number($soNumber ?: ($generated ? '' : special_order_next_number())),
            'to' => !empty($m['toManual']) && $m['to'] !== '' ? $m['to'] : special_order_recipient_line($participants),
            'subject' => !$m['subject'] || $m['subject'] === 'Authority to Attend the ' . $order['purpose'] ? special_order_subject($order) : $m['subject'],
            'issueDate' => $m['issueDate'] ?: ($generated ? (new DateTimeImmutable($order['generatedAt']))->format('F j, Y') : date('F j, Y')),
            'memoBody' => $m['memoBody'] ?: special_order_memo_body($order, $participants),
        ]),
        'autoTo' => empty($m['toManual']),
        'purpose' => $order['purpose'], 'destination' => $order['destination'], 'start' => $order['start'], 'end' => $order['end'],
        'participants' => $participants,
    ];
}

/* Validate the employee's proposed editable SO/RTA content. HR reviews it before generating. */
function special_order_request_draft(array $in, string $purpose, string $destination, string $start, ?string $end, array $employee): array
{
    $defaults = special_order_defaults();
    $number = special_order_plain_text(special_order_rich_input($in, 'soNumber', 40, false, 'SO number')) ?: special_order_next_number();
    $meta = [
        'refNo' => special_order_rich_input($in, 'refNo', 60, false, 'Reference No.') ?: special_order_reference_number($number),
        'to' => special_order_rich_input($in, 'to', 500, false, 'TO'), 'toManual' => !empty($in['toManual']),
        'subject' => special_order_rich_input($in, 'subject', 255, false, 'Subject') ?: special_order_subject(['destination' => $destination, 'purpose' => $purpose]),
        'issueDate' => special_order_rich_input($in, 'issueDate', 80, false, 'Date') ?: date('F j, Y'),
        'memoBody' => special_order_rich_input($in, 'memoBody', 2000, false, 'Travel authority request'),
        'forLine' => special_order_rich_input($in, 'forLine', 80, false, 'Signing line'),
        'signatoryDesignation' => special_order_rich_input($in, 'signatoryDesignation', 120, false, 'Designation'),
        'initials' => special_order_rich_input($in, 'initials', 40, false, 'Initials'),
        'footer' => special_order_fixed_footer(),
        'vehicle' => one_of((string) ($in['vehicle'] ?? 'none'), ['none', 'official'], 'Vehicle'),
        'plate' => special_order_rich_input($in, 'plate', 40, false, 'Plate number'), 'driver' => special_order_rich_input($in, 'driver', 120, false, 'Driver'),
        'allowances' => !empty($in['allowances']), 'meals' => !empty($in['meals']), 'incidental' => !empty($in['incidental']), 'lodging' => !empty($in['lodging']),
        'expenses' => !empty($in['expenses']), 'terminal' => !empty($in['terminal']), 'fuel' => !empty($in['fuel']),
        'others' => special_order_rich_input($in, 'others', 120, false, 'Other expenses'),
        'preparedName' => special_order_rich_input($in, 'preparedName', 120, false, 'Prepared by'),
        'preparedTitle' => special_order_rich_input($in, 'preparedTitle', 120, false, 'Position of the preparer'),
        'routing' => special_order_rich_input($in, 'routing', 300, false, 'Routing slip'),
        'proposedParticipants' => [], 'proposedAccommodation' => [], 'proposedTravelTime' => [],
    ];
    $proposed = array_values(array_unique(array_map('intval', array_filter((array) ($in['p'] ?? []), 'is_numeric'))));
    if (!in_array((int) $employee['id'], $proposed, true)) array_unshift($proposed, (int) $employee['id']);
    if (count($proposed) > 300) fail('A Special Order can list up to 300 participants.');
    $acc = (array) ($in['acc'] ?? []);
    $travel = (array) ($in['tt'] ?? []);
    foreach ($proposed as $empId) {
        $person = employee_exists($empId);
        if ($person['status'] !== 'Active') fail('Only active employees can be proposed as participants.');
        $meta['proposedParticipants'][] = $empId;
        $meta['proposedAccommodation'][$empId] = one_of((string) ($acc[$empId] ?? 'LIVE-OUT'), ['LIVE-OUT', 'LIVE-IN'], 'Accommodation');
        $meta['proposedTravelTime'][$empId] = one_of((string) ($travel[$empId] ?? 'INCLUSIVE'), ['INCLUSIVE', 'EXCLUSIVE'], 'Travel time');
    }
    if (!$meta['toManual'] || $meta['to'] === '') $meta['toManual'] = false;
    if ($meta['memoBody'] === '') $meta['memoBody'] = special_order_memo_body([
        'destination' => $destination, 'purpose' => $purpose, 'start' => $start, 'end' => $end,
    ], [[ 'firstName' => $employee['first_name'], 'lastName' => $employee['last_name'] ]]);
    $office = special_order_rich_input($in, 'soOffice', 160, false, 'Region / office line') ?: $defaults['office'];
    $signatoryName = special_order_rich_input($in, 'signatoryName', 120, false, 'Name of the signatory') ?: $defaults['signatoryName'];
    $signatoryTitle = special_order_rich_input($in, 'signatoryTitle', 120, false, 'Position of the signatory') ?: $defaults['signatoryTitle'];
    $body = special_order_rich_input($in, 'soBody', 4000, false, 'Order text');
    if ($body === '') $body = special_order_default_body([['type' => $employee['type']]], ['purpose' => $purpose, 'destination' => $destination, 'start' => $start, 'end' => $end]);
    return ['number' => $number, 'office' => $office, 'body' => $body, 'signatoryName' => $signatoryName, 'signatoryTitle' => $signatoryTitle, 'meta' => $meta];
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

/* The next suggested number, in the office's style: 2026-0001, 2026-0002 … . The secretary can edit it. */
function special_order_next_number(): string
{
    ensure_special_orders();
    $count = (int) q("SELECT COUNT(*) FROM employee_requests WHERE request_type = 'special_order' AND generated_at IS NOT NULL AND YEAR(generated_at) = ?", [date('Y')])->fetchColumn();
    return date('Y') . '-' . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
}

function special_order_reference_number(string $number): string
{
    if (preg_match('/^(\d{4})-(\d{4})$/', $number, $m)) return substr($m[1], -2) . 'PSO43-TO-' . $m[2];
    return '';
}

function special_order_recipient_line(array $participants): string
{
    if (count($participants) > 3) return 'ALL CONCERNED PSA MISAMIS ORIENTAL OFFICIALS AND PERSONNEL';
    if (!$participants) return 'ALL CONCERNED PSA MISAMIS ORIENTAL OFFICIALS AND PERSONNEL';
    return implode("\n\n", array_map(function ($participant) {
        return mb_strtoupper(trim($participant['firstName'] . ' ' . $participant['lastName'])) . "\n" . $participant['title'];
    }, $participants));
}

function special_order_subject(array $order): string
{
    $subject = 'Authority to travel';
    if ($order['destination'] !== '') $subject .= ' to ' . $order['destination'];
    if ($order['purpose'] !== '') $subject .= ' to Conduct ' . $order['purpose'];
    return $subject . '.';
}

function special_order_memo_body(array $order, array $participants): string
{
    $names = count($participants) > 3
        ? 'the PSA Officials and Personnel listed in the attached list'
        : implode(', ', array_map(function ($p) { return trim($p['firstName'] . ' ' . $p['lastName']); }, $participants));
    if (count($participants) > 1 && count($participants) <= 3) {
        $last = array_pop($participants);
        $names = implode(', ', array_map(function ($p) { return trim($p['firstName'] . ' ' . $p['lastName']); }, $participants)) . ' and ' . trim($last['firstName'] . ' ' . $last['lastName']);
    }
    $where = $order['destination'] !== '' ? ' to ' . $order['destination'] : '';
    $purpose = $order['purpose'] !== '' ? ' to Conduct ' . $order['purpose'] : '';
    return 'Respectfully requesting for the authority to travel of ' . $names . $where . $purpose . ' from '
        . special_order_dates_text($order['start'], $order['end']) . '.';
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

/* Default SO text from the Misamis Oriental sample, with expense wording matched to employment type. */
function special_order_default_body(array $participants, array $request): string
{
    $where = $request['destination'] !== '' ? ' to ' . $request['destination'] : '';
    $event = $request['purpose'] !== '' ? ' to Conduct ' . $request['purpose'] : '';
    $paragraphs = ['The PSA Officials and Personnel in the attached list are hereby authorized to travel' . $where . $event
        . ' from ' . special_order_dates_text($request['start'], $request['end']) . '.'];
    $eligibleForEo298 = array_filter($participants, function ($employee) { return in_array($employee['type'] ?? '', ['Regular', 'Co-Term'], true); });
    if ($eligibleForEo298) {
        $paragraphs[] = 'Payment/reimbursement of allowable traveling expenses based on EO No. 298 series of 2004 and resulting differential pursuant to EO No. 77 series of 2019 is chargeable to the general fund, subject to the usual accounting and auditing rules and regulations.';
    }
    $jobOrder = array_filter($participants, function ($e) { return in_array($e['type'] ?? '', ['Job Order', 'Contract of Service Worker'], true); });
    if ($jobOrder) {
        $paragraphs[] = 'Job Order/Contract of Service Worker shall be entitled to payment of travelling expenses following Office Memorandum 2025-175 subject to the usual accounting and auditing rules and regulations.';
    }
    $paragraphs[] = 'THIS SPECIAL ORDER IS TO TAKE EFFECT ON ' . special_order_dates_text($request['start'], $request['end']) . ' INCLUSIVE OF TRAVEL TIME AND IS TO REMAIN IN FORCE UNTIL SOONER REVOKED OR MODIFIED.';
    return implode("\n\n", $paragraphs);
}

/* Exact prior auto-generated copy only. This lets an unsigned old draft adopt the new template
   while leaving text that HR deliberately edited untouched. */
function special_order_legacy_default_body(array $participants, array $request): string
{
    $where = $request['destination'] !== '' ? ', at ' . $request['destination'] : '';
    $many = count($participants) > 1;
    $paragraphs = [
        'The official participant' . ($many ? 's' : '') . ' listed in the attached document' . ($many ? ' are' : ' is') . ' hereby authorized to attend the ' . $request['purpose']
            . ' on ' . special_order_dates_text($request['start'], $request['end']) . $where . '.',
        'Payment/reimbursement of allowable traveling expenses based on EO No. 77 series of 2019 is chargeable to the general fund, subject to the usual accounting and auditing rules and regulations.',
    ];
    if (array_filter($participants, function ($e) { return in_array($e['type'] ?? '', ['Job Order', 'Contract of Service Worker'], true); })) {
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
    // Move only values that match the old sample defaults; keep office-specific values people typed.
    if (($saved['office'] ?? '') === 'REGION X - NORTHERN MINDANAO') $saved['office'] = 'Misamis Oriental';
    if (($saved['to'] ?? '') === 'ALL CONCERNED PSA RSSO 10 OFFICIALS AND EMPLOYEES') $saved['to'] = '';
    if (($saved['forLine'] ?? '') === 'FOR THE REGIONAL DIRECTOR') $saved['forLine'] = '';
    if (($saved['signatoryTitle'] ?? '') === 'Senior Statistical Specialist') $saved['signatoryTitle'] = 'Chief Statistical Specialist';
    if (($saved['signatoryDesignation'] ?? '') === 'In-Charge of Office, RSSO 10') $saved['signatoryDesignation'] = 'Provincial Statistical Office - Misamis Oriental';
    if (isset($saved['footer']) && strpos($saved['footer'], 'rsso10.psa.gov.ph') !== false) {
        $saved['footer'] = "3rd Floor, Limketkai Module-2 BPO & Cyberpark Bldg., Rosario Crescent\nLimketkai Center, Brgy 31, Cagayan de Oro City\nContact Number: 09565766106\nEmail: misamisoriental@psa.gov.ph";
    }
    // First-time values follow the marked Misamis Oriental PSO sample.
    $saved['footer'] = special_order_fixed_footer();
    return array_merge([
        'office' => 'Misamis Oriental', 'to' => '', 'forLine' => '',
        'signatoryName' => 'MARIA LIZA M. BIGORNIA', 'signatoryTitle' => 'Chief Statistical Specialist', 'signatoryDesignation' => 'Provincial Statistical Office - Misamis Oriental',
        'initials' => '', 'preparedName' => '', 'preparedTitle' => '', 'routing' => '',
        'footer' => special_order_fixed_footer(),
    ], array_map('strval', $saved));
}

/* Everything on the SO, RTA and participants list that has no column of its own. */
function special_order_meta(?string $json): array
{
    $meta = $json ? (array) json_decode($json, true) : [];
    if (!array_key_exists('toManual', $meta)) {
        $oldTo = trim((string) ($meta['to'] ?? ''));
        $meta['toManual'] = $oldTo !== '' && !in_array($oldTo, ['ALL CONCERNED', 'ALL CONCERNED PSA RSSO 10 OFFICIALS AND EMPLOYEES'], true);
    }
    if (($meta['to'] ?? '') === 'ALL CONCERNED PSA RSSO 10 OFFICIALS AND EMPLOYEES' || ($meta['to'] ?? '') === 'ALL CONCERNED') $meta['toManual'] = false;
    if (($meta['forLine'] ?? '') === 'FOR THE REGIONAL DIRECTOR') $meta['forLine'] = '';
    if (($meta['signatoryDesignation'] ?? '') === 'In-Charge of Office, RSSO 10') $meta['signatoryDesignation'] = 'Provincial Statistical Office - Misamis Oriental';
    if (isset($meta['footer']) && strpos($meta['footer'], 'rsso10.psa.gov.ph') !== false) {
        $meta['footer'] = "3rd Floor, Limketkai Module-2 BPO & Cyberpark Bldg., Rosario Crescent\nLimketkai Center, Brgy 31, Cagayan de Oro City\nContact Number: 09565766106\nEmail: misamisoriental@psa.gov.ph";
    }
    return array_merge([
        'refNo' => '', 'to' => '', 'toManual' => false, 'subject' => '', 'issueDate' => '', 'memoBody' => '', 'forLine' => '',
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
function act_special_order_generate(array $in, array $user, ?array $logoUpload = null): array
{
    $row = special_order_row(int_in($in, 'id'));
    if ($row['status'] === 'rejected') fail('This request was declined, so no Special Order can be generated.');
    if ($row['signed_at']) fail('This Special Order is already signed. Upload a new signed copy instead of editing it.');
    $number = special_order_plain_text(special_order_rich_input($in, 'soNumber', 40, true, 'SO number'));
    if ($number === '') fail('SO number is required.', 422, 'soNumber');
    $office = special_order_rich_input($in, 'soOffice', 160, false, 'Region / office line');
    $body = special_order_rich_input($in, 'soBody', 4000, true, 'Order text');
    $signatory = special_order_rich_input($in, 'signatoryName', 120, true, 'Name of the signatory');
    $signatoryTitle = special_order_rich_input($in, 'signatoryTitle', 120, true, 'Position of the signatory');
    $meta = [
        'refNo' => special_order_rich_input($in, 'refNo', 60, false, 'Reference No.'),
        'to' => special_order_rich_input($in, 'to', 500, true, 'TO'), 'toManual' => !empty($in['toManual']),
        'subject' => special_order_rich_input($in, 'subject', 255, true, 'Subject'),
        'issueDate' => special_order_rich_input($in, 'issueDate', 80, true, 'Date'),
        'memoBody' => special_order_rich_input($in, 'memoBody', 2000, true, 'Travel authority request'),
        'forLine' => special_order_rich_input($in, 'forLine', 80, false, 'Signing line'),
        'signatoryDesignation' => special_order_rich_input($in, 'signatoryDesignation', 120, false, 'Designation'),
        'initials' => special_order_rich_input($in, 'initials', 40, false, 'Initials'),
        'footer' => special_order_fixed_footer(),
        'vehicle' => one_of((string) ($in['vehicle'] ?? 'none'), ['none', 'official'], 'Vehicle'),
        'plate' => special_order_rich_input($in, 'plate', 40, false, 'Plate number'),
        'driver' => special_order_rich_input($in, 'driver', 120, false, 'Driver'),
        'allowances' => !empty($in['allowances']), 'meals' => !empty($in['meals']), 'incidental' => !empty($in['incidental']), 'lodging' => !empty($in['lodging']),
        'expenses' => !empty($in['expenses']), 'terminal' => !empty($in['terminal']), 'fuel' => !empty($in['fuel']),
        'others' => special_order_rich_input($in, 'others', 120, false, 'Other expenses'),
        'preparedName' => special_order_rich_input($in, 'preparedName', 120, false, 'Prepared by'),
        'preparedTitle' => special_order_rich_input($in, 'preparedTitle', 120, false, 'Position of the preparer'),
        'routing' => special_order_rich_input($in, 'routing', 300, false, 'Routing slip'),
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
    $existingNumbers = q("SELECT so_number FROM employee_requests WHERE request_type = 'special_order' AND so_number IS NOT NULL AND id <> ? AND (so_group_id IS NULL OR so_group_id <> ?)",
        [$row['id'], $row['id']])->fetchAll(PDO::FETCH_COLUMN);
    $numberText = special_order_plain_text($number);
    $taken = in_array($numberText, array_map('special_order_plain_text', $existingNumbers), true);
    if ($taken) fail('Another Special Order already uses number ' . $number . '.', 422, 'soNumber');

    if (!empty($in['resetSoLogo'])) {
        q('UPDATE so_branding SET header_logo=NULL, header_logo_type=NULL WHERE id=1');
    } elseif ($logoUpload !== null) {
        $image = @getimagesizefromstring($logoUpload['bytes']);
        if (!$image || !in_array($image['mime'] ?? '', ['image/png', 'image/jpeg'], true)) {
            fail('The header logo must be a valid PNG or JPG image.', 422, 'soHeaderLogo');
        }
        q("INSERT INTO so_branding (id, header_logo, header_logo_type) VALUES (1, ?, ?)
           ON DUPLICATE KEY UPDATE header_logo=VALUES(header_logo), header_logo_type=VALUES(header_logo_type)", [$logoUpload['bytes'], $image['mime']]);
    }

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
