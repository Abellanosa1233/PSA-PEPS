for (const field of document.querySelectorAll('[data-employee-position]')) {
  const input = field.querySelector('input');
  const list = field.querySelector('datalist');
  const type = input.form.elements.namedItem('type');
  const catalog = JSON.parse(field.dataset.employeePosition);

  function choices() {
    return catalog[type.value] ?? {};
  }

  function validate() {
    const value = input.value.trim();
    const valid = !value || Object.prototype.hasOwnProperty.call(choices(), value);
    input.setCustomValidity(valid ? '' : 'Choose a position listed for this employment type.');
  }

  function refreshOptions() {
    const options = Object.entries(choices()).map(([value, label]) => {
      const option = document.createElement('option');
      option.value = value;
      option.label = label;
      return option;
    });
    list.replaceChildren(...options);
    validate();
  }

  input.addEventListener('input', validate);
  input.addEventListener('change', validate);
  type.addEventListener('change', () => {
    if (!Object.prototype.hasOwnProperty.call(choices(), input.value.trim())) input.value = '';
    refreshOptions();
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
  refreshOptions();
}
