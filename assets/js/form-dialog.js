if (typeof HTMLDialogElement !== 'undefined' && typeof HTMLDialogElement.prototype.showModal === 'function') {
  const dialog = document.createElement('dialog');
  dialog.className = 'modal sign-out-dialog shared-form-dialog';
  dialog.setAttribute('aria-labelledby', 'shared-form-title');
  document.body.append(dialog);

  async function finishDialog(afterClose) {
    if (dialog.hasAttribute('data-closing')) return;
    dialog.setAttribute('data-closing', '');
    if (typeof dialog.getAnimations === 'function') {
      await Promise.allSettled(dialog.getAnimations().map((animation) => animation.finished));
    }
    dialog.close();
    dialog.removeAttribute('data-closing');
    afterClose();
  }

  function cancel(event) {
    event.preventDefault();
    finishDialog(() => {
      if (dialog.querySelector('[data-password-toggle]')) dialog.replaceChildren();
    });
  }

  dialog.addEventListener('cancel', cancel);

  function showFieldError(field, message) {
    const wrapper = field.closest('.field');
    let error = wrapper.querySelector('.field-error');
    if (message) {
      if (!error) {
        error = document.createElement('span');
        error.className = 'field-error';
        error.id = `dialog-error-${field.name}`;
        error.setAttribute('aria-live', 'polite');
        wrapper.append(error);
      }
      if (error.textContent !== message) error.textContent = message;
      field.setAttribute('aria-invalid', 'true');
      field.setAttribute('aria-describedby', error.id);
    } else {
      error?.remove();
      field.removeAttribute('aria-invalid');
      field.removeAttribute('aria-describedby');
    }
    return !message;
  }

  function eventValidation(form) {
    form.noValidate = true;
    const fields = Array.from(form.querySelectorAll('.field input'));
    const start = form.elements.namedItem('start');
    const end = form.elements.namedItem('end');
    const limits = { title: 160, detail: 255 };

    function validate(field) {
      const label = field.closest('.field').firstElementChild.textContent.trim();
      const value = field.value.trim();
      const limit = limits[field.name];
      let message = '';
      if (field.validity.badInput) {
        message = `Enter a valid ${field.type}.`;
      } else if (field.required && !value) {
        message = `${label} is required.`;
      } else if (limit && Array.from(value).length > limit) {
        message = `${label} must be ${limit} characters or fewer.`;
      } else if (field === end && start.value && value && end.valueAsNumber <= start.valueAsNumber) {
        message = 'End time must be after start time.';
      }
      return showFieldError(field, message);
    }

    for (const field of fields) {
      field.addEventListener('input', () => {
        if (field.hasAttribute('aria-invalid')) validate(field);
        if (field === start && end.hasAttribute('aria-invalid')) validate(end);
      });
    }
    return () => {
      const invalid = fields.filter((field) => !validate(field));
      invalid[0]?.focus();
      return invalid.length === 0;
    };
  }

  function accountValidation(form) {
    form.noValidate = true;
    const fields = Array.from(form.querySelectorAll('.field input'));
    const newPassword = form.elements.namedItem('newPassword');
    const confirmation = form.elements.namedItem('confirmPassword');
    const encoder = new TextEncoder();

    function validate(field) {
      const wrapper = field.closest('.field');
      const label = wrapper.firstElementChild.textContent.trim();
      const value = field.value;
      let message = '';
      if (field === newPassword && Array.from(value).length < 8) {
        message = 'Use at least 8 characters for the new password.';
      } else if (field === newPassword && (encoder.encode(value).length > 72 || value.includes(String.fromCharCode(0)))) {
        message = 'Use a password of up to 72 bytes without null characters.';
      } else if (field === confirmation && !value) {
        message = 'Confirm your new password.';
      } else if (field === confirmation && value !== newPassword.value) {
        message = 'The new passwords must match.';
      } else if (field === newPassword || field === confirmation || field.name === 'currentPassword') {
        if (!value) message = `${label} is required.`;
      } else if (!value.trim()) {
        message = `${label} is required.`;
      } else if (Array.from(value.trim()).length > 120) {
        message = `${label} must be 120 characters or fewer.`;
      }

      return showFieldError(field, message);
    }

    for (const field of fields) {
      field.addEventListener('input', () => {
        validate(field);
        if (field === newPassword && confirmation && (confirmation.value || confirmation.hasAttribute('aria-invalid'))) {
          validate(confirmation);
        }
      });
      field.addEventListener('blur', () => {
        if (!field.hasAttribute('aria-invalid')) validate(field);
      });

      const toggle = field.closest('.field').querySelector('[data-password-toggle]');
      if (!toggle) continue;
      const label = field.closest('.field').querySelector('label');
      field.id += '-dialog';
      label.htmlFor = field.id;
      toggle.hidden = false;
      toggle.setAttribute('aria-controls', field.id);
      const updateToggle = () => {
        toggle.textContent = field.type === 'password' ? 'Show' : 'Hide';
        toggle.setAttribute('aria-label', `${toggle.textContent} ${label.textContent.toLowerCase()}`);
      };
      updateToggle();
      toggle.addEventListener('click', () => {
        field.type = field.type === 'password' ? 'text' : 'password';
        updateToggle();
      });
    }

    return () => {
      const invalid = fields.filter((field) => !validate(field));
      invalid[0]?.focus();
      return invalid.length === 0;
    };
  }

  function openFormDialog(trigger, feedback) {
    const disclosure = trigger.parentElement;
    const original = disclosure.querySelector('form');
    if (!original) return;
    disclosure.open = false;

    const form = original.cloneNode(true);
    const action = form.elements.namedItem('_action').value;
    form.className = '';
    const submit = form.querySelector('button[type="submit"]');
    const isConfirmation = disclosure.dataset.formDialog === 'confirm';
    dialog.classList.toggle('wide', !isConfirmation);
    if (!isConfirmation) {
      const marker = document.createElement('input');
      marker.type = 'hidden';
      marker.name = '_shared_form';
      marker.value = action;
      form.append(marker);
    }

    const header = document.createElement('div');
    header.className = 'modal-head';
    const title = document.createElement('h2');
    title.id = 'shared-form-title';
    title.textContent = trigger.textContent.trim();
    header.append(title);

    const body = document.createElement('div');
    body.className = 'modal-body';
    const prompt = disclosure.querySelector(':scope > p');
    if (prompt) body.append(prompt.cloneNode(true));
    let invalidField;
    if (feedback) {
      for (const control of form.elements) {
        if (control.type === 'hidden' || !control.name) continue;
        if (control.type === 'checkbox') control.checked = Object.hasOwn(feedback.values, control.name);
        else if (Object.hasOwn(feedback.values, control.name)) control.value = feedback.values[control.name];
      }
      const error = document.createElement('p');
      error.textContent = feedback.message;
      invalidField = form.elements.namedItem(feedback.field ?? '');
      const label = invalidField?.closest('.field');
      if (label) {
        error.className = 'field-error';
        error.id = 'shared-form-field-error';
        invalidField.setAttribute('aria-invalid', 'true');
        invalidField.setAttribute('aria-describedby', error.id);
        label.append(error);
        invalidField.addEventListener('input', () => {
          invalidField.removeAttribute('aria-invalid');
          invalidField.removeAttribute('aria-describedby');
          error.remove();
        }, { once: true });
      } else {
        error.className = 'card web-message';
        error.setAttribute('role', 'alert');
        body.append(error);
      }
    }
    const fields = document.createElement('div');
    fields.className = 'form-grid';
    for (const child of Array.from(form.children)) {
      if (child !== submit && !child.matches('input[type="hidden"]')) fields.append(child);
    }
    if (fields.children.length) body.append(fields);

    const footer = document.createElement('div');
    footer.className = 'modal-foot';
    const cancelButton = document.createElement('button');
    cancelButton.className = 'btn';
    cancelButton.type = 'button';
    cancelButton.textContent = 'Cancel';
    cancelButton.autofocus = isConfirmation;
    cancelButton.addEventListener('click', cancel);
    if (isConfirmation) submit.className = 'btn danger';
    footer.append(cancelButton, submit);
    form.append(header, body, footer);
    let validate;
    if (action === 'event.save') validate = eventValidation(form);
    if (action === 'profile.save' || action === 'password.change') validate = accountValidation(form);
    form.addEventListener('submit', (submitEvent) => {
      submitEvent.preventDefault();
      if (validate && !validate()) return;
      finishDialog(() => form.submit());
    });
    dialog.replaceChildren(form);
    dialog.showModal();
    invalidField?.focus();
  }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-form-dialog] > summary');
    if (!trigger) return;
    event.preventDefault();
    openFormDialog(trigger);
  });

  const feedbackElement = document.getElementById('shared-form-feedback');
  if (feedbackElement) {
    const feedback = JSON.parse(feedbackElement.dataset.feedback);
    const target = Array.from(document.querySelectorAll('[data-form-dialog="form"]')).find((disclosure) =>
      disclosure.querySelector('input[name="_action"]')?.value === feedback.action);
    if (target) openFormDialog(target.querySelector('summary'), feedback);
  } else {
    const trigger = document.querySelector('[data-auto-open] > summary');
    if (trigger) openFormDialog(trigger);
  }
}
