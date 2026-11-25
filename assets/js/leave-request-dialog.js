(() => {
  const dialog = document.getElementById('leave-request-dialog');
  const openButton = document.querySelector('[data-open-leave-request]');
  const form = dialog?.querySelector('form');

  if (!dialog || !openButton || !form || typeof dialog.showModal !== 'function') return;

  openButton.addEventListener('click', () => {
    if (!dialog.open) dialog.showModal();
  });
  dialog.querySelectorAll('[data-close-leave-request]').forEach((button) => {
    button.addEventListener('click', () => dialog.close());
  });
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });

  const feedbackElement = document.getElementById('shared-form-feedback');
  if (!feedbackElement) return;

  const feedback = JSON.parse(feedbackElement.dataset.feedback);
  if (feedback.action !== 'leave.create') return;

  const values = feedback.values || {};
  ['empId', 'type', 'start', 'end', 'reason'].forEach((name) => {
    if (Object.hasOwn(values, name)) form.elements.namedItem(name).value = values[name];
  });
  const approveNow = form.elements.namedItem('approveNow');
  if (approveNow) approveNow.checked = Object.hasOwn(values, 'approveNow');

  const invalidField = feedback.field ? form.elements.namedItem(feedback.field) : null;
  const fieldContainer = invalidField?.closest('.field');
  if (fieldContainer) {
    const error = document.createElement('span');
    error.className = 'field-error';
    error.id = 'leave-request-field-error';
    error.setAttribute('aria-live', 'polite');
    error.textContent = feedback.message;
    fieldContainer.append(error);
    invalidField.setAttribute('aria-invalid', 'true');
    invalidField.setAttribute('aria-describedby', error.id);
  } else {
    const error = document.createElement('p');
    error.className = 'form-error';
    error.setAttribute('role', 'alert');
    error.textContent = feedback.message;
    dialog.querySelector('.modal-body').prepend(error);
  }

  dialog.showModal();
  invalidField?.focus();
})();
