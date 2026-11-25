(() => {
  const form = document.querySelector('.employee-profile-details-card .web-fields');
  const dialog = document.getElementById('employee-change-confirmation');

  if (form && dialog && typeof dialog.showModal === 'function') {
    const closeButton = form.querySelector('[data-employee-profile-close]');
    const saveButton = form.querySelector('[data-employee-profile-save]');
    const summary = dialog.querySelector('[data-employee-change-summary]');
    const cancelButton = dialog.querySelector('[data-cancel-employee-changes]');
    const confirmButton = dialog.querySelector('[data-confirm-employee-changes]');
    const original = JSON.parse(saveButton.dataset.originalEmployee);
    const fields = ['firstName', 'lastName', 'email', 'phone', 'title', 'department', 'type', 'location', 'startDate', 'salary', 'managerId', 'biometricId', 'loginUsername', 'loginPassword'];
    let confirmed = false;

    const removeAccess = form.elements.namedItem('removeAccess');

    function fieldChanges() {
      const changes = fields.reduce((changes, name) => {
        const control = form.elements.namedItem(name);
        if (!control) return changes;
        const current = control.value.trim();
        const previous = original[name] == null ? '' : String(original[name]);
        const same = name === 'salary' && current !== ''
          ? Number(current) === Number(previous)
          : current === previous;

        if (!same) {
          const label = control.closest('.field').querySelector('span').textContent.trim();
          changes.push({ label, control, previous, current });
        }
        return changes;
      }, []);
      if (removeAccess?.checked) {
        changes.push({ label: 'Sign-in access', control: removeAccess, previous: 'Has a login', current: 'Removed' });
      }
      return changes;
    }

    function syncButtons() {
      const changed = fieldChanges().length > 0;
      closeButton.hidden = changed;
      saveButton.hidden = !changed;
    }

    function displayValue(control, value) {
      // Never show a password; just say whether a new one is being set.
      if (control.type === 'password') return value ? 'New temporary password set' : '—';
      if (control instanceof HTMLSelectElement) {
        const option = Array.from(control.options).find((item) => item.value === value);
        if (option) return option.textContent.trim();
      }
      return value || '—';
    }

    function renderChanges(changes) {
      const table = document.createElement('table');
      table.className = 'data';
      const head = table.createTHead().insertRow();
      ['Field', 'Before', 'After'].forEach((label) => {
        const cell = document.createElement('th');
        cell.scope = 'col';
        cell.textContent = label;
        head.append(cell);
      });

      const body = table.createTBody();
      changes.forEach(({ label, control, previous, current }) => {
        const row = body.insertRow();
        [label, displayValue(control, previous), displayValue(control, current)].forEach((value) => {
          row.insertCell().textContent = value;
        });
      });

      summary.replaceChildren(table);
    }

    form.addEventListener('input', syncButtons);
    form.addEventListener('change', syncButtons);
    syncButtons();

    form.addEventListener('submit', (event) => {
      if (confirmed) {
        confirmed = false;
        return;
      }

      event.preventDefault();
      const changes = fieldChanges();
      if (!changes.length) {
        window.location.assign(closeButton.href);
        return;
      }

      renderChanges(changes);
      dialog.showModal();
    });

    cancelButton.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault();
      dialog.close();
    });
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) dialog.close();
    });
    confirmButton.addEventListener('click', () => {
      confirmed = true;
      dialog.close();
      form.requestSubmit(saveButton);
    });
  }
})();
