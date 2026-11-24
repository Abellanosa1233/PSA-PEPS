window.HR = window.HR || {};
HR.pages = HR.pages || {};
(function (HR) {
  'use strict';
  var ui = HR.ui, D = HR.D, ic = HR.icon;
  var weekOffset = null;

  function greeting() {
    var h = new Date().getHours();
    return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
  }

  function kpi(label, icon, value, pill, note) {
    return '<div class="card kpi"><div class="kpi-top"><span>' + ui.esc(label) + '</span>' + ic(icon) + '</div>' +
      '<div class="kpi-value">' + ui.esc(value) + '</div>' +
      '<div class="kpi-foot"><span class="pill">' + ui.esc(pill) + '</span><span>' + ui.esc(note) + '</span></div></div>';
  }

  function weekDays(offset) {
    var mon = D.add(D.monday(D.today()), offset * 7);
    return [0, 1, 2, 3, 4].map(function (i) { return D.iso(D.add(mon, i)); });
  }

  function attendanceChart(days) {
    var W = 760, H = 210, L = 58, R = 8, T = 10, B = 26;
    var ph = H - T - B, pw = W - L - R, slot = pw / days.length, bw = Math.min(48, slot * 0.5);
    var today = D.todayIso();
    var y = function (p) { return T + ph * (1 - p); };
    var out = '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Attendance by day as a share of the workforce">';
    [0, 0.5, 1].forEach(function (p) {
      out += '<line class="grid-line" x1="' + L + '" x2="' + (W - R) + '" y1="' + y(p) + '" y2="' + y(p) + '"/>' +
        '<text x="' + (L - 12) + '" y="' + (y(p) + 4) + '" text-anchor="end">' + (p * 100) + '%</text>';
    });
    days.forEach(function (di, i) {
      var x = L + slot * i + (slot - bw) / 2;
      var s = HR.q.dayStats(di);
      var label = ui.dayShort(di);
      out += '<text x="' + (x + bw / 2) + '" y="' + (H - 6) + '" text-anchor="middle"' + (di === today ? ' style="fill:var(--fg);font-weight:600"' : '') + '>' + label + '</text>';
      if (di > today || !s.total) {
        out += '<rect x="' + x + '" y="' + y(1) + '" width="' + bw + '" height="' + ph + '" rx="3" fill="none" stroke="var(--line-strong)" stroke-dasharray="3 3"><title>' + label + ': no data yet</title></rect>';
        return;
      }
      var segs = [['office', 'var(--bar-1)'], ['remote', 'var(--attendance-remote)'], ['away', 'var(--attendance-away)']];
      var acc = 0;
      var awayish = s.away + s.leave + (di < today ? s.none : 0);
      var vals = { office: s.office / s.total, remote: s.remote / s.total, away: awayish / s.total };
      var clipId = 'attendance-clip-' + i;
      out += '<defs><clipPath id="' + clipId + '"><rect x="' + x + '" y="' + y(1) + '" width="' + bw + '" height="' + ph + '" rx="4"/></clipPath></defs><g clip-path="url(#' + clipId + ')">';
      segs.forEach(function (sg) {
        var v = vals[sg[0]];
        if (v <= 0) return;
        var top = y(acc + v), h = y(acc) - top;
        out += '<rect x="' + x + '" y="' + top + '" width="' + bw + '" height="' + h + '" fill="' + sg[1] + '"><title>' + label + ' · ' + ui.attLabel[sg[0]] + ': ' + ui.pct(v) + '</title></rect>';
        acc += v;
      });
      out += '</g>';
    });
    return out + '</svg>';
  }

  HR.pages.overview = {
    title: 'Overview',
    render: function (el) {
      var data = HR.store.data, q = HR.q, ti = D.todayIso();
      var active = q.active();
      var st = q.dayStats(ti);
      var present = st.office + st.remote;
      var monthAgo = D.iso(D.add(D.today(), -30));
      var newHires = active.filter(function (e) { return e.startDate > monthAgo; }).length;
      var outToday = q.leavesOn(ti);
      var unplanned = outToday.filter(function (l) { return l.type === 'Sick leave'; }).length;
      var open = data.positions.filter(function (p) { return p.status === 'open'; });
      var inPipe = data.candidates.filter(function (c) { return c.stage !== 'Rejected' && c.stage !== 'Hired' && open.some(function (p) { return p.id === c.positionId; }); }).length;
      if (weekOffset === null) weekOffset = D.today().getDay() >= 1 && D.today().getDay() <= 2 ? -1 : 0;
      var days = weekDays(weekOffset);
      var weekOpts = [0, -1, -2, -3, -4, -5].map(function (o) { var w = weekDays(o); return { value: o, label: ui.range(w[0], w[4]) }; });
      var pending = q.pending().sort(function (a, b) { return a.start < b.start ? -1 : 1; });
      var events = data.events.filter(function (e) { return e.date === ti; });
      var nowHm = new Date().toTimeString().slice(0, 5);
      var roles = open.map(function (p) {
        var c = q.candidatesFor(p.id);
        var adv = c.reduce(function (s, x) { return s + HR.STAGES.indexOf(x.stage); }, 0);
        return { p: p, n: c.length, progress: c.length ? adv / (c.length * (HR.STAGES.length - 1)) : 0 };
      }).sort(function (a, b) { return b.n - a.n; }).slice(0, 3);
      var firstName = (data.user.name || '').split(' ')[0];

      el.innerHTML =
        '<div class="page-head"><div><h1>' + greeting() + ', ' + ui.esc(firstName) + '</h1><p>Review workforce activity and priority updates for today.</p></div>' +
        '<div class="actions"><button class="btn" data-act="calendar">' + ic('calendar') + ui.esc(ui.dayLong(ti).replace(/^(\w+),/, '$1,')) + '</button></div></div>' +

        '<div class="grid kpis">' +
        kpi('Total employees', 'users', ui.num(active.length), '+' + newHires, 'joined in the last 30 days') +
        kpi('Present today', 'clock', ui.num(present), st.total ? ui.pct(present / st.total) : '0%', 'of your workforce') +
        kpi('On leave today', 'calendar', ui.num(outToday.length), (outToday.length - unplanned) + ' planned', '· ' + unplanned + ' unplanned') +
        kpi('Open positions', 'briefcase', ui.num(open.length), ui.plural(inPipe, 'candidate'), '· in the pipeline') +
        '</div>' +

        '<div class="grid cols-main">' +
        '<section class="card attendance-overview"><div class="card-head"><div><h3>Attendance overview</h3><p>A little perspective on your team’s week.</p></div>' +
        '<form class="attendance-week" id="week-form"><select class="input sm" id="week-pick" aria-label="Week">' + ui.options(weekOpts, weekOffset) + '</select><button class="btn sm" type="submit">Show</button></form></div>' +
        '<div class="legend attendance-legend"><span><i class="attendance-office"></i>In office</span><span><i class="attendance-remote"></i>Remote</span><span><i class="attendance-away"></i>Away</span></div>' +
        '<div class="chart attendance-chart">' + attendanceChart(days) + '</div></section>' +

        '<section class="card schedule-card"><div class="card-head"><div><h3>Today\'s schedule</h3></div><button class="link-btn" data-act="calendar">View calendar</button></div>' +
        '<div class="sched-day" style="padding-top:4px">' + ui.esc(ui.dayShort(ti).toUpperCase() + ', ' + D.today().getDate() + ' ' + ui.date(ti).split(' ')[0].toUpperCase()) + '</div>' +
        (events.length ? '<div class="sched">' + events.map(function (e) {
          return '<div class="sched-item' + (e.end < nowHm ? ' past' : '') + '"><div class="t"><strong>' + e.start + '</strong><small>' + e.end + '</small></div><div class="rule"></div>' +
            '<div class="e"><strong>' + ui.esc(e.title) + '</strong><small>' + ui.esc(e.detail) + '</small></div></div>';
        }).join('') + '</div>' : ui.empty('No events scheduled', 'Add an activity to the schedule as needed.', '<button class="btn sm" data-act="add-event">' + ic('plus') + 'Add event</button>')) +
        '</section></div>' +

        '<div class="grid cols-main">' +
        '<section class="card"><div class="card-head"><div><h3>Time-off requests <span class="pill">' + pending.length + ' pending</span></h3></div><a class="link-btn" href="#time-off">View all requests ' + ic('arrowRight', 14) + '</a></div>' +
        (pending.length ? '<div class="table-wrap" style="margin-top:10px"><table class="data"><thead><tr><th>Employee</th><th>Leave type</th><th>Dates</th><th class="num">Actions</th></tr></thead><tbody>' +
          pending.slice(0, 5).map(function (l) {
            var e = q.emp(l.empId);
            return '<tr class="clickable" data-emp="' + l.empId + '"><td>' + ui.who(e) + '</td><td>' + ui.esc(l.type) + '</td><td>' + ui.esc(ui.range(l.start, l.end)) + '<span class="sub">' + ui.days(l.days) + '</span></td>' +
              '<td class="actions"><button class="sq approve" data-approve="' + l.id + '" aria-label="Approve" title="Approve">' + ic('check', 14) + '</button>' +
              '<button class="sq reject" data-reject="' + l.id + '" aria-label="Decline" title="Decline">' + ic('x', 14) + '</button></td></tr>';
          }).join('') + '</tbody></table></div>' +
          '<div class="card-foot"><span>Showing ' + Math.min(5, pending.length) + ' of ' + pending.length + ' pending. Select a person to open their profile.</span></div>'
          : ui.empty('No pending requests', 'New leave requests will appear here for review.')) +
        '</section>' +

        '<section class="card"><div class="card-head"><div><h3>Hiring at a glance</h3></div><span class="pill">' + ui.plural(open.length, 'open role') + '</span></div>' +
        '<div style="margin-top:6px">' + (roles.length ? roles.map(function (r) {
          return '<div class="role-row"><div class="line"><strong>' + ui.esc(r.p.title) + '</strong></div>' +
            '<div class="line"><span class="muted" style="font-size:11.5px">' + ui.esc(r.p.department + ' · ' + r.p.type) + '</span><span class="muted" style="font-size:11.5px">' + ui.plural(r.n, 'candidate') + '</span></div>' +
            '<div class="meter" title="Average pipeline progress"><span style="width:' + Math.round(r.progress * 100) + '%"></span></div></div>';
        }).join('') : ui.empty('No open roles', 'Open a role to start tracking candidates.')) + '</div>' +
        '<div style="padding:0 16px 14px"><a class="btn" style="width:100%" href="#recruitment">View recruitment ' + ic('arrowRight', 14) + '</a></div></section>' +
        '</div>';

      el.onclick = function (e) {
        var t = e.target.closest('[data-act],[data-approve],[data-reject],[data-emp]');
        if (!t) return;
        if (t.dataset.approve) { e.stopPropagation(); return ui.act('leave.decide', { id: Number(t.dataset.approve), status: 'approved' }, 'Request approved'); }
        if (t.dataset.reject) { e.stopPropagation(); return ui.act('leave.decide', { id: Number(t.dataset.reject), status: 'rejected' }, 'Request declined'); }
        if (t.dataset.emp) { location.hash = 'employee-' + t.dataset.emp; return; }
        if (t.dataset.act === 'calendar') HR.forms.calendar();
        if (t.dataset.act === 'add-event') HR.forms.event();
      };
      el.onchange = function (e) {
        if (e.target.id === 'week-pick') weekOffset = Number(e.target.value);
      };
      el.onsubmit = function (e) {
        if (e.target.id !== 'week-form') return;
        e.preventDefault();
        HR.render();
      };
    }
  };
})(window.HR);
