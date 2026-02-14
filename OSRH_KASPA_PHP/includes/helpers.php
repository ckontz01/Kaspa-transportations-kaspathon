<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * HTML-escape output.
 */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Build an absolute URL from a relative path using BASE_URL.
 * If $path is already absolute (http/https), return as-is.
 */
function url(string $path): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

/**
 * Redirect to a given path or URL and exit.
 * If $path is relative, it is prefixed with BASE_URL.
 */
function redirect(string $path, int $code = 302): void
{
    if (!preg_match('#^https?://#i', $path)) {
        $path = url($path);
    }

    if (headers_sent($file, $line)) {
        // Fallback to JavaScript redirect if headers already sent
        echo '<script>window.location.href = ' . json_encode($path) . ';</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($path, ENT_QUOTES) . '"></noscript>';
        exit;
    }

    http_response_code($code);
    header('Location: ' . $path);
    exit;
}

/**
 * Cryptographically secure random bytes with fallbacks.
 */
function osrh_random_bytes(int $length = 32): string
{
    try {
        return random_bytes($length);
    } catch (Exception $e) {
        if (function_exists('openssl_random_pseudo_bytes')) {
            return openssl_random_pseudo_bytes($length);
        }

        $bytes = '';
        for ($i = 0; $i < $length; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }
        return $bytes;
    }
}

/**
 * App-specific password hashing: PBKDF2-HMAC-SHA256.
 * Returns ['hash' => binary, 'salt' => binary].
 */
function osrh_password_hash(string $plainPassword, ?string $saltBinary = null): array
{
    if ($saltBinary === null) {
        $saltBinary = osrh_random_bytes(PASSWORD_SALT_BYTES);
    }

    $hashBinary = hash_pbkdf2(
        'sha256',
        $plainPassword,
        $saltBinary,
        PASSWORD_ITERATIONS,
        PASSWORD_HASH_BYTES,
        true // binary
    );

    return [
        'hash' => $hashBinary,
        'salt' => $saltBinary,
    ];
}

/**
 * Verify a password against salt + hash pair.
 */
function osrh_password_verify(string $plainPassword, string $saltBinary, string $hashBinary): bool
{
    $calc = osrh_password_hash($plainPassword, $saltBinary);
    return hash_equals($hashBinary, $calc['hash']);
}

/**
 * Generate (if needed) and return CSRF token for this session.
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(osrh_random_bytes(PASSWORD_SALT_BYTES));
    }

    return $_SESSION['_csrf_token'];
}

/**
 * Echo a hidden field with the CSRF token.
 */
