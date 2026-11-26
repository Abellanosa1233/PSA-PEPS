<?php
declare(strict_types=1);

function employee_form(array $state, array $employee = [], ?array $candidate = null): void
{
    $hidden = $employee ? ['id' => $employee['id']] : [];
    if ($candidate) {
        $hidden['fromCandidate'] = $candidate['id'];
        [$first, $last] = array_pad(preg_split('/\s+/', $candidate['name'], 2), 2, '');
        $employee += ['firstName' => $first, 'lastName' => $last, 'email' => $candidate['email'],
            'title' => $candidate['position']['title'], 'department' => $candidate['position']['department'],
            'type' => $candidate['position']['type'], 'location' => $candidate['position']['location']];
    }
    card('Add employee');
    post_form('employee.save', $hidden);
    employee_fields($state, $employee);
    echo '<p class="full muted">A temporary HRMS login will be emailed to the address entered above.</p>';
    end_form('Save employee');
    echo '</section>';
}

function employee_fields(array $state, array $employee, ?int $employeeId = null, array $errors = []): void
{
    field('firstName', 'First name', $employee['firstName'] ?? '', 'text', true, $errors['firstName'] ?? '');
    field('lastName', 'Last name', $employee['lastName'] ?? '', 'text', true, $errors['lastName'] ?? '');
    $emailAttributes = $employeeId === null ? [
        'placeholder' => 'name@gmail.com', 'inputmode' => 'email', 'autocomplete' => 'email',
    ] : [];
    field('email', $employeeId === null ? 'Email (@gmail.com by default)' : 'Email',
        $employee['email'] ?? '', $employeeId === null ? 'text' : 'email', true, $errors['email'] ?? '', $emailAttributes);
    field('phone', 'Phone', $employee['phone'] ?? '', 'tel', false, $errors['phone'] ?? '');
    $types = EMPLOYEE_TYPES;
    if ($employeeId !== null && !empty($employee['type']) && !in_array($employee['type'], $types, true)) $types[] = $employee['type'];
    choice('type', 'Employment type', options($types), $employee['type'] ?? '');
    employee_position_field($employee, $errors['title'] ?? '', $employeeId !== null);
    field('location', 'Location', $employee['location'] ?? '', 'text', false, $errors['location'] ?? '');
    field('startDate', 'Start date', $employee['startDate'] ?? $state['today'], 'date', true, $errors['startDate'] ?? '');
    field('biometricId', 'Biometric ID', $employee['biometricId'] ?? '', 'text', false, $errors['biometricId'] ?? '', ['maxlength' => '30', 'pattern' => '[A-Za-z0-9_-]+']);
}

function employee_position_field(array $employee, string $error, bool $editing): void
{
    static $sequence = 0;
    $selected = $employee['title'] ?? '';
    $type = $employee['type'] ?? EMPLOYEE_TYPES[0];
    $positions = employee_position_options();
    if ($editing && $selected !== '' && !isset($positions[$type][$selected])) {
        $positions[$type][$selected] = $selected . ' (current)';
    }
    $listId = 'employee-position-options-' . ++$sequence;
    echo '<label class="field" data-employee-position="' . html_json($positions) . '"><span>Position</span>';
    echo '<input class="input" type="text" name="title" placeholder="Search position name or abbreviation" autocomplete="off" maxlength="120"'
        . ' value="' . h($selected) . '" list="' . h($listId) . '"' . ($error !== '' ? ' aria-invalid="true"' : '') . '>';
    echo '<datalist id="' . h($listId) . '">';
    foreach ($positions[$type] ?? [] as $value => $label) {
        echo '<option value="' . h($value) . '" label="' . h($label) . '"></option>';
    }
    echo '</datalist>';
    if ($error !== '') echo '<span class="field-error" role="alert">' . h($error) . '</span>';
    echo '</label>';
}

/* "Sign-in access" in the Add/Edit employee form. $knownAccount: true/false when we know whether this
   person has a login (profile page), null in the shared Add/Edit dialog (filled in per person). */
function employee_login_fields(array $state, array $employee, array $errors = [], ?bool $knownAccount = null): void
{
    $account = isset($employee['id']) ? (((array) ($state['accounts'] ?? []))[$employee['id']] ?? null) : null;
    $username = $employee['loginUsername'] ?? ($account['username'] ?? '');
    echo '<fieldset class="form-grid employee-login full"><legend>Sign-in access <span class="muted">(optional)</span></legend>';
    echo '<p class="full muted employee-login-help">Give this person a login to their own workspace: attendance, time off, and Special and Travel Orders. '
        . 'Leave both boxes empty if they don’t need one. To reset a forgotten password, enter a new temporary password.</p>';
    field('loginUsername', 'Username', $username, 'text', false, $errors['loginUsername'] ?? '', ['maxlength' => '60', 'pattern' => '[A-Za-z0-9._-]+', 'autocomplete' => 'off']);
    field('loginPassword', $account ? 'New temporary password' : 'Temporary password', '', 'password', false, $errors['loginPassword'] ?? '', ['minlength' => '8', 'maxlength' => '72', 'autocomplete' => 'new-password']);
    echo '<p class="full hint">At least 8 characters. Share it privately; they’ll be asked to choose their own after signing in.'
        . ($account && !empty($account['mustChangePassword']) ? ' They haven’t changed their current temporary password yet.' : '') . '</p>';
    if ($knownAccount !== false) {
        echo '<label class="check-line full"><input type="checkbox" name="removeAccess" value="1"> Remove sign-in access' . ($knownAccount === null ? ' (if they have one)' : '') . '</label>';
    }
    echo '</fieldset>';
}

