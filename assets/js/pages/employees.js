window.HR = window.HR || {};
HR.pages = HR.pages || {};
(function (HR) {
  'use strict';
  var ui = HR.ui, D = HR.D, ic = HR.icon;
  var f = { q: '', dept: '', status: 'Active', type: '' };

  function filtered() {
    var needle = f.q.trim().toLowerCase();
    return HR.store.data.employees.filter(function (e) {
      if (f.status && e.status !== f.status) return false;
      if (f.dept && e.department !== f.dept) return false;
      if (f.type && e.type !== f.type) return false;
      if (needle && (HR.q.name(e) + ' ' + e.email + ' ' + e.title).toLowerCase().indexOf(needle) < 0) return false;
      return true;
    });
  }

  function rows(list) {
    var ti = D.todayIso();
    if (!list.length) return '<tr><td colspan="7">' + ui.empty('No one matches these filters', 'Try a different name, department or status.') + '</td></tr>';
    return list.map(function (e) {
      var st = e.status === 'Offboarded' ? ui.badge('Offboarded', 'plain') : ui.attBadge(HR.q.statusOn(e.id, ti));
      return '<tr class="clickable" data-emp="' + e.id + '"><td>' + ui.who(e, e.email) + '</td><td>' + ui.esc(e.title) + '</td><td>' + ui.esc(e.department) + '</td>' +
        '<td>' + ui.esc(e.type) + '</td><td class="tabular">' + ui.dateY(e.startDate) + '</td><td>' + st + '</td>' +
        '<td class="actions"><button class="sq" data-edit="' + e.id + '" aria-label="Edit ' + ui.esc(HR.q.name(e)) + '" title="Edit">' + ic('edit', 14) + '</button>' +
        '<button class="sq reject" data-del="' + e.id + '" aria-label="Delete ' + ui.esc(HR.q.name(e)) + '" title="Delete">' + ic('trash', 14) + '</button></td></tr>';
    }).join('');
  }

  function confirmDelete(e) {
    ui.confirm({
      title: 'Delete ' + HR.q.name(e) + '?',
      message: 'This permanently removes their record, attendance and time-off history. Paid payslips are kept. To keep history, offboard them instead.',
      confirmLabel: 'Delete permanently', danger: true,
      onConfirm: function () {
        return HR.store.run('employee.delete', { id: e.id }).then(function () {
          ui.toast('Deleted ' + HR.q.name(e));
          if (location.hash.indexOf('employee-') === 1) location.hash = 'employees';
        });
      }
    });
  }

  HR.pages.employees = {
    title: 'Employees',
    render: function (el) {
      var data = HR.store.data;
      var list = filtered();
      el.innerHTML =
        '<div class="page-head"><div><h1>Employees</h1><p>' + ui.plural(HR.q.active().length, 'active employee') + ' across ' + HR.DEPARTMENTS.length + ' departments.</p></div>' +
        '<div class="actions"><button class="btn" data-act="export">' + ic('download') + 'Export CSV</button><button class="btn primary" data-act="add">' + ic('plus') + 'Add employee</button></div></div>' +
        '<section class="card"><div class="card-body toolbar" style="border-bottom:1px solid var(--line)">' +
        '<input class="input grow" type="search" id="emp-q" placeholder="Search by name, email or title" value="' + ui.esc(f.q) + '" aria-label="Search employees">' +
        '<select class="input" id="emp-dept" aria-label="Department">' + ui.options(HR.DEPARTMENTS, f.dept, 'All departments') + '</select>' +
        '<select class="input" id="emp-type" aria-label="Employment type">' + ui.options(HR.EMP_TYPES, f.type, 'All types') + '</select>' +
        '<select class="input" id="emp-status" aria-label="Status">' + ui.options([{ value: 'Active', label: 'Active' }, { value: 'Offboarded', label: 'Offboarded' }], f.status, 'Everyone') + '</select>' +
        '<span class="muted" id="emp-count" style="margin-left:auto">' + ui.plural(list.length, 'person', 'people') + '</span></div>' +
        '<div class="table-wrap"><table class="data"><thead><tr><th>Name</th><th>Job title</th><th>Department</th><th>Type</th><th>Start date</th><th>Today</th><th class="num">Actions</th></tr></thead>' +
        '<tbody id="emp-rows">' + rows(list) + '</tbody></table></div></section>';

      el.oninput = function (e) {
        if (e.target.id === 'emp-q') {
          f.q = e.target.value;
          var l = filtered();
          el.querySelector('#emp-rows').innerHTML = rows(l);
          el.querySelector('#emp-count').textContent = ui.plural(l.length, 'person', 'people');
        }
      };
      el.onchange = function (e) {
        var map = { 'emp-dept': 'dept', 'emp-type': 'type', 'emp-status': 'status' };
        if (map[e.target.id]) { f[map[e.target.id]] = e.target.value; HR.render(); }
      };
      el.onclick = function (e) {
        var t = e.target.closest('[data-act],[data-edit],[data-del],[data-emp]');
        if (!t) return;
        if (t.dataset.edit) return HR.forms.employee(HR.q.emp(t.dataset.edit));
        if (t.dataset.del) return confirmDelete(HR.q.emp(t.dataset.del));
        if (t.dataset.emp) { location.hash = 'employee-' + t.dataset.emp; return; }
        if (t.dataset.act === 'add') HR.forms.employee();
        if (t.dataset.act === 'export') {
          ui.csv('employees-' + D.todayIso() + '.csv', [['ID', 'First name', 'Last name', 'Email', 'Phone', 'Job title', 'Department', 'Type', 'Location', 'Start date', 'End date', 'Annual salary', 'Manager', 'Status']].concat(
            filtered().map(function (x) { return [x.id, x.firstName, x.lastName, x.email, x.phone, x.title, x.department, x.type, x.location, x.startDate, x.endDate || '', x.salary, x.managerId ? HR.q.name(HR.q.emp(x.managerId)) : '', x.status]; })));
          ui.toast('Exported ' + ui.plural(filtered().length, 'employee'));
        }
      };
    }
  };

  /* ---------- Profile ---------- */
  HR.pages.employee = {
    title: 'Employee',
    parent: 'employees',
    render: function (el, id) {
      var e = HR.q.emp(id);
      if (!e) { el.innerHTML = ui.empty('This employee was not found', 'They may have been deleted.', '<a class="btn" href="#employees">Back to employees</a>'); el.onclick = null; return; }
      var data = HR.store.data, ti = D.todayIso();
      var mgr = e.managerId ? HR.q.emp(e.managerId) : null;
      var reports = HR.q.reports(e.id);
      var bal = HR.q.balances(e.id);
      var leaves = data.leaves.filter(function (l) { return l.empId === e.id; });
      var strip = [];
      for (var d = D.today(), n = 0; n < 15; d = D.add(d, -1)) {
        if (D.weekend(d)) continue;
        var di = D.iso(d), s = e.startDate > di ? null : HR.q.statusOn(e.id, di);
        strip.unshift('<span class="' + (s || '') + '" title="' + ui.esc(ui.dayLong(di) + ': ' + ui.attLabel[s || 'none']) + '">' + ui.dayShort(di)[0] + '</span>');
        n++;
      }
      var today = HR.q.statusOn(e.id, ti);
      el.innerHTML =
        '<a class="back" href="#employees">' + ic('arrowLeft', 14) + 'All employees</a>' +
        '<div class="page-head"><div class="profile-head">' + ui.avatar(e, 'lg') + '<div><h1>' + ui.esc(HR.q.name(e)) + '</h1><p>' + ui.esc(e.title + ' · ' + e.department) + '</p>' +
        '<div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">' + (e.status === 'Active' ? ui.badge('Active', 'ok') + ui.attBadge(today) : ui.badge('Offboarded ' + ui.dateY(e.endDate), 'plain')) + '</div></div></div>' +
        '<div class="actions">' + (e.status === 'Active'
          ? '<button class="btn" data-act="leave">' + ic('calendar') + 'Request time off</button><button class="btn" data-act="offboard">Offboard</button>'
          : '<button class="btn" data-act="reactivate">Reactivate</button>') +
        '<button class="btn" data-act="delete">' + ic('trash') + 'Delete</button>' +
        '<button class="btn primary" data-act="edit">' + ic('edit') + 'Edit details</button></div></div>' +

        '<div class="grid cols-main">' +
        '<section class="card"><div class="card-head"><h3>Details</h3></div><div class="card-body"><dl class="dl">' +
        '<dt>Work email</dt><dd>' + ui.esc(e.email) + '</dd><dt>Phone</dt><dd>' + ui.esc(e.phone || '—') + '</dd>' +
        '<dt>Location</dt><dd>' + ui.esc(e.location || '—') + '</dd><dt>Employment</dt><dd>' + ui.esc(e.type) + '</dd>' +
        '<dt>Start date</dt><dd>' + ui.dateY(e.startDate) + ' <span class="muted">· ' + ui.tenure(e.startDate) + '</span></dd>' +
        '<dt>Reports to</dt><dd>' + (mgr ? '<a class="link-btn" href="#employee-' + mgr.id + '">' + ui.esc(HR.q.name(mgr)) + '</a>' : '—') + '</dd>' +
        '<dt>Annual salary</dt><dd class="tabular">' + ui.money(e.salary) + ' <span class="muted">· ' + ui.money(e.salary / 12) + ' a month</span></dd>' +
        '<dt>Employee ID</dt><dd class="tabular">#' + e.id + '</dd>' +
        '</dl></div></section>' +
        '<section class="card"><div class="card-head"><div><h3>Leave balance</h3><p>' + D.today().getFullYear() + ' allowance</p></div></div><div class="card-body" style="padding-top:6px">' +
        ['Annual leave', 'Personal leave', 'Sick leave'].map(function (t) {
          var b = bal[t];
          return '<div class="bal"><div class="line"><strong style="font-weight:600">' + t + '</strong><span class="tabular">' + b.left + ' of ' + b.allowance + ' left</span></div>' +
            '<div class="meter"><span style="width:' + (b.allowance ? Math.min(100, Math.round((b.used + b.pending) / b.allowance * 100)) : 0) + '%"></span></div>' +
            '<span class="sub">' + b.used + ' used' + (b.pending ? ' · ' + b.pending + ' pending' : '') + '</span></div>';
        }).join('') + '</div></section></div>' +

        '<div class="grid cols-main">' +
        '<section class="card"><div class="card-head"><h3>Time-off history</h3></div>' +
        (leaves.length ? '<div class="table-wrap" style="margin-top:10px"><table class="data"><thead><tr><th>Type</th><th>Dates</th><th class="num">Days</th><th>Status</th><th></th></tr></thead><tbody>' +
          leaves.map(function (l) {
            var canCancel = l.status === 'pending' || (l.status === 'approved' && l.end >= ti);
            return '<tr><td>' + ui.esc(l.type) + (l.reason ? '<span class="sub">' + ui.esc(l.reason) + '</span>' : '') + '</td><td class="tabular">' + ui.range(l.start, l.end) + ', ' + l.start.slice(0, 4) + '</td><td class="num">' + l.days + '</td><td>' + ui.leaveBadge(l.status) + '</td>' +
              '<td class="actions">' + (canCancel ? '<button class="btn sm ghost" data-cancel="' + l.id + '">Cancel</button>' : '') + '</td></tr>';
          }).join('') + '</tbody></table></div>' : ui.empty('No time off yet', 'Requests will be listed here.')) + '</section>' +
        '<section class="card"><div class="card-head"><div><h3>Last 3 weeks</h3><p>Workdays, oldest first</p></div></div><div class="card-body">' +
        '<div class="att-strip">' + strip.join('') + '</div>' +
        '<div class="legend" style="padding:12px 0 0;flex-wrap:wrap"><span><i style="background:var(--bar-1)"></i>Office</span><span><i style="background:var(--bar-2)"></i>Remote</span><span><i style="background:var(--warn-bg);border:1px solid var(--warn)"></i>Leave</span><span><i style="background:var(--bad-bg);border:1px solid var(--bad)"></i>Away</span></div>' +
        (reports.length ? '<div class="side-label" style="padding:18px 0 8px">Direct reports · ' + reports.length + '</div>' + reports.map(function (r) {
          return '<a href="#employee-' + r.id + '" style="display:block;padding:6px 0">' + ui.who(r) + '</a>';
        }).join('') : '') +
        '</div></section></div>';

      el.oninput = el.onchange = null;
      el.onclick = function (ev) {
        var t = ev.target.closest('[data-act],[data-cancel]');
        if (!t) return;
        if (t.dataset.cancel) return ui.act('leave.cancel', { id: Number(t.dataset.cancel) }, 'Request cancelled');
        var a = t.dataset.act;
        if (a === 'edit') HR.forms.employee(e);
        if (a === 'leave') HR.forms.leave(e.id, true);
        if (a === 'delete') confirmDelete(e);
        if (a === 'reactivate') ui.act('employee.status', { id: e.id, status: 'Active' }, HR.q.name(e) + ' is active again');
        if (a === 'offboard') ui.modal({
          title: 'Offboard ' + HR.q.name(e) + '?',
          subtitle: 'Their history is kept. Pending requests are withdrawn and direct reports lose this manager.',
          submitLabel: 'Offboard', danger: true,
          body: ui.field('endDate', 'Last working day', D.todayIso(), 'date'),
          onSubmit: function (v) { return HR.store.run('employee.status', { id: e.id, status: 'Offboarded', endDate: v.endDate }).then(function () { ui.toast(HR.q.name(e) + ' was offboarded'); }); }
        });
      };
    }
  };
})(window.HR);
