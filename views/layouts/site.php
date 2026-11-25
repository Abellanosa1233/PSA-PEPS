<!doctype html>
<html lang="en" data-theme="<?= h($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($user ? $pages[$page] : $screen) ?> · HRMS</title>
    <link rel="stylesheet" href="<?= h(versioned_asset('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap">
    <link rel="stylesheet" href="<?= h(versioned_asset('assets/css/php.css')) ?>">
    <script>
        try {
            if (localStorage.getItem('hrms-sidebar-collapsed') === '1') {
                document.documentElement.classList.add('sidebar-collapsed');
            }
        } catch (_) {}
    </script>
</head>
<body>
<div class="page-loader" id="page-loader" role="status" aria-label="Loading page" hidden>
    <div class="page-loader-seal">
        <img src="assets/psa-logo.svg" width="64" height="64" alt="">
    </div>
</div>
<script>
    if (performance.getEntriesByType('navigation')[0]?.type === 'reload') {
        const loader = document.getElementById('page-loader');
        loader.hidden = false;
        window.addEventListener('pageshow', () => { loader.hidden = true; }, { once: true });
    }
</script>
<?php if (!$user): ?>
    <main class="auth web-auth<?= $screen === 'Sign in' ? ' web-auth-signin' : '' ?>">
        <button class="btn desktop-full-screen desktop-full-screen-auth" type="button" hidden>Full screen</button>
        <section class="auth-card<?= $screen === 'Sign in' ? ' auth-card-signin' : '' ?>">
            <a class="brand" href="<?= h(page_url('overview')) ?>"><img class="brand-logo" src="assets/psa-logo.svg" alt="Philippine Statistics Authority seal"><span class="brand-copy"><strong>HRMS</strong><small>Human Resource Management System</small></span></a>
            <h1><?= h($screen) ?></h1>
            <?php if ($screen === 'Sign in'): ?><p class="auth-intro">Welcome back. Sign in to your people workspace.</p><?php endif; ?>
            <?php web_message($errorField ? '' : $error, ''); ?>
            <?php if ($screen === 'Install HRMS'): ?>
                <p>Create the database tables before setting up your administrator account.</p>
                <?php post_form('install'); ?>
                <?php if (setup_key_required()) field('setupKey', 'Setup key', '', 'password'); ?>
                <label><input type="checkbox" name="demo" value="1"> Load sample data</label>
                <?php end_form('Install'); ?>
            <?php else: ?>
                <?php post_form($screen === 'Sign in' ? 'login' : 'setup'); ?>
                <?php if ($screen !== 'Sign in') field('name', 'Full name'); ?>
                <?php if ($screen !== 'Sign in' && setup_key_required()) field('setupKey', 'Setup key', '', 'password'); ?>
                <?php
                    if ($screen === 'Sign in') {
                        field('username', 'Username', $formError['values']['username'] ?? '', 'text', true, $errorField === 'username' ? $error : '', [
                            'autocomplete' => 'username', 'minlength' => '3', 'maxlength' => '60', 'pattern' => '[A-Za-z0-9._-]+',
                        ]);
                        field('password', 'Password', '', 'password', true, $errorField === 'password' ? $error : '', [
                            'autocomplete' => 'current-password', 'passwordToggle' => true,
                        ]);
                    } else {
                        field('username', 'Username');
                        field('password', 'Password', '', 'password');
                    }
                ?>
                <?php if ($screen === 'Sign in'): ?><p class="auth-security-note">Authorized personnel only · Philippine Statistics Authority</p><?php endif; ?>
                <?php end_form($screen); ?>
            <?php endif; ?>
        </section>
    </main>
    <?php if ($screen === 'Sign in'): ?>
        <script>
            (function () {
                var button = document.querySelector('[data-password-toggle]');
                var password = document.getElementById('signin-password');
                var form = document.querySelector('.web-auth-signin form');
                var username = form && form.elements.namedItem('username');
                if (!form || !username || !password) return;
                form.noValidate = true;

                function validate(input) {
                    var message = input === username
                        ? (!input.value.trim() ? 'Enter your username.' : (!/^[a-z0-9._-]{3,60}$/i.test(input.value.trim()) ? 'Use 3–60 letters, numbers, dots, dashes or underscores.' : ''))
                        : (!input.value ? 'Enter your password.' : '');
                    var field = input.closest('.field');
                    var error = field.querySelector('.field-error');
                    if (message && !error) {
                        error = document.createElement('span');
                        error.className = 'field-error';
                        error.id = 'field-error-' + input.name;
                        field.appendChild(error);
                    }
                    if (error) {
                        error.textContent = message;
                        error.hidden = !message;
                    }
                    if (message) {
                        input.setAttribute('aria-invalid', 'true');
                        input.setAttribute('aria-describedby', error.id);
                    } else {
                        input.removeAttribute('aria-invalid');
                        input.removeAttribute('aria-describedby');
                    }
                    return !message;
                }

                form.addEventListener('submit', function (event) {
                    var usernameValid = validate(username);
                    var passwordValid = validate(password);
                    if (!usernameValid || !passwordValid) {
                        event.preventDefault();
                        (usernameValid ? password : username).focus();
                    }
                });
                form.addEventListener('input', function (event) {
                    if (event.target === username || event.target === password) {
                        if (event.target.hasAttribute('aria-invalid')) validate(event.target);
                    }
                });
                if (button) button.addEventListener('click', function () {
                    var visible = password.type === 'password';
                    password.type = visible ? 'text' : 'password';
                    button.setAttribute('aria-pressed', String(visible));
                    button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
                    button.title = visible ? 'Hide password' : 'Show password';
                });
            }());
        </script>
    <?php endif; ?>
