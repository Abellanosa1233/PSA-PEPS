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
    card($hidden && !$candidate ? 'Employee details' : 'Add employee', $hidden && !$candidate ? 'employee-profile-details-card' : '');
    post_form('employee.save', $hidden);
    employee_fields($state, $employee, $hidden['id'] ?? null);
    if ($hidden && !$candidate) {
        echo '<a class="btn" data-employee-profile-close href="' . h(page_url('employees')) . '">Close</a>';
        echo '<button class="btn primary" type="submit" data-employee-profile-save data-original-employee="' . html_json($employee) . '" hidden>Save Changes</button></form>';
        ?>
        <dialog class="modal employee-change-confirmation" id="employee-change-confirmation" aria-labelledby="employee-change-title" aria-describedby="employee-change-description">
            <div class="modal-head"><h2 id="employee-change-title">Confirm changes</h2></div>
            <div class="modal-body">
                <p id="employee-change-description">Review the changes before saving.</p>
                <div class="table-wrap" data-employee-change-summary></div>
            </div>
            <div class="modal-foot">
                <button class="btn" type="button" data-cancel-employee-changes>Cancel</button>
                <button class="btn primary" type="button" data-confirm-employee-changes>Save Changes</button>
            </div>
        </dialog>
        <script src="<?= h(versioned_asset('assets/js/employee-profile-form.js')) ?>" defer></script>
        <?php
    } else {
        end_form('Save employee');
    }
    echo '</section>';
}

function employee_fields(array $state, array $employee, ?int $employeeId = null, array $errors = []): void
{
    field('firstName', 'First name', $employee['firstName'] ?? '', 'text', true, $errors['firstName'] ?? '');
    field('lastName', 'Last name', $employee['lastName'] ?? '', 'text', true, $errors['lastName'] ?? '');
    field('email', 'Email', $employee['email'] ?? '', 'email', true, $errors['email'] ?? '');
    field('phone', 'Phone', $employee['phone'] ?? '', 'tel', false, $errors['phone'] ?? '');
    field('title', 'Job title', $employee['title'] ?? '', 'text', true, $errors['title'] ?? '');
    choice('department', 'Department', options(DEPARTMENTS), $employee['department'] ?? '');
    choice('type', 'Employment type', options(EMP_TYPES), $employee['type'] ?? '');
    field('location', 'Location', $employee['location'] ?? '', 'text', false, $errors['location'] ?? '');
    field('startDate', 'Start date', $employee['startDate'] ?? $state['today'], 'date', true, $errors['startDate'] ?? '');
    field('salary', 'Annual salary', $employee['salary'] ?? '', 'number', true, $errors['salary'] ?? '');
    $managers = employee_options(array_filter($state['employees'], function ($e) use ($employeeId) { return $e['id'] !== $employeeId && $e['status'] === 'Active'; }
    ));
    choice('managerId', 'Manager', ['' => 'None'] + $managers, $employee['managerId'] ?? '');
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
    $values = $failed ? $formError['values'] : ($editing ?? []);
    $errorField = $formError['field'] ?? '';
    $errors = $failed && in_array($errorField, ['firstName', 'lastName', 'email', 'phone', 'title', 'location', 'startDate', 'salary'], true)
        ? [$errorField => $formError['message']] : [];
    ?>
    <dialog id="add-employee" class="modal wide employee-dialog" aria-labelledby="add-employee-title"<?= $open ? ' open data-reopen' : '' ?>>
        <div class="modal-head">
            <h2 id="add-employee-title"><?= $editing ? 'Edit employee' : 'Add employee' ?></h2>
            <a class="sq" data-close-employee href="<?= h(page_url($page, $query)) ?>" aria-label="Close"><?= icon('x', 14) ?></a>
        </div>
        <form method="post" action="<?= h(page_url($page, $query)) ?>"<?= $editing ? ' data-original-employee="' . html_json($editing) . '"' : '' ?>>
            <?php hidden_inputs(['_action' => 'employee.save', '_token' => $_SESSION['web_csrf'], '_employee_modal' => '1']); ?>
            <input type="hidden" name="id" value="<?= h($editing['id'] ?? '') ?>">
            <input type="hidden" data-new-employee-date value="<?= h($state['today']) ?>">
            <div class="modal-body">
                <?php if ($failed && !$errors) web_message($formError['message'], ''); ?>
                <div class="form-grid"><?php employee_fields($state, $values, null, $errors); ?></div>
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

