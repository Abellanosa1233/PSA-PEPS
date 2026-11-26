<?php
declare(strict_types=1);

function ensure_employee_onboarding_mail(): void
{
    if (!function_exists('curl_init')) {
        fail('Employee account email requires the PHP cURL extension.', 503);
    }
    if (defined('GMAIL_SMTP_USER') && GMAIL_SMTP_USER !== '') {
        if (!filter_var(GMAIL_SMTP_USER, FILTER_VALIDATE_EMAIL)
            || !defined('GMAIL_SMTP_APP_PASSWORD')
            || strlen(str_replace(' ', '', GMAIL_SMTP_APP_PASSWORD)) !== 16) {
            fail('Set the Gmail sender and its app password before adding employees.', 503);
        }
        return;
    }
    if (!defined('RESEND_API_KEY') || trim(RESEND_API_KEY) === ''
        || !defined('EMPLOYEE_MAIL_FROM') || trim(EMPLOYEE_MAIL_FROM) === '') {
        fail('Employee account email is unavailable. Set up the mail sender and try again.', 503);
    }
}

function provision_employee_login(int $id, array $employee): void
{
    $name = $employee['first_name'] . ' ' . $employee['last_name'];
    $prefix = trim(preg_replace('/[^a-z0-9]+/', '.', strtolower($name)), '.');
    $username = substr($prefix ?: 'employee', 0, 40) . '.' . $id . '.' . random_int(1000, 9999);
    $password = bin2hex(random_bytes(12));
    act_account_create(['empId' => $id, 'username' => $username, 'password' => $password]);

    $message = "Hello $name,\n\nYour PSA HRMS account is ready.\n\nUsername: $username\nTemporary password: $password\n\nYou will be prompted to choose a new password after signing in.";
    if (defined('EMPLOYEE_SIGN_IN_URL') && EMPLOYEE_SIGN_IN_URL !== '') {
        $message .= "\n\nSign in: " . EMPLOYEE_SIGN_IN_URL;
    }
    if (defined('GMAIL_SMTP_USER') && GMAIL_SMTP_USER !== '') {
        send_employee_email_with_gmail($employee['email'], $message);
        return;
    }
    $request = curl_init('https://api.resend.com/emails');
    curl_setopt_array($request, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . RESEND_API_KEY, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'from' => EMPLOYEE_MAIL_FROM,
            'to' => [$employee['email']],
            'subject' => 'Your PSA HRMS employee account',
            'text' => $message,
        ]),
    ]);
    $body = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_HTTP_CODE);
    curl_close($request);
    if ($status < 200 || $status >= 300 || !is_string($body) || empty(json_decode($body, true)['id'])) {
        fail('The account email could not be sent. Check the email address or mail settings and try again.', 502);
    }
}

function send_employee_email_with_gmail(string $recipient, string $message): void
{
    $sender = GMAIL_SMTP_USER;
    $payload = implode("\r\n", [
        'Date: ' . date(DATE_RFC2822),
        "From: PSA HRMS <$sender>",
        "To: <$recipient>",
        'Subject: Your PSA HRMS employee account',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($message)),
    ]);
    $offset = 0;
    $request = curl_init('smtp://smtp.gmail.com:587');
    curl_setopt_array($request, [
        CURLOPT_USE_SSL => CURLUSESSL_ALL,
        CURLOPT_USERNAME => $sender,
        CURLOPT_PASSWORD => str_replace(' ', '', GMAIL_SMTP_APP_PASSWORD),
        CURLOPT_MAIL_FROM => "<$sender>",
        CURLOPT_MAIL_RCPT => ["<$recipient>"],
        CURLOPT_UPLOAD => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_READFUNCTION => function ($handle, $stream, int $length) use ($payload, &$offset): string {
            $chunk = substr($payload, $offset, $length);
            $offset += strlen($chunk);
            return $chunk;
        },
    ]);
    $sent = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    if ($sent === false || $status < 200 || $status >= 300) {
        fail('Gmail did not accept the account email. Check the sender settings and try again.', 502);
    }
}
