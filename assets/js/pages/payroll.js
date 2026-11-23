window.HR = window.HR || {};
HR.pages = HR.pages || {};
(function (HR) {
  'use strict';
  var ui = HR.ui, ic = HR.icon;
  var runId = null;

  function sum(lines, k) { return lines.reduce(function (s, l) { return s + l[k]; }, 0); }

  HR.pages.payroll = {
    title: 'Payroll',
    render: function (el) {
      var data = HR.store.data;
      var runs = data.payRuns.slice().sort(function (a, b) { return a.period < b.period ? 1 : -1; });
      if (!runs.length) {
        el.innerHTML = '<div class="page-head"><div><h1>Payroll</h1></div></div><section class="card">' + ui.empty('No pay runs yet', 'Start your first monthly pay run.', '<button class="btn primary" data-act="create">' + ic('plus') + 'Start pay run</button>') + '</section>';
        el.onclick = function (e) { if (e.target.closest('[data-act=create]')) ui.act('payroll.create', {}, 'Pay run started'); };
        return;
      }
      var run = runs.find(function (r) { return r.id === runId; }) || runs.find(function (r) { return r.status === 'draft'; }) || runs[0];
      runId = run.id;
      var lines = HR.q.payLines(run);
      var draft = run.status === 'draft';
      var hasDraft = runs.some(function (r) { return r.status === 'draft'; });
      var kpi = function (label, v, note) { return '<div class="card kpi"><div class="kpi-top"><span>' + label + '</span></div><div class="kpi-value">' + v + '</div><div class="kpi-foot">' + note + '</div></div>'; };

      el.innerHTML =
        '<div class="page-head"><div><h1>Payroll</h1><p>Monthly pay runs. Withholding tax is ' + data.settings.taxRate + '% and SSS, PhilHealth and Pag-IBIG contributions are ' + ui.money(data.settings.benefits) + ' per person.</p></div>' +
        '<div class="actions"><select class="input" style="width:auto" id="run-pick" aria-label="Pay run">' +
        ui.options(runs.map(function (r) { return { value: r.id, label: ui.month(r.period) + (r.status === 'draft' ? ' · Draft' : ' · Paid') }; }), run.id) + '</select>' +
        '<button class="btn" data-act="export">' + ic('download') + 'Export CSV</button>' +
        (!hasDraft ? '<button class="btn" data-act="create">' + ic('plus') + 'Start next pay run</button>' : '') +
        (draft ? '<button class="btn primary" data-act="pay">' + ic('check') + 'Approve and pay</button>' : '') + '</div></div>' +

        '<div class="grid kpis">' +
        kpi('Employees paid', ui.num(lines.length), '<span class="pill">' + ui.month(run.period) + '</span>') +
        kpi('Gross pay', ui.money(sum(lines, 'gross')), '<span>incl. ' + ui.money(sum(lines, 'bonus')) + ' in bonuses</span>') +
        kpi('Deductions', ui.money(sum(lines, 'tax') + sum(lines, 'benefits')), '<span>tax and benefits</span>') +
        kpi('Net pay', ui.money(sum(lines, 'net')), draft ? ui.badge('Draft', 'warn') : ui.badge('Paid ' + ui.dateY((run.paidAt || '').slice(0, 10)), 'ok')) +
        '</div>' +

        '<section class="card"><div class="card-head"><div><h3>' + ui.month(run.period) + '</h3><p>' + (draft ? 'Add one-off bonuses below, then approve. Amounts are recalculated from current salaries until the run is paid.' : 'This run is locked. Amounts are the payslips issued on the pay date.') + '</p></div></div>' +
        '<div class="table-wrap" style="margin-top:10px"><table class="data"><thead><tr><th>Employee</th><th>Department</th><th class="num">Base</th><th class="num">Bonus</th><th class="num">Gross</th><th class="num">Tax</th><th class="num">Contributions</th><th class="num">Net pay</th></tr></thead><tbody>' +
        lines.map(function (l) {
          return '<tr><td>' + (l.empId && HR.q.emp(l.empId) ? '<a href="#employee-' + l.empId + '">' + ui.who(HR.q.emp(l.empId)) + '</a>' : ui.who(l.name, '')) + '</td><td>' + ui.esc(l.department) + '</td>' +
            '<td class="num">' + ui.money2(l.base) + '</td>' +
            '<td class="num">' + (draft ? '<input class="input sm" style="width:100px;text-align:right" type="number" min="0" step="50" data-bonus="' + l.empId + '" value="' + (l.bonus || '') + '" placeholder="0" aria-label="Bonus for ' + ui.esc(l.name) + '">' : ui.money2(l.bonus)) + '</td>' +
            '<td class="num">' + ui.money2(l.gross) + '</td><td class="num">' + ui.money2(l.tax) + '</td><td class="num">' + ui.money2(l.benefits) + '</td><td class="num"><strong>' + ui.money2(l.net) + '</strong></td></tr>';
        }).join('') + '</tbody><tfoot><tr><td colspan="2">Total</td><td class="num">' + ui.money2(sum(lines, 'base')) + '</td><td class="num">' + ui.money2(sum(lines, 'bonus')) + '</td><td class="num">' + ui.money2(sum(lines, 'gross')) + '</td><td class="num">' + ui.money2(sum(lines, 'tax')) + '</td><td class="num">' + ui.money2(sum(lines, 'benefits')) + '</td><td class="num">' + ui.money2(sum(lines, 'net')) + '</td></tr></tfoot></table></div></section>';

      el.oninput = null;
      el.onchange = function (e) {
        if (e.target.id === 'run-pick') { runId = Number(e.target.value); HR.render(); return; }
        if (e.target.dataset.bonus) ui.act('payroll.bonus', { runId: run.id, empId: Number(e.target.dataset.bonus), amount: Number(e.target.value || 0) }, 'Bonus saved');
      };
      el.onclick = function (e) {
        var t = e.target.closest('[data-act]');
        if (!t) return;
        if (t.dataset.act === 'create') ui.act('payroll.create', {}, 'Next pay run started').then(function (r) { runId = r.id; HR.render(); });
        if (t.dataset.act === 'pay') ui.confirm({
          title: 'Approve ' + ui.month(run.period) + ' payroll?',
          message: 'This pays ' + ui.plural(lines.length, 'employee') + ' a total net of ' + ui.money2(sum(lines, 'net')) + ' and issues payslips. Paid runs are locked and cannot be edited.',
          confirmLabel: 'Approve and pay',
          onConfirm: function () { return HR.store.run('payroll.pay', { runId: run.id }).then(function () { ui.toast(ui.month(run.period) + ' payroll paid'); }); }
        });
        if (t.dataset.act === 'export') ui.csv('payroll-' + run.period + '.csv', [['Period', 'Employee', 'Department', 'Base', 'Bonus', 'Gross', 'Tax', 'Benefits', 'Net', 'Status']].concat(
          lines.map(function (l) { return [run.period, l.name, l.department, l.base, l.bonus, l.gross, l.tax, l.benefits, l.net, run.status]; })));
      };
    }
  };
})(window.HR);
