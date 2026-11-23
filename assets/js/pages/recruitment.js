window.HR = window.HR || {};
HR.pages = HR.pages || {};
(function (HR) {
  'use strict';
  var ui = HR.ui, ic = HR.icon;
  var posId = null, showClosed = false;

  function positionForm(p) {
    ui.modal({
      title: p ? 'Edit role' : 'Open a role', submitLabel: p ? 'Save role' : 'Open role',
      body: '<div class="form-grid">' + ui.field('title', 'Role title', p && p.title, 'text', { full: true, extra: 'required' }) +
        ui.select('department', 'Department', ui.options(HR.DEPARTMENTS, p ? p.department : 'Engineering')) +
        ui.select('type', 'Employment type', ui.options(HR.EMP_TYPES, p ? p.type : 'Full-time')) +
        ui.field('location', 'Location', p ? p.location : '', 'text', { full: true }) + '</div>',
      onSubmit: function (v) {
        v.id = p ? p.id : null;
        return HR.store.run('position.save', v).then(function (r) { posId = r.id; ui.toast(p ? 'Role updated' : 'Opened "' + v.title + '"'); });
      }
    });
  }

  HR.pages.recruitment = {
    title: 'Recruitment',
    render: function (el) {
      var data = HR.store.data, q = HR.q;
      if (HR.pages.recruitment.focus) { posId = HR.pages.recruitment.focus; HR.pages.recruitment.focus = null; }
      var open = data.positions.filter(function (p) { return p.status === 'open'; });
      var visible = data.positions.filter(function (p) { return showClosed || p.status === 'open'; });
      var pos = q.position(posId) || visible[0] || data.positions[0];
      posId = pos ? pos.id : null;
      var live = data.candidates.filter(function (c) { return c.stage !== 'Rejected' && open.some(function (p) { return p.id === c.positionId; }); });
      var by = function (s) { return live.filter(function (c) { return c.stage === s; }).length; };
      var kpi = function (label, v, note) { return '<div class="card kpi"><div class="kpi-top"><span>' + label + '</span></div><div class="kpi-value">' + v + '</div><div class="kpi-foot">' + note + '</div></div>'; };

      el.innerHTML =
        '<div class="page-head"><div><h1>Recruitment</h1><p>Track open roles and move candidates through your hiring stages.</p></div>' +
        '<div class="actions"><button class="btn primary" data-act="new-pos">' + ic('plus') + 'Open a role</button></div></div>' +
        '<div class="grid kpis">' +
        kpi('Open roles', open.length, '<span>' + (data.positions.length - open.length) + ' closed</span>') +
        kpi('In the pipeline', live.filter(function (c) { return c.stage !== 'Hired'; }).length, '<span class="pill">' + by('Applied') + ' new</span><span>waiting for screening</span>') +
        kpi('Interviewing', by('Interview'), '<span>' + by('Screening') + ' in screening</span>') +
        kpi('Offers out', by('Offer'), '<span>' + by('Hired') + ' hired</span>') + '</div>' +

        '<div class="grid cols-side">' +
        '<section class="card"><div class="card-head"><h3>Roles</h3><label class="check" style="font-size:12px"><input type="checkbox" id="show-closed"' + (showClosed ? ' checked' : '') + '> Show closed</label></div>' +
        '<div class="pos-list" style="margin-top:10px">' + (visible.length ? visible.map(function (p) {
          return '<div class="pos-item' + (pos && p.id === pos.id ? ' on' : '') + '" data-pos="' + p.id + '" tabindex="0" role="button"><div class="line"><strong>' + ui.esc(p.title) + '</strong>' +
            (p.status === 'closed' ? ui.badge('Closed', 'plain') : '<span class="pill">' + q.candidatesFor(p.id).length + '</span>') + '</div><span class="sub">' + ui.esc(p.department + ' · ' + p.type + (p.location ? ' · ' + p.location : '')) + '</span></div>';
        }).join('') : ui.empty('No open roles', 'Open a role to start hiring.')) + '</div></section>' +

        '<section class="card" style="min-width:0">' + (pos ? (function () {
          var cands = q.candidatesFor(pos.id);
          return '<div class="card-head"><div><h3>' + ui.esc(pos.title) + ' ' + (pos.status === 'open' ? ui.badge('Open', 'ok') : ui.badge('Closed', 'plain')) + '</h3><p>' + ui.esc(pos.department + ' · ' + pos.type + (pos.location ? ' · ' + pos.location : '') + ' · opened ' + ui.dateY(pos.createdAt)) + '</p></div>' +
            '<div class="toolbar"><button class="btn sm" data-act="edit-pos">' + ic('edit', 14) + 'Edit</button>' +
            '<button class="btn sm" data-act="toggle-pos">' + (pos.status === 'open' ? 'Close role' : 'Reopen') + '</button>' +
            (pos.status === 'open' ? '<button class="btn sm primary" data-act="new-cand">' + ic('plus', 14) + 'Add candidate</button>' : '') + '</div></div>' +
            '<div class="board">' + HR.STAGES.map(function (s, si) {
              var list = cands.filter(function (c) { return c.stage === s; });
              return '<div class="col"><h4>' + s + ' <span class="muted">' + list.length + '</span></h4>' + list.map(function (c) {
                return '<div class="cand"><strong>' + ui.esc(c.name) + '</strong><small>' + ui.esc((c.email || 'No email') + ' · ' + (c.source || 'Direct')) + '</small><small>Applied ' + ui.date(c.appliedAt) + '</small>' +
                  '<div class="row">' +
                  (si > 0 ? '<button class="sq" data-move="' + c.id + '" data-to="' + HR.STAGES[si - 1] + '" title="Back to ' + HR.STAGES[si - 1] + '" aria-label="Move ' + ui.esc(c.name) + ' back to ' + HR.STAGES[si - 1] + '">' + ic('chevLeft', 14) + '</button>' : '') +
                  (s !== 'Hired' ? '<button class="sq reject" data-move="' + c.id + '" data-to="Rejected" title="Reject" aria-label="Reject ' + ui.esc(c.name) + '">' + ic('x', 14) + '</button>' : '') +
                  (si < HR.STAGES.length - 1 ? '<button class="sq approve" data-move="' + c.id + '" data-to="' + HR.STAGES[si + 1] + '" title="Move to ' + HR.STAGES[si + 1] + '" aria-label="Move ' + ui.esc(c.name) + ' to ' + HR.STAGES[si + 1] + '">' + ic('chevRight', 14) + '</button>' : '') +
                  (s === 'Hired' ? (c.employeeId ? '<a class="btn sm" href="#employee-' + c.employeeId + '">View employee</a>' : '<button class="btn sm primary" data-convert="' + c.id + '">Create employee</button>') : '') +
                  '</div></div>';
              }).join('') + '</div>';
            }).join('') + '</div>';
        })() : ui.empty('Pick a role', 'Choose a role on the left to see its pipeline.')) + '</section></div>';

      el.oninput = null;
      el.onchange = function (e) { if (e.target.id === 'show-closed') { showClosed = e.target.checked; HR.render(); } };
      el.onkeydown = function (e) { if (e.key === 'Enter' && e.target.dataset.pos) { posId = Number(e.target.dataset.pos); HR.render(); } };
      el.onclick = function (e) {
        var t = e.target.closest('[data-pos],[data-act],[data-move],[data-convert]');
        if (!t) return;
        if (t.dataset.pos) { posId = Number(t.dataset.pos); HR.render(); return; }
        if (t.dataset.move) return ui.act('candidate.move', { id: Number(t.dataset.move), stage: t.dataset.to }, t.dataset.to === 'Rejected' ? 'Candidate rejected' : 'Moved to ' + t.dataset.to);
        if (t.dataset.convert) {
          var c = data.candidates.find(function (x) { return x.id === Number(t.dataset.convert); });
          var parts = c.name.split(' ');
          return HR.forms.employee(null, { firstName: parts[0], lastName: parts.slice(1).join(' '), email: '', title: pos.title, department: pos.department, type: pos.type, location: pos.location, fromCandidate: c.id });
        }
        var a = t.dataset.act;
        if (a === 'new-pos') positionForm();
        if (a === 'edit-pos') positionForm(pos);
        if (a === 'toggle-pos') ui.act('position.status', { id: pos.id, status: pos.status === 'open' ? 'closed' : 'open' }, pos.status === 'open' ? 'Role closed' : 'Role reopened');
        if (a === 'new-cand') ui.modal({
          title: 'Add candidate', subtitle: 'For ' + pos.title, submitLabel: 'Add candidate',
          body: '<div class="form-grid">' + ui.field('name', 'Full name', '', 'text', { full: true, extra: 'required' }) + ui.field('email', 'Email', '', 'email') +
            ui.select('source', 'Source', ui.options(['LinkedIn', 'Referral', 'Careers page', 'Agency', 'Other'], 'Careers page')) + '</div>',
          onSubmit: function (v) { v.positionId = pos.id; return HR.store.run('candidate.save', v).then(function () { ui.toast('Added ' + v.name); }); }
        });
      };
    }
  };
})(window.HR);
