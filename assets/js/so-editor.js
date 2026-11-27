// Special Order editing on the document itself (HR's Generate SO/RTA, and the employee's
// Request Special Order). Text typed in place ([data-name]) is copied into hidden fields when the
// form is sent; the RTA's FOR block follows the signatory typed on page 1; participants are added
// and removed in the list of official participants.
(function () {
  function emptyBlock(element) {
    return !!element && /^(DIV|P)$/.test(element.tagName) && !element.textContent.trim() && !element.querySelector('img,table,hr');
  }
  function currentBlock(field) {
    var selection = window.getSelection();
    if (!selection || !selection.rangeCount) return null;
    var node = selection.getRangeAt(0).startContainer;
    var element = node.nodeType === 1 ? node : node.parentElement;
    while (element && element !== field) {
      if (/^(DIV|P)$/.test(element.tagName) && element.parentElement === field) return element;
      element = element.parentElement;
    }
    return null;
  }
  function hasTooManyBlankLines(field) {
    var block = currentBlock(field);
    if (block && emptyBlock(block) && emptyBlock(block.previousElementSibling)) return true;
    var html = field.innerHTML.toLowerCase();
    var breaks = html.match(/(?:<br\s*\/?\s*>\s*)+$/);
    return !!(breaks && (breaks[0].match(/<br\b/g) || []).length >= 3);
  }

  document.querySelectorAll('form[data-so-editor]').forEach(function (form) {
    var lastEditor = null;
    form.addEventListener('focusin', function (event) {
      var field = event.target.closest && event.target.closest('.so-edit[data-name]');
      if (field) lastEditor = field;
    });
    // One-line fields: Enter doesn't add a line break.
    form.addEventListener('keydown', function (event) {
      var field = event.target.closest && event.target.closest('.so-edit[data-name]');
      if (!field || event.key !== 'Enter') return;
      if (!['soBody', 'memoBody', 'to'].includes(field.dataset.name) || hasTooManyBlankLines(field)) event.preventDefault();
    });

    var savedRange = null;
    document.addEventListener('selectionchange', function () {
      var selection = window.getSelection();
      if (selection && selection.rangeCount && form.contains(selection.getRangeAt(0).commonAncestorContainer)) savedRange = selection.getRangeAt(0).cloneRange();
    });
    function restoreEditorSelection() {
      if (lastEditor) lastEditor.focus();
      if (savedRange) {
        var selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(savedRange);
      }
    }
    function runCommand(command) {
      restoreEditorSelection();
      document.execCommand(command, false, null);
    }

    form.querySelectorAll('[data-so-action]').forEach(function (button) {
      button.addEventListener('mousedown', function (event) { event.preventDefault(); });
      button.addEventListener('click', function () {
        var command = button.dataset.soAction;
        if (['undo', 'redo', 'bold', 'italic', 'underline', 'insertUnorderedList', 'insertOrderedList', 'indent', 'outdent', 'removeFormat'].includes(command)) runCommand(command);
        else if (['insertLineBreak', 'insertParagraph'].includes(command) && lastEditor && ['soBody', 'memoBody', 'to'].includes(lastEditor.dataset.name)) {
          if (hasTooManyBlankLines(lastEditor)) return;
          runCommand(command);
        } else if (command === 'checklist') {
          restoreEditorSelection();
          document.execCommand('insertText', false, '☐ ');
        } else if (command === 'link') {
          var url = window.prompt('Enter a link URL (https:// or mailto:)');
          if (!url) return;
          url = url.trim();
          if (!/^(https?:\/\/|mailto:)[^\s]+$/i.test(url)) { window.alert('Enter a valid https:// or mailto: URL.'); return; }
          restoreEditorSelection();
          var selection = window.getSelection();
          if (selection && !selection.isCollapsed) document.execCommand('createLink', false, url);
          else {
            var label = window.prompt('Link text', url) || url;
            var anchor = document.createElement('a'); anchor.href = url; anchor.textContent = label;
            if (selection && selection.rangeCount) { var range = selection.getRangeAt(0); range.deleteContents(); range.insertNode(anchor); range.setStartAfter(anchor); range.collapse(true); selection.removeAllRanges(); selection.addRange(range); }
          }
        } else if (command === 'image') {
          if (!lastEditor || lastEditor.dataset.name !== 'soBody') { window.alert('Place the cursor in the Special Order text to insert an image.'); return; }
          var imageInput = form.querySelector('[data-so-image]'); if (imageInput) imageInput.click();
        }
      });
    });

    form.querySelectorAll('[data-so-color]').forEach(function (picker) {
      picker.addEventListener('input', function () { restoreEditorSelection(); document.execCommand(picker.dataset.soColor, false, picker.value); });
    });
    var alignPicker = form.querySelector('[data-so-align]');
    if (alignPicker) alignPicker.addEventListener('change', function () { runCommand(alignPicker.value); });
    var spacingPicker = form.querySelector('[data-so-line-height]');
    if (spacingPicker) spacingPicker.addEventListener('change', function () {
      if (!lastEditor || !['soBody', 'memoBody'].includes(lastEditor.dataset.name)) return;
      restoreEditorSelection();
      document.execCommand('formatBlock', false, 'p');
      var block = currentBlock(lastEditor);
      if (block) block.style.lineHeight = spacingPicker.value;
      else lastEditor.style.lineHeight = spacingPicker.value;
    });
    var imageInput = form.querySelector('[data-so-image]');
    if (imageInput) imageInput.addEventListener('change', function () {
      var file = imageInput.files && imageInput.files[0];
      imageInput.value = '';
      if (!file) return;
      if (!/^image\/(png|jpeg|webp)$/.test(file.type) || file.size > 16384) { window.alert('Choose a PNG, JPG or WebP image up to 16 KB.'); return; }
      var reader = new FileReader();
      reader.onload = function () { restoreEditorSelection(); document.execCommand('insertImage', false, reader.result); };
      reader.readAsDataURL(file);
    });

    form.querySelectorAll('[data-so-print]').forEach(function (button) {
      button.addEventListener('click', function () {
        var overflowing = Array.prototype.some.call(form.querySelectorAll('.so-sheet:not(.participants-sheet)'), function (sheet) {
          return sheet.scrollHeight > sheet.clientHeight + 2;
        });
        if (overflowing) {
          window.alert('Some text extends beyond the A4 page. Reduce the text or paragraph spacing before printing.');
          return;
        }
        window.print();
      });
    });

    // The RTA's FOR block mirrors the signatory on page 1.
    form.addEventListener('input', function (event) {
      var field = event.target.closest && event.target.closest('.so-edit[data-name]');
      if (!field) return;
      form.querySelectorAll('[data-mirror="' + field.dataset.name + '"]').forEach(function (copy) { copy.textContent = field.textContent.trim(); });
      if (field.dataset.name === 'to') {
        field.dataset.autoRecipient = '0';
        var manual = form.querySelector('input[name="toManual"]');
        if (manual) manual.value = '1';
      }
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
    function syncRecipients() {
      var field = form.querySelector('[data-name="to"][data-auto-recipient="1"]');
      if (!field || !table) return;
      var rows = Array.prototype.slice.call(table.querySelectorAll('tr[data-emp]'));
      if (rows.length > 3) {
        field.textContent = 'ALL CONCERNED PSA MISAMIS ORIENTAL OFFICIALS AND PERSONNEL';
        return;
      }
      field.textContent = rows.map(function (row) {
        var name = row.cells[0].firstChild.textContent.trim();
        var names = name.split(',');
        if (names.length > 1) name = names[1].trim() + ' ' + names[0].trim();
        return name.toUpperCase() + '\n' + row.cells[2].textContent.trim();
      }).join('\n\n');
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
        syncRecipients();
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
        syncRecipients();
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
      if (form.hasAttribute('data-so-requester')) {
        ['allowances', 'meals', 'incidental', 'lodging', 'expenses', 'terminal', 'fuel'].forEach(function (name) {
          if (!form.querySelector('input[name="' + name + '"]:checked')) add(name, '0');
        });
      }
      form.querySelectorAll('.so-edit[data-name]').forEach(function (field) {
        if (field.dataset.name.slice(-2) === '[]') return; // routing cells, below
        // Preserve supported rich formatting; the server validates tags, links, images and styles.
        var html = field.innerHTML.replace(/ /g, ' ').trim();
        if (field.style.lineHeight && !/<\/?(?:p|div)\b/i.test(html)) html = '<div style="line-height:' + field.style.lineHeight + '">' + html + '</div>';
        if (!/<\/?(?:b|strong|i|em|u|br|p|div|span|ul|ol|li|a|img|font|blockquote)\b/i.test(html)) html = '<span>' + html + '</span>';
        add(field.dataset.name, html);
      });
      // Routing slip rows → "Designation | Initial" lines.
      var routing = [];
      form.querySelectorAll('[data-routing] tr').forEach(function (row) {
        var designation = row.querySelector('[data-name="routingDesignation[]"]');
        var initial = row.querySelector('[data-name="routingInitial[]"]');
        if (designation && designation.textContent.trim()) routing.push(designation.innerHTML.trim() + ' | ' + (initial ? initial.innerHTML.trim() : ''));
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
