// Special Order editing on the document itself (HR's Generate SO/RTA, and the employee's
// Request Special Order). Text typed in place ([data-name]) is copied into hidden fields when the
// form is sent; the RTA's FOR block follows the signatory typed on page 1; participants are added
// and removed in the list of official participants.
(function () {
  var multiLine = { soBody: true, footer: true };

  document.querySelectorAll('form[data-so-editor]').forEach(function (form) {
    // One-line fields: Enter doesn't add a line break.
    form.addEventListener('keydown', function (event) {
      var field = event.target.closest && event.target.closest('.so-edit[data-name]');
      if (field && event.key === 'Enter' && !multiLine[field.dataset.name]) event.preventDefault();
    });

    // The RTA's FOR block mirrors the signatory on page 1.
    form.addEventListener('input', function (event) {
      var field = event.target.closest && event.target.closest('.so-edit[data-name]');
      if (!field) return;
      form.querySelectorAll('[data-mirror="' + field.dataset.name + '"]').forEach(function (copy) { copy.textContent = field.textContent.trim(); });
    });

    // Participants: remove, and add from the list of employees.
    var table = form.querySelector('[data-participants] tbody');
    var picker = form.querySelector('[data-add-participant]');
    function syncPicker() {
      if (!picker || !table) return;
      var listed = {};
      table.querySelectorAll('tr[data-emp]').forEach(function (row) { listed[row.dataset.emp] = true; });
      Array.prototype.forEach.call(picker.options, function (option) { if (option.value) option.hidden = option.disabled = !!listed[option.value]; });
    }
    function select(kind, values, chosen) {
      var element = document.createElement('select');
      element.className = 'so-select';
      element.setAttribute('data-' + kind, '');
      values.forEach(function (value) { var option = new Option(value, value, false, value === chosen); element.add(option); });
      return element;
    }
    if (table) {
      table.addEventListener('click', function (event) {
        var remove = event.target.closest('.so-remove');
        if (!remove) return;
        remove.closest('tr').remove();
        syncPicker();
      });
    }
    if (picker && table) {
      picker.addEventListener('change', function () {
        var option = picker.selectedOptions[0];
        if (!option || !option.value) return;
        var row = document.createElement('tr');
        row.dataset.emp = option.value;
        var name = document.createElement('td');
        name.textContent = option.dataset.name;
        var remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'so-remove'; remove.textContent = '×';
        remove.title = 'Remove from this SO'; remove.setAttribute('aria-label', 'Remove ' + option.dataset.name);
        name.appendChild(remove);
        var office = document.createElement('td'); office.textContent = option.dataset.office;
        var title = document.createElement('td'); title.textContent = option.dataset.title;
        var acc = document.createElement('td'); acc.className = 'c'; acc.appendChild(select('acc', ['LIVE-OUT', 'LIVE-IN'], 'LIVE-OUT'));
        var tt = document.createElement('td'); tt.className = 'mid'; tt.appendChild(select('tt', ['INCLUSIVE', 'EXCLUSIVE'], 'INCLUSIVE'));
        [name, office, title, acc, tt].forEach(function (cell) { row.appendChild(cell); });
        // Keep the list alphabetical, as on the office's list.
        var after = Array.prototype.find.call(table.rows, function (existing) { return existing.cells[0].firstChild.textContent.localeCompare(option.dataset.name) > 0; });
        table.insertBefore(row, after || null);
        picker.value = '';
        syncPicker();
      });
      syncPicker();
    }

    form.addEventListener('submit', function () {
      form.querySelectorAll('.so-generated').forEach(function (input) { input.remove(); });
      function add(name, value) {
        var input = document.createElement('input');
        input.type = 'hidden'; input.className = 'so-generated'; input.name = name; input.value = value;
        form.appendChild(input);
      }
      form.querySelectorAll('.so-edit[data-name]').forEach(function (field) {
        if (field.dataset.name.slice(-2) === '[]') return; // routing cells, below
        // Multi-line fields keep their line breaks (innerText); one-line fields use textContent, which
        // keeps the letters as typed even where the page shows them in capitals.
        var text = (multiLine[field.dataset.name] ? field.innerText : field.textContent).replace(/\r/g, '').replace(/ /g, ' ');
        add(field.dataset.name, multiLine[field.dataset.name] ? text.replace(/\n{3,}/g, '\n\n').trim() : text.replace(/\s+/g, ' ').trim());
      });
      // Routing slip rows → "Designation | Initial" lines.
      var routing = [];
      form.querySelectorAll('[data-routing] tr').forEach(function (row) {
        var designation = row.querySelector('[data-name="routingDesignation[]"]');
        var initial = row.querySelector('[data-name="routingInitial[]"]');
        if (designation && designation.textContent.trim()) routing.push(designation.textContent.trim() + ' | ' + (initial ? initial.textContent.trim() : ''));
      });
      if (form.querySelector('[data-routing]')) add('routing', routing.join('\n'));
      if (table) {
        table.querySelectorAll('tr[data-emp]').forEach(function (row) {
          var id = row.dataset.emp;
          add('p[]', id);
          add('acc[' + id + ']', row.querySelector('[data-acc]').value);
          add('tt[' + id + ']', row.querySelector('[data-tt]').value);
        });
      }
    });
  });
}());
