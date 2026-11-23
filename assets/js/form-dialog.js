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
    finishDialog(() => {});
  }

  dialog.addEventListener('cancel', cancel);
  function openFormDialog(trigger, feedback) {
    const disclosure = trigger.parentElement;
    const original = disclosure.querySelector('form');
    if (!original) return;
    disclosure.open = false;

    const form = original.cloneNode(true);
    form.className = '';
    const submit = form.querySelector('button[type="submit"]');
    const isConfirmation = disclosure.dataset.formDialog === 'confirm';
    dialog.classList.toggle('wide', !isConfirmation);
    if (!isConfirmation) {
      const marker = document.createElement('input');
      marker.type = 'hidden';
      marker.name = '_shared_form';
      marker.value = form.elements.namedItem('_action').value;
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
    form.addEventListener('submit', (submitEvent) => {
      submitEvent.preventDefault();
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
