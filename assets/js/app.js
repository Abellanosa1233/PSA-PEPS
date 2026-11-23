/* App shell: boot sequence (install → admin setup → sign in), sidebar, top bar, router */
window.HR = window.HR || {};
(function (HR) {
  'use strict';
  var ui = HR.ui, ic = HR.icon;
  var root = document.getElementById('root');
  var NAV = [
    ['overview', 'Overview', 'grid'], ['employees', 'Employees', 'users'], ['attendance', 'Attendance', 'clock'],
    ['time-off', 'Time off', 'calendar'], ['payroll', 'Payroll', 'wallet'], ['recruitment', 'Recruitment', 'briefcase'], ['reports', 'Reports', 'chart']
  ];

  HR.applyTheme && HR.applyTheme();

  /* ---------- Routing ---------- */
  function route() {
    var h = (location.hash || '').replace(/^#/, '') || 'overview';
    var m = h.match(/^employee-(\d+)$/);
    if (m) return { page: 'employee', param: Number(m[1]), nav: 'employees' };
    if (!HR.pages[h]) return { page: 'overview', nav: 'overview' };
    return { page: h, nav: HR.pages[h].parent || h };
  }

  HR.render = function () {
    if (!HR.store.data || !document.getElementById('view')) return;
    var r = route();
    var view = document.getElementById('view');
    var page = HR.pages[r.page];
    var scroll = window.scrollY;
    var same = view.dataset.page === r.page + ':' + (r.param || '');
    renderChrome(r);
    try {
      page.render(view, r.param);
    } catch (err) {
      console.error(err);
      view.innerHTML = ui.empty('This page could not be shown', err.message);
    }
    view.dataset.page = r.page + ':' + (r.param || '');
    if (same) window.scrollTo(0, scroll); else window.scrollTo(0, 0);
    var title = r.page === 'employee' && HR.q.emp(r.param) ? HR.q.name(HR.q.emp(r.param)) : page.title;
    document.title = title + ' · Horizon HRMS';
  };

  /* ---------- Chrome ---------- */
  function renderChrome(r) {
    var data = HR.store.data;
    var pending = HR.q.pending().length;
    document.querySelectorAll('.nav a').forEach(function (a) { a.classList.toggle('active', a.dataset.nav === r.nav); });
    document.querySelectorAll('.side-link').forEach(function (a) { a.classList.toggle('active', a.dataset.nav === r.nav); });
    var cnt = document.getElementById('nav-timeoff-count');
    cnt.textContent = pending; cnt.hidden = !pending;
    document.getElementById('ws-company').textContent = data.settings.company;
    document.getElementById('ws-name').textContent = data.settings.workspace;
    document.getElementById('crumb-page').textContent = r.page === 'employee' ? 'Employee profile' : HR.pages[r.page].title;
    document.getElementById('bell-dot').hidden = !pending;
    document.getElementById('user-name').textContent = data.user.name;
    document.getElementById('user-role').textContent = data.user.role;
    document.getElementById('user-avatar').outerHTML = ui.avatar(data.user.name).replace('class="avatar"', 'class="avatar" id="user-avatar"');
    document.getElementById('app').classList.remove('nav-open');
  }

  function shell() {
    root.innerHTML =
      '<div class="app" id="app">' +
      '<aside class="sidebar" id="sidebar" aria-label="Main navigation">' +
      '<a class="brand" href="#overview"><span class="brand-mark">h</span>horizon</a>' +
      '<div><div class="side-label">Workspace</div><button class="ws-switch" data-go="preferences" title="Workspace settings"><div><strong id="ws-company"></strong><span id="ws-name"></span></div>' + ic('chevDown', 14) + '</button></div>' +
      '<div><div class="side-label">Manage</div><nav class="nav">' + NAV.map(function (n) {
        return '<a href="#' + n[0] + '" data-nav="' + n[0] + '">' + ic(n[2]) + '<span>' + n[1] + '</span>' + (n[0] === 'time-off' ? '<span class="count" id="nav-timeoff-count"></span>' : '') + '</a>';
      }).join('') + '</nav></div>' +
      '<div class="side-spacer"></div>' +
      '<div class="side-links"><a class="side-link" href="#supervisor" data-nav="supervisor">Supervisor workspace ' + ic('arrowUpRight', 13) + '</a>' +
      '<a class="side-link" href="#self-service" data-nav="self-service">Employee workspace ' + ic('arrowUpRight', 13) + '</a></div>' +
      '<div class="help-card"><strong>A little help goes a long way.</strong><p>Find answers in our help center.</p><a href="#help">Get support ' + ic('arrowRight', 13) + '</a></div>' +
      '<nav class="nav"><a href="#preferences" data-nav="preferences">' + ic('settings') + '<span>Settings</span></a><a href="#help" data-nav="help">' + ic('help') + '<span>Help &amp; resources</span></a></nav>' +
      '</aside><div class="scrim" id="scrim"></div>' +
      '<div class="main"><header class="topbar">' +
      '<button class="icon-btn menu-btn" id="menu-btn" aria-label="Open navigation">' + ic('menu') + '</button>' +
      '<div class="crumbs"><span>Workspace</span>' + ic('chevRight', 13) + '<strong id="crumb-page"></strong></div>' +
      '<div class="top-actions">' +
      '<div class="search">' + ic('search', 15) + '<input id="global-search" type="search" placeholder="Search anything..." autocomplete="off" aria-label="Search people, roles and candidates"><kbd>Ctrl K</kbd><div class="search-results" id="search-results" hidden></div></div>' +
      '<button class="icon-btn" id="theme-toggle"></button>' +
      '<div class="popover-wrap"><button class="icon-btn" id="bell" aria-label="Notifications">' + ic('bell') + '<span class="dot" id="bell-dot" hidden></span></button><div class="popover" id="bell-pop" hidden></div></div>' +
      '<div class="popover-wrap"><button class="user-btn" id="user-btn" aria-haspopup="menu"><span class="avatar" id="user-avatar"></span><span class="who-text"><strong id="user-name"></strong><span id="user-role"></span></span>' + ic('chevDown', 14) + '</button>' +
      '<div class="popover menu" id="user-pop" hidden role="menu"><a href="#preferences" role="menuitem">' + ic('settings') + 'Settings</a><a href="#self-service" role="menuitem">' + ic('user') + 'Employee workspace</a><hr><button id="logout" role="menuitem">' + ic('logOut') + 'Sign out</button></div></div>' +
      '</div></header><main class="content" id="view"></main></div></div>';

    var search = document.getElementById('global-search');
    var results = document.getElementById('search-results');
    var bellPop = document.getElementById('bell-pop'), userPop = document.getElementById('user-pop');

    var themeBtn = document.getElementById('theme-toggle');
    function isDark() {
      var t = document.documentElement.getAttribute('data-theme');
      return t ? t === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
    }
    function paintThemeBtn() {
      var dark = isDark();
      themeBtn.innerHTML = ic(dark ? 'sun' : 'moon');
      themeBtn.title = dark ? 'Switch to light mode' : 'Switch to dark mode';
      themeBtn.setAttribute('aria-label', themeBtn.title);
    }
    themeBtn.onclick = function () {
      HR.applyTheme(isDark() ? 'light' : 'dark');
      paintThemeBtn();
      if (route().page === 'preferences') HR.render();
    };
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', paintThemeBtn);
    HR.paintThemeBtn = paintThemeBtn;
    paintThemeBtn();

    document.getElementById('menu-btn').onclick = function () { document.getElementById('app').classList.add('nav-open'); };
    document.getElementById('scrim').onclick = function () { document.getElementById('app').classList.remove('nav-open'); };
    root.querySelector('.ws-switch').onclick = function () { location.hash = 'preferences'; };

    function closePops(except) { [bellPop, userPop, results].forEach(function (p) { if (p !== except) p.hidden = true; }); }
    document.getElementById('bell').onclick = function (e) {
      e.stopPropagation();
      var p = HR.q.pending().slice(0, 8);
      bellPop.innerHTML = '<div class="side-label" style="padding:6px 8px">Needs your attention</div>' + (p.length ? p.map(function (l) {
        var emp = HR.q.emp(l.empId);
        return '<a class="notif" href="#time-off">' + ui.avatar(emp, 'sm') + '<div><strong>' + ui.esc(HR.q.name(emp)) + '</strong> requested ' + ui.esc(l.type.toLowerCase()) + '<small>' + ui.range(l.start, l.end) + ' · ' + ui.days(l.days) + '</small></div></a>';
      }).join('') : '<p class="muted" style="padding:8px">You\'re all caught up.</p>');
      closePops(bellPop);
      bellPop.hidden = !bellPop.hidden;
    };
    document.getElementById('user-btn').onclick = function (e) { e.stopPropagation(); closePops(userPop); userPop.hidden = !userPop.hidden; };
    document.getElementById('logout').onclick = function () { HR.api('logout', {}).then(function () { HR.store.set(null); signIn(); }); };
    document.addEventListener('click', function (e) { if (!e.target.closest('.popover-wrap') && !e.target.closest('.search')) closePops(); });

    var hl = -1;
    function doSearch() {
      var t = search.value.trim().toLowerCase();
      if (t.length < 2) { results.hidden = true; return; }
      var data = HR.store.data;
      var people = data.employees.filter(function (e) { return (HR.q.name(e) + ' ' + e.email + ' ' + e.title + ' ' + e.department).toLowerCase().indexOf(t) >= 0; }).slice(0, 6);
      var roles = data.positions.filter(function (p) { return p.title.toLowerCase().indexOf(t) >= 0; }).slice(0, 3);
      var cands = data.candidates.filter(function (c) { return c.name.toLowerCase().indexOf(t) >= 0 && c.stage !== 'Rejected'; }).slice(0, 3);
      var pages = NAV.concat([['supervisor', 'Supervisor workspace', 'users'], ['self-service', 'Employee workspace', 'user'], ['preferences', 'Settings', 'settings'], ['help', 'Help & resources', 'help']])
        .filter(function (n) { return n[1].toLowerCase().indexOf(t) >= 0; });
      var html = '';
      if (people.length) html += '<div class="grp">People</div>' + people.map(function (e) { return '<a href="#employee-' + e.id + '">' + ui.who(e, e.title + ' · ' + e.department) + '</a>'; }).join('');
      if (roles.length) html += '<div class="grp">Open roles</div>' + roles.map(function (p) { return '<a href="#recruitment" data-pos="' + p.id + '">' + ic('briefcase') + ui.esc(p.title) + '</a>'; }).join('');
      if (cands.length) html += '<div class="grp">Candidates</div>' + cands.map(function (c) { var p = HR.q.position(c.positionId); return '<a href="#recruitment" data-pos="' + c.positionId + '">' + ic('user') + ui.esc(c.name) + ' <span class="muted">· ' + ui.esc(c.stage + (p ? ' · ' + p.title : '')) + '</span></a>'; }).join('');
      if (pages.length) html += '<div class="grp">Pages</div>' + pages.map(function (n) { return '<a href="#' + n[0] + '">' + ic(n[2]) + n[1] + '</a>'; }).join('');
      results.innerHTML = html || '<p class="muted" style="padding:10px">Nothing matches "' + ui.esc(search.value) + '".</p>';
      results.hidden = false;
      hl = -1;
    }
    search.addEventListener('input', doSearch);
    search.addEventListener('focus', doSearch);
    search.addEventListener('keydown', function (e) {
      var items = results.querySelectorAll('a');
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        hl = Math.max(0, Math.min(items.length - 1, hl + (e.key === 'ArrowDown' ? 1 : -1)));
        items.forEach(function (a, i) { a.classList.toggle('hl', i === hl); });
      }
      if (e.key === 'Enter' && items.length) { (items[hl] || items[0]).click(); }
      if (e.key === 'Escape') { results.hidden = true; search.blur(); }
    });
    results.addEventListener('click', function (e) {
      var a = e.target.closest('a');
      if (!a) return;
      if (a.dataset.pos) HR.pages.recruitment.focus = Number(a.dataset.pos);
      results.hidden = true;
      search.value = '';
      if (a.getAttribute('href') === location.hash) setTimeout(HR.render);
    });
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && document.getElementById('global-search')) { e.preventDefault(); search.focus(); search.select(); }
    });
  }

  /* ---------- Auth screens ---------- */
  function authCard(title, text, body) {
    root.innerHTML = '<div class="auth"><form class="auth-card" novalidate><div class="brand" style="padding:0"><span class="brand-mark">h</span>horizon</div>' +
      '<div><h1>' + title + '</h1><p style="margin-top:4px">' + text + '</p></div>' + body + '<div class="form-error" hidden></div></form></div>';
    return root.querySelector('form');
  }
  function wire(form, fn) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var err = form.querySelector('.form-error'), btn = form.querySelector('[type=submit]');
      err.hidden = true; btn.disabled = true;
      fn(ui.formValues(form)).catch(function (x) { err.textContent = x.message; err.hidden = false; btn.disabled = false; });
    });
    var first = form.querySelector('input');
    if (first) first.focus();
  }
  function enter(state) { HR.store.set(state); shell(); HR.render(); }

  function install() {
    var f = authCard('Set up Horizon HRMS', 'The database is empty. Create the tables to get started.',
      '<label class="check"><input type="checkbox" name="demo" checked> Load sample data (43 employees, requests, attendance, payroll)</label>' +
      '<button class="btn primary" type="submit">Create database tables</button>');
    wire(f, function (v) { return HR.api('install', { demo: v.demo }).then(setup); });
  }
  function setup() {
    var f = authCard('Create the administrator', 'This account signs in to manage the whole workspace. Choose your own password.',
      ui.field('name', 'Your name', '') + ui.field('role', 'Your role', 'HR administrator') +
      ui.field('username', 'Username', '', 'text', { extra: 'autocomplete="username"' }) +
      ui.field('password', 'Password', '', 'password', { extra: 'autocomplete="new-password"', hint: 'At least 8 characters.' }) +
      '<button class="btn primary" type="submit">Create account and continue</button>');
    wire(f, function (v) { return HR.api('setup', v).then(enter); });
  }
  function signIn() {
    var f = authCard('Sign in', 'Welcome back. Sign in to your people workspace.',
      ui.field('username', 'Username', '', 'text', { extra: 'autocomplete="username"' }) +
      ui.field('password', 'Password', '', 'password', { extra: 'autocomplete="current-password"' }) +
      '<button class="btn primary" type="submit">Sign in</button>');
    wire(f, function (v) { return HR.api('login', v).then(enter); });
  }
  HR.onSignedOut = function () { if (HR.store.data) { HR.store.set(null); signIn(); ui.toast('Your session ended. Sign in again.', true); } };

  function boot() {
    if (location.protocol === 'file:') {
      root.innerHTML = '<div class="auth"><div class="auth-card"><h1>Open Horizon through the server</h1>' +
        '<p>This page was opened as a file, so it can\'t reach the database. Start Apache and MySQL in UniController, then open the address below.</p>' +
        '<a class="btn primary" href="http://localhost/HRMS/">Open http://localhost/HRMS/</a></div></div>';
      return;
    }
    root.innerHTML = '<div class="loading">Loading Horizon…</div>';
    HR.api('status').then(function (s) {
      if (!s.installed) return install();
      if (!s.hasUsers) return setup();
      if (!s.user) return signIn();
      return HR.store.load().then(function (state) { enter(state); });
    }).catch(function (err) {
      root.innerHTML = '<div class="auth"><div class="auth-card"><h1>Can\'t start Horizon</h1><p>' + ui.esc(err.message) + '</p><button class="btn primary" onclick="location.reload()">Try again</button></div></div>';
    });
  }

  HR.store.onChange(function () { if (HR.store.data && document.getElementById('view')) HR.render(); });
  window.addEventListener('hashchange', HR.render);
  boot();
})(window.HR);
