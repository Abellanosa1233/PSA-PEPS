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
    'time-off' => 'Time off', 'recruitment' => 'Recruitment',
    'reports' => 'Reports', 'supervisor' => 'Supervisor workspace',
    'self-service' => 'Employee workspace', 'preferences' => 'Settings', 'help' => 'Help & resources',
    'dtr' => 'Daily Time Record',
    'work-calendar' => 'Work calendar',
    // Employee-account pages
    'my-time-off' => 'My time off', 'my-attendance' => 'My attendance', 'my-profile' => 'My profile',
    'request-leave' => 'Request leave',
    'coe-requests' => 'COE requests',
    'coe-certificate' => 'Certificate of Employment',
    'document' => 'Document', // a document HR shared (My profile)
    'leave-file' => 'Leave attachment', // download of a supporting document (HR, or the employee who sent it)
];
$employeeOnlyPages = ['my-time-off', 'my-attendance', 'my-profile', 'request-leave'];
$path = rawurldecode(explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0]);
$route = basename($path);
$entry = $path === '' || substr($path, -1) === '/' || $route === 'index.php';
$legacyPage = $_GET['page'] ?? null;
$page = $entry ? (is_string($legacyPage) ? $legacyPage : 'overview') : ($route === 'settings' ? 'preferences' : $route);
if (!isset($pages[$page])) $page = 'overview';
$method = $_SERVER['REQUEST_METHOD'];
if ($entry && ($method === 'GET' || $method === 'HEAD') && ($legacyPage !== null || $route === 'index.php')) {
    $parameters = $_GET;
    unset($parameters['page']);
    header('Location: ' . page_url($page, $parameters), true, 302);
    exit;
}
$user = null;
$state = null;
$error = '';
$notice = '';
$errorLevel = 'failure';
$errorField = null;
$formError = [];
$isPost = $method === 'POST';
$query = array_intersect_key($_GET, array_flip(['id', 'date', 'q', 'department', 'status', 'month', 'type', 'todayStatus', 'week', 'p', 'archive', 'candidate', 'candidates', 'rolesPage', 'leavePage', 'requestPage', 'orderRequestPage']));
$screen = 'Sign in';
$theme = 'light';
try {
    start_session();
    if (!isset($_SESSION['web_csrf'])) $_SESSION['web_csrf'] = bin2hex(random_bytes(32));
    $theme = $_SESSION['web_theme'] ?? 'light';
    $user = current_user();
    if (!$user && !empty($_SESSION['account_session_replaced'])) {
        unset($_SESSION['account_session_replaced']);
        if (!isset($_GET['sessionEnded'])) {
            $sessionReplaced = true;
            require __DIR__ . '/views/layouts/site.php';
            exit;
        }
    }
    if ($user && !is_admin($user)) {
        // Employee accounts only see their own pages (and their team, if they supervise anyone).
        $pages['supervisor'] = 'Supervisor workspace';
        $allowedPages = array_merge(['overview', 'preferences', 'help', 'dtr', 'leave-file', 'document', 'work-calendar'], $employeeOnlyPages);
        if (can_manage_coe_requests($user)) $allowedPages = array_merge($allowedPages, ['coe-requests', 'coe-certificate']);
        if ($user['hasTeam']) $allowedPages[] = 'supervisor';
        if (!in_array($page, $allowedPages, true)) {
            if (!$isPost) {
                header('Location: ' . page_url('overview'), true, 302);
                exit;
            }
            $page = 'overview';
        }
    } elseif ($user && in_array($page, $employeeOnlyPages, true) && !$isPost) {
        // HR administrators use the Employee workspace view instead of these pages.
        header('Location: ' . page_url('self-service'), true, 302);
        exit;
    }
    if ($user && $page === 'request-leave' && isset($_GET['clear']) && !$isPost) {
        // Cancel on Request leave: forget the half-filled form.
        unset($_SESSION['leave_draft']);
        header('Location: ' . page_url('my-time-off'), true, 302);
        exit;
    }
    if (installed() && !has_users()) ensure_default_admin();
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
        } elseif ($action === 'leave.preview') {
            // "Check days & balance": keep what was typed (in the session, not the address bar,
            // since the reason is personal) and show the page again with the numbers worked out.
            require_user();
            $_SESSION['leave_draft'] = array_map('strval', array_intersect_key(array_filter($_POST, 'is_string'), array_flip(['type', 'start', 'end', 'dayLength', 'reason'])));
            header('Location: ' . page_url('request-leave'), true, 303);
            exit;
        } elseif ($action === 'theme') {
            require_user();
            $_SESSION['web_theme'] = one_of((string) ($_POST['theme'] ?? ''), ['system', 'light', 'dark'], 'Theme');
            if (($_SERVER['HTTP_X_HORIZON_VIEW'] ?? '') === 'theme-toggle') {
                http_response_code(204);
                exit;
            }
        } else {
            $user = require_user();
            $input = $_POST;
            if ($action === 'leave.create' && $page === 'request-leave') {
                // Keep what was typed so a failed submission (e.g. a missing reason) doesn't clear the form.
                $_SESSION['leave_draft'] = array_map('strval', array_intersect_key(array_filter($_POST, 'is_string'), array_flip(['type', 'start', 'end', 'dayLength', 'reason'])));
            }
            authorize_action($action, $input, $user);
            if ($action === 'profile.photo') $result = act_profile_photo($_FILES['profileImage'] ?? [], $user);
            elseif ($action === 'profile.photo.remove') $result = act_profile_photo_remove($user);
            elseif ($action === 'employee.import') $result = act_employee_import($_FILES['employeeCsv'] ?? []);
            elseif ($action === 'attendance.import') $result = act_attendance_import($_FILES['attendanceCsv'] ?? []);
            elseif ($action === 'attendance.biometrics') $result = act_attendance_biometrics($_FILES['biometricLog'] ?? []);
            elseif ($action === 'document.upload') $result = act_document_upload($input, $user, leave_attachment_upload($_FILES['document'] ?? [], 'document'));
            elseif ($action === 'coe.send') $result = act_coe_send($input, $user, leave_attachment_upload($_FILES['certificatePdf'] ?? [], 'certificatePdf', COE_PDF_MAX_MB));
            elseif ($action === 'leave.create') {
                $result = act_leave_create($input, $user, leave_attachment_upload($_FILES['attachment'] ?? []));
                if ($page === 'request-leave') unset($_SESSION['leave_draft']);
            } else {
                $handler = action_handler($action);
                $result = $handler($input, $user);
            }
        }
        if ($action !== 'theme' && ($result['changed'] ?? true) !== false) {
            if ($action === 'logout') unset($_SESSION['web_notice']);
            elseif ($action === 'employee.import') $_SESSION['web_notice'] = $result['imported'] . ' employee' . ($result['imported'] === 1 ? '' : 's') . ' imported successfully.';
            elseif ($action === 'attendance.import') $_SESSION['web_notice'] = $result['imported'] . ' attendance record' . ($result['imported'] === 1 ? '' : 's') . ' imported successfully.';
            elseif ($action === 'attendance.biometrics') $_SESSION['web_notice'] = biometric_import_message($result);
            elseif ($action === 'employee.save' && !empty($result['onboarded'])) $_SESSION['web_notice'] = 'Employee added and sign-in details sent by email.';
            else $_SESSION['web_notice'] = action_success_message($action, $_POST);
        }
        unset($query['candidate']);
        if (in_array($action, ['employee.save', 'position.save'], true)) {
            $query['id'] = $result['id'];
        } elseif (in_array($action, ['employee.delete', 'position.delete'], true)) {
            unset($query['id']);
        } elseif ($action === 'event.save' && $page === 'overview') {
            $query['date'] = $_POST['date'];
        }
        $destination = $action === 'login' ? 'overview' : ($action === 'employee.save' ? 'employees' : ($action === 'leave.create' && $page === 'request-leave' ? 'my-time-off' : $page));
        if ($action === 'login') $query = [];
        if ($action === 'coe.send') $query = ['archive' => '1'];
        if ($action === 'employee.save' && ($_POST['_employee_modal'] ?? '') === '1') {
            $destination = $page;
            if (empty($_GET['id'])) unset($query['id']);
        }
        header('Location: ' . page_url($destination, $query), true, 303);
        exit;
    }
    if ($method === 'GET' && $page === 'coe-requests' && $user && can_manage_coe_requests($user)
        && ($_SERVER['HTTP_X_HORIZON_VIEW'] ?? '') === 'coe-search') {
        $coeSearchFragment = true;
        $state = ['user' => $user];
        require __DIR__ . '/views/pages/coe-requests.php';
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
    $employeeModal = $page === 'employees'
        && ($_POST['_action'] ?? '') === 'employee.save'
        && ($_POST['_employee_modal'] ?? '') === '1';
    $fields = array_flip(['id', 'firstName', 'lastName', 'email', 'phone', 'title', 'department', 'type', 'location', 'startDate', 'salary', 'managerId', 'biometricId', 'loginUsername']); // never the password
    $sharedForm = $_POST['_shared_form'] ?? '';
    $sharedFormFields = [
        'leave.create' => ['empId', 'type', 'start', 'end', 'reason', 'approveNow'],
        'coe.request' => ['honorific', 'position', 'project', 'area', 'trainingStart', 'trainingEnd', 'start', 'end'],
        'coe.update' => ['requestId', 'honorific', 'position', 'project', 'area', 'trainingStart', 'trainingEnd', 'start', 'end'],
        'coe.send' => ['requestId'],
        'event.save' => ['date', 'title', 'detail', 'start', 'end', 'forEmployees'],
        'position.save' => ['id', 'title', 'department', 'type', 'location'],
        'candidate.save' => ['positionId', 'name', 'email', 'source'],
        'profile.save' => ['name', 'role'],
        'profile.emergency.save' => ['emergencyName', 'emergencyRelationship', 'emergencyPhone'],
        'password.change' => [],
        'admin.create' => ['name', 'username'],
        'employee.position.add' => ['type', 'name', 'code'],
    ];
    $sharedFields = is_string($sharedForm) ? ($sharedFormFields[$sharedForm] ?? null) : null;
    $_SESSION['web_form_error'] = [
        'page' => $page,
        'message' => $error,
        'level' => $errorLevel,
        'field' => $errorField,
        'employeeModal' => $employeeModal,
        'values' => $employeeModal
            ? array_filter(array_intersect_key($_POST, $fields), 'is_string')
            : (($_POST['_action'] ?? '') === 'login' ? ['username' => is_string($_POST['username'] ?? null) ? $_POST['username'] : ''] : []),
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
        if (is_admin($user)) {
            if ($page === 'employees' && isset($_GET['template'])) employee_import_template();
            if ($page === 'attendance' && isset($_GET['template'])) attendance_import_template();
            if (isset($_GET['export'])) export_page($page, $state);
        }
    } catch (Throwable $e) {
        error_log((string) $e);
        $error = 'The workspace data could not be loaded.';
        $errorLevel = 'error';
        http_response_code(500);
    }
}
if ($user && in_array($page, ['leave-file', 'document'], true)) {
    // A leave request's supporting document, or a document HR shared on My profile.
    // Access is checked by leave_attachment_for() / document_for().
    $id = (int) ($_GET['id'] ?? 0);
    $row = $page === 'leave-file' ? leave_attachment_for($user, $id) : document_for($user, $id);
    $file = !$row ? null : ($page === 'leave-file'
        ? ['bytes' => $row['attachment'], 'name' => $row['attachment_name'], 'type' => $row['attachment_type']]
        : ['bytes' => $row['file'], 'name' => $row['file_name'], 'type' => $row['file_type']]);
    if (!$file) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'This document was not found, or you do not have access to it.';
        exit;
    }
    header('Content-Type: ' . (in_array($file['type'], ['application/pdf', 'image/jpeg', 'image/png'], true) ? $file['type'] : 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $file['name'] ?: 'document') . '"');
    header('Content-Length: ' . strlen($file['bytes']));
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    echo $file['bytes'];
    exit;
}
if ($user && $state && $page === 'coe-certificate' && can_manage_coe_requests($user)) {
    $certificate = coe_requests_for_staff($user, (int) ($_GET['id'] ?? 0))[0] ?? null;
    $chiefSpecialists = $certificate ? current_chief_statistical_specialists() : [];
    require __DIR__ . '/views/pages/coe-certificate.php';
    exit;
}
if ($user && $state && $page === 'dtr') {
    // The DTR is a standalone printable page, without the app's sidebar and header.
    require __DIR__ . '/views/pages/dtr.php';
    exit;
}
require __DIR__ . '/views/layouts/site.php';
