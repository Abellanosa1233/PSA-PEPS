<!doctype html>
<html lang="en" data-theme="<?= h($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($user ? $pages[$page] : $screen) ?> · HRMS (Human Resource Management System)</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&amp;display=swap">
    <link rel="stylesheet" href="<?= h(versioned_asset('assets/css/php.css')) ?>">
</head>
<body>
<?php if (!$user): ?>
    <main class="auth web-auth">
        <button class="btn desktop-full-screen desktop-full-screen-auth" type="button" hidden>Full screen</button>
        <section class="auth-card">
            <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true">H</span><span>HRMS<small>Human Resource Management System</small></span></a>
            <h1><?= h($screen) ?></h1>
            <?php web_message($error, ''); ?>
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
                <?php field('username', 'Username'); field('password', 'Password', '', 'password'); ?>
                <?php end_form($screen); ?>
            <?php endif; ?>
        </section>
    </main>
<?php else:
    $navIcons = [
        'overview' => 'grid', 'employees' => 'users', 'attendance' => 'clock', 'time-off' => 'calendar',
        'payroll' => 'wallet', 'recruitment' => 'briefcase', 'reports' => 'chart',
    ];
    $pendingCount = count(array_filter($state['leaves'] ?? [], function ($leave) { return $leave['status'] === 'pending'; }));
    $settings = $state['settings'] ?? [];
    $signOutReturn = page_url($page, $query);
    $showSignOut = ($_GET['confirmSignOut'] ?? '') === '1';
?>
    <div class="app web-app">
        <aside class="sidebar" aria-label="Main navigation">
            <a class="brand sidebar-brand" href="<?= h(page_url('overview')) ?>" title="Human Resource Management System">
                <img src="assets/psa-logo.svg" width="40" height="40" alt="Philippine Statistics Authority">
                <span>HRMS</span>
            </a>
            <div>
                <div class="side-label">Workspace</div>
                <a class="ws-switch" href="<?= h(page_url('preferences')) ?>">
                    <div><strong><?= h($settings['company'] ?? 'HRMS') ?></strong><span><?= h($settings['workspace'] ?? 'Workspace') ?></span></div>
                    <?= icon('chevDown', 14) ?>
                </a>
            </div>
            <div>
                <div class="side-label">Manage</div>
                <nav class="nav">
                    <?php foreach ($navIcons as $key => $navIcon): ?>
                        <a href="<?= h(page_url($key)) ?>"<?= $key === $page ? ' class="active" aria-current="page"' : '' ?>>
                            <?= icon($navIcon) ?><span><?= h($pages[$key]) ?></span>
                            <?php if ($key === 'time-off' && $pendingCount): ?><span class="count"><?= $pendingCount ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <div class="side-spacer"></div>
            <div class="side-links">
                <?php foreach (['supervisor', 'self-service'] as $key): ?>
                    <a class="side-link<?= $page === $key ? ' active' : '' ?>" href="<?= h(page_url($key)) ?>"><?= h($pages[$key]) ?><?= icon('arrowUpRight', 13) ?></a>
                <?php endforeach; ?>
            </div>
            <div class="help-card">
                <strong>A little help goes a long way.</strong>
                <p>Find answers in our help center.</p>
                <a href="<?= h(page_url('help')) ?>">Get support <?= icon('arrowRight', 15) ?></a>
            </div>
            <nav class="nav" aria-label="Settings and help">
                <a class="<?= $page === 'preferences' ? 'active' : '' ?>" href="<?= h(page_url('preferences')) ?>"><?= icon('settings') ?> Settings</a>
                <a class="<?= $page === 'help' ? 'active' : '' ?>" href="<?= h(page_url('help')) ?>"><?= icon('help') ?> Help &amp; resources</a>
            </nav>
        </aside>
        <div class="main">
            <header class="topbar">
                <div class="crumbs"><span>Workspace</span><?= icon('chevRight', 13) ?><strong><?= h($pages[$page]) ?></strong></div>
                <div class="top-actions">
                    <button class="btn desktop-full-screen" type="button" hidden>Full screen</button>
                    <a class="icon-btn web-bell" href="<?= h(page_url('time-off', ['status' => 'pending'])) ?>" aria-label="Pending leave requests">
                        <?= icon('bell') ?><?php if ($pendingCount): ?><span class="dot"></span><?php endif; ?>
                    </a>
                    <details class="popover-wrap web-user">
                        <summary class="user-btn"><span class="avatar"><?= h(initials($user['name'])) ?></span><span class="who-text"><strong><?= h($user['name']) ?></strong><span><?= h($user['role']) ?></span></span><?= icon('chevDown', 14) ?></summary>
                        <div class="popover menu">
                            <a href="<?= h(page_url('preferences')) ?>"><?= icon('settings') ?> Settings</a>
                            <a href="<?= h(page_url('self-service')) ?>"><?= icon('user') ?> Employee workspace</a><hr>
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
                $hasEmployeeModal = $state && ($page === 'overview' || ($page === 'employees' && (int) ($_GET['id'] ?? 0) === 0));
                web_message($hasEmployeeModal && !empty($formError['employeeModal']) ? '' : $error, '');
                ?>
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
        <div id="shared-form-feedback" hidden data-feedback="<?= h(json_encode($formError['shared'], JSON_INVALID_UTF8_SUBSTITUTE)) ?>"></div>
    <?php endif; ?>
    <script src="<?= h(versioned_asset('assets/js/sign-out-dialog.js')) ?>" defer></script>
    <script src="<?= h(versioned_asset('assets/js/form-dialog.js')) ?>" defer></script>
<?php endif; ?>
<?php if ($error !== '' || $notice !== '') require __DIR__ . '/../partials/notifications.php'; ?>
<script src="<?= h(versioned_asset('assets/js/desktop-window.js')) ?>" defer></script>
</body>
</html>