<?php else:
    $navIcons = [
        'overview' => 'grid', 'employees' => 'users', 'attendance' => 'clock', 'time-off' => 'calendar',
        'payroll' => 'wallet', 'recruitment' => 'briefcase', 'reports' => 'chart',
    ];
    $isAdmin = is_admin($user);
    // HR is notified of every pending request; a supervising employee only of their direct reports' requests.
    $needsDecision = function ($item) use ($isAdmin, $user) {
        return $item['status'] === 'pending' && ($isAdmin || $item['empId'] !== $user['employeeId']);
    };
    $pendingLeaves = array_filter($state['leaves'] ?? [], $needsDecision);
    $pendingEmployeeRequests = array_filter($state['employeeRequests'] ?? [], $needsDecision);
    $pendingCount = count($pendingLeaves) + count($pendingEmployeeRequests);
    $employeesById = array_column($state['employees'] ?? [], null, 'id');
    $notifications = [];
    foreach ($pendingLeaves as $leave) $notifications[] = ['leave', $leave];
    foreach ($pendingEmployeeRequests as $request) $notifications[] = ['request', $request];
    $notifications = array_slice($notifications, 0, 8);
    $reviewPage = $isAdmin ? 'time-off' : 'supervisor';
    $reviewQuery = $isAdmin ? ['status' => 'pending'] : [];
    $homePage = $isAdmin ? 'overview' : 'self-service';
    $settings = $state['settings'] ?? [];
    $signOutReturn = page_url($page, $query);
    $showSignOut = ($_GET['confirmSignOut'] ?? '') === '1';
