<?php
declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/seed.php';
require __DIR__ . '/lib/state.php';
require __DIR__ . '/lib/actions.php';
require __DIR__ . '/lib/dispatch.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function respond(array $body, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
try {
    $action = (string) ($_GET['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'];
    $in = [];
    if ($method === 'POST') {
        // Custom header blocks cross-site form posts (browsers cannot set it without CORS approval).
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'HorizonHRMS') fail('Request rejected.', 400);
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($in)) $in = [];
    }

    switch ($action) {
        case 'status':
            $ok = installed();
            respond(['installed' => $ok, 'hasUsers' => $ok && has_users(), 'user' => $ok ? current_user() : null]);

        case 'install':
        case 'setup':
        case 'login':
            if ($method !== 'POST') fail('Use POST.', 405);
            respond(account_action($action, $in));

        case 'logout':
            respond(account_action($action, $in));

        case 'state':
            respond(build_state(require_user()));
    }

    $handler = action_handler($action);
    if ($method !== 'POST') fail('Use POST.', 405);
    $user = require_user();
    $result = $handler($in, $user);
    respond(['result' => $result, 'state' => build_state($user)]);
} catch (ApiError $e) {
    if (db_in_tx()) db()->rollBack();
    respond(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    if (db_in_tx()) db()->rollBack();
    error_log('[horizon-hrms] ' . $e);
    respond(['error' => 'Something went wrong on the server. Details were written to the Apache error log.'], 500);
}

function db_in_tx(): bool
{
    try { return db()->inTransaction(); } catch (Throwable $e) { return false; }
}
