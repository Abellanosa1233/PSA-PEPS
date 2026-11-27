const employeeDialog = document.getElementById('add-employee');

if (employeeDialog && typeof employeeDialog.showModal === 'function') {
  const form = employeeDialog.querySelector('form');
  const title = employeeDialog.querySelector('h2');
  const saveButton = form.querySelector('button[type="submit"]');
  const cancelButton = form.querySelector('[data-employee-cancel]');
  const closeButton = form.querySelector('[data-employee-unchanged]');
  const editableFields = ['firstName', 'lastName', 'email', 'phone', 'title', 'department', 'type', 'location', 'startDate', 'salary', 'managerId', 'biometricId', 'loginUsername', 'loginPassword']
    .filter((name) => form.elements.namedItem(name));
  const lengthLimits = { firstName: 80, lastName: 80, email: 160, phone: 40, title: 120, location: 80, loginUsername: 60, loginPassword: 72 };
  const locationPicker = typeof employeeLocationPicker === 'function' ? employeeLocationPicker(form, syncEmployeeButtons) : null;
  const validatedFields = Array.from(form.querySelectorAll('.field:not([hidden]) input[name]'));
  const fieldErrors = new Map(validatedFields.map((field) => {
    const wrapper = field.closest('.field');
    let message = wrapper.querySelector('.field-error');
    if (!message) {
      message = document.createElement('span');
      message.className = 'field-error';
      message.id = `field-error-${field.name}`;
      wrapper.append(message);
    }
    message.setAttribute('aria-live', 'polite');
    message.hidden = !message.textContent;
    return [field, message];
  }));
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let original = form.dataset.originalEmployee ? JSON.parse(form.dataset.originalEmployee) : null;
  let submitting = false;
  let readyToSubmit = false;

  function syncEmployeeButtons() {
    const unchanged = original !== null && !locationPicker?.changing && !form.elements.removeAccess?.checked && editableFields.every((name) => {
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
    locationPicker?.reset();
    employeeDialog.close();
    employeeDialog.showModal();
    form.querySelector('.field:not([hidden]) [aria-invalid="true"]')?.focus();
  }

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-add-employee]');
    if (!button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (original !== null) return;
    const defaults = {
      startDate: form.querySelector('[data-new-employee-date]').value,
      type: form.elements.type.options[0].value,
    };
    for (const name of ['id', ...editableFields]) {
      form.elements[name].value = defaults[name] ?? '';
    }
    form.elements.type.dispatchEvent(new Event('change'));
    locationPicker?.reset();
    syncEmployeeButtons();
    title.textContent = 'Add employee';
    form.querySelector('[role="alert"]')?.remove();
    validatedFields.forEach((field) => setEmployeeError(field, ''));
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

  function setEmployeeError(field, message) {
    const error = fieldErrors.get(field);
    error.hidden = !message;
    if (error.textContent !== message) error.textContent = message;
    if (message) {
      field.setAttribute('aria-invalid', 'true');
      field.setAttribute('aria-describedby', error.id);
    } else {
      field.removeAttribute('aria-invalid');
      field.removeAttribute('aria-describedby');
    }
  }

  function validateEmployeeField(field) {
    const value = field.value.trim();
    const label = field.closest('.field').firstElementChild.textContent;
    const limit = lengthLimits[field.name];
    const email = field.name === 'email' && field.type === 'text' && !value.includes('@')
      ? value + '@gmail.com' : value;
    let message = '';

    if (field.validity.customError) {
      message = field.validationMessage;
    } else if (field.validity.badInput) {
      message = field.name === 'startDate' ? 'Enter a valid start date.' : 'Enter a valid annual salary.';
    } else if (field.required && !value) {
      message = `${label} is required.`;
    } else if (limit && Array.from(value).length > limit) {
      message = `${label} must be ${limit} characters or fewer.`;
    } else if (field.name === 'email' && (email.length > limit || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))) {
      message = 'Enter a valid email address, like name@company.com.';
    } else if (field.name === 'phone' && value &&
        !/^(?:(?:\+?63|0)?9\d{9}|(?:\+?63|0)[2-8]\d{8})$/.test(value.replace(/[\s().-]/g, ''))) {
      message = 'Enter a Philippine number, like 0917 123 4567 or (02) 8123 4567.';
    } else if (field.name === 'startDate' && !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      message = 'Enter a valid start date.';
    } else if (field.name === 'salary' && (Number(value) < 0 || Number(value) > 10000000)) {
      message = 'Annual salary must be between 0 and 10,000,000.';
    } else if (field.name === 'loginUsername' && value && !/^[A-Za-z0-9._-]+$/.test(value)) {
      message = 'Use letters, numbers, dots, dashes or underscores only.';
    } else if (field.name === 'loginPassword' && field.value && Array.from(field.value).length < 8) {
      message = 'Temporary password must be at least 8 characters.';
    }

    setEmployeeError(field, message);
    return !message;
  }

  form.addEventListener('input', (event) => {
    if (fieldErrors.has(event.target)) {
      validateEmployeeField(event.target);
    }
    syncEmployeeButtons();
  });
  form.addEventListener('focusout', (event) => {
    if (fieldErrors.has(event.target) && !event.target.hasAttribute('aria-invalid')) {
      validateEmployeeField(event.target);
    }
  });
  form.addEventListener('change', (event) => {
    if (fieldErrors.has(event.target)) validateEmployeeField(event.target);
    syncEmployeeButtons();
  });

  form.noValidate = true;
  function firstInvalidField() {
    const invalidFields = validatedFields.filter((field) => !validateEmployeeField(field));
    const invalidLocation = locationPicker?.validate();
    return invalidFields[0] ?? invalidLocation;
  }

  form.addEventListener('submit', (event) => {
    if (readyToSubmit) {
      readyToSubmit = false;
      const invalidField = firstInvalidField();
      if (invalidField) {
        event.preventDefault();
        resetSavingState();
        invalidField.focus();
      }
      return;
    }
    event.preventDefault();
    if (saveButton.hidden) {
      closeEmployeeDialog();
      return;
    }
    if (submitting || employeeDialog.hasAttribute('data-closing')) return;
    const invalidField = firstInvalidField();
    if (invalidField) {
      invalidField.focus();
      return;
    }
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

  window.addEventListener('pageshow', (event) => {
    if (event.persisted) resetSavingState();
  });
}