function add_employee_button(string $page, array $query = []): void
{
    $url = page_url($page, ['add' => 1] + $query);
    echo '<a class="btn primary" data-add-employee href="' . h($url) . '">' . icon('plus') . ' Add employee</a>';
}

function add_employee_modal(array $state, string $page, array $formError): void
{
    $failed = !empty($formError['employeeModal']);
    $employees = array_column($state['employees'], null, 'id');
    $editing = $page === 'employees' ? ($employees[(int) ($formError['values']['id'] ?? $_GET['edit'] ?? 0)] ?? null) : null;
    $open = $failed || $editing || isset($_GET['add']) || ($_GET['id'] ?? '') === 'new';
    $query = array_intersect_key($_GET, array_flip(['q', 'department', 'status', 'type', 'todayStatus', 'p', 'date', 'week']));
    if (!empty($_GET['id']) && $editing) $query['id'] = $editing['id'];
    $values = $failed ? $formError['values'] : ($editing ?? []);
    $errorField = $formError['field'] ?? '';
    $errors = $failed && in_array($errorField, ['firstName', 'lastName', 'email', 'phone', 'title', 'location', 'startDate', 'salary', 'biometricId', 'loginUsername', 'loginPassword'], true)
        ? [$errorField => $formError['message']] : [];
    ?>
    <dialog id="add-employee" class="modal wide employee-dialog" aria-labelledby="add-employee-title"<?= $open ? ' open data-reopen' : '' ?>>
        <div class="modal-head">
            <h2 id="add-employee-title"><?= $editing ? 'Edit employee' : 'Add employee' ?></h2>
            <a class="sq" data-close-employee href="<?= h(page_url($page, $query)) ?>" aria-label="Close"><?= icon('x', 14) ?></a>
        </div>
        <form method="post" action="<?= h(page_url($page, $query)) ?>"<?= $editing ? ' data-original-employee="' . html_json($editing + ['loginUsername' => ((array) ($state['accounts'] ?? []))[$editing['id']]['username'] ?? '']) . '"' : '' ?>>
            <?php hidden_inputs(['_action' => 'employee.save', '_token' => $_SESSION['web_csrf'], '_employee_modal' => '1']); ?>
            <input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
            <input type="hidden" data-new-employee-date value="<?= h($state['today']) ?>">
            <div class="modal-body">
                <?php if ($failed && !$errors) web_message($formError['message'], ''); ?>
                <div class="form-grid"><?php employee_fields($state, $values, $editing['id'] ?? null, $errors); ?></div>
                <div class="form-grid" data-employee-location hidden>
                    <p class="full muted" data-current-location hidden></p>
                    <label class="field"><span>Province</span><select class="input" data-province aria-describedby="employee-location-message"></select></label>
                    <label class="field"><span>City or municipality</span><select class="input" data-city aria-describedby="employee-location-message" disabled></select></label>
                    <label class="field"><span>Barangay</span><select class="input" data-barangay aria-describedby="employee-location-message" disabled></select></label>
                    <div class="full">
                        <span id="employee-location-message" data-location-message aria-live="polite"></span>
                        <button class="btn" type="button" data-location-retry hidden>Retry loading locations</button>
                    </div>
                </div>
                <?php if ($editing): ?>
                    <div class="form-grid"><?php employee_login_fields($state, $values, $errors, null); ?></div>
                <?php else: ?>
                    <p class="muted">A temporary HRMS login will be emailed to the address entered above.</p>
                <?php endif; ?>
            </div>
            <div class="modal-foot">
                <a class="btn" data-close-employee data-employee-cancel href="<?= h(page_url($page, $query)) ?>">Cancel</a>
                <a class="btn primary" data-close-employee data-employee-unchanged href="<?= h(page_url($page, $query)) ?>" hidden>Close</a>
                <button class="btn primary employee-save" type="submit">
                    <span class="employee-save-ready">Save employee</span>
                    <span class="employee-save-pending"><span class="employee-save-spinner" aria-hidden="true"></span> Saving…</span>
                </button>
            </div>
        </form>
    </dialog>
    <script src="<?= h(versioned_asset('assets/js/employee-location.js')) ?>" defer></script>
    <script src="<?= h(versioned_asset('assets/js/employee-dialog.js')) ?>" defer></script>
    <?php
}

