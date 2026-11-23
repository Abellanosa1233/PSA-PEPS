/* Reusable dialogs used from several pages */
window.HR = window.HR || {};
(function (HR) {
  'use strict';
  var ui = HR.ui;
  HR.forms = {
    employee: function (e, prefill) {
      var v = Object.assign({ type: 'Full-time', startDate: HR.D.todayIso(), department: 'Design', location: '' }, prefill || {}, e || {});
      var mgrs = HR.q.active().filter(function (m) { return !e || m.id !== e.id; }).map(function (m) { return { value: m.id, label: HR.q.name(m) + ' · ' + m.title }; });
      ui.modal({
        title: e ? 'Edit ' + HR.q.name(e) : 'Add employee',
        subtitle: e ? 'Changes apply right away.' : 'Create a record for a new teammate.',
        wide: true,
        submitLabel: e ? 'Save changes' : 'Add employee',
        body: '<div class="form-grid">' +
          ui.field('firstName', 'First name', v.firstName, 'text', { extra: 'required autocomplete="off"' }) +
          ui.field('lastName', 'Last name', v.lastName, 'text', { extra: 'required autocomplete="off"' }) +
          ui.field('email', 'Work email', v.email, 'email', { extra: 'required autocomplete="off"' }) +
          ui.field('phone', 'Mobile or landline', v.phone, 'tel', { extra: 'placeholder="0917 123 4567" inputmode="tel"', hint: 'Philippine number. Saved as +63 917 123 4567.' }) +
          ui.field('title', 'Job title', v.title, 'text', { extra: 'required' }) +
          ui.select('department', 'Department', ui.options(HR.DEPARTMENTS, v.department)) +
          ui.select('type', 'Employment type', ui.options(HR.EMP_TYPES, v.type)) +
          ui.field('location', 'Location', v.location, 'text', { hint: 'City, e.g. Makati City, Cebu City, or "Remote"' }) +
          ui.field('startDate', 'Start date', v.startDate, 'date', { extra: 'required' }) +
          ui.field('salary', 'Annual salary (' + HR.store.data.settings.currency + ')', v.salary, 'number', { extra: 'min="0" step="500"' }) +
          ui.select('managerId', 'Reports to', ui.options(mgrs, v.managerId, 'No manager'), { full: true }) +
          '</div>',
        onSubmit: function (vals) {
          vals.id = e ? e.id : null;
          if (prefill && prefill.fromCandidate) vals.fromCandidate = prefill.fromCandidate;
          return HR.store.run('employee.save', vals).then(function (res) {
            ui.toast(e ? 'Saved changes to ' + vals.firstName + ' ' + vals.lastName : 'Added ' + vals.firstName + ' ' + vals.lastName);
            if (!e) location.hash = 'employee-' + res.id;
          });
        }
      });
    },

    leave: function (empId, lockEmp) {
      var t = HR.D.todayIso();
      ui.modal({
        title: 'New time-off request',
        subtitle: 'Weekends are not counted. Balances are checked when you submit.',
        submitLabel: 'Submit request',
        body: '<div class="form-grid">' +
          (lockEmp
            ? '<input type="hidden" name="empId" value="' + empId + '"><div class="field full"><label>Employee</label><div>' + ui.who(HR.q.emp(empId)) + '</div></div>'
            : ui.select('empId', 'Employee', ui.empOptions(empId, 'Choose an employee'), { full: true })) +
          ui.select('type', 'Leave type', ui.options(HR.LEAVE_TYPES, 'Annual leave'), { full: true }) +
          ui.field('start', 'First day', t, 'date') +
          ui.field('end', 'Last day', t, 'date') +
          ui.field('reason', 'Note (optional)', '', 'textarea', { full: true }) +
          (lockEmp ? '' : '<label class="check full"><input type="checkbox" name="approveNow"> Approve immediately</label>') +
          '<p class="hint full muted" id="leave-days"></p>' +
          '</div>',
        onOpen: function (form) {
          function upd() {
            var s = form.start.value, e = form.end.value, out = form.querySelector('#leave-days');
            if (!s || !e || e < s) { out.textContent = ''; return; }
            var n = HR.D.workdays(s, e);
            var bal = form.empId.value ? HR.q.balances(Number(form.empId.value))[form.type.value] : null;
            out.textContent = ui.days(n) + ' requested' + (bal ? ' · ' + bal.left + ' of ' + bal.allowance + ' days left this year' : '');
          }
          form.start.addEventListener('change', function () { if (form.end.value < form.start.value) form.end.value = form.start.value; upd(); });
          ['end', 'type', 'empId'].forEach(function (n) { form[n] && form[n].addEventListener('change', upd); });
          upd();
        },
        onSubmit: function (v) {
          if (!v.empId) throw new Error('Choose who the time off is for.');
          v.empId = Number(v.empId);
          return HR.store.run('leave.create', v).then(function () { ui.toast(v.approveNow ? 'Time off approved' : 'Request submitted for approval'); });
        }
      });
    },

    event: function (date) {
      ui.modal({
        title: 'Add to schedule',
        submitLabel: 'Add event',
        body: '<div class="form-grid">' +
          ui.field('title', 'Title', '', 'text', { full: true, extra: 'required' }) +
          ui.field('date', 'Date', date || HR.D.todayIso(), 'date', { full: true }) +
          ui.field('start', 'Starts', '10:00', 'time') +
          ui.field('end', 'Ends', '10:30', 'time') +
          ui.field('detail', 'Details', '', 'text', { full: true, hint: 'For example: "With Jamie Chen · Interview"' }) +
          '</div>',
        onSubmit: function (v) { return HR.store.run('event.save', v).then(function () { ui.toast('Added "' + v.title + '" to the schedule'); }); }
      });
    },

    calendar: function () {
      var t = HR.D.today();
      var days = [];
      for (var i = 0; i < 14; i++) days.push(HR.D.iso(HR.D.add(t, i)));
      var evs = HR.store.data.events;
      var body = days.map(function (d) {
        var list = evs.filter(function (e) { return e.date === d; });
        if (!list.length) return '';
        return '<div><div class="side-label" style="padding:6px 0">' + ui.esc(ui.dayLong(d)) + '</div>' + list.map(function (e) {
          return '<div class="who" style="justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--line)"><div><strong>' + ui.esc(e.title) + '</strong><small>' + ui.esc(e.start + '–' + e.end + (e.detail ? ' · ' + e.detail : '')) + '</small></div>' +
            '<button type="button" class="sq reject" data-del-ev="' + e.id + '" aria-label="Delete ' + ui.esc(e.title) + '">' + HR.icon('trash', 14) + '</button></div>';
        }).join('') + '</div>';
      }).join('') || ui.empty('Nothing scheduled', 'Nothing is booked for the next two weeks.');
      var m = ui.modal({
        title: 'Calendar', subtitle: 'The next two weeks', wide: true, cancelLabel: 'Close',
        body: body + '<div><button type="button" class="btn" data-new-ev>' + HR.icon('plus') + 'Add event</button></div>'
      });
      m.form.addEventListener('click', function (e) {
        var del = e.target.closest('[data-del-ev]');
        if (del) { ui.act('event.delete', { id: Number(del.dataset.delEv) }, 'Event removed').then(function () { m.close(); HR.forms.calendar(); }); }
        if (e.target.closest('[data-new-ev]')) { m.close(); HR.forms.event(); }
      });
    }
  };
})(window.HR);
