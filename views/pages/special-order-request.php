<?php
/* Employee request: the same proposed SO/RTA document HR reviews and prepares for signature.
   The administration logo remains managed by HR and is not an editable document field. */
$me = (int) $user['employeeId'];
$self = null;
foreach ($state['employees'] as $e) if ($e['id'] === $me) $self = $e;
$draft = $_SESSION['so_draft'] ?? [];
$defaults = special_order_defaults();
$active = q("SELECT id, first_name AS firstName, last_name AS lastName, location, department, title, emp_type AS type
             FROM employees WHERE status = 'Active' ORDER BY last_name, first_name")->fetchAll();
$active = array_map(function ($e) { $e['id'] = (int) $e['id']; return $e; }, $active);
$activeById = array_column($active, null, 'id');
$purpose = (string) ($draft['purpose'] ?? '');
$destination = (string) ($draft['destination'] ?? '');
$start = (string) ($draft['start'] ?? today());
$end = (string) ($draft['end'] ?? '');
$request = ['purpose' => $purpose, 'destination' => $destination, 'start' => $start, 'end' => $end !== '' ? $end : null];
$participant = [
    'empId' => $me,
    'name' => $self ? $self['lastName'] . ', ' . $self['firstName'] : '',
    'firstName' => $self['firstName'] ?? '', 'lastName' => $self['lastName'] ?? '',
    'office' => $self ? ($self['location'] ?: $self['department']) : '',
    'title' => $self['title'] ?? '', 'type' => $self['type'] ?? 'Regular',
    'accommodation' => $draft['acc'][$me] ?? 'LIVE-OUT', 'travelTime' => $draft['tt'][$me] ?? 'INCLUSIVE',
    'requester' => true,
];
$participants = [$participant];
$proposedIds = array_values(array_unique(array_map('intval', array_filter((array) ($draft['p'] ?? []), 'is_numeric'))));
foreach ($proposedIds as $proposedId) {
    if ($proposedId === $me || !isset($activeById[$proposedId])) continue;
    $person = $activeById[$proposedId];
    $participants[] = [
        'empId' => $proposedId, 'name' => $person['lastName'] . ', ' . $person['firstName'],
        'firstName' => $person['firstName'], 'lastName' => $person['lastName'],
        'office' => $person['location'] ?: $person['department'], 'title' => $person['title'], 'type' => $person['type'],
        'accommodation' => $draft['acc'][$proposedId] ?? 'LIVE-OUT', 'travelTime' => $draft['tt'][$proposedId] ?? 'INCLUSIVE', 'requester' => false,
    ];
}
$metaDefaults = special_order_meta(null);
$metaKeys = array_flip(array_keys($metaDefaults));
$meta = array_merge($metaDefaults, $defaults, array_intersect_key($draft, $metaKeys));
$meta['to'] = !empty($meta['toManual']) && $meta['to'] !== '' ? $meta['to'] : special_order_recipient_line($participants);
$number = (string) ($draft['soNumber'] ?? '') ?: special_order_next_number();
$meta['refNo'] = (string) ($meta['refNo'] ?? '') ?: special_order_reference_number($number);
$meta['subject'] = (string) ($meta['subject'] ?? '') ?: special_order_subject($request);
$meta['issueDate'] = (string) ($meta['issueDate'] ?? '') ?: date('F j, Y');
$meta['memoBody'] = (string) ($meta['memoBody'] ?? '') ?: special_order_memo_body($request, $participants);
$body = (string) ($draft['soBody'] ?? '') ?: special_order_default_body(array_map(function ($p) { return ['type' => $p['type']]; }, $participants), $request);
$doc = [
    'id' => 0, 'generated' => false, 'signed' => false,
    'company' => $state['settings']['company'] ?? 'Philippine Statistics Authority',
    'office' => (string) ($draft['soOffice'] ?? '') ?: $defaults['office'],
    'number' => $number, 'issued' => new DateTimeImmutable(), 'body' => $body,
    'signatoryName' => (string) ($draft['signatoryName'] ?? '') ?: $defaults['signatoryName'],
    'signatoryTitle' => (string) ($draft['signatoryTitle'] ?? '') ?: $defaults['signatoryTitle'],
    'meta' => $meta, 'autoTo' => empty($meta['toManual']),
    'purpose' => $purpose, 'destination' => $destination, 'start' => $start, 'end' => $end !== '' ? $end : null,
    'participants' => $participants,
];
so_document_assets(true);
?>
<form method="post" data-so-editor data-so-requester class="so-editor">
    <?php hidden_inputs(['_action' => 'employee.request.create', '_token' => $_SESSION['web_csrf'], 'empId' => $me, 'type' => 'special_order', 'toManual' => empty($meta['toManual']) ? 0 : 1]); ?>
    <div class="page-head so-editor-head">
        <div>
            <h1>Request Special Order</h1>
            <p>Edit the proposed SO/RTA in the three-page PDF template below. Use the toolbar for formatting, colors, links, lists, alignment and spacing. Font size and family stay as in the template. HR reviews your request and prepares the final copy; only HR can change the administration logo.</p>
        </div>
        <div class="actions">
            <a class="btn" href="<?= h(page_url('special-orders', ['clear' => 1])) ?>">Cancel</a>
            <button class="btn" type="button" data-so-print>Print preview</button>
            <button class="btn primary" type="submit">Submit request</button>
        </div>
    </div>
    <div class="card so-request-extra">
        <div class="request-inputs">
            <label>Activity <input class="input" name="purpose" maxlength="160" required value="<?= h($purpose) ?>"></label>
            <label>Venue <input class="input" name="destination" maxlength="160" value="<?= h($destination) ?>"></label>
            <label>From <input class="input" type="date" name="start" required value="<?= h($start) ?>"></label>
            <label>To <input class="input" type="date" name="end" value="<?= h($end) ?>"></label>
        </div>
        <label class="field"><span>Notes for HR (optional): organizer, invitation or memo reference</span>
            <input class="input" name="details" maxlength="1000" value="<?= h((string) ($draft['details'] ?? '')) ?>" placeholder="e.g. Invitation memo dated November 18, 2026"></label>
    </div>
    <?php so_format_toolbar(); ?>
    <div class="so-desk"><?php so_pages($doc, true, $active, true); ?></div>
</form>
