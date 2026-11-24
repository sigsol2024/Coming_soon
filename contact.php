<?php

declare(strict_types=1);

require __DIR__.'/lib/waitlist.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Use the waitlist form to join.']);
    exit;
}

if (trim((string) ($_POST['company_website'] ?? '')) !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

if (! waitlist_same_site()) {
    fail('Use the waitlist form to join.', 403);
}

$role = trim((string) ($_POST['role'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$phone = trim((string) ($_POST['phone'] ?? ''));
$roles = ['business' => 'Business', 'creator' => 'Creator'];

if (! isset($roles[$role])) {
    fail('Choose business or creator.');
}
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 160) {
    fail('Enter a valid email address.');
}
$digits = preg_replace('/\D+/', '', $phone) ?? '';
if (preg_match('/[^0-9+\-\s()]/', $phone) === 1 || strlen($digits) < 7 || strlen($digits) > 15 || strlen($phone) > 20) {
    fail('Enter a valid phone number.');
}

if (tooManyAttempts()) {
    fail('Too many tries. Please wait a little and try again.', 429);
}

try {
    $config = require __DIR__.'/config.php';
    $store = waitlist_open($config);
    $saved = waitlist_remember($store, $role, $email, $phone);
    $notice = waitlist_notify($store, $config, $saved['row'], $roles[$role]);
} catch (Throwable) {
    fail('We could not add you just now. Call us or message us on Instagram.', 503);
}

echo json_encode([
    'ok' => true,
    'already' => $notice['already'],
    'reset' => $notice['reset'],
    'message' => $notice['message'],
]);

function fail(string $message, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['message' => $message]);
    exit;
}

function tooManyAttempts(): bool
{
    $dir = __DIR__.'/storage';
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $file = $dir.'/contact-limit.json';
    $handle = fopen($file, 'c+');
    if ($handle === false || ! flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }

        return true;
    }
    $key = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    $now = time();
    $window = 3600;
    $limit = 5;
    $decoded = json_decode((string) stream_get_contents($handle), true);
    $data = is_array($decoded) ? $decoded : [];
    foreach ($data as $bucket => $times) {
        $fresh = array_values(array_filter(
            is_array($times) ? $times : [],
            fn ($at) => is_int($at) && ($now - $at) < $window
        ));
        if ($fresh === []) {
            unset($data[$bucket]);
        } else {
            $data[$bucket] = $fresh;
        }
    }
    $hits = $data[$key] ?? [];
    $limited = count($hits) >= $limit;
    if (! $limited) {
        $hits[] = $now;
        $data[$key] = $hits;
    }
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, (string) json_encode($data));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $limited;
}

function waitlist_same_site(): bool
{
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === 'null') {
        return false;
    }
    if ($origin !== '') {
        return strtolower((string) parse_url($origin, PHP_URL_HOST)) === $host;
    }
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer === '') {
        return true;
    }

    return strtolower((string) parse_url($referer, PHP_URL_HOST)) === $host;
}
