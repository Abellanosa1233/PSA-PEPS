const employeeDialog = document.getElementById('add-employee');

if (employeeDialog && typeof employeeDialog.showModal === 'function') {
  const form = employeeDialog.querySelector('form');
  const title = employeeDialog.querySelector('h2');
  const saveButton = form.querySelector('button[type="submit"]');
  const cancelButton = form.querySelector('[data-employee-cancel]');
  const closeButton = form.querySelector('[data-employee-unchanged]');
  const editableFields = ['firstName', 'lastName', 'email', 'phone', 'title', 'department', 'type', 'location', 'startDate', 'salary', 'managerId'];
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let original = form.dataset.originalEmployee ? JSON.parse(form.dataset.originalEmployee) : null;
  let submitting = false;
  let readyToSubmit = false;

  function syncEmployeeButtons() {
    const unchanged = original !== null && editableFields.every((name) => {
      const value = form.elements[name].value.trim();
      const saved = original[name] ?? '';
      return name === 'salary' && value !== '' ? Number(value) === Number(saved) : value === String(saved);
    });
    saveButton.hidden = unchanged;
    cancelButton.hidden = unchanged;
    closeButton.hidden = !unchanged;
  }

  function closeEmployeeDialog() {
    if (submitting || employeeDialog.hasAttribute('data-closing')) return;
    if (reducedMotion.matches || typeof employeeDialog.getAnimations !== 'function') {
      employeeDialog.close();
      return;
    }

    employeeDialog.setAttribute('data-closing', '');
    const animations = employeeDialog.getAnimations();
    Promise.allSettled(animations.map((animation) => animation.finished)).then(() => {
      employeeDialog.close();
      employeeDialog.removeAttribute('data-closing');
    });
  }

  syncEmployeeButtons();
  if (employeeDialog.hasAttribute('data-reopen')) {
    employeeDialog.close();
    employeeDialog.showModal();
  }

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-add-employee], [data-edit-employee]');
    if (!button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const employee = button.dataset.editEmployee ? JSON.parse(button.dataset.editEmployee) : {};
    const defaults = {
      startDate: form.querySelector('[data-new-employee-date]').value,
      salary: 0,
      department: form.elements.department.options[0].value,
      type: form.elements.type.options[0].value,
    };
    const manager = form.elements.managerId;
    for (const option of manager.options) {
      option.disabled = Boolean(employee.id && option.value === String(employee.id));
    }
    for (const name of ['id', ...editableFields]) {
      form.elements[name].value = employee[name] ?? defaults[name] ?? '';
    }
    original = employee.id ? employee : null;
    syncEmployeeButtons();
    title.textContent = employee.id ? 'Edit employee' : 'Add employee';
    const error = form.querySelector('[role="alert"]');
    if (error) error.remove();
    event.preventDefault();
    employeeDialog.showModal();
  });

  employeeDialog.querySelectorAll('[data-close-employee]').forEach((button) => {
    button.addEventListener('click', (event) => {
      event.preventDefault();
      closeEmployeeDialog();
    });
  });

  employeeDialog.addEventListener('cancel', (event) => {
    event.preventDefault();
    closeEmployeeDialog();
  });

  form.addEventListener('input', syncEmployeeButtons);
  form.addEventListener('change', syncEmployeeButtons);

  form.addEventListener('submit', (event) => {
    if (readyToSubmit) {
      readyToSubmit = false;
      return;
    }
    event.preventDefault();
    if (saveButton.hidden) {
      closeEmployeeDialog();
      return;
    }
    if (submitting || employeeDialog.hasAttribute('data-closing')) return;
    submitting = true;
    saveButton.disabled = true;
    saveButton.setAttribute('data-saving', '');
    form.setAttribute('aria-busy', 'true');
    window.setTimeout(() => {
      readyToSubmit = true;
      form.requestSubmit();
    }, reducedMotion.matches ? 0 : 140);
  });

  function resetSavingState() {
    submitting = false;
    readyToSubmit = false;
    saveButton.disabled = false;
    saveButton.removeAttribute('data-saving');
    form.removeAttribute('aria-busy');
  }

  form.addEventListener('invalid', () => {
    if (readyToSubmit) resetSavingState();
  }, true);

  window.addEventListener('pageshow', (event) => {
    if (event.persisted) resetSavingState();
  });
}
