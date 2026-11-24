window.HR = window.HR || {};
HR.pages = HR.pages || {};
(function (HR) {
  'use strict';
  var ui = HR.ui, ic = HR.icon;

  function theme() { try { return localStorage.getItem('horizon-theme') || 'system'; } catch (e) { return 'system'; } }
  HR.applyTheme = function (t) {
    t = t || theme();
    if (t === 'system') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', t);
    try { localStorage.setItem('horizon-theme', t); } catch (e) { }
  };

  HR.pages.preferences = {
    title: 'Settings',
    render: function (el) {
      var s = HR.store.data.settings, u = HR.store.data.user;
      el.innerHTML =
        '<div class="page-head"><div><h1>Settings</h1><p>Company details, leave policy, payroll rules and your account.</p></div></div>' +
        '<div class="grid cols-even">' +
        '<form class="card" id="org-form" novalidate><div class="card-head"><div><h3>Company and policy</h3><p>Applies to everyone in this workspace.</p></div></div><div class="card-body form-grid">' +
        ui.field('company', 'Company name', s.company) + ui.field('workspace', 'Workspace name', s.workspace) +
        ui.select('currency', 'Currency', ui.options([{ value: 'PHP', label: 'PHP · Philippine peso (₱)' }, 'USD', 'EUR', 'GBP', 'SGD', 'AUD', 'CAD', 'JPY', 'INR'], s.currency)) +
        ui.field('taxRate', 'Withholding tax (%)', s.taxRate, 'number', { extra: 'min="0" max="60" step="0.5"' }) +
        ui.field('benefits', 'Contributions per month (SSS, PhilHealth, Pag-IBIG)', s.benefits, 'number', { extra: 'min="0" step="50"', full: true, hint: 'A flat employee share deducted from every payslip.' }) +
        '<div class="full side-label" style="padding:6px 0 0">Leave allowance, days per year</div>' +
        HR.LEAVE_TYPES.map(function (t) { return ui.field('allow_' + t, t, s.allowances[t], 'number', { extra: 'min="0" max="365"' }); }).join('') +
        '<div class="form-error full" hidden></div><div class="full"><button class="btn primary" type="submit">Save company settings</button></div></div></form>' +

        '<div class="grid" style="align-content:start">' +
        '<form class="card" id="me-form" novalidate><div class="card-head"><div><h3>Your account</h3><p>Signed in as ' + ui.esc(u.username) + '</p></div></div><div class="card-body form-grid">' +
        ui.field('name', 'Your name', u.name) + ui.field('role', 'Your role', u.role) +
        ui.field('currentPassword', 'Current password', '', 'password', { extra: 'autocomplete="current-password"' }) +
        ui.field('newPassword', 'New password', '', 'password', { extra: 'autocomplete="new-password"', hint: 'Leave blank to keep your password.' }) +
        '<div class="form-error full" hidden></div><div class="full"><button class="btn primary" type="submit">Save account</button></div></div></form>' +
        '<section class="card"><div class="card-head"><div><h3>Appearance</h3><p>Saved on this device.</p></div></div><div class="card-body toolbar">' +
        ['system', 'light', 'dark'].map(function (t) { return '<button class="chip-btn' + (theme() === t ? ' on' : '') + '" data-theme-set="' + t + '">' + t.charAt(0).toUpperCase() + t.slice(1) + '</button>'; }).join('') + '</div></section>' +
        '<section class="card"><div class="card-head"><div><h3>Demo data</h3><p>Replace all records with fresh sample data. Your login is kept.</p></div></div><div class="card-body"><button class="btn" data-act="reset">' + ic('refresh') + 'Reset demo data</button></div></section>' +
        '</div></div>';

      function bind(id, action, build, msg) {
        var form = el.querySelector('#' + id);
        form.addEventListener('submit', function (e) {
          e.preventDefault();
          var err = form.querySelector('.form-error');
          err.hidden = true;
          HR.store.run(action, build(ui.formValues(form))).then(function () { ui.toast(msg); }, function (x) { err.textContent = x.message; err.hidden = false; });
        });
      }
      bind('org-form', 'settings.save', function (v) {
        v.allowances = {};
        HR.LEAVE_TYPES.forEach(function (t) { v.allowances[t] = Number(v['allow_' + t]); });
        return v;
      }, 'Company settings saved');
      bind('me-form', 'profile.save', function (v) { return v; }, 'Account saved');

      el.oninput = el.onchange = null;
      el.onclick = function (e) {
        var t = e.target.closest('[data-theme-set],[data-act]');
        if (!t) return;
        if (t.dataset.themeSet) { HR.applyTheme(t.dataset.themeSet); if (HR.paintThemeBtn) HR.paintThemeBtn(); HR.render(); }
        if (t.dataset.act === 'reset') ui.confirm({
          title: 'Reset all data?',
          message: 'Every employee, request, attendance record, role and pay run is deleted and replaced with sample data. This cannot be undone.',
          confirmLabel: 'Delete and reset', danger: true,
          onConfirm: function () { return HR.store.run('demo.reset', {}).then(function () { ui.toast('Demo data restored'); location.hash = 'overview'; }); }
        });
      };
    }
  };

  HR.pages.help = {
    title: 'Help & resources',
    render: function (el) {
      var qa = [
        ['How do I add a new hire?', 'Use "Add employee" on the Overview or Employees page. Hired candidates in Recruitment have a "Create employee" button that fills in the role for you.'],
        ['How are leave days counted?', 'Only weekdays count. Each request is checked against the yearly allowance for its type, which you can change in Settings. Pending requests already count against the balance.'],
        ['What happens when I approve a pay run?', 'Payslips are calculated from current salaries, bonuses, the withholding tax rate and the SSS, PhilHealth and Pag-IBIG contributions, then saved and locked. The next month can be started from the Payroll page.'],
        ['How do employees clock in?', 'In the Employee workspace, pick the person and use Clock in or Clock out. HR can also edit any day on the Attendance page.'],
        ['Where is the data stored?', 'HRMS stores organization data in its configured MySQL database. Follow your agency backup procedures and verify backups regularly.'],
        ['I see "Cannot reach the server"', 'Start Apache and MySQL from the XAMPP Control Panel, then reload the page.']
      ];
      el.innerHTML = '<div class="page-head"><div><h1>Help & resources</h1><p>Guidance for using the Human Resource Management System.</p></div></div>' +
        '<section class="card">' + qa.map(function (x, i) {
          return '<div style="padding:16px;' + (i ? 'border-top:1px solid var(--line)' : '') + '"><h3 style="font-size:14px;margin-bottom:6px">' + x[0] + '</h3><p style="color:var(--fg-2);max-width:68ch">' + x[1] + '</p></div>';
        }).join('') + '</section>';
      el.onclick = el.oninput = el.onchange = null;
    }
  };
})(window.HR);
