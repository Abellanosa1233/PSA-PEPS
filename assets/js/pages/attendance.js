window.HR = window.HR || {};
HR.pages = HR.pages || {};
(function (HR) {
  'use strict';
  var ui = HR.ui, D = HR.D, ic = HR.icon;
  var day = null, dept = '', needle = '';

  function hours(r) {
    if (!r || !r.in || !r.out) return '';
    var m = D.mins(r.out) - D.mins(r.in);
    return m > 0 ? (Math.floor(m / 60) + 'h ' + String(m % 60).padStart(2, '0') + 'm') : '';
  }

  HR.pages.attendance = {
    title: 'Attendance',
    render: function (el) {
      var ti = D.todayIso();
      if (!day) day = ti;
      var s = HR.q.dayStats(day);
      var people = HR.q.employedOn(day).filter(function (e) {
        return (!dept || e.department === dept) && (!needle || HR.q.name(e).toLowerCase().indexOf(needle.toLowerCase()) >= 0);
      });
      var future = day > ti;
      var past = day < ti;
      var weekend = D.weekend(D.parse(day));
      var chip = function (k, label) { return '<span class="pill" style="height:26px;padding:0 10px">' + label + ' <strong style="margin-left:4px">' + s[k] + '</strong></span>'; };

      el.innerHTML =
        '<div class="page-head"><div><h1>Attendance</h1><p>Record where people worked and when they clocked in and out.</p></div>' +
        '<div class="actions"><button class="btn sq" style="width:34px;height:34px" data-shift="-1" aria-label="Previous day">' + ic('chevLeft') + '</button>' +
        '<input class="input" style="width:auto" type="date" id="att-day" value="' + day + '" aria-label="Day">' +
        '<button class="btn sq" style="width:34px;height:34px" data-shift="1" aria-label="Next day">' + ic('chevRight') + '</button>' +
        (day !== ti ? '<button class="btn" data-act="today">Today</button>' : '') +
        '<button class="btn" data-act="export">' + ic('download') + 'Export</button>' +
        (!future && !past ? '<button class="btn primary" data-act="fill">' + ic('check') + 'Mark unrecorded as in office</button>' : '') + '</div></div>' +

        '<div class="toolbar">' + chip('office', 'In office') + chip('remote', 'Remote') + chip('away', 'Away') + chip('leave', 'On leave') + chip('none', 'Not recorded') +
        '<span class="muted" style="margin-left:auto">' + ui.esc(ui.dayLong(day)) + (weekend ? ' · weekend' : '') + '</span></div>' +

        '<section class="card"><div class="card-body toolbar" style="border-bottom:1px solid var(--line)">' +
        '<input class="input grow" type="search" id="att-q" placeholder="Find a person" value="' + ui.esc(needle) + '" aria-label="Find a person">' +
        '<select class="input" id="att-dept" aria-label="Department">' + ui.options(HR.DEPARTMENTS, dept, 'All departments') + '</select></div>' +
        (future ? ui.empty('This day hasn\'t happened yet', 'Attendance can be recorded from the day itself onwards. Approved time off already shows on the overview.') :
        '<div class="table-wrap"><table class="data"><thead><tr><th>Employee</th><th>Status</th><th>Clock in</th><th>Clock out</th><th class="num">Hours</th></tr></thead><tbody>' +
        (people.length ? people.map(function (e) {
          var lv = HR.q.leaveOn(e.id, day);
          var r = HR.q.record(e.id, day) || {};
          var dis = lv || r.status === 'away' || !r.status ? ' disabled' : '';
          return '<tr data-row="' + e.id + '"><td>' + ui.who(e, e.department) + '</td>' +
            '<td>' + (lv ? ui.badge('On leave · ' + lv.type, 'warn') : past ? ui.esc(ui.attLabel[r.status || 'none']) :
              '<select class="input sm" style="width:140px" data-f="status" aria-label="Status for ' + ui.esc(HR.q.name(e)) + '">' +
              ui.options([{ value: 'office', label: 'In office' }, { value: 'remote', label: 'Remote' }, { value: 'away', label: 'Away' }], r.status || '', 'Not recorded') + '</select>') + '</td>' +
            (past ? '<td>' + ui.esc(r.in || 'Did not clock in') + '</td><td>' + ui.esc(r.out || 'Did not clock out') + '</td>' :
              '<td><input class="input sm" style="width:110px" type="time" data-f="in" value="' + (r.in || '') + '"' + dis + ' aria-label="Clock in"></td>' +
              '<td><input class="input sm" style="width:110px" type="time" data-f="out" value="' + (r.out || '') + '"' + dis + ' aria-label="Clock out"></td>') +
            '<td class="num">' + hours(r) + '</td></tr>';
        }).join('') : '<tr><td colspan="5">' + ui.empty('Nobody matches', 'Try another name or department.') + '</td></tr>') +
        '</tbody></table></div>') + '</section>';

      el.onclick = function (e) {
        var t = e.target.closest('[data-shift],[data-act]');
        if (!t) return;
        if (t.dataset.shift) { day = D.iso(D.add(D.parse(day), Number(t.dataset.shift))); HR.render(); return; }
        if (t.dataset.act === 'today') { day = ti; HR.render(); }
        if (t.dataset.act === 'fill') ui.act('attendance.fill', { date: day }, function (r) { return r.count ? 'Marked ' + ui.plural(r.count, 'person', 'people') + ' as in office' : 'Everyone already has a record'; });
        if (t.dataset.act === 'export') {
          ui.csv('attendance-' + day + '.csv', [['Date', 'Employee', 'Department', 'Status', 'Clock in', 'Clock out']].concat(HR.q.employedOn(day).map(function (x) {
            var r = HR.q.record(x.id, day) || {};
            return [day, HR.q.name(x), x.department, ui.attLabel[HR.q.statusOn(x.id, day) || 'none'], r.in || '', r.out || ''];
          })));
        }
      };
      el.oninput = function (e) {
        if (e.target.id === 'att-q') {
          needle = e.target.value;
          clearTimeout(el._t);
          el._t = setTimeout(function () { HR.render(); var i = document.getElementById('att-q'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }, 250);
        }
      };
      el.onchange = function (e) {
        if (e.target.id === 'att-day') { if (e.target.value) { day = e.target.value; HR.render(); } return; }
        if (e.target.id === 'att-dept') { dept = e.target.value; HR.render(); return; }
        var row = e.target.closest('[data-row]');
        if (!row || past) return;
        var status = row.querySelector('[data-f=status]').value;
        var tin = row.querySelector('[data-f=in]').value, tout = row.querySelector('[data-f=out]').value;
        if (e.target.dataset.f === 'status' && status && status !== 'away' && !tin) tin = new Date().toTimeString().slice(0, 5);
        HR.store.run('attendance.set', { date: day, empId: Number(row.dataset.row), status: status, in: tin, out: tout }).catch(function (err) { ui.toast(err.message, true); HR.render(); });
      };
    }
  };
})(window.HR);