?>
    <div class="app web-app">
        <aside class="sidebar" id="web-sidebar" aria-label="Main navigation">
            <button class="sidebar-toggle" id="web-sidebar-toggle" type="button" aria-controls="web-sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar"><?= icon('chevLeft', 16) ?></button>
            <a class="brand sidebar-brand" href="<?= h(page_url($homePage)) ?>" title="Human Resource Management System">
                <img src="assets/psa-logo.svg" width="40" height="40" alt="Philippine Statistics Authority">
                <span class="brand-copy"><strong>HRMS</strong><small>Human Resource Management System</small></span>
            </a>
            <div>
                <div class="side-label">Workspace</div>
                <a class="ws-switch" href="<?= h(page_url('preferences')) ?>">
                    <div><strong><?= h($settings['company'] ?? 'HRMS') ?></strong><span><?= h($settings['workspace'] ?? 'Workspace') ?></span></div>
                    <?= icon('chevDown', 14) ?>
                </a>
            </div>
            <?php if (!$isAdmin) $navIcons = ['self-service' => 'user'] + ($user['hasTeam'] ? ['supervisor' => 'users'] : []); ?>
            <div>
                <div class="side-label"><?= $isAdmin ? 'Manage' : 'My account' ?></div>
                <nav class="nav">
                    <?php foreach ($navIcons as $key => $navIcon): ?>
                        <a href="<?= h(page_url($key)) ?>" title="<?= h($pages[$key]) ?>"<?= $key === $page ? ' class="active" aria-current="page"' : '' ?>>
                            <?= icon($navIcon) ?><span><?= h($pages[$key]) ?></span>
                            <?php if ($key === $reviewPage && $pendingCount): ?><span class="count"><?= $pendingCount ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="side-spacer"></div>
            <?php if ($isAdmin): ?>
            <div class="side-links">
                <?php foreach (['supervisor', 'self-service'] as $key): ?>
                    <a class="side-link<?= $page === $key ? ' active' : '' ?>" href="<?= h(page_url($key)) ?>" title="<?= h($pages[$key]) ?>"><?= h($pages[$key]) ?><?= icon('arrowUpRight', 13) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="help-card">
                <strong>Human Resource Support</strong>
                <p>Access system guidance and assistance.</p>
                <a href="<?= h(page_url('help')) ?>">Open help center <?= icon('arrowRight', 15) ?></a>
            </div>
            <nav class="nav" aria-label="Settings and help">
                <a class="<?= $page === 'preferences' ? 'active' : '' ?>" href="<?= h(page_url('preferences')) ?>" aria-label="Settings" title="Settings"<?= $page === 'preferences' ? ' aria-current="page"' : '' ?>>
                    <?= icon('settings') ?><span>Settings</span>
                </a>
                <a class="<?= $page === 'help' ? 'active' : '' ?>" href="<?= h(page_url('help')) ?>" aria-label="Help &amp; resources" title="Help &amp; resources"<?= $page === 'help' ? ' aria-current="page"' : '' ?>>
                    <?= icon('help') ?><span>Help &amp; resources</span>
                </a>
            </nav>
        </aside>
        <div class="scrim" id="web-nav-scrim"></div>
        <div class="main">
            <header class="topbar">
                <button class="icon-btn menu-btn" id="web-menu-btn" type="button" aria-label="Open navigation"><?= icon('menu', 18) ?></button>
                <div class="crumbs"><span>Workspace</span><?= icon('chevRight', 13) ?><strong><?= h($pages[$page]) ?></strong></div>
                <div class="top-actions">
                    <button class="btn desktop-full-screen" type="button" hidden>Full screen</button>
                    <form method="post" id="web-theme-form">
                        <?php hidden_inputs(['_action' => 'theme', '_token' => $_SESSION['web_csrf']]); ?>
                        <?php /* Two plain buttons; CSS shows the right one, including for "System" theme on a dark device. */ ?>
                        <button type="submit" name="theme" value="light" class="icon-btn theme-to-light" aria-label="Switch to light mode" title="Switch to light mode"><?= icon('sun', 17) ?></button>
                        <button type="submit" name="theme" value="dark" class="icon-btn theme-to-dark" aria-label="Switch to dark mode" title="Switch to dark mode"><?= icon('moon', 17) ?></button>
                    </form>
                    <details class="popover-wrap web-notifications-wrap">
                        <summary class="icon-btn web-bell" id="notification-bell" aria-label="Notifications<?= $pendingCount ? ', ' . $pendingCount . ' pending' : '' ?>" aria-haspopup="true" aria-expanded="false">
                            <?= icon('bell') ?><?php if ($pendingCount): ?><span class="dot"></span><?php endif; ?>
                        </summary>
                        <div class="popover notification-popover" id="notification-popover">
                            <div class="notification-popover-head"><strong>Notifications</strong><?php if ($pendingCount): ?><span class="pill"><?= $pendingCount ?> pending</span><?php endif; ?></div>
                            <?php if ($notifications): ?>
                                <?php foreach ($notifications as [$kind, $item]):
                                    $employee = $employeesById[$item['empId']] ?? null;
                                    $name = $employee ? employee_name($employee) : 'Former employee';
                                    $label = $kind === 'leave' ? 'Requested ' . strtolower($item['type']) : ($item['type'] === 'travel_order' ? 'Travel Order' : 'Special Order') . ' · ' . $item['purpose'];
                                ?>
                                    <a class="notification-item" href="<?= h(page_url($reviewPage, $reviewQuery)) ?>">
                                        <span class="avatar sm"><?= h(initials($name)) ?></span>
                                        <span><strong><?= h($name) ?></strong><small><?= h($label) ?> · <?= h($item['start']) ?></small></span>
                                    </a>
                                <?php endforeach; ?>
                                <a class="notification-footer" href="<?= h(page_url($reviewPage, $reviewQuery)) ?>">Review pending requests</a>
                            <?php else: ?>
                                <div class="notification-empty"><?= icon('check', 18) ?><strong>No pending notifications</strong><span>There are no new notifications.</span></div>
                            <?php endif; ?>
                        </div>
                    </details>
                    <details class="popover-wrap web-user">
                        <summary class="user-btn"><span class="avatar"><?php if (!empty($user['avatarUrl'])): ?><img src="<?= h($user['avatarUrl']) ?>" alt=""><?php else: ?><?= h(initials($user['name'])) ?><?php endif; ?></span><span class="who-text"><strong><?= h($user['name']) ?></strong><span><?= h($isAdmin ? 'Administrator · ' . $user['role'] : 'Employee access') ?></span></span><?= icon('chevDown', 14) ?></summary>
                        <div class="popover menu">
                            <a href="<?= h(page_url('preferences')) ?>"><?= icon('settings') ?> Settings</a>
                            <a href="<?= h(page_url('self-service')) ?>"><?= icon('user') ?> <?= h($pages['self-service']) ?></a><hr>
                            <a id="open-sign-out" href="<?= h(page_url($page, ['confirmSignOut' => 1] + $query)) ?>" aria-haspopup="dialog"><?= icon('logOut') ?> Sign out</a>
                        </div>
                    </details>
                </div>
            </header>
            <main class="content">
                <?php if (!in_array($page, ['overview', 'employees', 'attendance', 'time-off', 'reports'], true) || !$state): ?>
                    <div class="page-head"><h1><?= h($pages[$page]) ?></h1></div>
                <?php endif; ?>
                <?php
                $hasEmployeeModal = $state && $page === 'employees' && (int) ($_GET['id'] ?? 0) === 0;
                web_message($hasEmployeeModal && !empty($formError['employeeModal']) ? '' : $error, '');
                ?>
                <?php if (!empty($user['mustChangePassword']) && $page !== 'preferences'): ?>
                    <div class="temp-password-banner" role="status"><?= icon('settings', 16) ?><span>You’re signed in with a temporary password from HR. Please choose your own.</span><a class="btn sm primary" href="<?= h(page_url('preferences')) ?>">Change password</a></div>
                <?php endif; ?>
                <?php if ($state) require __DIR__ . '/../pages/' . ($page === 'preferences' ? 'settings' : $page) . '.php'; ?>
                <?php if ($hasEmployeeModal) add_employee_modal($state, $page, $formError); ?>
            </main>
        </div>
    </div>
    <dialog id="sign-out-dialog" class="modal sign-out-dialog" aria-labelledby="sign-out-title" aria-describedby="sign-out-description"<?= $showSignOut ? ' open' : '' ?>>
        <form method="post" action="<?= h($signOutReturn) ?>">
            <?php hidden_inputs(['_action' => 'logout', '_token' => $_SESSION['web_csrf']]); ?>
            <div class="modal-head"><h2 id="sign-out-title">Sign out?</h2></div>
            <div class="modal-body"><p id="sign-out-description">Are you sure you want to sign out?</p></div>
            <div class="modal-foot">
                <a class="btn" id="cancel-sign-out" href="<?= h($signOutReturn) ?>" autofocus>Cancel</a>
                <button class="btn primary" type="submit"><?= icon('logOut') ?> Sign out</button>
            </div>
        </form>
    </dialog>
    <?php if (!empty($formError['shared'])): ?>
        <div id="shared-form-feedback" hidden data-feedback="<?= html_json($formError['shared']) ?>"></div>
    <?php endif; ?>
    <script>
        (function () {
            var wrap = document.querySelector('.web-notifications-wrap');
            var bell = document.getElementById('notification-bell');
            if (!wrap || !bell) return;
            wrap.addEventListener('toggle', function () { bell.setAttribute('aria-expanded', wrap.open ? 'true' : 'false'); });
            document.addEventListener('click', function (event) { if (wrap.open && !wrap.contains(event.target)) wrap.open = false; });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && wrap.open) { wrap.open = false; bell.focus(); }
            });
        }());
    </script>
    <script src="<?= h(versioned_asset('assets/js/form-dialog.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($error !== '' || $notice !== '') require __DIR__ . '/../partials/notifications.php'; ?>
