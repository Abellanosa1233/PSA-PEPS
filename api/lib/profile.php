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
    foreach (['emergencyName', 'emergencyRelationship', 'emergencyPhone'] as $field) {
        if (!is_string($in[$field] ?? '')) fail('Enter a text value for this field.', 422, $field);
    }
    $name = str_in($in, 'emergencyName', 120, false, 'Contact name');
    $relationship = str_in($in, 'emergencyRelationship', 60, false, 'Relationship');
    $phone = str_in($in, 'emergencyPhone', 40, false, 'Phone number');
    if ($name !== '' || $relationship !== '' || $phone !== '') {
        if ($name === '') fail('Enter the contact name.', 422, 'emergencyName');
        if ($relationship === '') fail('Enter the relationship.', 422, 'emergencyRelationship');
        if ($phone === '') fail('Enter the phone number.', 422, 'emergencyPhone');
        $phone = ph_phone($phone, 'emergencyPhone');
    }
    q('UPDATE employees SET emergency_name = ?, emergency_relationship = ?, emergency_phone = ? WHERE id = ?', [
        $name === '' ? null : $name, $relationship === '' ? null : $relationship, $phone === '' ? null : $phone, $emp['id'],
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

function act_coe_send(array $in, array $user, ?array $file): array
{
    if (!can_manage_coe_requests($user)) fail('Only HR or focal persons can send COE copies.', 403);
    if (!$file || $file['type'] !== 'application/pdf') fail('Choose the finished certificate as a PDF.', 422, 'certificatePdf');
    $id = int_in($in, 'requestId');
    ensure_employee_requests_table();
    ensure_profile_details();
    db()->beginTransaction();
    try {
        $request = q("SELECT emp_id, coe_document_id, start_date, end_date FROM employee_requests
                      WHERE id=? AND request_type='coe' FOR UPDATE", [$id])->fetch();
        if (!$request) fail('That COE request no longer exists.', 404);
        $empId = (int) $request['emp_id'];
        $documentId = $request['coe_document_id'] ? q('SELECT id FROM employee_documents WHERE id=? AND emp_id=? FOR UPDATE', [$request['coe_document_id'], $empId])->fetchColumn() : false;
        if ($documentId) {
            q('UPDATE employee_documents SET file=?, file_name=?, file_type=?, created_by=?, created_at=NOW() WHERE id=?',
                [$file['bytes'], $file['name'], $file['type'], $user['id'], $documentId]);
        } else {
            $period = $request['start_date'] . ($request['end_date'] ? ' to ' . $request['end_date'] : '');
            $documentId = act_document_upload(['empId' => $empId, 'title' => 'Certificate of Employment', 'note' => $period], $user, $file)['id'];
            q('UPDATE employee_requests SET coe_document_id=? WHERE id=?', [$documentId, $id]);
        }
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return ['id' => (int) $documentId];
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
