<?php
declare(strict_types=1);

/* My profile: details employees keep up to date themselves, and documents HR shares.
   authorize_action() forces empId onto the signed-in employee for the self-service actions;
   uploading and removing documents is HR-only. */

function act_profile_personal_save(array $in): array
{
    ensure_profile_details();
    $emp = employee_exists(int_in($in, 'empId'));
    $email = strtolower(str_in($in, 'personalEmail', 160, false, 'Personal email'));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid personal email, like name@gmail.com, or leave it blank.', 422, 'personalEmail');
    q('UPDATE employees SET preferred_name = ?, personal_email = ?, phone = ?, home_address = ? WHERE id = ?', [
        str_in($in, 'preferredName', 80, false, 'Preferred name') ?: null,
        $email ?: null,
        ph_phone(str_in($in, 'phone', 40, false, 'Phone number')),
        str_in($in, 'homeAddress', 255, false, 'Home address') ?: null,
        $emp['id'],
    ]);
    return [];
}

function act_profile_emergency_save(array $in): array
{
    ensure_profile_details();
    $emp = employee_exists(int_in($in, 'empId'));
    $name = str_in($in, 'emergencyName', 120, false, 'Contact name');
    $phone = ph_phone(str_in($in, 'emergencyPhone', 40, false, 'Phone number'));
    if ($name !== '' && $phone === '') fail('Add a phone number for your emergency contact.', 422, 'emergencyPhone');
    q('UPDATE employees SET emergency_name = ?, emergency_relationship = ?, emergency_phone = ? WHERE id = ?', [
        $name ?: null, str_in($in, 'emergencyRelationship', 60, false, 'Relationship') ?: null, $phone ?: null, $emp['id'],
    ]);
    return [];
}

/* HR only. $file comes from leave_attachment_upload() on a real upload. */
function act_document_upload(array $in, array $user, ?array $file): array
{
    ensure_profile_details();
    if (!$file) fail('Choose the document to upload (PDF, JPG or PNG, up to 5 MB).', 422, 'document');
    $empId = int_in($in, 'empId', false);
    if ($empId) employee_exists($empId);
    q('INSERT INTO employee_documents (emp_id, title, note, requires_ack, file, file_name, file_type, created_by) VALUES (?,?,?,?,?,?,?,?)', [
        $empId ?: null, str_in($in, 'title', 120, true, 'Document title'), str_in($in, 'note', 160, false, 'Note'),
        empty($in['requiresAck']) ? 0 : 1, $file['bytes'], $file['name'], $file['type'], ($user['id'] ?? 0) ?: null,
    ]);
    return ['id' => (int) db()->lastInsertId()];
}

function act_document_delete(array $in): array
{
    ensure_profile_details();
    q('DELETE FROM employee_documents WHERE id = ?', [int_in($in, 'id')]);
    return [];
}

function act_document_acknowledge(array $in): array
{
    ensure_profile_details();
    $empId = int_in($in, 'empId');
    $doc = q('SELECT emp_id, requires_ack FROM employee_documents WHERE id = ?', [int_in($in, 'id')])->fetch();
    if (!$doc || ($doc['emp_id'] !== null && (int) $doc['emp_id'] !== $empId)) fail('That document was not found.', 404);
    q('INSERT IGNORE INTO document_acknowledgements (document_id, emp_id) VALUES (?, ?)', [(int) $in['id'], $empId]);
    return [];
}

/* A shared document's file, if this user may open it: HR, the employee it was shared with,
   or anyone when it was shared with everyone. Null when not found or not allowed. */
function document_for(array $user, int $id): ?array
{
    ensure_profile_details();
    $doc = q('SELECT emp_id, file, file_name, file_type FROM employee_documents WHERE id = ?', [$id])->fetch();
    if (!$doc) return null;
    if (!is_admin($user) && $doc['emp_id'] !== null && (int) $doc['emp_id'] !== (int) ($user['employeeId'] ?? 0)) return null;
    return $doc;
}

/* Document metadata (never the files) for page data: HR gets all; an employee their own and company-wide ones. */
function documents_state(array $user): array
{
    ensure_profile_details();
    $me = is_admin($user) ? null : (int) $user['employeeId'];
    $rows = q('SELECT d.id, d.emp_id, d.title, d.note, d.requires_ack, d.file_name, d.created_at,
                      (SELECT COUNT(*) FROM document_acknowledgements a WHERE a.document_id = d.id) AS ack_count,
                      (SELECT a.acknowledged_at FROM document_acknowledgements a WHERE a.document_id = d.id AND a.emp_id = ?) AS my_ack
               FROM employee_documents d
               WHERE ? IS NULL OR d.emp_id IS NULL OR d.emp_id = ?
               ORDER BY d.created_at DESC, d.id DESC', [$me, $me, $me])->fetchAll();
    return array_map(function ($d) use ($me) {
        return [
            'id' => (int) $d['id'], 'empId' => $d['emp_id'] === null ? null : (int) $d['emp_id'], 'title' => $d['title'], 'note' => $d['note'],
            'requiresAck' => (bool) $d['requires_ack'], 'fileName' => $d['file_name'], 'createdAt' => $d['created_at'],
            'acknowledgedAt' => $me === null ? null : $d['my_ack'],
            'ackCount' => $me === null ? (int) $d['ack_count'] : null, // HR only
        ];
    }, $rows);
}
