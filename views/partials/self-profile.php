<?php
declare(strict_types=1);

/* My profile sections. Work details come from HR's record (read-only here); personal details and
   the emergency contact are the employee's own to keep up to date; documents are shared by HR. */

function self_employee_code(int $id): string
{
    return 'EMP-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function self_value(?string $v, string $empty = 'Not added yet'): string
{
    return $v !== null && trim($v) !== '' ? h($v) : '<span class="muted">' . h($empty) . '</span>';
}

function self_profile_header_card(array $me): void
{
    $active = $me['status'] === 'Active';
    echo '<section class="card self-id"><div class="card-body">'
        . '<div class="self-id-top"><div><h2>' . h(employee_name($me)) . '</h2><p class="muted">' . h($me['title'] . ' · ' . $me['department']) . '</p></div>'
        . '<span class="badge ' . ($active ? 'ok' : 'plain') . '">' . ($active ? 'Active employee' : 'Offboarded') . '</span></div>'
        . '<dl class="self-id-facts"><div><dt>Employee ID</dt><dd>' . h(self_employee_code($me['id'])) . '</dd></div>'
        . '<div><dt>Work email</dt><dd>' . h($me['email']) . '</dd></div>'
        . '<div><dt>Location</dt><dd>' . self_value($me['location'], '—') . '</dd></div></dl></div></section>';
}

function self_employment_card(array $me, array $people): void
{
    $manager = $me['managerId'] !== null ? ($people[$me['managerId']] ?? null) : null;
    $arrangement = strcasecmp(trim((string) $me['location']), 'Remote') === 0 ? 'Remote' : 'On-site';
    echo '<section class="card"><div class="card-head"><h3>Employment summary</h3></div><div class="card-body"><dl class="self-today-facts self-facts">'
        . '<dt>Employment type</dt><dd>' . h($me['type']) . '</dd>'
        . '<dt>Start date</dt><dd>' . h((new DateTimeImmutable($me['startDate']))->format('M j, Y')) . '</dd>'
        . '<dt>Manager</dt><dd>' . h($manager ? employee_name($manager) : '—') . '</dd>'
        . '<dt>Work arrangement</dt><dd>' . h($arrangement) . '</dd>'
        . '<dt>Biometric ID</dt><dd>' . self_value($me['biometricId'] ?? null, 'Not set — ask HR') . '</dd>'
        . '</dl></div></section>';
}

function self_personal_card(array $me, bool $editable, bool $openForm = false): void
{
    echo '<section class="card" id="personal"><div class="card-head"><h3>Personal information</h3></div><div class="card-body"><dl class="self-rows">'
        . '<dt>Legal name</dt><dd>' . h(employee_name($me)) . '</dd>'
        . '<dt>Preferred name</dt><dd>' . self_value($me['preferredName'] ?? null) . '</dd>'
        . '<dt>Personal email</dt><dd>' . self_value($me['personalEmail'] ?? null) . '</dd>'
        . '<dt>Phone number</dt><dd>' . self_value($me['phone'] ?? null) . '</dd>'
        . '<dt>Home address</dt><dd>' . self_value($me['homeAddress'] ?? null) . '</dd>'
        . '</dl>';
    if ($editable) {
        echo '<details class="form-disclosure" id="edit-personal" data-form-dialog="form"' . ($openForm ? ' open data-auto-open' : '') . '><summary class="btn">Edit personal details</summary>';
        post_form('profile.personal.save', ['empId' => $me['id']]);
        field('preferredName', 'Preferred name', $me['preferredName'] ?? '', 'text', false, '', ['maxlength' => '80']);
        field('personalEmail', 'Personal email', $me['personalEmail'] ?? '', 'email', false, '', ['maxlength' => '160', 'autocomplete' => 'email']);
        field('phone', 'Phone number', $me['phone'] ?? '', 'tel', false, '', ['maxlength' => '40', 'autocomplete' => 'tel']);
        field('homeAddress', 'Home address', $me['homeAddress'] ?? '', 'text', false, '', ['maxlength' => '255', 'autocomplete' => 'street-address']);
        echo '<p class="hint">Your legal name, job title and department come from HR. Philippine phone numbers are saved as +63 917 123 4567.</p>';
        end_form('Save personal details');
        echo '</details>';
    }
    echo '</div></section>';
}

function self_emergency_card(array $me, bool $editable): void
{
    echo '<section class="card"><div class="card-head"><h3>Emergency contact</h3></div><div class="card-body"><dl class="self-stack">'
        . '<dt>Contact name</dt><dd>' . self_value($me['emergencyName'] ?? null) . '</dd>'
        . '<dt>Relationship</dt><dd>' . self_value($me['emergencyRelationship'] ?? null, '—') . '</dd>'
        . '<dt>Phone number</dt><dd>' . self_value($me['emergencyPhone'] ?? null, '—') . '</dd></dl>';
    if ($editable) {
        echo '<details class="form-disclosure" data-form-dialog="form"><summary class="btn self-wide">Edit emergency contact</summary>';
        post_form('profile.emergency.save', ['empId' => $me['id']]);
        field('emergencyName', 'Contact name', $me['emergencyName'] ?? '', 'text', false, '', ['maxlength' => '120']);
        field('emergencyRelationship', 'Relationship', $me['emergencyRelationship'] ?? '', 'text', false, '', ['maxlength' => '60']);
        field('emergencyPhone', 'Phone number', $me['emergencyPhone'] ?? '', 'tel', false, '', ['maxlength' => '40']);
        end_form('Save emergency contact');
        echo '</details>';
    }
    echo '</div></section>';
}

/* Documents HR shared with this employee (or everyone), with View and Acknowledge. */
function self_documents_card(array $state, int $id): void
{
    $docs = array_values(array_filter($state['documents'] ?? [], function ($d) use ($id) { return $d['empId'] === null || $d['empId'] === $id; }));
    echo '<section class="card"><div class="card-head"><h3>My documents</h3></div>';
    if (!$docs) {
        echo '<div class="empty"><strong>No documents yet</strong><span>Your contract, the employee handbook and other documents appear here when HR shares them.</span></div></section>';
        return;
    }
    echo '<div class="self-docs">';
    foreach ($docs as $d) {
        $added = (new DateTimeImmutable($d['createdAt']))->format('M j, Y');
        $status = $d['acknowledgedAt'] ? 'Acknowledged · ' . (new DateTimeImmutable($d['acknowledgedAt']))->format('M j, Y')
            : (($d['note'] !== '' ? $d['note'] . ' · ' : '') . 'Added ' . $added);
        echo '<div class="self-doc"><span><strong>' . h($d['title']) . '</strong><small>' . h($status) . ($d['requiresAck'] && !$d['acknowledgedAt'] ? ' · <span class="badge warn">Please acknowledge</span>' : '') . '</small></span>'
            . '<span class="self-doc-actions">';
        if ($d['requiresAck'] && !$d['acknowledgedAt']) {
            confirm_action('document.acknowledge', 'Acknowledge', 'Confirm you have read “' . $d['title'] . '”?', ['id' => $d['id'], 'empId' => $id]);
        }
        echo '<a class="link-btn" href="' . h(page_url('document', ['id' => $d['id']])) . '" target="_blank" rel="noopener">View document ' . icon('arrowRight', 14) . '</a></span></div>';
    }
    echo '</div></section>';
}

function self_protected_card(): void
{
    echo '<section class="card"><div class="card-head"><h3>Your information, protected</h3></div><div class="card-body">'
        . '<p class="muted">Personal information is visible only to you and authorized HR team members.</p>'
        . '<p class="self-protect-q"><strong>Need to change your work details?</strong><br><span class="muted">Contact HR to update your role, department or manager.</span></p>'
        . '<a class="link-btn" href="' . h(page_url('help')) . '">Contact HR ' . icon('arrowRight', 14) . '</a></div></section>';
}

/* HR: documents shared with one employee, plus the upload form. Shown on the employee's profile. */
function hr_employee_documents_card(array $state, array $employee): void
{
    $id = $employee['id'];
    $docs = array_values(array_filter($state['documents'] ?? [], function ($d) use ($id) { return $d['empId'] === $id; }));
    card('Documents');
    echo '<div class="card-body"><p class="muted">Shared with ' . h($employee['firstName']) . ' on My profile. Company-wide documents (like the handbook) are managed in Settings.</p>';
    hr_document_upload_form($id, 'Share a document with ' . $employee['firstName']);
    if ($docs) {
        web_table(['Document', 'Added', 'Status', 'Actions'], array_map(function ($d) {
            return [
                function () use ($d) { echo '<a href="' . h(page_url('document', ['id' => $d['id']])) . '" target="_blank" rel="noopener">' . h($d['title']) . '</a>' . ($d['note'] !== '' ? '<span class="sub">' . h($d['note']) . '</span>' : ''); },
                (new DateTimeImmutable($d['createdAt']))->format('M j, Y'),
                $d['requiresAck'] ? ($d['ackCount'] ? 'Acknowledged' : 'Waiting for acknowledgement') : '—',
                function () use ($d) { confirm_action('document.delete', 'Remove', 'Remove “' . $d['title'] . '”? The employee will no longer see it.', ['id' => $d['id']]); },
            ];
        }, $docs));
    }
    echo '</div></section>';
}

/* HR: company-wide documents for everyone (Settings). */
function hr_company_documents_card(array $state): void
{
    $docs = array_values(array_filter($state['documents'] ?? [], function ($d) { return $d['empId'] === null; }));
    $active = count(array_filter($state['employees'], function ($e) { return $e['status'] === 'Active'; }));
    card('Company documents');
    echo '<div class="card-body"><p class="muted">Shared with every employee on My profile, for example the employee handbook or code of conduct.</p>';
    hr_document_upload_form(null, 'Share a document with everyone');
    if ($docs) {
        web_table(['Document', 'Added', 'Acknowledged', 'Actions'], array_map(function ($d) use ($active) {
            return [
                function () use ($d) { echo '<a href="' . h(page_url('document', ['id' => $d['id']])) . '" target="_blank" rel="noopener">' . h($d['title']) . '</a>' . ($d['note'] !== '' ? '<span class="sub">' . h($d['note']) . '</span>' : ''); },
                (new DateTimeImmutable($d['createdAt']))->format('M j, Y'),
                $d['requiresAck'] ? $d['ackCount'] . ' of ' . $active . ' employees' : 'Not required',
                function () use ($d) { confirm_action('document.delete', 'Remove', 'Remove “' . $d['title'] . '” for everyone?', ['id' => $d['id']]); },
            ];
        }, $docs));
    }
    echo '</div></section>';
}

function hr_document_upload_form(?int $empId, string $label): void
{
    echo '<details class="form-disclosure" data-form-dialog="form"><summary class="btn">' . icon('plus', 14) . ' ' . h($label) . '</summary>';
    post_form('document.upload', $empId ? ['empId' => $empId] : [], false, 'multipart/form-data');
    field('title', 'Document title', '', 'text', true, '', ['maxlength' => '120']);
    field('note', 'Note (optional)', '', 'text', false, '', ['maxlength' => '160']);
    echo '<div class="field"><label for="document-file-' . ($empId ?: 'all') . '">File</label><input class="input" id="document-file-' . ($empId ?: 'all') . '" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required><small class="hint">PDF, JPG or PNG, up to 5 MB.</small></div>';
    echo '<label class="check-line"><input type="checkbox" name="requiresAck" value="1"> Ask the employee to acknowledge it</label>';
    end_form('Share document');
    echo '</details>';
}

/* HR: an employee's personal details and emergency contact (read-only for HR; the employee keeps them up to date). */
function hr_personal_details_card(array $employee): void
{
    card('Personal details and emergency contact');
    echo '<div class="card-body"><dl class="self-rows">'
        . '<dt>Preferred name</dt><dd>' . self_value($employee['preferredName'] ?? null, '—') . '</dd>'
        . '<dt>Personal email</dt><dd>' . self_value($employee['personalEmail'] ?? null, '—') . '</dd>'
        . '<dt>Home address</dt><dd>' . self_value($employee['homeAddress'] ?? null, '—') . '</dd>'
        . '<dt>Emergency contact</dt><dd>' . self_value(trim(($employee['emergencyName'] ?? '') . (($employee['emergencyRelationship'] ?? '') !== '' ? ' (' . $employee['emergencyRelationship'] . ')' : '')), '—') . '</dd>'
        . '<dt>Emergency phone</dt><dd>' . self_value($employee['emergencyPhone'] ?? null, '—') . '</dd>'
        . '</dl><p class="muted">The employee keeps these up to date on My profile.</p></div></section>';
}
