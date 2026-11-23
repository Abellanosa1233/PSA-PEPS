window.HR = window.HR || {};
HR.pages = HR.pages || {};
(function (HR) {
  'use strict';
  var ui = HR.ui, D = HR.D, ic = HR.icon;
  var tab = 'pending', typeF = '';

  HR.pages['time-off'] = {
    title: 'Time off',
    render: function (el) {
      var data = HR.store.data, ti = D.todayIso();
      var counts = { pending: 0, approved: 0, rejected: 0, all: data.leaves.length };
      data.leaves.forEach(function (l) { counts[l.status]++; });
      var list = data.leaves.filter(function (l) { return (tab === 'all' || l.status === tab) && (!typeF || l.type === typeF); });
      if (tab === 'pending') list.sort(function (a, b) { return a.start < b.start ? -1 : 1; });
      var mon = D.monday(D.today()), fri = D.iso(D.add(mon, 4)), monI = D.iso(mon);
      var out = data.leaves.filter(function (l) { return l.status === 'approved' && l.start <= fri && l.end >= monI; });
      var upcoming = data.leaves.filter(function (l) { return l.status === 'approved' && l.start > fri; }).sort(function (a, b) { return a.start < b.start ? -1 : 1; }).slice(0, 6);
      var tabs = [['pending', 'Pending'], ['approved', 'Approved'], ['rejected', 'Declined'], ['all', 'All']];

      el.innerHTML =
        '<div class="page-head"><div><h1>Time off</h1><p>Review requests, check who is out and keep balances in line with policy.</p></div>' +
        '<div class="actions"><button class="btn primary" data-act="new">' + ic('plus') + 'New request</button></div></div>' +
        '<div class="grid cols-main">' +
        '<section class="card"><div class="card-body toolbar" style="border-bottom:1px solid var(--line)">' +
        tabs.map(function (t) { return '<button class="chip-btn' + (tab === t[0] ? ' on' : '') + '" data-tab="' + t[0] + '">' + t[1] + ' <span class="n">' + counts[t[0]] + '</span></button>'; }).join('') +
        '<select class="input sm" style="margin-left:auto;width:auto" id="to-type" aria-label="Leave type">' + ui.options(HR.LEAVE_TYPES, typeF, 'All leave types') + '</select></div>' +
        (list.length ? '<div class="table-wrap"><table class="data"><thead><tr><th>Employee</th><th>Leave type</th><th>Dates</th><th class="num">Days</th><th>Status</th><th class="num">Actions</th></tr></thead><tbody>' +
          list.map(function (l) {
            var e = HR.q.emp(l.empId);
            var acts = l.status === 'pending'
              ? '<button class="sq approve" data-approve="' + l.id + '" title="Approve" aria-label="Approve">' + ic('check', 14) + '</button><button class="sq reject" data-reject="' + l.id + '" title="Decline" aria-label="Decline">' + ic('x', 14) + '</button>'
              : (l.status === 'approved' && l.end >= ti ? '<button class="btn sm ghost" data-cancel="' + l.id + '">Cancel</button>' : '');
            return '<tr><td><a href="#employee-' + l.empId + '">' + ui.who(e) + '</a></td><td>' + ui.esc(l.type) + (l.reason ? '<span class="sub">' + ui.esc(l.reason) + '</span>' : '') + '</td>' +
              '<td class="tabular">' + ui.range(l.start, l.end) + '<span class="sub">Requested ' + ui.date(l.createdAt) + '</span></td><td class="num">' + l.days + '</td><td>' + ui.leaveBadge(l.status) + '</td><td class="actions">' + acts + '</td></tr>';
          }).join('') + '</tbody></table></div>'
          : ui.empty(tab === 'pending' ? 'No requests waiting' : 'Nothing here', tab === 'pending' ? 'New requests will appear here for approval.' : 'Try another tab or leave type.')) +
        '</section>' +

        '<div class="grid" style="align-content:start">' +
        '<section class="card"><div class="card-head"><div><h3>Out this week</h3><p>' + ui.range(monI, fri) + '</p></div></div><div class="card-body" style="display:flex;flex-direction:column;gap:10px">' +
        (out.length ? out.map(function (l) { return ui.who(HR.q.emp(l.empId), l.type + ' · ' + ui.range(l.start, l.end)); }).join('') : '<p class="muted">Everyone is in this week.</p>') + '</div></section>' +
        '<section class="card"><div class="card-head"><h3>Coming up</h3></div><div class="card-body" style="display:flex;flex-direction:column;gap:10px">' +
        (upcoming.length ? upcoming.map(function (l) { return ui.who(HR.q.emp(l.empId), l.type + ' · ' + ui.range(l.start, l.end)); }).join('') : '<p class="muted">No approved time off after this week.</p>') + '</div></section>' +
        '<section class="card"><div class="card-head"><div><h3>Leave policy</h3><p>Days per person per year</p></div><a class="link-btn" href="#preferences">Edit</a></div><div class="card-body"><dl class="dl" style="grid-template-columns:1fr auto">' +
        HR.LEAVE_TYPES.map(function (t) { return '<dt>' + t + '</dt><dd class="tabular" style="text-align:right">' + data.settings.allowances[t] + '</dd>'; }).join('') + '</dl></div></section>' +
        '</div></div>';

      el.oninput = null;
      el.onchange = function (e) { if (e.target.id === 'to-type') { typeF = e.target.value; HR.render(); } };
      el.onclick = function (e) {
        var t = e.target.closest('[data-tab],[data-act],[data-approve],[data-reject],[data-cancel]');
        if (!t) return;
        if (t.dataset.tab) { tab = t.dataset.tab; HR.render(); }
        if (t.dataset.act === 'new') HR.forms.leave();
        if (t.dataset.approve) ui.act('leave.decide', { id: Number(t.dataset.approve), status: 'approved' }, 'Request approved');
        if (t.dataset.reject) ui.act('leave.decide', { id: Number(t.dataset.reject), status: 'rejected' }, 'Request declined');
        if (t.dataset.cancel) ui.confirm({
          title: 'Cancel this time off?', message: 'The request is removed and the days go back to the employee\'s balance.', confirmLabel: 'Cancel time off', danger: true,
          onConfirm: function () { return HR.store.run('leave.cancel', { id: Number(t.dataset.cancel) }).then(function () { ui.toast('Time off cancelled'); }); }
        });
      };
    }
  };
})(window.HR);
