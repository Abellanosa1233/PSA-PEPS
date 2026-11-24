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
          ui.field('salary', 'Annual salary (' + HR.store.data.settings.currency + ')', v.salary, 'number', { extra: 'min="0.01" max="10000000" step="any" required' }) +
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
      var selected = HR.D.parse(HR.D.todayIso());
      var evs = HR.store.data.events || [];

      function renderMonth() {
        var first = new Date(selected.getFullYear(), selected.getMonth(), 1);
        var lastDay = new Date(selected.getFullYear(), selected.getMonth() + 1, 0).getDate();
        var leading = (first.getDay() + 6) % 7;
        var cellCount = Math.ceil((leading + lastDay) / 7) * 7;
        var html = '<div class="schedule-calendar-toolbar">' +
          '<button type="button" class="icon-btn" data-cal-prev aria-label="Previous month">' + HR.icon('chevLeft', 16) + '</button>' +
          '<strong>' + ui.esc(first.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })) + '</strong>' +
          '<button type="button" class="icon-btn" data-cal-next aria-label="Next month">' + HR.icon('chevRight', 16) + '</button></div>' +
          '<div class="schedule-calendar-weekdays">' +
          ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(function (day) { return '<span>' + day + '</span>'; }).join('') +
          '</div><div class="schedule-calendar-grid">';
        for (var index = 0; index < cellCount; index++) {
          var day = index - leading + 1;
          if (day < 1 || day > lastDay) {
            html += '<div class="schedule-calendar-day is-outside" aria-hidden="true"></div>';
            continue;
          }
          var date = new Date(selected.getFullYear(), selected.getMonth(), day);
          var iso = HR.D.iso(date);
          var events = evs.filter(function (event) { return event.date === iso; }).sort(function (a, b) { return a.start.localeCompare(b.start); });
          var classes = 'schedule-calendar-day';
          if (iso === HR.D.todayIso()) classes += ' is-today';
          if (iso === HR.D.iso(selected)) classes += ' is-selected';
          html += '<button type="button" class="' + classes + '" data-cal-date="' + iso + '">' +
            '<span class="schedule-calendar-number">' + day + '</span>' +
            events.slice(0, 2).map(function (event) {
              return '<span class="schedule-calendar-event"><b>' + ui.esc(event.start) + '</b> ' + ui.esc(event.title) + '</span>';
            }).join('') +
            (events.length > 2 ? '<span class="schedule-calendar-more">+' + (events.length - 2) + ' more</span>' : '') +
            '</button>';
        }
        return html + '</div>';
      }

      var m = ui.modal({
        title: 'Schedule calendar',
        subtitle: 'Monthly schedule view',
        wide: true,
        cancelLabel: 'Close',
        body: renderMonth()
      });
      var body = m.form.querySelector('.modal-body');
      m.form.addEventListener('click', function (e) {
        if (e.target.closest('[data-cal-prev]')) {
          selected = new Date(selected.getFullYear(), selected.getMonth() - 1, 1);
          body.innerHTML = renderMonth();
          return;
        }
        if (e.target.closest('[data-cal-next]')) {
          selected = new Date(selected.getFullYear(), selected.getMonth() + 1, 1);
          body.innerHTML = renderMonth();
          return;
        }
        var day = e.target.closest('[data-cal-date]');
        if (!day) return;
        var date = day.dataset.calDate;
        var items = evs.filter(function (event) { return event.date === date; }).sort(function (a, b) { return a.start.localeCompare(b.start); });
        m.close();
        ui.modal({
          title: ui.dayLong(date),
          subtitle: 'Schedule details',
          cancelLabel: 'Close',
          noFooter: true,
          body: items.length ? '<div class="calendar-day-events">' + items.map(function (event) {
            return '<article class="calendar-day-event"><div class="calendar-day-event-time"><strong>' + ui.esc(event.start) + '</strong><span>' + ui.esc(event.end) + '</span></div><div class="calendar-day-event-info"><strong>' + ui.esc(event.title) + '</strong><span>' + ui.esc(event.detail || 'No additional details') + '</span></div></article>';
          }).join('') + '</div>' : '<div class="calendar-day-empty"><strong>No events scheduled</strong><p>This date is currently available.</p></div>'
        });
      });
    }
  };
})(window.HR);
