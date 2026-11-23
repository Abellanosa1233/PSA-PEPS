/* Client data layer: talks to the PHP API (api/index.php) and offers read helpers.
   The server is the source of truth; every write returns the fresh state. */
window.HR = window.HR || {};
(function (HR) {
  'use strict';
  var pad = function (n) { return String(n).padStart(2, '0'); };
  var data = null;
  var listeners = [];

  var D = {
    iso: function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); },
    parse: function (s) { var p = s.split('-').map(Number); return new Date(p[0], p[1] - 1, p[2] || 1); },
    add: function (d, n) { var x = new Date(d.getFullYear(), d.getMonth(), d.getDate()); x.setDate(x.getDate() + n); return x; },
    /* "Today" comes from the server so the whole team shares one calendar */
    today: function () { return data && data.today ? D.parse(data.today) : (function () { var n = new Date(); return new Date(n.getFullYear(), n.getMonth(), n.getDate()); })(); },
    todayIso: function () { return D.iso(D.today()); },
    weekend: function (d) { var w = d.getDay(); return w === 0 || w === 6; },
    monday: function (d) { return D.add(d, -((d.getDay() + 6) % 7)); },
    workdays: function (a, b) {
      var n = 0;
      for (var d = D.parse(a), e = D.parse(b); d <= e; d = D.add(d, 1)) if (!D.weekend(d)) n++;
      return n;
    },
    mins: function (hm) { if (!hm) return null; var p = hm.split(':').map(Number); return p[0] * 60 + p[1]; },
    periodEnd: function (p) { var y = +p.slice(0, 4), m = +p.slice(5, 7); return D.iso(new Date(y, m, 0)); }
  };
  HR.D = D;

  HR.LEAVE_TYPES = ['Annual leave', 'Personal leave', 'Sick leave', 'Parental leave', 'Unpaid leave'];
  HR.DEPARTMENTS = ['Design', 'Engineering', 'People', 'Product', 'Marketing', 'Sales', 'Finance', 'Operations'];
  HR.STAGES = ['Applied', 'Screening', 'Interview', 'Offer', 'Hired'];
  HR.EMP_TYPES = ['Full-time', 'Part-time', 'Contract'];

  /* ---------- API ---------- */
  HR.api = function (action, payload) {
    var opts = { method: payload === undefined ? 'GET' : 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'HorizonHRMS' } };
    if (payload !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(payload); }
    return fetch('api/index.php?action=' + encodeURIComponent(action), opts).then(function (res) {
      return res.text().then(function (text) {
        var body;
        try { body = JSON.parse(text); } catch (e) {
          throw new Error('The server sent an unexpected response. Check that Apache and MySQL are running in UniController.');
        }
        if (!res.ok) {
          var err = new Error(body.error || 'The request failed.');
          err.status = res.status;
          if (res.status === 401 && HR.onSignedOut && action !== 'login') HR.onSignedOut();
          throw err;
        }
        return body;
      });
    }, function () {
      throw new Error('Cannot reach the server. Start Apache in UniController, then try again.');
    });
  };

  HR.store = {
    get data() { return data; },
    set: function (state) { data = state; listeners.forEach(function (fn) { fn(); }); },
    onChange: function (fn) { listeners.push(fn); },
    load: function () { return HR.api('state').then(function (s) { HR.store.set(s); return s; }); },
    /* Run a write action; resolves with the action's result after state refresh */
    run: function (action, payload) {
      return HR.api(action, payload || {}).then(function (body) {
        HR.store.set(body.state);
        return body.result;
      });
    }
  };

  /* ---------- Read helpers ---------- */
  function att(di) { return (data.attendance && data.attendance[di]) || {}; }
  HR.q = {
    emp: function (id) { id = Number(id); return data.employees.find(function (e) { return e.id === id; }); },
    name: function (e) { return e ? e.firstName + ' ' + e.lastName : 'Former employee'; },
    active: function () { return data.employees.filter(function (e) { return e.status === 'Active'; }); },
    employedOn: function (di) {
      return data.employees.filter(function (e) { return e.startDate <= di && (e.status === 'Active' ? true : e.endDate && e.endDate >= di); });
    },
    leaveOn: function (empId, di) {
      return data.leaves.find(function (l) { return l.empId === empId && l.status === 'approved' && l.start <= di && l.end >= di; });
    },
    leavesOn: function (di) { return data.leaves.filter(function (l) { return l.status === 'approved' && l.start <= di && l.end >= di; }); },
    record: function (empId, di) { return att(di)[String(empId)]; },
    statusOn: function (empId, di) {
      if (HR.q.leaveOn(empId, di)) return 'leave';
      var r = HR.q.record(empId, di);
      return r ? r.status : null;
    },
    dayStats: function (di) {
      var s = { office: 0, remote: 0, away: 0, leave: 0, none: 0, total: 0 };
      HR.q.employedOn(di).forEach(function (e) { s.total++; s[HR.q.statusOn(e.id, di) || 'none']++; });
      return s;
    },
    balances: function (empId) {
      var year = String(D.today().getFullYear()), out = {};
      HR.LEAVE_TYPES.forEach(function (t) { out[t] = { allowance: +data.settings.allowances[t] || 0, used: 0, pending: 0 }; });
      data.leaves.forEach(function (l) {
        if (l.empId !== empId || l.start.slice(0, 4) !== year || !out[l.type]) return;
        if (l.status === 'approved') out[l.type].used += l.days;
        if (l.status === 'pending') out[l.type].pending += l.days;
      });
      Object.keys(out).forEach(function (t) { out[t].left = out[t].allowance - out[t].used - out[t].pending; });
      return out;
    },
    reports: function (managerId) { return data.employees.filter(function (e) { return e.managerId === managerId && e.status === 'Active'; }); },
    managers: function () {
      var ids = {};
      data.employees.forEach(function (e) { if (e.managerId && e.status === 'Active') ids[e.managerId] = true; });
      return data.employees.filter(function (e) { return ids[e.id] && e.status === 'Active'; });
    },
    position: function (id) { id = Number(id); return data.positions.find(function (p) { return p.id === id; }); },
    candidatesFor: function (posId) { return data.candidates.filter(function (c) { return c.positionId === posId && c.stage !== 'Rejected'; }); },
    pending: function () { return data.leaves.filter(function (l) { return l.status === 'pending'; }); },
    /* Mirrors compute_lines() in api/lib/bootstrap.php, used to preview draft runs */
    payLines: function (run) {
      if (run.lines) return run.lines;
      var s = data.settings, start = run.period + '-01', end = D.periodEnd(run.period);
      return data.employees.filter(function (e) {
        return e.startDate <= end && (!e.endDate || e.endDate >= start);
      }).map(function (e) {
        var base = Math.round(e.salary / 12 * 100) / 100;
        var bonus = +(run.bonuses[String(e.id)] || 0);
        var gross = base + bonus;
        var tax = Math.round(gross * s.taxRate) / 100;
        var ben = +s.benefits;
        return { empId: e.id, name: HR.q.name(e), department: e.department, base: base, bonus: bonus, gross: Math.round(gross * 100) / 100, tax: tax, benefits: ben, net: Math.round((gross - tax - ben) * 100) / 100 };
      });
    }
  };
})(window.HR);
