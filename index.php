<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (PHP_VERSION_ID < 70200) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'HRMS requires PHP 7.2 or newer. Apache is running PHP ', PHP_VERSION,
        '. Start Apache from an XAMPP installation that includes PHP 7.2 or newer.';
    exit;
}
require_once __DIR__ . '/api/lib/bootstrap.php';
require __DIR__ . '/api/lib/seed.php';
require __DIR__ . '/api/lib/state.php';
require __DIR__ . '/api/lib/actions.php';
require __DIR__ . '/api/lib/dispatch.php';
require __DIR__ . '/api/lib/web.php';

$pages = [
    'overview' => 'Overview', 'employees' => 'Employees', 'attendance' => 'Attendance',
    'time-off' => 'Time off', 'payroll' => 'Payroll', 'recruitment' => 'Recruitment',
    'reports' => 'Reports', 'supervisor' => 'Supervisor workspace',
    'self-service' => 'Employee workspace', 'preferences' => 'Settings', 'help' => 'Help & resources',
];
$page = (string) ($_GET['page'] ?? 'overview');
if (!isset($pages[$page])) $page = 'overview';
$user = null;
$state = null;
$error = '';
$notice = '';
$errorLevel = 'failure';
$errorField = null;
$formError = [];
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$query = array_intersect_key($_GET, array_flip(['id', 'date', 'q', 'department', 'status', 'month', 'type', 'todayStatus', 'week', 'p', 'candidate', 'candidates', 'rolesPage', 'leavePage', 'slipsPage']));
$screen = 'Sign in';
$theme = 'system';
try {
    start_session();
    if (!isset($_SESSION['web_csrf'])) $_SESSION['web_csrf'] = bin2hex(random_bytes(32));
    $theme = $_SESSION['web_theme'] ?? 'system';
    $user = current_user();
    $screen = !installed() ? 'Install HRMS' : (has_users() ? 'Sign in' : 'Create administrator');
    if ($isPost) {
        unset($_SESSION['web_form_error'], $_SESSION['web_notice']);
        if (!hash_equals($_SESSION['web_csrf'], (string) ($_POST['_token'] ?? ''))) {
            fail('This form expired. Reload the page and try again.', 403);
        }
        $action = (string) ($_POST['_action'] ?? '');
        if (in_array($action, ['install', 'setup', 'login', 'logout'], true)) {
            account_action($action, $_POST);
            if ($action === 'logout') {
                start_session();
                session_regenerate_id(true);
            }
        } elseif ($action === 'theme') {
            require_user();
            $_SESSION['web_theme'] = one_of((string) ($_POST['theme'] ?? ''), ['system', 'light', 'dark'], 'Theme');
        } else {
            $user = require_user();
            $handler = action_handler($action);
            $result = $handler($_POST, $user);
        }
        if (($result['changed'] ?? true) !== false) {
            $_SESSION['web_notice'] = action_success_message($action, $_POST);
        }
        unset($query['candidate']);
        if (in_array($action, ['employee.save', 'position.save', 'payroll.create'], true)) {
            $query['id'] = $result['id'];
        } elseif (in_array($action, ['employee.delete', 'position.delete'], true)) {
            unset($query['id']);
        }
        $destination = $action === 'employee.save' ? 'employees' : $page;
        if ($action === 'employee.save' && ($_POST['_employee_modal'] ?? '') === '1') {
            $destination = $page;
            unset($query['id']);
        }
        header('Location: ' . page_url($destination, $query), true, 303);
        exit;
    }
    if (($_SERVER['HTTP_X_HORIZON_VIEW'] ?? '') !== 'employee-search') {
        $notice = $_SESSION['web_notice'] ?? '';
        unset($_SESSION['web_notice']);
        if (($_SESSION['web_form_error']['page'] ?? null) === $page) {
            $formError = $_SESSION['web_form_error'];
            $error = $formError['message'];
            $errorLevel = $formError['level'] ?? 'failure';
            unset($_SESSION['web_form_error']);
        }
    }
} catch (ApiError $e) {
    $error = $e->getMessage();
    $errorField = $e->field;
    if ($e->status >= 500) $errorLevel = 'error';
    if (!$isPost) http_response_code($e->status);
} catch (Throwable $e) {
    error_log((string) $e);
    $error = 'Something went wrong on the server.';
    $errorLevel = 'error';
    if (!$isPost) http_response_code(500);
}

if ($isPost) {
    try {
        if (db()->inTransaction()) db()->rollBack();
    } catch (Throwable $e) {
        error_log((string) $e);
    }
    $employeeModal = in_array($page, ['employees', 'overview'], true)
        && ($_POST['_action'] ?? '') === 'employee.save'
        && ($_POST['_employee_modal'] ?? '') === '1';
    $fields = array_flip(['id', 'firstName', 'lastName', 'email', 'phone', 'title', 'department', 'type', 'location', 'startDate', 'salary', 'managerId']);
    $sharedForm = $_POST['_shared_form'] ?? '';
    $sharedFormFields = [
        'leave.create' => ['empId', 'type', 'start', 'end', 'reason', 'approveNow'],
        'event.save' => ['date', 'title', 'detail', 'start', 'end'],
        'position.save' => ['id', 'title', 'department', 'type', 'location'],
        'candidate.save' => ['positionId', 'name', 'email', 'source'],
        'profile.save' => ['name', 'role'],
        'password.change' => [],
    ];
    $sharedFields = is_string($sharedForm) ? ($sharedFormFields[$sharedForm] ?? null) : null;
    $_SESSION['web_form_error'] = [
        'page' => $page,
        'message' => $error,
        'level' => $errorLevel,
        'field' => $errorField,
        'employeeModal' => $employeeModal,
        'values' => $employeeModal ? array_filter(array_intersect_key($_POST, $fields), 'is_string') : [],
        'shared' => $sharedFields !== null ? [
            'action' => $sharedForm,
            'message' => $error,
            'field' => $errorField,
            'values' => array_filter(array_intersect_key($_POST, array_flip($sharedFields)), 'is_string'),
        ] : null,
    ];
    header('Location: ' . page_url($page, $query), true, 303);
    exit;
}

if ($user) {
    try {
        if (db()->inTransaction()) db()->rollBack();
        $state = build_state($user);
        if (isset($_GET['export'])) export_page($page, $state);
    } catch (Throwable $e) {
        error_log((string) $e);
        $error = 'The workspace data could not be loaded.';
        $errorLevel = 'error';
        http_response_code(500);
    }
}
require __DIR__ . '/views/layouts/site.php';
