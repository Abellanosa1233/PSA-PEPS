/* Supervisor workspace (a manager's view of their team) and
   Employee workspace (self-service view for one person). */
window.HR = window.HR || {};
HR.pages = HR.pages || {};
(function (HR) {
  'use strict';
  var ui = HR.ui, D = HR.D, ic = HR.icon;

  HR.pages.supervisor = {
    title: 'Supervisor workspace',
    render: function (el) {
      var data = HR.store.data, ti = D.todayIso();
      var managers = HR.q.managers();
      var mgr = HR.q.emp(data.settings.supervisorId);
      if (!mgr || managers.indexOf(mgr) < 0) mgr = managers[0];
      if (!mgr) { el.innerHTML = '<div class="page-head"><div><h1>Supervisor workspace</h1></div></div><section class="card">' + ui.empty('No managers yet', 'Set "Reports to" on employee records to build teams.') + '</section>'; el.onclick = null; return; }
      var team = HR.q.reports(mgr.id);
      var ids = team.map(function (e) { return e.id; });
      var pending = data.leaves.filter(function (l) { return l.status === 'pending' && ids.indexOf(l.empId) >= 0; });
      var upcoming = data.leaves.filter(function (l) { return l.status === 'approved' && l.end >= ti && ids.indexOf(l.empId) >= 0; }).sort(function (a, b) { return a.start < b.start ? -1 : 1; });
      var here = team.filter(function (e) { var s = HR.q.statusOn(e.id, ti); return s === 'office' || s === 'remote'; }).length;

      el.innerHTML =
        '<div class="page-head"><div><h1>Supervisor workspace</h1><p>What a manager sees: their team today, approvals and who is out.</p></div>' +
        '<div class="actions"><label class="muted" for="mgr-pick">Viewing as</label><select class="input" style="width:auto" id="mgr-pick">' +
        ui.options(managers.map(function (m) { return { value: m.id, label: HR.q.name(m) + ' · ' + m.title }; }), mgr.id) + '</select></div></div>' +
        '<div class="grid kpis">' +
        '<div class="card kpi"><div class="kpi-top"><span>Team size</span>' + ic('users') + '</div><div class="kpi-value">' + team.length + '</div><div class="kpi-foot"><span>direct reports</span></div></div>' +
        '<div class="card kpi"><div class="kpi-top"><span>Working today</span>' + ic('clock') + '</div><div class="kpi-value">' + here + '</div><div class="kpi-foot"><span class="pill">' + (team.length ? ui.pct(here / team.length) : '0%') + '</span><span>of the team</span></div></div>' +
        '<div class="card kpi"><div class="kpi-top"><span>Awaiting approval</span>' + ic('calendar') + '</div><div class="kpi-value">' + pending.length + '</div><div class="kpi-foot"><span>time-off requests</span></div></div>' +
        '<div class="card kpi"><div class="kpi-top"><span>Out or booked</span>' + ic('briefcase') + '</div><div class="kpi-value">' + upcoming.length + '</div><div class="kpi-foot"><span>approved, today onwards</span></div></div></div>' +
        '<div class="grid cols-main">' +
        '<section class="card"><div class="card-head"><h3>Team today</h3></div><div class="table-wrap" style="margin-top:10px"><table class="data"><thead><tr><th>Name</th><th>Status</th><th>Clock in</th><th>Leave left</th></tr></thead><tbody>' +
        (team.length ? team.map(function (e) {
          var r = HR.q.record(e.id, ti) || {};
          return '<tr class="clickable" data-emp="' + e.id + '"><td>' + ui.who(e) + '</td><td>' + ui.attBadge(HR.q.statusOn(e.id, ti)) + '</td><td class="tabular">' + (r.in || '—') + '</td><td class="tabular">' + HR.q.balances(e.id)['Annual leave'].left + ' days</td></tr>';
        }).join('') : '<tr><td colspan="4">' + ui.empty('No direct reports', 'Assign people to this manager from their employee record.') + '</td></tr>') + '</tbody></table></div></section>' +
        '<div class="grid" style="align-content:start">' +
        '<section class="card"><div class="card-head"><h3>Approvals</h3></div><div class="card-body" style="display:flex;flex-direction:column;gap:12px">' +
        (pending.length ? pending.map(function (l) {
          return '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center">' + ui.who(HR.q.emp(l.empId), l.type + ' · ' + ui.range(l.start, l.end) + ' · ' + ui.days(l.days)) +
            '<span style="display:flex;gap:6px"><button class="sq approve" data-approve="' + l.id + '" aria-label="Approve">' + ic('check', 14) + '</button><button class="sq reject" data-reject="' + l.id + '" aria-label="Decline">' + ic('x', 14) + '</button></span></div>';
        }).join('') : '<p class="muted">Nothing waiting for this manager.</p>') + '</div></section>' +
        '<section class="card"><div class="card-head"><h3>Who\'s out</h3></div><div class="card-body" style="display:flex;flex-direction:column;gap:10px">' +
        (upcoming.length ? upcoming.map(function (l) { return ui.who(HR.q.emp(l.empId), l.type + ' · ' + ui.range(l.start, l.end)); }).join('') : '<p class="muted">The whole team is around.</p>') + '</div></section></div></div>';

      el.oninput = null;
      el.onchange = function (e) { if (e.target.id === 'mgr-pick') ui.act('workspace.view', { supervisorId: Number(e.target.value) }); };
      el.onclick = function (e) {
        var t = e.target.closest('[data-approve],[data-reject],[data-emp]');
        if (!t) return;
        if (t.dataset.approve) return ui.act('leave.decide', { id: Number(t.dataset.approve), status: 'approved' }, 'Request approved');
        if (t.dataset.reject) return ui.act('leave.decide', { id: Number(t.dataset.reject), status: 'rejected' }, 'Request declined');
        location.hash = 'employee-' + t.dataset.emp;
      };
    }
  };

  HR.pages['self-service'] = {
    title: 'Employee workspace',
    render: function (el) {
      var data = HR.store.data, ti = D.todayIso();
      var me = HR.q.emp(data.settings.selfEmpId);
      if (!me || me.status !== 'Active') me = HR.q.active()[0];
      if (!me) { el.innerHTML = ui.empty('No employees yet', 'Add an employee first.'); el.onclick = null; return; }
      var rec = HR.q.record(me.id, ti) || {};
      var lv = HR.q.leaveOn(me.id, ti);
      var bal = HR.q.balances(me.id);
      var mine = data.leaves.filter(function (l) { return l.empId === me.id; });
      var slips = data.payRuns.filter(function (r) { return r.status === 'paid'; }).map(function (r) {
        return { run: r, line: (r.lines || []).find(function (l) { return l.empId === me.id; }) };
      }).filter(function (x) { return x.line; }).reverse();
      var weekend = D.weekend(D.today());

      var clock;
      if (lv) clock = '<p>' + ui.badge('On leave · ' + lv.type, 'warn') + '</p><p class="muted">Enjoy your time off. You are back on ' + ui.dayLong(D.iso(D.add(D.parse(lv.end), 1))) + '.</p>';
      else if (!rec.in) clock = '<p class="muted">You haven\'t clocked in today.</p><div class="toolbar"><button class="btn primary" data-clock="in" data-st="office">' + ic('logIn') + 'Clock in at the office</button><button class="btn" data-clock="in" data-st="remote">Clock in remotely</button></div>';
      else if (!rec.out) clock = '<p>' + ui.attBadge(rec.status) + ' <span class="muted">since ' + rec.in + '</span></p><div class="toolbar"><button class="btn primary" data-clock="out">' + ic('logOut') + 'Clock out</button></div>';
      else clock = '<p>' + ui.attBadge(rec.status) + ' <span class="muted">' + rec.in + ' – ' + rec.out + '</span></p><p class="muted">You\'re done for today.</p>';

      el.innerHTML =
        '<div class="page-head"><div><h1>Hi, ' + ui.esc(me.firstName) + '</h1><p>Your attendance, time off and payslips in one place.</p></div>' +
        '<div class="actions"><label class="muted" for="me-pick">Viewing as</label><select class="input" style="width:auto" id="me-pick">' + ui.empOptions(me.id) + '</select>' +
        '<button class="btn primary" data-act="leave">' + ic('calendar') + 'Request time off</button></div></div>' +
        '<div class="grid cols-3">' +
        '<section class="card"><div class="card-head"><div><h3>Today</h3><p>' + ui.esc(ui.dayLong(ti)) + (weekend ? ' · weekend' : '') + '</p></div>' + ic('clock') + '</div><div class="card-body" style="display:flex;flex-direction:column;gap:12px">' + clock + '</div></section>' +
        '<section class="card" style="grid-column:span 2"><div class="card-head"><h3>Leave balance</h3></div><div class="card-body grid" style="grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:16px">' +
        ['Annual leave', 'Personal leave', 'Sick leave'].map(function (t) {
          var b = bal[t];
          return '<div><span class="muted">' + t + '</span><div class="kpi-value" style="margin:4px 0">' + b.left + '<span class="muted" style="font-size:13px;font-weight:500"> / ' + b.allowance + ' days</span></div><div class="meter"><span style="width:' + (b.allowance ? Math.min(100, (b.used + b.pending) / b.allowance * 100) : 0) + '%"></span></div></div>';
        }).join('') + '</div></section></div>' +
        '<div class="grid cols-even">' +
        '<section class="card"><div class="card-head"><h3>My requests</h3></div>' + (mine.length ? '<div class="table-wrap" style="margin-top:10px"><table class="data"><tbody>' + mine.map(function (l) {
          return '<tr><td>' + ui.esc(l.type) + '<span class="sub">' + ui.range(l.start, l.end) + ' · ' + ui.days(l.days) + '</span></td><td>' + ui.leaveBadge(l.status) + '</td><td class="actions">' +
            (l.status === 'pending' || (l.status === 'approved' && l.start > ti) ? '<button class="btn sm ghost" data-cancel="' + l.id + '">Cancel</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table></div>' : ui.empty('No requests yet', 'Your time-off requests will show here.')) + '</section>' +
        '<section class="card"><div class="card-head"><h3>Payslips</h3></div>' + (slips.length ? '<div class="table-wrap" style="margin-top:10px"><table class="data"><tbody>' + slips.map(function (s) {
          return '<tr><td><strong style="font-weight:600">' + ui.month(s.run.period) + '</strong><span class="sub">Paid ' + ui.dateY((s.run.paidAt || '').slice(0, 10)) + '</span></td><td class="num">' + ui.money2(s.line.net) + '<span class="sub">net</span></td>' +
            '<td class="actions"><button class="btn sm" data-slip="' + s.run.id + '">' + ic('file', 14) + 'View</button></td></tr>';
        }).join('') + '</tbody></table></div>' : ui.empty('No payslips yet', 'Payslips appear after a pay run is approved.')) + '</section></div>';

      el.oninput = null;
      el.onchange = function (e) { if (e.target.id === 'me-pick') ui.act('workspace.view', { selfEmpId: Number(e.target.value) }); };
      el.onclick = function (e) {
        var t = e.target.closest('[data-act],[data-clock],[data-cancel],[data-slip]');
        if (!t) return;
        if (t.dataset.act === 'leave') HR.forms.leave(me.id, true);
        if (t.dataset.clock) ui.act('attendance.clock', { empId: me.id, mode: t.dataset.clock, status: t.dataset.st }, t.dataset.clock === 'in' ? 'Clocked in' : 'Clocked out');
        if (t.dataset.cancel) ui.act('leave.cancel', { id: Number(t.dataset.cancel) }, 'Request cancelled');
        if (t.dataset.slip) {
          var s = slips.find(function (x) { return x.run.id === Number(t.dataset.slip); });
          var row = function (k, v, strong) { return '<dt' + (strong ? ' style="color:var(--fg);font-weight:600"' : '') + '>' + k + '</dt><dd class="tabular" style="text-align:right' + (strong ? ';font-weight:700' : '') + '">' + v + '</dd>'; };
          ui.modal({
            title: 'Payslip · ' + ui.month(s.run.period), subtitle: HR.q.name(me) + ' · ' + me.title + ' · ' + data.settings.company, cancelLabel: 'Close',
            body: '<dl class="dl" style="grid-template-columns:1fr auto">' + row('Base pay', ui.money2(s.line.base)) + row('Bonus', ui.money2(s.line.bonus)) + row('Gross pay', ui.money2(s.line.gross), true) +
              row('Withholding tax', '−' + ui.money2(s.line.tax)) + row('SSS, PhilHealth, Pag-IBIG', '−' + ui.money2(s.line.benefits)) + row('Net pay', ui.money2(s.line.net), true) + '</dl>' +
              '<p class="muted">Paid ' + ui.dateY((s.run.paidAt || '').slice(0, 10)) + '.</p>'
          });
        }
      };
    }
  };
})(window.HR);
