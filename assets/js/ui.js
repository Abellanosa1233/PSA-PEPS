/* Shared UI helpers: formatting, avatars, badges, modals, toasts, CSV export */
window.HR = window.HR || {};
(function (HR) {
  'use strict';
  var ic = HR.icon;
  var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

  var ui = {
    esc: function (s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    },
    money: function (n, opts) {
      var cur = (HR.store.data && HR.store.data.settings.currency) || 'PHP';
      try {
        return new Intl.NumberFormat('en-PH', Object.assign({ style: 'currency', currency: cur, maximumFractionDigits: 0 }, opts || {})).format(n || 0);
      } catch (e) { return cur + ' ' + Math.round(n || 0).toLocaleString(); }
    },
    money2: function (n) { return ui.money(n, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    num: function (n) { return Number(n || 0).toLocaleString('en-PH'); },
    pct: function (n) { return (Math.round(n * 1000) / 10).toFixed(1) + '%'; },
    date: function (iso) { if (!iso) return '—'; var d = HR.D.parse(iso); return MONTHS[d.getMonth()] + ' ' + d.getDate(); },
    dateY: function (iso) { if (!iso) return '—'; var d = HR.D.parse(iso); return MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); },
    dayLong: function (iso) { var d = HR.D.parse(iso); return DAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear(); },
    dayShort: function (iso) { return DAYS[HR.D.parse(iso).getDay()]; },
    month: function (period) { var d = HR.D.parse(period + '-01'); return ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][d.getMonth()] + ' ' + d.getFullYear(); },
    range: function (a, b) {
      if (a === b) return ui.date(a);
      var x = HR.D.parse(a), y = HR.D.parse(b);
      if (x.getMonth() === y.getMonth() && x.getFullYear() === y.getFullYear()) return MONTHS[x.getMonth()] + ' ' + x.getDate() + ' – ' + y.getDate();
      return ui.date(a) + ' – ' + ui.date(b);
    },
    days: function (n) { return n + (n === 1 ? ' day' : ' days'); },
    plural: function (n, word, many) { return n + ' ' + (n === 1 ? word : (many || word + 's')); },
    tenure: function (start) {
      var a = HR.D.parse(start), b = HR.D.today();
      var m = (b.getFullYear() - a.getFullYear()) * 12 + b.getMonth() - a.getMonth() - (b.getDate() < a.getDate() ? 1 : 0);
      if (m < 1) return 'Less than a month';
      var y = Math.floor(m / 12); m = m % 12;
      return [y ? ui.plural(y, 'year') : '', m ? ui.plural(m, 'month') : ''].filter(Boolean).join(' ');
    },
    initials: function (name) { return String(name).split(/\s+/).map(function (p) { return p[0] || ''; }).join('').slice(0, 2).toUpperCase(); },
    avatar: function (nameOrEmp, size) {
      var name = typeof nameOrEmp === 'string' ? nameOrEmp : HR.q.name(nameOrEmp);
      return '<span class="avatar' + (size ? ' ' + size : '') + '" aria-hidden="true">' + ui.esc(ui.initials(name)) + '</span>';
    },
    who: function (e, sub) {
      var name = typeof e === 'string' ? e : HR.q.name(e);
      return '<div class="who">' + ui.avatar(name) + '<div style="min-width:0"><strong>' + ui.esc(name) + '</strong><small>' + ui.esc(sub == null ? (e && e.title ? e.title : '') : sub) + '</small></div></div>';
    },
    badge: function (text, kind) { return '<span class="badge ' + (kind || '') + '">' + ui.esc(text) + '</span>'; },
    leaveBadge: function (status) { return ui.badge(status.charAt(0).toUpperCase() + status.slice(1), { approved: 'ok', pending: 'warn', rejected: 'bad' }[status]); },
    attLabel: { office: 'In office', remote: 'Remote', away: 'Away', leave: 'On leave', none: 'Not recorded' },
    attBadge: function (st) { return ui.badge(ui.attLabel[st || 'none'], { office: 'ok', remote: '', away: 'bad', leave: 'warn', none: 'plain' }[st || 'none']); },
    options: function (list, selected, placeholder) {
      var out = placeholder != null ? '<option value="">' + ui.esc(placeholder) + '</option>' : '';
      return out + list.map(function (o) {
        var v = typeof o === 'object' ? o.value : o, l = typeof o === 'object' ? o.label : o;
        return '<option value="' + ui.esc(v) + '"' + (String(v) === String(selected) ? ' selected' : '') + '>' + ui.esc(l) + '</option>';
      }).join('');
    },
    empOptions: function (selected, placeholder, filter) {
      var list = HR.q.active().filter(filter || function () { return true; }).map(function (e) { return { value: e.id, label: HR.q.name(e) + ' · ' + e.department }; });
      return ui.options(list, selected, placeholder);
    },
    empty: function (title, text, action) {
      return '<div class="empty"><strong>' + ui.esc(title) + '</strong><span>' + ui.esc(text || '') + '</span>' + (action || '') + '</div>';
    },

    /* ---------- Toasts ---------- */
    toast: function (msg, isErr) {
      var root = document.getElementById('toasts');
      var t = document.createElement('div');
      t.className = 'toast' + (isErr ? ' err' : '');
      t.setAttribute('role', isErr ? 'alert' : 'status');
      t.innerHTML = ic(isErr ? 'x' : 'check', 15) + '<span>' + ui.esc(msg) + '</span>';
      root.appendChild(t);
      setTimeout(function () { t.remove(); }, isErr ? 6000 : 3200);
    },
    /* Run a write action, report the outcome as a toast */
    act: function (action, payload, success) {
      return HR.store.run(action, payload).then(function (res) {
        if (success) ui.toast(typeof success === 'function' ? success(res) : success);
        return res;
      }, function (err) { ui.toast(err.message, true); throw err; });
    },

    /* ---------- Modals ---------- */
    modal: function (o) {
      var root = document.getElementById('modal-root');
      var wrap = document.createElement('div');
      wrap.className = 'modal-backdrop';
      wrap.innerHTML =
        '<form class="modal' + (o.wide ? ' wide' : '') + '" novalidate role="dialog" aria-modal="true" aria-labelledby="modal-title">' +
        '<div class="modal-head"><div><h2 id="modal-title">' + ui.esc(o.title) + '</h2>' + (o.subtitle ? '<p>' + ui.esc(o.subtitle) + '</p>' : '') + '</div>' +
        '<button type="button" class="icon-btn" data-close aria-label="Close">' + ic('x') + '</button></div>' +
        '<div class="modal-body">' + (o.body || '') + '<div class="form-error" hidden></div></div>' +
        (o.noFooter ? '' : '<div class="modal-foot"><button type="button" class="btn" data-close>' + ui.esc(o.cancelLabel || 'Cancel') + '</button>' +
          (o.onSubmit ? '<button type="submit" class="btn ' + (o.danger ? 'danger' : 'primary') + '">' + ui.esc(o.submitLabel || 'Save') + '</button>' : '') + '</div>') +
        '</form>';
      root.appendChild(wrap);
      var form = wrap.querySelector('form');
      var errBox = wrap.querySelector('.form-error');
      var prevFocus = document.activeElement;
      function close() {
        document.removeEventListener('keydown', onKey);
        wrap.remove();
        if (prevFocus && prevFocus.focus) prevFocus.focus();
      }
      function onKey(e) { if (e.key === 'Escape') close(); }
      document.addEventListener('keydown', onKey);
      wrap.addEventListener('mousedown', function (e) { if (e.target === wrap) close(); });
      wrap.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', close); });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!o.onSubmit) return close();
        var values = ui.formValues(form);
        var btn = form.querySelector('[type=submit]');
        btn.disabled = true;
        errBox.hidden = true;
        Promise.resolve().then(function () { return o.onSubmit(values, form); }).then(function (keepOpen) {
          if (keepOpen !== true) close(); else btn.disabled = false;
        }, function (err) {
          btn.disabled = false;
          errBox.textContent = err.message;
          errBox.hidden = false;
        });
      });
      if (o.onOpen) o.onOpen(form, close);
      var first = form.querySelector('.modal-body input:not([type=hidden]), .modal-body select, .modal-body textarea');
      (first || form.querySelector('[type=submit]') || form).focus();
      return { form: form, close: close };
    },
    confirm: function (o) {
      return ui.modal({
        title: o.title, body: '<p style="color:var(--fg-2)">' + ui.esc(o.message) + '</p>' + (o.extra || ''),
        submitLabel: o.confirmLabel || 'Confirm', danger: o.danger,
        onSubmit: function (v) { return o.onConfirm(v); }
      });
    },
    formValues: function (form) {
      var out = {};
      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.disabled) return;
        if (el.type === 'checkbox') out[el.name] = el.checked;
        else out[el.name] = el.value;
      });
      return out;
    },
    field: function (name, label, value, type, attrs) {
      var id = 'f-' + name.replace(/[^a-z0-9]/gi, '-');
      var passwordToggle = attrs && attrs.passwordToggle && type === 'password';
      var control = type === 'textarea'
        ? '<textarea class="input" id="' + id + '" name="' + name + '">' + ui.esc(value || '') + '</textarea>'
        : '<input class="input" id="' + id + '" name="' + name + '" type="' + (type || 'text') + '" value="' + ui.esc(value == null ? '' : value) + '"' + (attrs && attrs.extra ? ' ' + attrs.extra : '') + '>';
      if (passwordToggle) {
        control = '<span class="password-input-wrap">' + control +
          '<button class="password-toggle" type="button" data-password-toggle aria-controls="' + id + '" aria-label="Show password" aria-pressed="false" title="Show password"><span class="password-eye">' +
          ic('eye', 18) + '</span><span class="password-eye-off">' + ic('eyeOff', 18) + '</span></button></span>';
      }
      return '<div class="field' + (attrs && attrs.full ? ' full' : '') + '"><label for="' + id + '">' + ui.esc(label) + '</label>' +
        control +
        (attrs && attrs.hint ? '<span class="hint">' + ui.esc(attrs.hint) + '</span>' : '') + '</div>';
    },
    select: function (name, label, optionsHtml, attrs) {
      var id = 'f-' + name.replace(/[^a-z0-9]/gi, '-');
      return '<div class="field' + (attrs && attrs.full ? ' full' : '') + '"><label for="' + id + '">' + ui.esc(label) + '</label><select class="input" id="' + id + '" name="' + name + '">' + optionsHtml + '</select>' +
        (attrs && attrs.hint ? '<span class="hint">' + ui.esc(attrs.hint) + '</span>' : '') + '</div>';
    },

    /* ---------- CSV export ---------- */
    csv: function (filename, rows) {
      var text = rows.map(function (r) {
        return r.map(function (c) { var s = String(c == null ? '' : c); return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; }).join(',');
      }).join('\r\n');
      var blob = new Blob(['﻿' + text], { type: 'text/csv;charset=utf-8' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }
  };
  HR.ui = ui;
})(window.HR);
