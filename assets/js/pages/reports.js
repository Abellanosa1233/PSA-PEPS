window.HR = window.HR || {};
HR.pages = HR.pages || {};
(function (HR) {
  'use strict';
  var ui = HR.ui, D = HR.D, ic = HR.icon;

  function hbars(rows, fmt) {
    var max = Math.max.apply(null, rows.map(function (r) { return r[1]; }).concat([1]));
    return rows.map(function (r) {
      return '<div class="hbar"><span>' + ui.esc(r[0]) + '</span><div class="track"><span style="width:' + (r[1] / max * 100) + '%"></span></div><span class="v">' + (fmt ? fmt(r[1]) : r[1]) + '</span></div>';
    }).join('');
  }

  function columns(rows, fmt, max) {
    var W = 520, H = 190, L = 8, B = 24, T = 18;
    max = max || Math.max.apply(null, rows.map(function (r) { return r[1]; }).concat([1]));
    var slot = (W - L * 2) / rows.length, bw = Math.min(46, slot * 0.55), ph = H - T - B;
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img">' +
      '<line class="grid-line" x1="0" x2="' + W + '" y1="' + (T + ph) + '" y2="' + (T + ph) + '"/>' +
      rows.map(function (r, i) {
        var h = max ? r[1] / max * ph : 0, x = L + slot * i + (slot - bw) / 2, y = T + ph - h, last = i === rows.length - 1;
        return '<rect x="' + x + '" y="' + y + '" width="' + bw + '" height="' + Math.max(h, 1) + '" rx="3" fill="' + (last ? 'var(--bar-1)' : 'var(--bar-2)') + '"><title>' + ui.esc(r[0] + ': ' + (fmt ? fmt(r[1]) : r[1])) + '</title></rect>' +
          '<text x="' + (x + bw / 2) + '" y="' + (y - 5) + '" text-anchor="middle"' + (last ? ' style="fill:var(--fg);font-weight:600"' : '') + '>' + (fmt ? fmt(r[1]) : r[1]) + '</text>' +
          '<text x="' + (x + bw / 2) + '" y="' + (H - 6) + '" text-anchor="middle">' + ui.esc(r[0]) + '</text>';
      }).join('') + '</svg>';
  }

  HR.pages.reports = {
    title: 'Reports',
    render: function (el) {
      var data = HR.store.data, ti = D.todayIso(), year = ti.slice(0, 4);
      var active = HR.q.active();
      var byDept = HR.DEPARTMENTS.map(function (d) { return [d, active.filter(function (e) { return e.department === d; }).length]; }).sort(function (a, b) { return b[1] - a[1]; });

      var weeks = [];
      for (var w = 5; w >= 0; w--) {
        var mon = D.add(D.monday(D.today()), -7 * w), present = 0, expected = 0;
        for (var i = 0; i < 5; i++) {
          var di = D.iso(D.add(mon, i));
          if (di > ti) break;
          var s = HR.q.dayStats(di);
          present += s.office + s.remote;
          expected += s.total - s.leave;
        }
        weeks.push([ui.date(D.iso(mon)), expected ? present / expected : 0]);
      }

      var leaveByType = HR.LEAVE_TYPES.map(function (t) {
        return [t.replace(' leave', ''), data.leaves.filter(function (l) { return l.status === 'approved' && l.type === t && l.start.slice(0, 4) === year; }).reduce(function (s, l) { return s + l.days; }, 0)];
      });
      var payroll = data.payRuns.slice().sort(function (a, b) { return a.period < b.period ? -1 : 1; }).slice(-6).map(function (r) {
        return [ui.month(r.period).slice(0, 3), HR.q.payLines(r).reduce(function (s, l) { return s + l.gross; }, 0)];
      });
      var avgSalary = byDept.map(function (d) {
        var list = active.filter(function (e) { return e.department === d[0]; });
        return [d[0], list.length ? list.reduce(function (s, e) { return s + e.salary; }, 0) / list.length : 0];
      }).sort(function (a, b) { return b[1] - a[1]; });
      var yearAgo = D.iso(D.add(D.today(), -365));
      var hires = data.employees.filter(function (e) { return e.startDate >= yearAgo; }).length;
      var leavers = data.employees.filter(function (e) { return e.endDate && e.endDate >= yearAgo; }).length;
      var short = function (n) { return n >= 1000 ? (Math.round(n / 100) / 10) + 'k' : Math.round(n); };

      el.innerHTML =
        '<div class="page-head"><div><h1>Reports</h1><p>Headcount, attendance, time off and payroll at a glance.</p></div>' +
        '<div class="actions"><button class="btn" data-act="export">' + ic('download') + 'Export headcount CSV</button></div></div>' +
        '<div class="grid kpis">' +
        '<div class="card kpi"><div class="kpi-top"><span>Headcount</span>' + ic('users') + '</div><div class="kpi-value">' + active.length + '</div><div class="kpi-foot"><span class="pill">+' + hires + '</span><span>hired in the last 12 months</span></div></div>' +
        '<div class="card kpi"><div class="kpi-top"><span>Leavers</span>' + ic('logOut') + '</div><div class="kpi-value">' + leavers + '</div><div class="kpi-foot"><span class="pill">' + ui.pct(active.length ? leavers / (active.length + leavers) : 0) + '</span><span>12-month turnover</span></div></div>' +
        '<div class="card kpi"><div class="kpi-top"><span>Attendance this week</span>' + ic('clock') + '</div><div class="kpi-value">' + ui.pct(weeks[5][1]) + '</div><div class="kpi-foot"><span>of expected workdays present</span></div></div>' +
        '<div class="card kpi"><div class="kpi-top"><span>Annual payroll</span>' + ic('wallet') + '</div><div class="kpi-value">' + ui.money(active.reduce(function (s, e) { return s + e.salary; }, 0), { notation: 'compact', maximumFractionDigits: 1 }) + '</div><div class="kpi-foot"><span>base salaries, active staff</span></div></div>' +
        '</div>' +
        '<div class="grid cols-even">' +
        '<section class="card"><div class="card-head"><div><h3>Headcount by department</h3><p>Active employees</p></div></div><div class="card-body">' + hbars(byDept) + '</div></section>' +
        '<section class="card"><div class="card-head"><div><h3>Attendance rate</h3><p>Present ÷ expected, by week starting</p></div></div><div class="chart">' + columns(weeks, function (v) { return Math.round(v * 100) + '%'; }, 1) + '</div></section>' +
        '<section class="card"><div class="card-head"><div><h3>Time off taken in ' + year + '</h3><p>Approved days by type</p></div></div><div class="card-body">' + hbars(leaveByType, function (v) { return v + 'd'; }) + '</div></section>' +
        '<section class="card"><div class="card-head"><div><h3>Monthly payroll cost</h3><p>Gross pay per run' + (data.payRuns.some(function (r) { return r.status === 'draft'; }) ? '; the latest run is still a draft' : '') + '</p></div></div><div class="chart">' + columns(payroll, short) + '</div></section>' +
        '<section class="card" style="grid-column:1/-1"><div class="card-head"><div><h3>Average base salary by department</h3><p>Active employees, ' + data.settings.currency + ' per year</p></div></div><div class="card-body">' + hbars(avgSalary, function (v) { return short(v); }) + '</div></section>' +
        '</div>';

      el.oninput = el.onchange = null;
      el.onclick = function (e) {
        if (e.target.closest('[data-act=export]')) ui.csv('headcount-' + ti + '.csv', [['Department', 'Active employees', 'Average base salary']].concat(byDept.map(function (d) {
          var a = avgSalary.find(function (x) { return x[0] === d[0]; });
          return [d[0], d[1], Math.round(a ? a[1] : 0)];
        })));
      };
    }
  };
})(window.HR);