function csrf_field(): void
{
    $token = csrf_token();
    echo '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Verify a CSRF token from a form submission.
 */
function verify_csrf_token(?string $token): bool
{
    if (empty($_SESSION['_csrf_token']) || !is_string($token)) {
        return false;
    }

    return hash_equals($_SESSION['_csrf_token'], $token);
}

/**
 * Safe array getter (e.g. from $_POST).
 */
function array_get($array, $key, $default = null)
{
    if (!is_array($array)) {
        return $default;
    }
    return array_key_exists($key, $array) ? $array[$key] : $default;
}

// ---------------------------------------------------------
// EMAIL UTILITIES
// ---------------------------------------------------------

/**
 * Format a datetime value in Cyprus time (Europe/Nicosia).
 */
function osrh_format_cyprus_datetime($value): string
{
    if ($value instanceof DateTimeInterface) {
        $dt = clone $value;
        $dt->setTimezone(new DateTimeZone('Europe/Nicosia'));
        return $dt->format('Y-m-d H:i');
    }

    if (is_string($value) && $value !== '') {
        return $value;
    }

    return '(time unavailable)';
}

/**
 * Send a plain-text email via SMTP with Brevo HTTP fallback.
 */
function osrh_send_email(array $recipients, string $subject, string $body, ?string $sender = null): void
{
    $to = array_values(array_filter(array_map('trim', $recipients), static function ($email) {
        return $email !== '';
    }));

    if (empty($to)) {
        throw new InvalidArgumentException('At least one recipient is required');
    }

    $from = $sender ?: EMAIL_DEFAULT_SENDER;

    try {
        osrh_send_email_via_smtp($from, $to, $subject, $body);
        return;
    } catch (Throwable $smtpError) {
        error_log('SMTP delivery failed: ' . $smtpError->getMessage());
        if (EMAIL_BREVO_API_KEY === '') {
            throw $smtpError;
        }
    }

    osrh_send_email_via_brevo($from, $to, $subject, $body);
}

/**
 * SMTP transport using native sockets; supports SSL or STARTTLS.
 */
function osrh_send_email_via_smtp(string $from, array $to, string $subject, string $body): void
{
    $transportHost = EMAIL_USE_SSL ? 'ssl://' . EMAIL_SMTP_HOST : EMAIL_SMTP_HOST;
    $socket = @stream_socket_client($transportHost . ':' . EMAIL_SMTP_PORT, $errno, $errstr, 10, STREAM_CLIENT_CONNECT);

    if (!$socket) {
        throw new RuntimeException('SMTP connection failed: ' . $errstr . " ({$errno})");
    }

    stream_set_timeout($socket, 10);

    $readLine = static function () use ($socket): string {
        $line = fgets($socket, 512);
        if ($line === false) {
            throw new RuntimeException('SMTP read failed');
        }
        return $line;
    };

    $expect = static function (int $code) use ($readLine, $socket): void {
        $line = $readLine();
        if ((int)substr($line, 0, 3) !== $code) {
            throw new RuntimeException('Unexpected SMTP response: ' . trim($line));
        }
        while (strlen($line) > 3 && $line[3] === '-') {
            $line = $readLine();
        }
    };

    $write = static function (string $command) use ($socket): void {
        fwrite($socket, $command . "\r\n");
    };

    $expect(220);
    $hostLabel = 'localhost';
    $write('EHLO ' . $hostLabel);
    $expect(250);

    if (EMAIL_USE_TLS && !EMAIL_USE_SSL) {
        $write('STARTTLS');
        $expect(220);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('Failed to enable STARTTLS');
        }
        $write('EHLO ' . $hostLabel);
        $expect(250);
    }

    if (EMAIL_SMTP_USERNAME !== '') {
        $write('AUTH LOGIN');
        $expect(334);
        $write(base64_encode(EMAIL_SMTP_USERNAME));
        $expect(334);
        $write(base64_encode(EMAIL_SMTP_PASSWORD));
        $expect(235);
    }

    $write('MAIL FROM: <' . $from . '>');
    $expect(250);

    foreach ($to as $recipient) {
        $write('RCPT TO: <' . $recipient . '>');
        $expect(250);
    }

    $write('DATA');
    $expect(354);

    $safeBody = str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], $body);
    $headers = [
        'Subject: ' . $subject,
        'From: ' . $from,
        'To: ' . implode(', ', $to),
        'Date: ' . gmdate('D, d M Y H:i:s O'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $safeBody . "\r\n.";
    $write($message);
    $expect(250);

    $write('QUIT');
    fclose($socket);
}

/**
 * Brevo HTTP API fallback when SMTP is not reachable.
 */
function osrh_send_email_via_brevo(string $from, array $to, string $subject, string $body): void
{
    $payload = [
        'sender' => ['email' => $from],
        'to' => array_map(static function ($email) {
            return ['email' => $email];
        }, $to),
        'subject' => $subject,
        'textContent' => $body,
    ];

    $context = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nAccept: application/json\r\napi-key: " . EMAIL_BREVO_API_KEY,
            'content' => json_encode($payload),
            'timeout' => 10,
        ],
    ]);

    $response = @file_get_contents('https://api.brevo.com/v3/smtp/email', false, $context);
    $statusLine = is_array($http_response_header ?? null) ? ($http_response_header[0] ?? '') : '';

    if ($response === false || !preg_match('/\s(\d{3})\s/', $statusLine, $m) || (int)$m[1] >= 400) {
        throw new RuntimeException('Brevo API request failed: ' . $statusLine);
    }
}
