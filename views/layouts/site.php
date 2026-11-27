<!doctype html>
<html lang="en" data-theme="<?= h($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($user ? $pages[$page] : $screen) ?> · HRMS</title>
    <link rel="icon" type="image/svg+xml" href="<?= h(versioned_asset('assets/psa-logo.svg')) ?>">
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
    var navigationEntries = performance.getEntriesByType && performance.getEntriesByType('navigation');
    if (navigationEntries && navigationEntries[0] && navigationEntries[0].type === 'reload') {
        var pageLoader = document.getElementById('page-loader');
        pageLoader.hidden = false;
        window.addEventListener('pageshow', function () { pageLoader.hidden = true; }, { once: true });
    }
</script>
<?php if (!empty($sessionReplaced)): ?>
    <noscript><p role="alert">This account was signed in on another device. <a href="<?= h(page_url('overview')) ?>">Return to sign-in</a></p></noscript>
<?php elseif (!$user): ?>
    <main class="auth web-auth<?= $screen === 'Sign in' ? ' web-auth-signin' : '' ?>">
        <button class="btn desktop-full-screen desktop-full-screen-auth" type="button" hidden>Full screen</button>
        <section class="auth-card<?= $screen === 'Sign in' ? ' auth-card-signin' : '' ?>">
            <a class="brand" href="<?= h(page_url('overview')) ?>"><img class="brand-logo" src="assets/psa-logo.svg" alt="Philippine Statistics Authority seal"><span class="brand-copy"><strong>HRMS</strong><small>Human Resource Management System</small></span></a>
            <h1><?= h($screen) ?></h1>
            <?php web_message($errorField ? '' : $error, ''); ?>
            <?php if ($screen === 'Install HRMS'): ?>
                <p>Create the database tables before setting up your administrator account.</p>
                <?php post_form('install'); ?>
                <?php if (setup_key_required()) field('setupKey', 'Setup key', '', 'password'); ?>
                <?php end_form('Install'); ?>
            <?php else: ?>
                <?php post_form($screen === 'Sign in' ? 'login' : 'setup'); ?>
                <?php if ($screen !== 'Sign in') field('name', 'Full name'); ?>
                <?php if ($screen !== 'Sign in' && setup_key_required()) field('setupKey', 'Setup key', '', 'password'); ?>
                <?php if ($screen === 'Sign in'): ?>
                    <?php
                        field('username', 'Username', $formError['values']['username'] ?? '', 'text', true, $errorField === 'username' ? $error : '');
                        field('password', 'Password', '', 'password', true, $errorField === 'password' ? $error : '', ['passwordToggle' => true]);
                    ?>
                <?php else: ?>
                    <?php field('username', 'Username'); field('password', 'Password', '', 'password'); ?>
                <?php endif; ?>
                <?php end_form($screen); ?>
            <?php endif; ?>
        </section>
    </main>
    <?php if ($screen === 'Sign in'): ?>
        <script>
            (function () {
                var form = document.querySelector('.web-auth-signin form');
                var username = form && form.elements.namedItem('username');
                var password = form && form.elements.namedItem('password');
                var button = form && form.querySelector('[data-password-toggle]');
                if (!form || !username || !password) return;
                form.noValidate = true;

                function validate(input) {
                    var value = input === username ? input.value.trim() : input.value;
                    var message = '';
                    if (input === username) {
                        if (!value) message = 'Please enter your username.';
                        else if (!/^[a-z0-9._-]{3,60}$/i.test(value)) {
                            message = 'Username must be 3–60 characters and may contain only letters, numbers, periods, hyphens, and underscores.';
                        }
                    } else if (!value) {
                        message = 'Please enter your password.';
                    }

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
                    if ((event.target === username || event.target === password)
                            && event.target.hasAttribute('aria-invalid')) {
                        validate(event.target);
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
        'special-orders' => 'file', 'recruitment' => 'briefcase', 'reports' => 'chart',
    ];
    $isAdmin = is_admin($user);
    // HR is notified of every pending request; a supervising employee only of their direct reports' requests.
    $needsDecision = function ($item) use ($isAdmin, $user) {
        return $item['status'] === 'pending' && ($isAdmin || $item['empId'] !== $user['employeeId']);
    };
    $pendingLeaves = array_filter($state['leaves'] ?? [], $needsDecision);
    $pendingEmployeeRequests = array_filter($state['employeeRequests'] ?? [], function ($item) use ($needsDecision) {
        return $item['type'] !== 'coe' && $needsDecision($item);
    });
    // Attendance corrections are decided by HR only.
    $pendingCorrections = $isAdmin ? array_filter($state['corrections'] ?? [], function ($c) { return $c['status'] === 'pending'; }) : [];
    $pendingCount = count($pendingLeaves) + count($pendingEmployeeRequests) + count($pendingCorrections);
    // Special Orders the secretary still has to generate or get signed.
    $openSpecialOrders = $isAdmin ? count(array_filter($state['employeeRequests'] ?? [], function ($r) {
        return $r['type'] === 'special_order' && $r['groupId'] === null && in_array(special_order_stage($r), ['requested', 'for_signature'], true);
    })) : 0;
    $employeesById = array_column($state['employees'] ?? [], null, 'id');
    $notifications = [];
    foreach ($pendingLeaves as $leave) $notifications[] = ['leave', $leave];
    foreach ($pendingEmployeeRequests as $request) $notifications[] = ['request', $request];
    foreach ($pendingCorrections as $correction) $notifications[] = ['correction', $correction + ['start' => $correction['date']]];
    $notifications = array_slice($notifications, 0, 8);
    $myUpdates = $isAdmin ? [] : employee_updates($state, (int) $user['employeeId']);
    $reviewPage = $isAdmin ? 'time-off' : 'supervisor';
    $reviewQuery = $isAdmin ? ['status' => 'pending'] : [];
    $homePage = 'overview';
    $settings = $state['settings'] ?? [];
    $signOutReturn = page_url($page, $query);
    $showSignOut = ($_GET['confirmSignOut'] ?? '') === '1';
?>
    <div class="app web-app<?= !$isAdmin && in_array($page, ['overview', 'my-time-off', 'my-profile', 'coe-requests'], true) ? ' employee-dashboard-shell' : '' ?>">
        <aside class="sidebar" id="web-sidebar" aria-label="Main navigation">
            <button class="sidebar-toggle" id="web-sidebar-toggle" type="button" aria-controls="web-sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar"><?= icon('chevLeft', 16) ?></button>
            <a class="brand sidebar-brand" href="<?= h(page_url($homePage)) ?>" title="Human Resource Management System">
                <img src="assets/psa-logo.svg" width="40" height="40" alt="Philippine Statistics Authority">
                <span class="brand-copy"><strong>HRMS</strong><small>Human Resource Management System</small></span>
            </a>
            <div>
                <div class="side-label">Workspace</div>
                <a class="ws-switch" href="<?= h(page_url('preferences')) ?>">
                    <div><strong><?= h($settings['company'] ?? 'HRMS') ?></strong><span><?= h($isAdmin ? ($settings['workspace'] ?? 'Workspace') : 'Employee workspace') ?></span></div>
                    <?= icon('chevDown', 14) ?>
                </a>
            </div>
            <?php if (!$isAdmin) $navIcons = ['overview' => 'grid', 'my-time-off' => 'calendar', 'special-orders' => 'file', 'my-attendance' => 'clock', 'my-profile' => 'user']; ?>
            <?php $navIcons['work-calendar'] = 'calendar'; ?>
            <?php if (!$isAdmin && !empty($user['canViewCoeRequests'])) $navIcons['coe-requests'] = 'file'; ?>
            <div>
                <div class="side-label"><?= $isAdmin ? 'Manage' : 'My workspace' ?></div>
                <nav class="nav">
                    <?php foreach ($navIcons as $key => $navIcon): ?>
                        <a href="<?= h(page_url($key)) ?>" title="<?= h($pages[$key]) ?>"<?= $key === $page || ($key === 'my-time-off' && $page === 'request-leave') || ($key === 'special-orders' && in_array($page, ['special-order-edit', 'special-order-request'], true)) ? ' class="active" aria-current="page"' : '' ?>>
                            <?= icon($navIcon) ?><span><?= h($pages[$key]) ?></span>
                            <?php $navCount = $key === $reviewPage ? $pendingCount - count($pendingCorrections) : ($key === 'attendance' ? count($pendingCorrections) : ($key === 'special-orders' ? $openSpecialOrders : 0)); if ($navCount): ?><span class="count"><?= $navCount ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="side-spacer"></div>
            <?php $sideLinks = $isAdmin ? ['supervisor', 'self-service'] : ($user['hasTeam'] ? ['supervisor'] : []); ?>
            <?php if ($sideLinks): ?>
            <div class="side-links">
                <?php foreach ($sideLinks as $key): ?>
                    <a class="side-link<?= $page === $key ? ' active' : '' ?>" href="<?= h(page_url($key)) ?>" title="<?= h($pages[$key]) ?>"><?= h($pages[$key]) ?><?php if (!$isAdmin && $key === $reviewPage && $pendingCount): ?><span class="count"><?= $pendingCount ?></span><?php endif; ?><?= icon('arrowUpRight', 13) ?></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="help-card">
                <?php if ($isAdmin): ?>
                <strong>Human Resource Support</strong>
                <p>Access system guidance and assistance.</p>
                <a href="<?= h(page_url('help')) ?>">Open help center <?= icon('arrowRight', 15) ?></a>
                <?php else: ?>
                <strong>Need a hand?</strong>
                <p>Your HR team is here to help.</p>
                <a href="<?= h(page_url('help')) ?>">Contact HR <?= icon('arrowRight', 15) ?></a>
                <?php endif; ?>
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
                <div class="crumbs"><span>Workspace</span><?= icon('chevRight', 13) ?><strong><?= h($page === 'request-leave' ? 'My time off / Request leave' : $pages[$page]) ?></strong></div>
                <div class="top-actions">
                    <button class="btn desktop-full-screen" type="button" hidden>Full screen</button>
                    <form method="post" id="web-theme-form">
                        <?php hidden_inputs(['_action' => 'theme', '_token' => $_SESSION['web_csrf']]); ?>
                        <?php /* Two plain buttons; CSS shows the right one, including for "System" theme on a dark device. */ ?>
                        <button type="submit" name="theme" value="light" class="icon-btn theme-to-light" aria-label="Switch to light mode" title="Switch to light mode"><?= icon('sun', 17) ?></button>
                        <button type="submit" name="theme" value="dark" class="icon-btn theme-to-dark" aria-label="Switch to dark mode" title="Switch to dark mode"><?= icon('moon', 17) ?></button>
                    </form>
                    <details class="popover-wrap web-notifications-wrap">
                        <summary class="icon-btn web-bell" id="notification-bell" aria-label="Notifications<?= $pendingCount ? ', ' . $pendingCount . ' pending' : '' ?><?= $myUpdates ? ', ' . count($myUpdates) . ' update' . (count($myUpdates) === 1 ? '' : 's') : '' ?>" aria-haspopup="true" aria-expanded="false">
                            <?= icon('bell') ?><?php if ($pendingCount || $myUpdates): ?><span class="dot"></span><?php endif; ?>
                        </summary>
                        <div class="popover notification-popover" id="notification-popover">
                            <div class="notification-popover-head"><strong>Notifications</strong><?php if ($pendingCount): ?><span class="pill"><?= $pendingCount ?> pending</span><?php elseif ($myUpdates): ?><span class="pill"><?= count($myUpdates) ?> new</span><?php endif; ?></div>
                            <?php foreach ($myUpdates as $u): ?>
                                <a class="notification-item" href="<?= h(page_url('my-time-off')) ?>">
                                    <span class="avatar sm"><?= icon($u['ok'] ? 'check' : 'x', 14) ?></span>
                                    <span><strong><?= h($u['title']) ?></strong><small><?= h($u['detail']) ?></small></span>
                                </a>
                            <?php endforeach; ?>
                            <?php if ($notifications): ?>
                                <?php foreach ($notifications as [$kind, $item]):
                                    $employee = $employeesById[$item['empId']] ?? null;
                                    $name = $employee ? employee_name($employee) : 'Former employee';
                                    $label = $kind === 'leave' ? 'Requested ' . strtolower($item['type'])
                                        : ($kind === 'correction' ? 'Attendance correction' : employee_request_label($item['type']) . ' · ' . $item['purpose']);
                                ?>
                                    <a class="notification-item" href="<?= h($kind === 'correction' ? page_url('attendance') : page_url($reviewPage, $reviewQuery)) ?>">
                                        <span class="avatar sm"><?= h(initials($name)) ?></span>
                                        <span><strong><?= h($name) ?></strong><small><?= h($label) ?> · <?= h($item['start']) ?></small></span>
                                    </a>
                                <?php endforeach; ?>
                                <a class="notification-footer" href="<?= h(page_url($reviewPage, $reviewQuery)) ?>">Review pending requests</a>
                            <?php elseif (!$myUpdates): ?>
                                <div class="notification-empty"><?= icon('check', 18) ?><strong>No pending notifications</strong><span>There are no new notifications.</span></div>
                            <?php endif; ?>
                        </div>
                    </details>
                    <details class="popover-wrap web-user">
                        <summary class="user-btn"><span class="avatar"><?php if (!empty($user['avatarUrl'])): ?><img src="<?= h($user['avatarUrl']) ?>" alt=""><?php else: ?><?= h(initials($user['name'])) ?><?php endif; ?></span><span class="who-text"><strong><?= h($user['name']) ?></strong><span><?= h($isAdmin ? 'Administrator · ' . $user['role'] : $user['role'] . ' · Employee') ?></span></span><?= icon('chevDown', 14) ?></summary>
                        <div class="popover menu">
                            <a href="<?= h(page_url('preferences')) ?>"><?= icon('settings') ?> Settings</a>
                            <a href="<?= h(page_url($isAdmin ? 'self-service' : 'my-profile')) ?>"><?= icon('user') ?> <?= h($isAdmin ? $pages['self-service'] : 'My profile') ?></a><hr>
                            <a id="open-sign-out" href="<?= h(page_url($page, ['confirmSignOut' => 1] + $query)) ?>" aria-haspopup="dialog"><?= icon('logOut') ?> Sign out</a>
                        </div>
                    </details>
                </div>
            </header>
            <main class="content">
                <?php if (!in_array($page, ['overview', 'employees', 'attendance', 'time-off', 'reports', 'special-orders', 'special-order-edit', 'special-order-request', 'my-time-off', 'request-leave', 'my-attendance', 'my-profile', 'coe-requests'], true) || !$state): ?>
                    <div class="page-head"><h1><?= h($pages[$page]) ?></h1></div>
                <?php endif; ?>
                <?php
                $hasEmployeeModal = $state && $page === 'employees'
                    && ((int) ($_GET['id'] ?? 0) === 0 || !empty($_GET['edit']) || !empty($formError['employeeModal']));
                web_message($hasEmployeeModal && !empty($formError['employeeModal']) ? '' : $error, '');
                ?>
                <?php if (!empty($user['mustChangePassword']) && $page !== 'preferences'): ?>
                    <div class="temp-password-banner" role="status"><?= icon('settings', 16) ?><span><?= $isAdmin ? 'You’re signed in with the default administrator password. Choose your own now to keep the system secure.' : 'You’re signed in with a temporary password from HR. Please choose your own.' ?></span><a class="btn sm primary" href="<?= h(page_url('preferences')) ?>">Change password</a></div>
                <?php endif; ?>
                <?php if ($state) require __DIR__ . '/../pages/' . ($page === 'preferences' ? 'settings' : ($page === 'overview' && !$isAdmin ? 'my-overview' : $page)) . '.php'; ?>
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
    <?php if (in_array($page, ['employees', 'recruitment'], true)): ?>
        <script src="<?= h(versioned_asset('assets/js/employee-position.js')) ?>" defer></script>
    <?php endif; ?>
<?php endif; ?>
<?php if ($user || !empty($sessionReplaced)): ?>
    <dialog id="session-replaced-dialog" class="modal sign-out-dialog"
        aria-labelledby="session-replaced-title" aria-describedby="session-replaced-description"
        data-sign-in="<?= h(page_url('overview', ['sessionEnded' => 1])) ?>"<?= !empty($sessionReplaced) ? ' data-replaced' : '' ?>>
        <div class="modal-head"><h2 id="session-replaced-title">Signed in on another device</h2></div>
        <div class="modal-body"><p id="session-replaced-description">This account was signed in on another device. This device will return to sign-in in 3 seconds.</p></div>
    </dialog>
    <script src="<?= h(versioned_asset('assets/js/session-status.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($error !== '' || $notice !== '') require __DIR__ . '/../partials/notifications.php'; ?>
<?php if ($user): ?>
<script>
    (function () {
        var app = document.querySelector('.web-app');
        var toggle = document.getElementById('web-sidebar-toggle');
        var menu = document.getElementById('web-menu-btn');
        var scrim = document.getElementById('web-nav-scrim');
        var signOutDialog = document.getElementById('sign-out-dialog');
        var openSignOut = document.getElementById('open-sign-out');
        var cancelSignOut = document.getElementById('cancel-sign-out');
        if (openSignOut && signOutDialog && typeof signOutDialog.showModal === 'function' && !signOutDialog.open) {
            openSignOut.addEventListener('click', function (event) {
                if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
                event.preventDefault();
                signOutDialog.showModal();
            });
            cancelSignOut.addEventListener('click', function (event) {
                if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
                event.preventDefault();
                signOutDialog.close();
            });
        }
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
<script src="<?= h(versioned_asset('assets/js/attendance-clock.js')) ?>" defer></script>
</body>
</html>
