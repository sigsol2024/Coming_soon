<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
for ($pass = 0; $pass < 5; $pass++) {
    $decoded = rawurldecode($path);
    if ($decoded === $path) {
        break;
    }
    $path = $decoded;
}
$path = str_replace("\0", '', $path);
$parts = [];
foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
    if ($part === '' || $part === '.') {
        continue;
    }
    if ($part === '..') {
        array_pop($parts);
        continue;
    }
    $parts[] = $part;
}
$lower = strtolower('/'.implode('/', $parts));
$blocked = $lower === '/config.php'
    || $lower === '/storage'
    || str_starts_with($lower, '/storage/')
    || $lower === '/lib'
    || str_starts_with($lower, '/lib/')
    || str_ends_with($lower, '.sqlite')
    || str_ends_with($lower, '.sqlite3');

if ($blocked) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

return false;