<?php if ($user): ?>
<script>
    (function () {
        var app = document.querySelector('.web-app');
        var toggle = document.getElementById('web-sidebar-toggle');
        var menu = document.getElementById('web-menu-btn');
        var scrim = document.getElementById('web-nav-scrim');
        function updateToggle() {
            if (!toggle) return;
            var collapsed = document.documentElement.classList.contains('sidebar-collapsed');
            toggle.setAttribute('aria-expanded', String(!collapsed));
            toggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            toggle.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
            toggle.innerHTML = collapsed ? <?= json_encode(icon('chevRight', 16)) ?> : <?= json_encode(icon('chevLeft', 16)) ?>;
        }
        if (toggle) toggle.addEventListener('click', function () {
            document.documentElement.classList.toggle('sidebar-collapsed');
            try { localStorage.setItem('hrms-sidebar-collapsed', document.documentElement.classList.contains('sidebar-collapsed') ? '1' : '0'); } catch (_) {}
            updateToggle();
        });
        if (menu && app) menu.addEventListener('click', function () { app.classList.add('nav-open'); });
        if (scrim && app) scrim.addEventListener('click', function () { app.classList.remove('nav-open'); });
        if (app) app.querySelectorAll('.sidebar a').forEach(function (link) {
            link.addEventListener('click', function () { app.classList.remove('nav-open'); });
        });
        updateToggle();
    }());
</script>
<?php endif; ?>
<script src="<?= h(versioned_asset('assets/js/desktop-window.js')) ?>" defer></script>
<script src="<?= h(versioned_asset('assets/js/page-loader.js')) ?>" defer></script>
</body>
</html>
